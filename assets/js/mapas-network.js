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

    $(".entity-actions .btn-danger, .js--remove-entity-button").each(function () {
        const q = $(this).addClass("hltip");
        const title = q.attr("title");
        var titles = (typeof(title) != "string") ? [] : [title];
        titles.push(MapasCulturais.gettext.pluginMapasNetwork.deletionPropagationTooltip);
        q.attr("title", titles.join(" "));
        return;
    });

    $(".objeto-meta .js-sync-switch input").change(function (e) {
        var q = $(e.target);
        const value = q.is(":checked");
        q.prop("disabled", true);
        $.post(MapasCulturais.createUrl("network-node", "syncControl"), {
            "network__id": q.closest(".objeto-meta").children(".mned-network-id").val(),
            "value": value,
        }).done(function() {
            const message = value ? MapasCulturais.gettext.pluginMapasNetwork.syncEnabled :
                                    MapasCulturais.gettext.pluginMapasNetwork.syncDisabled;
            MapasCulturais.Messages.success(message);
            return;
        }).fail(function () {
            q.prop("checked", !value);
            MapasCulturais.Messages.error(MapasCulturais.gettext.pluginMapasNetwork.syncControlError);
            return;
        }).always(function () {
            q.prop("disabled", false);
            return;
        });
        return;
    })

    if ($("#editable-entity").length == 0) {
        $("#main-section").before("<div id=\"editable-entity\" style=\"background: none; border: none; box-shadow: none; height: 0px; min-height: 0px;\"></div>");
    }
    return;
});
