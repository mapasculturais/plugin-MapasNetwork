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
        <h2><span id="title-panel"><?php i::_e('Vinculação de conta com outros Mapas Culturais');?></span> </h2>
        <?php $this->applyTemplateHook("panel-header", "before"); ?>
        <?php $this->applyTemplateHook("panel-header", "begin"); ?>
        <div class="container-subitle-line">
            <h2><span class="subtitle-panel"><?php i::_e("Mapas vinculados"); ?></span>
                <hr>
            </h2>
        </div>
    </div>
    <?php $this->applyTemplateHook("panel-header", "end") ?>
    </header>
    <?php $this->applyTemplateHook("panel-header", "after"); ?>

    <?php if ($found_accounts) : ?>
        <div class="alert info">
            <span><?php i::_e('Você não vinculou sua conta em nenhum Mapa Cultural. Veja os mapas disponíveis vinculação de conta ou utilize o botão “Vincular conta” para adicionar um Mapa Cultural não listado.'); ?>
            </span>
        </div>
        <div class="container-subitle-line">
                <h2 class="subtitle-panel"><?php i::_e ("Mapas que você possui conta");?></h2>
                <hr>
            </div>
        <?php foreach ($found_accounts as $url) : ?>
            <article class="objeto clearfix">
                <div class="btn-clearfix-position">
                    <h1><?= $url; ?></h1>                  
                        <form method="POST" action="<?= $this->controller->createUrl('create') ?>">
                            <input type="hidden" name="url" value="<?= $url ?>" />
                            <button class="btn btn-small btn-primary"><?php i::_e("vincular conta"); ?></button>
                        </form>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>

    <div id="main">
        <?php foreach ($nodes as $node) : ?>
            <?php $this->part("network-node/panel-node.php", array("entity" => $node)); ?>
        <?php endforeach; ?>
        <!-- alert-info deletado para melhoria de layout -->
    </div>

            <!-- alert-info deletado para melhoria de layout-->

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