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

        $data = $this->plugin->serializeEntity($entity);

        $data = [
            'nodeSlug' => $this->plugin->nodeSlug,
            'className' => $entity->getClassName(),
            'network__id' => $entity->network__id,
            'data' => $data,
        ];

        try {
            $app->log->info("SYNC: {$entity} -> {$node->url}");
            $node->api->apiPost("network-node/{$action}", $data, [], [CURLOPT_TIMEOUT => 30]);
            $target = ($entity instanceof \MapasCulturais\Entities\EventOccurrence) ? $entity->event : $entity;
            $nodes = (array) $target->network__tracking_nodes ?? [];
            $nodes[$node->slug] = true;
            $target->network__tracking_nodes = $nodes;
            $this->plugin->skip($target, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
            $target->save(true);
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
        return (string) $data['entity'] . '->' . $data['node'] . '/' . $data['syncAction'];
    }
}