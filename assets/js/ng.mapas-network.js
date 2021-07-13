console.log("JS is being loaded.");
(function (angular) {
    "use strict";
    var module = angular.module('ng.mapas-network', []);

    module.config(['$httpProvider', function ($httpProvider) {
        $httpProvider.defaults.headers.post['Content-Type'] = 'application/x-www-form-urlencoded;charset=utf-8';
        $httpProvider.defaults.headers.common["X-Requested-With"] = 'XMLHttpRequest';
        $httpProvider.defaults.transformRequest = function (data) {
            var result = angular.isObject(data) && String(data) !== '[object File]' ? $.param(data) : data;

            return result;
        };
    }]);

    module.controller('MapasNetworkController',['$scope', 'MapasNetworkService','$window', function($scope, MapasNetworkService, $window) {
        console.log("Controller constructed?");
        $scope.data = {whatever: "blah", entityShouldSync: true};
        $scope.toggleSync = function () {
            console.log("Toggle sync");
            $scope.data.entityShouldSync = !$scope.data.entityShouldSync;
        };
    }]);

    module.factory('MapasNetworkService', ['$http', '$rootScope', 'UrlService', function ($http, $rootScope, UrlService) {
        return {

        };
    }]);

})(angular);
