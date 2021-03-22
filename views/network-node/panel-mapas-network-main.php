<?php
$this->layout = "panel";
$entity_name = "\\MapasNetwork\\Node";
$name = \MapasCulturais\i::__("vínculo");
$modal_id = "add-network-node";
$url = \MapasCulturais\App::i()->createUrl("network-node", "create");
?>
<div class="panel-list panel-main-content">
<?php $this->applyTemplateHook("panel-header", "before"); ?>
    <header class="panel-header clearfix">
        <?php $this->applyTemplateHook("panel-header", "begin"); ?>
        <h2><?php \MapasCulturais\i::_e("Meus Mapas Culturais"); ?></h2>
        <div class="btn btn-default add">
            <a class="js-open-dialog" href="javascript:void(0)" data-dialog-block="true" data-dialog="#add-network-node" data-dialog-callback="MapasCulturais.addEntity" data-form-action="insert" data-dialog-title="<?php \MapasCulturais\i::_e("Vincule uma conta de Mapa Cultural"); ?>">
                <?php \MapasCulturais\i::_e("Vincular novo Mapa"); ?>
            </a>
        </div>
        <?php $this->applyTemplateHook("panel-header", "end") ?>
    </header>
    <?php $this->applyTemplateHook("panel-header", "after"); ?>

    <div id="main">
        <?php foreach ($nodes as $node): ?>
            <?php $this->part("network-node/panel-node.php", array("entity" => $node)); ?>
        <?php endforeach; ?>
        <?php if (!$nodes): ?>
            <div class="alert info"><?php \MapasCulturais\i::_e("Você não possui nenhum Mapa vinculado.");?></div>
        <?php endif; ?>
    </div>

    <div id="add-network-node" class="entity-modal has-step js-dialog " style="display: none"><a href="#" class="js-close icon icon-close" rel="noopener noreferrer"></a>
        <header>
            <div class="node-title-create">
                <h2><?php \MapasCulturais\i::_e("Vincule uma conta de Mapa Cultural"); ?></h2>
            </div>
        </header>
        <div class="modal-body">
            <div>
                <span class="message"></span>
                <img src="<?php $this->asset("img/spinner_192.gif") ?>" class="spinner hidden" alt="Enviando..." style="width:5%"/>
                <?php $this->part("modal/feedback-event", ["entity_name" => "agent", "label" => $name, "modal_id" => $modal_id]); ?>
            </div>
            <div class="create-node">
                <?php $this->applyTemplateHook("node-modal-form", "before"); ?>
                <form method="POST" class="create-entity" action="<?php echo $url; ?>"
                    data-entity="<?php echo $url; ?>" data-formid="<?php echo $modal_id; ?>" id="form-for-<?php echo $modal_id; ?>">
                    <?php $this->applyTemplateHook("node-modal-form", "begin" )?>
                    <?php $this->part("modal/before-form"); ?>

                    <input type="hidden" name="parent_id" value="<?php echo $app->user->profile->id; ?>">
                    <?php $this->part("modal/footer", ["entity" => $entity_name]); ?>
                    <?php $app->applyHook("mapasculturais.add_entity_modal.form:after"); ?>
                    <?php $this->applyTemplateHook("node-modal-form", "end"); ?>
                </form>
                <?php $this->applyTemplateHook("node-modal-form", "after"); ?>
            </div>
        </div>
    </div>
</div>
