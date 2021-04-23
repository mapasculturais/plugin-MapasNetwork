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

        $app->hook('mapasculturais.run:before', function () use ($app, $plugin) {
            if (!$app->user->is('guest')) {

                $nodes = $plugin->getCurrentUserNodes();

                foreach ($nodes as $node) {
                    
                    $config = [
                        'label' => "Id da entidade no node {$node->slug}",
                        'private' => true,
                        'type' => 'integer'
                    ];

                    // algo como network_spcultura_entity_id
                    $key = $node->entityMetadataKey;

                    $plugin->registerAgentMetadata($key, $config);
                    $plugin->registerSpaceMetadata($key, $config);
                    $plugin->registerEventMetadata($key, $config);
                }
            }
        });


        $entities_hook_prefix = 'entity(<<Agent|Space>>)';

        $app->hook("{$entities_hook_prefix}.get(networkRevisionPrefix)", function (&$value) use($plugin) {
            $slug = $plugin->nodeSlug;
            
            $entity_id = str_replace('MapasCulturais\\Entities\\', '', (string) $this);
    
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
        });

        $app->hook("{$entities_hook_prefix}.update:before", function () use($plugin, $app) {
            if (in_array($this, $plugin->skipList)) {
                return;
            }
            $uid = uniqid('',true);

            $revisions = $this->network__revisions;
            $revisions[] = "{$this->networkRevisionPrefix}:{$uid}";

            $this->network__revisions = $revisions;
        });

        $app->hook("{$entities_hook_prefix}.insert:before", function () use($plugin, $app) {
            if (!$this->network__id) {
                $uid = uniqid('',true);
                
                $this->network__id = "{$this->networkRevisionPrefix}:{$uid}";
            }
        });

        $app->hook("{$entities_hook_prefix}.insert:after", function () use($plugin, $app) {
            $metadata_key = $plugin->entityMetadataKey;
            $this->$metadata_key = $this->id;

            $nodes = Plugin::getEntityNodes($this);

            foreach($nodes as $node) {
                if ($plugin->checkNodeFilter($node, $this)) {
                    $data = [
                        'syncAction' => 'createdEntity',
                        'entity' => $this, 
                        'node' => $node, 
                        'nodeSlug' => $node->slug
                    ];
                    $app->enqueueJob('network__sync_entity', $data);
                }
            }
        });


        $app->hook("{$entities_hook_prefix}.update:after", function () use($plugin, $app) {
            $metadata_key = $plugin->entityMetadataKey;
            $this->$metadata_key = $this->id;

            $nodes = Plugin::getEntityNodes($this);

            foreach($nodes as $node) {
                if ($plugin->checkNodeFilter($node, $this)) {
                    $data = [
                        'syncAction' => 'updatedEntity',
                        'entity' => $this, 
                        'node' => $node, 
                        'nodeSlug' => $node->slug
                    ];
                    $app->enqueueJob('network__sync_entity', $data); 
                }
            }
        });

        return;
    }


    /**
     * Verifica se o nó deve receber a entidade

     * @param Node $node 
     * @param Entity $entity 
     * @return bool 
     */
    function checkNodeFilter(Entities\Node $node, Entity $entity) {
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
        $sync_entity = new SyncEntityJobType('network__sync_entity', $this);
        $app->registerJobType($sync_entity);

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

    function unserializeEntity($value) {
        if(is_string($value) && preg_match('#@entity:(.*)#', $value, $matches)) {
            $app = App::i();
            $network__id = $matches[1];

            preg_match("#:(\w+)::#", $network__id, $matches);

            $class = 'MapasCulturais\\Entities\\' . $matches[1];

            $query = new ApiQuery($class, ['network__id' => "EQ({$network__id})"]);
            
            $ids = $query->findIds();
            $id = $ids[0] ?? null;

            $value = $id ? $app->repo($class)->find($id) : null;
            
        } else if(is_array($value) || $value instanceof \stdClass) {
            foreach($value as &$val) {
                $val = $this->unserializeEntity($val);
            }
        }

        return $value;
    }


    protected $skipList = [];

    function skip($entity) {
        $this->skipList[] = $entity;
    }
}
