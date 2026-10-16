<?php

define('_PS_VERSION_', '8.2.1');
define('_DB_PREFIX_', 'ps_');

class Context
{
    public object $shop;

    public static function getContext(): self
    {
        $context = new self();
        $context->shop = (object) ['id' => 3];

        return $context;
    }
}

class CustomCatalogProfile
{
    public bool $show_prices = false;
    public bool $use_deepest_category = false;

    public function getManufacturerIds(): array
    {
        return [];
    }
}

class Product
{
    public static array $calls = [];
    private int $id;

    public function __construct(int $id, bool $full, int $lang, int $shop)
    {
        $this->id = $id;
        self::$calls[] = [$id, $lang, $shop];
    }

    public function isNew(): bool
    {
        return $this->id === 1;
    }
}

class Db
{
    public static function getInstance(): self
    {
        return new self();
    }

    public function executeS(string $query): array
    {
        $rows = [];
        foreach ([[1, 11], [1, 12], [2, 0]] as [$id, $attribute]) {
            $rows[] = [
                'id_product' => $id, 'id_product_attribute' => $attribute,
                'product_name' => 'Product ' . $id, 'product_reference_base' => 'BASE-' . $id,
                'product_reference' => 'REF-' . $attribute, 'attribute_names' => 'Variant ' . $attribute,
                'id_image' => 0, 'category_name' => 'Category', 'id_category' => 1,
            ];
        }

        return $rows;
    }
}

class TCPDF
{
    public array $calls = [];
    private float $y = 30;

    public function __call(string $method, array $args)
    {
        $this->calls[] = [$method, $args];
        if ($method === 'SetXY') {
            $this->y = $args[1];
        } elseif ($method === 'SetY') {
            $this->y = $args[0];
        } elseif ($method === 'MultiCell') {
            $this->y += $args[1];
        } elseif ($method === 'Ln') {
            $this->y += $args[0];
        } elseif ($method === 'AddPage') {
            $this->y = 30;
        }
        return match ($method) {
            'getOriginalMargins' => ['left' => 15, 'right' => 15],
            'getPageWidth' => 210,
            'getPageHeight' => 297,
            'getBreakMargin' => 20,
            'GetY' => $this->y,
            default => null,
        };
    }
}

require dirname(__DIR__) . '/classes/CatalogPdfGenerator.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

$generator = new CatalogPdfGenerator();
$profile = new CustomCatalogProfile();
$rows = (new ReflectionMethod($generator, 'getProducts'))->invoke($generator, $profile, 2);
check(Product::$calls === [[1, 2, 3], [2, 2, 3]], 'Native Product::isNew is used once per product in the current shop/language.');
check($rows[0]['is_new'] && $rows[1]['is_new'] && !$rows[2]['is_new'], 'Native novelty status is shared by variants and not applied to old products.');
$categories = (new ReflectionMethod($generator, 'groupByCategory'))->invoke($generator, $rows);
$products = $categories[0]['products'];
check($products[1]['is_new'] && !$products[2]['is_new'], 'Novelty status survives category grouping.');
$draw = new ReflectionMethod($generator, 'drawProduct');
foreach ([true, false] as $isNew) {
    foreach ([true, false] as $variants) {
        $product = $products[$variants ? 1 : 2];
        $product['is_new'] = $isNew;
        $pdf = new CustomCatalogTCPDF();
        $draw->invoke($generator, $pdf, $product, $profile);
        $badges = array_values(array_filter($pdf->calls, static fn (array $call): bool =>
            $call[0] === 'Cell' && $call[1][2] === 'NOUVEAU'
        ));
        check(count($badges) === ($isNew ? 1 : 0), 'Badge presence matches novelty for ' . ($variants ? 'variant' : 'simple') . ' products.');
        if ($isNew) {
            check(in_array(['SetFillColor', [195, 65, 10]], $pdf->calls, true)
                && in_array(['SetTextColor', [255, 255, 255]], $pdf->calls, true)
                && $badges[0][1][6] === true, 'Badge uses a contrasting coloured background and white text.');
            check(in_array(['SetXY', [37, 39.0]], $pdf->calls, true), 'Product name is placed below the badge without overlap.');
        }
    }
}
$pdf = new CustomCatalogTCPDF();
$pdf->SetY(255);
$product = $products[2];
$product['is_new'] = true;
$draw->invoke($generator, $pdf, $product, $profile);
check(in_array(['AddPage', []], $pdf->calls, true), 'Badge height is included when deciding a page break.');
