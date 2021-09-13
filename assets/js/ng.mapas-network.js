(function (angular) {
    "use strict";
    var module = angular.module("ng.mapas-network", []);

    module.config(["$httpProvider", function ($httpProvider) {
        $httpProvider.defaults.headers.post["Content-Type"] = "application/x-www-form-urlencoded;charset=utf-8";
        $httpProvider.defaults.headers.common["X-Requested-With"] = "XMLHttpRequest";
        $httpProvider.defaults.transformRequest = function (data) {
            var result = (angular.isObject(data) && (String(data) !== "[object File]")) ? $.param(data) : data;
            return result;
        };
    }]);

    module.controller("MapasNetworkController",["$scope", "MapasNetworkService","$window", function($scope, MapasNetworkService, $window) {
        $scope.entityShouldSync = !MapasCulturais.entity.syncControl;
        $scope.toggleSync = function () {
            MapasNetworkService.syncControl(MapasCulturais.entity.networkId, $scope.entityShouldSync).success(function () {
                const message = $scope.entityShouldSync ? MapasCulturais.gettext.pluginMapasNetwork.syncEnabled :
                                                          MapasCulturais.gettext.pluginMapasNetwork.syncDisabled;
                MapasCulturais.Messages.success(message);
                return;
            }).error(function () {
                $scope.entityShouldSync = !$scope.entityShouldSync;
                MapasCulturais.Messages.error(MapasCulturais.gettext.pluginMapasNetwork.syncControlError);
                return;
            });
            return;
        };
    }]);

    module.factory("MapasNetworkService", ["$http", "$rootScope", "UrlService", function ($http, $rootScope, UrlService) {
        return {
            syncControl: function (networkId, value) {
                var url = MapasCulturais.createUrl("network-node", "syncControl");
                return $http.post(url, {"network__id": networkId, "value": value});
            }
        };
    }]);

})(angular);
