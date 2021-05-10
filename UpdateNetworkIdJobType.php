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
        $node = $job->node;
        $entity = $job->entity;
        
        $data = [
            'className' => $entity->className,
            'current_network__id' => $job->current_network__id,
            'new_network__id' => $job->new_network__id
        ];

        $node->api->apiPost('network-node/updateEntityNetworkId', $data);
        
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