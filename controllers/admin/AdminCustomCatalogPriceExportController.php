<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CatalogPriceExportService.php';

class AdminCustomCatalogPriceExportController extends ModuleAdminController
{
    private CatalogPriceExportService $exportService;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->exportService = new CatalogPriceExportService($this->module);
    }

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addJqueryPlugin('select2');
        $this->addCSS($this->module->getPathUri() . 'views/css/admin-price-export.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin-price-export.js');
    }

    public function postProcess(): void
    {
        try {
            if (Tools::isSubmit('export_csv')) {
                $this->exportService->export(Tools::getAllValues(), 'csv');
            }
            if (Tools::isSubmit('export_xlsx')) {
                $this->exportService->export(Tools::getAllValues(), 'xlsx');
            }
        } catch (Throwable $exception) {
            $this->errors[] = $exception->getMessage();
        }

        parent::postProcess();
    }

    public function initContent(): void
    {
        parent::initContent();

        $viewData = $this->exportService->getViewData(
            Tools::getAllValues(),
            Tools::isSubmit('preview_prices')
        );
        $viewData['form_action'] = $this->context->link->getAdminLink('AdminCustomCatalogPriceExport');
        $viewData['customer_search_url'] = $viewData['form_action'] . '&ajax=1&action=searchCustomers';

        $this->context->smarty->assign($viewData);
        $this->setTemplate('price_export.tpl');
    }

    public function ajaxProcessSearchCustomers(): void
    {
        $this->ajaxRender(json_encode([
            'results' => $this->exportService->searchCustomers((string) Tools::getValue('term', '')),
        ]));
    }
}
