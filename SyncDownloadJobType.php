<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Entities\Job;

class SyncDownloadJobType extends \MapasCulturais\Definitions\JobType
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
        $class_name = $job->className;
        $data = $job->data;
        $query = new \MapasCulturais\ApiQuery($job->ownerClassName, [
            "network__id" => "EQ({$job->ownerNetworkID})",
            "user" => "EQ({$job->user})"
        ]);
        $ids = $query->findIds();
        if (!$ids && isset($job->ownerSourceNetworkID)) {
            $query = new \MapasCulturais\ApiQuery($job->ownerClassName, [
                "network__id" => "EQ({$job->ownerSourceNetworkID})",
                "user" => "EQ({$job->user})"
            ]);
            $ids = $query->findIds();
        }
        if (!$ids) {
            $app->log->info("Download task for {$data["url"]} cannot find " .
                            "owner of class {$job->ownerClassName} with " .
                            "network ID {$job->ownerNetworkID} and user " .
                            "{$job->user}.");
            return false;
        }
        $id = $ids[0];
        $owner = $app->repo($job->ownerClassName)->find($id);
        if ($app->config["network.fixNodeURLs"] ?? false) {
            $app->log->info("network.fixNodeURLs is enabled");
            $orig_host = parse_url($data["url"], PHP_URL_HOST);
            $host = parse_url($job->node->url);
            $data["url"] = implode($host, explode($orig_host, $data["url"], 2));
        }
        $app->log->info("DOWNLOAD: {$data["url"]}");
        $ch = curl_init($data["url"]);
        $tmp = tempnam("/tmp", "");
        $handle = fopen($tmp, "wb");
        curl_setopt($ch, CURLOPT_FILE, $handle);
        if (!curl_exec($ch)) {
            fclose($handle);
            unlink($tmp);
            $app->log->info("Error downloading from {$data["url"]}.");
            return false;
        }
        curl_close($ch);
        $sz = ftell($handle);
        fclose($handle);
        if (md5_file($tmp) != $data["md5"]) {
            $app->log->info("Download from {$data["url"]} is corrupt.");
            unlink($tmp);
            return false;
        }
        $file = new $class_name([
            "name" => $data["name"],
            "type" => $data["mimeType"],
            "tmp_name" => $tmp,
            "error" => 0,
            "size" => $sz
        ]);
        if (isset($data["description"])) {
            $file->description = $data["description"];
        }
        $file->group = $data["group"];
        $file->owner = $owner;
        // inform network ID to the plugin and prevent it from being created again
        $this->plugin->skip($owner, [Plugin::SKIP_BEFORE]);
        $app->user = $app->repo("User")->find($job->user);
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
        return "{$this->plugin->nodeSlug}:{$data["networkID"]}->download";
    }
}