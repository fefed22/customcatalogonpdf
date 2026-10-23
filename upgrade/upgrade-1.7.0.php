<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_7_0($module): bool
{
    $db = Db::getInstance();
    $queries = require dirname(__DIR__) . '/sql/install.php';
    foreach ($queries as $query) {
        if (strpos($query, '`' . _DB_PREFIX_ . 'customcatalogonpdf_public_price`') !== false
            && !$db->execute($query)) {
            return false;
        }
    }

    $table = _DB_PREFIX_ . 'customcatalogonpdf_tarif';
    $columns = $db->executeS('SHOW COLUMNS FROM `' . $table . '` LIKE "show_public_price"');
    if ($columns === false) {
        return false;
    }
    if ($columns) {
        return true;
    }

    return (bool) $db->execute(
        'ALTER TABLE `' . $table . '` ADD `show_public_price` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`'
    );
}
