<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Ajoute la colonne `group_reduction_percent` à la table des lignes de tarif
 * pour les installations 1.3.0 antérieures à cette colonne.
 */
function upgrade_module_1_3_1($module): bool
{
    $table = _DB_PREFIX_ . 'customcatalogonpdf_tarif_line';

    $exists = Db::getInstance()->executeS(
        'SHOW COLUMNS FROM `' . $table . '` LIKE "group_reduction_percent"'
    );

    if (!empty($exists)) {
        return true;
    }

    return (bool) Db::getInstance()->execute(
        'ALTER TABLE `' . $table . '`
         ADD `group_reduction_percent` DECIMAL(10,4) NOT NULL DEFAULT 0 AFTER `base_price`'
    );
}
