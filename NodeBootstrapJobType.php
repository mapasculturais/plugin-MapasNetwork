<?php
namespace MapasNetwork;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entities\Space;

class NodeBootstrapJobType extends \MapasCulturais\Definitions\JobType
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

        $user = $node->user;
        
        $file_groups = [
            "agent" => array_keys($app->getRegisteredFileGroupsByEntity(Agent::class)),
            "space" => array_keys($app->getRegisteredFileGroupsByEntity(Space::class)),
        ];
        $metalist_groups = [
            "agent" => array_keys($app->getRegisteredMetaListGroupsByEntity(Agent::class)),
            "space" => array_keys($app->getRegisteredMetaListGroupsByEntity(Space::class)),
        ];
        $map_ids = function ($entity) { return $entity->id; };
        $map_serialize = function ($entity) use ($file_groups, $metalist_groups) {
            if (Plugin::ensureNetworkID($entity)) {
                $this->plugin->skip($entity, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                Plugin::saveMetadata($entity, ["network__id"]);
            }
            $serialised = $this->plugin->serializeEntity($entity);
            $serialised = $this->plugin->serializeAttachments($entity, "files", $file_groups[$entity->controllerId], $serialised);
            $serialised = $this->plugin->serializeAttachments($entity, "metalists", $metalist_groups[$entity->controllerId], $serialised);
            return $serialised;
        };
        // agents
        $agents_args = Plugin::parseFilters($node->getFilters('agent'));
        $agent_ids = array_map($map_ids, $user->getEnabledAgents());
        if ($agent_ids) { // it HAS been the case that this was reached with only a draft agent, thus the need to check
            $agents_args["id"] = "IN(" . implode(",", $agent_ids) . ")";
            $agents_query = new ApiQuery(Agent::class, $agents_args);
            $agent_ids = $agents_query->findIds();
            if(!in_array($user->profile->id, $agent_ids)) {
                $agent_ids[] = $user->profile->id;
            }
            $agents = $app->repo("Agent")->findBy(["id" => $agent_ids]);
        } else {
            $agents = [];
        }
        // spaces
        $spaces_args = Plugin::parseFilters($node->getFilters('space'));
        $space_ids = array_map($map_ids, $user->getEnabledSpaces());
        if ($space_ids) {
            $spaces_args["id"] = "IN(" . implode(",", $space_ids) . ")";
            $spaces_query = new ApiQuery(Space::class, $spaces_args);
            $space_ids = $spaces_query->findIds();
            $spaces = $app->repo("Space")->findBy(["id" => $space_ids]);
        } else {
            $spaces = [];
        }
        // POST data
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "agents" => array_map($map_serialize, $agents),
            "spaces" => array_map($map_serialize, $spaces),
        ];
        $node->api->apiPost("network-node/bootstrapSync", $data);
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
        return ("{$data["node"]}/bootstrapSync");
    }
}