<?php

require __DIR__ . '/tarif-validation.php';

function near(?float $actual, float $expected, string $message): void
{
    check($actual !== null && abs($actual - $expected) < 0.00001, $message);
}

function resetPublicPrices(): void
{
    $db = Db::getInstance();
    $db->reset();
    $db->execute('UPDATE ps_customcatalogonpdf_tarif_line
        SET base_price = 100, reduction_percent = 20, final_price = 80');
}

resetPublicPrices();
$line = $service->updatePublicPrice(1, 1, 'public_markup', 25);
near($line['public_price'], 100, 'A 25% markup on a net price of 80 gives a public price of 100.');
near($line['public_markup_percent'], 25, 'The markup is returned with the public price.');
$line = $service->updatePublicPrice(1, 1, 'public_price', 120);
near($line['public_markup_percent'], 50, 'Typing a public price of 120 recalculates the markup to 50%.');
$line = $service->updatePublicPrice(1, 1, 'public_markup', 33.333);
near($line['public_price'], 106.67, 'Public prices calculated from a markup are rounded to cents.');
$line = $service->updatePublicPrice(1, 1, 'public_price', 119.999);
near($line['public_price'], 120, 'Typed public prices are rounded to cents.');
$data = $service->getTarifData(1);
$loaded = $data['sections'][0]['lines'][0];
near($loaded['public_price'], 120, 'Reloaded lines expose the stored public price.');
near($loaded['public_markup_percent'], 50, 'Reloaded lines expose the markup against the net price.');
check($data['sections'][0]['lines'][1]['public_price'] === null
    && $data['sections'][0]['lines'][1]['public_markup_percent'] === null,
    'Lines without a public price expose empty values.');
check($data['show_public_price'] === false, 'Tariffs hide the public price in exports by default.');

$line = $service->updateLine(1, 1, 'price', 60);
near($line['public_price'], 120, 'Changing the net price keeps the shared public price.');
near($line['public_markup_percent'], 100, 'Changing the net price recalculates the markup.');
$line = $service->updateLine(1, 1, 'additional_reduction', 50);
near($line['public_markup_percent'], 300, 'Changing the additional discount recalculates the markup too.');

resetPublicPrices();
$service->updatePublicPrice(1, 1, 'public_price', 120);
$copy = $service->duplicate(1, 10);
$copyLine = (int) $db->getValue('SELECT id_line FROM ps_customcatalogonpdf_tarif_line WHERE id_tarif = '
    . $copy . ' AND id_product = 1');
$line = $service->updateLine($copy, $copyLine, 'price', 96);
near($line['public_price'], 120, 'Another tariff with the same product shows the same public price.');
near($line['public_markup_percent'], 25, 'Each tariff computes the markup from its own net price.');
$service->updatePublicPrice($copy, $copyLine, 'public_price', 150);
near($service->getTarifData(1)['sections'][0]['lines'][0]['public_price'], 150,
    'Editing the public price from one tariff updates every tariff with the product.');
near($service->getTarifData(1)['sections'][0]['lines'][0]['public_markup_percent'], 87.5,
    'The other tariff keeps its net price and recalculates its markup.');
check((int) $db->getValue('SELECT COUNT(*) FROM ps_customcatalogonpdf_public_price') === 1,
    'A public price is stored once per product or combination.');
$service->updatePublicPrice(1, 2, 'public_price', 90);
check((int) $db->getValue('SELECT COUNT(*) FROM ps_customcatalogonpdf_public_price
    WHERE id_product = 2 AND id_product_attribute = 21') === 1,
    'Combinations keep their own public price.');

$line = $service->updatePublicPrice(1, 1, 'public_price', null);
check($line['public_price'] === null && $line['public_markup_percent'] === null, 'An empty value clears the public price.');
check($service->getTarifData($copy)['sections'][0]['lines'][0]['public_price'] === null,
    'Clearing the public price applies to every tariff.');

resetPublicPrices();
$service->updatePublicPrice(1, 1, 'public_price', 100);
$before = $db->executeS('SELECT * FROM ps_customcatalogonpdf_public_price');
foreach ([0, -5, INF, NAN] as $value) {
    rejects(fn () => $service->updatePublicPrice(1, 1, 'public_price', $value), 'Invalid public prices are rejected.');
}
foreach ([-100, -150, NAN] as $value) {
    rejects(fn () => $service->updatePublicPrice(1, 1, 'public_markup', $value), 'Invalid markups are rejected.');
}
rejects(fn () => $service->updatePublicPrice(1, 1, 'final_price', 100), 'Unknown public price modes are rejected.');
rejects(fn () => $service->updatePublicPrice(2, 1, 'public_price', 100), 'A line cannot be changed through another tariff.');
$db->failure = 'update:customcatalogonpdf_public_price';
rejects(fn () => $service->updatePublicPrice(1, 1, 'public_price', 110), 'Persistence failures are surfaced.');
check($db->executeS('SELECT * FROM ps_customcatalogonpdf_public_price') === $before,
    'Rejected public prices leave the stored value untouched.');

$service->updateLine(1, 1, 'reduction', 100);
rejects(fn () => $service->updatePublicPrice(1, 1, 'public_markup', 20),
    'A markup cannot be applied to a free net price.');
$line = $service->updatePublicPrice(1, 1, 'public_price', 50);
check($line['public_price'] === 50.0 && $line['public_markup_percent'] === null,
    'A free net price accepts a typed public price without dividing by zero.');

resetPublicPrices();
$service->updatePublicPrice(1, 1, 'public_price', 99.9);
$dataset = $service->buildExportDataset(1);
check(count($dataset['headers']) === 5 && !in_array('public_price', array_column($dataset['headers'], 'key'), true)
    && $dataset['show_public_price'] === false,
    'Exports omit the public price when the tariff option is disabled.');
check($service->setShowPublicPrice(1, true) === true, 'The export option can be enabled.');
$dataset = $service->buildExportDataset(1);
$keys = array_column($dataset['headers'], 'key');
check(end($keys) === 'public_price' && $dataset['show_public_price'] === true
    && end($dataset['headers'])['numeric'] === true,
    'CSV and Excel exports add a numeric public price column when enabled.');
check($dataset['rows'][0]['public_price'] === 99.9 && $dataset['rows'][1]['public_price'] === null,
    'Export rows contain the public price, or an empty value without one.');
check(getTarifShowFlag($copy = $service->duplicate(1, 10)) === 1, 'Duplication keeps the export option.');
$service->setShowPublicPrice(1, false);
check(getTarifShowFlag(1) === 0, 'The export option can be disabled again.');
rejects(fn () => $service->setShowPublicPrice(999, true), 'The export option requires an existing tariff.');

function getTarifShowFlag(int $idTarif): int
{
    return (int) Db::getInstance()->getValue('SELECT show_public_price FROM ps_customcatalogonpdf_tarif WHERE id_tarif = ' . $idTarif);
}

require dirname(__DIR__) . '/upgrade/upgrade-1.7.0.php';
$db->reset();
$db->execute('DROP TABLE ps_customcatalogonpdf_public_price');
$db->execute('ALTER TABLE ps_customcatalogonpdf_tarif DROP COLUMN show_public_price');
$before = $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line');
check(upgrade_module_1_7_0(null) && upgrade_module_1_7_0(null), 'The public price migration is idempotent.');
check(getTarifShowFlag(1) === 0 && $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line') === $before,
    'Migration hides the public price in existing exports and leaves the lines untouched.');
$service->updatePublicPrice(1, 1, 'public_price', 100);
check((int) $db->getValue('SELECT COUNT(*) FROM ps_customcatalogonpdf_public_price') === 1,
    'Migration creates the shared public price table.');
$db->failure = 'read';
check(!upgrade_module_1_7_0(null), 'Migration reports schema inspection failures.');
