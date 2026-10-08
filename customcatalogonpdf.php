<?php
/**
 * Module : customcatalogonpdf
 * Génère des catalogues produits en PDF avec profils configurables.
 *
 * @author  Créa2média
 * @version 1.3.5
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class CustomCatalogOnPdf extends Module
{
    public function __construct()
    {
        $this->name            = 'customcatalogonpdf';
        $this->tab             = 'administration';
        $this->version         = '1.3.5';
        $this->author          = 'Créa2média';
        $this->need_instance   = 0;
        $this->bootstrap       = true;
        $this->ps_versions_compliancy = ['min' => '8.0.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Catalogue produits PDF');
        $this->description = $this->l('Génère des catalogues produits en PDF avec des profils de génération personnalisés (filtre marques, prix, clients…).');
    }

    public function install(): bool
    {
        return parent::install()
            && $this->installDb()
            && $this->installTab();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->uninstallDb()
            && $this->uninstallTab();
    }

    // -------------------------------------------------------------------------
    // DB
    // -------------------------------------------------------------------------

    private function installDb(): bool
    {
        $queries = require __DIR__ . '/sql/install.php';
        foreach ($queries as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }
        return true;
    }

    private function uninstallDb(): bool
    {
        $queries = require __DIR__ . '/sql/uninstall.php';
        foreach ($queries as $query) {
            Db::getInstance()->execute($query);
        }
        return true;
    }

    // -------------------------------------------------------------------------
    // Tab
    // -------------------------------------------------------------------------

    private function installTab(): bool
    {
        return $this->installAdminTab(
            'AdminCustomCatalogOnPdf',
            'Catalogue PDF',
            'picture_as_pdf'
        ) && $this->installExportTab() && $this->installTarifTab();
    }

    public function installExportTab(): bool
    {
        return $this->installAdminTab(
            'AdminCustomCatalogPriceExport',
            'Export tarifs',
            'file_download'
        );
    }

    public function installTarifTab(): bool
    {
        return $this->installAdminTab(
            'AdminCustomCatalogTarif',
            'Tarifs clients',
            'request_quote'
        );
    }

    private function uninstallTab(): bool
    {
        foreach (['AdminCustomCatalogTarif', 'AdminCustomCatalogPriceExport', 'AdminCustomCatalogOnPdf'] as $className) {
            $idTab = (int) Tab::getIdFromClassName($className);
            if ($idTab && !(new Tab($idTab))->delete()) {
                return false;
            }
        }

        return true;
    }

    private function installAdminTab(string $className, string $name, string $icon): bool
    {
        if ((int) Tab::getIdFromClassName($className) > 0) {
            return true;
        }

        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = $className;
        $tab->name = [];
        foreach (Language::getLanguages(true) as $language) {
            $tab->name[$language['id_lang']] = $name;
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->module = $this->name;
        $tab->icon = $icon;

        return (bool) $tab->add();
    }

    // -------------------------------------------------------------------------
    // Back-office link
    // -------------------------------------------------------------------------

    public function getContent(): void
    {
        Tools::redirectAdmin(
            Context::getContext()->link->getAdminLink('AdminCustomCatalogOnPdf')
        );
    }
}
