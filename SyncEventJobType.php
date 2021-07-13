<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;
use MapasCulturais\ApiQuery;

class SyncEventJobType extends \MapasCulturais\Definitions\JobType
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
        $event = $this->plugin->getEntityByNetworkId(substr($job->event, 8), $job->node);
        $space = $this->plugin->getEntityByNetworkId(substr($job->space, 8), $job->node);
        if (!$space->owner) {
            $query = new ApiQuery("MapasCulturais\\Entities\\User", [
                "network__proxy_slug" => "EQ({$job->node->slug})"
            ]);
            $ids = $query->findIds();
            if (empty($ids)) {
                $app->log->info("Node {$job->node->slug} has no proxy user.");
                return false;
            }
            $user = $app->repo("User")->find($ids[0]);
            $space->owner = $user->profile;
            $space->save(true);
        }
        $occurrence = $this->plugin->unserializeEntity($job->data);
        $occurrence["space"] = "@entity:{$space->network__id}";
        $network_id = $occurrence["network__id"];
        $ids = $event->network__occurrence_ids ?? [];
        $ids[$network_id] = Plugin::UNKNOWN_ID;
        $event->network__occurrence_ids = $ids;
        $occurrence = $this->plugin->createEntity($occurrence["class_name"], $network_id, $occurrence);
        //$revisions_key = "network__occurrence_revisions";
        //$ids_key = "network__occurrence_ids";
        // try {
            //$app->log->info("SYNC: $entity -> {$node->url}");
            //$node->api->apiPost("network-node/{$action}", $data, [],
            //                    [CURLOPT_TIMEOUT => 30]);
        // } catch (\MapasSDK\Exceptions\UnexpectedError $e) {
        //     $app->log->info($e->getMessage());
        //     return false;
        // }
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
        return "({$data["data"]["network__id"]})->{$data["node"]}/createdEventOccurrence}";
    }
}
