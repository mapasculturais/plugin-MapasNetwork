<?php

namespace MapasNetwork\Controllers;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\UserApp;
use MapasCulturais\i;
use MapasCulturais\Traits;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\Event;
use MapasCulturais\Exceptions\PermissionDenied;
use MapasNetwork\Plugin;
use MapasSDK\MapasSDK;
use MapasNetwork\Entities as NodeEntities;
use MapasNetwork\Entities\Node as EntitiesNode;
use RuntimeException;

class Node extends \MapasCulturais\Controller
{
    use Traits\ControllerAPI;

    /**
     * 
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

        $confirmed = $_SESSION['mapas-network:confirmed'] = true;

        $app->redirect($this->createUrl('connect'));
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
        $nodeRepo = $app->repo(NodeEntities\Node::class);
        $this->render("panel-mapas-network-main", [
            "nodes" => $nodeRepo->findBy(["user" => $app->user]),
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
        $app->disableAccessControl();
        $entity->userApp->destroy(true);
        $app->enableAccessControl();
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

        $connect_to = $this->data['to'] ?? $_SESSION['mapas-network:to'] ?? null;
        $create_token = $this->data['token'] ?? $_SESSION['mapas-network:token'] ?? null;
        $name = base64_decode($this->data['name'] ?? null) ?? $_SESSION['mapas-network:name'] ?: $connect_to;
        $isConfirmed = $_SESSION['mapas-network:confirmed'] ?? null;

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
            $_SESSION['mapas-network:to'],
            $_SESSION['mapas-network:token'],
            $_SESSION['mapas-network:name']
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

    function GET_return ()
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

    function POST_createdEntity() {
        $this->requireAuthentication();

        $app = App::i();

        $node_slug = $this->postData['nodeSlug'];
        $class_name = $this->postData['className'];
        $network_id = $this->postData['network__id'];
        $data = $this->postData['data'];


        if (isset($data[$this->plugin->entityMetadataKey])) {
            $this->json('ok');
            return;
        }

        $classes = [
            Agent::class,
            Space::class,
        ];

        if(!in_array($class_name, $classes)){
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, 'create');
        }


        // verifica se a entidade já existe para o usuário
        $query = new ApiQuery($class_name, ['network__id' => "EQ({$network_id})", 'user' => "EQ({$app->user->id})"]);
        if($ids = $query->findIds()) {
            $id = $ids[0];

            /**
             * aproveita a requisição para atualizar o id da entidade no outro nó,
             * desta forma a propagação dos 
             */            
            $entity = $app->repo($class_name)->find($id);


            
            $entity->{"network__{$node_slug}_entity_id"} = $data['id'];

            $entity->save(true);

            $app->log->debug("$network_id already exists with id {$id}");
            $this->json("$network_id already exists with id {$id}");

            return;
        }

        $node = $this->getRequestOriginNode();

        $data = $this->plugin->unserializeEntity($data, $node);

        $this->plugin->createEntity($class_name, $network_id, $data);
    }

    function POST_updatedEntity() {
        $this->requireAuthentication();

        $app = App::i();

        $node_slug = $this->postData['nodeSlug'];
        $class_name = $this->postData['className'];
        $network_id = $this->postData['network__id'];
        $data = $this->postData['data'];

        $revision_id = end($data['network__revisions']);

        $classes = [
            Agent::class,
            Space::class,
        ];

        if(!in_array($class_name, $classes)){
            // @todo arrumar esse throw
            throw new PermissionDenied($app->user, $app->user, 'update');
        }


        // verifica se a entidade já existe para o usuário
        $query = new ApiQuery($class_name, ['network__id' => "EQ({$network_id})", 'user' => "EQ({$app->user->id})"]);
        if($ids = $query->findIds()) {
            $id = $ids[0];

            $entity = $app->repo($class_name)->find($id);
            $entity->network__revisions = $entity->network__revisions ?? [];
            
            if (in_array($revision_id, $entity->network__revisions)){
                $app->log->debug("$network_id $revision_id already exists");
                $this->json("$network_id $revision_id already exists");
                return;
            }

            $revisions = $entity->network__revisions;
            $revisions[] = $revision_id;

            $entity->network__revisions = $revisions;


            $app->log->debug("updating $network_id");

            $skip_fields = [
                'id',
                'user',
                'userId',
                'createTimestamp',
                'updateTimestamp',
                'network__revisions'
            ];

            $skip_null_fields = [
                'parent',
                'owner',
                'agent',
            ];

            $node = $this->getRequestOriginNode();

            $data = $this->plugin->unserializeEntity($data, $node);
            
            foreach ($data as $key => $val) {
                if(in_array($key, $skip_fields)) {
                    continue;
                }

                $entity->$key = $val;
            }

            $this->plugin->skip($entity);

            $entity->save(true);
        }

    }

    function GET_entity() {
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
            if($node->slug == $node_slug) {
                return $node;
            }
        }

        return null;
    }
}
