<?php

namespace MapasNetwork;

use MapasCulturais\ApiQuery;

class AllSeeingApiQuery extends ApiQuery
{
    public function __construct($entity_class_name, $api_params, $is_subsite_filter=false,
                                $select_all=false, $disable_access_control=false, $parentQuery=null)
    {
        parent::__construct($entity_class_name, $api_params, $is_subsite_filter, $select_all,
                            $disable_access_control, $parentQuery);
        $this->usesStatus = false;
        return;
    }
}
