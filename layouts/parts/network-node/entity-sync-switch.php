<div class="container-switch"> 
    Sincronizar
    <label>
        <span class="switch" ng-controller="MapasNetworkController" style="vertical-align: middle;">
            <input type="checkbox" ng-model="entityShouldSync" ng-change="toggleSync()" />
            <span class="slider"></span>
        </span>
    </label>
</div> 