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
            $app->log->info("SYNC: {$job->className}:{$job->entity["id"]} " .
                            "-> {$node->url}");
            $node->api->apiPost("network-node/{$job->syncAction}", $data, [],
                                [CURLOPT_TIMEOUT => 30]);
            $entity = $this->plugin->getEntityByNetworkId(in_array($job->className, ["Agent", "Event", "Space"]) ? $job->networkID : $job->ownerNetworkID);
            if ($entity != null) {
                $nodes = (array) $entity->network__tracking_nodes ?? [];
                if (isset($nodes[$node->slug])) {
                    unset($nodes[$node->slug]);
                    $entity->network__tracking_nodes = $nodes;
                    $this->plugin->skip($entity, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                    $entity->save(true);
                }
            }
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
        return ("{$data["className"]}:{$data["entity"]["id"]}->" .
                "{$data["node"]}/{$data["syncAction"]}");
    }
}