$(function () {
    $('#add-network-node .js-cancel').click(function (e) { 
        $('#add-network-node form').get(0).reset();

        $('#add-network-node>.js-close').click();
    });

    $('#add-network-node .js-submit').click(function (e) { 
        $('#add-network-node form').submit();
    });
    // Hancle confirm button
    $('#main-section .js-confirm-map-sync').click(function (e) {
        //$('js-confirm-map-sync').submit();
        //alert('Entramos');
        $.ajax('/network-node/confirmLinkAccount', {
            type: 'POST',
            data: {
                confirmed: true,
            }
        });
    });
    $('#main-section .js-cancel-map-sync').click(function (e) {
        //$('js-confirm-map-sync').submit();
        //alert('Entramos');
        $.ajax('/network-node/cancelAccountLink', {
            type: 'POST',
            data: {
                confirmed: false,
            }
        });
    });
});
