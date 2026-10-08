<?php
/**
 * Service métier des tarifs clients.
 *
 * Gère la recherche client/produit, le calcul des prix (base catalogue et prix
 * effectif client), la gestion des sections et lignes, la duplication, la
 * validation (création de prix spécifiques) et les exports Excel / CSV.
 *
 * @author Créa2média
 */

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CustomCatalogTarif.php';

class TarifService
{
    private Context $context;
    private Module $module;

    public function __construct(Module $module)
    {
        $this->context = Context::getContext();
        $this->module = $module;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Recherche (select2)
    // ─────────────────────────────────────────────────────────────────────────

    public function searchCustomers(string $term): array
    {
        $term = trim($term);
        if ($term !== '' && Tools::strlen($term) < 2) {
            return [];
        }

        $escaped = pSQL($term);
        $customers = Db::getInstance()->executeS('
            SELECT c.id_customer, c.firstname, c.lastname, c.email, c.company
            FROM `' . _DB_PREFIX_ . 'customer` c
            WHERE c.active = 1
              AND c.deleted = 0
              AND (
                  c.firstname LIKE "%' . $escaped . '%"
                  OR c.lastname LIKE "%' . $escaped . '%"
                  OR c.email LIKE "%' . $escaped . '%"
                  OR c.company LIKE "%' . $escaped . '%"
              )
            ORDER BY c.lastname, c.firstname
            LIMIT 50
        ') ?: [];

        return array_map(function (array $c): array {
            return [
                'id' => (int) $c['id_customer'],
                'text' => $this->formatCustomerText($c),
            ];
        }, $customers);
    }

    public function getCustomerOption(int $idCustomer): ?array
    {
        if ($idCustomer <= 0) {
            return null;
        }

        $c = Db::getInstance()->getRow('
            SELECT c.id_customer, c.firstname, c.lastname, c.email, c.company
            FROM `' . _DB_PREFIX_ . 'customer` c
            WHERE c.id_customer = ' . $idCustomer);

        if (!$c) {
            return null;
        }

        return [
            'id' => (int) $c['id_customer'],
            'text' => $this->formatCustomerText($c),
        ];
    }

    private function formatCustomerText(array $c): string
    {
        $company = trim((string) ($c['company'] ?? ''));

        return sprintf(
            '%s %s (%s)%s',
            $c['firstname'],
            $c['lastname'],
            $c['email'],
            $company !== '' ? ' - ' . $company : ''
        );
    }

    public function searchProducts(string $term): array
    {
        $term = trim($term);
        if ($term === '' || Tools::strlen($term) < 2) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $escaped = pSQL($term);

        $products = Db::getInstance()->executeS('
            SELECT p.id_product, pl.name, p.reference, p.ean13
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . $idLang . '
               AND pl.id_shop = ' . $idShop . '
            WHERE (
                  pl.name LIKE "%' . $escaped . '%"
                  OR p.reference LIKE "%' . $escaped . '%"
                  OR p.ean13 LIKE "%' . $escaped . '%"
              )
            ORDER BY pl.name
            LIMIT 30
        ') ?: [];

        $results = [];
        foreach ($products as $p) {
            $idProduct = (int) $p['id_product'];
            $combinations = $this->getCombinations($idProduct, $idLang);

            if (empty($combinations)) {
                $results[] = [
                    'id' => $idProduct . ':0',
                    'text' => $this->buildProductLabel($p['name'], '', $p['reference'], $p['ean13']),
                ];
                continue;
            }

            foreach ($combinations as $combination) {
                $results[] = [
                    'id' => $idProduct . ':' . (int) $combination['id_product_attribute'],
                    'text' => $this->buildProductLabel(
                        $p['name'],
                        $combination['attribute_names'],
                        $combination['reference'] ?: $p['reference'],
                        $combination['ean13'] ?: $p['ean13']
                    ),
                ];
            }
        }

        return $results;
    }

    private function buildProductLabel(string $name, string $attributes, ?string $reference, ?string $ean): string
    {
        $label = $name;
        if ($attributes !== '') {
            $label .= ' - ' . $attributes;
        }
        $meta = array_filter([
            $reference ? 'Réf ' . $reference : '',
            $ean ? 'EAN ' . $ean : '',
        ]);
        if (!empty($meta)) {
            $label .= ' [' . implode(' / ', $meta) . ']';
        }

        return $label;
    }

    private function getCombinations(int $idProduct, int $idLang): array
    {
        return Db::getInstance()->executeS('
            SELECT pa.id_product_attribute, pa.reference, pa.ean13,
                   GROUP_CONCAT(
                       DISTINCT CONCAT(agl.name, " : ", al.name)
                       ORDER BY agl.name, al.name SEPARATOR " / "
                   ) AS attribute_names
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                ON pac.id_product_attribute = pa.id_product_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
                ON a.id_attribute = pac.id_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $idLang . '
            WHERE pa.id_product = ' . $idProduct . '
            GROUP BY pa.id_product_attribute
            ORDER BY pa.id_product_attribute
        ') ?: [];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Calcul des prix
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Prix de base catalogue HT (sans aucune remise).
     */
    public function getBasePrice(int $idProduct, int $idProductAttribute): float
    {
        $sp = null;

        return (float) Product::getPriceStatic(
            $idProduct,
            false,
            $idProductAttribute ?: null,
            6,
            null,
            false,
            false,
            1,
            false,
            null,
            null,
            null,
            $sp,
            true,
            false,
            null,
            false
        );
    }

    /**
     * Prix effectif actuel HT du client (prix spécifiques / remises groupe inclus).
     */
    public function getCurrentPrice(int $idProduct, int $idProductAttribute, int $idCustomer): float
    {
        $sp = null;

        return (float) Product::getPriceStatic(
            $idProduct,
            false,
            $idProductAttribute ?: null,
            6,
            null,
            false,
            true,
            1,
            false,
            $idCustomer ?: null,
            null,
            null,
            $sp,
            true,
            true,
            null,
            true
        );
    }

    /**
     * Prix HT appliqué à un groupe client (remise groupe uniquement, sans client).
     */
    public function getGroupPrice(int $idProduct, int $idProductAttribute, int $idGroup): float
    {
        if ($idGroup <= 0) {
            return $this->getBasePrice($idProduct, $idProductAttribute);
        }

        $idCurrency = Validate::isLoadedObject($this->context->currency)
            ? (int) $this->context->currency->id
            : (int) Currency::getDefaultCurrencyId();
        $idCountry = Validate::isLoadedObject($this->context->country)
            ? (int) $this->context->country->id
            : (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $sp = null;

        return (float) Product::priceCalculation(
            (int) $this->context->shop->id,
            $idProduct,
            $idProductAttribute ?: null,
            $idCountry,
            0,
            '',
            $idCurrency,
            $idGroup,
            1,
            false,
            6,
            false,
            true,
            true,
            $sp,
            true,
            0,
            false,
            0,
            0,
            0
        );
    }

    /**
     * Groupe par défaut d'un client (0 si aucun).
     */
    public function getCustomerDefaultGroup(int $idCustomer): int
    {
        if ($idCustomer <= 0) {
            return 0;
        }

        return (int) Db::getInstance()->getValue(
            'SELECT id_default_group FROM `' . _DB_PREFIX_ . 'customer` WHERE id_customer = ' . (int) $idCustomer
        );
    }

    /**
     * Indique si le client possède un prix spécifique qui lui est propre.
     */
    public function hasCustomerSpecificPrice(int $idProduct, int $idProductAttribute, int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }

        return (bool) Db::getInstance()->getValue('
            SELECT 1 FROM `' . _DB_PREFIX_ . 'specific_price`
            WHERE id_customer = ' . (int) $idCustomer . '
              AND id_product = ' . (int) $idProduct . '
              AND (id_product_attribute = ' . (int) $idProductAttribute . ' OR id_product_attribute = 0)');
    }

    /**
     * Calcule les prix d'une ligne pour un client : catalogue, remise groupe,
     * prix actuel effectif.
     *
     * @return array{catalog:float,group_reduction:float,current:float,id_group:int}
     */
    private function computeLinePrices(int $idProduct, int $idProductAttribute, int $idCustomer): array
    {
        $catalog = $this->getBasePrice($idProduct, $idProductAttribute);
        $idGroup = $this->getCustomerDefaultGroup($idCustomer);
        $groupPrice = $this->getGroupPrice($idProduct, $idProductAttribute, $idGroup);
        $current = $this->getCurrentPrice($idProduct, $idProductAttribute, $idCustomer);

        $groupReduction = $catalog > 0 ? round((($catalog - $groupPrice) / $catalog) * 100, 2) : 0.0;

        return [
            'catalog' => $catalog,
            'group_reduction' => $groupReduction,
            'current' => $current,
            'id_group' => $idGroup,
        ];
    }

    /**
     * Prix final HT à partir du prix catalogue et d'une réduction client (%).
     */
    private function computeFinalFromReduction(float $catalogPrice, float $reductionPercent): float
    {
        return round($catalogPrice * (1 - ($reductionPercent / 100)), 2);
    }

    /**
     * Réduction client (%) à partir du prix catalogue et d'un prix final.
     */
    private function computeReductionFromFinal(float $catalogPrice, float $finalPrice): float
    {
        if ($catalogPrice <= 0) {
            return 0.0;
        }

        return round((($catalogPrice - $finalPrice) / $catalogPrice) * 100, 4);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Informations produit (affichage des lignes)
    // ─────────────────────────────────────────────────────────────────────────

    public function getProductInfo(int $idProduct, int $idProductAttribute): ?array
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;

        $product = Db::getInstance()->getRow('
            SELECT p.id_product, pl.name, pl.link_rewrite, p.reference, p.ean13
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . $idLang . '
               AND pl.id_shop = ' . $idShop . '
            WHERE p.id_product = ' . (int) $idProduct);

        if (!$product) {
            return null;
        }

        $reference = (string) $product['reference'];
        $ean13 = (string) $product['ean13'];
        $attributeNames = '';
        $idImage = 0;

        if ($idProductAttribute > 0) {
            $combination = Db::getInstance()->getRow('
                SELECT pa.reference, pa.ean13,
                       GROUP_CONCAT(
                           DISTINCT CONCAT(agl.name, " : ", al.name)
                           ORDER BY agl.name, al.name SEPARATOR " / "
                       ) AS attribute_names
                FROM `' . _DB_PREFIX_ . 'product_attribute` pa
                LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                    ON pac.id_product_attribute = pa.id_product_attribute
                LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
                    ON a.id_attribute = pac.id_attribute
                LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` al
                    ON al.id_attribute = a.id_attribute AND al.id_lang = ' . $idLang . '
                LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl
                    ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ' . $idLang . '
                WHERE pa.id_product_attribute = ' . (int) $idProductAttribute . '
                GROUP BY pa.id_product_attribute');

            if ($combination) {
                $attributeNames = (string) $combination['attribute_names'];
                if (!empty($combination['reference'])) {
                    $reference = (string) $combination['reference'];
                }
                if (!empty($combination['ean13'])) {
                    $ean13 = (string) $combination['ean13'];
                }
            }

            $idImage = (int) Db::getInstance()->getValue('
                SELECT pai.id_image
                FROM `' . _DB_PREFIX_ . 'product_attribute_image` pai
                INNER JOIN `' . _DB_PREFIX_ . 'image` i ON i.id_image = pai.id_image
                WHERE pai.id_product_attribute = ' . (int) $idProductAttribute . '
                ORDER BY i.position ASC');
        }

        if ($idImage <= 0) {
            $idImage = (int) Db::getInstance()->getValue('
                SELECT id_image
                FROM `' . _DB_PREFIX_ . 'image`
                WHERE id_product = ' . (int) $idProduct . ' AND cover = 1');
        }

        return [
            'name' => (string) $product['name'],
            'attribute_names' => $attributeNames,
            'reference' => $reference,
            'ean13' => $ean13,
            'id_image' => $idImage,
            'image_url' => $this->getImageUrl($idImage, (string) $product['link_rewrite']),
        ];
    }

    private function getImageUrl(int $idImage, string $linkRewrite): string
    {
        if ($idImage <= 0) {
            return '';
        }

        return $this->context->link->getImageLink($linkRewrite ?: 'product', (string) $idImage, 'small_default');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lecture d'un tarif complet (éditeur / exports)
    // ─────────────────────────────────────────────────────────────────────────

    public function getTarifData(int $idTarif, bool $detectChanges = false): ?array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            return null;
        }

        $sections = Db::getInstance()->executeS('
            SELECT id_section, title, position
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_section`
            WHERE id_tarif = ' . (int) $idTarif . '
            ORDER BY position ASC, id_section ASC') ?: [];

        $lines = Db::getInstance()->executeS('
            SELECT *
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif . '
            ORDER BY position ASC, id_line ASC') ?: [];

        $linesBySection = [];
        $hasChanges = false;
        foreach ($lines as $line) {
            $decorated = $this->decorateLine($line, (int) $tarif->id_customer, $detectChanges);
            if (!empty($decorated['has_changes'])) {
                $hasChanges = true;
            }
            $linesBySection[(int) $line['id_section']][] = $decorated;
        }

        $sectionList = [];
        foreach ($sections as $section) {
            $idSection = (int) $section['id_section'];
            $sectionList[] = [
                'id_section' => $idSection,
                'title' => (string) $section['title'],
                'position' => (int) $section['position'],
                'lines' => $linesBySection[$idSection] ?? [],
            ];
        }

        // Section virtuelle « sans section » (id_section = 0)
        $sectionList[] = [
            'id_section' => 0,
            'title' => '',
            'position' => 9999,
            'lines' => $linesBySection[0] ?? [],
        ];

        return [
            'tarif' => $tarif,
            'customer' => $this->getCustomerOption((int) $tarif->id_customer),
            'sections' => $sectionList,
            'has_changes' => $hasChanges,
        ];
    }

    /**
     * Construit la représentation complète d'une ligne (infos produit + prix +
     * indicateurs de remise) à partir d'un enregistrement stocké.
     *
     * Si $detectChanges est vrai, compare le prix catalogue et la remise de
     * groupe stockés avec les valeurs actuelles et signale tout écart.
     */
    private function decorateLine(array $line, int $idCustomer, bool $detectChanges = false): array
    {
        $idProduct = (int) $line['id_product'];
        $idProductAttribute = (int) $line['id_product_attribute'];
        $info = $this->getProductInfo($idProduct, $idProductAttribute);

        $storedBase = (float) $line['base_price'];
        $storedGroup = (float) $line['group_reduction_percent'];
        $liveBase = $storedBase;
        $liveGroup = $storedGroup;
        $hasChanges = false;

        if ($detectChanges) {
            $prices = $this->computeLinePrices($idProduct, $idProductAttribute, $idCustomer);
            $liveBase = $prices['catalog'];
            $liveGroup = $prices['group_reduction'];
            $hasChanges = abs($liveBase - $storedBase) >= 0.005
                || abs($liveGroup - $storedGroup) >= 0.005;
        }

        return [
            'id_line' => (int) $line['id_line'],
            'id_section' => (int) $line['id_section'],
            'id_product' => $idProduct,
            'id_product_attribute' => $idProductAttribute,
            'name' => $info['name'] ?? ('#' . $idProduct),
            'attribute_names' => $info['attribute_names'] ?? '',
            'reference' => $info['reference'] ?? '',
            'ean13' => $info['ean13'] ?? '',
            'image_url' => $info['image_url'] ?? '',
            'id_image' => $info['id_image'] ?? 0,
            'base_price' => $storedBase,
            'group_reduction_percent' => $storedGroup,
            'current_price' => (float) $line['current_price'],
            'reduction_percent' => (float) $line['reduction_percent'],
            'final_price' => (float) $line['final_price'],
            'has_group_rule' => $storedGroup > 0,
            'has_customer_rule' => $this->hasCustomerSpecificPrice($idProduct, $idProductAttribute, $idCustomer),
            'live_base_price' => $liveBase,
            'live_group_reduction_percent' => $liveGroup,
            'has_changes' => $hasChanges,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gestion des lignes
    // ─────────────────────────────────────────────────────────────────────────

    public function addLine(int $idTarif, int $idProduct, int $idProductAttribute, int $idSection): array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $prices = $this->computeLinePrices($idProduct, $idProductAttribute, (int) $tarif->id_customer);
        $catalog = $prices['catalog'];
        $current = $prices['current'];
        // Réduction client par défaut : celle qui reproduit le prix actuel du client
        $reductionPercent = $catalog > 0 ? $this->computeReductionFromFinal($catalog, $current) : 0.0;
        $finalPrice = $current;

        $position = (int) Db::getInstance()->getValue('
            SELECT IFNULL(MAX(position), -1) + 1
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif . ' AND id_section = ' . (int) $idSection);

        Db::getInstance()->insert('customcatalogonpdf_tarif_line', [
            'id_tarif' => (int) $idTarif,
            'id_section' => (int) $idSection,
            'id_product' => (int) $idProduct,
            'id_product_attribute' => (int) $idProductAttribute,
            'position' => $position,
            'base_price' => $catalog,
            'group_reduction_percent' => $prices['group_reduction'],
            'current_price' => $current,
            'reduction_percent' => $reductionPercent,
            'final_price' => $finalPrice,
        ]);

        $idLine = (int) Db::getInstance()->Insert_ID();
        $this->touch($tarif);

        $row = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_line = ' . $idLine);

        return $this->decorateLine($row, (int) $tarif->id_customer);
    }

    /**
     * Met à jour une ligne par saisie de la réduction (%) ou du prix final.
     *
     * @param string $mode 'reduction' ou 'price'
     */
    public function updateLine(int $idTarif, int $idLine, string $mode, float $value): array
    {
        $line = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_line = ' . (int) $idLine . ' AND id_tarif = ' . (int) $idTarif);

        if (!$line) {
            throw new RuntimeException($this->module->l('Ligne introuvable.'));
        }

        $catalog = (float) $line['base_price'];

        if ($mode === 'price') {
            $finalPrice = round($value, 2);
            $reductionPercent = $this->computeReductionFromFinal($catalog, $finalPrice);
        } else {
            $reductionPercent = round($value, 4);
            $finalPrice = $this->computeFinalFromReduction($catalog, $reductionPercent);
        }

        Db::getInstance()->update('customcatalogonpdf_tarif_line', [
            'reduction_percent' => $reductionPercent,
            'final_price' => $finalPrice,
        ], 'id_line = ' . (int) $idLine);

        $this->touchById($idTarif);

        return [
            'id_line' => (int) $idLine,
            'reduction_percent' => $reductionPercent,
            'final_price' => $finalPrice,
        ];
    }

    public function deleteLine(int $idTarif, int $idLine): void
    {
        Db::getInstance()->delete(
            'customcatalogonpdf_tarif_line',
            'id_line = ' . (int) $idLine . ' AND id_tarif = ' . (int) $idTarif
        );
        $this->touchById($idTarif);
    }

    /**
     * Réordonne / réaffecte les lignes à partir d'une structure envoyée par le JS.
     *
     * @param array $sections [ ['id_section' => int, 'lines' => [id_line, ...]], ... ]
     */
    public function reorderLines(int $idTarif, array $sections): void
    {
        foreach ($sections as $section) {
            $idSection = (int) ($section['id_section'] ?? 0);
            $lineIds = $section['lines'] ?? [];
            $position = 0;
            foreach ($lineIds as $idLine) {
                Db::getInstance()->update('customcatalogonpdf_tarif_line', [
                    'id_section' => $idSection,
                    'position' => $position,
                ], 'id_line = ' . (int) $idLine . ' AND id_tarif = ' . (int) $idTarif);
                $position++;
            }
        }
        $this->touchById($idTarif);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Gestion des sections
    // ─────────────────────────────────────────────────────────────────────────

    public function addSection(int $idTarif, string $title): array
    {
        $position = (int) Db::getInstance()->getValue('
            SELECT IFNULL(MAX(position), -1) + 1
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_section`
            WHERE id_tarif = ' . (int) $idTarif);

        Db::getInstance()->insert('customcatalogonpdf_tarif_section', [
            'id_tarif' => (int) $idTarif,
            'title' => pSQL($title),
            'position' => $position,
        ]);
        $idSection = (int) Db::getInstance()->Insert_ID();
        $this->touchById($idTarif);

        return [
            'id_section' => $idSection,
            'title' => $title,
            'position' => $position,
            'lines' => [],
        ];
    }

    public function updateSectionTitle(int $idTarif, int $idSection, string $title): void
    {
        Db::getInstance()->update(
            'customcatalogonpdf_tarif_section',
            ['title' => pSQL($title)],
            'id_section = ' . (int) $idSection . ' AND id_tarif = ' . (int) $idTarif
        );
        $this->touchById($idTarif);
    }

    public function deleteSection(int $idTarif, int $idSection): void
    {
        // Les lignes de la section reviennent à « sans section »
        Db::getInstance()->update(
            'customcatalogonpdf_tarif_line',
            ['id_section' => 0],
            'id_section = ' . (int) $idSection . ' AND id_tarif = ' . (int) $idTarif
        );
        Db::getInstance()->delete(
            'customcatalogonpdf_tarif_section',
            'id_section = ' . (int) $idSection . ' AND id_tarif = ' . (int) $idTarif
        );
        $this->touchById($idTarif);
    }

    public function reorderSections(int $idTarif, array $sectionIds): void
    {
        $position = 0;
        foreach ($sectionIds as $idSection) {
            Db::getInstance()->update('customcatalogonpdf_tarif_section', [
                'position' => $position,
            ], 'id_section = ' . (int) $idSection . ' AND id_tarif = ' . (int) $idTarif);
            $position++;
        }
        $this->touchById($idTarif);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Rafraîchissement des prix
    // ─────────────────────────────────────────────────────────────────────────

    public function refreshPrices(int $idTarif): void
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $lines = Db::getInstance()->executeS('
            SELECT id_line, id_product, id_product_attribute, reduction_percent
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif) ?: [];

        foreach ($lines as $line) {
            $prices = $this->computeLinePrices(
                (int) $line['id_product'],
                (int) $line['id_product_attribute'],
                (int) $tarif->id_customer
            );
            // On conserve la réduction client saisie et on recalcule le prix final
            $reductionPercent = (float) $line['reduction_percent'];
            $finalPrice = $this->computeFinalFromReduction($prices['catalog'], $reductionPercent);

            Db::getInstance()->update('customcatalogonpdf_tarif_line', [
                'base_price' => $prices['catalog'],
                'group_reduction_percent' => $prices['group_reduction'],
                'current_price' => $prices['current'],
                'final_price' => $finalPrice,
            ], 'id_line = ' . (int) $line['id_line']);
        }

        $this->touch($tarif);
    }

    /**
     * Synchronise les lignes dont le prix catalogue ou la remise de groupe ont
     * changé, en CONSERVANT le prix final : la réduction client est recalculée
     * et, si le tarif est validé, le prix spécifique propre au client est adapté
     * pour que le client paie toujours le même prix final.
     *
     * @return int Nombre de lignes mises à jour
     */
    public function syncPrices(int $idTarif): int
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $idCustomer = (int) $tarif->id_customer;
        $adaptSpecificPrices = $tarif->isValidated() && $idCustomer > 0;

        $lines = Db::getInstance()->executeS('
            SELECT *
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif) ?: [];

        $updated = 0;
        foreach ($lines as $line) {
            $idProduct = (int) $line['id_product'];
            $idProductAttribute = (int) $line['id_product_attribute'];
            $prices = $this->computeLinePrices($idProduct, $idProductAttribute, $idCustomer);

            $changed = abs($prices['catalog'] - (float) $line['base_price']) >= 0.005
                || abs($prices['group_reduction'] - (float) $line['group_reduction_percent']) >= 0.005;
            if (!$changed) {
                continue;
            }

            $finalPrice = (float) $line['final_price'];
            $newCatalog = $prices['catalog'];
            // On conserve le prix final : la réduction client est recalculée
            $reductionPercent = $this->computeReductionFromFinal($newCatalog, $finalPrice);

            Db::getInstance()->update('customcatalogonpdf_tarif_line', [
                'base_price' => $newCatalog,
                'group_reduction_percent' => $prices['group_reduction'],
                'current_price' => $prices['current'],
                'reduction_percent' => $reductionPercent,
            ], 'id_line = ' . (int) $line['id_line']);

            // Adapter le prix spécifique client existant pour préserver le prix final
            if ($adaptSpecificPrices && $this->hasCustomerSpecificPrice($idProduct, $idProductAttribute, $idCustomer)) {
                $this->applyCustomerSpecificPrice($idProduct, $idProductAttribute, $idCustomer, $newCatalog, $finalPrice);
            }

            $updated++;
        }

        if ($updated > 0) {
            if (method_exists('SpecificPrice', 'flushCache')) {
                SpecificPrice::flushCache();
            }
            Product::flushPriceCache();
        }

        $this->touch($tarif);

        return $updated;
    }

    /**
     * Écrase le prix spécifique propre au client pour atteindre le prix final
     * voulu (pourcentage par rapport au prix catalogue, arrondi à 2 décimales).
     * Les remises de groupe ne sont jamais touchées.
     */
    private function applyCustomerSpecificPrice(
        int $idProduct,
        int $idProductAttribute,
        int $idCustomer,
        float $catalogPrice,
        float $finalPrice
    ): void {
        Db::getInstance()->delete('specific_price',
            'id_product = ' . $idProduct
            . ' AND id_product_attribute = ' . $idProductAttribute
            . ' AND id_customer = ' . $idCustomer
            . ' AND id_group = 0'
            . ' AND id_specific_price_rule = 0'
        );

        if ($catalogPrice <= 0) {
            return;
        }

        $reductionPercent = round((($catalogPrice - $finalPrice) / $catalogPrice) * 100, 2);

        Db::getInstance()->insert('specific_price', [
            'id_specific_price_rule' => 0,
            'id_cart' => 0,
            'id_product' => $idProduct,
            'id_shop' => 0,
            'id_shop_group' => 0,
            'id_currency' => 0,
            'id_country' => 0,
            'id_group' => 0,
            'id_customer' => $idCustomer,
            'id_product_attribute' => $idProductAttribute,
            'price' => -1,
            'from_quantity' => 1,
            'reduction' => $reductionPercent / 100,
            'reduction_tax' => 0,
            'reduction_type' => 'percentage',
            'from' => '0000-00-00 00:00:00',
            'to' => '0000-00-00 00:00:00',
        ]);
    }

    public function duplicate(int $idTarif, int $idCustomer): int
    {
        $source = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($source)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }
        if (!$this->isActiveCustomer($idCustomer)) {
            throw new RuntimeException($this->module->l('Client cible invalide.'));
        }

        $copy = new CustomCatalogTarif();
        $copy->id_customer = $idCustomer;
        $copy->name = $source->name . ' ' . $this->module->l('(copie)');
        $copy->logo = $source->logo;
        $copy->status = CustomCatalogTarif::STATUS_DRAFT;
        $copy->date_validated = null;
        $copy->add();

        $newIdTarif = (int) $copy->id;

        // Copier les sections en conservant la correspondance d'identifiants
        $sectionMap = [0 => 0];
        $sections = Db::getInstance()->executeS('
            SELECT id_section, title, position
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_section`
            WHERE id_tarif = ' . (int) $idTarif) ?: [];

        foreach ($sections as $section) {
            Db::getInstance()->insert('customcatalogonpdf_tarif_section', [
                'id_tarif' => $newIdTarif,
                'title' => pSQL($section['title']),
                'position' => (int) $section['position'],
            ]);
            $sectionMap[(int) $section['id_section']] = (int) Db::getInstance()->Insert_ID();
        }

        // Copier les lignes en recalculant le prix actuel pour le nouveau client
        $lines = Db::getInstance()->executeS('
            SELECT *
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif) ?: [];

        foreach ($lines as $line) {
            $idProduct = (int) $line['id_product'];
            $idProductAttribute = (int) $line['id_product_attribute'];
            $reduction = (float) $line['reduction_percent'];
            $prices = $this->computeLinePrices($idProduct, $idProductAttribute, $idCustomer);

            Db::getInstance()->insert('customcatalogonpdf_tarif_line', [
                'id_tarif' => $newIdTarif,
                'id_section' => $sectionMap[(int) $line['id_section']] ?? 0,
                'id_product' => $idProduct,
                'id_product_attribute' => $idProductAttribute,
                'position' => (int) $line['position'],
                'base_price' => $prices['catalog'],
                'group_reduction_percent' => $prices['group_reduction'],
                'current_price' => $prices['current'],
                'reduction_percent' => $reduction,
                'final_price' => $this->computeFinalFromReduction($prices['catalog'], $reduction),
            ]);
        }

        return $newIdTarif;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation → prix spécifiques
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Verrouille les prix du tarif sous forme de prix spécifiques client.
     *
     * Pour chaque ligne, calcule le pourcentage de réduction (par rapport au
     * prix de base catalogue) permettant d'atteindre le prix final, arrondi à
     * 2 décimales, puis écrase toute règle propre au client sur ce produit.
     * Les remises de groupe ne sont jamais modifiées ; si le prix final
     * correspond au prix de groupe du client, aucune règle client n'est créée
     * (le client conserve simplement sa remise de groupe).
     *
     * @return int Nombre de prix spécifiques créés
     */
    public function validate(int $idTarif): int
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }
        $idCustomer = (int) $tarif->id_customer;
        if ($idCustomer <= 0) {
            throw new RuntimeException($this->module->l('Sélectionnez un client avant de valider le tarif.'));
        }

        $idGroup = $this->getCustomerDefaultGroup($idCustomer);

        $lines = Db::getInstance()->executeS('
            SELECT id_product, id_product_attribute, final_price
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif) ?: [];

        $count = 0;
        foreach ($lines as $line) {
            $idProduct = (int) $line['id_product'];
            $idProductAttribute = (int) $line['id_product_attribute'];
            $finalPrice = (float) $line['final_price'];
            $basePrice = $this->getBasePrice($idProduct, $idProductAttribute);
            $groupPrice = $this->getGroupPrice($idProduct, $idProductAttribute, $idGroup);

            // Écraser toute règle propre au client (jamais les remises de groupe)
            Db::getInstance()->delete('specific_price',
                'id_product = ' . $idProduct
                . ' AND id_product_attribute = ' . $idProductAttribute
                . ' AND id_customer = ' . $idCustomer
                . ' AND id_group = 0'
                . ' AND id_specific_price_rule = 0'
            );

            if ($basePrice <= 0) {
                continue;
            }

            // Pas de dérogation : le prix final correspond au prix de groupe
            if (abs($finalPrice - $groupPrice) < 0.005) {
                continue;
            }

            // Pourcentage de réduction par rapport au prix de base (arrondi 2 décimales)
            $reductionPercent = round((($basePrice - $finalPrice) / $basePrice) * 100, 2);

            Db::getInstance()->insert('specific_price', [
                'id_specific_price_rule' => 0,
                'id_cart' => 0,
                'id_product' => $idProduct,
                'id_shop' => 0,
                'id_shop_group' => 0,
                'id_currency' => 0,
                'id_country' => 0,
                'id_group' => 0,
                'id_customer' => $idCustomer,
                'id_product_attribute' => $idProductAttribute,
                'price' => -1,
                'from_quantity' => 1,
                'reduction' => $reductionPercent / 100,
                'reduction_tax' => 0,
                'reduction_type' => 'percentage',
                'from' => '0000-00-00 00:00:00',
                'to' => '0000-00-00 00:00:00',
            ]);
            $count++;
        }

        if (method_exists('SpecificPrice', 'flushCache')) {
            SpecificPrice::flushCache();
        }
        Product::flushPriceCache();

        $tarif->status = CustomCatalogTarif::STATUS_VALIDATED;
        $tarif->date_validated = date('Y-m-d H:i:s');
        $tarif->update();

        return $count;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Exports Excel / CSV
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Construit le jeu de données export (infos produit + prix final uniquement).
     */
    public function buildExportDataset(int $idTarif): array
    {
        $data = $this->getTarifData($idTarif);
        if ($data === null) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $headers = [
            ['key' => 'section', 'label' => $this->module->l('Section'), 'numeric' => false],
            ['key' => 'product', 'label' => $this->module->l('Produit'), 'numeric' => false],
            ['key' => 'reference', 'label' => $this->module->l('Référence'), 'numeric' => false],
            ['key' => 'ean13', 'label' => $this->module->l('EAN'), 'numeric' => false],
            ['key' => 'final_price', 'label' => $this->module->l('Prix HT'), 'numeric' => true],
        ];

        $rows = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['lines'] as $line) {
                $rows[] = [
                    'section' => (string) $section['title'],
                    'product' => trim($line['name'] . ($line['attribute_names'] ? ' - ' . $line['attribute_names'] : '')),
                    'reference' => (string) $line['reference'],
                    'ean13' => (string) $line['ean13'],
                    'final_price' => (float) $line['final_price'],
                ];
            }
        }

        return [
            'tarif' => $data['tarif'],
            'headers' => $headers,
            'rows' => $rows,
        ];
    }

    public function export(int $idTarif, string $format): void
    {
        $dataset = $this->buildExportDataset($idTarif);
        if ($format === 'csv') {
            $this->streamCsv($dataset);
            return;
        }
        if ($format === 'xlsx') {
            $this->streamXlsx($dataset);
            return;
        }
        throw new InvalidArgumentException($this->module->l('Format d’export non pris en charge.'));
    }

    private function streamCsv(array $dataset): void
    {
        $this->clearOutputBuffers();

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $this->buildFilename($dataset['tarif'], 'csv') . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array_column($dataset['headers'], 'label'), ';');

        foreach ($dataset['rows'] as $row) {
            $values = [];
            foreach ($dataset['headers'] as $header) {
                $value = $row[$header['key']] ?? '';
                $values[] = $header['numeric'] && $value !== null
                    ? number_format((float) $value, 2, ',', '')
                    : $this->escapeSpreadsheetFormula((string) $value);
            }
            fputcsv($output, $values, ';');
        }
        fclose($output);
        exit;
    }

    private function streamXlsx(array $dataset): void
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new RuntimeException($this->module->l('La bibliothèque PhpSpreadsheet est indisponible.'));
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->module->l('Tarif'));

        foreach ($dataset['headers'] as $columnIndex => $header) {
            $column = $columnIndex + 1;
            $sheet->setCellValueExplicitByColumnAndRow($column, 1, $header['label'], DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($column)->setAutoSize(true);
        }

        foreach ($dataset['rows'] as $rowIndex => $row) {
            foreach ($dataset['headers'] as $columnIndex => $header) {
                $value = $row[$header['key']] ?? null;
                $column = $columnIndex + 1;
                $excelRow = $rowIndex + 2;
                if ($header['numeric'] && $value !== null) {
                    $sheet->setCellValueByColumnAndRow($column, $excelRow, (float) $value);
                    $sheet->getStyleByColumnAndRow($column, $excelRow)
                        ->getNumberFormat()->setFormatCode('#,##0.00');
                } else {
                    $sheet->setCellValueExplicitByColumnAndRow($column, $excelRow, (string) $value, DataType::TYPE_STRING);
                }
            }
        }

        $lastColumn = count($dataset['headers']);
        if ($lastColumn > 0) {
            $sheet->getStyleByColumnAndRow(1, 1, $lastColumn, 1)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(1, 1, $lastColumn, 1)
                ->getFill()->setFillType(Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFD9EAF7');
            $sheet->setAutoFilterByColumnAndRow(1, 1, $lastColumn, count($dataset['rows']) + 1);
            $sheet->freezePane('A2');
        }

        $this->clearOutputBuffers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $this->buildFilename($dataset['tarif'], 'xlsx') . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    private function escapeSpreadsheetFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function buildFilename(CustomCatalogTarif $tarif, string $extension): string
    {
        $slug = Tools::link_rewrite($tarif->name) ?: ('tarif_' . (int) $tarif->id);

        return sprintf('tarif_%s_%s.%s', $slug, date('Ymd'), $extension);
    }

    private function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isActiveCustomer(int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }

        return (bool) Db::getInstance()->getValue('
            SELECT 1 FROM `' . _DB_PREFIX_ . 'customer`
            WHERE id_customer = ' . (int) $idCustomer . ' AND deleted = 0');
    }

    private function touch(CustomCatalogTarif $tarif): void
    {
        $tarif->date_upd = date('Y-m-d H:i:s');
        $tarif->update();
    }

    private function touchById(int $idTarif): void
    {
        Db::getInstance()->update(
            'customcatalogonpdf_tarif',
            ['date_upd' => date('Y-m-d H:i:s')],
            'id_tarif = ' . (int) $idTarif
        );
    }
}
