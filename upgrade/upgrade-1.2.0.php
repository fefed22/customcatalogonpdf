<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_2_0($module): bool
{
    return Db::getInstance()->execute(
        'ALTER TABLE `' . _DB_PREFIX_ . 'customcatalogonpdf_profile`
         ADD `use_deepest_category` TINYINT(1) NOT NULL DEFAULT 1 AFTER `use_discounts`'
    );
}
