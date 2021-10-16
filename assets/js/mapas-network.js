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

    $(".entity-actions .btn-danger").click(function (e) {
        gramps = $(e.target).parent().parent();
        eid = $("input.mned-id", gramps).val();
        ectrlid = $("input.mned-controller-id", gramps).val();
        if (!confirm(MapasCulturais.gettext.pluginMapasNetwork.confirmDeletionPropagation)) {
            params = {};
            params[ectrlid] = eid;
            $(e.target).attr("href", MapasCulturais.createUrl("network-node", "insularDelete", params));
        }
        return;
    });
    return;
});
