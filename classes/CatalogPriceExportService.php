<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CatalogPriceExportService
{
    private Context $context;
    private Module $module;

    public function __construct(Module $module)
    {
        $this->context = Context::getContext();
        $this->module = $module;
    }

    public function getViewData(array $request, bool $loadProducts): array
    {
        $filters = $this->normalizeFilters($request);
        $dataset = $loadProducts && $this->hasPrimaryPrice($filters)
            ? $this->buildDataset($filters)
            : ['headers' => [], 'rows' => []];

        return [
            'groups' => $this->getGroups(),
            'suppliers' => $this->getSuppliers(),
            'filters' => $filters,
            'selected_customer' => $this->getCustomerOption($filters['id_customer']),
            'headers' => $dataset['headers'],
            'rows' => $this->formatRowsForDisplay($dataset),
            'has_primary_price' => $this->hasPrimaryPrice($filters),
        ];
    }

    public function searchCustomers(string $term): array
    {
        $term = trim($term);
        if ($term !== '' && Tools::strlen($term) < 2) {
            return [];
        }

        $escapedTerm = pSQL($term);
        $customers = Db::getInstance()->executeS('
            SELECT c.id_customer, c.firstname, c.lastname, c.email
            FROM `' . _DB_PREFIX_ . 'customer` c
            WHERE c.active = 1
              AND c.deleted = 0
              AND (
                  c.firstname LIKE "%' . $escapedTerm . '%"
                  OR c.lastname LIKE "%' . $escapedTerm . '%"
                  OR c.email LIKE "%' . $escapedTerm . '%"
              )
            ORDER BY c.lastname, c.firstname
            LIMIT 50
        ') ?: [];

        return array_map(static function (array $customer): array {
            return [
                'id' => (int) $customer['id_customer'],
                'text' => sprintf(
                    '%s %s (%s)',
                    $customer['firstname'],
                    $customer['lastname'],
                    $customer['email']
                ),
            ];
        }, $customers);
    }

    public function export(array $request, string $format): void
    {
        $filters = $this->normalizeFilters($request);
        if (!$this->hasPrimaryPrice($filters)) {
            throw new InvalidArgumentException($this->module->l('Sélectionnez un groupe ou un client avant d’exporter.'));
        }

        $dataset = $this->buildDataset($filters);
        if ($format === 'csv') {
            $this->streamCsv($dataset, $filters);
            return;
        }
        if ($format === 'xlsx') {
            $this->streamXlsx($dataset, $filters);
            return;
        }

        throw new InvalidArgumentException($this->module->l('Format d’export non pris en charge.'));
    }

    private function normalizeFilters(array $request): array
    {
        $groups = array_column($this->getGroups(), 'name', 'id_group');
        $supplierIds = array_map('intval', array_column($this->getSuppliers(), 'id_supplier'));

        $idGroup = (int) ($request['id_group'] ?? 0);
        $idCustomer = (int) ($request['id_customer'] ?? 0);
        $idComparisonGroup = (int) ($request['id_comparison_group'] ?? 0);
        $idSupplier = (int) ($request['id_supplier'] ?? 0);
        $includePurchasePrice = !empty($request['include_purchase_price']);

        if (!isset($groups[$idGroup])) {
            $idGroup = 0;
        }
        if (!isset($groups[$idComparisonGroup])) {
            $idComparisonGroup = 0;
        }
        if (!$this->isActiveCustomer($idCustomer)) {
            $idCustomer = 0;
        }
        if (!in_array($idSupplier, $supplierIds, true)) {
            $idSupplier = 0;
        }

        return [
            'id_group' => $idGroup,
            'id_customer' => $idCustomer,
            'id_comparison_group' => $idComparisonGroup,
            'include_purchase_price' => $includePurchasePrice && $idSupplier > 0,
            'purchase_price_requested' => $includePurchasePrice,
            'id_supplier' => $idSupplier,
        ];
    }

    private function buildDataset(array $filters): array
    {
        $products = $this->getProducts($filters);
        $groupNames = array_column($this->getGroups(), 'name', 'id_group');
        $customer = $this->getCustomerOption($filters['id_customer']);

        $primaryLabel = $filters['id_customer'] > 0
            ? $this->module->l('Prix HT — client')
            : sprintf(
                $this->module->l('Prix HT — %s'),
                $groupNames[$filters['id_group']] ?? ''
            );

        $headers = [
            ['key' => 'category', 'label' => $this->module->l('Catégorie'), 'numeric' => false],
            ['key' => 'manufacturer', 'label' => $this->module->l('Fabricant'), 'numeric' => false],
            ['key' => 'product', 'label' => $this->module->l('Nom du produit'), 'numeric' => false],
            ['key' => 'reference', 'label' => $this->module->l('Référence'), 'numeric' => false],
            ['key' => 'primary_price', 'label' => $primaryLabel, 'numeric' => true],
        ];

        if ($filters['id_comparison_group'] > 0) {
            $headers[] = [
                'key' => 'comparison_price',
                'label' => sprintf(
                    $this->module->l('Prix HT — %s'),
                    $groupNames[$filters['id_comparison_group']] ?? ''
                ),
                'numeric' => true,
            ];
        }
        if ($filters['include_purchase_price']) {
            $headers[] = [
                'key' => 'purchase_price',
                'label' => $this->module->l('Prix d’achat HT'),
                'numeric' => true,
            ];
        }

        $rows = [];
        foreach ($products as $product) {
            $primaryPrice = $filters['id_customer'] > 0
                ? $this->getCustomerPrice($product, $filters['id_customer'])
                : $this->getGroupPrice($product, $filters['id_group']);

            $row = [
                'category' => (string) $product['category_name'],
                'manufacturer' => (string) $product['manufacturer_name'],
                'product' => trim(
                    (string) $product['product_name']
                    . ($product['attribute_names'] ? ' - ' . $product['attribute_names'] : '')
                ),
                'reference' => (string) $product['product_reference'],
                'primary_price' => (float) $primaryPrice,
            ];

            if ($filters['id_comparison_group'] > 0) {
                $row['comparison_price'] = (float) $this->getGroupPrice(
                    $product,
                    $filters['id_comparison_group']
                );
            }
            if ($filters['include_purchase_price']) {
                $row['purchase_price'] = $product['purchase_price'] === null
                    ? null
                    : (float) $product['purchase_price'];
            }
            $rows[] = $row;
        }

        return [
            'headers' => $headers,
            'rows' => $rows,
            'customer' => $customer,
        ];
    }

    private function getProducts(array $filters): array
    {
        $idLang = (int) $this->context->language->id;
        $idShop = (int) $this->context->shop->id;
        $purchasePriceSelect = '';

        if ($filters['include_purchase_price']) {
            $purchasePriceSelect = ', (
                SELECT product_supplier.product_supplier_price_te
                FROM `' . _DB_PREFIX_ . 'product_supplier` product_supplier
                WHERE product_supplier.id_product = p.id_product
                  AND product_supplier.id_product_attribute = COALESCE(pa.id_product_attribute, 0)
                  AND product_supplier.id_supplier = ' . (int) $filters['id_supplier'] . '
                ORDER BY product_supplier.id_product_supplier DESC
                LIMIT 1
            ) AS purchase_price';
        }

        return Db::getInstance()->executeS('
            SELECT
                p.id_product,
                pa.id_product_attribute,
                pl.name AS product_name,
                GROUP_CONCAT(
                    DISTINCT CONCAT(agl_group.name, " : ", agl.name)
                    ORDER BY agl_group.name, agl.name
                    SEPARATOR " / "
                ) AS attribute_names,
                COALESCE(NULLIF(pa.reference, ""), p.reference) AS product_reference,
                cl.name AS category_name,
                manufacturer.name AS manufacturer_name
                ' . $purchasePriceSelect . '
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` product_shop
                ON product_shop.id_product = p.id_product
               AND product_shop.id_shop = ' . $idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON pl.id_product = p.id_product
               AND pl.id_lang = ' . $idLang . '
               AND pl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa
                ON pa.id_product = p.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON cl.id_category = product_shop.id_category_default
               AND cl.id_lang = ' . $idLang . '
               AND cl.id_shop = ' . $idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                ON pac.id_product_attribute = pa.id_product_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute` attribute_value
                ON attribute_value.id_attribute = pac.id_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` agl
                ON agl.id_attribute = attribute_value.id_attribute
               AND agl.id_lang = ' . $idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl_group
                ON agl_group.id_attribute_group = attribute_value.id_attribute_group
               AND agl_group.id_lang = ' . $idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` manufacturer
                ON manufacturer.id_manufacturer = p.id_manufacturer
            WHERE product_shop.active = 1
            GROUP BY
                p.id_product,
                pa.id_product_attribute,
                pl.name,
                pa.reference,
                p.reference,
                cl.name,
                manufacturer.name
            ORDER BY p.id_product, pa.id_product_attribute
        ') ?: [];
    }

    private function getCustomerPrice(array $product, int $idCustomer): float
    {
        $specificPriceOutput = null;

        return (float) Product::getPriceStatic(
            (int) $product['id_product'],
            false,
            (int) $product['id_product_attribute'] ?: null,
            6,
            null,
            false,
            true,
            1,
            false,
            $idCustomer,
            null,
            null,
            $specificPriceOutput,
            true,
            true,
            null,
            true
        );
    }

    private function getGroupPrice(array $product, int $idGroup): float
    {
        $cart = $this->context->cart;
        $addressId = Validate::isLoadedObject($cart)
            ? (int) $cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}
            : null;
        $address = Address::initialize($addressId, true);
        $currencyId = Validate::isLoadedObject($this->context->currency)
            ? (int) $this->context->currency->id
            : Currency::getDefaultCurrencyId();
        $specificPriceOutput = null;

        return (float) Product::priceCalculation(
            (int) $this->context->shop->id,
            (int) $product['id_product'],
            (int) $product['id_product_attribute'] ?: null,
            (int) $address->id_country,
            (int) $address->id_state,
            $address->postcode,
            $currencyId,
            $idGroup,
            1,
            false,
            6,
            false,
            true,
            true,
            $specificPriceOutput,
            true,
            0,
            false,
            0,
            0,
            0
        );
    }

    private function streamCsv(array $dataset, array $filters): void
    {
        $this->clearOutputBuffers();
        $filename = $this->buildFilename($filters, 'csv');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException($this->module->l('Impossible d’ouvrir le flux d’export CSV.'));
        }

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

    private function streamXlsx(array $dataset, array $filters): void
    {
        if (!class_exists(Spreadsheet::class)) {
            throw new RuntimeException($this->module->l('La bibliothèque PhpSpreadsheet est indisponible.'));
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($this->module->l('Tarifs'));

        foreach ($dataset['headers'] as $columnIndex => $header) {
            $column = $columnIndex + 1;
            $sheet->setCellValueExplicitByColumnAndRow(
                $column,
                1,
                $header['label'],
                DataType::TYPE_STRING
            );
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
                        ->getNumberFormat()
                        ->setFormatCode('#,##0.00');
                } else {
                    $sheet->setCellValueExplicitByColumnAndRow(
                        $column,
                        $excelRow,
                        (string) $value,
                        DataType::TYPE_STRING
                    );
                }
            }
        }

        $lastColumn = count($dataset['headers']);
        if ($lastColumn > 0) {
            $sheet->getStyleByColumnAndRow(1, 1, $lastColumn, 1)->getFont()->setBold(true);
            $sheet->getStyleByColumnAndRow(1, 1, $lastColumn, 1)
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB('FFD9EAF7');
            $sheet->setAutoFilterByColumnAndRow(1, 1, $lastColumn, count($dataset['rows']) + 1);
            $sheet->freezePane('A2');
        }

        $this->clearOutputBuffers();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $this->buildFilename($filters, 'xlsx') . '"');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    private function formatRowsForDisplay(array $dataset): array
    {
        $rows = [];
        foreach ($dataset['rows'] as $row) {
            $displayRow = [];
            foreach ($dataset['headers'] as $header) {
                $value = $row[$header['key']] ?? null;
                $displayRow[] = $header['numeric'] && $value !== null
                    ? number_format((float) $value, 2, ',', ' ')
                    : (string) $value;
            }
            $rows[] = $displayRow;
        }

        return $rows;
    }

    private function getGroups(): array
    {
        return Group::getGroups((int) $this->context->language->id) ?: [];
    }

    private function getSuppliers(): array
    {
        return Db::getInstance()->executeS('
            SELECT supplier.id_supplier, supplier.name
            FROM `' . _DB_PREFIX_ . 'supplier` supplier
            WHERE supplier.active = 1
            ORDER BY supplier.name
        ') ?: [];
    }

    private function getCustomerOption(int $idCustomer): ?array
    {
        if ($idCustomer <= 0) {
            return null;
        }

        $customer = Db::getInstance()->getRow('
            SELECT c.id_customer, c.firstname, c.lastname, c.email
            FROM `' . _DB_PREFIX_ . 'customer` c
            WHERE c.id_customer = ' . $idCustomer . '
              AND c.active = 1
              AND c.deleted = 0
        ');
        if (!$customer) {
            return null;
        }

        return [
            'id' => (int) $customer['id_customer'],
            'text' => sprintf(
                '%s %s (%s)',
                $customer['firstname'],
                $customer['lastname'],
                $customer['email']
            ),
        ];
    }

    private function isActiveCustomer(int $idCustomer): bool
    {
        if ($idCustomer <= 0) {
            return false;
        }

        return (bool) Db::getInstance()->getValue('
            SELECT 1
            FROM `' . _DB_PREFIX_ . 'customer`
            WHERE id_customer = ' . $idCustomer . '
              AND active = 1
              AND deleted = 0
        ');
    }

    private function hasPrimaryPrice(array $filters): bool
    {
        return $filters['id_customer'] > 0 || $filters['id_group'] > 0;
    }

    private function buildFilename(array $filters, string $extension): string
    {
        $context = $filters['id_customer'] > 0
            ? 'client_' . $filters['id_customer']
            : 'groupe_' . $filters['id_group'];

        return sprintf('tarifs_%s_%s.%s', $context, date('Ymd'), $extension);
    }

    private function escapeSpreadsheetFormula(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function clearOutputBuffers(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}
