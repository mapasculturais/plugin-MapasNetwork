<?php
namespace MapasNetwork;

use MapasCulturais\Entities\Job;

/**
 * @package MapasNetwork
 */
class UpdateNetworkIdJobType extends \MapasCulturais\Definitions\JobType
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
        $skip_node = $job->node;
        $entity = $job->entity;
        $this->plugin->foreachEntityNodeDo($entity, function ($node, $entity) use ($job, $skip_node) {
            if ($node->equals($skip_node)) {
                return;
            }
            $data = [
                "className" => $entity->className,
                "current_network__id" => $job->current_network__id,
                "nodeSlug" => $this->plugin->nodeSlug,
                "new_network__id" => $job->new_network__id
            ];
            $node->api->apiPost("network-node/updateEntityNetworkId", $data);
            return;
        });
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
        return ("{$data["node"]}/{$data["entity"]}/updateEntityNetworkId");
    }
}