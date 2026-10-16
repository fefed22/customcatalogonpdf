<?php

// Standalone regression tests: php tests/product-search.php
define('_PS_VERSION_', '8.2.1');
define('_PS_MODULE_DIR_', dirname(__DIR__, 2) . '/');
define('_DB_PREFIX_', 'ps_');

class ObjectModel
{
    public const TYPE_INT = 1;
    public const TYPE_STRING = 2;
    public const TYPE_DATE = 3;
    public int $id;

    public function __construct(int $id)
    {
        $this->id = $id;
    }
}

class Validate
{
    public static function isLoadedObject(ObjectModel $object): bool
    {
        return $object->id === 1;
    }
}

class Module
{
    public function l(string $message): string
    {
        return $message;
    }
}

class Context
{
    public object $language;
    public object $shop;
    public object $currency;

    public static function getContext(): self
    {
        $context = new self();
        $context->language = (object) ['id' => 1];
        $context->shop = (object) ['id' => 1];
        $context->currency = (object) ['id' => 1];

        return $context;
    }
}

class Tools
{
    public static function strlen(string $value): int
    {
        return strlen($value);
    }

    public static function displayPrice(float $price, object $currency): string
    {
        return number_format($price, 2, '.', '') . ' EUR';
    }
}

function pSQL(string $value): string
{
    return str_replace('"', '""', $value);
}

class Db
{
    public PDO $pdo;
    public bool $failNext = false;
    private static ?self $instance = null;

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE ps_manufacturer (id_manufacturer INTEGER, name TEXT);
            CREATE TABLE ps_product (id_product INTEGER, reference TEXT, ean13 TEXT, id_manufacturer INTEGER);
            CREATE TABLE ps_product_shop (id_product INTEGER, id_shop INTEGER, active INTEGER);
            CREATE TABLE ps_product_lang (id_product INTEGER, id_shop INTEGER, id_lang INTEGER, name TEXT);
            CREATE TABLE ps_product_attribute (
                id_product_attribute INTEGER, id_product INTEGER, reference TEXT, ean13 TEXT, attribute_names TEXT
            );
            CREATE TABLE ps_customcatalogonpdf_tarif_line (
                id_tarif INTEGER, id_product INTEGER, id_product_attribute INTEGER
            );
            INSERT INTO ps_manufacturer VALUES (1, "Brand Alpha"), (2, "Brand Beta");
            INSERT INTO ps_product_attribute VALUES
                (21, 2, "VAR-RED", "EAN-RED", "Red"), (22, 2, "VAR-BLUE", "EAN-BLUE", "Blue");
            INSERT INTO ps_customcatalogonpdf_tarif_line VALUES (1, 1, 0), (1, 2, 21), (2, 3, 0);
        ');
        $product = $this->pdo->prepare('INSERT INTO ps_product VALUES (?, ?, ?, ?)');
        $shop = $this->pdo->prepare('INSERT INTO ps_product_shop VALUES (?, ?, ?)');
        $lang = $this->pdo->prepare('INSERT INTO ps_product_lang VALUES (?, ?, 1, ?)');
        for ($id = 1; $id <= 65; ++$id) {
            $product->execute([$id, 'REF-' . $id, 'EAN-' . $id, $id <= 40 ? 1 : 2]);
            $shopId = $id === 65 ? 2 : 1;
            $shop->execute([$id, $shopId, in_array($id, [1, 2, 3], true) ? 0 : 1]);
            $lang->execute([$id, $shopId, sprintf('Product %02d', $id)]);
        }
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function executeS(string $sql)
    {
        if ($this->failNext) {
            $this->failNext = false;

            return false;
        }
        // Only the existing MySQL-specific attribute aggregation needs an adapter.
        if (strpos($sql, 'GROUP_CONCAT(') !== false) {
            if (!preg_match('/WHERE pa.id_product = (\d+)/', $sql, $match)) {
                throw new RuntimeException('Unexpected combination query.');
            }
            $sql = 'SELECT id_product_attribute, reference, ean13, attribute_names
                    FROM ps_product_attribute WHERE id_product = ' . (int) $match[1] . '
                    ORDER BY id_product_attribute';
        }

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRow(string $sql)
    {
        return $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    }

    public function update(string $table, array $values, string $where): bool
    {
        if ($this->failNext) {
            $this->failNext = false;

            return false;
        }
        $assignments = implode(', ', array_map(static fn (string $key): string => $key . ' = ?', array_keys($values)));
        $statement = $this->pdo->prepare('UPDATE ps_' . $table . ' SET ' . $assignments . ' WHERE ' . $where);

        return $statement->execute(array_values($values));
    }
}

require dirname(__DIR__) . '/classes/TarifService.php';

class ProductSearchTestService extends TarifService
{
    public function getProductInfo(int $idProduct, int $idProductAttribute): ?array
    {
        return [
            'name' => 'Product ' . $idProduct,
            'reference' => 'REF-' . $idProduct,
            'attribute_names' => $idProductAttribute ? 'Variant ' . $idProductAttribute : '',
            'ean13' => '',
            'image_url' => '/image-' . $idProduct . '.jpg',
        ];
    }

    public function getBasePrice(int $idProduct, int $idProductAttribute): float
    {
        return 10 + $idProduct + $idProductAttribute / 100;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function checkThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $exception) {
        check($exception->getMessage() !== '', $message);

        return;
    }
    throw new RuntimeException($message);
}

$service = new ProductSearchTestService(new Module());
$results = $service->searchProducts('Product', 1);
$keys = array_column($results, 'id');
check(!in_array('1:0', $keys, true), 'Select excludes existing simple products.');
check(!in_array('2:21', $keys, true) && in_array('2:22', $keys, true), 'Select keeps only remaining variants.');
check(in_array('2:all', $keys, true) && strpos($results[0]['text'], '(1)') !== false, 'Global entry counts remaining variants.');
check(in_array('3:0', $keys, true), 'Another tariff does not exclude products.');
check(in_array('31:0', $keys, true), 'Exclusion happens before the 30-product limit.');
check($service->searchProducts('x', 1) === [], 'Select retains its minimum search length.');
check($service->searchProducts('Product 01', 1) === [], 'Fully added products produce no select options.');
check($service->searchProducts('VAR-BLUE', 1)[1]['id'] === '2:22', 'Variant reference search works.');
check($service->searchProducts('EAN-BLUE', 1)[1]['id'] === '2:22', 'Variant EAN search works.');
check(count($service->searchProducts('Brand Beta', 1)) === 24, 'Search accepts a brand name and respects the current shop.');
check($service->searchProducts('Product', 1, 2)[0]['id'] === '41:0', 'Select applies the manufacturer filter.');
check($service->searchProducts('Product 65', 1) === [], 'Products outside the current shop are absent.');
check(count($service->getProductManufacturers()) === 2, 'Manufacturer options are loaded.');
check(strpos($service->searchProducts('Product 03', 1)[0]['text'], '[Inactif]') !== false, 'Inactive simple products remain selectable and are labelled.');
$inactiveVariants = $service->searchProducts('Product 02', 1);
check(count($inactiveVariants) === 2
    && strpos($inactiveVariants[0]['text'], '[Inactif]') !== false
    && strpos($inactiveVariants[1]['text'], '[Inactif]') !== false, 'Inactive variants and global entries are labelled and remain selectable.');
check(strpos($service->searchProducts('Product 04', 1)[0]['text'], '[Inactif]') === false, 'Active select options are not labelled inactive.');

$first = $service->browseProducts(1, '', 0, 1);
check($first['page'] === 1 && $first['has_more'] && count($first['products']) === 25, 'First page includes 24 parents and their variants.');
$cards = array_column($first['products'], null, 'id');
check($cards['1:0']['in_tarif'] && $cards['2:21']['in_tarif'], 'Browser keeps existing products marked as present.');
check(!$cards['2:22']['in_tarif'] && !$cards['3:0']['in_tarif'], 'Browser keeps remaining products available.');
check($cards['3:0']['active'] === false && !$cards['3:0']['in_tarif'], 'Inactive simple cards remain available with explicit status.');
check($cards['2:22']['active'] === false && !$cards['2:22']['in_tarif'], 'Inactive variant cards remain available with explicit status.');
check($cards['1:0']['active'] === false && $cards['1:0']['in_tarif'], 'Inactive cards already in the tariff remain marked as present.');
check($cards['4:0']['active'] === true, 'Browser exposes active status as a boolean.');
check($cards['2:21']['id_product'] === 2 && $cards['2:22']['id_product'] === 2
    && $cards['2:22']['combination_count'] === 2, 'Variant cards expose the parent and total combination count for bulk addition.');
check($cards['3:0']['combination_count'] === 0, 'Simple products do not offer combination bulk addition.');
check($cards['2:22']['price'] === '12.22 EUR' && $cards['2:22']['image_url'] === '/image-2.jpg', 'Cards include variant price and photo.');
$missing = $service->browseProducts(1, '', 0, 1, true);
$missingKeys = array_column($missing['products'], 'id');
check(count($missingKeys) === 24 && $missing['has_more'], 'Missing-only filtering happens before pagination.');
check(!in_array('1:0', $missingKeys, true) && !in_array('2:21', $missingKeys, true)
    && in_array('2:22', $missingKeys, true), 'Missing-only mode hides existing targets but preserves remaining variants.');
check(in_array('25:0', $missingKeys, true), 'Missing-only pagination fills the page with available products.');
check($service->browseProducts(1, '', 0, 2, true)['products'][0]['id'] === '26:0', 'Missing-only second page has no gaps or overlap.');
$missingLast = $service->browseProducts(1, '', 0, 3, true);
check(count($missingLast['products']) === 15 && !$missingLast['has_more'], 'Missing-only last page terminates pagination correctly.');
check($service->browseProducts(1, 'Product 01', 0, 1, true)['products'] === [], 'Missing-only searches omit fully added products.');
$second = $service->browseProducts(1, '', 0, 2);
$third = $service->browseProducts(1, '', 0, 3);
check($second['products'][0]['id'] === '25:0' && count($second['products']) === 24, 'Second page has no overlaps or missing products.');
check(count($third['products']) === 16 && !$third['has_more'], 'Last page excludes lookahead and terminates pagination.');
check($service->browseProducts(1, '', 2, 1)['products'][0]['id'] === '41:0', 'Browser applies manufacturer filter.');
check(count($service->browseProducts(1, 'Brand Beta', 0, 1)['products']) === 24, 'Browser searches brand names.');
check($service->browseProducts(1, 'not-found', 0, 1)['products'] === [], 'No results returns an empty product list.');
check($service->browseProducts(1, '', 0, 0)['page'] === 1, 'Page numbers are bounded.');
checkThrows(fn () => $service->browseProducts(99, '', 0, 1), 'Invalid tariffs report an error.');
Db::getInstance()->pdo->exec('INSERT INTO ps_customcatalogonpdf_tarif_line VALUES (1, 2, 22)');
check($service->searchProducts('Product 02', 1) === [], 'Fully added variants remove the global select entry.');
$afterAdd = $service->browseProducts(1, 'Product 02', 0, 1);
check($afterAdd['products'][0]['in_tarif'] && $afterAdd['products'][1]['in_tarif'], 'Browser refresh reflects additions.');
check($service->browseProducts(1, 'Product 02', 0, 1, true)['products'] === [], 'Missing-only mode removes a product once all variants have been added.');
Db::getInstance()->pdo->exec('UPDATE ps_product SET id_manufacturer = 0 WHERE id_product IN (1, 2, 3, 65)');
Db::getInstance()->pdo->exec('UPDATE ps_product SET id_manufacturer = NULL WHERE id_product = 4');
$unbranded = $service->browseProducts(1, '', -1, 1);
check(array_column($unbranded['products'], 'id') === ['1:0', '2:21', '2:22', '3:0', '4:0'],
    'No-brand browser filter includes zero and null manufacturer IDs, variants and inactive products, within the current shop.');
check($unbranded['products'][0]['manufacturer_name'] === 'Sans marque', 'Unbranded cards explicitly display Sans marque.');
check(array_column($service->searchProducts('Product', 1, -1), 'id') === ['3:0', '4:0'],
    'No-brand select filter still excludes existing products and variants.');
check(array_column($service->browseProducts(1, '', -1, 1, true)['products'], 'id') === ['3:0', '4:0'],
    'No-brand and missing-only filters combine correctly.');
check(array_column($service->browseProducts(1, 'Product 04', -1, 1, true)['products'], 'id') === ['4:0'],
    'Text, no-brand and missing-only filters combine correctly.');
check($service->searchProducts('Product 03', 1, 1) === [], 'A named manufacturer filter excludes unbranded products.');
check($service->browseProducts(1, 'Product 03', 0, 1)['products'][0]['id'] === '3:0',
    'All-brands mode still includes unbranded products.');
Db::getInstance()->failNext = true;
checkThrows(fn () => $service->browseProducts(1, '', 0, 1), 'Database errors are surfaced.');

$db = Db::getInstance();
$db->pdo->exec('ALTER TABLE ps_customcatalogonpdf_tarif_line ADD COLUMN id_line INTEGER');
$db->pdo->exec('ALTER TABLE ps_customcatalogonpdf_tarif_line ADD COLUMN is_processed INTEGER NOT NULL DEFAULT 0');
$db->pdo->exec('ALTER TABLE ps_customcatalogonpdf_tarif_line ADD COLUMN reduction_percent REAL NOT NULL DEFAULT 0');
$db->pdo->exec('UPDATE ps_customcatalogonpdf_tarif_line SET id_line = rowid');
check($service->setLineProcessed(1, 1, true)['is_processed'] === true, 'Lines can be marked processed even at zero reduction.');
check((int) $db->getRow('SELECT is_processed FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1')['is_processed'] === 1,
    'Processed state is persisted in the database.');
check((float) $db->getRow('SELECT reduction_percent FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1')['reduction_percent'] === 0.0,
    'Marking a line does not alter its reduction.');
checkThrows(fn () => $service->setLineProcessed(2, 1, false), 'A line cannot be marked through a different tariff.');
checkThrows(fn () => $service->setLineProcessed(1, 999, true), 'Missing lines report an error.');
$db->failNext = true;
checkThrows(fn () => $service->setLineProcessed(1, 1, false), 'Saving failures are surfaced.');
check((int) $db->getRow('SELECT is_processed FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1')['is_processed'] === 1,
    'Failed updates preserve the previous processed state.');
check($service->setLineProcessed(1, 1, false)['is_processed'] === false, 'Lines can be unchecked.');
check((int) $db->getRow('SELECT is_processed FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1')['is_processed'] === 0,
    'Unchecked state is persisted.');
