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

    public function searchProducts(string $term, int $idTarif = 0, int $idManufacturer = 0): array
    {
        $term = trim($term);
        if ($term === '' || Tools::strlen($term) < 2) {
            return [];
        }

        $idLang = (int) $this->context->language->id;
        $products = $this->findProducts($term, $idManufacturer, 0, 30, $idTarif);
        $existing = $this->getExistingProductKeys($idTarif);

        $results = [];
        foreach ($products as $p) {
            $idProduct = (int) $p['id_product'];
            $name = $p['name'] . (!(bool) $p['active'] ? ' [' . $this->module->l('Inactif') . ']' : '');
            $combinations = $this->getCombinations($idProduct, $idLang);

            if (empty($combinations)) {
                if (isset($existing[$idProduct . ':0'])) {
                    continue;
                }
                $results[] = [
                    'id' => $idProduct . ':0',
                    'text' => $this->buildProductLabel($name, '', $p['reference'], $p['ean13']),
                ];
                continue;
            }

            $combinations = array_values(array_filter($combinations, function (array $combination) use ($existing, $idProduct): bool {
                return !isset($existing[$idProduct . ':' . (int) $combination['id_product_attribute']]);
            }));
            if (!$combinations) {
                continue;
            }

            $results[] = [
                'id' => $idProduct . ':all',
                'text' => $name . ' — ' . sprintf(
                    $this->module->l('Déclinaisons restantes (%d)'),
                    count($combinations)
                ),
            ];

            foreach ($combinations as $combination) {
                $results[] = [
                    'id' => $idProduct . ':' . (int) $combination['id_product_attribute'],
                    'text' => $this->buildProductLabel(
                        $name,
                        $combination['attribute_names'],
                        $combination['reference'] ?: $p['reference'],
                        $combination['ean13'] ?: $p['ean13']
                    ),
                ];
            }
        }

        return $results;
    }

    public function getProductManufacturers(): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT DISTINCT m.id_manufacturer, m.name
            FROM `' . _DB_PREFIX_ . 'manufacturer` m
            INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_manufacturer = m.id_manufacturer
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product AND ps.id_shop = ' . (int) $this->context->shop->id . '
            ORDER BY m.name');
        if ($rows === false) {
            throw new RuntimeException($this->module->l('Impossible de charger les marques.'));
        }

        return $rows;
    }

    private function findProducts(string $term, int $idManufacturer, int $offset, int $limit, int $excludeTarif = 0): array
    {
        $escaped = pSQL(trim($term));
        $where = '';
        $availabilityJoin = '';
        if ($escaped !== '') {
            $where .= ' AND (
                pl.name LIKE "%' . $escaped . '%"
                OR p.reference LIKE "%' . $escaped . '%"
                OR p.ean13 LIKE "%' . $escaped . '%"
                OR m.name LIKE "%' . $escaped . '%"
                OR EXISTS (
                    SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute` search_pa
                    WHERE search_pa.id_product = p.id_product
                      AND (search_pa.reference LIKE "%' . $escaped . '%"
                           OR search_pa.ean13 LIKE "%' . $escaped . '%")
                )
            )';
        }
        if ($idManufacturer > 0) {
            $where .= ' AND p.id_manufacturer = ' . $idManufacturer;
        } elseif ($idManufacturer === -1) {
            $where .= ' AND (p.id_manufacturer = 0 OR p.id_manufacturer IS NULL)';
        }
        if ($excludeTarif > 0) {
            // La cible 0 représente un produit simple ; les autres sont ses déclinaisons.
            $availabilityJoin = '
                LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` available_pa
                    ON available_pa.id_product = p.id_product
                LEFT JOIN `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line` available_line
                    ON available_line.id_tarif = ' . $excludeTarif . '
                   AND available_line.id_product = p.id_product
                   AND available_line.id_product_attribute = COALESCE(available_pa.id_product_attribute, 0)';
            $where .= ' AND available_line.id_tarif IS NULL';
        }
        $rows = Db::getInstance()->executeS('
            SELECT DISTINCT p.id_product, pl.name, p.reference, p.ean13, ps.active, m.name AS manufacturer_name
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON ps.id_product = p.id_product AND ps.id_shop = ' . (int) $this->context->shop->id . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . (int) $this->context->language->id . '
               AND pl.id_shop = ps.id_shop
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON m.id_manufacturer = p.id_manufacturer
            ' . $availabilityJoin . '
            WHERE 1 ' . $where . '
            ORDER BY pl.name, p.id_product
            LIMIT ' . $offset . ', ' . $limit);
        if ($rows === false) {
            throw new RuntimeException($this->module->l('Impossible de charger les produits.'));
        }

        return $rows;
    }

    private function getExistingProductKeys(int $idTarif): array
    {
        if ($idTarif <= 0) {
            return [];
        }
        $rows = Db::getInstance()->executeS('
            SELECT id_product, id_product_attribute
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . $idTarif);
        if ($rows === false) {
            throw new RuntimeException($this->module->l('Impossible de charger les produits du tarif.'));
        }
        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['id_product'] . ':' . $row['id_product_attribute']] = true;
        }

        return $keys;
    }

    public function browseProducts(int $idTarif, string $term, int $idManufacturer, int $page, bool $onlyMissing = false): array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }
        $page = max(1, $page);
        $pageSize = 24;
        $products = $this->findProducts($term, $idManufacturer, ($page - 1) * $pageSize, $pageSize + 1, $onlyMissing ? $idTarif : 0);
        $hasMore = count($products) > $pageSize;
        $existing = $this->getExistingProductKeys($idTarif);
        $results = [];
        foreach (array_slice($products, 0, $pageSize) as $product) {
            $idProduct = (int) $product['id_product'];
            $combinations = $this->getCombinations($idProduct, (int) $this->context->language->id);
            $targets = $combinations ?: [['id_product_attribute' => 0]];
            foreach ($targets as $target) {
                $idAttribute = (int) $target['id_product_attribute'];
                $key = $idProduct . ':' . $idAttribute;
                if ($onlyMissing && isset($existing[$key])) {
                    continue;
                }
                $info = $this->getProductInfo($idProduct, $idAttribute);
                if (!$info) {
                    throw new RuntimeException($this->module->l('Produit introuvable.'));
                }
                $results[] = array_merge($info, [
                    'id' => $key,
                    'id_product' => $idProduct,
                    'combination_count' => count($combinations),
                    'active' => (bool) $product['active'],
                    'manufacturer_name' => (string) ($product['manufacturer_name'] ?? $this->module->l('Sans marque')),
                    'price' => Tools::displayPrice($this->getBasePrice($idProduct, $idAttribute), $this->context->currency),
                    'in_tarif' => isset($existing[$key]),
                ]);
            }
        }

        return ['products' => $results, 'page' => $page, 'has_more' => $hasMore];
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

    private function computeDiscountPrices(float $catalog, float $reduction, float $additional, ?float $intermediate = null): array
    {
        if (!is_finite($catalog) || $catalog < 0
            || !is_finite($additional) || $additional < 0 || $additional > 100
            || !is_finite($reduction) || $reduction > 100) {
            throw new RuntimeException($this->module->l('Remise invalide : la remise supplémentaire doit être comprise entre 0 et 100 %.'));
        }
        $intermediate = $intermediate ?? $this->computeFinalFromReduction($catalog, $reduction);
        $final = $this->computeFinalFromReduction($intermediate, $additional);

        return [
            'intermediate_price' => $intermediate,
            'additional_reduction_percent' => $additional,
            'total_reduction_percent' => $this->computeReductionFromFinal($catalog, $final),
            'final_price' => $final,
        ];
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
        $reductionPercent = (float) $line['reduction_percent'];
        $discounts = $this->computeDiscountPrices($storedBase, $reductionPercent, (float) $line['additional_reduction_percent']);
        $currentPrice = (float) $line['current_price'];

        $liveBase = $storedBase;
        $liveGroup = $storedGroup;
        $liveCurrent = $currentPrice;
        $hasChanges = false;

        // En mode éditeur on dispose des prix actuels : ils servent à détecter
        // les changements et à déterminer précisément l'origine de la réduction.
        if ($detectChanges) {
            $prices = $this->computeLinePrices($idProduct, $idProductAttribute, $idCustomer);
            $liveBase = $prices['catalog'];
            $liveGroup = $prices['group_reduction'];
            $liveCurrent = $prices['current'];
            $hasChanges = abs($liveBase - $storedBase) >= 0.005
                || abs($liveGroup - $storedGroup) >= 0.005;
        }

        $hasCustomerRule = $this->hasCustomerSpecificPrice($idProduct, $idProductAttribute, $idCustomer);
        $reductionSource = $this->resolveReductionSource(
            $reductionPercent,
            $liveGroup,
            $liveBase,
            $liveCurrent,
            $hasCustomerRule
        );

        return [
            'id_line' => (int) $line['id_line'],
            'is_processed' => (bool) $line['is_processed'],
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
            'current_price' => $currentPrice,
            'reduction_percent' => $reductionPercent,
            'additional_reduction_percent' => $discounts['additional_reduction_percent'],
            'intermediate_price' => $discounts['intermediate_price'],
            'total_reduction_percent' => $this->computeReductionFromFinal($storedBase, (float) $line['final_price']),
            'final_price' => (float) $line['final_price'],
            'has_group_rule' => $storedGroup > 0,
            'has_customer_rule' => $hasCustomerRule,
            'reduction_source' => $reductionSource,
            'live_base_price' => $liveBase,
            'live_group_reduction_percent' => $liveGroup,
            'has_changes' => $hasChanges,
        ];
    }

    /**
     * Détermine l'origine de la réduction client affichée sur une ligne :
     *  - 'group'    : le pourcentage correspond à la remise de groupe ;
     *  - 'customer' : le pourcentage est verrouillé sur le client (prix
     *                 spécifique client existant) ;
     *  - 'custom'   : le pourcentage n'est encore ni sur le client ni issu du
     *                 groupe (valeur négociée pas encore validée) ;
     *  - 'none'     : aucune réduction.
     */
    private function resolveReductionSource(
        float $reductionPercent,
        float $groupReduction,
        float $catalogPrice,
        float $currentPrice,
        bool $hasCustomerRule
    ): string {
        $tol = 0.01;

        $customerPercent = ($hasCustomerRule && $catalogPrice > 0)
            ? round((($catalogPrice - $currentPrice) / $catalogPrice) * 100, 2)
            : null;

        if ($customerPercent !== null && abs($reductionPercent - $customerPercent) < $tol) {
            return 'customer';
        }
        if ($groupReduction > $tol && abs($reductionPercent - $groupReduction) < $tol) {
            return 'group';
        }
        if (abs($reductionPercent) < $tol && $groupReduction < $tol && !$hasCustomerRule) {
            return 'none';
        }

        return 'custom';
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

        if ($this->lineExists($idTarif, $idProduct, $idProductAttribute)) {
            throw new RuntimeException($this->module->l('Ce produit est déjà présent dans le tarif.'));
        }

        return $this->insertLine($tarif, $idProduct, $idProductAttribute, $idSection);
    }

    /**
     * Ajoute une ligne pour chaque déclinaison d'un produit déclinable.
     * Les déclinaisons déjà présentes dans le tarif sont ignorées.
     *
     * @return array{added: array, skipped: int}
     */
    public function addAllCombinations(int $idTarif, int $idProduct, int $idSection): array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $combinations = $this->getCombinations($idProduct, (int) $this->context->language->id);
        if (empty($combinations)) {
            // Produit sans déclinaison : on ajoute le produit global
            if ($this->lineExists($idTarif, $idProduct, 0)) {
                return ['added' => [], 'skipped' => 1];
            }

            return ['added' => [$this->insertLine($tarif, $idProduct, 0, $idSection)], 'skipped' => 0];
        }

        $added = [];
        $skipped = 0;
        foreach ($combinations as $combination) {
            $idProductAttribute = (int) $combination['id_product_attribute'];
            if ($this->lineExists($idTarif, $idProduct, $idProductAttribute)) {
                $skipped++;
                continue;
            }
            $added[] = $this->insertLine($tarif, $idProduct, $idProductAttribute, $idSection);
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * Ajoute en masse des lignes à partir d'une liste de références produit ou
     * déclinaison. Une référence correspondant à une déclinaison ajoute cette
     * déclinaison ; une référence produit sans déclinaison ajoute le produit ;
     * une référence produit avec déclinaisons ajoute toutes ses déclinaisons.
     *
     * @param string[] $references
     *
     * @return array{added: array, not_found: string[]}
     */
    public function addLinesByReferences(int $idTarif, array $references, int $idSection): array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        $added = [];
        $notFound = [];
        $duplicates = [];
        $seen = [];

        foreach ($references as $reference) {
            $reference = trim($reference);
            if ($reference === '') {
                continue;
            }
            // Éviter les doublons dans la même soumission
            if (isset($seen[Tools::strtolower($reference)])) {
                continue;
            }
            $seen[Tools::strtolower($reference)] = true;

            $targets = $this->resolveProductByReference($reference);
            if (empty($targets)) {
                $notFound[] = $reference;
                continue;
            }

            $addedForRef = 0;
            foreach ($targets as $target) {
                $idProduct = (int) $target['id_product'];
                $idProductAttribute = (int) $target['id_product_attribute'];
                if ($this->lineExists($idTarif, $idProduct, $idProductAttribute)) {
                    continue;
                }
                $added[] = $this->insertLine($tarif, $idProduct, $idProductAttribute, $idSection);
                $addedForRef++;
            }

            // Toutes les cibles de cette référence étaient déjà présentes
            if ($addedForRef === 0) {
                $duplicates[] = $reference;
            }
        }

        return [
            'added' => $added,
            'not_found' => $notFound,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Indique si le couple produit / déclinaison est déjà présent dans le tarif.
     */
    private function lineExists(int $idTarif, int $idProduct, int $idProductAttribute): bool
    {
        return (bool) Db::getInstance()->getValue('
            SELECT 1
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif . '
              AND id_product = ' . (int) $idProduct . '
              AND id_product_attribute = ' . (int) $idProductAttribute);
    }

    /**
     * Résout une référence en une liste de cibles [id_product, id_product_attribute].
     *
     * @return array<array{id_product:int,id_product_attribute:int}>
     */
    private function resolveProductByReference(string $reference): array
    {
        $escaped = pSQL($reference);

        // 1) Référence de déclinaison
        $combination = Db::getInstance()->getRow('
            SELECT pa.id_product, pa.id_product_attribute
            FROM `' . _DB_PREFIX_ . 'product_attribute` pa
            WHERE pa.reference = "' . $escaped . '"');
        if ($combination) {
            return [[
                'id_product' => (int) $combination['id_product'],
                'id_product_attribute' => (int) $combination['id_product_attribute'],
            ]];
        }

        // 2) Référence produit
        $idProduct = (int) Db::getInstance()->getValue('
            SELECT id_product
            FROM `' . _DB_PREFIX_ . 'product`
            WHERE reference = "' . $escaped . '"');
        if ($idProduct <= 0) {
            return [];
        }

        $combinations = $this->getCombinations($idProduct, (int) $this->context->language->id);
        if (empty($combinations)) {
            return [['id_product' => $idProduct, 'id_product_attribute' => 0]];
        }

        $targets = [];
        foreach ($combinations as $combination) {
            $targets[] = [
                'id_product' => $idProduct,
                'id_product_attribute' => (int) $combination['id_product_attribute'],
            ];
        }

        return $targets;
    }
    private function insertLine(
        CustomCatalogTarif $tarif,
        int $idProduct,
        int $idProductAttribute,
        int $idSection
    ): array {
        $idTarif = (int) $tarif->id;
        $prices = $this->computeLinePrices($idProduct, $idProductAttribute, (int) $tarif->id_customer);
        $catalog = $prices['catalog'];
        $current = $prices['current'];
        // Réduction client par défaut : celle qui reproduit le prix actuel du client
        $reductionPercent = $catalog > 0 ? $this->computeReductionFromFinal($catalog, $current) : 0.0;
        $finalPrice = $current;

        $position = (int) Db::getInstance()->getValue('
            SELECT IFNULL(MAX(position), -1) + 1
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . $idTarif . ' AND id_section = ' . (int) $idSection);

        Db::getInstance()->insert('customcatalogonpdf_tarif_line', [
            'id_tarif' => $idTarif,
            'id_section' => (int) $idSection,
            'id_product' => (int) $idProduct,
            'id_product_attribute' => (int) $idProductAttribute,
            'position' => $position,
            'base_price' => $catalog,
            'group_reduction_percent' => $prices['group_reduction'],
            'current_price' => $current,
            'reduction_percent' => $reductionPercent,
            'additional_reduction_percent' => 0,
            'final_price' => $finalPrice,
        ]);

        $idLine = (int) Db::getInstance()->Insert_ID();
        $this->touch($tarif);

        $row = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_line = ' . $idLine);

        return $this->decorateLine($row, (int) $tarif->id_customer);
    }

    public function setLineProcessed(int $idTarif, int $idLine, bool $processed): array
    {
        $db = Db::getInstance();
        $line = $db->getRow('
            SELECT id_line FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_line = ' . $idLine . ' AND id_tarif = ' . $idTarif);
        if (!$line) {
            throw new RuntimeException($this->module->l('Ligne introuvable.'));
        }
        if (!$db->update('customcatalogonpdf_tarif_line', [
            'is_processed' => (int) $processed,
        ], 'id_line = ' . $idLine . ' AND id_tarif = ' . $idTarif)) {
            throw new RuntimeException($this->module->l('Impossible d’enregistrer le suivi de cette ligne.'));
        }

        return ['id_line' => $idLine, 'is_processed' => $processed];
    }

    /**
     * Met à jour une ligne par saisie de la réduction (%) ou du prix final.
     *
     * @param string $mode 'reduction', 'additional_reduction' ou 'price' (net après les deux remises)
     */
    public function updateLine(int $idTarif, int $idLine, string $mode, float $value): array
    {
        $line = Db::getInstance()->getRow('
            SELECT * FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_line = ' . (int) $idLine . ' AND id_tarif = ' . (int) $idTarif);

        if (!$line) {
            throw new RuntimeException($this->module->l('Ligne introuvable.'));
        }
        if (!in_array($mode, ['reduction', 'additional_reduction', 'price'], true) || !is_finite($value)) {
            throw new RuntimeException($this->module->l('Mode ou valeur de remise invalide.'));
        }

        $catalog = (float) $line['base_price'];
        $reductionPercent = (float) $line['reduction_percent'];
        $additional = (float) $line['additional_reduction_percent'];
        $intermediate = null;

        if ($mode === 'price') {
            if ($value < 0 || ($catalog <= 0 && $value > 0)) {
                throw new RuntimeException($this->module->l('Prix final invalide.'));
            }
            $net = round($value, 2);
            if ($additional >= 100) {
                if ($net > 0) {
                    throw new RuntimeException($this->module->l('Une remise supplémentaire de 100 % impose un prix final nul.'));
                }
            } else {
                $intermediate = round($net / (1 - $additional / 100), 2);
                $reductionPercent = $this->computeReductionFromFinal($catalog, $intermediate);
            }
        } elseif ($mode === 'additional_reduction') {
            $this->computeDiscountPrices($catalog, $reductionPercent, $value);
            $additional = round($value, 4);
        } else {
            $reductionPercent = round($value, 4);
        }
        $discounts = $this->computeDiscountPrices($catalog, $reductionPercent, $additional, $intermediate);

        if (!Db::getInstance()->update('customcatalogonpdf_tarif_line', [
            'reduction_percent' => $reductionPercent,
            'additional_reduction_percent' => $additional,
            'final_price' => $discounts['final_price'],
        ], 'id_line = ' . (int) $idLine . ' AND id_tarif = ' . $idTarif)) {
            throw new RuntimeException($this->module->l('Impossible d’enregistrer les remises de cette ligne.'));
        }

        $this->touchById($idTarif);

        $tarif = new CustomCatalogTarif($idTarif);
        $idCustomer = (int) $tarif->id_customer;
        $hasCustomerRule = $this->hasCustomerSpecificPrice(
            (int) $line['id_product'],
            (int) $line['id_product_attribute'],
            $idCustomer
        );
        $liveCurrent = $this->getCurrentPrice(
            (int) $line['id_product'],
            (int) $line['id_product_attribute'],
            $idCustomer
        );
        $reductionSource = $this->resolveReductionSource(
            $reductionPercent,
            (float) $line['group_reduction_percent'],
            $catalog,
            $liveCurrent,
            $hasCustomerRule
        );

        return array_merge([
            'id_line' => (int) $idLine,
            'reduction_percent' => $reductionPercent,
            'reduction_source' => $reductionSource,
        ], $discounts);
    }

    /**
     * Remplace une des deux remises sur la section en conservant l'autre.
     */
    public function applySectionReduction(int $idTarif, int $idSection, float $reductionPercent, string $mode = 'reduction'): array
    {
        $tarif = new CustomCatalogTarif($idTarif);
        if (!Validate::isLoadedObject($tarif)) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }

        if (!in_array($mode, ['reduction', 'additional_reduction'], true)) {
            throw new RuntimeException($this->module->l('Type de remise de section invalide.'));
        }
        $this->computeDiscountPrices(100, $mode === 'reduction' ? $reductionPercent : 0,
            $mode === 'additional_reduction' ? $reductionPercent : 0);
        $reductionPercent = round($reductionPercent, 4);

        $lines = Db::getInstance()->executeS('
            SELECT *
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . (int) $idTarif . ' AND id_section = ' . (int) $idSection);
        if ($lines === false) {
            throw new RuntimeException($this->module->l('Impossible de charger les lignes de la section.'));
        }

        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException($this->module->l('Impossible de démarrer la mise à jour de section.'));
        }
        try {
            $updated = [];
            foreach ($lines as $line) {
                $updated[] = $this->updateLine($idTarif, (int) $line['id_line'], $mode, $reductionPercent);
            }
            if (!$db->execute('COMMIT')) {
                throw new RuntimeException($this->module->l('Impossible de finaliser les remises de section.'));
            }
        } catch (Throwable $e) {
            if (!$db->execute('ROLLBACK')) {
                throw new RuntimeException($this->module->l('Échec de l’annulation des remises de section.'), 0, $e);
            }
            throw $e;
        }

        return $updated;
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
            SELECT id_line, id_product, id_product_attribute, reduction_percent, additional_reduction_percent
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
            $discounts = $this->computeDiscountPrices($prices['catalog'], $reductionPercent, (float) $line['additional_reduction_percent']);

            Db::getInstance()->update('customcatalogonpdf_tarif_line', [
                'base_price' => $prices['catalog'],
                'group_reduction_percent' => $prices['group_reduction'],
                'current_price' => $prices['current'],
                'final_price' => $discounts['final_price'],
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
        $adaptSpecificPrices = false;
        foreach ($this->getValidationHistory($idTarif) as $validation) {
            if (($validation['target_type'] === 'customer' && (int) $validation['id_target'] === $idCustomer)
                || $validation['target_type'] === 'legacy') {
                $adaptSpecificPrices = $tarif->isValidated() && $idCustomer > 0;
                break;
            }
        }

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
            if ($newCatalog <= 0 && $finalPrice > 0) {
                throw new RuntimeException($this->module->l('Un prix catalogue nul ne permet pas de conserver ce prix final.'));
            }
            // On conserve le prix final : la réduction client est recalculée
            $additional = (float) $line['additional_reduction_percent'];
            $this->computeDiscountPrices($newCatalog, 0, $additional);
            if ($additional < 100) {
                $intermediate = $finalPrice / (1 - $additional / 100);
                $reductionPercent = $this->computeReductionFromFinal($newCatalog, $intermediate);
            } else {
                if (abs($finalPrice) >= 0.005) {
                    throw new RuntimeException($this->module->l('Une remise supplémentaire de 100 % impose un prix final nul.'));
                }
                $reductionPercent = (float) $line['reduction_percent'];
            }

            Db::getInstance()->update('customcatalogonpdf_tarif_line', [
                'base_price' => $newCatalog,
                'group_reduction_percent' => $prices['group_reduction'],
                'current_price' => $prices['current'],
                'reduction_percent' => $reductionPercent,
            ], 'id_line = ' . (int) $line['id_line']);

            // Adapter le prix spécifique client existant pour préserver le prix final
            if ($adaptSpecificPrices && $this->hasCustomerSpecificPrice($idProduct, $idProductAttribute, $idCustomer)) {
                $this->replaceSpecificPrice($idProduct, $idProductAttribute, $idCustomer, 0, $newCatalog, $finalPrice);
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
     * Remplace les règles manuelles de cette cible et de cette déclinaison uniquement.
     */
    private function replaceSpecificPrice(
        int $idProduct,
        int $idProductAttribute,
        int $idCustomer,
        int $idGroup,
        float $catalogPrice,
        float $finalPrice,
        bool $inheritGroupPrice = false
    ): bool {
        if (!is_finite($finalPrice) || $finalPrice < 0 || !is_finite($catalogPrice) || $catalogPrice < 0) {
            throw new RuntimeException($this->module->l('Prix invalide dans le tarif.'));
        }
        if ($catalogPrice <= 0 && $finalPrice > 0) {
            throw new RuntimeException($this->module->l('Un prix catalogue nul ne permet pas de calculer cette remise.'));
        }
        $db = Db::getInstance();
        if (!$db->delete('specific_price',
            'id_product = ' . $idProduct
            . ' AND id_product_attribute = ' . $idProductAttribute
            . ' AND id_customer = ' . $idCustomer
            . ($idCustomer > 0 ? '' : ' AND id_group = ' . $idGroup)
            . ' AND id_cart = 0 AND id_specific_price_rule = 0'
        )) {
            throw new RuntimeException($this->module->l('Impossible de remplacer les anciens prix spécifiques.'));
        }

        if ($inheritGroupPrice || $catalogPrice <= 0) {
            return false;
        }

        $reductionPercent = round((($catalogPrice - $finalPrice) / $catalogPrice) * 100, 2);

        if (!$db->insert('specific_price', [
            'id_specific_price_rule' => 0,
            'id_cart' => 0,
            'id_product' => $idProduct,
            'id_shop' => 0,
            'id_shop_group' => 0,
            'id_currency' => 0,
            'id_country' => 0,
            'id_group' => $idGroup,
            'id_customer' => $idCustomer,
            'id_product_attribute' => $idProductAttribute,
            'price' => -1,
            'from_quantity' => 1,
            'reduction' => $reductionPercent / 100,
            'reduction_tax' => 0,
            'reduction_type' => 'percentage',
            'from' => '0000-00-00 00:00:00',
            'to' => '0000-00-00 00:00:00',
        ])) {
            throw new RuntimeException($this->module->l('Impossible d’enregistrer les nouveaux prix spécifiques.'));
        }

        return true;
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
            $additional = (float) $line['additional_reduction_percent'];
            $prices = $this->computeLinePrices($idProduct, $idProductAttribute, $idCustomer);
            $discounts = $this->computeDiscountPrices($prices['catalog'], $reduction, $additional);

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
                'additional_reduction_percent' => $additional,
                'final_price' => $discounts['final_price'],
            ]);
        }

        return $newIdTarif;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Validation → prix spécifiques
    // ─────────────────────────────────────────────────────────────────────────

    public function getValidationHistory(int $idTarif): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT *
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_validation`
            WHERE id_tarif = ' . $idTarif . '
            ORDER BY id_validation DESC', true, false);
        if ($rows === false) {
            throw new RuntimeException($this->module->l('Impossible de charger l’historique des validations.'));
        }

        return $rows;
    }

    public function getValidationTargets(int $idTarif): array
    {
        $rows = Db::getInstance()->executeS('
            SELECT t.id_tarif, c.id_customer, c.firstname, c.lastname,
                   g.id_group, gl.name AS group_name
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif` t
            LEFT JOIN `' . _DB_PREFIX_ . 'customer` c
                ON c.id_customer = t.id_customer AND c.deleted = 0
            LEFT JOIN `' . _DB_PREFIX_ . 'group` g ON g.id_group = c.id_default_group
            LEFT JOIN `' . _DB_PREFIX_ . 'group_lang` gl
                ON gl.id_group = g.id_group AND gl.id_lang = ' . (int) $this->context->language->id . '
            WHERE t.id_tarif = ' . $idTarif, true, false);
        if ($rows === false) {
            throw new RuntimeException($this->module->l('Impossible de charger la cible de validation.'));
        }
        if (!$rows) {
            throw new RuntimeException($this->module->l('Tarif introuvable.'));
        }
        $row = $rows[0];
        if (!(int) $row['id_customer']) {
            return [];
        }
        $targets = [
            'customer' => [
                'id' => (int) $row['id_customer'],
                'name' => trim($row['firstname'] . ' ' . $row['lastname']),
            ],
        ];
        if ((int) $row['id_group'] > 0) {
            $targets['group'] = ['id' => (int) $row['id_group'], 'name' => (string) $row['group_name']];
        }

        return $targets;
    }

    public function prepareValidation(int $idTarif, string $target, bool $lockLines = false): array
    {
        if (!in_array($target, ['customer', 'group'], true)) {
            throw new RuntimeException($this->module->l('Cible de validation invalide.'));
        }
        $targets = $this->getValidationTargets($idTarif);
        if (!isset($targets[$target])) {
            throw new RuntimeException($this->module->l('Client ou groupe par défaut introuvable. Enregistrez un client valide avant de continuer.'));
        }
        $db = Db::getInstance();
        $lines = $db->executeS('
            SELECT id_line, id_product, id_product_attribute, reduction_percent, additional_reduction_percent, final_price
            FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif_line`
            WHERE id_tarif = ' . $idTarif . ' ORDER BY id_line' . ($lockLines ? ' FOR UPDATE' : ''), true, false);
        if ($lines === false) {
            throw new RuntimeException($this->module->l('Impossible de charger les lignes du tarif.'));
        }
        if (!$lines) {
            throw new RuntimeException($this->module->l('Impossible de valider un tarif vide.'));
        }
        $history = $this->getValidationHistory($idTarif);
        $snapshot = [
            $idTarif, $target, $targets, $lines, (int) $this->context->employee->id,
            $history[0]['id_validation'] ?? 0,
        ];

        return [
            'id_target' => $targets[$target]['id'],
            'id_group' => $targets['group']['id'] ?? 0,
            'target_name' => $targets[$target]['name'],
            'line_count' => count($lines),
            'confirmation_token' => hash_hmac('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR), _COOKIE_KEY_),
            'lines' => $lines,
        ];
    }

    /**
     * Le jeton lie la seconde confirmation aux lignes, à la cible et à l'employé.
     */
    public function validate(int $idTarif, string $target, int $idTarget, string $confirmationToken): int
    {
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            throw new RuntimeException($this->module->l('Impossible de démarrer la validation.'));
        }
        try {
            $lockedTarif = $db->executeS('SELECT id_tarif, id_customer FROM `' . _DB_PREFIX_ . 'customcatalogonpdf_tarif`
                WHERE id_tarif = ' . $idTarif . ' FOR UPDATE', true, false);
            if (!$lockedTarif) {
                throw new RuntimeException($this->module->l('Tarif introuvable.'));
            }
            if ($db->executeS('SELECT id_customer FROM `' . _DB_PREFIX_ . 'customer`
                WHERE id_customer = ' . (int) $lockedTarif[0]['id_customer'] . ' FOR UPDATE', true, false) === false) {
                throw new RuntimeException($this->module->l('Impossible de vérifier le client du tarif.'));
            }
            $prepared = $this->prepareValidation($idTarif, $target, true);
            if ($idTarget !== $prepared['id_target']
                || !hash_equals($prepared['confirmation_token'], $confirmationToken)) {
                throw new RuntimeException($this->module->l('Le tarif ou sa cible a changé. Rechargez la page et recommencez les deux confirmations.'));
            }
            $tarif = new CustomCatalogTarif($idTarif);
            $idCustomer = $target === 'customer' ? $idTarget : 0;
            $idGroup = $prepared['id_group'];
            $count = 0;
            foreach ($prepared['lines'] as $line) {
                $idProduct = (int) $line['id_product'];
                $idAttribute = (int) $line['id_product_attribute'];
                $finalPrice = (float) $line['final_price'];
                $basePrice = $this->getBasePrice($idProduct, $idAttribute);
                $skip = $target === 'customer'
                    && abs($finalPrice - $this->getGroupPrice($idProduct, $idAttribute, $idGroup)) < 0.005;
                if ($this->replaceSpecificPrice(
                    $idProduct, $idAttribute, $idCustomer, $target === 'group' ? $idGroup : 0,
                    $basePrice, $finalPrice, $skip
                )) {
                    $count++;
                }
            }
            $date = date('Y-m-d H:i:s');
            if (!$db->insert('customcatalogonpdf_tarif_validation', [
                'id_tarif' => $idTarif,
                'target_type' => pSQL($target),
                'id_target' => $idTarget,
                'target_name' => pSQL($prepared['target_name']),
                'id_employee' => (int) $this->context->employee->id,
                'employee_name' => pSQL(trim($this->context->employee->firstname . ' ' . $this->context->employee->lastname)),
                'line_count' => $prepared['line_count'],
                'date_add' => $date,
            ])) {
                throw new RuntimeException($this->module->l('Impossible d’enregistrer l’historique de validation.'));
            }
            $tarif->status = CustomCatalogTarif::STATUS_VALIDATED;
            $tarif->date_validated = $date;
            if (!$tarif->update()) {
                throw new RuntimeException($this->module->l('Impossible d’enregistrer le statut du tarif.'));
            }
            if (!$db->execute('COMMIT')) {
                throw new RuntimeException($this->module->l('Impossible de finaliser la validation.'));
            }
        } catch (Throwable $e) {
            if (!$db->execute('ROLLBACK')) {
                throw new RuntimeException($this->module->l('Échec de l’annulation de la validation. Vérifiez les prix enregistrés.'), 0, $e);
            }
            throw $e;
        } finally {
            if (method_exists('SpecificPrice', 'flushCache')) {
                SpecificPrice::flushCache();
            }
            Product::flushPriceCache();
        }

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
