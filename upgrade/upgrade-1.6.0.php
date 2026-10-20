<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0($module): bool
{
    $table = _DB_PREFIX_ . 'customcatalogonpdf_tarif_line';
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "additional_reduction_percent"');
    if ($columns === false) {
        return false;
    }
    if ($columns) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '` ADD `additional_reduction_percent` DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER `reduction_percent`'
    );
}
