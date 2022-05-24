<?php
namespace MapasNetwork;

use MapasCulturais\ApiQuery;
use MapasCulturais\App;
use MapasCulturais\Entities\Agent;
use MapasCulturais\Entities\Event;
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
            "event" => array_keys($app->getRegisteredFileGroupsByEntity(Event::class)),
            "space" => array_keys($app->getRegisteredFileGroupsByEntity(Space::class)),
        ];
        $metalist_groups = [
            "agent" => array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity(Agent::class)), $allowed_metalist_groups),
            "event" => array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity(Event::class)), $allowed_metalist_groups),
            "space" => array_intersect(array_keys($app->getRegisteredMetaListGroupsByEntity(Space::class)), $allowed_metalist_groups),
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
        // events
        $event_ids = array_map($map_ids, $user->getEnabledEvents());
        if ($event_ids) {
            $query = "IN(" . implode(",", $event_ids) . ")";
            $event_ids = (new ApiQuery(Event::class, ["id" => $query]))->findIds();
            $events = array_filter($app->repo("Event")->findBy(["id" => $event_ids]),
                                   function ($event) use ($node) {
                foreach ($event->occurrences as $occurrence) {
                    if (Plugin::checkNodeFilter($node, $occurrence->space)) {
                        Plugin::ensureNetworkID($occurrence, $event, "network__occurrence_ids");
                        return true;
                    }
                }
                return false;
            });
            foreach ($events as $event) {
                $this->plugin->skip($event, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
                Plugin::saveMetadata($event, ["network__occurrence_ids"]);
            }
        } else {
            $events = [];
        }
        // POST data
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "agents" => array_map($map_serialize, $agents),
            "events" => array_map($map_serialize, $events),
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