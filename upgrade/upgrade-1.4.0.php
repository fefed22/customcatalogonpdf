<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_4_0($module): bool
{
    $table = _DB_PREFIX_ . 'customcatalogonpdf_profile';
    $columns = Db::getInstance()->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "id_tarif"');
    if ($columns === false) {
        return false;
    }
    if ($columns) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '` ADD `id_tarif` INT(10) UNSIGNED NOT NULL DEFAULT 0 AFTER `id_manufacturers`'
    );
}
