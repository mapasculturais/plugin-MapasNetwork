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

        $allowed_metalist_groups = $this->plugin->allowedMetaListGroups;
        $file_groups = [
            "agent" => array_keys($app->getRegisteredFileGroupsByEntity(Agent::class)),
            "space" => array_keys($app->getRegisteredFileGroupsByEntity(Space::class))
        ];
        $metalist_groups = [
            "agent" => array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity(Agent::class)), $allowed_metalist_groups),
            "space" => array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity(Space::class)), $allowed_metalist_groups)
        ];
        $map_ids = function ($entity) { return $entity->id; };
        $map_serialize = function ($entity) use ($file_groups, $metalist_groups) {
            $serialised = $this->plugin->serializeEntity($entity);
            $serialised = $this->plugin->serializeAttachments($entity, "files", $file_groups[$entity->controllerId], $serialised);
            $serialised = $this->plugin->serializeAttachments($entity, "metalists", $metalist_groups[$entity->controllerId], $serialised);
            return $serialised;
        };

        $agents_args = $node->getFilters(Agent::class);
        $agent_ids = array_map($map_ids, $user->getEnabledAgents());
        $agents_args['id'] = 'IN(' . implode(',', $agent_ids) . ')';
        $agents_query = new ApiQuery(Agent::class, $agents_args);
        $agent_ids = $agents_query->findIds();
        $agents = $app->repo('Agent')->findBy(['id' => $agent_ids]);

        $spaces_args = $node->getFilters(Space::class);
        $space_ids = array_map($map_ids, $user->getEnabledSpaces());
        if ($space_ids) {
            $spaces_args['id'] = 'IN(' . implode(',', $space_ids) . ')';
            $spaces_query = new ApiQuery(Space::class, $spaces_args);
            $space_ids = $spaces_query->findIds();
            $spaces = $app->repo('Space')->findBy(['id' => $space_ids]);
        } else {
            $spaces = [];
        }

        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            'agents' => array_map($map_serialize, $agents),
            'spaces' => array_map($map_serialize, $spaces),
        ];
        $node->api->apiPost('network-node/bootstrapSync', $data);
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