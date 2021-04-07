$(function () {
    $('#add-network-node .js-cancel').click(function (e) { 
        $('#add-network-node form').get(0).reset();

        $('#add-network-node>.js-close').click();
    });

    $('#add-network-node .js-submit').click(function (e) { 
        $('#add-network-node form').submit();
    });
});
