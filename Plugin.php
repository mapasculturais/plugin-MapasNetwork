<?php

namespace MapasNetwork;

use Doctrine\ORM\OptimisticLockException;
use Doctrine\ORM\ORMInvalidArgumentException;
use Doctrine\ORM\TransactionRequiredException;
use Doctrine\ORM\ORMException;
use InvalidArgumentException;
use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\i;
use MapasNetwork\Entities\Node;
use MapasSDK\Exceptions\BadRequest;
use MapasSDK\Exceptions\Unauthorized;
use MapasSDK\Exceptions\Forbidden;
use MapasSDK\Exceptions\NotFound;
use MapasSDK\Exceptions\UnexpectedError;

/**
 * @property-read string $nodeSlug
 * @package MapasNetwork
 */
class Plugin extends \MapasCulturais\Plugin
{
    const JOB_SLUG = "network__sync_entity";
    const JOB_SLUG_DELETION = "network__sync_entity_deletion";
    const JOB_SLUG_DOWNLOADS = "network__sync_download_files";
    const JOB_SLUG_FILES = "network__sync_entity_files";
    const JOB_SLUG_METALISTS = "network__sync_entity_metalists";
    const SKIP_AFTER = "after";
    const SKIP_BEFORE = "before";

    protected $allowedMetaListGroups = ["links", "videos"];
    protected $savedNetworkID = null;

    function __construct(array $config = [])
    {
        $app = App::i();

        $filters = $config['filters'] ?? [];

        $config += [
            'nodeSlug' => str_replace('.', '', $_SERVER['HOSTNAME'] ?? parse_url($app->baseUrl, PHP_URL_HOST)),
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

        /** @var Plugin $plugin */
        $plugin = $this;

        $app->hook("app.register:after", function() use ($plugin) {
            /** @var \MapasCulturais\App $this */
            $group_types = [
                [
                    "getGroups" => "getRegisteredMetaListGroupsByEntity",
                    "infix" => "metalist",
                    "editHook" => "plugin(MapasNetwork).allowedMetaListGroups",
                    "hookArgs" => [&$plugin->allowedMetaListGroups],
                ],
                ["getGroups" => "getRegisteredFileGroupsByEntity", "infix" => "files"]
            ];
            $register_types = [
                ["class" => \MapasCulturais\Entities\Agent::class, "register" => "registerAgentMetadata"],
                ["class" => \MapasCulturais\Entities\Space::class, "register" => "registerSpaceMetadata"],
            ];
            foreach ($group_types as $group_type) {
                $get_groups = $group_type["getGroups"];
                $infix = $group_type["infix"];
                if (isset($group_type["editHook"]) && isset($group_type["hookArgs"])) {
                    $this->applyHook($group_type["editHook"], $group_type["hookArgs"]);
                }
                foreach ($register_types as $register_type) {
                    $register = $register_type["register"];
                    $groups = $this->$get_groups($register_type["class"]);
                    foreach ($groups as $group) {
                        if (isset($group_type["hookArgs"]) && !in_array($group->name, $group_type["hookArgs"][0])) {
                            continue;
                        }
                        // network IDs
                        $definition = [
                            "label" => ("$infix({$group->name}): " . i::__("lista dos IDs de rede", "mapas-network")),
                            "type" => "json",
                            "default" => []
                        ];
                        $plugin->$register("network__ids_{$infix}_{$group->name}", $definition);
                        // network revisions
                        $definition = [
                            "label" => ("$infix({$group->name}): " . i::__("lista das revisões de rede", "mapas-network")),
                            "type" => "json",
                            "default" => []
                        ];
                        $plugin->$register("network__revisions_{$infix}_{$group->name}", $definition);
                    }
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
                $plugin->registerSpaceMetadata($key, $config);
                //$plugin->registerEventMetadata($key, $config);
            }
            return;
        });

        $entities_hook_prefix = 'entity(<<Agent|Space>>)';

        $app->hook("entity(<<Agent|Space>>).get(networkRevisionPrefix)", function (&$value) use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            $slug = $plugin->nodeSlug;
            $entity_id = str_replace("MapasCulturais\\Entities\\", "", (string) $this);
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
            return;
        });

        $app->hook("{$entities_hook_prefix}.update:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this] ?? []))) {
                return;
            }
            Plugin::ensureNetworkID($this);
            $uid = uniqid("", true);
            $revisions = $this->network__revisions;
            $revisions[] = "{$this->networkRevisionPrefix}:{$uid}";
            $this->network__revisions = $revisions;
            return;
        });

        $app->hook("{$entities_hook_prefix}.insert:before", function () {
            /** @var \MapasCulturais\Entity $this */
            Plugin::ensureNetworkID($this);
            return;
        });

        $app->hook("{$entities_hook_prefix}.insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            $plugin->syncEntity($this, "createdEntity");
            return;
        });

        $app->hook("{$entities_hook_prefix}.update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entity $this */
            if (in_array(self::SKIP_AFTER, ($plugin->skipList[(string) $this] ?? []))) {
                return;
            }
            $plugin->syncEntity($this, "updatedEntity");
            return;
        });

        $metalist_hook_component = "metalist(<<links|videos>>)";
        $app->hook("$entities_hook_prefix.$metalist_hook_component.insert:before", function () use ($plugin) {
            /** @var MapasCulturais\Entities\MetaList $this */
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
            Plugin::ensureNetworkID($this->owner);
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE]);
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
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
            /** @var \MapasCulturais\Entities\MetaList $this */
            if (in_array(self::SKIP_BEFORE, ($plugin->skipList[(string) $this->owner] ?? []))) {
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
        $app->hook("$entities_hook_prefix.$metalist_hook_component.update:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            if (in_array(self::SKIP_AFTER, ($plugin->skipList[(string) $this->owner] ?? []))) {
                return;
            }
            $plugin->syncMetaList($this, "updatedMetaList");
            return;
        });
        $app->hook("$entities_hook_prefix.$metalist_hook_component.remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\MetaList $this */
            $plugin->requestDeletion($this, "deletedMetaList", $this->group, "metalist");
            return;
        });
        $app->hook("$entities_hook_prefix.file(<<*>>).insert:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
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
            Plugin::ensureNetworkID($this->owner);
            $plugin->skip($this->owner, [Plugin::SKIP_BEFORE]);
            $this->owner->save(true);
            return;
        });
        $app->hook("$entities_hook_prefix.file(<<*>>).insert:after", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            $ids_key = "network__ids_files_{$this->group}";
            $ids = (array) $this->owner->$ids_key ?? [];
            if (array_search($this->id, $ids) === false) {
                $ids[$plugin->savedNetworkID] = $this->id;
                $this->owner->$ids_key = $ids;
                $this->owner->save(true);
            }
            $plugin->syncFile($this, "createdFile");
            return;
        });
        $app->hook("$entities_hook_prefix.file(<<*>>).remove:before", function () use ($plugin) {
            /** @var \MapasCulturais\Entities\File $this */
            $plugin->requestDeletion($this, "deletedFile", $this->group, "files");
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

        // background jobs
        $app->registerJobType(new SyncEntityJobType(self::JOB_SLUG, $this));
        $app->registerJobType(new SyncFileJobType(self::JOB_SLUG_FILES, $this));
        $app->registerJobType(new SyncMetaListJobType(self::JOB_SLUG_METALISTS, $this));
        $app->registerJobType(new SyncDeletionJobType(self::JOB_SLUG_DELETION, $this));
        $app->registerJobType(new SyncDownloadJobType(self::JOB_SLUG_DOWNLOADS, $this));
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

    static function ensureNetworkID(\MapasCulturais\Entity $entity)
    {
        if (!$entity->network__id) {
            $uid = uniqid("", true);
            $entity->network__id = "{$entity->networkRevisionPrefix}:{$uid}";
        }
        return;
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

    function serializeEntity($value, $get_json_serialize = true) {
        if($get_json_serialize && $value instanceof Entity) {
            $value = $value->jsonSerialize();
        }

        if($value instanceof Entity) {
            $value = "@entity:{$value->network__id}";

        } else if(is_array($value) || $value instanceof \stdClass) {
            foreach($value as &$val) {
                $val = $this->serializeEntity($val, false);
            }
        }

        return $value;
    }

    /**
     * 
     * @param mixed $value 
     * @param Node|null $not_found_node 
     * @return mixed 
     */
    function unserializeEntity($value, Node $not_found_node = null) {
        if(is_string($value) && preg_match('#@entity:(.*)#', $value, $matches)) {
            $network__id = $matches[1];
            $value = $this->getEntityByNetworkId($network__id, $not_found_node);
            
        } else if(is_array($value) || $value instanceof \stdClass) {
            foreach($value as $key => &$val) {
                if (in_array($key, ['terms', 'location'])) {
                    $val = $val ? (array) $val : null;
                } else {
                    $val = $this->unserializeEntity($val, $not_found_node);
                }
            }
        }

        return $value;
    }

    function getEntityByNetworkId($network__id, Node $not_found_node = null) {
        $app = App::i();

        preg_match("#:(\w+)::#", $network__id, $matches);

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
        $this->skip($entity->owner, [Plugin::SKIP_BEFORE]);
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

    protected $skipList = [];

    function skip($entity, $modes) {
        $this->skipList[(string) $entity] = $modes;
    }

    function saveNetworkID($network_id)
    {
        $this->savedNetworkID = $network_id;
        return;
    }

    function syncEntity(\MapasCulturais\Entity $entity, string $action)
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

    function createEntity($class_name, $network_id, array $data) {
        $app = App::i();

        $app->log->debug("creating $network_id");

        $entity = new $class_name;

        $skip_fields = [
            'id',
            'user',
            'userId',
            'createTimestamp',
            'updateTimestamp'
        ];

        $skip_null_fields = [
            'owner',
            'parent',
            'agent'
        ];

        $data = $this->unserializeEntity($data);

        foreach ($data as $key => $val) {
            if(in_array($key, $skip_fields)) {
                continue;
            }

            if (is_null($val) && in_array($key, $skip_null_fields)) {
                continue;
            }
            
            $entity->$key = $val;
        }

        $entity->save(true);

        return $entity;
    }
}
