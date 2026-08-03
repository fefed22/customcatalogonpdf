<?php
/**
 * Contrôleur admin pour la gestion des profils de catalogue PDF.
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CustomCatalogProfile.php';

class AdminCustomCatalogOnPdfController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table       = 'customcatalogonpdf_profile';
        $this->className   = 'CustomCatalogProfile';
        $this->bootstrap   = true;
        $this->lang        = false;
        $this->identifier  = 'id_profile';
        $this->_defaultOrderBy = 'name';

        parent::__construct();

        $this->fields_list = [
            'id_profile' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'name' => [
                'title' => $this->l('Nom du profil'),
            ],
            'title' => [
                'title' => $this->l('Titre couverture'),
            ],
            'show_prices' => [
                'title'   => $this->l('Prix'),
                'type'    => 'bool',
                'align'   => 'center',
                'orderby' => false,
                'search'  => false,
            ],
            'date_upd' => [
                'title' => $this->l('Mise à jour'),
                'type'  => 'datetime',
            ],
        ];

        $this->addRowAction('edit');
        $this->addRowAction('generatepdf');
        $this->addRowAction('delete');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Génération PDF : interceptée avant tout rendu HTML
    // ─────────────────────────────────────────────────────────────────────────

    public function initProcess(): void
    {
        if (Tools::getValue('generatepdf') && ($id_profile = (int) Tools::getValue('id_profile'))) {
            $this->doGeneratePdf($id_profile);
            // doGeneratePdf() se termine par exit — on n'atteint pas cette ligne
        }
        parent::initProcess();
    }

    private function doGeneratePdf(int $id_profile): void
    {
        require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CatalogPdfGenerator.php';
        try {
            $generator = new CatalogPdfGenerator();
            $generator->generate($id_profile); // exit interne
        } catch (Throwable $e) {
            $this->errors[] = $this->l('Erreur lors de la génération PDF : ') . $e->getMessage();
            // On n'exit pas ici pour que l'erreur s'affiche dans la liste
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bouton action "Générer PDF" dans la liste
    // ─────────────────────────────────────────────────────────────────────────

    public function displayGeneratepdfLink(?string $token, int $id, ?string $name = null): string
    {
        $url = self::$currentIndex
            . '&token=' . $this->token
            . '&id_profile=' . $id
            . '&generatepdf=1';

        return '<a class="btn btn-default btn-xs" href="' . $url . '" title="' . $this->l('Générer le PDF') . '">'
            . '<i class="icon-file-pdf-o"></i>&nbsp;' . $this->l('PDF')
            . '</a>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Formulaire d'ajout / modification
    // ─────────────────────────────────────────────────────────────────────────

    public function renderForm(): string
    {
        $id_lang       = (int) $this->context->language->id;
        $manufacturers = $this->getManufacturersList();
        $groups        = $this->getGroupsList();
        $customers     = $this->getCustomersList();

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Profil de catalogue PDF'),
                'icon'  => 'icon-file-pdf-o',
            ],
            'input' => [
                [
                    'type'     => 'text',
                    'label'    => $this->l('Nom interne du profil'),
                    'name'     => 'name',
                    'required' => true,
                    'hint'     => $this->l('Utilisé uniquement en back-office pour retrouver le profil.'),
                ],
                [
                    'type'  => 'text',
                    'label' => $this->l('Titre visible sur la couverture'),
                    'name'  => 'title',
                    'hint'  => $this->l('Affiché en grand sur la page de couverture du PDF.'),
                ],
                [
                    'type'     => 'select',
                    'label'    => $this->l('Filtrer par marques'),
                    'name'     => 'id_manufacturers[]',
                    'id'       => 'id_manufacturers',
                    'multiple' => true,
                    'class'    => 'select2-manufacturers',
                    'options'  => [
                        'query'      => $manufacturers,
                        'id'         => 'id_manufacturer',
                        'name'       => 'name',
                        'default'    => ['value' => '', 'label' => $this->l('— Toutes les marques —')],
                    ],
                    'hint' => $this->l('Laissez vide pour inclure toutes les marques.'),
                ],
                [
                    'type'    => 'switch',
                    'label'   => $this->l('Afficher les prix'),
                    'name'    => 'show_prices',
                    'values'  => $this->getSwitchValues(),
                    'hint'    => $this->l('Active l\'affichage des prix (HT) dans le catalogue.'),
                ],
                [
                    'type'    => 'switch',
                    'label'   => $this->l('Tenir compte des remises groupe / client'),
                    'name'    => 'use_discounts',
                    'values'  => $this->getSwitchValues(),
                    'hint'    => $this->l('Si actif, applique les tarifs du groupe ou du client sélectionné.'),
                ],
                [
                    'type'    => 'select',
                    'label'   => $this->l('Groupe client (pour le prix)'),
                    'name'    => 'id_group',
                    'options' => [
                        'query'   => $groups,
                        'id'      => 'id_group',
                        'name'    => 'name',
                        'default' => ['value' => 0, 'label' => $this->l('— Aucun groupe —')],
                    ],
                    'hint' => $this->l('Utilisé si « Tenir compte des remises » est actif et qu\'aucun client n\'est sélectionné.'),
                ],
                [
                    'type'    => 'select',
                    'label'   => $this->l('Client spécifique (prix personnalisé)'),
                    'name'    => 'id_customer',
                    'id'      => 'id_customer',
                    'class'   => 'select2-customer',
                    'options' => [
                        'query'   => $customers,
                        'id'      => 'id_customer',
                        'name'    => 'fullname',
                        'default' => ['value' => 0, 'label' => $this->l('— Aucun client —')],
                    ],
                    'hint' => $this->l('Prioritaire sur le groupe. Utilise les tarifs spécifiques de ce client.'),
                ],
            ],
            'submit' => [
                'title' => $this->l('Enregistrer'),
            ],
        ];

        // Pré-remplissage des valeurs pour l'édition
        /** @var CustomCatalogProfile|null $obj */
        $obj = $this->loadObject(true);
        if ($obj && Validate::isLoadedObject($obj)) {
            $this->fields_value['id_manufacturers[]'] = $obj->getManufacturerIds();
        } else {
            $this->fields_value['id_manufacturers[]'] = [];
        }

        $html = parent::renderForm();
        $html .= $this->renderFormJs();

        return $html;
    }

    /**
     * Injecte le JS pour Select2 + affichage conditionnel des champs prix.
     */
    private function renderFormJs(): string
    {
        return '
<script>
(function($) {
    $(document).ready(function() {

        // ── Select2 sur les marques ──────────────────────────────────────────
        if ($.fn.select2) {
            $("#id_manufacturers").select2({
                width: "100%",
                placeholder: "' . $this->l('Toutes les marques') . '",
                allowClear: true
            });
            $("#id_customer").select2({
                width: "100%",
                placeholder: "' . $this->l('Aucun client') . '",
                allowClear: true
            });
        }

        // ── Affichage conditionnel des champs prix ───────────────────────────
        function togglePriceFields() {
            var showPrices = $("input[name=show_prices]:checked").val() == 1
                          || $("#show_prices_on").is(":checked");
            var $discountRow = $("input[name=use_discounts]").closest(".form-group");
            var $groupRow    = $("select[name=id_group]").closest(".form-group");
            var $custRow     = $("select[name=id_customer]").closest(".form-group");

            if (showPrices) {
                $discountRow.show();
                toggleDiscountFields();
            } else {
                $discountRow.hide();
                $groupRow.hide();
                $custRow.hide();
            }
        }

        function toggleDiscountFields() {
            var useDiscounts = $("input[name=use_discounts]:checked").val() == 1
                            || $("#use_discounts_on").is(":checked");
            var $groupRow = $("select[name=id_group]").closest(".form-group");
            var $custRow  = $("select[name=id_customer]").closest(".form-group");
            if (useDiscounts) {
                $groupRow.show();
                $custRow.show();
            } else {
                $groupRow.hide();
                $custRow.hide();
            }
        }

        $("input[name=show_prices]").on("change", togglePriceFields);
        $("input[name=use_discounts]").on("change", toggleDiscountFields);
        togglePriceFields();
    });
})(jQuery);
</script>';
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Traitement du formulaire (POST)
    // ─────────────────────────────────────────────────────────────────────────

    public function postProcess(): void
    {
        if (Tools::isSubmit('submitAdd' . $this->table) || Tools::isSubmit('submitAdd' . $this->table . 'AndStay')) {
            // Sérialiser le tableau de marques avant sauvegarde
            $raw = Tools::getValue('id_manufacturers');
            $ids = is_array($raw) ? array_map('intval', $raw) : [];
            // Remplacer par la valeur sérialisée que l'ObjectModel lira
            $_POST['id_manufacturers'] = json_encode($ids);
        }
        parent::postProcess();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Données pour les selects du formulaire
    // ─────────────────────────────────────────────────────────────────────────

    private function getManufacturersList(): array
    {
        return Manufacturer::getManufacturers(false, (int) $this->context->language->id, true) ?: [];
    }

    private function getGroupsList(): array
    {
        $groups = Group::getGroups((int) $this->context->language->id);
        return $groups ?: [];
    }

    private function getCustomersList(): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT c.id_customer,
                   CONCAT(
                       c.lastname,
                       " ",
                       c.firstname,
                       " - ",
                       c.email,
                       IF(
                           COALESCE(NULLIF(TRIM(c.company), ""), ca.company_name) <> "",
                           CONCAT(" - ", COALESCE(NULLIF(TRIM(c.company), ""), ca.company_name)),
                           ""
                       )
                   ) AS fullname
            FROM `' . _DB_PREFIX_ . 'customer` c
            LEFT JOIN (
                SELECT a.id_customer,
                       MAX(NULLIF(TRIM(a.company), "")) AS company_name
                FROM `' . _DB_PREFIX_ . 'address` a
                WHERE a.deleted = 0
                GROUP BY a.id_customer
            ) ca ON ca.id_customer = c.id_customer
            WHERE c.active = 1 AND c.deleted = 0
            ORDER BY c.lastname ASC, c.firstname ASC');

        return $rows ?: [];
    }

    private function getSwitchValues(): array
    {
        return [
            ['id' => 'on', 'value' => 1, 'label' => $this->l('Oui')],
            ['id' => 'off', 'value' => 0, 'label' => $this->l('Non')],
        ];
    }
}
