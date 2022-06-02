<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;
use MapasCulturais\Entity;
use MapasNetwork\Entities\Node;

class SyncDownloadJobType extends \MapasCulturais\Definitions\JobType
{
    protected $plugin;

    function __construct(string $slug, Plugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($slug);
        return;
    }

    protected function _execute(Job $job) {
        $app = App::i();
        $plugin = $this->plugin;

        $app->user = $app->repo("User")->find($job->user);
        $foreign_data = $job->foreign_data;
        $foreign_entity = $job->foreign_entity;
        $owner = $job->owner; 

        $file_groups = array_keys($app->getRegisteredFileGroupsByEntity($owner));
        foreach ($foreign_data as $group => $group_data) {
            if (!in_array($group, $file_groups)) {
                continue;
            }

            if (isset($group_data["id"])) {
                $network_file_id = array_search($group_data['id'], $foreign_entity['network__file_ids']);
                $this->downloadFile($owner, $network_file_id, $group_data);
            } else {
                foreach ($group_data as $file_data) {
                    $entity_files = $owner->files;
                    // se já existe um arquivo com o mesmo md5 no mesmo grupo, pula
                    if (count(array_filter(($entity_files[$group] ?? []), function ($item) use ($file_data) {
                        return ($item->md5 == $file_data["md5"]);
                    })) > 0) {
                        continue;
                    }
                    $network_file_id = array_search($file_data["id"], $foreign_entity['network__file_ids']);
                    $this->downloadFile($owner, $network_file_id, $file_data);
                }
            }
        }


        $plugin->skip($owner, [Plugin::SKIP_BEFORE, Plugin::SKIP_AFTER]);
        $owner->save(true);

        return true;
    }

    protected function downloadFile(Entity $owner, string $network_file_id, $file_data)
    {
        $plugin = $this->plugin;
        $app = App::i();

        $basename = basename($file_data["url"]);
        $file_data["url"] = str_replace($basename, urlencode($basename), $file_data["url"]);

        Plugin::log("DOWNLOAD: {$file_data["url"]}");

        $ch = curl_init($file_data["url"]);
        $tmp = tempnam("/tmp", "");
        $handle = fopen($tmp, "wb");

        curl_setopt($ch, CURLOPT_FILE, $handle);
        if (!curl_exec($ch)) {
            fclose($handle);
            unlink($tmp);
            Plugin::log("Error downloading from {$file_data["url"]}.");
            return false;
        }
        curl_close($ch);
        $sz = ftell($handle);
        fclose($handle);

        $class_name = $owner->fileClassName;
        $file = new $class_name([
            "name" => $file_data["name"],
            "type" => $file_data["mimeType"],
            "tmp_name" => $tmp,
            "error" => 0,
            "size" => $sz
        ]);
        if (isset($file_data["description"])) {
            $file->description = $file_data["description"];
        }
        $file->group = $file_data["group"];
        $file->owner = $owner;

        $definition = $app->getRegisteredFileGroup($owner->controllerId, $file_data["group"]);

        if ($definition->unique && ($current_file = $owner->files[$file_data["group"]] ?? null)) {
            $plugin->skip($file, [Plugin::SKIP_AFTER]);
            $current_file->delete(true);
        }
        
        // inform network ID to the plugin and prevent it from being created again
        $app->hook("entity(<<Agent|Event|Space>>).file(<<*>>).insert:after", function () use($file, $network_file_id) {
            if ($this == $file) {
                $value = $this->owner->network__file_ids ?: (object)[];
                $value->$network_file_id = $this->id;
                $this->owner->network__file_ids = $value;
            }
            
        }, -10);

        $plugin->skip($file, [Plugin::SKIP_REVISION]);
        $file->save(true);
        
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
        // local nodeSlug doesn't matter in production but is requird in a shared database development setup
        return "{$this->plugin->nodeSlug}:{$data["owner"]}->downloads";
    }
}