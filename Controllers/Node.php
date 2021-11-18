<?php

namespace MapasNetwork\Controllers;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\Entities\UserApp;
use MapasCulturais\i;
use MapasCulturais\Utils;
use MapasCulturais\Traits;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Space;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasNetwork\Plugin;
use MapasNetwork\Entities as NodeEntities;
use MapasNetwork\Entities\Node as EntitiesNode;
use RuntimeException;

/**
 * Node Linking Sequence
 * 1. source.POST_create
 * 2. destination.GET_connect
 * 3. source.GET_verifyConnectionToken
 * 3. source.GET_return
 * 4. destination.GET_verifyConnectionToken
 * 5. destination.GET_getKeys
 * 6. destination.POST_finish
 */
class Node extends \MapasCulturais\Controller
{
    use Traits\ControllerAPI;

    /**
     * @var Plugin
     */
    public $plugin;

    function __construct()
    {
        $this->layout = "mapas-network";

        $this->plugin = App::i()->plugins['MapasNetwork'];
        return;
    }

    public function GET_index()
    {
        $this->render("mapas-network");
        return;
    }

    function GET_confirmLinkAccount()
    {
        $app = App::i();

        $_SESSION["mapas-network:confirmed"] = true;

        $app->redirect($this->createUrl("connect"));
    }

    public function GET_linkAccounts()
    {

        $originName = $_SESSION['mapas-network:name'];

        $this->render("link-accounts", [
            'origin_name' => $originName,
        ]);
        return;
    }

    public function GET_panel()
    {
        $this->requireAuthentication();
        $app = App::i();

        $interval = $this->plugin->config['nodes-verification-interval'];
        if($interval[0] != '+') {
            $interval = '+' . $interval;
        }
        $app->user->network__next_verification_datetime = $interval;
        $app->user->save(true);

        $nodeRepo = $app->repo(NodeEntities\Node::class);
        $nodes = $nodeRepo->findBy(["user" => $app->user]);

        $found_accounts = $this->plugin->findAccounts($nodes);

        $this->render("panel-mapas-network-main", [
            "nodes" => $nodes,
            'found_accounts' => $found_accounts
        ]);
        return;
    }

    public function GET_delete()
    {
        $this->DELETE_single();
        return;
    }

    public function DELETE_single()
    {
        $app = App::i();
        $nodeRepo = $app->repo("MapasNetwork\\Entities\\Node");
        $entity = null;
        if (isset($this->data["id"])) { // original request
            $entity = $nodeRepo->find($this->data["id"]);
        } else { // API request (propagated); Mapas must expose the JWT authenticator's UserApp
            $entity = $nodeRepo->findOneBy(["userApp" => $app->auth->userApp]);
        }
        if (!$entity) {
            $app->pass();
            return;
        }
        $entity->checkPermission("delete"); // UserApp has soft-delete, so we'll use the Node logic instead
        if (isset($this->data["id"])) { // propagate the request via API
            $entity->api->apiDelete("{$this->id}/single", []);
        }
        $single_url = $entity->singleUrl;
        Plugin::sudo(function () use ($entity) {
            $entity->userApp->destroy(true);
            return;
        });
        if ($app->request->isAjax()) {
            $this->json(true);
        } else {
            // e redireciona de volta para o referer
            $redirect_url = $app->request->getReferer();
            if ($redirect_url === $single_url) {
                $redirect_url = $app->createUrl("panel");
            }
            $app->applyHookBoundTo($this, "DELETE({$this->id}):beforeRedirect", [$entity, &$redirect_url]);
            $app->redirect($redirect_url);
        }
        return;
    }

    function createToken() {
        $app = App::i();

        $token = App::getToken(32);
        $secret = App::getToken(64);

        $verifier = (object) [
            'secret' => $secret,
            'userId' => $app->user->id
        ];

        // o tempo limite para o processo de vinculação ser concluído é de 5 minutos
        $app->cache->save("{$token}:secret", $secret, 300);
        $app->cache->save("{$token}:verifier", $verifier, 300);

        return $token;
    }

    function checkTokenSecret($token, $secret, $verify_user = false) {
        $app = App::i();

        return ($verifier = $app->cache->fetch("{$token}:verifier")) &&
                $verifier->secret === $secret &&
                (!$verify_user || $verifier->userId === $app->user->id);
    }

    function POST_create()
    {
        $this->requireAuthentication();
        $app = App::i();
        $url = $this->postData['url'];

        $create_token = $this->createToken();

        $app->cache->save("{$create_token}:url". $url, 300);

        $site_name = urlencode(base64_encode($app->siteName));
        $app->redirect("{$url}{$this->id}/connect?to={$app->baseUrl}&token={$create_token}&name={$site_name}");
    }

    function GET_filters() {
        $this->json($this->plugin->config['filters']);
    }

    /**
     * Verifica o token e, caso válido, retorna o segredo.
     *
     * Um token só é válido uma única vez e caso haja uma segunda tentativa de verificar,
     * o verificador que contém o segredo é apagado.
     */
    function GET_verifyConnectionToken()
    {
        $app = App::i();
        $token = $this->data['token'] ?? null;
        $return_token = $this->data['returnToken'] ?? null;
        if ($token){
            $secret = $app->cache->fetch("{$token}:secret");
            if ($secret) {
                $app->cache->delete("{$token}:secret");
                if ($return_token) {
                    $app->cache->save("{$token}:returnToken", $return_token, 300);
                }
                $app->log->debug("token válido: {$token}");
                $app->halt(200, $secret);
            } else {
                $app->log->debug("secret não encontrado: {$token}");
                $app->cache->delete("{$token}:verifier");
            }
        } else {
            $app->log->debug("token inválido: {$token}");
        }
    }

    function GET_connect()
    {
        $app = App::i();

        $timestamp = $_SESSION['mapas-network:timestamp'] ?? new DateTime('+300 seconds');
        $connect_to = $this->data['to'] ?? $_SESSION['mapas-network:to'] ?? null;
        $create_token = $this->data['token'] ?? $_SESSION['mapas-network:token'] ?? null;
        $name = base64_decode($this->data['name'] ?? null) ?? $_SESSION['mapas-network:name'] ?: $connect_to;
        $isConfirmed = $_SESSION['mapas-network:confirmed'] ?? null;

        $_SESSION['mapas-network:timestamp'] = $timestamp;
        $_SESSION['mapas-network:to'] = $connect_to;
        $_SESSION['mapas-network:token'] = $create_token;
        $_SESSION['mapas-network:name'] = $name;
        $_SESSION['mapas-network:confirmed'] = $isConfirmed;

        $this->requireAuthentication();

        // Verificar se já foi confirmado.
        // Redirecionar caso ainda não esteja confirmado
        if (!$isConfirmed) {
            $app->redirect($this->createUrl('linkAccounts'));
        }

        unset(
            $_SESSION['mapas-network:timestamp'],
            $_SESSION['mapas-network:to'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:name'],
            $_SESSION['mapas-network:confirmed']
        );

        if ($connect_to && $create_token) {
            $connect_token = $this->createToken();

            // verifica o token e recebe o segredo que será enviado no retorno
            $create_secret = file_get_contents("{$connect_to}{$this->id}/verifyConnectionToken?token={$create_token}&returnToken={$connect_token}");

            if ($create_secret) {
                // cria o App
                $user_app = new UserApp;
                $user_app->name = i::__('Rede Mapas') . ": {$name} ({$connect_to})";
                $user_app->save(true);

                $app->log->debug("user app criado: {$user_app->name}");

                $app->cache->save("{$connect_token}:userAppId", $user_app->id, 300);

                $site_name = urlencode(base64_encode($app->siteName));

                $app->redirect("{$connect_to}{$this->id}/return?from={$app->baseUrl}&token={$create_token}&s={$create_secret}&returnToken={$connect_token}&name={$site_name}");
            }
        }
    }

    function GET_return()
    {
        $app = App::i();

        $connect_from = $_SESSION['mapas-network:from'] ?? $this->data['from'] ?? null;
        $create_token = $_SESSION['mapas-network:token'] ?? $this->data['token'] ?? null;
        $create_secret = $_SESSION['mapas-network:secret'] ?? $this->data['s'] ?? null;
        $connect_token = $_SESSION['mapas-network:returnToken'] ?? $this->data['returnToken'] ?? null;
        $name = $_SESSION['mapas-network:name'] ?? base64_decode($this->data['name'] ?? null) ?: $connect_from;

        $_SESSION['mapas-network:from'] = $connect_from;
        $_SESSION['mapas-network:token'] = $create_token;
        $_SESSION['mapas-network:secret'] = $create_secret;
        $_SESSION['mapas-network:returnToken'] = $connect_token;
        $_SESSION['mapas-network:name'] = $name;

        $this->requireAuthentication();

        unset(
            $_SESSION['mapas-network:from'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:secret'],
            $_SESSION['mapas-network:returnToken'],
            $_SESSION['mapas-network:name']
        );

        if ($connect_token && $this->checkTokenSecret($create_token, $create_secret, true)) {
            $connect_secret = file_get_contents("{$connect_from}{$this->id}/verifyConnectionToken?token={$connect_token}");
            if ($connect_secret) {
                $keys = json_decode(file_get_contents("{$connect_from}{$this->id}/getKeys?token={$connect_token}&s=$connect_secret"));
                $node = new NodeEntities\Node;
                $node->url = $connect_from;
                $node->status = 1;
                $node->name = "$name ($connect_from)";
                // cria o App
                $user_app = new UserApp;
                $user_app->name = i::__("Rede Mapas") . ": {$name} ({$connect_from})";
                $user_app->save(true);
                $node->userApp = $user_app;
                $node->save(true);
                $node->setKeyPair($keys[0], $keys[1]);
                // create a proxy user for entities that may need to be imported for Event sync
                $this->createProxyUser($node, $name);
                $node->api->apiPost("{$this->id}/finish", [
                    "token" => $connect_token,
                    "publicKey" => $user_app->getPublicKey(),
                    "privateKey" => $user_app->getPrivateKey(),
                    "connect_to" => $app->baseUrl,
                    "name" => $app->siteName
                ]);
                $app->redirect($this->createUrl('panel'));
            }
        }
    }

    function GET_getKeys()
    {
        $connect_token = $this->data['token'] ?? null;
        $connect_secret = $this->data['s'] ?? null;

        if ($this->checkTokenSecret($connect_token, $connect_secret)) {
            $app = App::i();
            $user_app_id = $app->cache->fetch("$connect_token:userAppId");
            $user_app = $app->repo('UserApp')->find($user_app_id);

            $this->json([$user_app->publicKey, $user_app->privateKey]);
        }
    }

    function POST_finish()
    {
        $this->requireAuthentication();
        $app = App::i();
        $connect_from = $this->postData["connect_to"];
        $site_name = $this->postData["name"];
        $public_key = $this->postData["publicKey"];
        $private_key = $this->postData["privateKey"];
        $connect_token = $this->postData["token"];
        $node = new NodeEntities\Node;
        $node->url = $connect_from;
        $node->status = 1;
        $node->name = "$site_name ($connect_from)";
        $user_app_id = $app->cache->fetch("$connect_token:userAppId");
        $node->userApp = $app->repo("UserApp")->find($user_app_id);
        $node->save(true);
        $node->setKeyPair($public_key, $private_key);
        // create a proxy user for entities that may need to be imported for Event sync
        $this->createProxyUser($node, $site_name);
        return;
    }

    function GET_cancelAccountLink()
    {
        $app = App::i();

        $url = $_SESSION['mapas-network:to'] . '/network-node/panel/';

        unset(
            $_SESSION['mapas-network:to'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:name']
        );

        $app->redirect($url);
    }

    function POST_createdEntity()
    {
        $this->requireAuthentication();
        $app = App::i();
        $class_name = $this->postData["className"];
        $network_id = $this->postData["network__id"];
        $data = $this->postData["data"];
        if (isset($data[$this->plugin->entityMetadataKey])) {
            $this->json("ok");
            return;
        }
        $classes = [
            Agent::class,
            Space::class,
        ];
        if (!in_array($class_name, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, "create");
        }
        $node = $this->getRequestOriginNode();
        // verifica se a entidade já existe para o usuário
        if ($id = $this->findEntityId($class_name, $network_id)) {
            /**
             * aproveita a requisição para atualizar o id da entidade no outro nó,
             * desta forma a propagação dos
             */
            $entity = $app->repo($class_name)->find($id);
            $entity->{$node->entityMetadataKey} = $data["id"];
            $entity->save(true);
            $app->log->debug("$network_id already exists with id {$id}");
            $this->json("$network_id already exists with id {$id}");
            return;
        } else { // tries to locate the entity among existing entities to link
            $enabled_list = ($class_name == Agent::class) ? $app->user->enabledAgents : $app->user->enabledSpaces;
            foreach ($enabled_list as $local) {
                if ($this->compareEntityData($local, $data)) {
                    $original_data = $data;
                    $data = $this->mergeEntity($local, $data, $node);
                    $this->writeEntityFields($local, $data);
                    $local->{$node->entityMetadataKey} = $original_data["id"];
                    $this->verifyAndUpdateNetworkId($local, $original_data);
                    if (($local->usesFiles() && !empty($local->getFiles())) ||
                        ($local->usesMetaLists() && !empty($local->getMetaLists()))) {
                        App::i()->enqueueJob(Plugin::JOB_SLUG, [
                            "syncAction" => Plugin::ACTION_RESYNC,
                            "entity" => $local,
                            "node" => $node,
                            "nodeSlug" => $node->slug
                        ]);
                    }
                    $local->save(true);
                    $app->log->debug("LINKED: {$local} => {$local->network__id}");
                    return;
                }
            }
        }
        // entity not found, could be either an actual create or a scoped, in which case files and metalists must be handled
        $files = $data["files"] ?? null;
        $metalists = $data["metalists"] ?? null;
        $data = $this->plugin->unserializeEntity($data, $node);
        $entity = $this->plugin->createEntity($class_name, $network_id, $data);
        if ($files) {
            $this->bootstrapFiles($entity, $files, $data, $node);
        }
        if ($metalists) {
            $this->bootstrapMetaLists($entity, $metalists, $data, $node);
        }
        return;
    }

    function POST_createdEventOccurrence()
    {
        $this->requireAuthentication();
        $app = App::i();
        $class_name = $this->postData["className"];
        $data = $this->postData["data"];
        $network_id = $data["network__id"];
        $node = $this->getRequestOriginNode();
        $app->log->debug("createdEventOccurrence: $network_id");
        if (isset($data[$this->plugin->entityMetadataKey])) {
            $this->json("ok");
            return;
        }
        if ($class_name !== EventOccurrence::class) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, "create");
        }
        // verifica se o evento já existe neste nó, e se a ocorrência já existe
        $event = $this->plugin->unserializeEntity($data["event"]);
        if ($event && isset($event->network__occurrence_ids->$network_id)) {
            $id = $event->network__occurrence_ids->$network_id;
            /**
             * aproveita a requisição para atualizar o id da entidade no outro nó,
             * desta forma a propagação dos
             */
            $entity = $app->repo($class_name)->find($id);
            $entity->{$node->entityMetadataKey} = $data["id"];
            $event->{$node->entityMetadataKey} = $data["event"]["id"];
            $event->save(true);
            Plugin::sudo(function () use ($entity) {
                $entity->save(true); // this is an existing, authorised occurrence, but without "sudo" it'll generate a request to save
                return;
            });
            $app->log->debug("$network_id already exists with id {$id}");
            $this->json("$network_id already exists with id {$id}");
            return;
        }
        // unlike event, space comes as embedded data, so we still need to look for the entity
        $space = $this->plugin->unserializeEntity($data["space"]);
        $space_entity = $this->plugin->getEntityByNetworkId($space["network__id"]);
        if (!$space_entity) {
            if (!$space["owner"]) {
                $id = Plugin::getProxyUserIDForNode($node->slug);
                if (!$id) {
                    throw new \Exception("The proxy user for {$node->slug} does not exist.");
                }
                $proxy_user = $app->repo("User")->find($id);
                $space["owner"] = $proxy_user->profile;
                $space["network__proxied_owner"] = $data["space"]["owner"];
            }
            $plugin = $this->plugin;
            // the space's owner isn't necessarily the event's owner so this must be sudone
            Plugin::sudo(function () use ($node, $plugin, $space) {
                $space_entity = $plugin->createEntity(Plugin::getClassFromNetworkID($space["network__id"]), $space["network__id"], $space);
                $space_entity->{$node->entityMetadataKey} = $space["id"];
                $space_entity->save(true);
                return;
            });
        }
        $data["space"] = "@entity:{$space["network__id"]}";
        if ($event && $space) {
            $data = $this->plugin->unserializeEntity($data);
            $plugin = $this->plugin;
            Plugin::sudo(function () use ($class_name, $data, $event, $network_id, $plugin) {
                $ids_map = ((array) $event->network__occurrence_ids) ?? [];
                $ids_map[$network_id] = Plugin::UNKNOWN_ID;
                $event->network__occurrence_ids = $ids_map;
                $plugin->createEntity($class_name, $network_id, $data);
                return;
            });
        } else { // if we need to grab the event, best to do so after we've replied to the POST
            if (preg_match("#@entity:(.*)#", $data["event"], $event_id)) {
                $app->enqueueJob(Plugin::JOB_SLUG_EVENT, [
                    "event" => $event_id[0],
                    "space" => $data["space"],
                    "node" => $node,
                    "nodeSlug" => $node->slug,
                    "data" => $data
                ]);
            }
        }
        $this->json("OK");
        return;
    }

    function POST_createdFile()
    {
        $this->requireAuthentication();
        $app = App::i();
        $owner_class = $this->postData["ownerClassName"];
        $owner_network_id = $this->postData["ownerNetworkID"];
        $class_name = $this->postData["className"];
        $network_id = $this->postData["network__id"];
        $data = $this->plugin->unserializeEntity($this->postData["data"]);
        $group = $data["group"];
        $revision_key = "network__revisions_files_$group";
        $network_ids_key = "network__ids_files_$group";
        $revisions = $this->postData[$revision_key];
        $revision_id = isset($revisions) ? end($revisions) : null;
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($owner_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "create file");
        }
        // obtain the owner entity
        if ($id = $this->findEntityId($owner_class, $owner_network_id)) {
            $owner = $app->repo($owner_class)->find($id);
            $owner->$revision_key = $owner->$revision_key ?? [];
            $owner->$network_ids_key = $owner->$network_ids_key ?? [];
            if (isset($owner->$network_ids_key->$network_id) ||
                in_array($revision_id, $owner->$revision_key)) {
                $this->json("$network_id $revision_id already exists");
                return;
            }
            // since the whole group is treated as one thing as far as revisions go, insertion is a revision
            $revisions = $owner->$revision_key;
            $revisions[] = $revision_id;
            $owner->$revision_key = $revisions;
            // save the new entry's network ID (as placeholder)
            $network_ids = (array) $owner->$network_ids_key;
            $network_ids[$network_id] = Plugin::UNKNOWN_ID;
            $owner->$network_ids_key = $network_ids;
            // stop network and revision IDs from being created again
            $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
            // save only the owner since the IDs are kept in the owner and the entry doesn't exist yet
            $owner->save(true);
            // enqueue the download
            $app->enqueueJob(Plugin::JOB_SLUG_DOWNLOADS, [
                "node" => $this->getRequestOriginNode(),
                "user" => $app->user->id,
                "networkID" => $network_id,
                "className" => $class_name,
                "ownerClassName" => $owner_class,
                "ownerNetworkID" => $owner_network_id,
                "data" => $data
            ]);
        }
        return;
    }

    function POST_createdMetaList()
    {
        $this->requireAuthentication();
        $app = App::i();
        $owner_class = $this->postData["ownerClassName"];
        $owner_network_id = $this->postData["ownerNetworkID"];
        $class_name = $this->postData["className"];
        $network_id = $this->postData["network__id"];
        $data = $this->plugin->unserializeEntity($this->postData["data"]);
        $group = $data["group"];
        $revision_key = "network__revisions_metalist_$group";
        $network_ids_key = "network__ids_metalist_$group";
        $revisions = $this->postData[$revision_key];
        $revision_id = isset($revisions) ? end($revisions) : null;
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($owner_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "create metalist");
        }
        // obtain the owner entity
        if ($id = $this->findEntityId($owner_class, $owner_network_id)) {
            $owner = $app->repo($owner_class)->find($id);
            $owner->$revision_key = $owner->$revision_key ?? [];
            $owner->$network_ids_key = $owner->$network_ids_key ?? [];
            if (isset($owner->$network_ids_key->$network_id) ||
                in_array($revision_id, $owner->$revision_key)) {
                $this->json("$network_id $revision_id already exists");
                return;
            }
            // since the whole group is treated as one thing as far as revisions go, insertion is a revision
            $revisions = $owner->$revision_key;
            $revisions[] = $revision_id;
            $owner->$revision_key = $revisions;
            // create the item and associate it to the owner
            $metalists = $owner->metalists;
            $new_item = new $class_name();
            $new_item->owner = $owner;
            $new_item->group = $group;
            $new_item->title = $data["title"];
            $new_item->value = $data["value"];
            if (isset($data["description"])) {
                $new_item->description = $data["description"];
            }
            if (!isset($metalists[$group])) {
                $metalists[$group] = [];
            }
            $metalists[$group][] = $new_item;
            $owner->metalists = $metalists;
            // save the new entry's network ID (as placeholder)
            $network_ids = (array) $owner->$network_ids_key;
            $network_ids[$network_id] = Plugin::UNKNOWN_ID;
            $owner->$network_ids_key = $network_ids;
            // stop network and revision IDs from being created again
            $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
            // both owner and new entry must be saved since the IDs are kept in the owner
            $owner->save(true);
            $new_item->save(true);
        }
        return;
    }

    function POST_deletedEventOccurrence()
    {
        $this->requireAuthentication();
        $app = App::i();
        $event_class = $this->postData["ownerClassName"];
        $event_network_id = $this->postData["ownerNetworkID"];
        $network_id = $this->postData["network__id"];
        // obtain the owner entity
        if ($id = $this->findEntityId($event_class, $event_network_id)) {
            $event = $app->repo($event_class)->find($id);
            $event->network__occurrence_ids = $event->network__occurrence_ids ?? [];
            // delete the item
            $id = $event->network__occurrence_ids->$network_id ?? null;
            if (!$id) { // silently exit; the item may have already been deleted
                return;
            }
            $item = $app->repo("EventOccurrence")->find($id);
            // stop revision ID from being created again
            $this->plugin->skip($event, [Plugin::SKIP_BEFORE]);
            // the owner must be saved since the IDs are kept there
            $event->save(true);
            $item->delete(true);
        }
        return;
    }

    function POST_descopedEventOccurrence()
    { // the name of the endpoint is used in the hooks, do not unify these
        $this->deletedEventOccurrence();
        return;
    }

    function POST_deletedEntity()
    {
        $this->requireAuthentication();
        $app = App::i();
        $entity_class = $this->postData["className"];
        $network_id = $this->postData["network__id"];
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($entity_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "delete entity");
        }
        // obtain the entity
        if ($id = $this->findEntityId($entity_class, $network_id)) {
            $entity = $app->repo($entity_class)->find($id);
            $entity->delete(true);
        }
        return;
    }

    function POST_descopedEntity()
    { // the name of the endpoint is used in the hooks, do not unify these
        $this->POST_deletedEntity();
        return;
    }

    function POST_deletedFile()
    {
        $this->requireAuthentication();
        $app = App::i();
        $owner_class = $this->postData["ownerClassName"];
        $owner_network_id = $this->postData["ownerNetworkID"];
        $network_id = $this->postData["network__id"];
        $group = $this->postData["group"];
        $revision_key = "network__revisions_files_$group";
        $network_ids_key = "network__ids_files_$group";
        $revisions = $this->postData[$revision_key];
        $revision_id = isset($revisions) ? end($revisions) : null;
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($owner_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "delete file");
        }
        // obtain the owner entity
        if ($id = $this->findEntityId($owner_class, $owner_network_id)) {
            $owner = $app->repo($owner_class)->find($id);
            $owner->$revision_key = $owner->$revision_key ?? [];
            $owner->$network_ids_key = $owner->$network_ids_key ?? [];
            if (in_array($revision_id, $owner->$revision_key)) {
                $this->json("$network_id $revision_id already exists");
                return;
            }
            // add a revision
            $revisions = $owner->$revision_key;
            $revisions[] = $revision_id;
            $owner->$revision_key = $revisions;
            // delete the item
            $id = $owner->$network_ids_key->$network_id ?? null;
            if (!$id) { // silently exit; the item may have already been deleted
                return;
            }
            $item = $app->repo("File")->find($id);
            // stop revision ID from being created again
            $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
            // the owner must be saved since the IDs are kept there
            if ($item) {
                $item->delete(true);
            } else { // edge case, remove network_id for deleted item
                $network_ids = (array) $owner->$network_ids_key;
                unset($network_ids[$network_id]);
                $owner->$network_ids_key = $network_ids;
            }
            $owner->save(true);
        }
        return;
    }

    function POST_deletedMetaList()
    {
        $this->requireAuthentication();
        $app = App::i();
        $owner_class = $this->postData["ownerClassName"];
        $owner_network_id = $this->postData["ownerNetworkID"];
        $network_id = $this->postData["network__id"];
        $group = $this->postData["group"];
        $revision_key = "network__revisions_metalist_$group";
        $network_ids_key = "network__ids_metalist_$group";
        $revisions = $this->postData[$revision_key];
        $revision_id = isset($revisions) ? end($revisions) : null;
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($owner_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "delete metalist");
        }
        // obtain the owner entity
        if ($id = $this->findEntityId($owner_class, $owner_network_id)) {
            $owner = $app->repo($owner_class)->find($id);
            $owner->$revision_key = $owner->$revision_key ?? [];
            $owner->$network_ids_key = $owner->$network_ids_key ?? [];
            if (in_array($revision_id, $owner->$revision_key)) {
                $this->json("$network_id $revision_id already exists");
                return;
            }
            // add a revision
            $revisions = $owner->$revision_key;
            $revisions[] = $revision_id;
            $owner->$revision_key = $revisions;
            // delete the item
            $id = $owner->$network_ids_key->$network_id;
            if (!$id) { // silently exit; the item may have already been deleted
                return;
            }
            $item = $app->repo("MetaList")->find($id);
            // stop revision ID from being created again
            $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
            // the owner must be saved since the IDs are kept there
            $owner->save(true);
            $item->delete(true);
        }
        return;
    }

    function POST_scopedEntity()
    { // the name of the endpoint is used for decisions, do not unify
        $this->POST_createdEntity();
        return;
    }

    function POST_syncControl()
    {
        $this->requireAuthentication();
        $app = App::i();
        $network_id = $this->postData["network__id"];
        $value = ($this->postData["value"] == "true") ? Plugin::SYNC_ON : Plugin::SYNC_OFF;
        $entity = $this->plugin->getEntityByNetworkId($network_id);
        if ($entity->ownerUser->id != $app->user->id) {
            throw new PermissionDenied($app->user, $entity, "control synchronization of");
        }
        $entity->network__sync_control = $value;
        $entity->save(true);
        return;
    }

    function POST_updatedEntity()
    {
        $this->requireAuthentication();
        $app = App::i();
        $class_name = $this->postData["className"];
        $network_id = $this->postData["network__id"];
        $data = $this->postData["data"];
        $revision_id = end($data["network__revisions"]);
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($class_name, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, "update");
        }
        // verifica se a entidade já existe para o usuário
        if ($id = $this->findEntityId($class_name, $network_id)) {
            $entity = $app->repo($class_name)->find($id);
            $node = $this->getRequestOriginNode();
            if (isset($data["files"])) {
                $this->bootstrapFiles($entity, $data["files"], $data, $node);
            }
            if (isset($data["metalists"])) {
                $this->bootstrapMetaLists($entity, $data["metalists"], $data, $node);
            }
            $metakey = $node->entityMetadataKey;
            $entity->network__revisions = $entity->network__revisions ?? [];
            if (in_array($revision_id, $entity->network__revisions)) {
                $this->json("$network_id $revision_id already exists");
                if (!($entity->$metakey ?? null)) {
                    $app->log->debug("Saving $metakey for node {$node->slug}.");
                    $entity->$metakey = $data["id"];
                    $this->plugin->skip($entity, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                    $entity->save(true);
                }
                return;
            }
            $revisions = $entity->network__revisions;
            $revisions[] = $revision_id;
            $entity->$metakey = $data["id"];
            $entity->network__revisions = $revisions;
            $this->writeEntityFields($entity, $data);
            $this->plugin->skip($entity, [Plugin::SKIP_BEFORE]);
            $entity->save(true);
        }
        return;
    }

    function POST_updatedEventOccurrence()
    {
        $this->requireAuthentication();
        $app = App::i();
        $class_name = $this->postData["className"];
        $data = $this->postData["data"];
        $network_id = $data["network__id"];
        $revision_id = end($data["network__revisions"]);
        if ($class_name !== EventOccurrence::class) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, "update");
        }
        $event = $this->plugin->unserializeEntity($data["event"]);
        $revisions = (array) $event->network__occurrence_revisions;
        if (in_array($revision_id, ($revisions[$network_id] ?? []))) {
            $this->json("$network_id $revision_id already exists");
            return;
        }
        $revisions[$network_id][] = $revision_id;
        // unlike event, space comes as embedded data, so we still need to look for the entity
        $space = $this->plugin->unserializeEntity($data["space"]);
        $node = $this->getRequestOriginNode();
        $space_entity = $this->plugin->getEntityByNetworkId($space["network__id"]);
        if (!$space_entity) {
            if (!$space["owner"]) {
                $id = Plugin::getProxyUserIDForNode($node->slug);
                if (!$id) {
                    throw new \Exception("The proxy user for {$node->slug} does not exist.");
                }
                $proxy_user = $app->repo("User")->find($id);
                $space["owner"] = $proxy_user->profile;
                $space["network__proxied_owner"] = $data["space"]["owner"];
            }
            $plugin = $this->plugin;
            // the space's owner isn't necessarily the event's owner so this must be sudone
            Plugin::sudo(function () use ($plugin, $space) {
                $space_entity = $plugin->createEntity(Plugin::getClassFromNetworkID($space["network__id"]), $space["network__id"], $space);
                $space_entity->save(true);
                return;
            });
        }
        $data["space"] = "@entity:{$space["network__id"]}";
        $data = $this->plugin->unserializeEntity($data);
        Plugin::sudo(function () use ($app, $class_name, $data, $network_id, $revisions) {
            $event = $data["event"];
            $ids_map = ((array) $event->network__occurrence_ids) ?? [];
            $event->network__occurrence_ids = $ids_map;
            $event->network__occurrence_revisions = $revisions;
            $entity = $app->repo($class_name)->find($ids_map[$network_id]);
            $this->writeEntityFields($entity, $data);
            // stop revision ID from being created again
            $this->plugin->skip($event, [Plugin::SKIP_BEFORE]);
            $event->save(true);
            $entity->save(true);
            return;
        });
        $this->json("OK");
        return;
    }

    function POST_updatedMetaList()
    {
        $this->requireAuthentication();
        $app = App::i();
        $owner_class = $this->postData["ownerClassName"];
        $owner_network_id = $this->postData["ownerNetworkID"];
        $network_id = $this->postData["network__id"];
        $data = $this->plugin->unserializeEntity($this->postData["data"]);
        $group = $data["group"];
        $revision_key = "network__revisions_metalist_$group";
        $network_ids_key = "network__ids_metalist_$group";
        $revisions = $this->postData[$revision_key];
        $revision_id = isset($revisions) ? end($revisions) : null;
        $classes = [
            Agent::class,
            Event::class,
            Space::class,
        ];
        if (!in_array($owner_class, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user,
                                       "update metalist");
        }
        // obtain the owner entity
        if ($id = $this->findentityId($owner_class, $owner_network_id)) {
            $owner = $app->repo($owner_class)->find($id);
            $owner->$revision_key = $owner->$revision_key ?? [];
            $owner->$network_ids_key = $owner->$network_ids_key ?? [];
            if (in_array($revision_id, $owner->$revision_key)) {
                $this->json("$network_id $revision_id already exists");
                return;
            }
            // add a revision
            $revisions = $owner->$revision_key;
            $revisions[] = $revision_id;
            $owner->$revision_key = $revisions;
            // update the item
            $id = $owner->$network_ids_key->$network_id;
            if (!$id) {
                $this->errorJson("The item $network_id does not exist.", 404);
                return;
            }
            $item = $app->repo("MetaList")->find($id);
            $item->title = $data["title"];
            $item->value = $data["value"];
            $item->description = $data["description"] ?? null;
            // stop revision ID from being created again
            $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
            // both owner and entry must be saved since the IDs are kept in the owner
            $owner->save(true);
            $item->save(true);
        }
        return;
    }

    protected function writeEntityFields(\MapasCulturais\Entity $entity, $data)
    {
        $node = $this->getRequestOriginNode();
        $data = $this->plugin->unserializeEntity($data, $node);
        Plugin::convertEntityData($entity, $data);
        return;
    }

    function GET_entity()
    {
        $this->requireAuthentication();
        $network__id = $this->data['network__id'];

        if (!$network__id) {
            $this->errorJson("network__id is required", 400);
            return;
        }

        $entity = $this->plugin->getEntityByNetworkId($network__id);

        if (!$entity) {
            $this->errorJson("entity not found", 404);
        }

        $entity->checkPermission('@control');

        $data = $this->plugin->serializeEntity($entity);

        $this->json($data);
    }

    function POST_bootstrapSync()
    {
        $this->requireAuthentication();
        $app = App::i();
        $origin_node = $this->getRequestOriginNode();
        $inputs = [
            ["local" => $app->user->enabledAgents, "remote" => ($this->postData["agents"] ?? [])],
            ["local" => $app->user->enabledSpaces, "remote" => ($this->postData["spaces"] ?? [])]
        ];
        foreach ($inputs as $input) {
            foreach ($input["remote"] as $foreign_data) {
                $linked = false;
                foreach ($input["local"] as $entity) {
                    if ($this->compareEntityData($entity, $foreign_data)) {
                        $linked = true;
                        $entity->{$origin_node->entityMetadataKey} = $foreign_data["id"];
                        $data = $this->mergeEntity($entity, $foreign_data, $origin_node);
                        $this->writeEntityFields($entity, $data);
                        $this->verifyAndUpdateNetworkId($entity, $foreign_data);
                        $entity->save(true);
                        $app->log->debug("LINKED: {$entity} => {$entity->network__id}");
                    }
                }
                if (!$linked) {
                    $metakey = $origin_node->entityMetadataKey;
                    $foreign_data[$metakey] = $foreign_data["id"];
                    $this->plugin->createEntity($entity->getClassName(), $foreign_data["network__id"], $foreign_data);
                }
            }
        }
        return;
    }

    function POST_resyncEntity()
    { // the name of the endpoint is used for decisions, do not unify
        $this->POST_updatedEntity();
        return;
    }

    function POST_updateEntityNetworkId()
    {
        $this->requireAuthentication();
        $app = App::i();
        $class_name = $this->data["className"];
        $current_network__id = $this->data["current_network__id"];
        $new_network__id = $this->data["new_network__id"];
        if ($current_network__id == $new_network__id) {
            $this->errorJson("current_network__id and new_network__id are the same", 400);
        }
        $classes = [
            Agent::class,
            Space::class,
        ];
        if (!in_array($class_name, $classes)) {
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, "update");
        }
        if ($id = $this->findEntityId($class_name, $current_network__id)) {
            $entity = $app->repo($class_name)->find($id);
            $this->plugin->skip($entity, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            $entity->network__id = $new_network__id;
            $entity->save(true);
            $app->log->debug("UPDATED: {$entity} => {$entity->network__id}");
            $skip_node = $this->getRequestOriginNode();
            $app->enqueueJob(Plugin::JOB_UPDATE_NETWORK_ID, [
                "entity" => $entity,
                "node" => $skip_node,
                "current_network__id" => $current_network__id,
                "new_network__id" => $new_network__id
            ]);
        }
    }

    function POST_verifyAccount()
    {
        $app = App::i();
        $conn = $app->em->getConnection();

        $emails_in = [];
        $params = [];
        foreach($this->postData['emails'] ?? [] as $i => $mail) {
            $key = "mail_{$i}";
            $emails_in[] = ':'.$key;
            $params[$key] = $mail;
        }
        $emails_in = implode(', ', $emails_in);

        $docs_in = [];
        foreach($this->postData['documents'] ?? [] as $i => $doc) {
            $key = "doc_{$i}";
            $docs_in[] = ':'.$key;
            $params[$key] = preg_replace('#[^\d]#','',$doc);
        }
        $docs_in = implode(',', $docs_in);

        if ($emails_in && $docs_in) {
            $sql = "SELECT count(distinct(a.user_id))
                    FROM agent a
                        JOIN usr u ON u.id = a.user_id
                        LEFT JOIN agent_meta email_pub ON email_pub.object_id = a.id AND email_pub.key = 'emailPublico'
                        LEFT JOIN agent_meta email_priv ON email_priv.object_id = a.id AND email_priv.key = 'emailPrivado'
                        LEFT JOIN agent_meta doc ON doc.object_id = a.id AND doc.key = 'documento'
                    WHERE
                        u.email IN({$emails_in}) OR
                        email_pub.value IN({$emails_in}) OR
                        email_priv.value IN({$emails_in}) OR
                        REGEXP_REPLACE(doc.value,'[^0-9]','','g') IN({$docs_in})";
        } else if ($emails_in) {
            $sql = "SELECT count(distinct(a.user_id))
                    FROM agent a
                        JOIN usr u ON u.id = a.user_id
                        LEFT JOIN agent_meta email_pub ON email_pub.object_id = a.id AND email_pub.key = 'emailPublico'
                        LEFT JOIN agent_meta email_priv ON email_priv.object_id = a.id AND email_priv.key = 'emailPrivado'
                    WHERE
                        u.email IN({$emails_in}) OR
                        email_pub.value IN({$emails_in}) OR
                        email_priv.value IN({$emails_in})";

        } else if ($docs_in) {
            $sql = "SELECT count(distinct(a.user_id))
                    FROM agent a
                        LEFT JOIN agent_meta doc ON doc.object_id = a.id AND doc.key = 'documento'
                    WHERE
                        REGEXP_REPLACE(doc.value,'[^0-9]','','g') IN({$docs_in})";
        }

        $result = $conn->fetchColumn($sql, $params);
        $this->json($result);
    }

    function bootstrapFiles(Entity $entity, array $foreign_data, array $foreign_entity, NodeEntities\Node $node)
    {
        $app = App::i();
        $file_groups = array_keys($app->getRegisteredFileGroupsByEntity($entity));
        foreach ($foreign_data as $group => $group_data) {
            if (!in_array($group, $file_groups)) {
                continue;
            }
            $revision_key = "network__revisions_files_$group";
            $network_ids_key = "network__ids_files_$group";
            $revisions = $entity->$revision_key ?? [];
            $revisions[] = end($foreign_entity[$revision_key]);
            $entity->$revision_key = $revisions;
            if (isset($group_data["id"])) {
                $network_id = array_keys($foreign_entity[$network_ids_key])[0];
                $this->enqueueResyncDownload($node, $entity, $network_ids_key, $network_id, $foreign_entity, $group_data);
            } else {
                foreach ($group_data as $file) {
                    if (count(array_filter(($entity->files[$group] ?? []), function ($item) use ($file) {
                        return ($item->md5 == $file["md5"]);
                    })) > 0) {
                        continue;
                    }
                    $network_id = array_search($file["id"], $foreign_entity[$network_ids_key]);
                    $this->enqueueResyncDownload($node, $entity, $network_ids_key, $network_id, $foreign_entity, $file);
                }
            }
        }
        $entity->save(true);
        return;
    }

    function bootstrapMetaLists(Entity $entity, array $foreign_data, array $foreign_entity)
    {
        $app = App::i();
        $list_groups = array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity($entity)), $this->plugin->allowedMetaListGroups);
        foreach ($foreign_data as $group => $group_data) {
            if (!in_array($group, $list_groups)) {
                continue;
            }
            $revision_key = "network__revisions_metalist_$group";
            $network_ids_key = "network__ids_metalist_$group";
            $revisions = $entity->$revision_key ?? [];
            $revisions[] = end($foreign_entity[$revision_key]);
            $entity->$revision_key = $revisions;
            foreach ($group_data as $list_item) {
                if (count(array_filter(($entity->metalists[$group] ?? []), function ($item) use ($list_item) {
                    return (($item->title == ($list_item["title"] ?? null)) &&
                            ($item->value == ($list_item["value"] ?? null)) &&
                            ($item->description == ($list_item["description"] ?? null)));
                })) > 0) {
                    continue;
                }
                $network_id = array_search($list_item["id"], $foreign_entity[$network_ids_key]);
                $metalists = $entity->metalists;
                $new_item = new \MapasCulturais\Entities\MetaList();
                $new_item->owner = $entity;
                $new_item->group = $group;
                $new_item->title = $list_item["title"];
                $new_item->value = $list_item["value"];
                if (isset($list_item["description"])) {
                    $new_item->description = $list_item["description"];
                }
                if (!isset($metalists[$group])) {
                    $metalists[$group] = [];
                }
                $metalists[$group][] = $new_item;
                $entity->metalists = $metalists;
                // save the new entry's network ID (as placeholder)
                $network_ids = (array) $entity->$network_ids_key;
                $network_ids[$network_id] = Plugin::UNKNOWN_ID;
                $entity->$network_ids_key = $network_ids;
                // stop network and revision IDs from being created again
                $this->plugin->skip($entity, [Plugin::SKIP_BEFORE]);
                // save the new entry
                $new_item->save(true);
            }
            // the owner must be saved since the IDs are kept in it
            $entity->save(true);
        }
        return;
    }

    function createProxyUser(EntitiesNode $node, string $name)
    {
        if (Plugin::getProxyUserIDForNode($node->slug)) {
            return;
        }
        Plugin::sudo(function () use ($node, $name) {
            $app = App::i();
            $new_user = new \MapasCulturais\Entities\User;
            $new_user->authProvider = __CLASS__;
            $auth_uid = uniqid();
            $auth_uid = "{$node->slug}.{$auth_uid}@MapasNetwork";
            $new_user->authUid = $auth_uid;
            $new_user->email = $auth_uid;
            $new_user->network__proxy_slug = $node->slug;
            $app->em->persist($new_user);
            $app->em->flush();
            $agent = new Agent($new_user);
            $agent->name = $name;
            $agent->type = 2;
            $agent->status = Agent::STATUS_ENABLED;
            $agent->save();
            $app->em->flush();
            $new_user->profile = $agent;
            $new_user->save(true);
            return;
        });
        return;
    }

    function compareEntityData(Entity $entity, array $foreign_data) {
        if($entity instanceof Agent) {
            return $this->compareAgentData($entity, $foreign_data);
        } else if ($entity instanceof Space) {
            return $this->compareSpaceData($entity, $foreign_data);
        }
    }

    function compareAgentData(Agent $agent, array $foreign_data) {
        $data = (object) $foreign_data;

        // verifica se é o mesmo tipo
        if ($data->type != (string) $agent->type) {
            return false;
        }

        $anetwork__id = $agent->network__id ?? '';
        $fnetwork__id = $data->network__id ?? '';
        if ($anetwork__id && $anetwork__id == $fnetwork__id) {
            return true;
        }

        //verifica se o número de documento é igual
        $fdoc = preg_replace('#[^0-9]#', '', $data->documento ?? '');
        $adoc = preg_replace('#[^0-9]#', '', $agent->documento ?? '');

        if ($adoc && $fdoc == $adoc) {
            return true;
        }

        if ($data->nomeCompleto ?? null) {
            $fname = Utils::slugify($data->nomeCompleto);
            $aname = Utils::slugify($agent->nomeCompleto ?? '');

            if(Utils::isTheSameName($fname, $aname)) {
                return true;
            }
        }

        if ($data->name ?? null) {
            $fname = Utils::slugify($data->name);
            $aname = Utils::slugify($agent->name ?? '');

            if(Utils::isTheSameName($fname, $aname)) {
                return true;
            }
        }

        return false;
    }

    function compareSpaceData(Space $space, array $foreign_data) {
        $data = (object) $foreign_data;

        // verifica se é o mesmo tipo
        if ($data->type != (string) $space->type) {
            return false;
        }

        $anetwork__id = $space->network__id ?? '';
        $fnetwork__id = $data->network__id ?? '';
        if ($anetwork__id && $anetwork__id == $fnetwork__id) {
            return true;
        }

        //verifica se o número de documento é igual
        $fdoc = preg_replace('#[^0-9]#', '', $data->cnpj ?? '');
        $adoc = preg_replace('#[^0-9]#', '', $space->cnpj ?? '');

        if ($adoc && $fdoc == $adoc) {
            return true;
        }

        if ($data->name ?? null) {
            $fname = Utils::slugify($data->name);
            $aname = Utils::slugify($space->name ?? '');

            if(Utils::isTheSameName($fname, $aname)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Enfileira o download de um arquivo em um processo de ressincronização.
     * @param $node O nó de origem do arquivo.
     * @param $entity A entidade que dever receber o arquivo.
     * @param $network_ids_key A chave com os network__id dos arquivos do grupo.
     * @param $network_id O network__id do arquivo.
     * @param $owner_data Os dados recebidos sobre o proprietário do arquivo.
     * @param $data Os dados recebidos sobre o arquivo.
     */
    function enqueueResyncDownload(EntitiesNode $node, Entity $entity, string $network_ids_key,
                                   string $network_id, array $owner_data, array $data)
    {
        $app = App::i();
        $network_ids = isset($entity->$network_ids_key) ? (array) $entity->$network_ids_key : [];
        $network_ids[$network_id] = Plugin::UNKNOWN_ID;
        $entity->$network_ids_key = $network_ids;
        $app->enqueueJob(Plugin::JOB_SLUG_DOWNLOADS, [
            "node" => $node,
            "user" => $app->user->id,
            "networkID" => $network_id,
            "className" => $entity->fileClassName,
            "ownerClassName" => $entity->className,
            "ownerNetworkID" => $entity->network__id,
            "ownerSourceNetworkID" => $owner_data["network__id"],
            "data" => $data
        ]);
        return;
    }

    /**
     * Encontra o id local da entidade com o network__id fornecido.
     * @param $class_name A classe da entidade buscada.
     * @param $network_id O network__id da entidade buscada.
     * @return int O id local da entidade, ou zero se não encontrada.
     */
    function findEntityId(string $class_name, string $network_id): int
    {
        $app = App::i();
        $query = new \MapasCulturais\ApiQuery($class_name, [
            "network__id" => "EQ($network_id)",
            "status" => "GTE(-10)",
            "user" => "EQ({$app->user->id})",
            "@permissions" => "view",
        ]);
        $ids = $query->findIds();
        return ($ids[0] ?? 0);
    }

    /**
     * Combina os dados recebidos com a entidade local, priorizando a mais atual.
     * @param $entity A entidade local que deve receber os dados.
     * @param $foreign_data Os dados recebidos de outro nó.
     */
    function mergeEntity(Entity $entity, array $foreign_data)
    {
        $origin_node = $this->getRequestOriginNode();
        $entity_updated = $entity->updateTimestamp ?? $entity->createTimestamp;
        $foreign_updated = new DateTime($foreign_data["updateTimestamp"]["date"] ?? $foreign_data["createTimestamp"]["date"]);
        $data = [];
        // faz merge das infos que vieram no request com a info do agente, mantendo a versão mais nova da info.
        foreach ($foreign_data as $key => $val) {
            if (in_array($key, ["network__id", "createTimestamp", "updateTimestamp", "network__revisions"])) {
                continue;
            }
            if ($val == $entity->$key) {
                continue;
            }
            if ($key == "files") {
                $this->bootstrapFiles($entity, $val, $foreign_data, $origin_node);
                continue;
            } else if ($key == "metalists") {
                $this->bootstrapMetaLists($entity, $val, $foreign_data);
                continue;
            }
            if ($val && $entity->$key) {
                // se a informação local é mais nova que a informação que veio no request
                // @todo: o ideal é verificar no histórico de revisões a data que foi preenchida a info
                if ($entity_updated <= $foreign_updated) {
                    $data[$key] = $val;
                }
            } else if ($val) {
                $data[$key] = $val;
            }
        }
        return $data;
    }

    /**
     * Atualiza o network__id das entidades se necessário para conclusão do merge.
     * @param $entity A entidade local que está recebendo os dados.
     * @param $foreign_data Os dados recebidos de outro nó.
     */
    function verifyAndUpdateNetworkId(\MapasCulturais\Entity $entity, array $foreign_data)
    {
        $fdate = new DateTime($foreign_data["createTimestamp"]["date"]);
        if ($fdate < $entity->createTimestamp) {
            $new_network__id = $foreign_data["network__id"];
            $current_network__id = $entity->network__id;
            $entity->network__id = $new_network__id;
            $skip_node = $this->getRequestOriginNode();
        } else { // with post-bootstrap comparing and linking of newly created entities, the local entity may be older
            $new_network__id = $entity->network__id;
            $current_network__id = $foreign_data["network__id"];
            $skip_node = null;
        }
        App::i()->enqueueJob(Plugin::JOB_UPDATE_NETWORK_ID, [
            "entity" => $entity,
            "node" => $skip_node,
            "current_network__id" => $current_network__id,
            "new_network__id" => $new_network__id
        ]);
        return;
    }

    /**
     * Retorna o nó que está fazendo a requisição
     * @return NodeEntities\Node
     * @throws RuntimeException
     */
    function getRequestOriginNode()
    {
        $app = App::i();

        $node_slug = $this->postData['nodeSlug'] ?? null;

        $nodes = $app->repo(EntitiesNode::class)->findBy(['user' => $app->user]);

        foreach ($nodes as $node) {
            if ($node->slug == $node_slug) {
                return $node;
            }
        }

        return null;
    }
}
