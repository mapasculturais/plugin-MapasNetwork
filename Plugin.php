<?php

namespace MapasNetwork;

use Closure;
use DateTime;
use MapasCulturais\i;
use MapasCulturais\App;
use MapasCulturais\ApiQuery;
use MapasCulturais\Entity;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Space;
use MapasCulturais\Entities\Event;
use MapasCulturais\Entities\File;
use MapasCulturais\Entities\MetaList;
use MapasCulturais\Entities\EventOccurrence;
use MapasCulturais\Entities\Notification;
use MapasNetwork\Entities\Node;
use MapasSDK\MapasSDK;

/**
 * @property-read string $nodeSlug
 * @property-read string $entityMetadataKey
 * @property-read string $privatePath Caminho onde são salvos as chaves
 * @property-read Entities\Node[] $currentUserNodes
 * 
 * @package MapasNetwork
 */
class Plugin extends \MapasCulturais\Plugin
{
    const ACTION_RESYNC = "resyncEntity";
    const ACTION_SCOPED = "scopedEntity";

    const JOB_SLUG = "network__sync_entity";
    const JOB_SLUG_DELETION = "network__sync_entity_deletion";
    const JOB_SLUG_DOWNLOADS = "network__sync_download_files";
    const JOB_SLUG_EVENT = "network__sync_event";
    const JOB_SLUG_FILES = "network__sync_entity_files";
    const JOB_SLUG_METALISTS = "network__sync_entity_metalists";
    const JOB_SLUG_BOOTSTRAP = "network__node_bootstrap";
    const JOB_UPDATE_NETWORK_ID = "network__update_network_id";

    const SKIP_REVISION = "revision";
    const SKIP_AFTER = "after";
    const SKIP_BEFORE = "before";

    const SYNC_ON = 0;
    const SYNC_OFF = 1;
    const SYNC_AUTO_OFF = 2;
    const SYNC_DELETED = 3;

    const UNKNOWN_ID = -1;

    /**
     * Url para redirecionar
     * @var string
     */
    protected $redirectTo;

    /**
     * Lista de entidades que não devem ser sincronizadas ao salvar
     * @var array
     */
    protected $skipList = [];

    /**
     * Instância do plugin
     * @var Plugin
     */
    static $instance;

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

        self::$instance = $this;
        parent::__construct($config);
    }

    function _init()
    {
        $app = App::i();

        /** @var Plugin $plugin */
        $plugin = $this;

        
        $app->hook("auth.login", function () use ($plugin) {
            /**
             * Certifica-se de que o cache dos filtros existam no momento do login
             */
            foreach($plugin->config['nodes'] as $url) {
                self::getNodeFilters($url);
            }

            /**
             * Inicializa os metadados das entidaes já existentes
             */
            $app = App::i();
            $user = $app->user;
            if (!$user->profile->network__id) {
                foreach($user->agents as $entity) {
                    Plugin::ensureNetworkID($entity);
                    $entity->save();
                }

                foreach($user->spaces as $entity) {
                    Plugin::ensureNetworkID($entity);
                    $entity->save();
                }

                foreach($user->events as $entity) {
                    Plugin::ensureNetworkID($entity);
                    $entity->save();
                }

                $app->em->flush();
            }
        });

        /**
         * Adiciona o menu "Vinculação de conta" no painel
         */
        $app->hook("template(<<*>>.nav.panel.apps):before", function () {
            /** @var MapasCulturais\Theme $this */
            $this->part("network-node/panel-mapas-network-sidebar.php");
        });

        /**
         * Enfilera os assets necessários para as rotas da "Vinculação da conta"
         */
        $app->hook("GET(network-node.<<*>>):before", function () use ($app) {
            $app->view->enqueueScript("app", "ng.mc.module.notifications", "js/ng.mc.module.notifications.js");
            $app->view->enqueueScript("app", "ng.mc.directive.editBox", "js/ng.mc.directive.editBox.js");
            $app->view->enqueueScript("app", "ng.mapas-network", "js/ng.mapas-network.js", ["mapasculturais"]);
            $app->view->enqueueScript("app", "mapas-network", "js/mapas-network.js", ["mapasculturais"]);
            $app->view->enqueueStyle("app", "mapas-network", "css/mapas-network.css");
        });

        /**
         * Enfilera os asset para o switch de liga/desliga da sincronização
         */
        $app->hook("GET(panel.<<agents|events|spaces>>):before", function () use ($app) {
            if (empty(self::getCurrentUserNodes())) {
                return;
            }
            Plugin::addPropagationUX($app->view);
        });
        
        $app->hook("template(panel.<<agents|events|spaces>>.entity-actions):begin", function ($entity) {
            /** @var MapasCulturais\Theme $this */
            $nodes = self::getCurrentUserNodes();
            
            if (!($entity instanceof Entity)) {
                $app = App::i();
                $entity_map = [
                    'events' => 'Event',
                    'spaces' => 'Space',
                    'agents' => 'Agent'
                ];
                $class = $entity_map[$this->controller->action];
                $entity = $app->repo($class)->find($entity->id);
            } 

            if (empty($nodes) || !$entity->network__id) {
                return;
            }
            if (($entity->network__sync_control ?: self::SYNC_ON) != self::SYNC_DELETED) {
                $this->part("network-node/panel-sync-switch.php", ["entity" => $entity]);
            }
        });
        $app->hook("GET(panel.<<*>>):before", function () use ($app) {
            $app->view->enqueueStyle("app", "mapas-network", "css/mapas-network.css");
        });
        $app->hook("GET(<<agent|event|space>>.single):before", function () use ($app) {
            /** @var MapasCulturais\Controllers\EntityController $this */
            if (empty(self::getCurrentUserNodes())) {
                return;
            }
            Plugin::addPropagationUX($app->view);
            $app->view->jsObject["mapasNetworkData"] = [
                "className" => $this->requestedEntity->className,
                "controllerId" => $this->id,
            ];
        });
        $app->hook("template(<<agent|event|space>>.<<*>>.name):after", function () use ($app) {
            /** @var MapasCulturais\Theme $this */
            $entity = $this->controller->requestedEntity;
            if ($app->mode == APPMODE_DEVELOPMENT) {
                echo "<!-- {$entity->network__id} -->";
            }
            if (($app->user->id == $entity->ownerUser->id) &&
                (($entity->network__sync_control ?: self::SYNC_ON) != self::SYNC_DELETED)) {
                $app->view->jsObject["entity"]["syncControl"] = !!($entity->network__sync_control ?: self::SYNC_ON);
                $app->view->jsObject["entity"]["networkId"] = $entity->network__id;
                $this->part("network-node/entity-sync-switch");
            }
        });
        $app->hook("view.includeAngularEntityAssets:after", function () use ($app) {
            $app->view->enqueueScript("app", "ng.mapas-network", "js/ng.mapas-network.js", [/*"mapasculturais"*/]);
            $app->view->jsObject["angularAppDependencies"][] = "ng.mapas-network";
        });

        $dir = self::getPrivatePath();
        if (!is_dir($dir)) {
            mkdir($dir);
        }
        
        $app->hook("mapasculturais.run:before", function () use ($plugin) {
            /** @var \MapasCulturais\App $this */
            if ($this->user->is("guest")) {
                return;
            }
            $plugin->registerEntityMetadataKeyForNodes(Plugin::getCurrentUserNodes());
        });

        /**
         * Redireciona o usuário para a página de vinculação de contas
         * se forem encontradas contas em outros nós que ainda não foram vinculadas.
         * O redirecionamento acontece no primeiro login após a instalação do plugin ou em
         * um intervalo configurado no plugin.
         */
        $app->hook("auth.login", function () use ($app, $plugin) {
            // se está no processo de vinculação, salva o redirect original para uso posterior
            if (($_SESSION["mapas-network:timestamp"] ?? null) > new DateTime()) {
                $plugin->redirectTo = $app->auth->redirectPath;
                return;
            } else { // vinculação expirada, remove dados da sessão
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
                $exclude_nodes = $plugin->getCurrentUserNodes();
                try {
                    if ($plugin->findAccounts($exclude_nodes)) {
                        $app->hook("auth.redirectUrl", function (&$redirectUrl) use ($app) {
                            $redirectUrl = $app->createUrl("network-node", "panel");
                        });
                    }
                } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
                    self::log($e->getMessage());
                }
            }
        });

        /**
         * Getter networkRevisionPrefix que retorna o prefixo dos ids das revisões da entidade
         */
        $app->hook("entity(<<Agent|Event|Space>>).get(networkRevisionPrefix)", function (&$value) use ($plugin) {
            /** @var Entity $this */
            $slug = $plugin->nodeSlug;
            $entity_id = str_replace("MapasCulturais\\Entities\\", "", (string) $this);
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
        });

        /**
         * Sincronização de novas entidades
         */
        $app->hook("entity(<<Agent|Space>>).insert:finish", function () use ($plugin) {
            /** @var Entity $this */
            if (Plugin::ensureNetworkID($this)) {
                $plugin->skip($this, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                $this->save(true);
                // Plugin::saveMetadata($this, ["network__id"]);
            }
            $plugin->syncEntity($this, "createdEntity");
        });

        /**
         * Atualização das entidades
         */
        $app->hook("entity(<<Agent|Event|Space>>).update:before,entity(<<Agent|Event|Space>>).meta(<<*>>).update:before,-entity(<<Agent|Event|Space>>).meta(network<<*>>).update:before", function () use ($plugin, $app) {
            /** @var Entity $entity */
            if(strpos($this->className,'Meta') > 0 ) {
                $entity = $this->owner;
            } else {
                $entity = $this;
            }
            if ($plugin->shouldSkip($entity, self::SKIP_BEFORE)) {
                return;
            }
            Plugin::ensureNetworkID($entity);
            $uid = uniqid("", true);
            $revisions = $entity->network__revisions;
            $revisions[] = "{$entity->networkRevisionPrefix}:{$uid}";
            $entity->network__revisions = $revisions;
        });
        $app->hook("entity(<<Agent|Event|Space>>).update:finish,entity(<<Agent|Event|Space>>).meta(<<*>>).update:after,-entity(<<Agent|Event|Space>>).meta(network<<*>>).update:after", function () use ($plugin) {
            /** @var Entity $entity */
            if(strpos($this->className,'Meta') > 0 ) {
                $entity = $this->owner;
            } else {
                $entity = $this;
            }
            if ($plugin->shouldSkip($entity, self::SKIP_AFTER)) {
                return;
            }
            $plugin->syncEntity($entity, "updatedEntity");
        },1000);

        /**
         * Deleção das entidades
         */
        $app->hook("entity(<<Agent|Event|Space>>).delete:after", function () use ($plugin) {
            /** @var Entity $this */
            if (($this->network__sync_control ?: self::SYNC_ON) != self::SYNC_ON) {
                $this->network__sync_control = self::SYNC_DELETED;
                $plugin->skip($this, [self::SKIP_BEFORE, self::SKIP_AFTER]);
                $this->save();
            }
        });
        $app->hook("entity(<<Agent|Event|Space>>).undelete:after", function () use ($plugin) {
            /** @var Entity $this */
            if (($this->network__sync_control ?: self::SYNC_ON) == self::SYNC_DELETED) {
                $this->network__sync_control = self::SYNC_AUTO_OFF;
                Plugin::notifySyncControlOff($this);
                $plugin->skip($this, [self::SKIP_BEFORE, self::SKIP_AFTER]);
                $this->save(true);
            }
        });

        /**
         * Dispara a sincronização da entidade quando a sincronização é reativada para a entidade
         */
        $app->hook("entity(<<agent|event|space>>).meta(network__sync_control).update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\Metadata $this */
            if ($this->value != self::SYNC_ON) {
                return;
            }
            $auto_off = self::SYNC_AUTO_OFF;
            if ($this->owner->changedMetadata["network__sync_control"]["oldValue"] == "$auto_off") {
                $plugin->syncEntity($this->owner, "updatedEntity");
            }
        });

        /**
         * =====================================
         * Sincronização de eventos
         * Para eventos, deve ser observada as ocorrências, não o próprio evento
         * =====================================
         */
        $app->hook("entity(EventOccurrence).insert:before", function () use ($plugin) {
            /** @var EventOccurrence $this */
            if (!$this->event || $plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            Plugin::ensureNetworkID($this->event);
            Plugin::ensureNetworkID($this->space);
            Plugin::ensureNetworkID($this->space->owner);
        });
        $app->hook("entity(EventOccurrence).insert:after", function () use ($plugin) {
            /** @var EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_AFTER)) {
                return;
            }
            if ($this->status == EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            $plugin->registerEventOccurrence($this);
        });
        $app->hook("entity(EventOccurrence).remove:before", function () use ($plugin) {
            /** @var EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            if ($this->status == EventOccurrence::STATUS_PENDING) {
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
            // Plugin::saveMetadata($this->event, ["network__occurrence_ids", "network__occurrence_revisions"]);
            $this->event->save(true);
            $nodes = Plugin::getEntityNodes($this->event);
            $is_descope = str_ends_with($_SERVER["REQUEST_URI"], "/descopedEventOccurrence");
            foreach ($nodes as $node) {
                $meta_key = $node->entityMetadataKey;
                $meta_value = $this->event->$meta_key;
                if (Plugin::checkNodeFilter($node, $this->space) || ($is_descope && $meta_value)) {
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
        });
        $app->hook("entity(EventOccurrence).update:before", function () use ($plugin) {
            /** @var EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_BEFORE)) {
                return;
            }
            if ($this->status == EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            $uid = uniqid("", true);
            $revisions = (array) $this->event->network__occurrence_revisions ?: [];
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
        });
        $app->hook("entity(EventOccurrence).update:after", function () use ($plugin) {
            /** @var EventOccurrence $this */
            if ($plugin->shouldSkip($this->event, self::SKIP_AFTER)) {
                return;
            }
            if ($this->status == EventOccurrence::STATUS_PENDING) {
                return; // do not sync pending occurrence
            }
            if ((array_search($this->id, ((array) $this->event->network__occurrence_ids ?: [])) == false)) {
                $plugin->registerEventOccurrence($this); // this was a pending occurrence, treat as creation
            } else {
                $plugin->syncEventOccurrence($this, "updatedEventOccurrence");
            }
        });



        /**
         * =====================================
         * Sincronização de MetaLists
         * =====================================
         */
        $app->hook("entity(<<Agent|Event|Space>>).metalist(<<*>>).insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if ($plugin->shouldSkip($this, self::SKIP_AFTER)) {
                return;
            }
            
            if (!$plugin->shouldSkip($this, self::SKIP_REVISION)) {
                $network_id = Plugin::ensureNetworkID($this, $this->owner, 'network__metalist_ids');
                $revisions = $this->owner->network__metalist_revisions;
                $revisions[] = $network_id;
                $this->owner->network__metalist_revisions = $revisions;
            }

            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            $this->owner->save(true);
        
            $plugin->syncMetaList($this, "createdMetaList");
        });
        $app->hook("entity(<<Agent|Event|Space>>).metalist(<<*>>).update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if ($plugin->shouldSkip($this, self::SKIP_AFTER)) {
                return;
            }
            
            if (!$plugin->shouldSkip($this, self::SKIP_REVISION)) {
                $uid = uniqid("", true);
                $revisions = $this->owner->network__metalist_revisions;
                $revisions[] = "{$plugin->nodeSlug}:MetaList:{$this->id}:{$uid}";
                $this->owner->network__metalist_revisions = $revisions;
            }
            
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            $this->owner->save(true);

            $plugin->syncMetaList($this, "updatedMetaList");
        });
        $app->hook("entity(<<Agent|Event|Space>>).metalist(<<*>>).remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if ($plugin->shouldSkip($this, self::SKIP_BEFORE)) {
                return;
            }

            $ids = (array) $this->owner->network__metalist_ids;
            $network_id = array_search($this->id, (array) $ids);

            unset($ids[$network_id]);
            $this->owner->network__metalist_ids = $ids;

            if (!$plugin->shouldSkip($this, self::SKIP_REVISION)) {
                $uid = uniqid("", true);
                $revisions = $this->owner->network__metalist_revisions;
                $revisions[] = "{$plugin->nodeSlug}:MetaList:{$this->id}:{$uid}";
                $this->owner->network__metalist_revisions = $revisions;
            }

            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            
            $this->owner->save(true);

            $plugin->requestDeletion($this, $network_id, "deletedMetaList", "metalist");
        });



        /**
         * =====================================
         * Sincronização de Arquivos
         * =====================================
         */

        $app->hook("entity(<<Agent|Event|Space>>).file(<<*>>).insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            if ($plugin->shouldSkip($this, self::SKIP_AFTER)) {
                return;
            }
            
            if (!$plugin->shouldSkip($this, self::SKIP_REVISION)) {
                $network_id = Plugin::ensureNetworkID($this, $this->owner, 'network__file_ids');
                $revisions = $this->owner->network__file_revisions;
                $revisions[] = $network_id;
                $this->owner->network__file_revisions = $revisions;
            }
        
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            $this->owner->save(true);
    
            $plugin->syncFile($this, "createdFile");
        });
        /** @todo implementar sincronização de edições em arquivos. hoje o mapa não oferece interface para edição */
        $app->hook("entity(<<Agent|Event|Space>>).file(<<*>>).remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            if ($plugin->shouldSkip($this, self::SKIP_BEFORE)) {
                return;
            }
        
            $ids = (array) $this->owner->network__file_ids;
            $network_id = array_search($this->id, (array) $ids);
        
            unset($ids[$network_id]);
            $this->owner->network__file_ids = $ids;
        
            if (!$plugin->shouldSkip($this, self::SKIP_REVISION)) { 
                $uid = uniqid("", true);
                $revisions = $this->owner->network__file_revisions;
                $revisions[] = "{$plugin->nodeSlug}:File:{$this->id}:{$uid}";
                $this->owner->network__file_revisions = $revisions;
            }
        
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            
            $this->owner->save(true);
        
            $plugin->requestDeletion($this, $network_id, "deletedFile", "file");
        });
    }

    /**
     * Verifica se o nó deve receber a entidade

     * @param Node $node
     * @param Entity $entity
     * @return bool
     */
    static function checkNodeFilter(Entities\Node $node, Entity $entity)
    {
        $app = App::i();

        $filters = self::parseFilters($node->getFilters($entity->entityType));
        
        $filters["id"] = "EQ($entity->id)";
        $app->em->flush($entity); // we expect this ApiQuery to operate on up-to-date data
        $query = new ApiQuery($entity->className, $filters);
        $result = $query->findIds() == [$entity->id];

        return $result;
    }

    static function parseFilters($filters) {
        foreach ($filters as &$value) {
            if (is_array($value)) {
                $imploded = implode(",", $value);
                $value = "IN($imploded)";
            } else {
                $value = "EQ($value)";
            }
        }

        return $filters;
    }

    /**
     * Registro de metadados e Job Types
     */
    function register()
    {
        $app = App::i();
        $app->registerController("network-node", "\\MapasNetwork\\Controllers\\Node");

        /** Register metadata */

        // synchronisation control
        $sync_control = [
            "label" => i::__("Controle da sincronização automática", "mapas-network"),
            "type" => "int",
            "default" => self::SYNC_ON
        ];
        $this->registerAgentMetadata("network__sync_control", $sync_control);
        $this->registerEventMetadata("network__sync_control", $sync_control);
        $this->registerSpaceMetadata("network__sync_control", $sync_control);
        $revisions_metadata = [
            'label' => i::__('Lista das revisões da rede', 'mapas-network'),
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

        /** 
         * Metadados para controle da sincronização de arquivos 
         */
        $file_ids_metadata = [
            "label" => i::__("Ids dos arquivos na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => (object)[]
        ];
        $this->registerAgentMetadata("network__file_ids", $file_ids_metadata);
        $this->registerSpaceMetadata("network__file_ids", $file_ids_metadata);
        $this->registerEventMetadata("network__file_ids", $file_ids_metadata);

        $file_revisions_metadata = [
            "label" => i::__("Listas de revisões dos arquivos na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__file_revisions", $file_revisions_metadata);
        $this->registerSpaceMetadata("network__file_revisions", $file_revisions_metadata);
        $this->registerEventMetadata("network__file_revisions", $file_revisions_metadata);

        /** 
         * Metadados para controle da sincronização de metalists 
         */
        $metalist_ids_metadata = [
            "label" => i::__("Ids dos metalists na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => (object)[]
        ];
        $this->registerAgentMetadata("network__metalist_ids", $metalist_ids_metadata);
        $this->registerSpaceMetadata("network__metalist_ids", $metalist_ids_metadata);
        $this->registerEventMetadata("network__metalist_ids", $metalist_ids_metadata);

        $metalist_revisions_metadata = [
            "label" => i::__("Listas de revisões dos metalists na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__metalist_revisions", $metalist_revisions_metadata);
        $this->registerSpaceMetadata("network__metalist_revisions", $metalist_revisions_metadata);
        $this->registerEventMetadata("network__metalist_revisions", $metalist_revisions_metadata);

        /** 
         * Metadados para controle da sincronização de ocorrência de eventos 
         */
        // similar to files and metalists, these are dictionaries keyed by the network__id
        $this->registerEventMetadata("network__occurrence_ids", [
            "label" => i::__("Ids das ocorrências na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => (object)[]
        ]);
        $this->registerEventMetadata("network__occurrence_revisions", [
            "label" => i::__("Listas de revisões das ocorrências na rede de Mapas", "mapas-network"),
            "type" => "json",
            "default" => []
        ]);
        
        $this->registerSpaceMetadata("network__proxied_owner", [
            "label" => i::__("O network Id do proprietário original do espaço", "mapas-network"),
            "type" => "string"
        ]);
        $this->registerUserMetadata("network__proxy_slug", [
            "label" => i::__("Se este usuário é proxy de alguma instalação, o slug da mesma.", "mapas-network"),
            "type" => "string"
        ]);
        $this->registerUserMetadata("network__next_verification_datetime", [
            "label" => i::__("Data da próxima verificação por contas nos nodes", "mapas-network"),
            "type" => "DateTime"
        ]);
        // background jobs
        $app->registerJobType(new SyncEntityJobType(self::JOB_SLUG, $this));
        $app->registerJobType(new SyncEventJobType(self::JOB_SLUG_EVENT, $this));
        $app->registerJobType(new SyncFileJobType(self::JOB_SLUG_FILES, $this));
        $app->registerJobType(new SyncMetaListJobType(self::JOB_SLUG_METALISTS, $this));
        $app->registerJobType(new SyncDeletionJobType(self::JOB_SLUG_DELETION, $this));
        $app->registerJobType(new SyncDownloadJobType(self::JOB_SLUG_DOWNLOADS, $this));
        $app->registerJobType(new NodeBootstrapJobType(self::JOB_SLUG_BOOTSTRAP, $this));
        $app->registerJobType(new UpdateNetworkIdJobType(self::JOB_UPDATE_NETWORK_ID, $this));
    }

    /**
     * Retorna o slug da instalação
     * 
     * @return string
     */
    function getNodeSlug()
    {
        return $this->_config['nodeSlug'];
    }

    /**
     * Retorna o slug do metadado que indica o id da entidade neste nó
     */
    function getEntityMetadataKey()
    {
        // @todo trocar por slug do nó
        $slug = $this->nodeSlug;
        return "network__{$slug}_entity_id";
    }

    static function addPropagationUX(\MapasCulturais\Theme $view)
    {
        $view->enqueueScript("app", "mapas-network", "js/mapas-network.js", ["mapasculturais"]);
        $view->localizeScript("pluginMapasNetwork", [
            "deletionPropagationTooltip" => i::__("Se a sincronização estiver habilitada, a entidade será apagada nos demais Mapas da rede.", "mapas-network"),
            "syncControlError" => i::__("Ocorreu um erro ao alterar o controle de sincronização.", "mapas-network"),
            "syncDisabled" => i::__("Sincronização desabilitada.", "mapas-network"),
            "syncEnabled" => i::__("Sincronização habilitada", "mapas-network"),
        ]);
        return;
    }

    static function generateNetworkId(Entity $entity) {
        $plugin = Plugin::$instance;
        $entity_type = str_replace("MapasCulturais\\Entities\\", "", $entity->className);
        $uid = uniqid("", true);
        return "{$plugin->nodeSlug}:{$entity_type}:{$entity->id}:{$uid}";
    }
    
    /**
     * Garante que uma entidade possua um network_id
     *
     * @param Entity $entity
     * @param Entity $owner
     * @param string $key
     * 
     * @return bool
     */
    static function ensureNetworkID(Entity $entity, Entity $owner=null, string $key=null)
    {
        if (!$key && !$entity->network__id) {
            $entity->network__id = Plugin::generateNetworkId($entity);
            $entity->network__revisions = [$entity->network__id];
            
            /**
             * Network Ids e revisões de Arquivos
             */
            $network__file_ids = [];
            foreach($entity->files as $fs) {
                if ($fs instanceof File) {
                    $fs = [$fs];
                }

                foreach($fs as $file) {
                    if($file->parent) {
                        continue;
                    }
                    $network_file_id = Plugin::generateNetworkId($file);
                    $network__file_ids[$network_file_id] = $file->id;
                }
            }
            $entity->network__file_ids = (object) $network__file_ids;
            $entity->network__file_revisions = empty($network__file_ids) ? 
                                                [] : ["{$entity->network__id}::FILES"];

            /**
             * Network Ids e revisões de Metalists
             */
            $network__metalist_ids = [];
            foreach($entity->metalists as $mls) {
                foreach($mls as $metalist) {
                    $network_metalist_id = Plugin::generateNetworkId($metalist);
                    $network__metalist_ids[$network_metalist_id] = $metalist->id;
                }
            }
            $entity->network__metalist_ids = (object) $network__metalist_ids;
            $entity->network__metalist_revisions = empty($network__metalist_ids) ? 
                                                        [] : ["{$entity->network__id}::METALISTS"];

            if ($entity instanceof Event) {
                $network__occurrence_ids = [];
                foreach($entity->occurrences as $mls) {
                    foreach($mls as $occurrence) {
                        $network_occurrence_id = Plugin::generateNetworkId($occurrence);
                        $network__occurrence_ids[$network_occurrence_id] = $occurrence->id;
                    }
                }
                $entity->network__occurrence_ids = (object) $network__occurrence_ids;
                $entity->network__occurrence_revisions = empty($network__occurrence_ids) ? 
                                                            [] : ["{$entity->network__id}::OCCURRENCES"];
            }
            
        } else if ($key && $owner) {
            $network_id = array_search($entity->id, (array) $owner->$key);
            if ($network_id === false) { // network__id doesn't exist or is pending association with the entity ID
                $network_id = array_search(Plugin::UNKNOWN_ID, (array) $owner->$key);
                if ($network_id === false) { // network__id doesn't exist at all
                    $network_id = Plugin::generateNetworkId($entity);
                } else App::i()->log->debug("ensureNetworkID: special entity {$entity} using placeholder ID");
            } else {
                // App::i()->log->debug("ensureNetworkID: special entity {$entity} already registered");
                return false;
            }
            $ids = (array) $owner->$key;
            $ids[$network_id] = $entity->id;
            $owner->$key = $ids;
            return $network_id;
        } else {
            return false;
        }
        return true;
    }

    /**
     * Extrai a classe de uma entidade, dado um network_id
     * 
     * @param string $network_id 
     * @return string 
     */
    static function getClassFromNetworkID(string $network_id)
    {        
        if (preg_match("#(@entity)?:([a-z]+):#i", $network_id, $matches)) {
            return "MapasCulturais\\Entities\\{$matches[2]}";
        } else {
            return null;
        }
    }

    /**
     * Retorna o caminho de onde são salvos as chaves
     * 
     * @return string
     */
    static function getPrivatePath()
    {
        // @todo: colocar numa configuração
        return PRIVATE_FILES_PATH . 'mapas-network-keys/';
    }

    /**
     * Retorna a lista de nós que o usuário autenticado possui vínculo
     * 
     * @return Entities\Node[]
     */
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
     * Retorna a lista de nodes para sincronização de uma entidade
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

    
    /**
     * Retorna o id do usuário proxy de um nó, dado o slug do nó
     * @param string $slug 
     * 
     * @return mixed  
     */
    static function getProxyUserIDForNode(string $slug)
    {
        $query = new ApiQuery("MapasCulturais\\Entities\\User", ["network__proxy_slug" => "EQ($slug)"]);
        $ids = $query->findIds();
        if (empty($ids)) {
            return null;
        }
        return $ids[0];
    }

    /**
     * Serializa uma entidade
     * 
     * @param mixed $value 
     * @param bool $get_json_serialize 
     * 
     * @return array 
     */
    function serializeEntity($value, bool $get_json_serialize=true, Entities\Node $for_node = null)
    {
        if ($get_json_serialize && ($value instanceof Entity)) {
            $temp_value = $value->jsonSerialize();
            if ($value instanceof EventOccurrence) {
                $temp_value["event"] = $value->event;
                $network_id = array_search($value->id, (array) ($value->event->network__occurrence_ids ?: []));
                $temp_value["network__id"] = $network_id;

                $__revisions = $value->event->network__occurrence_revisions;
                $__id = $__revisions->$network_id ?? null;
                if ($network_id && $__id) {
                    $temp_value["network__revisions"] = $__revisions->$network_id;
                }
                
                $temp_value["space"] = "@entity:{$value->space->network__id}";
                
            } else if (($value instanceof Event) && (!empty((array) $value->occurrences))) {
                $temp_value["occurrences"] = [];
                foreach ($value->occurrences as $occurrence) {
                    $new_occurrence = $this->serializeEntity($occurrence);
                    $temp_value["occurrences"][] = $new_occurrence;
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
     * Serializa os arquivos de uma entidade
     * 
     * @param Entity $entity 
     * @param string $key 
     * @param array $groups 
     * @param array $serialized 
     * 
     * @return array 
     */
    function serializeAttachments(Entity $entity, string $key, array $groups, array $serialized)
    {
        $serialized[$key] = array_filter($this->serializeEntity($entity->$key), function ($att_key) use ($groups) {
            return in_array(((string) $att_key), $groups);
        }, ARRAY_FILTER_USE_KEY);
        return $serialized;
    }

    /**
     * Deserializa uma entidade serializada pelo método serializeEntity
     * 
     * @param mixed $value
     * @param Node|null $not_found_node
     * 
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

    /**
     * Retorna uma entidade pelo seu network_id.
     * 
     * Se a entidade não existir e $not_found_node não for null, cria uma nova entidade com os dados do node.
     * 
     * @param string $network__id 
     * @param Node|null $not_found_node 
     * 
     * @return Entity|null 
     */
    function getEntityByNetworkId(string $network__id, Node $not_found_node=null)
    {
        $app = App::i();
        $class_name = $this->getClassFromNetworkID($network__id);
        $query = new ApiQuery($class_name, [
            "network__id" => "EQ({$network__id})",
            "status" => "GTE(-10)",
            "@permissions" => "view",
        ]);
        $ids = $query->findIds();
        $id = $ids[0] ?? null;
        $entity = $id ? $app->repo($class_name)->find($id) : null;
        if (!$entity && $not_found_node) {
            $response = $not_found_node->api->apiGet("network-node/entity", ["network__id" => $network__id]);
            $entity = $this->createEntity($class_name, $network__id, (array) $response->response, $not_found_node);
        }
        return $entity;
    }

    /**
     * Registra o Job para deleção de uma entidade
     * 
     * @param Entity $entity 
     * @param string $action 
     * @param string $group 
     * @param string $type 
     * 
     * @return void 
     */
    function requestDeletion(Entity $entity, string $network_id, string $action, string $type)
    {
        $app = App::i();
        $revisions_key = "network__{$type}_revisions";
        $revisions = $entity->owner->$revisions_key;
        
        $nodes = Plugin::getEntityNodes($entity->owner);
        
        foreach ($nodes as $node) {
            $app->enqueueJob(self::JOB_SLUG_DELETION, [
                "syncAction" => $action,
                "entity" => $entity->jsonSerialize(),
                "node" => $node,
                "nodeSlug" => $node->slug,
                "networkID" => $network_id,
                "className" => $entity->className,
                "ownerClassName" => $entity->owner->className,
                "ownerNetworkID" => $entity->owner->network__id,
                "revisions_key" => $revisions_key,
                "revisions" => $revisions
            ]);
        }
    }

    /**
     * Executa o $cb para cada nó que tenha a entidade
     *
     * $cb = function ($node, $entity) use(...) {...}
     *
     * @param Entity $entity 
     * @param Closure $cb 
     * 
     * @return void 
     */
    static function foreachEntityNodeDo(Entity $entity, Closure $cb)
    {
        $nodes = Plugin::getEntityNodes($entity);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $entity) || (($entity->owner->{$node->entityMetadataKey}) ?: false)) {
                $cb($node, $entity);
            }
        }
    }

    /**
     * Registra o metadado do id da entidade nos outros nós
     * 
     * @param array $nodes 
     * 
     * @return void 
     */
    function registerEntityMetadataKeyForNodes(array $nodes)
    {
        foreach ($nodes as $node) {
            $config = [
                "label" => "Id da entidade no node {$node->slug}",
                "private" => true,
                "type" => "integer"
            ];
            // algo como network_spcultura_entity_id
            $key = $node->entityMetadataKey;
            $this->registerAgentMetadata($key, $config);
            $this->registerEventMetadata($key, $config);
            $this->registerSpaceMetadata($key, $config);
            $this->registerMetadata(EventOccurrence::class, $key, $config);
        }
    }

    /**
     * Adiciona a entidade para a lista de entidades que não devem ser sincronizadas
     */
    function skip(Entity $entity, $modes)
    {
        $hash = spl_object_hash($entity);
        $this->skipList[$hash] = $modes;
    }


    /**
     * Verifica se a entidade NÃO deve ser sincronizada
     * 
     * @param Entity $entity 
     * @param mixed $skip_type 
     * 
     * @return bool 
     */
    function shouldSkip(Entity $entity, $skip_type)
    {
        $hash = spl_object_hash($entity);

        if (in_array($skip_type, ($this->skipList[$hash] ?? []))) {
            return true;
        }
        if (($entity->network__sync_control ?: self::SYNC_ON) != self::SYNC_ON) {
            return true;
        } else if (App::i()->user->id != $entity->ownerUser->id) {
            Plugin::ensureNetworkID($entity);
            $uid = uniqid("", true);
            $revisions = $entity->network__revisions;
            $revisions[] = "{$entity->networkRevisionPrefix}:{$uid}";
            $entity->network__revisions = $revisions;
            $entity->network__sync_control = self::SYNC_AUTO_OFF;
            Plugin::notifySyncControlOff($entity);
            return true;
        }
        return false;
    }

    /**
     * Cria o(s) Job(s) para sincronização da entidade informada
     * 
     * @param Entity $entity 
     * @param string $action 
     * 
     * @return void 
     */
    function syncEntity(Entity $entity, string $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $entity->$metadata_key = $entity->id;
        $nodes = Plugin::getCurrentUserNodes();
        $destination_nodes = array_filter($nodes, function ($node) use ($entity) {
            $node_entity_metadata_key = $node->entityMetadataKey;
            // se a entidade já está vinculada, retorna true
            if ($entity->$node_entity_metadata_key ?: false) {
                return true;
            }
            return Plugin::checkNodeFilter($node, $entity);
        });

        $tracking_nodes = [];
        foreach ($nodes as $node) {
            $meta_key = $node->entityMetadataKey;
            if ($entity->$meta_key) {
                $tracking_nodes[$node->slug] = $node;
            }
        }
        foreach ($destination_nodes as $node) {
            $app->enqueueJob(self::JOB_SLUG, [
                "syncAction" => isset($tracking_nodes[$node->slug]) ? $action : self::ACTION_SCOPED,
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
    }

    /**
     * Cria o(s) Job(s) para sincronizar o evento / ocorrência de evento
     * 
     * @param EventOccurrence $occurrence 
     * @param string $action 
     * 
     * @return void 
     */
    function syncEventOccurrence(EventOccurrence $occurrence, string $action)
    {
        $app = App::i();
        $event = $occurrence->event;
        $metadata_key = $this->entityMetadataKey;
        $event->$metadata_key = $event->id;
        // we don't use foreachEntityNodeDo here because filtering and looping have different references
        $nodes = Plugin::getEntityNodes($occurrence->event);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $event->owner) || Plugin::checkNodeFilter($node, $occurrence->space)) {
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
    }

    /**
     * Cria o(s) Job(s) para sincronizar o arquivo
     * 
     * @param File $file 
     * @param mixed $action 
     * 
     * @return void 
     */
    function syncFile(File $file, $action)
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
        });
    }

    /**
     * Cria o(s) Job(s) para sincronizar o metalist
     * 
     * @param MetaList $list 
     * @param mixed $action 
     * 
     * @return void 
     */
    function syncMetaList(MetaList $list, $action)
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
        });
    }

    /**
     * Cria uma nova entidade da classe informada com o network_id e dados informados
     * 
     * @param string $class_name 
     * @param string $network_id 
     * @param array $data 
     * @param Node $origin 
     * 
     * @return Entity 
     */
    function createEntity(string $class_name, string $network_id, array $data, Node $origin)
    {
        $app = App::i();
        self::log("creating $network_id");
        $entity = new $class_name;
        $data = $this->unserializeEntity($data);
        Plugin::convertEntityData($entity, $data);
        $this->sudo(function () use ($entity) {
            $entity->save(true);
            return;
        });
        foreach (($data["occurrences"] ?? []) as $occurrence) {
            $network_id = array_search($occurrence["id"], $data["network__occurrence_ids"]);
            $this->skip($entity, [self::SKIP_BEFORE, self::SKIP_AFTER]);

            $space = $this->unserializeEntity($occurrence["space"]);
            if (is_null($space)) {
                continue;
            } else if (is_array($space)) {
                $space = $this->resolveVenue($space, $origin);
            }

            $occurrence["space"] = $space;
            $occurrence["event"] = $entity;

            $this->createEntity(EventOccurrence::class, $network_id, $occurrence, $origin);
            
        }
        return $entity;
    }

    /**
     * Procura nos nós configurados por contas do usuário autenticado.
     * Retorna uma lista das urls dos nós onde o usuário autenticado possui conta.
     * 
     * @param array $exclude_nodes 
     * @return array 
     */
    function findAccounts(array $exclude_nodes = [])
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
                self::log("$node->url === $node_url");
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

    /**
     * 
     * @param EventOccurrence $occurrence 
     * @return void 
     */
    function registerEventOccurrence(EventOccurrence $occurrence)
    {
        Plugin::ensureNetworkID($occurrence, $occurrence->event, "network__occurrence_ids");
        // use skips because we don't want to trigger hooks for these bookkeeping tasks
        $this->skip($occurrence->event, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        $occurrence->event->save(true);
        // Plugin::saveMetadata($occurrence->event, ["network__id", "network__occurrence_ids"]);
        $this->skip($occurrence->space, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        $occurrence->space->save(true);
        // Plugin::saveMetadata($occurrence->space, ["network__id"]);
        $this->skip($occurrence->space->owner, [self::SKIP_BEFORE, self::SKIP_AFTER]);
        $occurrence->space->owner->save(true);
        // Plugin::saveMetadata($occurrence->space->owner, ["network__id"]);
        $this->syncEventOccurrence($occurrence, "createdEventOccurrence");
    }

    /**
     * Cria ou retorna o espaço dado os dados recebidas do espaço.
     * 
     * Se não existir um espaço com o network_id que consta no 
     * 
     * @param array $space_data 
     * @param Node $node 
     * @return Space|null
     */
    function resolveVenue(array $space_data, Node $node)
    {
        $space_entity = $this->getEntityByNetworkId($space_data["network__id"]);
        if (!$space_entity) {
            if (!$space_data["owner"]) {
                $id = Plugin::getProxyUserIDForNode($node->slug);
                if (!$id) {
                    throw new \Exception("The proxy user for {$node->slug} does not exist.");
                }
                $proxy_user = App::i()->repo("User")->find($id);
                $space_data["network__proxied_owner"] = $space_data["owner"];
                $space_data["owner"] = $proxy_user->profile;
            }
            $plugin = $this;
            // the space's owner isn't necessarily the event's owner so this must be sudone
            Plugin::sudo(function () use ($node, $plugin, $space_data) {
                $space_entity = $plugin->createEntity(Plugin::getClassFromNetworkID($space_data["network__id"]), $space_data["network__id"], $space_data, $node);
                $space_entity->{$node->entityMetadataKey} = $space_data["id"];
                $space_entity->save(true);
            });
        }
        return $space_entity;
    }

    /**
     * Aplica os dados enviados à entidade enviada.
     * 
     * @param Entity $entity 
     * @param array $data 
     * 
     * @return void 
     */
    static function convertEntityData(Entity $entity, array $data)
    {
        $skip_fields = [
            "id",
            "user",
            "userId",
            "createTimestamp",
            "updateTimestamp",
            "network__occurrence_ids",
            "network__sync_control",

            "network__file_ids",  "network__file_revisions",
            "network__metalist_ids",  "network__metalist_revisions",
            
            "occurrences", // handled in createEntity
            "files",
            "metalists"
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
            if (($key == "status") && (($entity->network__sync_control ?: self::SYNC_ON) == self::SYNC_DELETED)) {
                continue;
            }
            if (($entity instanceof EventOccurrence)) {
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
    }

    /**
     * Envia para o dono da entidade uma notificação avisando que a sincronização
     * foi automaticamente desligada.
     * @param $entity A entidade cuja sincronização foi desligada.
     */
    static function notifySyncControlOff(Entity $entity)
    {
        $notification = new Notification;
        $notification->user = $entity->ownerUser;
        $message = i::__("A sincronização para %s foi automaticamente desabilitada. Visite a página para reabilitar.", "mapas-network");
        $notification->message = sprintf($message, "<a href=\"{$entity->singleUrl}\" >{$entity->name}</a>");
        $notification->save(true);
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
        });
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
    }

    /**
     * Retorna os filtros de um nó dada a url do nó
     * 
     * @param mixed $url 
     * @return array
     */
    static function getNodeFilters($url) {
        $app = App::i();
        $api = new MapasSDK($url);

        $cache_key = $url . ':filters';
        
        if ($app->cache->contains($cache_key)) {
            $filters = $app->cache->fetch($cache_key);
        } else {
            $response = $api->apiGet('network-node/filters');

            $filters = $response->response ?? [];

            $app->cache->save($cache_key, $filters, 30 * MINUTE_IN_SECONDS);
        }

        return $filters;
    }

    /**
     * Faz log se a configuraçao $app->config['plugin.MapasNetwork.log'] estiver definida e for verdadeira
     * 
     * @param mixed $message 
     * @return void 
     */
    static function log(string $message, string $level = 'debug') {
        $app = App::i();

        if ($app->_config['plugin.MapasNetwork.log'] ?? false) {
            $app->log->$level($message);
        }
    }
}
