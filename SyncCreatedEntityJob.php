<?php 
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncCreatedEntityJob extends \MapasCulturais\Definitions\JobType
{
    protected $plugin;

    function __construct(string $slug, Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($slug);
    }
    protected function _execute(Job $job)
    {
        $app = App::i();

        $entity = $job->entity;
        $node = $job->node;

        $data = $entity->jsonSerialize();

        $data = [
            'nodeSlug' => $this->plugin->nodeSlug,
            'className' => $entity->getClassName(),
            'data' => $data,
            'network__id' => $entity->network__id
        ];

        try{
            $app->log->info("SYNC: {$entity} -> {$node->url}");
            $node->api->apiPost('network-node/createdEntity', $data, [], [CURLOPT_TIMEOUT => 30]);

            return true;
        } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
            $app->log->debug($e->getMessage());

            return false;
        }
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
        return (string) $data['entity'] . '->' . $data['node'];
    }
}