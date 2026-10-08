<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

return [
    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_profile` (
        `id_profile`      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`            VARCHAR(255)     NOT NULL,
        `title`           VARCHAR(255)     NOT NULL DEFAULT \'\',
        `id_manufacturers` TEXT,
        `id_group`        INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `id_customer`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `show_prices`     TINYINT(1)       NOT NULL DEFAULT 0,
        `use_discounts`   TINYINT(1)       NOT NULL DEFAULT 0,
        `use_deepest_category` TINYINT(1)   NOT NULL DEFAULT 1,
        `date_add`        DATETIME         NOT NULL,
        `date_upd`        DATETIME         NOT NULL,
        PRIMARY KEY (`id_profile`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif` (
        `id_tarif`        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_customer`     INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `name`            VARCHAR(255)     NOT NULL DEFAULT \'\',
        `logo`            VARCHAR(255)     NOT NULL DEFAULT \'\',
        `status`          TINYINT(1)       NOT NULL DEFAULT 0,
        `date_validated`  DATETIME         NULL DEFAULT NULL,
        `date_add`        DATETIME         NOT NULL,
        `date_upd`        DATETIME         NOT NULL,
        PRIMARY KEY (`id_tarif`),
        KEY `id_customer` (`id_customer`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_section` (
        `id_section`      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_tarif`        INT(10) UNSIGNED NOT NULL,
        `title`           VARCHAR(255)     NOT NULL DEFAULT \'\',
        `position`        INT(10) UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`id_section`),
        KEY `id_tarif` (`id_tarif`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',

    'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line` (
        `id_line`               INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
        `id_tarif`              INT(10) UNSIGNED NOT NULL,
        `id_section`            INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `id_product`            INT(10) UNSIGNED NOT NULL,
        `id_product_attribute`  INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `position`              INT(10) UNSIGNED NOT NULL DEFAULT 0,
        `base_price`            DECIMAL(20,6)    NOT NULL DEFAULT 0,
        `current_price`         DECIMAL(20,6)    NOT NULL DEFAULT 0,
        `reduction_percent`     DECIMAL(10,4)    NOT NULL DEFAULT 0,
        `final_price`           DECIMAL(20,6)    NOT NULL DEFAULT 0,
        PRIMARY KEY (`id_line`),
        KEY `id_tarif` (`id_tarif`),
        KEY `id_section` (`id_section`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',
];
