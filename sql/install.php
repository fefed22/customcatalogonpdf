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
        `date_add`        DATETIME         NOT NULL,
        `date_upd`        DATETIME         NOT NULL,
        PRIMARY KEY (`id_profile`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;',
];
