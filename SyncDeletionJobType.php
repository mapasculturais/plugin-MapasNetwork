<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncDeletionJobType extends \MapasCulturais\Definitions\JobType
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
        $node = $job->node;
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "className" => $job->className,
            "network__id" => $job->networkID,
            "ownerClassName" => $job->ownerClassName,
            "ownerNetworkID" => $job->ownerNetworkID,
            "group" => $job->group,
            $job->revisions_key => $job->revisions,
        ];
        try {
            Plugin::log("SYNC: {$job->className}:{$job->entity["id"]} " .
                            "-> {$node->url}");
            $node->api->apiPost("network-node/{$job->syncAction}", $data, [],
                                [CURLOPT_TIMEOUT => 30]);
            $target_network_id = in_array($job->className, ["Agent", "Space"]) ? $job->networkID : $job->ownerNetworkID;
            $entity = $this->plugin->getEntityByNetworkId($target_network_id);
            if (($entity != null) && ($target_network_id == $job->networkID)) {
                $meta_key = $node->entityMetadataKey;
                $entity->$meta_key = 0;
                $this->plugin->skip($entity, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                $entity->save(true);
            }
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
        return ("{$data["className"]}:{$data["entity"]["id"]}->" .
                "{$data["node"]}/{$data["syncAction"]}");
    }
}