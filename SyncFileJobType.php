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
        $app = App::i();
        $action = $job->syncAction;
        $entity = $job->entity;
        $group = $entity->group;
        $node = $job->node;
        $revisions_key = "network__revisions_files_$group";
        $ids_key = "network__ids_files_$group";
        $network_id = array_search($entity->id, (array) $entity->owner->$ids_key);
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "ownerClassName" => $entity->owner->className,
            "ownerNetworkID" => $entity->owner->network__id,
            "className" => $entity->className,
            "network__id" => $network_id,
            $revisions_key => $entity->owner->$revisions_key,
            "data" => $this->plugin->serializeEntity($entity)
        ];
        try {
            $app->log->info("SYNC: $entity -> {$node->url}");
            $node->api->apiPost("network-node/{$action}", $data, [],
                                [CURLOPT_TIMEOUT => 30]);
        } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
            $app->log->debug($e->getMessage());
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