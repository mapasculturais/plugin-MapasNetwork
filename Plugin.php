<?php

namespace MapasNetwork;

use Closure;
use DateTime;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\ORM\ORMException;
use InvalidArgumentException;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\i;

use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Space;

use MapasNetwork\Entities\Node;
use MapasSDK\Exceptions\BadRequest;
use MapasSDK\Exceptions\Unauthorized;
use MapasSDK\Exceptions\Forbidden;
use MapasSDK\Exceptions\NotFound;
use MapasSDK\Exceptions\UnexpectedError;
use MapasSDK\MapasSDK;

/**
 * @property-read string $nodeSlug
 * @package MapasNetwork
 */
class Plugin extends \MapasCulturais\Plugin
{
    const JOB_SLUG = "network__sync_entity";
    const JOB_SLUG_DELETION = "network__sync_entity_deletion";
    const JOB_SLUG_DOWNLOADS = "network__sync_download_files";
    const JOB_SLUG_EVENT = "network__sync_event";
    const JOB_SLUG_FILES = "network__sync_entity_files";
    const JOB_SLUG_METALISTS = "network__sync_entity_metalists";
    const JOB_SLUG_BOOTSTRAP = "network__node_bootstrap";
    const JOB_UPDATE_NETWORK_ID = "network__update_network_id";

    const SKIP_AFTER = "after";
    const SKIP_BEFORE = "before";

    const SYNC_ON = 0;
    const SYNC_OFF = 1;
    const SYNC_AUTO_OFF = 2;

    const UNKNOWN_ID = -1;

    protected $allowedMetaListGroups = ["links", "videos"];
    protected $redirectTo = null;

    function __construct(array $config = [])
    {
        $app = App::i();

        $filters = $config['filters'] ?? [];

        $config += [
            'nodeSlug' => str_replace('.', '', $_SERVER['HOSTNAME'] ?? parse_url($app->baseUrl, PHP_URL_HOST)),
            'filters' => $filters += [
                'agent' => [],
                'space' => []
            ],
            'nodes' => [],
            // usar os formatos relativos (https://www.php.net/manual/pt_BR/datetime.formats.relative.php)
            'nodes-verification-interval' => '1 week'
        ];

        parent::__construct($config);
        return;
    }

    function _init()
    {
        $app = App::i();

        $app->hook("template(<<*>>.nav.panel.apps):before", function () {
            /** @var MapasCulturais\Theme $this */
            $this->part("network-node/panel-mapas-network-sidebar.php");
            return;
        });
        $app->hook("GET(network-node.<<*>>):before", function () use ($app) {
            $app->view->enqueueScript("app", "ng.mc.module.notifications",
                                      "js/ng.mc.module.notifications.js");
            $app->view->enqueueScript("app", "ng.mc.directive.editBox",
                                      "js/ng.mc.directive.editBox.js");
            $app->view->enqueueScript("app", "ng.mapas-network",
                                      "js/ng.mapas-network.js",
                                      ["mapasculturais"]);
            $app->view->enqueueScript("app", "mapas-network",
                                      "js/mapas-network.js",
                                      ["mapasculturais"]);
            $app->view->enqueueStyle("app", "mapas-network",
                                      "css/mapas-network.css");
            return;
        });
        $app->hook("GET(panel.<<*>>):before", function () use ($app) {
            $app->view->enqueueStyle("app", "mapas-network",
                                     "css/mapas-network.css");
            return;
        });
        $app->hook("template(<<agent|event|space>>.<<*>>.name):after", function () use ($app) {
            /** @var MapasCulturais\Theme $this */
            $entity = $this->controller->requestedEntity;
            if ($app->user->id == $entity->ownerUser->id) {
                $app->view->jsObject["entity"]["syncControl"] = !!($entity->network__sync_control ?? self::SYNC_ON);
                $app->view->jsObject["entity"]["networkId"] = $entity->network__id;
                $app->view->jsObject["gettext"]["pluginMapasNetwork"] = [
                    "syncControlError" => i::__("Ocorreu um erro ao alterar o controle de sincroniza????o.", "mapas-network"),
                    "syncDisabled" => i::__("Sincroniza????o desabilitada.", "mapas-network"),
                    "syncEnabled" => i::__("Sincroniza????o habilitada", "mapas-network")
                ];
                $this->part("network-node/entity-sync-switch");
            }
            return;
        });
        $app->hook("view.includeAngularEntityAssets:after", function () use ($app) {
            $app->view->enqueueScript("app", "mapas-network", "js/ng.mapas-network.js", [/*"mapasculturais"*/]);
            $app->view->jsObject["angularAppDependencies"][] = "ng.mapas-network";
            return;
        });

        $dir = self::getPrivatePath();
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        /** @var Plugin $plugin */
        $plugin = $this;

        // because this depends on getRegistered*GroupsByEntity, it must be guaranteed to run only after other plugins had a go at register
        $app->hook("app.register:after", function() use ($plugin) {
            /** @var \MapasCulturais\App $this */
            $register_types = [
                ["class" => \MapasCulturais\Entities\Agent::class, "registerFunction" => "registerAgentMetadata"],
                ["class" => \MapasCulturais\Entities\Event::class, "registerFunction" => "registerEventMetadata"],
                ["class" => \MapasCulturais\Entities\Space::class, "registerFunction" => "registerSpaceMetadata"],
            ];
            $ids_label = i::__("lista dos IDs de rede", "mapas-network");
            $revisions_label = i::__("lista das revis??es de rede", "mapas-network");
            // metalist groups
            foreach ($register_types as $type) {
                $register_function = $type["registerFunction"];
                $groups = $this->getRegisteredMetaListGroupsByEntity($type["class"]);
                // for metalists, we have a default list of groups that hooks can add to;
                // this is because metalists used by the Reports module should not be synchronised
                $this->applyHook("plugin(MapasNetwork).syncMetaListGroups", [&$plugin->allowedMetaListGroups]);
                foreach ($groups as $group) {
                    if (!in_array($group->name, $plugin->allowedMetaListGroups)) {
                        continue;
                    }
                    $plugin->$register_function("network__ids_metalist_{$group->name}", [
                        "label" => ("metalist({$group->name}): $ids_label"),
                        "type" => "json",
                        "default" => []
                    ]);
                    $plugin->$register_function("network__revisions_metalist_{$group->name}", [
                        "label" => ("metalist({$group->name}): $revisions_label"),
                        "type" => "json",
                        "default" => []
                    ]);
                }
                $groups = $this->getRegisteredFileGroupsByEntity($type["class"]);
                $this->applyHook("plugin(MapasNetwork).syncFileGroups", [&$groups]);
                foreach ($groups as $group) {
                    $plugin->$register_function("network__ids_files_{$group->name}", [
                        "label" => ("files({$group->name}): $ids_label"),
                        "type" => "json",
                        "default" => []
                    ]);
                    $plugin->$register_function("network__revisions_files_{$group->name}", [
                        "label" => ("files({$group->name}): $revisions_label"),
                        "type" => "json",
                        "default" => []
                    ]);
                }
            }
            return;
        });
        $app->hook("mapasculturais.run:before", function () use ($plugin) {
            /** @var \MapasCulturais\App $this */
            if ($this->user->is("guest")) {
                return;
            }
            $nodes = Plugin::getCurrentUserNodes();
            foreach ($nodes as $node) {
                $config = [
                    "label" => "Id da entidade no node {$node->slug}",
                    "private" => true,
                    "type" => "integer"
                ];
                // algo como network_spcultura_entity_id
                $key = $node->entityMetadataKey;
                $plugin->registerAgentMetadata($key, $config);
                $plugin->registerEventMetadata($key, $config);
                $plugin->registerSpaceMetadata($key, $config);
            }
            return;
        });

        $entities_hook_prefix = "entity(<<Agent|Space>>)";
        $entities_hook_prefix_all = "entity(<<Agent|Event|Space>>)";

        $app->hook("$entities_hook_prefix_all.get(networkRevisionPrefix)", function (&$value) use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            $slug = $plugin->nodeSlug;
            $entity_id = str_replace("MapasCulturais\\Entities\\", "", (string) $this);
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
            return;
        });
        $app->hook("{$entities_hook_prefix}.insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            if (Plugin::ensureNetworkID($this)) {
                $plugin->skip($this, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                Plugin::saveMetadata($this, ["network__id"]);
            }
            $plugin->syncEntity($this, "createdEntity");
            return;
        });
        $app->hook("$entities_hook_prefix_all.update:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            if ($plugin->shouldSkip($this, self::SKIP_BEFORE)) {
                return;
            }
            Plugin::ensureNetworkID($this);
            $uid = uniqid("", true);
            $revisions = $this->network__revisions;
            $revisions[] = "{$this->networkRevisionPrefix}:{$uid}";
            $this->network__revisions = $revisions;
            return;
        });
        $app->hook("$entities_hook_prefix_all.update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            if ($plugin->shouldSkip($this, self::SKIP_AFTER)) {
                return;
            }
            $plugin->syncEntity($this, "updatedEntity");
            return;
        });
        $app->hook("entity(<<agent|event|space>>).meta(network__sync_control).update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\Metadata $this */
            if ($this->value != self::SYNC_ON) {
                return;
            }
            $auto_off = self::SYNC_AUTO_OFF;
            if ($this->owner->changedMetadata["network__sync_control"]["oldValue"] == "$auto_off") {
                $plugin->syncEntity($this->owner, "updatedEntity");
            }
            return;
        });

        // for insertion, we need to watch EventOccurrence insertion rather than Event insertion
        $app->hook("entity(EventOccurrence).insert:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            Plugin::ensureNetworkID($this->event);
            Plugin::ensureNetworkID($this->space);
            Plugin::ensureNetworkID($this->space->owner);
            return;
        });
        $app->hook("entity(EventOccurrence).insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_AFTER)) {
                return;
            }
            if ($this->status == \MapasCulturais\Entities\EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            $plugin->registerEventOccurrence($this);
            return;
        });
        $app->hook("entity(EventOccurrence).remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            if ($this->status == \MapasCulturais\Entities\EventOccurrence::STATUS_PENDING) {
                return; // this is a no-op since we never sync a pending occurrence
            }
            $ids = (array) $this->event->network__occurrence_ids;
            $network_id = array_search($this->id, $ids);
            unset($ids[$network_id]);
            $this->event->network__occurrence_ids = $ids;
            $revisions = (array) $this->event->network__occurrence_revisions;
            unset($revisions[$network_id]);
            $this->event->network__occurrence_revisions = $revisions;
            // use skips because we don't want to trigger hooks for these bookkeeping tasks
            $plugin->skip($this->event, [self::SKIP_BEFORE, self::SKIP_AFTER]);
            Plugin::saveMetadata($this->event, ["network__occurrence_ids", "network__occurrence_revisions"]);
            $nodes = Plugin::getEntityNodes($this->event);
            $is_descope = str_ends_with($_SERVER["REQUEST_URI"], "/descopedEventOccurrence");
            $tracking_nodes = (array) $this->event->network__tracking_nodes;
            foreach ($nodes as $node) {
                if (Plugin::checkNodeFilter($node, $this->space) || ($is_descope && isset($tracking_nodes[$node->slug]))) {
                    App::i()->enqueueJob(self::JOB_SLUG_DELETION, [
                        "syncAction" => $is_descope ? "descopedEventOccurrence" : "deletedEventOccurrence",
                        "entity" => $this->jsonSerialize(),
                        "node" => $node,
                        "nodeSlug" => $node->slug,
                        "networkID" => $network_id,
                        "className" => $this->className,
                        "ownerClassName" => $this->event->className,
                        "ownerNetworkID" => $this->event->network__id,
                        "group" => "occurrence",
                        "revisions_key" => "network__occurrence_revisions",
                        "revisions" => $revisions
                    ]);
                }
            }
            return;
        });
        $app->hook("entity(EventOccurrence).update:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            if ($this->status == \MapasCulturais\Entities\EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            $uid = uniqid("", true);
            $revisions = (array) $this->event->network__occurrence_revisions ?? [];
            $ids = (array) $this->event->network__occurrence_ids;
            $network_id = array_search($this->id, $ids);
            if (!isset($revisions[$network_id])) {
                $revisions[$network_id] = [];
            }
            $revisions[$network_id][] = "{$this->event->networkRevisionPrefix}:{$uid}";
            $this->event->network__occurrence_revisions = $revisions;
            Plugin::ensureNetworkID($this->event);
            $plugin->skip($this->event, [Plugin::SKIP_BEFORE]);
            $this->event->save(true);
            return;
        });
        $app->hook("entity(EventOccurrence).update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_AFTER)) {
                return;
            }
            if ($this->status == \MapasCulturais\Entities\EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            if ((array_search($this->id, ((array) $this->event->network__occurrence_ids ?? [])) == false)) {
                $plugin->registerEventOccurrence($this); // this was a pending occurrence, treat as creation
            } else {
                $plugin->syncEventOccurrence($this, "updatedEventOccurrence");
            }
            return;
        });

        $metalist_hook_component = "metalist(<<*>>)";
        $app->hook("$entities_hook_prefix_all.$metalist_hook_component.insert:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if (!in_array($this->group, $plugin->allowedMetaListGroups)) {
                return;
            }
            if ($plugin->shouldSkip($this->owner, self::SKIP_BEFORE)) {
                return;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_metalist_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            Plugin::ensureNetworkID($this->owner);
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE]);
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix_all.$metalist_hook_component.insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if (!in_array($this->group, $plugin->allowedMetaListGroups)) {
                return;
            }
            if ($plugin->shouldSkip($this->owner, self::SKIP_AFTER)) {
                return;
            }
            $ids_key = "network__ids_metalist_{$this->group}";
            if (Plugin::ensureNetworkID($this, $this->owner, $ids_key)) {
                $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                Plugin::saveMetadata($this->owner, [$ids_key]);
            }
            $plugin->syncMetaList($this, "createdMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix_all.$metalist_hook_component.update:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if (!in_array($this->group, $plugin->allowedMetaListGroups)) {
                return;
            }
            if ($plugin->shouldSkip($this->owner, self::SKIP_BEFORE)) {
                return;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_metalist_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            Plugin::ensureNetworkID($this->owner);
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE]);
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix_all.$metalist_hook_component.update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if ($plugin->shouldSkip($this->owner, self::SKIP_AFTER)) {
                return;
            }
            if (!in_array($this->group, $plugin->allowedMetaListGroups)) {
                return;
            }
            $plugin->syncMetaList($this, "updatedMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix_all.$metalist_hook_component.remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if ($plugin->shouldSkip($this->owner, self::SKIP_BEFORE)) {
                return;
            }
            if (!in_array($this->group, $plugin->allowedMetaListGroups)) {
                return;
            }
            $plugin->requestDeletion($this, "deletedMetaList", $this->group, "metalist");
            return;
        });
        $app->hook("$entities_hook_prefix_all.file(<<*>>).insert:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            if ($plugin->shouldSkip($this->owner, self::SKIP_BEFORE)) {
                return;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_files_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            Plugin::ensureNetworkID($this->owner);
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE]);
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix_all.file(<<*>>).insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            if ($plugin->shouldSkip($this->owner, self::SKIP_AFTER)) {
                return;
            }
            $ids_key = "network__ids_files_{$this->group}";
            if (Plugin::ensureNetworkID($this, $this->owner, $ids_key)) {
                $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                Plugin::saveMetadata($this->owner, [$ids_key]);
            }
            $plugin->syncFile($this, "createdFile");
            return;
        });
        $app->hook("$entities_hook_prefix_all.file(<<*>>).remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            if ($plugin->shouldSkip($this->owner, self::SKIP_BEFORE)) {
                return;
            }
            $plugin->requestDeletion($this, "deletedFile", $this->group, "files");
            return;
        });

        /**
         * Redireciona o usu??rio para a p??gina de vincula????o de contas
         * se forem encontradas contas em outros n??s que ainda n??o foram vinculadas.
         * O redirecionamento acontece no primeiro login ap??s a instala????o do plugin ou em
         * um intervalo configurado no plugin.
         */
        $app->hook("auth.login", function () use ($app, $plugin) {
            // se est?? no processo de vincula????o, salva o redirect original para uso posterior
            if (($_SESSION["mapas-network:timestamp"] ?? null) > new DateTime()) {
                $plugin->redirectTo = $app->auth->redirectPath;
                return;
            } else { // vincula????o expirada, remove dados da sess??o
                unset(
                    $_SESSION["mapas-network:timestamp"],
                    $_SESSION["mapas-network:to"],
                    $_SESSION["mapas-network:token"],
                    $_SESSION["mapas-network:name"],
                    $_SESSION["mapas-network:confirmed"]
                );
            }
            $date = $app->user->network__next_verification_datetime;
            if ($date < new DateTime()) {
                $repo = $app->repo(Entities\Node::class);
                $nodes = $repo->findBy(["user" => $app->user]);
                try {
                    if ($plugin->findAccounts($nodes)) {
                        $plugin->redirectTo = $app->createUrl("network-node", "panel");
                    }
                } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
                    $app->log->debug($e->getMessage());
                }
            }
        });
        $app->hook("auth.successful:redirectUrl", function (&$redirectUrl) use ($plugin) {
            if ($plugin->redirectTo) {
                $redirectUrl = $plugin->redirectTo;
            }
            return;
        });
        return;
    }

    /**
     * Verifica se o n?? deve receber a entidade

     * @param Node $node
     * @param Entity $entity
     * @return bool
     */
    static function checkNodeFilter(Entities\Node $node, Entity $entity) {
        
        $filters = $node->getFilters($entity->entityType);

        foreach($filters as &$value) {
            if (is_array($value)) {
                $imploded = implode(',', $value);
                $value = "IN($imploded)";
            } else {
                $value = "EQ($value)";
            }
        }

        $filters['id'] = "EQ($entity->id)";
        $query = new ApiQuery($entity->className, $filters);

        $result = $query->findIds() == [$entity->id];

        return $result;
    }

    function register()
    {
        $app = App::i();
        $app->registerController("network-node", "\\MapasNetwork\\Controllers\\Node");
        // synchronisation control
        $sync_control = [
            "label" => i::__("Controle da sincroniza????o autom??tica", "mapas-network"),
            "type" => "int",
            "default" => self::SYNC_ON
        ];
        $this->registerAgentMetadata("network__sync_control", $sync_control);
        $this->registerEventMetadata("network__sync_control", $sync_control);
        $this->registerSpaceMetadata("network__sync_control", $sync_control);
        // register metadata
        $revisions_metadata = [
            'label' => i::__('Lista das revis??es da rede', 'mapas-network'),
            'type' => 'json',
            'default' => []
        ];
        $this->registerAgentMetadata('network__revisions', $revisions_metadata);
        $this->registerEventMetadata('network__revisions', $revisions_metadata);
        $this->registerSpaceMetadata('network__revisions', $revisions_metadata);
        $network_id_metadata = [
            'label' => i::__('Id da entidade na rede de mapas', 'mapas-network'),
            'type' => 'string',
        ];
        $this->registerAgentMetadata('network__id', $network_id_metadata);
        $this->registerEventMetadata('network__id', $network_id_metadata);
        $this->registerSpaceMetadata('network__id', $network_id_metadata);
        // similar to files and metalists, these are dictionaries keyed by the network__id
        $this->registerEventMetadata("network__occurrence_ids", [
            "label" => i::__("Ids das ocorr??ncias na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => []
        ]);
        $this->registerEventMetadata("network__occurrence_revisions", [
            "label" => i::__("Listas de revis??es das ocorr??ncias na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => []
        ]);
        $this->registerSpaceMetadata("network__proxied_owner", [
            "label" => i::__("O network Id do propriet??rio original do espa??o", "mapas-network"),
            "type" => "string"
        ]);
        $this->registerUserMetadata("network__proxy_slug", [
            "label" => i::__("Se este usu??rio ?? proxy de alguma instala????o, o slug da mesma.", "mapas-network"),
            "type" => "string"
        ]);
        $this->registerUserMetadata("network__next_verification_datetime", [
            "label" => i::__("Data da pr??xima verifica????o por contas nos nodes", "mapas-network"),
            "type" => "DateTime"
        ]);
        $network_tracking_metadata = [
            "label" => i::__("Slugs dos n??s que compartilham a entidade", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__tracking_nodes", $network_tracking_metadata);
        $this->registerEventMetadata("network__tracking_nodes", $network_tracking_metadata);
        $this->registerSpaceMetadata("network__tracking_nodes", $network_tracking_metadata);
        // background jobs
        $app->registerJobType(new SyncEntityJobType(self::JOB_SLUG, $this));
        $app->registerJobType(new SyncEventJobType(self::JOB_SLUG_EVENT, $this));
        $app->registerJobType(new SyncFileJobType(self::JOB_SLUG_FILES, $this));
        $app->registerJobType(new SyncMetaListJobType(self::JOB_SLUG_METALISTS, $this));
        $app->registerJobType(new SyncDeletionJobType(self::JOB_SLUG_DELETION, $this));
        $app->registerJobType(new SyncDownloadJobType(self::JOB_SLUG_DOWNLOADS, $this));
        $app->registerJobType(new NodeBootstrapJobType(self::JOB_SLUG_BOOTSTRAP, $this));
        $app->registerJobType(new UpdateNetworkIdJobType(self::JOB_UPDATE_NETWORK_ID, $this));
        return;
    }

    function getNodeSlug()
    {
        return $this->_config['nodeSlug'];
    }

    function getEntityMetadataKey()
    {
        // @todo trocar por slug do n??
        $slug = $this->nodeSlug;
        return "network__{$slug}_entity_id";
    }

    static function ensureNetworkID(\MapasCulturais\Entity $entity, \MapasCulturais\Entity $owner=null, string $key=null)
    {
        if (!$key && !$entity->network__id) {
            $uid = uniqid("", true);
            $entity->network__id = "{$entity->networkRevisionPrefix}:{$uid}";
        } else if ($key && $owner) {
            $network_id = array_search($entity->id, (array) $owner->$key);
            if ($network_id === false) { // network__id doesn't exist or is pending association with the entity ID
                $network_id = array_search(Plugin::UNKNOWN_ID, (array) $owner->$key);
                if ($network_id === false) { // network__id doesn't exist at all
                    App::i()->log->debug("ensureNetworkID: creating new for special entity {$entity}");
                    $plugin = App::i()->plugins["MapasNetwork"];
                    $uid = uniqid("", true);
                    $entity_id = str_replace("MapasCulturais\\Entities\\", "", "{$entity->className}:{$entity->id}");
                    $network_id = "{$plugin->nodeSlug}:{$entity_id}:$uid";
                } else App::i()->log->debug("ensureNetworkID: special entity {$entity} using placeholder ID");
            } else {
                App::i()->log->debug("ensureNetworkID: special entity {$entity} already registered");
                return false;
            }
            $ids = (array) $owner->$key;
            $ids[$network_id] = $entity->id;
            $owner->$key = $ids;
        } else {
            return false;
        }
        return true;
    }

    static function getClassFromNetworkID(string $network_id)
    {
        $matches = [];
        preg_match("#(?:@entity:)?\w+:(\w+):\d*:#", $network_id, $matches);
        return "MapasCulturais\\Entities\\{$matches[1]}";
    }

    static function getPrivatePath()
    {
        // @todo: colocar numa configura????o
        return PRIVATE_FILES_PATH . 'mapas-network-keys/';
    }

    static function getCurrentUserNodes()
    {
        $app = App::i();

        if (!$app->user->is('guest')) {
            return self::getEntityNodes($app->user);
        } else {
            return [];
        }
    }

    /**
     * Retorna a lista de nodes para sincroniza????o de uma entidade
     *
     * @param Entity $entity
     *
     * @return Entities\Node[]
     */
    static function getEntityNodes(Entity $entity)
    {
        $app = App::i();

        $nodes = $app->repo(Entities\Node::class)->findBy(['user' => $entity->ownerUser]);

        $app->applyHookBoundTo($entity, "{$entity->hookPrefix}.networkNodes", [&$nodes]);

        return $nodes;
    }

    static function getProxyUserIDForNode(string $slug)
    {
        $query = new ApiQuery("MapasCulturais\\Entities\\User", ["network__proxy_slug" => "EQ($slug)"]);
        $ids = $query->findIds();
        if (empty($ids)) {
            return null;
        }
        return $ids[0];
    }

    function serializeEntity($value, $get_json_serialize=true)
    {
        if ($get_json_serialize && ($value instanceof Entity)) {
            $temp_value = $value->jsonSerialize();
            if ($value instanceof \MapasCulturais\Entities\EventOccurrence) {
                $temp_value["event"] = $value->event;
                $network_id = array_search($value->id, ((array) $value->event->network__occurrence_ids ?? []));
                $temp_value["network__id"] = $network_id;
                if ($network_id && isset($value->event->network__occurrence_revisions->$network_id)) {
                    $temp_value["network__revisions"] = $value->event->network__occurrence_revisions->$network_id;
                }
                if (isset($temp_value["space"])) {
                    $temp_value["space"] = $this->serializeEntity($value->space);
                }
            }
            $value = $temp_value;
        }
        if (($value instanceof Entity) && $value->usesMetadata()) {
            $value = "@entity:{$value->network__id}";
        } else if (is_array($value) || ($value instanceof \stdClass)) {
            foreach ($value as &$val) {
                $val = $this->serializeEntity($val, false);
            }
        } else if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        }
        return $value;
    }

    /**
     *
     * @param mixed $value
     * @param Node|null $not_found_node
     * @return mixed
     */
    function unserializeEntity($value, Node $not_found_node=null)
    {
        $array_conversions = ["terms", "location", "network__occurrence_ids", "network__occurrence_revisions"];
        if (is_string($value) && preg_match("#@entity:(.*)#", $value, $matches)) {
            $network__id = $matches[1];
            $value = $this->getEntityByNetworkId($network__id, $not_found_node);
        } else if (is_array($value) || ($value instanceof \stdClass)) {
            $value = (array) $value;
            foreach ($value as $key => $val) {
                if (in_array((string) $key, $array_conversions)) {
                    $value[$key] = $val ? (array) $val : null;
                } else {
                    $value[$key] = $this->unserializeEntity($val, $not_found_node);
                }
            }
        }
        return $value;
    }

    function getEntityByNetworkId($network__id, Node $not_found_node=null)
    {
        $app = App::i();
        preg_match("#:(\w+):\d*:#", $network__id, $matches);
        $class_name = 'MapasCulturais\\Entities\\' . $matches[1];
        $query = new ApiQuery($class_name, ['network__id' => "EQ({$network__id})"]);
        $ids = $query->findIds();
        $id = $ids[0] ?? null;
        $entity = $id ? $app->repo($class_name)->find($id) : null;
        if (!$entity && $not_found_node) {
            $response = $not_found_node->api->apiGet('network-node/entity', ['network__id' => $network__id]);
            $entity = $this->createEntity($class_name, $network__id, (array) $response->response);
        }
        return $entity;
    }

    function requestDeletion(\MapasCulturais\Entity $entity, $action,
                             $group, $type)
    {
        $app = App::i();
        $revisions_key = "network__revisions_{$type}_{$group}";
        $revisions = $entity->owner->$revisions_key;
        if (!in_array(self::SKIP_BEFORE,
                      ($this->skipList[(string) $entity->owner] ?? []))) {
            $uid = uniqid("", true);
            $revisions[] = "{$entity->owner->networkRevisionPrefix}:{$uid}";
            $entity->owner->$revisions_key = $revisions;
        }
        $metadata_key = $this->entityMetadataKey;
        $entity->owner->$metadata_key = $entity->owner->id;
        $ids_key = "network__ids_{$type}_{$group}";
        $network_id = array_search($entity->id,
                                   (array) $entity->owner->$ids_key);
        $ids = (array) $entity->owner->$ids_key;
        unset($ids[$network_id]);
        $entity->owner->$ids_key = $ids;
        Plugin::ensureNetworkID($entity->owner);
        $entity->owner->save(true);
        $nodes = Plugin::getEntityNodes($entity->owner);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $entity->owner)) {
                $app->enqueueJob(self::JOB_SLUG_DELETION, [
                    "syncAction" => $action,
                    "entity" => $entity->jsonSerialize(),
                    "node" => $node,
                    "nodeSlug" => $node->slug,
                    "networkID" => $network_id,
                    "className" => $entity->className,
                    "ownerClassName" => $entity->owner->className,
                    "ownerNetworkID" => $entity->owner->network__id,
                    "group" => $group,
                    "revisions_key" => $revisions_key,
                    "revisions" => $revisions
                ]);
            }
        }
        return;
    }

    /**
     * Executa o $cb para cada n?? que tenha a entidade
     *
     * $cb = function ($node, $entity) use(...) {...}
     */
    static function foreachEntityNodeDo($entity, Closure $cb)
    {
        $nodes = Plugin::getEntityNodes($entity->owner);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $entity->owner)) {
                $cb($node, $entity);
            }
        }
    }

    protected $skipList = [];

    function skip($entity, $modes) {
        $this->skipList[(string) $entity] = $modes;
    }

    function syncEntity(\MapasCulturais\Entity $entity, string $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $entity->$metadata_key = $entity->id;
        $nodes = Plugin::getEntityNodes($entity);

        $destination_nodes = array_filter($nodes, function ($node) use ($entity) {
            $node_entity_metadata_key = $node->entityMetadataKey;
            // se a entidade j?? est?? vinculada, retorna true
            if($entity->$node_entity_metadata_key ?? false) {
                return true;
            } else {
                return Plugin::checkNodeFilter($node, $entity);
            }
        });
        $tracking_nodes = (array) $entity->network__tracking_nodes ?? [];
        foreach ($nodes as $node) {
            if (isset($tracking_nodes[$node->slug])) {
                $tracking_nodes[$node->slug] = $node;
            }
        }
        foreach ($destination_nodes as $node) {
            $app->enqueueJob(self::JOB_SLUG, [
                "syncAction" => $action,
                "entity" => $entity,
                "node" => $node,
                "nodeSlug" => $node->slug
            ]);
            unset($tracking_nodes[$node->slug]);
        }
        foreach ($tracking_nodes as $slug => $node) {
            $app->enqueueJob(self::JOB_SLUG_DELETION, [
                "syncAction" => "descopedEntity",
                "entity" => $entity->jsonSerialize(),
                "node" => $node,
                "nodeSlug" => $slug,
                "networkID" => $entity->network__id,
                "className" => $entity->className,
                "ownerClassName" => $entity->owner->className,
                "ownerNetworkID" => $entity->owner->network__id,
                "group" => "",
                "revisions_key" => "network__revisions",
                "revisions" => $entity->network__revisions
            ]);
        }
        return;
    }

    function syncEventOccurrence(\MapasCulturais\Entities\EventOccurrence $occurrence, string $action)
    {
        $app = App::i();
        $event = $occurrence->event;
        $metadata_key = $this->entityMetadataKey;
        $event->$metadata_key = $event->id;
        // we don't use foreachEntityNodeDo here because filtering and looping have different references
        $nodes = Plugin::getEntityNodes($occurrence->event);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $occurrence->space)) {
                $app->enqueueJob(self::JOB_SLUG, [
                    "syncAction" => $action,
                    "entity" => $occurrence,
                    "node" => $node,
                    "nodeSlug" => $node->slug
                ]);
            } else if ($action != "createdEventOccurrence") {
                /* If an occurrence update changes the space such that it no
                   longer passes the filter, any nodes that previously passed
                   the filter need to know about it (i.e. delete). A different
                   endpoint is used because propagation must take into account
                   how this deletion came to be.
                 */
                $ids = (array) $event->network__occurrence_ids;
                $network_id = array_search($occurrence->id, $ids);
                $revisions = (array) $event->network__occurrence_revisions;
                $app->enqueueJob(self::JOB_SLUG_DELETION, [
                    "syncAction" => "descopedEventOccurrence",
                    "entity" => $occurrence->jsonSerialize(),
                    "node" => $node,
                    "nodeSlug" => $node->slug,
                    "networkID" => $network_id,
                    "className" => $occurrence->className,
                    "ownerClassName" => $event->className,
                    "ownerNetworkID" => $event->network__id,
                    "group" => "occurrence",
                    "revisions_key" => "network__occurrence_revisions",
                    "revisions" => $revisions
                ]);
            }
        }
        return;
    }

    function syncFile(\MapasCulturais\Entities\File $file, $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $file->owner->$metadata_key = $file->owner->id;
        Plugin::foreachEntityNodeDo($file, function ($node, $file) use ($action, $app) {
            $app->enqueueJob(self::JOB_SLUG_FILES, [
                "syncAction" => $action,
                "entity" => $file,
                "node" => $node,
                "nodeSlug" => $node->slug
            ]);
            return;
        });
        return;
    }

    function syncMetaList(\MapasCulturais\Entities\MetaList $list, $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $list->owner->$metadata_key = $list->owner->id;
        Plugin::foreachEntityNodeDo($list, function ($node, $list) use ($action, $app) {
            $app->enqueueJob(self::JOB_SLUG_METALISTS, [
                "syncAction" => $action,
                "entity" => $list,
                "node" => $node,
                "nodeSlug" => $node->slug
            ]);
            return;
        });
        return;
    }

    function createEntity($class_name, $network_id, array $data)
    {
        $app = App::i();
        $app->log->debug("creating $network_id");
        $entity = new $class_name;
        $data = $this->unserializeEntity($data);
        Plugin::convertEntityData($entity, $data);
        $entity->save(true);
        return $entity;
    }

    function findAccounts(array $exclude_nodes=null)
    {
        $app = App::i();

        $find_data = [
            'emails' => [$app->user->email],
            'documents' => []
        ];

        foreach ($app->user->enabledAgents as $agent) {
            if($agent->emailPrivado && !in_array($agent->emailPrivado, $find_data['emails'])) {
                $find_data['emails'][] = $agent->emailPrivado;
            }

            if($agent->emailPublico && !in_array($agent->emailPublico, $find_data['emails'])) {
                $find_data['emails'][] = $agent->emailPublico;
            }

            if ($agent->documento) {
                $find_data['documents'][] = $agent->documento;
            }
        }

        $responses = [];
        foreach ($this->config['nodes'] as $node_url) {
            $linked = false;
            foreach($exclude_nodes as $node) {
                $app->log->debug("$node->url === $node_url");
                if ($node->url == $node_url) {
                    $linked = true;
                    break;
                }
            }
            if ($linked) {
                continue;
            }
            $sdk = new MapasSDK($node_url);

            $curl = $sdk->apiPost('network-node/verifyAccount', $find_data);

            if ($curl->response) {
                $responses[] = $node_url;
            }
        }
        return $responses;
    }

    function registerEventOccurrence(\MapasCulturais\Entities\EventOccurrence $occurrence)
    {
        Plugin::ensureNetworkID($occurrence, $occurrence->event, "network__occurrence_ids");
        // use skips because we don't want to trigger hooks for these bookkeeping tasks
        $this->skip($occurrence->event, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        Plugin::saveMetadata($occurrence->event, ["network__id", "network__occurrence_ids"]);
        $this->skip($occurrence->space, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        Plugin::saveMetadata($occurrence->space, ["network__id"]);
        $this->skip($occurrence->space->owner, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        Plugin::saveMetadata($occurrence->space->owner, ["network__id"]);
        $this->syncEventOccurrence($occurrence, "createdEventOccurrence");
        return;
    }

    function shouldSkip(\MapasCulturais\Entity $entity, $skip_type)
    {
        if (in_array($skip_type, ($this->skipList[(string) $entity] ?? []))) {
            return true;
        }
        if (($entity->network__sync_control ?? self::SYNC_ON) != self::SYNC_ON) {
            return true;
        } else if (App::i()->user->id != $entity->ownerUser->id) {
            Plugin::ensureNetworkID($entity);
            $uid = uniqid("", true);
            $revisions = $entity->network__revisions;
            $revisions[] = "{$entity->networkRevisionPrefix}:{$uid}";
            $entity->network__revisions = $revisions;
            $entity->network__sync_control = self::SYNC_AUTO_OFF;
            // notifies user that synchronisation is disabled
            $notification = new \MapasCulturais\Entities\Notification;
            $notification->user = $entity->ownerUser;
            $message = i::__("A sincroniza????o para %s foi automaticamente desabilitada. Visite a p??gina para reabilitar.", "mapas-network");
            $notification->message = sprintf($message, "<a href=\"{$entity->singleUrl}\" >{$entity->name}</a>");
            $notification->save(true);
            return true;
        }
        return false;
    }

    static function convertEntityData(Entity $entity, array $data)
    {
        $skip_fields = [
            "id",
            "user",
            "userId",
            "createTimestamp",
            "updateTimestamp",
            "network__occurrence_ids",
            "network__sync_control"
        ];
        $skip_null_fields = [
            "owner",
            "parent",
            "agent"
        ];
        foreach ($data as $key => $val) {
            if (in_array($key, $skip_fields)) {
                continue;
            }
            if (is_null($val) && in_array($key, $skip_null_fields)) {
                continue;
            }
            if (($entity instanceof \MapasCulturais\Entities\EventOccurrence)) {
                $skip = false;
                $prefix_length = 16; // "YYYY-mm-dd HH:ii"
                switch ($key) {
                    case "network__id": // network__id is kept in the event entity
                        $skip = true;
                        break;
                    case "startsOn": // special conversion required
                    case "endsOn":
                    case "until":
                        $prefix_length = 10; // "YYYY-mm-dd"
                        // fall-through
                    case "startsAt":
                    case "endsAt":
                        $entity->$key = substr($val["date"], 0, $prefix_length);
                        $skip = true;
                        break;
                    default: break;
                }
                if ($skip) {
                    continue;
                }
            }
            $entity->$key = $val;
        }
        return;
    }

    static function saveMetadata(Entity $entity, array $keys)
    {
        self::sudo(function () use ($entity, $keys) {
            $changes = $entity->changedMetadata;
            foreach ($keys as $key) {
                if (isset($changes[$key])) {
                    $entity->getMetadata($key, true)->save(true);
                }
            }
            return;
        });
        return;
    }

    static function sudo(callable $task)
    {
        $app = App::i();
        $access_control = $app->isAccessControlEnabled();
        if ($access_control) {
            $app->disableAccessControl();
        }
        $task();
        if ($access_control) {
            $app->enableAccessControl();
        }
        return;
    }
}
