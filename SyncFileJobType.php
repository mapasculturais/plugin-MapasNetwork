<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncFileJobType extends \MapasCulturais\Definitions\JobType
{
    protected $plugin;

    function __construct(string $slug, Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($slug);
        return;
    }

    protected function _execute(Job $job)
    {
        $action = $job->syncAction;
        $entity = $job->entity;
        $node = $job->node;
        $network_id = array_search($entity->id, (array) $entity->owner->network__file_ids);
        if(!$network_id) {
            return false;
        }
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "ownerClassName" => $entity->owner->className,
            "ownerNetworkID" => $entity->owner->network__id,
            "className" => $entity->className,
            "network__id" => $network_id,
            "network__file_revisions" => $entity->owner->network__file_revisions,
            "data" => $this->plugin->serializeEntity($entity)
        ];
        try {
            Plugin::log("SYNC: $entity -> {$node->url}");
            $node->api->apiPost("network-node/{$action}", $data, [],
                                [CURLOPT_TIMEOUT => 30]);
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
    protected function _generateId(array $data, string $start_string,
                                   string $interval_string, int $iterations)
    {
        return "{$data["entity"]}->{$data["node"]}/{$data["syncAction"]}";
    }
}