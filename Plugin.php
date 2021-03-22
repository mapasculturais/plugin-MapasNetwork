<?php
namespace MapasNetwork;

use MapasCulturais\App;

class Plugin extends \MapasCulturais\Plugin
{
    function __construct(array $config=[])
    {
        parent::__construct($config);
        return;
    }

    function _init()
    {
        $app = App::i();
        $driver = $app->em->getConfiguration()->getMetadataDriverImpl();
        $driver->addPaths([__DIR__]);
        $app->hook("panel.menu:before", function () {
            $this->part("network-node/panel-mapas-network-sidebar.php");
            return;
        });
        $app->hook("GET(network-node.<<*>>):before", function () use ($app) {
            $app->view->enqueueScript("app", "ng.mc.module.notifications",
                                      "js/ng.mc.module.notifications.js");
            $app->view->enqueueScript("app", "ng.mc.directive.editBox",
                                      "js/ng.mc.directive.editBox.js");
            $app->view->enqueueScript("app", "ng.mapas-network",
                                      "js/ng.mapas-network.js",
                                      ["mapasculturais"]);
            return;
        });
        $app->hook("GET(panel.<<*>>):before", function () use ($app) {
            $app->view->enqueueStyle("app", "mapas-network",
                                     "css/mapas-network.css");
            return;
        });
        return;
    }

    function register()
    {
        App::i()->registerController("network-node",
                                     "\\MapasNetwork\\NodeController");
        return;
    }
}
