<?php
use MapasCulturais\i;
?>
<article class="objeto clearfix">
    <h1><a href="<?php echo $entity->singleUrl; ?>"><?php echo $entity->url; ?></a></h1>
    <div class="entity-actions">    
        <a class="btn btn-small btn-danger" href="<?php echo $entity->deleteUrl; ?>"><?php i::_e("excluir");?></a>
    </div>
</article>
