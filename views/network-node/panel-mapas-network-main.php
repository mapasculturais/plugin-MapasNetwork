<?php

use MapasCulturais\i;

$this->layout = "panel";
$entity_name = "MapasNetwork\\Entities\\Node";
$name = i::__("vínculo");
$modal_id = "add-network-node";
$url = \MapasCulturais\App::i()->createUrl("network-node", "create");
?>
<div class="panel-list panel-main-content">
    <div class="container-panel-header">
        <h2><span id="title-panel">Vinculação de conta com outros Mapas Culturais</span> </h2>
        <?php $this->applyTemplateHook("panel-header", "before"); ?>
            <?php $this->applyTemplateHook("panel-header", "begin"); ?>
            <h2><span id="subtitle-panel"><?php i::_e("Mapas vinculados"); ?></span></h2>
    </div>
    <?php $this->applyTemplateHook("panel-header", "end") ?>
    </header>
    <?php $this->applyTemplateHook("panel-header", "after"); ?>

    <?php if ($found_accounts) : ?>
        <div class="alert info">
            <?php i::_e('Detectamos que você possui conta nos mapas culturais listados abaixo. Recomendamos que você vincule suas contas para que suas informações fiquem sincronizadas.'); ?>
        </div>
        <?php foreach ($found_accounts as $url) : ?>
            <article class="objeto clearfix">
                <h1><?= $url; ?></h1>
                <form method="POST" action="<?= $this->controller->createUrl('create') ?>">
                    <input type="hidden" name="url" value="<?= $url ?>" />
                    <button class="btn btn-small btn-primary"><?php i::_e("vincular conta"); ?></button>
                </form>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <div id="main">
        <?php foreach ($nodes as $node) : ?>
            <?php $this->part("network-node/panel-node.php", array("entity" => $node)); ?>
        <?php endforeach; ?>
        <?php if (!$nodes) : ?>
            <div class="alert info"><?php i::_e("Você não possui nenhum Mapa vinculado."); ?></div>
        <?php endif; ?>
    </div>

    <div class="alert info">
        <?php i::_e('Se você possui conta em outro mapa cultural que não esteja listado acima, você pode vincular as contas utilizando o botão abaixo.'); ?>
    </div>
    <div class="btn btn-default add">
        <a class="js-open-dialog" href="javascript:void(0)" data-dialog-block="true" data-dialog="#add-network-node" data-dialog-callback="MapasCulturais.addEntity" data-form-action="insert" data-dialog-title="<?php i::_e("Vincule uma conta de Mapa Cultural"); ?>">
            <?php i::_e("Vincular conta em outro mapa cultural"); ?>
        </a>
    </div>

    <div id="add-network-node" class="entity-modal has-step js-dialog " style="display: none">
        <a href="#" class="js-close icon icon-close" rel="noopener noreferrer"></a>
        <header>
            <div class="node-title-create">
                <h2><?php i::_e("Vincule uma conta de Mapa Cultural"); ?></h2>
            </div>
        </header>
        <div class="modal-body">
            <div>
                <span class="message"></span>
                <img src="<?php $this->asset("img/spinner_192.gif") ?>" class="spinner hidden" alt="Enviando..." style="width:5%" />
            </div>
            <div class="create-node">
                <?php $this->applyTemplateHook("node-modal-form", "before"); ?>
                <form method="POST" action="<?php echo $url; ?>">
                    <?php $this->applyTemplateHook("node-modal-form", "begin") ?>
                    <?php $this->part("modal/before-form"); ?>

                    <labe>
                        <?php i::_e('URL do mapa a ser vinculado') ?> <span class="modal-required">*</span>
                        <input type="text" name="url">
                    </labe>
                    <?php $this->part("modal/footer", ["entity" => $entity_name]); ?>

                    <?php $this->applyTemplateHook("node-modal-form", "end"); ?>
                </form>
                <?php $this->applyTemplateHook("node-modal-form", "after"); ?>
            </div>

            <footer>
                <button class="btn btn-primary js-submit"><?php i::_e('Vincular Mapa') ?></button>
                <button class="btn btn-defalt js-cancel"><?php i::_e('Cancelar') ?></button>
            </footer>
        </div>
    </div>
</div>