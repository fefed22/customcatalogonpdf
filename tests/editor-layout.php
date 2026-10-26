<?php

require __DIR__ . '/tarif-validation.php';

$db->reset();
$first = $service->addSection(1, 'Première');
$second = $service->addSection(1, 'Seconde');
check($second['position'] === 0, 'A new section is created at the first position.');
$titles = array_column($service->getTarifData(1)['sections'], 'title');
check($titles === ['Seconde', 'Première', ''], 'New sections appear before existing sections, "Sans section" stays last.');
$service->reorderSections(1, [$first['id_section'], $second['id_section']]);
$third = $service->addSection(1, 'Troisième');
$titles = array_column($service->getTarifData(1)['sections'], 'title');
check($titles === ['Troisième', 'Première', 'Seconde', ''], 'A new section goes on top while keeping a manual section order.');
check((int) $db->getValue('SELECT COUNT(*) FROM ps_customcatalogonpdf_tarif_section WHERE id_tarif = 1') === 3,
    'Each call creates exactly one section.');
$db->failure = 'insert:customcatalogonpdf_tarif_section';
rejects(fn () => $service->addSection(1, 'Échec'), 'Section creation failures are reported.');
