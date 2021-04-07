<?php

namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entity;
use MapasCulturais\i;

/**
 * @property-read string $nodeSlug 
 * @package MapasNetwork
 */
class Plugin extends \MapasCulturais\Plugin
{
    function __construct(array $config = [])
    {
        $app = App::i();

        $config += [
            'nodeSlug' => parse_url($app->baseUrl, PHP_URL_HOST) 
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

        $app->hook('entity(<<Agent|Space|Event>>).get(networkId)', function (&$value) use($plugin) {
            $slug = $plugin->nodeSlug;
            
            $entity_id = str_replace('MapasCulturais\\Entities\\', '', (string) $this);
    
            // algo como spcultura:Agent:33
            $value = "{$slug}:$entity_id";
        });

        $app->hook('entity(<<Agent|Space|Event>>).<<insert|update>>:before', function () use($plugin, $app) {
            $uid = uniqid('',true);

            $revisions = $this->networkRevisions;
            $revisions[] = "{$this->networkId}:{$uid}";

            $this->networkRevisions = $revisions;
        });

        $app->hook('entity(<<Agent|Space|Event>>).insert:after', function () use($plugin, $app) {
            $metadata_key = $plugin->entityMetadataKey;
            $this->$metadata_key = $this->id;
        });

        // na criação de 
        $app->hook('entity(<<Agent|Space|Event>>).insert:after', function () use($plugin) {
            $plugin->syncCreatedEntity($this);
        });

        return;
    }

    function register()
    {
        $app = App::i();

        $app->registerController("network-node", "\\MapasNetwork\\Controllers\\Node");

        $revisions_metadata = [
            'label' => i::__('Lista das revisões da rede', 'mapas-network'),
            'type' => 'json',
            'private' => true,
            'default' => []
        ];

        $this->registerAgentMetadata('networkRevisions', $revisions_metadata);
        $this->registerSpaceMetadata('networkRevisions', $revisions_metadata);
        $this->registerEventMetadata('networkRevisions', $revisions_metadata);

        return;
    }

    function getNodeSlug () {
        return $this->_config['nodeSlug'];
    }

    function getEntityMetadataKey() {
        // @todo trocar por slug do nó
        $slug = $this->nodeSlug;

        return "network_{$slug}_entity_id";
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

    function syncCreatedEntity(Entity $entity) {
        $app = App::i();

        $nodes = self::getEntityNodes($entity);

        $revisions = $entity->networkRevisions;
        $revision = end($revisions);

        foreach($nodes as $node) {
            $data = $entity->jsonSerialize();
            if(isset($data[$node->entityMetadataKey])) {
                continue;
            }
            $data = [
                'nodeSlug' => $this->nodeSlug,
                'className' => $entity->getClassName(),
                'data' => $data,
                'revision' => $revision,
            ];
            // @todo rever o timeout. 1 segundo é muito
            try{
                $node->api->apiPost('network-node/createdEntity', $data, [], [CURLOPT_TIMEOUT => 1]);
            } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
                $app->log->debug($e->getMessage());
            }
        }
    }
}
