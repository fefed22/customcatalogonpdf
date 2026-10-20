<?php

define('_PS_VERSION_', '8.2.1');
define('_PS_MODULE_DIR_', dirname(__DIR__, 2) . '/');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');
define('_COOKIE_KEY_', 'test-only-key');

class ObjectModel
{
    public const TYPE_INT = 1;
    public const TYPE_STRING = 2;
    public const TYPE_DATE = 3;
    public int $id = 0;

    public function __construct(int $id = 0)
    {
        $row = Db::getInstance()->executeS('SELECT * FROM ps_customcatalogonpdf_tarif WHERE id_tarif = ' . $id)[0] ?? null;
        if ($row) {
            $this->id = $id;
            foreach ($row as $key => $value) {
                if ($key !== 'id_tarif') {
                    $this->$key = $value;
                }
            }
        }
    }

    public function update(): bool
    {
        return Db::getInstance()->update('customcatalogonpdf_tarif', [
            'status' => $this->status,
            'date_validated' => $this->date_validated,
        ], 'id_tarif = ' . $this->id);
    }
}

class Validate
{
    public static function isLoadedObject(object $object): bool
    {
        return $object->id > 0;
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
    public object $employee;
    private static ?self $instance = null;

    public static function getContext(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
            self::$instance->language = (object) ['id' => 1];
            self::$instance->employee = (object) ['id' => 8, 'firstname' => 'Alice', 'lastname' => 'Admin'];
        }

        return self::$instance;
    }
}

class Tools
{
    public static array $request = [];

    public static function getValue(string $name, $default = false)
    {
        return self::$request[$name] ?? $default;
    }
}

class Product
{
    public static function flushPriceCache(): void
    {
    }
}

function pSQL(string $value): string
{
    return str_replace("'", "''", $value);
}

class Db
{
    public PDO $pdo;
    public string $failure = '';
    private static ?self $instance = null;

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public function reset(): void
    {
        $this->failure = '';
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('
            CREATE TABLE ps_customcatalogonpdf_tarif (
                id_tarif INTEGER PRIMARY KEY, id_customer INTEGER, status INTEGER,
                date_validated TEXT, date_add TEXT, date_upd TEXT, name TEXT
            );
            INSERT INTO ps_customcatalogonpdf_tarif VALUES (1, 10, 0, NULL, "", "", "Test");
            CREATE TABLE ps_customer (
                id_customer INTEGER, id_default_group INTEGER, firstname TEXT, lastname TEXT, deleted INTEGER
            );
            INSERT INTO ps_customer VALUES (10, 3, "Bob", "Client", 0);
            CREATE TABLE ps_group (id_group INTEGER, name TEXT);
            INSERT INTO ps_group VALUES (3, "Professionnels");
            CREATE TABLE ps_group_lang (id_group INTEGER, id_lang INTEGER, name TEXT);
            INSERT INTO ps_group_lang VALUES (3, 1, "Professionnels");
            CREATE TABLE ps_customcatalogonpdf_tarif_line (
                id_line INTEGER PRIMARY KEY, id_tarif INTEGER, id_product INTEGER,
                id_product_attribute INTEGER, final_price NUMERIC,
                base_price NUMERIC DEFAULT 80, group_reduction_percent NUMERIC DEFAULT 0,
                current_price NUMERIC DEFAULT 80, reduction_percent NUMERIC DEFAULT 20
            );
            INSERT INTO ps_customcatalogonpdf_tarif_line (id_line, id_tarif, id_product, id_product_attribute, final_price)
                VALUES (1, 1, 1, 0, 80), (2, 1, 2, 21, 70);
            CREATE TABLE ps_customcatalogonpdf_tarif_validation (
                id_validation INTEGER PRIMARY KEY AUTOINCREMENT, id_tarif INTEGER,
                target_type TEXT, id_target INTEGER, target_name TEXT, id_employee INTEGER,
                employee_name TEXT, line_count INTEGER, date_add TEXT
            );
            CREATE TABLE ps_specific_price (
                id_specific_price INTEGER PRIMARY KEY AUTOINCREMENT,
                id_specific_price_rule INTEGER DEFAULT 0, id_cart INTEGER DEFAULT 0,
                id_product INTEGER, id_shop INTEGER DEFAULT 0, id_shop_group INTEGER DEFAULT 0,
                id_currency INTEGER DEFAULT 0, id_country INTEGER DEFAULT 0,
                id_group INTEGER DEFAULT 0, id_customer INTEGER DEFAULT 0,
                id_product_attribute INTEGER DEFAULT 0, price NUMERIC DEFAULT -1,
                from_quantity INTEGER DEFAULT 1, reduction NUMERIC, reduction_tax INTEGER DEFAULT 0,
                reduction_type TEXT DEFAULT "percentage", "from" TEXT, "to" TEXT
            );
            INSERT INTO ps_specific_price (id_product, id_product_attribute, id_customer, id_group, reduction) VALUES
                (1, 0, 10, 0, 0.05), (2, 21, 10, 0, 0.05),
                (1, 0, 0, 3, 0.05), (2, 21, 0, 3, 0.05),
                (9, 0, 10, 0, 0.15), (9, 0, 0, 3, 0.15),
                (2, 22, 10, 0, 0.25), (2, 22, 0, 3, 0.25),
                (1, 0, 11, 0, 0.35), (1, 0, 0, 4, 0.35);
        ');
    }

    private function fails(string $operation): bool
    {
        if ($this->failure === $operation) {
            $this->failure = '';
            return true;
        }

        return false;
    }

    public function execute(string $sql): bool
    {
        if ($this->fails($sql)) {
            return false;
        }
        if ($sql === 'START TRANSACTION') {
            return $this->pdo->beginTransaction();
        }
        if ($sql === 'COMMIT') {
            return $this->pdo->commit();
        }
        if ($sql === 'ROLLBACK') {
            return $this->pdo->rollBack();
        }
        if (strpos($sql, 'CREATE TABLE IF NOT EXISTS') === 0) {
            $sql = str_replace('INT(10) UNSIGNED', 'INTEGER', $sql);
            $sql = str_replace(' AUTO_INCREMENT', '', $sql);
            $sql = preg_replace('/,\s*KEY `tarif_history` \([^)]*\)/', '', $sql);
            $sql = str_replace(' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', '', $sql);
        }

        return $this->pdo->exec($sql) !== false;
    }

    public function executeS(string $sql, bool $array = true, bool $useCache = true)
    {
        if (str_contains($sql, 'FOR UPDATE') && $useCache) {
            throw new RuntimeException('Locking reads must bypass the PrestaShop query cache.');
        }
        if ($this->fails('read')) {
            return false;
        }

        return $this->pdo->query(str_replace(' FOR UPDATE', '', $sql))->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getValue(string $sql)
    {
        return $this->pdo->query($sql)->fetchColumn();
    }

    public function delete(string $table, string $where): bool
    {
        return !$this->fails('delete') && $this->execute('DELETE FROM ps_' . $table . ' WHERE ' . $where);
    }

    public function insert(string $table, array $data): bool
    {
        if ($this->fails('insert:' . $table)) {
            return false;
        }
        $columns = array_map(static fn (string $key): string => '`' . $key . '`', array_keys($data));
        $statement = $this->pdo->prepare('INSERT INTO ps_' . $table . ' (' . implode(',', $columns)
            . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')');

        return $statement->execute(array_values($data));
    }

    public function update(string $table, array $data, string $where): bool
    {
        if ($this->fails('update:' . $table)) {
            return false;
        }
        $columns = array_map(static fn (string $key): string => '`' . $key . '` = ?', array_keys($data));
        $statement = $this->pdo->prepare('UPDATE ps_' . $table . ' SET ' . implode(',', $columns) . ' WHERE ' . $where);

        return $statement->execute(array_values($data));
    }
}

require dirname(__DIR__) . '/classes/TarifService.php';

class TestTarifService extends TarifService
{
    public float $basePrice = 100;
    public float $groupPrice = 90;

    public function getBasePrice(int $idProduct, int $idProductAttribute): float
    {
        return $this->basePrice;
    }

    public function getGroupPrice(int $idProduct, int $idProductAttribute, int $idGroup): float
    {
        return $this->groupPrice;
    }

    public function getCurrentPrice(int $idProduct, int $idProductAttribute, int $idCustomer): float
    {
        return 90;
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo 'PASS: ' . $message . PHP_EOL;
}

function rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}

function state(): array
{
    $db = Db::getInstance();
    return [
        $db->executeS('SELECT * FROM ps_specific_price ORDER BY id_specific_price'),
        $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif'),
        $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_validation ORDER BY id_validation'),
    ];
}

$db = Db::getInstance();
$db->reset();
$service = new TestTarifService(new Module());
$before = state();
$prepared = $service->prepareValidation(1, 'group');
check($prepared['id_target'] === 3 && $prepared['target_name'] === 'Professionnels' && $prepared['line_count'] === 2,
    'Group preparation names the default customer group and the affected line count.');
check(state() === $before, 'Preparation does not write prices, status or history.');

foreach (['customer' => [10, 0], 'group' => [0, 3]] as $target => [$customer, $group]) {
    $db->reset();
    $prepared = $service->prepareValidation(1, $target);
    $before = state();
    $count = $service->validate(1, $target, $prepared['id_target'], $prepared['confirmation_token']);
    check($count === 2, $target . ': both negotiated prices are written.');
    $rules = $db->executeS('SELECT * FROM ps_specific_price WHERE id_customer = ' . $customer
        . ' AND id_group = ' . $group . ' AND (id_product = 1 OR (id_product = 2 AND id_product_attribute = 21))');
    check(count($rules) === 2 && abs((float) $rules[0]['reduction'] - 0.2) < 0.000001
        && abs((float) $rules[1]['reduction'] - 0.3) < 0.000001,
        $target . ': old discounts are replaced rather than accumulated.');
    $untouched = array_slice($before[0], 4);
    foreach ($untouched as $rule) {
        check($db->executeS('SELECT * FROM ps_specific_price WHERE id_specific_price = ' . $rule['id_specific_price'])[0] === $rule,
            $target . ': products, combinations and targets outside the selection stay unchanged.');
    }
    $other = $target === 'customer' ? [0, 3] : [10, 0];
    check((float) $db->getValue('SELECT reduction FROM ps_specific_price WHERE id_product = 1 AND id_customer = '
        . $other[0] . ' AND id_group = ' . $other[1]) === 0.05,
        $target . ': the opposite validation target is never overwritten.');
    $history = $service->getValidationHistory(1);
    check(count($history) === 1 && $history[0]['target_type'] === $target
        && (int) $history[0]['id_target'] === $prepared['id_target']
        && $history[0]['target_name'] === $prepared['target_name']
        && $history[0]['employee_name'] === 'Alice Admin'
        && (int) $history[0]['line_count'] === 2
        && $history[0]['date_add'] === $db->getValue('SELECT date_validated FROM ps_customcatalogonpdf_tarif'),
        $target . ': history records the target snapshot, employee, line count and validation date.');
    $after = state();
    rejects(fn () => $service->validate(1, $target, $prepared['id_target'], $prepared['confirmation_token']),
        $target . ': replaying an already committed confirmation is rejected.');
    check(state() === $after, $target . ': replay rejection leaves every persisted value unchanged.');
}

$db->reset();
foreach (['customer', 'group', 'customer'] as $target) {
    $prepared = $service->prepareValidation(1, $target);
    $service->validate(1, $target, $prepared['id_target'], $prepared['confirmation_token']);
}
check(array_column($service->getValidationHistory(1), 'target_type') === ['customer', 'group', 'customer'],
    'Repeated validations append to the complete history, newest first.');

foreach (['customer', 'group'] as $target) {
    $db->reset();
    $db->execute('INSERT INTO ps_specific_price (id_product, id_customer, id_group, reduction) VALUES (1, 10, 3, 0.1)');
    $prepared = $service->prepareValidation(1, $target);
    $service->validate(1, $target, $prepared['id_target'], $prepared['confirmation_token']);
    $remaining = (int) $db->getValue('SELECT COUNT(*) FROM ps_specific_price WHERE id_product = 1 AND id_customer = 10 AND id_group = 3');
    check($remaining === ($target === 'customer' ? 0 : 1),
        $target . ': a rule scoped to both client and group is only replaced by client validation.');
}

foreach (['delete', 'insert:specific_price', 'insert:customcatalogonpdf_tarif_validation',
    'update:customcatalogonpdf_tarif', 'COMMIT'] as $failure) {
    $db->reset();
    $prepared = $service->prepareValidation(1, 'group');
    $before = state();
    $db->failure = $failure;
    rejects(fn () => $service->validate(1, 'group', 3, $prepared['confirmation_token']),
        $failure . ': persistence errors are reported.');
    check(state() === $before, $failure . ': prices, status and history are rolled back together.');
}

foreach ([
    'lines changed' => 'UPDATE ps_customcatalogonpdf_tarif_line SET final_price = 75 WHERE id_line = 1',
    'default group changed' => 'UPDATE ps_customer SET id_default_group = 99',
    'customer removed' => 'UPDATE ps_customer SET deleted = 1',
] as $scenario => $sql) {
    $db->reset();
    $prepared = $service->prepareValidation(1, 'group');
    $db->execute($sql);
    $before = state();
    rejects(fn () => $service->validate(1, 'group', 3, $prepared['confirmation_token']), $scenario . ': confirmation is rejected.');
    check(state() === $before, $scenario . ': no prices are changed.');
}

$db->reset();
rejects(fn () => $service->validate(1, 'customer', 10, ''), 'A missing preparation token is rejected.');
$prepared = $service->prepareValidation(1, 'customer');
rejects(fn () => $service->validate(1, 'group', 3, $prepared['confirmation_token']), 'A client token cannot validate a group.');
rejects(fn () => $service->validate(1, 'customer', 11, $prepared['confirmation_token']), 'A different target ID is rejected.');
rejects(fn () => $service->prepareValidation(1, 'anything'), 'Unknown validation targets are rejected.');
rejects(fn () => $service->prepareValidation(99, 'customer'), 'Missing tariffs are rejected.');
$db->execute('DELETE FROM ps_customcatalogonpdf_tarif_line');
rejects(fn () => $service->prepareValidation(1, 'customer'), 'Empty tariffs cannot be validated.');

$db->reset();
$service->groupPrice = 80;
$prepared = $service->prepareValidation(1, 'customer');
check($service->validate(1, 'customer', 10, $prepared['confirmation_token']) === 1,
    'Customer validation preserves native group inheritance when the negotiated price matches the group price.');
check(!$db->getValue('SELECT COUNT(*) FROM ps_specific_price WHERE id_product = 1 AND id_customer = 10'),
    'An obsolete client override is removed when the group price applies.');

$db->reset();
$service->basePrice = 0;
$prepared = $service->prepareValidation(1, 'group');
$before = state();
rejects(fn () => $service->validate(1, 'group', 3, $prepared['confirmation_token']), 'A non-computable discount is rejected explicitly.');
check(state() === $before, 'An invalid price leaves the old discounts and status untouched.');
$service->basePrice = 100;

require dirname(__DIR__) . '/upgrade/upgrade-1.5.0.php';
$db->reset();
$db->execute('UPDATE ps_customcatalogonpdf_tarif SET status = 1, date_validated = "2026-10-01 12:30:00"');
$db->execute('DROP TABLE ps_customcatalogonpdf_tarif_validation');
check(upgrade_module_1_5_0(null) && upgrade_module_1_5_0(null), 'History migration can run twice.');
$history = $service->getValidationHistory(1);
check(count($history) === 1 && $history[0]['target_type'] === 'legacy'
    && (int) $history[0]['id_target'] === 0 && $history[0]['date_add'] === '2026-10-01 12:30:00',
    'Legacy migration preserves the known date without inventing a historical target.');

$service->groupPrice = 90;
$db->reset();
$prepared = $service->prepareValidation(1, 'group');
$service->validate(1, 'group', 3, $prepared['confirmation_token']);
$before = state();
check($service->syncPrices(1) === 2, 'Group-only tariffs still synchronize their document prices.');
check(state()[0] === $before[0], 'Synchronization after group-only validation changes neither client nor group specific prices.');

class ModuleAdminController
{
    public bool $validToken = true;
    public bool $canEdit = true;
    public array $response = [];
    public array $errors = [];

    public function l(string $message): string
    {
        return $message;
    }

    public function checkToken(): bool
    {
        return $this->validToken;
    }

    public function access(string $action): bool
    {
        return $this->canEdit;
    }

    public function ajaxRender(string $json): void
    {
        $this->response = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

require dirname(__DIR__) . '/controllers/admin/AdminCustomCatalogTarifController.php';
$controller = (new ReflectionClass(AdminCustomCatalogTarifController::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($controller, 'service'))->setValue($controller, $service);
$db->reset();
Tools::$request = ['id_tarif' => 1, 'validation_target' => 'group', 'id_target' => 3];
$_SERVER['REQUEST_METHOD'] = 'POST';
$controller->ajaxProcessPrepareTarifValidation();
check($controller->response['success'] && !isset($controller->response['lines']),
    'The preparation route returns the confirmed target and token without exposing the line dataset.');
Tools::$request['confirmation_token'] = $controller->response['confirmation_token'];
$before = state();
foreach (['GET', 'token', 'permission', 'second-confirmation'] as $failure) {
    $_SERVER['REQUEST_METHOD'] = $failure === 'GET' ? 'GET' : 'POST';
    $controller->validToken = $failure !== 'token';
    $controller->canEdit = $failure !== 'permission';
    Tools::$request['confirmed'] = $failure === 'second-confirmation' ? 0 : 1;
    $controller->ajaxProcessValidateTarif();
    check($controller->response['success'] === false && state() === $before,
        $failure . ': the route rejects unauthorized or incomplete validation without modifying prices.');
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$controller->validToken = true;
$controller->canEdit = true;
Tools::$request['confirmed'] = 1;
$controller->ajaxProcessValidateTarif();
check($controller->response['success'] && $controller->response['count'] === 2,
    'The confirmed POST route persists the group validation.');
Tools::$request = ['id_tarif' => 1, 'validatetarif' => 1];
$before = state();
$controller->postProcess();
check(count($controller->errors) === 1 && state() === $before, 'The legacy direct-validation URL no longer writes prices.');
