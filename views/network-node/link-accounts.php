<?php
use MapasCulturais\i;

$this->page_title = i::__("Vincular contas do Mapas Culturais");

$this->bodyProperties["ng-app"] = "mapas-network.app";
$this->bodyProperties["ng-controller"] = "MapasNetworkController";
?>

<div>
    <input ng-model="data.test">
    {{data.test}}
</div>
