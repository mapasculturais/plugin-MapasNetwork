
<?php 
use MapasCulturais\i; 
$checked = (($entity->network__sync_control ?? \MapasNetwork\Plugin::SYNC_ON) == \MapasNetwork\Plugin::SYNC_ON) ? 'checked' : '';
?>
<label class="mapas-network--panel-switch">
    <span class="switch js-sync-switch" style="border: 1px solid; vertical-align: middle;">
        <input 
            type="checkbox" <?= $checked ?> 
            data-mned-network-id="<?= $entity->network__id ?>"
        />
        <span class="slider"></span>
    </span>
    <?= i::__('Sincronizar') ?>
</label>
