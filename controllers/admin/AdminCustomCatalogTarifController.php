<?php
/**
 * Contrôleur admin des tarifs clients.
 *
 * Fournit la liste des tarifs, le formulaire d'en-tête (client, nom, logo),
 * l'éditeur de sections / lignes (via AJAX), le prix public conseillé, les
 * exports PDF / Excel / CSV (avec ou sans prix public),
 * la duplication vers un autre client et la validation en prix spécifiques.
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CustomCatalogTarif.php';
require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/TarifService.php';

class AdminCustomCatalogTarifController extends ModuleAdminController
{
    private TarifService $service;

    public function __construct()
    {
        $this->table = 'customcatalogonpdf_tarif';
        $this->className = 'CustomCatalogTarif';
        $this->bootstrap = true;
        $this->lang = false;
        $this->identifier = 'id_tarif';
        $this->_defaultOrderBy = 'date_upd';
        $this->_defaultOrderWay = 'DESC';

        parent::__construct();

        $this->service = new TarifService($this->module);

        $this->_select = '
            CONCAT(c.firstname, " ", c.lastname) AS customer_name,
            v.target_type AS validation_target_type,
            v.id_target AS validation_target_id,
            v.target_name AS validation_target_name,
            (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line` l
                WHERE l.id_tarif = a.id_tarif) AS nb_lines';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c ON c.id_customer = a.id_customer
            LEFT JOIN `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation` v
                ON v.id_validation = (
                    SELECT MAX(vlast.id_validation)
                    FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation` vlast
                    WHERE vlast.id_tarif = a.id_tarif
                )';

        $this->fields_list = [
            'id_tarif' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'name' => [
                'title' => $this->l('Nom du tarif'),
            ],
            'customer_name' => [
                'title' => $this->l('Client'),
                'search' => false,
                'orderby' => false,
            ],
            'nb_lines' => [
                'title' => $this->l('Produits'),
                'align' => 'center',
                'search' => false,
                'orderby' => false,
            ],
            'status' => [
                'title' => $this->l('Statut'),
                'align' => 'center',
                'orderby' => false,
                'search' => false,
                'callback' => 'renderStatusBadge',
            ],
            'date_validated' => [
                'title' => $this->l('Dernière validation'),
                'type' => 'datetime',
            ],
            'date_upd' => [
                'title' => $this->l('Mise à jour'),
                'type' => 'datetime',
                'align' => 'right',
            ],
        ];

        $this->addRowAction('edit');
        $this->addRowAction('tarifpdf');
        $this->addRowAction('tarifxlsx');
        $this->addRowAction('tarifcsv');
        $this->addRowAction('validatetarif');
        $this->addRowAction('delete');
    }

    public function renderStatusBadge($value, $row): string
    {
        if ((int) $value !== CustomCatalogTarif::STATUS_VALIDATED) {
            return '<span class="badge badge-default">' . $this->l('Brouillon') . '</span>';
        }
        $type = $row['validation_target_type'] ?? 'legacy';
        $label = $type === 'group' ? $this->l('Validé sur le groupe') : $this->l('Validé sur le client');
        if ($type === 'legacy') {
            $label = $this->l('Validation antérieure — cible non historisée');
        } else {
            $label .= ' : ' . $row['validation_target_name'] . ' (#' . (int) $row['validation_target_id'] . ')';
        }

        return '<span class="badge badge-success">' . Tools::safeOutput($label) . '</span>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Media
    // ─────────────────────────────────────────────────────────────────────────

    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);
        $this->addJqueryPlugin('select2');
        $this->addJqueryUI('ui.sortable');
        $this->addCSS($this->module->getPathUri() . 'views/css/admin-tarif.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin-tarif.js');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Actions exportées (interceptées avant tout rendu HTML)
    // ─────────────────────────────────────────────────────────────────────────

    public function initProcess(): void
    {
        $idTarif = (int) Tools::getValue('id_tarif');

        if ($idTarif > 0) {
            if (Tools::getValue('tarifpdf')) {
                $this->doGeneratePdf($idTarif);
            }
            if (Tools::getValue('tarifxlsx')) {
                $this->doExport($idTarif, 'xlsx');
            }
            if (Tools::getValue('tarifcsv')) {
                $this->doExport($idTarif, 'csv');
            }
        }

        parent::initProcess();
    }

    private function doGeneratePdf(int $idTarif): void
    {
        require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/TarifPdfGenerator.php';
        try {
            (new TarifPdfGenerator($this->service))->generate($idTarif);
        } catch (Throwable $e) {
            $this->errors[] = $this->l('Erreur lors de la génération PDF : ') . $e->getMessage();
        }
    }

    private function doExport(int $idTarif, string $format): void
    {
        try {
            $this->service->export($idTarif, $format);
        } catch (Throwable $e) {
            $this->errors[] = $this->l('Erreur lors de l’export : ') . $e->getMessage();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Traitement POST / actions
    // ─────────────────────────────────────────────────────────────────────────

    public function postProcess()
    {
        if (Tools::getValue('validatetarif') && ($idTarif = (int) Tools::getValue('id_tarif'))) {
            $this->errors[] = $this->l('Ouvrez le tarif et utilisez les deux confirmations pour le valider.');
            return true;
        }

        // Duplication vers un autre client (depuis l'éditeur)
        if (Tools::isSubmit('submitDuplicateTarif') && ($idTarif = (int) Tools::getValue('id_tarif'))) {
            $this->processDuplicateTarif($idTarif);
            return true;
        }

        // Capturer le logo existant avant la sauvegarde (copyFromPost le viderait)
        $previousLogo = null;
        if (
            (Tools::isSubmit('submitAdd' . $this->table) || Tools::isSubmit('submitAdd' . $this->table . 'AndStay'))
            && ($idTarif = (int) Tools::getValue('id_tarif')) > 0
        ) {
            $previousLogo = (string) Db::getInstance()->getValue(
                'SELECT logo FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif` WHERE id_tarif = ' . $idTarif
            );
        }

        $result = parent::postProcess();

        // Gestion du logo après l'enregistrement de l'objet
        if (
            (Tools::isSubmit('submitAdd' . $this->table) || Tools::isSubmit('submitAdd' . $this->table . 'AndStay'))
            && $this->object instanceof CustomCatalogTarif
            && Validate::isLoadedObject($this->object)
        ) {
            $this->handleLogoUpload((int) $this->object->id, $previousLogo);
        }

        return $result;
    }

    private function checkValidationRequest(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !$this->checkToken() || !$this->access('edit')) {
            throw new RuntimeException($this->l('Validation non autorisée.'));
        }
    }

    private function processDuplicateTarif(int $idTarif): void
    {
        $idCustomer = (int) Tools::getValue('duplicate_id_customer');
        try {
            $newId = $this->service->duplicate($idTarif, $idCustomer);
            Tools::redirectAdmin(
                self::$currentIndex . '&token=' . $this->token
                . '&id_tarif=' . $newId . '&updatecustomcatalogonpdf_tarif'
                . '&conf=19'
            );
        } catch (Throwable $e) {
            $this->errors[] = $e->getMessage();
        }
    }

    private function handleLogoUpload(int $idTarif, ?string $previousLogo = null): void
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            return;
        }

        // Par défaut, conserver le logo précédent (copyFromPost l'a vidé)
        if ($previousLogo !== null && $tarif->logo === '' && $previousLogo !== '') {
            $tarif->logo = $previousLogo;
            $tarif->update();
        }

        // Suppression demandée
        if (Tools::getValue('delete_logo')) {
            if ($tarif->getLogoPath() !== '') {
                @unlink($tarif->getLogoPath());
            }
            $tarif->logo = '';
            $tarif->update();
        }

        if (
            !isset($_FILES['logo'])
            || !is_uploaded_file($_FILES['logo']['tmp_name'])
            || (int) $_FILES['logo']['error'] !== UPLOAD_ERR_OK
            || (int) $_FILES['logo']['size'] <= 0
        ) {
            return;
        }

        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = Tools::strtolower(pathinfo((string) $_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true) || !getimagesize($_FILES['logo']['tmp_name'])) {
            $this->errors[] = $this->l('Logo invalide : utilisez une image JPG, PNG, GIF ou WebP.');
            return;
        }

        $dir = CustomCatalogTarif::getLogoDir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->errors[] = $this->l('Impossible de créer le répertoire de stockage du logo.');
            return;
        }

        $filename = 'tarif_' . $idTarif . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], $dir . $filename)) {
            $this->errors[] = $this->l('Impossible d’enregistrer le logo.');
            return;
        }

        if ($tarif->getLogoPath() !== '') {
            @unlink($tarif->getLogoPath());
        }
        $tarif->logo = $filename;
        $tarif->update();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Row actions
    // ─────────────────────────────────────────────────────────────────────────

    public function displayTarifpdfLink($token, int $id, $name = null): string
    {
        return $this->buildRowAction($id, 'tarifpdf', 'icon-file-pdf-o', $this->l('PDF'));
    }

    public function displayTarifxlsxLink($token, int $id, $name = null): string
    {
        return $this->buildRowAction($id, 'tarifxlsx', 'icon-file-excel-o', $this->l('Excel'));
    }

    public function displayTarifcsvLink($token, int $id, $name = null): string
    {
        return $this->buildRowAction($id, 'tarifcsv', 'icon-file-text-o', $this->l('CSV'));
    }

    public function displayValidatetarifLink($token, int $id, $name = null): string
    {
        $url = self::$currentIndex . '&token=' . $this->token . '&id_tarif=' . $id
            . '&updatecustomcatalogonpdf_tarif=1#tarif-validation';

        return '<a class="btn btn-default btn-xs" href="' . Tools::safeOutput($url) . '" title="' . $this->l('Valider') . '">'
            . '<i class="icon-lock"></i>&nbsp;' . $this->l('Valider') . '</a>';
    }

    private function buildRowAction(int $id, string $param, string $icon, string $label): string
    {
        $url = self::$currentIndex . '&token=' . $this->token . '&id_tarif=' . $id . '&' . $param . '=1';

        return '<a class="btn btn-default btn-xs" href="' . $url . '" title="' . $label . '">'
            . '<i class="' . $icon . '"></i>&nbsp;' . $label . '</a>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Formulaire
    // ─────────────────────────────────────────────────────────────────────────

    public function initToolbar()
    {
        parent::initToolbar();
        if ($this->display === 'edit') {
            unset($this->toolbar_btn['save']);
        }
    }

    public function renderForm(): string
    {
        /** @var CustomCatalogTarif|null $obj */
        $obj = $this->loadObject(true);
        $isEdit = $obj && Validate::isLoadedObject($obj);

        $selectedCustomer = $isEdit ? $this->service->getCustomerOption((int) $obj->id_customer) : null;

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Tarif client'),
                'icon' => 'icon-eur',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Nom du tarif'),
                    'name' => 'name',
                    'required' => true,
                    'hint' => $this->l('Nom du document de tarification (affiché sur les exports).'),
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Client'),
                    'name' => 'customer_widget',
                    'html_content' => $this->renderCustomerWidget($selectedCustomer),
                ],
                [
                    'type' => 'html',
                    'label' => $this->l('Logo du tarif'),
                    'name' => 'logo_widget',
                    'html_content' => $this->renderLogoWidget($isEdit ? $obj : null),
                ],
            ],
            'submit' => [
                'title' => $this->l('Enregistrer'),
            ],
        ];

        $this->fields_value['name'] = $isEdit ? $obj->name : '';

        $html = parent::renderForm();

        if ($isEdit) {
            $html = '<details class="tarif-header-details"' . ($this->errors ? ' open' : '') . '>'
                . '<summary>' . $this->l('Tarif client — modifier le nom, le client ou le logo') . '</summary>'
                . $html . '</details>'
                . $this->renderEditor($obj);
        }

        return $html;
    }

    private function renderLogoWidget(?CustomCatalogTarif $tarif): string
    {
        $html = '';

        if ($tarif && $tarif->getLogoPath() !== '') {
            $logoUri = $this->module->getPathUri() . 'uploads/tarif/' . rawurlencode($tarif->logo);
            $html .= '<div class="tarif-logo-preview" style="margin-bottom:8px;">'
                . '<img src="' . Tools::safeOutput($logoUri) . '" alt="" style="max-height:60px;border:1px solid #e0e6ed;border-radius:3px;">'
                . '</div>'
                . '<label class="checkbox-inline" style="margin-bottom:8px;">'
                . '<input type="checkbox" name="delete_logo" value="1"> '
                . $this->l('Supprimer le logo actuel') . '</label><br>';
        }

        $html .= '<input type="file" name="logo" accept="image/*">'
            . '<p class="help-block">' . $this->l('Logo affiché sur la couverture du PDF (JPG, PNG, GIF, WebP).') . '</p>';

        return $html;
    }

    private function renderCustomerWidget(?array $selectedCustomer): string
    {
        $searchUrl = self::$currentIndex . '&token=' . $this->token . '&ajax=1&action=SearchCustomers';

        return '<input type="hidden" name="id_customer" id="tarif_id_customer"'
            . ' class="js-tarif-customer form-control"'
            . ' data-search-url="' . Tools::safeOutput($searchUrl) . '"'
            . ' value="' . ($selectedCustomer ? (int) $selectedCustomer['id'] : '') . '"'
            . ' data-selected-text="' . ($selectedCustomer ? Tools::safeOutput($selectedCustomer['text']) : '') . '">'
            . '<p class="help-block">' . $this->l('Client auquel ce tarif est rattaché (requis pour la validation).') . '</p>';
    }

    private function renderEditor(CustomCatalogTarif $tarif): string
    {
        $data = $this->service->getTarifData((int) $tarif->id, true);
        $ajaxUrl = self::$currentIndex . '&token=' . $this->token;

        $tpl = $this->createTemplate('tarif_editor.tpl');
        $tpl->assign([
            'tarif' => [
                'id_tarif' => (int) $tarif->id,
                'id_customer' => (int) $tarif->id_customer,
                'name' => $tarif->name,
                'status' => (int) $tarif->status,
                'is_validated' => $tarif->isValidated(),
                'date_validated' => $tarif->date_validated,
            ],
            'sections' => $data['sections'] ?? [],
            'product_manufacturers' => $this->service->getProductManufacturers(),
            'customer' => $data['customer'] ?? null,
            'has_changes' => !empty($data['has_changes']),
            'show_public_price' => !empty($data['show_public_price']),
            'validation_targets' => $this->service->getValidationTargets((int) $tarif->id),
            'validation_history' => $this->service->getValidationHistory((int) $tarif->id),
            'ajax_url' => $ajaxUrl,
            'product_search_url' => $ajaxUrl . '&ajax=1&action=SearchProducts',
            'customer_search_url' => $ajaxUrl . '&ajax=1&action=SearchCustomers',
            'pdf_url' => $ajaxUrl . '&id_tarif=' . (int) $tarif->id . '&tarifpdf=1',
            'xlsx_url' => $ajaxUrl . '&id_tarif=' . (int) $tarif->id . '&tarifxlsx=1',
            'csv_url' => $ajaxUrl . '&id_tarif=' . (int) $tarif->id . '&tarifcsv=1',
            'form_action' => $ajaxUrl . '&id_tarif=' . (int) $tarif->id,
        ]);

        return $tpl->fetch();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // AJAX
    // ─────────────────────────────────────────────────────────────────────────

    public function ajaxProcessPrepareTarifValidation(): void
    {
        try {
            $this->checkValidationRequest();
            $prepared = $this->service->prepareValidation(
                (int) Tools::getValue('id_tarif'),
                (string) Tools::getValue('validation_target')
            );
            if ($prepared['id_target'] !== (int) Tools::getValue('id_target')) {
                throw new RuntimeException($this->l('La cible a changé. Rechargez le tarif avant de le valider.'));
            }
            unset($prepared['lines']);
            $this->ajaxRender(json_encode(array_merge(['success' => true], $prepared)));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessValidateTarif(): void
    {
        try {
            $this->checkValidationRequest();
            if ((string) Tools::getValue('confirmed') !== '1') {
                throw new RuntimeException($this->l('La seconde confirmation est requise.'));
            }
            $count = $this->service->validate(
                (int) Tools::getValue('id_tarif'),
                (string) Tools::getValue('validation_target'),
                (int) Tools::getValue('id_target'),
                (string) Tools::getValue('confirmation_token')
            );
            $this->ajaxRender(json_encode(['success' => true, 'count' => $count]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessSearchCustomers(): void
    {
        $this->ajaxRender(json_encode([
            'results' => $this->service->searchCustomers((string) Tools::getValue('term', '')),
        ]));
    }

    public function ajaxProcessSearchProducts(): void
    {
        try {
            $this->ajaxRender(json_encode([
                'results' => $this->service->searchProducts(
                    (string) Tools::getValue('term', ''),
                    (int) Tools::getValue('id_tarif'),
                    (int) Tools::getValue('id_manufacturer')
                ),
            ]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessBrowseProducts(): void
    {
        try {
            $result = $this->service->browseProducts(
                (int) Tools::getValue('id_tarif'),
                (string) Tools::getValue('term', ''),
                (int) Tools::getValue('id_manufacturer'),
                (int) Tools::getValue('page', 1),
                (bool) Tools::getValue('only_missing', false)
            );
            $this->ajaxRender(json_encode(array_merge(['success' => true], $result)));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessAddLine(): void
    {
        try {
            $idTarif = (int) Tools::getValue('id_tarif');
            $idSection = (int) Tools::getValue('id_section');
            $raw = (string) Tools::getValue('product');

            if (preg_match('/^(\d+):all$/', $raw, $m)) {
                $result = $this->service->addAllCombinations($idTarif, (int) $m[1], $idSection);
                $this->ajaxRender(json_encode([
                    'success' => true,
                    'lines' => $result['added'],
                    'skipped' => $result['skipped'],
                ]));

                return;
            }

            [$idProduct, $idProductAttribute] = $this->parseProductId($raw);
            $line = $this->service->addLine($idTarif, $idProduct, $idProductAttribute, $idSection);

            $this->ajaxRender(json_encode(['success' => true, 'lines' => [$line], 'skipped' => 0]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessUpdateLine(): void
    {
        try {
            $idTarif = (int) Tools::getValue('id_tarif');
            $idLine = (int) Tools::getValue('id_line');
            $mode = (string) Tools::getValue('mode', 'reduction');
            $value = $this->parseFloat((string) Tools::getValue('value'));
            $result = $this->service->updateLine($idTarif, $idLine, $mode, $value);
            $this->ajaxRender(json_encode(['success' => true, 'line' => $result]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessUpdatePublicPrice(): void
    {
        try {
            $raw = trim((string) Tools::getValue('value'));
            $result = $this->service->updatePublicPrice(
                (int) Tools::getValue('id_tarif'),
                (int) Tools::getValue('id_line'),
                (string) Tools::getValue('mode'),
                $raw === '' ? null : $this->parseFloat($raw)
            );
            $this->ajaxRender(json_encode(['success' => true, 'line' => $result]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessSetShowPublicPrice(): void
    {
        try {
            $value = (string) Tools::getValue('show_public_price');
            if (!in_array($value, ['0', '1'], true)) {
                throw new RuntimeException($this->l('Choix d’affichage invalide.'));
            }
            $show = $this->service->setShowPublicPrice((int) Tools::getValue('id_tarif'), $value === '1');
            $this->ajaxRender(json_encode(['success' => true, 'show_public_price' => $show]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessSetLineProcessed(): void
    {
        try {
            $value = (string) Tools::getValue('is_processed');
            if (!in_array($value, ['0', '1'], true)) {
                throw new RuntimeException($this->l('Statut de traitement invalide.'));
            }
            $line = $this->service->setLineProcessed(
                (int) Tools::getValue('id_tarif'),
                (int) Tools::getValue('id_line'),
                $value === '1'
            );
            $this->ajaxRender(json_encode(['success' => true, 'line' => $line]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessApplySectionReduction(): void
    {
        try {
            $idTarif = (int) Tools::getValue('id_tarif');
            $idSection = (int) Tools::getValue('id_section');
            $value = $this->parseFloat((string) Tools::getValue('value'));
            $lines = $this->service->applySectionReduction($idTarif, $idSection, $value, (string) Tools::getValue('mode', 'reduction'));
            $this->ajaxRender(json_encode(['success' => true, 'lines' => $lines]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessAddByReferences(): void
    {
        try {
            $idTarif = (int) Tools::getValue('id_tarif');
            $idSection = (int) Tools::getValue('id_section');
            $raw = (string) Tools::getValue('references');
            $references = preg_split('/\r\n|\r|\n/', $raw) ?: [];
            $result = $this->service->addLinesByReferences($idTarif, $references, $idSection);
            $this->ajaxRender(json_encode([
                'success' => true,
                'lines' => $result['added'],
                'not_found' => $result['not_found'],
                'duplicates' => $result['duplicates'],
                'id_section' => $idSection,
            ]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessDeleteLine(): void
    {
        try {
            $this->service->deleteLine((int) Tools::getValue('id_tarif'), (int) Tools::getValue('id_line'));
            $this->ajaxRender(json_encode(['success' => true]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessAddSection(): void
    {
        try {
            $section = $this->service->addSection(
                (int) Tools::getValue('id_tarif'),
                (string) Tools::getValue('title')
            );
            $this->ajaxRender(json_encode(['success' => true, 'section' => $section]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessUpdateSection(): void
    {
        try {
            $this->service->updateSectionTitle(
                (int) Tools::getValue('id_tarif'),
                (int) Tools::getValue('id_section'),
                (string) Tools::getValue('title')
            );
            $this->ajaxRender(json_encode(['success' => true]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessDeleteSection(): void
    {
        try {
            $this->service->deleteSection(
                (int) Tools::getValue('id_tarif'),
                (int) Tools::getValue('id_section')
            );
            $this->ajaxRender(json_encode(['success' => true]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessReorder(): void
    {
        try {
            $idTarif = (int) Tools::getValue('id_tarif');
            $sections = json_decode((string) Tools::getValue('sections'), true) ?: [];
            $sectionOrder = json_decode((string) Tools::getValue('section_order'), true) ?: [];

            $this->service->reorderLines($idTarif, $sections);
            if (!empty($sectionOrder)) {
                $this->service->reorderSections($idTarif, array_map('intval', $sectionOrder));
            }
            $this->ajaxRender(json_encode(['success' => true]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessRefreshPrices(): void
    {
        try {
            $this->service->refreshPrices((int) Tools::getValue('id_tarif'));
            $this->ajaxRender(json_encode(['success' => true]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    public function ajaxProcessSyncPrices(): void
    {
        try {
            $updated = $this->service->syncPrices((int) Tools::getValue('id_tarif'));
            $this->ajaxRender(json_encode(['success' => true, 'updated' => $updated]));
        } catch (Throwable $e) {
            $this->ajaxRender(json_encode(['success' => false, 'error' => $e->getMessage()]));
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{0:int,1:int} [id_product, id_product_attribute]
     */
    private function parseProductId(string $raw): array
    {
        $parts = explode(':', $raw);
        $idProduct = (int) ($parts[0] ?? 0);
        $idProductAttribute = (int) ($parts[1] ?? 0);
        if ($idProduct <= 0) {
            throw new RuntimeException($this->l('Produit invalide.'));
        }

        return [$idProduct, $idProductAttribute];
    }

    private function parseFloat(string $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if (!is_numeric($normalized) || !is_finite((float) $normalized)) {
            throw new RuntimeException($this->l('Saisissez une valeur numérique valide.'));
        }

        return (float) $normalized;
    }
}
