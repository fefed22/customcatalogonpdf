<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

return [
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_section`',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif`',
    'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_profile`',
];
