<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_5_0($module): bool
{
    $db = Db::getInstance();
    $queries = require dirname(__DIR__) . '/sql/install.php';
    foreach ($queries as $query) {
        if (strpos($query, '`' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation`') !== false
            && !$db->execute($query)) {
            return false;
        }
    }

    return (bool) $db->execute('
        INSERT INTO `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation`
            (id_tarif, target_type, id_target, target_name, id_employee, employee_name, line_count, date_add)
        SELECT t.id_tarif, "legacy", 0, "", 0, "", 0, t.date_validated
        FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif` t
        WHERE t.status = 1 AND t.date_validated IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation` v
              WHERE v.id_tarif = t.id_tarif
          )');
}
