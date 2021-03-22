<?php
namespace MapasNetwork;

use MapasCulturais\App;
use MapasCulturais\Traits;

class NodeController extends \MapasCulturais\Controllers\EntityController
{
    use Traits\ControllerAPI;

    function __construct()
    {
        parent::__construct();
        $this->entityClassName = "\\MapasNetwork\\Node";
        $this->layout = "mapas-network";
        return;
    }

    public function GET_index()
    {
        $this->render("mapas-network");
        return;
    }

    public function GET_linkAccounts()
    {
        $this->render("link-accounts");
        return;
    }

    public function GET_panel()
    {
        $this->requireAuthentication();
        $app = App::i();
        $nodeRepo = $app->repo("\\MapasNetwork\\Node");
        $this->render("panel-mapas-network-main", [
            "nodes" => $nodeRepo->findBy(["user" => $app->user]),
        ]);
        return;
    }
}