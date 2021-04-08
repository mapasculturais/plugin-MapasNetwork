(function (angular) {
    "use strict";

    var app = angular.module("mapas-network.app", [
        "mc.directive.editBox",
        "mc.module.notifications",
        "ngSanitize"
    ]);

    app.config(["$httpProvider", function ($httpProvider) {
            $httpProvider.defaults.headers.post["Content-Type"] = "application/x-www-form-urlencoded;charset=utf-8";
            $httpProvider.defaults.transformRequest = function (data) {
                var result = angular.isObject(data) && String(data) !== "[object File]" ? $.param(data) : data;
                return result;
            };
        }]);

    app.factory("MapasNetworkService", ["$http", "$rootScope", function ($http, $rootScope) {
            return {
                serviceProperty: null,
                getUrl: function () {
                    return MapasCulturais.baseURL // + controllerId  + '/' + actionName
                },
                doSomething: function (param) {
                    var data = {
                        prop: name
                    };
                    return $http.post(this.getUrl(), data).
                            success(function (data, status) {
                                $rootScope.$emit("something", {message: "Something was done", data: data, status: status});
                            }).
                            error(function (data, status) {
                                $rootScope.$emit("error", {message: "Cannot do something", data: data, status: status});
                            });
                }
            };
        }]);

    app.controller("MapasNetworkController", ["$scope", "$rootScope", "$timeout", "MapasNetworkService", "EditBox", function ($scope, $rootScope, $timeout, MapasNetworkService, EditBox) {
            var adjustingBoxPosition = false;
            $scope.editbox = EditBox;
            $scope.data = {
                test: "Mapas Network",
                spinner: false,
                apiQuery: {
                }
            };
            var adjustBoxPosition = function () {
                if (adjustingBoxPosition) {
                    return;
                }
                setTimeout(function () {
                    adjustingBoxPosition = true;
                    $("#module-name-owner-button").click();
                    adjustingBoxPosition = false;
                });
            };
            $rootScope.$on("repeatDone:findEntity:find-entity-module-name-owner", adjustBoxPosition);
            $scope.$watch("data.spinner", function (ov, nv) {
                if (ov && !nv)
                    adjustBoxPosition();
            });
        }]);
})(angular);