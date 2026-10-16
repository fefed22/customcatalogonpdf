<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_3_9($module): bool
{
    $table = _DB_PREFIX_ . 'customcatalogonpdf_tarif_line';
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "is_processed"');
    if ($columns === false) {
        return false;
    }
    if ($columns) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '` ADD `is_processed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `final_price`'
    );
}
