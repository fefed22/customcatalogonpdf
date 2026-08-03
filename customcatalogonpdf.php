<?php
/**
 * Module : customcatalogonpdf
 * Génère des catalogues produits en PDF avec profils configurables.
 *
 * @author  Créa2média
 * @version 1.0.0
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
        $this->version         = '1.0.0';
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
        $tab = new Tab();
        $tab->active     = 1;
        $tab->class_name = 'AdminCustomCatalogOnPdf';
        $tab->name       = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Catalogue PDF';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminCatalog');
        $tab->module    = $this->name;
        $tab->icon      = 'picture_as_pdf';

        return (bool) $tab->add();
    }

    private function uninstallTab(): bool
    {
        $id_tab = (int) Tab::getIdFromClassName('AdminCustomCatalogOnPdf');
        if ($id_tab) {
            $tab = new Tab($id_tab);
            return (bool) $tab->delete();
        }
        return true;
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
