<?php

require __DIR__ . '/tarif-validation.php';

function closeTo(float $actual, float $expected, string $message): void
{
    check(abs($actual - $expected) < 0.00001, $message);
}

function resetDiscounts(): void
{
    $db = Db::getInstance();
    $db->reset();
    $db->execute('UPDATE ps_customcatalogonpdf_tarif_line
        SET base_price = 100, reduction_percent = 20, final_price = 80');
}

resetDiscounts();
$line = $service->updateLine(1, 1, 'additional_reduction', 10);
closeTo($line['intermediate_price'], 80, 'The first 20% discount produces an intermediate price of 80.');
closeTo($line['final_price'], 72, 'The second 10% discount applies to 80, producing a net price of 72.');
closeTo($line['total_reduction_percent'], 28, 'The combined discount is 28%, not the sum of 30%.');
$data = $service->getTarifData(1);
$loaded = $data['sections'][0]['lines'][0];
check($loaded['additional_reduction_percent'] === 10.0 && $loaded['final_price'] === 72.0
    && $loaded['total_reduction_percent'] === 28.0 && $loaded['intermediate_price'] === 80.0,
    'Reloaded lines expose both discounts, both prices and the calculated total.');

$line = $service->updateLine(1, 1, 'price', 63);
closeTo($line['reduction_percent'], 30, 'Editing the net price to 63 recalculates the first discount to 30%.');
closeTo($line['additional_reduction_percent'], 10, 'Editing the net price preserves the second discount.');
closeTo($line['intermediate_price'], 70, 'Editing the net price recalculates the intermediate price to 70.');
closeTo($line['final_price'], 63, 'The persisted net price matches the direct input.');
$line = $service->updateLine(1, 1, 'reduction', 25);
closeTo($line['final_price'], 67.5, 'Editing the first discount reapplies the existing second discount.');
closeTo($line['additional_reduction_percent'], 10, 'The second discount survives first-discount edits.');
$line = $service->updateLine(1, 1, 'additional_reduction', 0);
closeTo($line['final_price'], 75, 'Removing the additional discount restores the intermediate price.');
closeTo($line['total_reduction_percent'], 25, 'A zero additional discount preserves single-discount behavior.');

resetDiscounts();
$db->execute('UPDATE ps_customcatalogonpdf_tarif_line SET id_section = 7 WHERE id_line = 2');
$section = $service->applySectionReduction(1, 0, 10, 'additional_reduction');
check(count($section) === 1 && $section[0]['final_price'] === 72.0, 'The second discount applies to the unsectioned lines only.');
closeTo((float) $db->getValue('SELECT additional_reduction_percent FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 2'),
    0, 'Lines outside the chosen section remain unchanged.');
$section = $service->applySectionReduction(1, 0, 30);
check($section[0]['additional_reduction_percent'] === 10.0 && $section[0]['final_price'] === 63.0,
    'Section first-discount updates keep the additional discount.');
$section = $service->applySectionReduction(1, 7, 5, 'additional_reduction');
check(count($section) === 1 && $section[0]['final_price'] === 76.0, 'The second discount works on a named section.');
$before = $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line');
$db->failure = 'update:customcatalogonpdf_tarif_line';
rejects(fn () => $service->applySectionReduction(1, 7, 15, 'additional_reduction'),
    'Section persistence failures are surfaced.');
check($db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line') === $before,
    'Failed section updates retain the persisted discounts.');

resetDiscounts();
$before = $db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line');
$db->lineWritesBeforeFailure = 1;
rejects(fn () => $service->applySectionReduction(1, 0, 10, 'additional_reduction'),
    'An error on the second line of a section is reported.');
check($db->executeS('SELECT * FROM ps_customcatalogonpdf_tarif_line') === $before,
    'Section updates roll back earlier lines when a later line cannot be saved.');

resetDiscounts();
$db->execute('UPDATE ps_customcatalogonpdf_tarif_line SET base_price = 19.99 WHERE id_line = 1');
$service->updateLine(1, 1, 'reduction', 17.125);
$line = $service->updateLine(1, 1, 'additional_reduction', 7.5);
closeTo($line['intermediate_price'], 16.57, 'Fractional first discounts round the intermediate price to cents.');
closeTo($line['final_price'], 15.33, 'The additional discount uses the rounded intermediate price.');
closeTo($line['total_reduction_percent'], 23.3117, 'The combined percentage is derived from the actual rounded net price.');

resetDiscounts();
$service->updateLine(1, 1, 'additional_reduction', 10);
$service->basePrice = 200;
$service->refreshPrices(1);
$row = $db->getRow('SELECT * FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1');
check((float) $row['reduction_percent'] === 20.0 && (float) $row['additional_reduction_percent'] === 10.0
    && (float) $row['final_price'] === 144.0, 'Price refresh preserves both percentages and recomputes the net price.');
$service->basePrice = 100;
$service->syncPrices(1);
$row = $db->getRow('SELECT * FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1');
check((float) $row['final_price'] === 144.0 && (float) $row['additional_reduction_percent'] === 10.0
    && (float) $row['reduction_percent'] === -60.0,
    'Synchronization keeps the net price and additional discount, adjusting the first discount only.');

resetDiscounts();
$service->updateLine(1, 1, 'additional_reduction', 10);
$copy = $service->duplicate(1, 10);
$row = $db->getRow('SELECT * FROM ps_customcatalogonpdf_tarif_line WHERE id_tarif = ' . $copy . ' AND id_product = 1');
check((float) $row['reduction_percent'] === 20.0 && (float) $row['additional_reduction_percent'] === 10.0
    && (float) $row['final_price'] === 72.0, 'Duplication retains both discounts and the resulting net price.');
$added = $service->addLine(1, 8, 0, 0);
check($added['additional_reduction_percent'] === 0.0 && $added['final_price'] === 90.0,
    'New lines start with no additional discount and preserve the current client price.');
$dataset = $service->buildExportDataset(1);
check($dataset['rows'][0]['final_price'] === 72.0 && count($dataset['headers']) === 5,
    'CSV and Excel datasets use the net after both discounts without adding internal discount columns.');

foreach (['customer', 'group'] as $target) {
    resetDiscounts();
    $service->updateLine(1, 1, 'additional_reduction', 10);
    $prepared = $service->prepareValidation(1, $target);
    $service->validate(1, $target, $prepared['id_target'], $prepared['confirmation_token']);
    $rule = $db->getRow('SELECT * FROM ps_specific_price WHERE id_product = 1 AND id_customer = '
        . ($target === 'customer' ? 10 : 0) . ' AND id_group = ' . ($target === 'group' ? 3 : 0));
    closeTo((float) $rule['reduction'], 0.28, $target . ': validation applies the calculated global 28% discount.');
}

resetDiscounts();
$service->updateLine(1, 1, 'additional_reduction', 100);
$line = $service->updateLine(1, 1, 'price', 0);
check($line['reduction_percent'] === 20.0 && $line['final_price'] === 0.0 && $line['total_reduction_percent'] === 100.0,
    'A 100% additional discount gives a free net price without dividing by zero.');
rejects(fn () => $service->updateLine(1, 1, 'price', 1), 'A positive net price with 100% additional discount is rejected.');
$service->basePrice = 200;
$service->syncPrices(1);
closeTo((float) $db->getValue('SELECT final_price FROM ps_customcatalogonpdf_tarif_line WHERE id_line = 1'), 0,
    'Synchronization preserves zero net prices with a 100% additional discount.');
$service->basePrice = 100;

foreach ([-1, 100.01, INF, NAN] as $value) {
    rejects(fn () => $service->updateLine(1, 1, 'additional_reduction', $value), 'Invalid additional discounts are rejected.');
}
rejects(fn () => $service->updateLine(1, 1, 'total_reduction', 50), 'The total discount has no directly editable API mode.');
rejects(fn () => $service->updateLine(2, 1, 'additional_reduction', 10), 'A line cannot be changed through a different tariff.');
rejects(fn () => $service->applySectionReduction(1, 0, -1, 'additional_reduction'), 'Invalid section discounts are rejected.');

resetDiscounts();
$prepared = $service->prepareValidation(1, 'group');
$db->execute('UPDATE ps_customcatalogonpdf_tarif_line SET additional_reduction_percent = 10 WHERE id_line = 1');
rejects(fn () => $service->validate(1, 'group', 3, $prepared['confirmation_token']),
    'Changing a component discount invalidates a pending confirmation even if the stored net has not changed.');

require dirname(__DIR__) . '/upgrade/upgrade-1.6.0.php';
$db->reset();
$before = $db->executeS('SELECT id_line, final_price, reduction_percent FROM ps_customcatalogonpdf_tarif_line');
$db->execute('ALTER TABLE ps_customcatalogonpdf_tarif_line DROP COLUMN additional_reduction_percent');
check(upgrade_module_1_6_0(null) && upgrade_module_1_6_0(null), 'The additional-discount migration is idempotent.');
check($db->executeS('SELECT id_line, final_price, reduction_percent FROM ps_customcatalogonpdf_tarif_line') === $before
    && (float) $db->getValue('SELECT SUM(additional_reduction_percent) FROM ps_customcatalogonpdf_tarif_line') === 0.0,
    'Migration initializes the additional discount to zero without changing existing prices or first discounts.');
$db->failure = 'read';
check(!upgrade_module_1_6_0(null), 'Migration reports schema inspection failures.');
