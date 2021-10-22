<label>
    <span class="switch js-sync-switch" style="border: 1px solid; vertical-align: middle;">
        <input type="checkbox" <?php if (($entity->network__sync_control ?? \MapasNetwork\Plugin::SYNC_ON) == \MapasNetwork\Plugin::SYNC_ON): ?>checked<?php endif ?> />
        <span class="slider"></span>
    </span>
    Sincronizar
</label><br />
