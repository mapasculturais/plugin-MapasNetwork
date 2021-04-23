<?php

namespace MapasNetwork;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\i;
use MapasNetwork\Entities\Node;

/**
 * @property-read string $nodeSlug
 * @package MapasNetwork
 */
class Plugin extends \MapasCulturais\Plugin
{
    const JOB_SLUG = "network__sync_entity";
    const JOB_SLUG_FILES = "network___sync_entity_files";
    const JOB_SLUG_METALISTS = "network__sync_entity_metalists";
    const SKIP_AFTER = "after";
    const SKIP_BEFORE = "before";

    protected $savedNetworkID = null;

    function __construct(array $config = [])
    {
        $app = App::i();

        $filters = $config['filters'] ?? [];

        $config += [
            'nodeSlug' => $_SERVER['HOSTNAME'] ?? str_replace('.', '', parse_url($app->baseUrl, PHP_URL_HOST)),
            'filters' => $filters += [
                'agent' => [],
                'space' => []
            ]
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
            $app->view->enqueueStyle(
                "app",
                "mapas-network",
                "css/mapas-network.css"
            );
            return;
        });

        $dir = self::getPrivatePath();
        if (!is_dir($dir)) {
            mkdir($dir);
        }

        $plugin = $this;

        $app->hook("mapasculturais.run:before", function () use ($app, $plugin) {
            if ($app->user->is("guest")) {
                return;
            }
            $nodes = $plugin->getCurrentUserNodes();
            foreach ($nodes as $node) {
                $config = [
                    "label" => "Id da entidade no node {$node->slug}",
                    "private" => true,
                    "type" => "integer"
                    ];
                    // algo como network_spcultura_entity_id
                    $key = $node->entityMetadataKey;
                    $plugin->registerAgentMetadata($key, $config);
                    $plugin->registerSpaceMetadata($key, $config);
                //$plugin->registerEventMetadata($key, $config);
                $config = [
                    "label" => "Lista de IDs dos downloads no node {$node->slug}",
                    "private" => true,
                    "type" => "json"
                ];
                $key = "{$node->entityMetadataKey}_files_downloads";
                $plugin->registerAgentMetadata($key, $config);
                $plugin->registerSpaceMetadata($key, $config);
                $config = [
                    "label" => "Lista de IDs dos links no node {$node->slug}",
                    "private" => true,
                    "type" => "json"
                ];
                $key = "{$node->entityMetadataKey}_metalist_links";
                $plugin->registerAgentMetadata($key, $config);
                $plugin->registerSpaceMetadata($key, $config);
                $config = [
                    "label" => "Lista de IDs dos víudeos no node {$node->slug}",
                    "private" => true,
                    "type" => "json"
                ];
                $key = "{$node->entityMetadataKey}_metalist_videos";
                $plugin->registerAgentMetadata($key, $config);
                $plugin->registerSpaceMetadata($key, $config);
            }
        });


        $entities_hook_prefix = 'entity(<<Agent|Space>>)';

        $app->hook("entity(<<Agent|Space>>).get(networkRevisionPrefix)", function (&$value) use ($plugin) {
            /** @var MapasCulturais\Entity $this */
            $slug = $plugin->nodeSlug;
            $entity_id = str_replace("MapasCulturais\\Entities\\", "", (string) $this);
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
        });

        $app->hook("{$entities_hook_prefix}.update:before", function () use ($plugin) {
            /** @var MapasCulturais\Entity $this */
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this] ?? []))) {
                return;
            }
            $uid = uniqid("", true);

            $revisions = $this->network__revisions;
            $revisions[] = "{$this->networkRevisionPrefix}:{$uid}";

            $this->network__revisions = $revisions;
        });

        $app->hook("{$entities_hook_prefix}.insert:before", function () {
            /** @var MapasCulturais\Entity $this */
            if (!$this->network__id) {
                $uid = uniqid("", true);
                $this->network__id = "{$this->networkRevisionPrefix}:{$uid}";
            }
        });

        $app->hook("{$entities_hook_prefix}.insert:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity $this */
            $plugin->syncEntity($this, "createdEntity");
            return;
        });

        $app->hook("{$entities_hook_prefix}.update:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity $this */
            if (in_array(self::SKIP_AFTER, ($plugin->skipList[(string) $this] ?? []))) {
                return;
            }
            $plugin->syncEntity($this, "updatedEntity");
            return;
        });

        $metalist_hook_component = "metalist(<<links|videos>>)";
        $app->hook("$entities_hook_prefix.$metalist_hook_component.insert:before", function () use ($plugin) {
            /** @var MapasCulturais\Entity\MetaList $this */
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this->owner] ?? []))) {
                return;
            }
            $ids_key = "network__ids_metalist_{$this->group}";
            $ids = (array) $this->owner->$ids_key ?? [];
            if (array_search($this->id, $ids) === false) {
                $uid = uniqid("", true);
                // replicate and adapt the code from the getter hook
                $prefix = str_replace("MapasCulturais\\Entities\\", "", "{$this->className}:{$this->id}");
                $plugin->savedNetworkID = "{$prefix}:{$uid}";
                $ids[$plugin->savedNetworkID] = -1;
                $this->owner->$ids_key = $ids;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_metalist_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.insert:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity\MetaList $this */
            $ids_key = "network__ids_metalist_{$this->group}";
            $ids = (array) $this->owner->$ids_key ?? [];
            if (array_search($this->id, $ids) === false) {
                $ids[$plugin->savedNetworkID] = $this->id;
                $this->owner->$ids_key = $ids;
                $this->owner->save(true);
            }
            $plugin->syncMetaList($this, "createdMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.update:before", function () use ($plugin) {
            /** @var MapasCulturais\Entity\MetaList $this */
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this->owner] ?? []))) {
                return;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_metalist_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.update:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity\MetaList $this */
            // TODO: implement
            if (in_array(self::SKIP_AFTER, ($plugin->skipList[(string) $this->owner] ?? []))) {
                return;
            }
            $plugin->syncMetaList($this, "updatedMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.remove:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity\MetaList $this */
            // TODO: implement
            $plugin->syncMetaList($this, "deletedMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix.file(downloads).insert:before", function () use ($plugin) {
            /** @var MapasCulturais\Entity\File $this */
            // TODO: verify/implement
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this->owner] ?? []))) {
                return;
            }
            $ids_key = "network__ids_files_{$this->group}";
            $ids = (array) $this->owner->$ids_key ?? [];
            if (array_search($this->id, $ids) === false) {
                $uid = uniqid("", true);
                // replicate the code from the getter hook because it's expensive to enable
                $prefix = str_replace("MapasCulturais\\Entities\\", "", (string) $this);
                $plugin->savedNetworkID = "{$prefix}:{$uid}";
                $ids[$plugin->savedNetworkID] = -1;
                $this->owner->$ids_key = $ids;
            }
            $uid = uniqid("", true);
            $revisions_key = "network__revisions_files_{$this->group}";
            $revisions = $this->owner->$revisions_key;
            $revisions[] = "{$this->owner->networkRevisionPrefix}:{$uid}";
            $this->owner->$revisions_key = $revisions;
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix.file(downloads).insert:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity\File $this */
            // TODO: implement
            $ids_key = "network__ids_files_{$this->group}";
            $ids = (array) $this->owner->$ids_key ?? [];
            if (array_search($this->id, $ids) === false) {
                $ids[$plugin->savedNetworkID] = $this->id;
                $this->owner->$ids_key = $ids;
                $this->owner->save(true);
            }
            $plugin->syncFileGroup($this, "createdFile");
            return;
        });
        $app->hook("$entities_hook_prefix.file(downloads).remove:after", function () use ($plugin) {
            /** @var MapasCulturais\Entity\File $this */
            // TODO: implement
            $plugin->syncFileGroup($this, "deletedFile");
            return;
        });
        return;
    }


    /**
     * Verifica se o nó deve receber a entidade

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

        // @todo: Verificar se a entidade já está conectada com o nó e retornar true ou pensar no que fazer quando a condição de sincronizaçào não mais for atendida.

        return $result;
    }

    function register()
    {
        $app = App::i();

        $app->registerController("network-node", "\\MapasNetwork\\Controllers\\Node");

        $revisions_metadata = [
            'label' => i::__('Lista das revisões da rede', 'mapas-network'),
            'type' => 'json',
            'default' => []
        ];

        $this->registerAgentMetadata('network__revisions', $revisions_metadata);
        $this->registerSpaceMetadata('network__revisions', $revisions_metadata);

        $network_id_metadata = [
            'label' => i::__('Id da entidade na rede de mapas', 'mapas-network'),
            'type' => 'string',
            'private' => true
        ];

        $this->registerAgentMetadata('network__id', $network_id_metadata);
        $this->registerSpaceMetadata('network__id', $network_id_metadata);

        // network IDs for downloads
        $definition = [
            "label" => i::__("Lista das IDs de rede dos downloads", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__ids_files_downloads", $definition);
        $this->registerSpaceMetadata("network__ids_files_downloads", $definition);
        // network revisions for downloads
        $definition = [
            "label" => i::__("Lista das revisões de rede dos downloads", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__revisions_files_downloads", $definition);
        $this->registerSpaceMetadata("network__revisions_files_downloads", $definition);
        // network IDs for links and video lists
        $definition = [
            "label" => i::__("Lista dos IDs de rede da lista de links", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__ids_metalist_links", $definition);
        $this->registerSpaceMetadata("network__ids_metalist_links", $definition);
        $definition = [
            "label" => i::__("Lista dos IDs de rede da lista de vídeos", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__ids_metalist_videos", $definition);
        $this->registerSpaceMetadata("network__ids_metalist_videos", $definition);
        // network revisions for links and video lists
        $definition = [
            "label" => i::__("Lista das revisões de rede da lista de links", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__revisions_metalist_links", $definition);
        $this->registerSpaceMetadata("network__revisions_metalist_links", $definition);
        $definition = [
            "label" => i::__("Lista das revisões de rede da lista de vídeos", "mapas-network"),
            "type" => "json",
            "default" => []
        ];
        $this->registerAgentMetadata("network__revisions_metalist_videos", $definition);
        $this->registerSpaceMetadata("network__revisions_metalist_videos", $definition);

        // background jobs
        $sync_entity = new SyncEntityJobType(self::JOB_SLUG, $this);
        $app->registerJobType($sync_entity);
        $app->registerJobType(new SyncFileJobType(self::JOB_SLUG_FILES, $this));
        $app->registerJobType(new SyncMetaListJobType(self::JOB_SLUG_METALISTS, $this));
        return;
    }

    function getNodeSlug () {
        return $this->_config['nodeSlug'];
    }

    function getEntityMetadataKey() {
        // @todo trocar por slug do nó
        $slug = $this->nodeSlug;

        return "network__{$slug}_entity_id";
    }

    static function getPrivatePath()
    {
        // @todo: colocar numa configuração
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


    protected $skipList = [];

    function skip($entity, $modes) {
        $this->skipList[(string) $entity] = $modes;
    }

    function saveNetworkID($network_id)
    {
        $this->savedNetworkID = $network_id;
        return;
    }

    function syncEntity(Entity $entity, string $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $entity->$metadata_key = $entity->id;
        $nodes = Plugin::getEntityNodes($entity);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $entity)) {
                $app->enqueueJob(self::JOB_SLUG, [
                    "syncAction" => $action,
                    "entity" => $entity,
                    "node" => $node,
                    "nodeSlug" => $node->slug
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
        $nodes = Plugin::getEntityNodes($file->owner);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $file->owner)) {
                $app->enqueueJob(self::JOB_SLUG_FILES, [
                    "syncAction" => $action,
                    "entity" => $file,
                    "node" => $node,
                    "nodeSlug" => $node->slug
                ]);
            }
        }
        return;
    }

    function syncMetaList(\MapasCulturais\Entities\MetaList $list, $action)
    {
        $app = App::i();
        $metadata_key = $this->entityMetadataKey;
        $list->owner->$metadata_key = $list->owner->id;
        $nodes = Plugin::getEntityNodes($list->owner);
        foreach ($nodes as $node) {
            if (Plugin::checkNodeFilter($node, $list->owner)) {
                $app->enqueueJob(self::JOB_SLUG_METALISTS, [
                    "syncAction" => $action,
                    "entity" => $list,
                    "node" => $node,
                    "nodeSlug" => $node->slug
                ]);
            }
        }
        return;
    }
}
