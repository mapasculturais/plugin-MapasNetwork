<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncFileJobType extends \MapasCulturais\Definitions\JobType
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
        $action = $job->syncAction;
        $entity = $job->entity;
        $group = $job->group;
        $node = $job->node;
        $revisions_key = "network__revisions_files_$group";
        $data = [
            "nodeSlug" => $this->plugin->nodeSlug,
            "className" => $entity->getClassName(),
            "network__id" => $entity->network__id,
            "group" => $group,
            $revisions_key => $entity->$revisions_key,
            "data" => [],
        ];
        // TODO: implementation will change to have job do the uploads instead of this
        foreach ($entity->files[$group] as $file) {
            $data["data"][] = [
                "md5" => $file->md5,
                "mimeType" => $file->mime_content_type,
                "name" => $file->name,
                "url" => $file->url,
                "description" => $file->description,
            ];
        }
        try {
            $app->log->info("SYNC: $entity.files($group) -> {$node->url}");
            $node->api->apiPost("network-node/{$action}", $data, [],
                                [CURLOPT_TIMEOUT => 30]);
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
        return "{$data["entity"]}.files({$data["group"]})->{$data["node"]}/{$data["syncAction"]}";
    }
}