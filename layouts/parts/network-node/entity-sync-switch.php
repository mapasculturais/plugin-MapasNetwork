<div class="container-switch"> 
    <span id="switch-sinc">Sincronizar</span>
    <label>
        <span class="switch" ng-controller="MapasNetworkController" style="vertical-align: middle;">
            <input type="checkbox" ng-model="entityShouldSync" ng-change="toggleSync()" />
            <span class="slider"></span>
        </span>
    </label>
</div> 