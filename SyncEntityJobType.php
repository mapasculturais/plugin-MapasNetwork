<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncEntityJobType extends \MapasCulturais\Definitions\JobType
{
    /**
     * MapasNetwork Plugin
     * @var Plugin
     */
    protected $plugin;

    function __construct(string $slug, Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($slug);
    }

    protected function _execute(Job $job)
    {
        $app = App::i();
        $action = $job->syncAction;
        $entity = $job->entity;
        $node = $job->node;
        $class_name = $entity->getClassName();
        $data = $this->plugin->serializeEntity($entity);
        if (in_array($action, [Plugin::ACTION_RESYNC, Plugin::ACTION_SCOPED])) {
            $groups = array_keys($app->getRegisteredFileGroupsByEntity($class_name));
            $data = $this->plugin->serializeAttachments($entity, "files", $groups, $data);
            $groups = array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity($class_name)), $this->plugin->allowedMetaListGroups);
            $data = $this->plugin->serializeAttachments($entity, "metalists", $groups, $data);
        }
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "className" => $class_name,
            "network__id" => $entity->network__id,
            "data" => $data,
        ];

        try {
            Plugin::log("SYNC: [[$action]] {$entity} -> {$node->url}");
            $node->api->apiPost("network-node/{$action}", $data, [], [CURLOPT_TIMEOUT => 30]);
        } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
            Plugin::log($e->getMessage());
            return false;
        }
        return true;
    }

    /**
     *
     * @param mixed $data
     * @param string $start_string
     * @param string $interval_string
     * @param int $iterations
     * @return string
     */
    protected function _generateId(array $data, string $start_string, string $interval_string, int $iterations)
    {
        return (string) $data['entity'] . '->' . $data['node'] . '/' . $data['syncAction'];
    }
}
