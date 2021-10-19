var MapasNetwork = {
    confirmDeletionPropagation: function (event, entityId, controllerId) {
        if (!confirm(MapasCulturais.gettext.pluginMapasNetwork.confirmDeletionPropagation)) {
            params = {};
            params[controllerId] = entityId;
            $(event.target).attr("href", MapasCulturais.createUrl("network-node", "insularDelete", params));
        }
        return;
    }
};

$(function () {
    $("#add-network-node .js-cancel").click(function (e) {
        $("#add-network-node form").get(0).reset();
        $("#add-network-node>.js-close").click();
        return;
    });

    $("#add-network-node .js-submit").click(function (e) {
        $("#add-network-node form").submit();
        return;
    });

    // for panel
    $(".entity-actions .btn-danger").click(function (e) {
        meta = $(e.target).parent().siblings(".objeto-meta");
        eid = $("input.mned-id", meta).val();
        ectrlid = $("input.mned-controller-id", meta).val();
        MapasNetwork.confirmDeletionPropagation(e, eid, ectrlid);
        return;
    });

    // for "single" page
    $(".js--remove-entity-button").click(function (e) {
        MapasNetwork.confirmDeletionPropagation(e, MapasCulturais.entity.id, MapasCulturais.mapasNetworkData.controllerId);
        return;
    });

    return;
});
