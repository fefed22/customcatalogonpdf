<?php
/**
 * Génération de catalogues produits en PDF.
 * Utilise TCPDF (disponible via Composer dans PS8).
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// Sous-classe TCPDF avec header/footer personnalisés
// ─────────────────────────────────────────────────────────────────────────────

class CustomCatalogTCPDF extends TCPDF
{
    /** Données à afficher dans le header (pages > 1) */
    public array $catalogHeaderInfo = [];

    /** Données à afficher dans le footer */
    public array $catalogFooterInfo = [];

    /**
     * Header : logo à gauche + infos couverture à droite.
     * Pas affiché sur la page de couverture (page 1).
     */
    public function Header(): void
    {
        if ($this->getPage() <= 1) {
            return;
        }

        $info   = $this->catalogHeaderInfo;
        $pageW  = $this->getPageWidth();
        $margin = $this->getOriginalMargins();

        // Logo boutique
        if (!empty($info['logo_path']) && file_exists($info['logo_path'])) {
            $this->Image($info['logo_path'], $margin['left'], 4, 28, 0, '', '', 'T', false, 300);
        }

        // Infos à droite (titre, mention prix, client, date)
        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(110, 110, 110);
        $y = 4;
        foreach ($info['lines'] ?? [] as $line) {
            if ($line === '') {
                continue;
            }
            $this->SetXY($pageW - $margin['right'] - 72, $y);
            $this->Cell(72, 4, $line, 0, 0, 'R');
            $y += 4;
        }

        // Ligne de séparation
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line($margin['left'], 22, $pageW - $margin['right'], 22);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.2);
    }

    /**
     * Footer : informations société + numéro de page.
     * Pas affiché sur la page de couverture (page 1).
     */
    public function Footer(): void
    {
        if ($this->getPage() <= 1) {
            return;
        }

        $info  = $this->catalogFooterInfo;
        $pageW = $this->getPageWidth();
        $margin = $this->getOriginalMargins();

        $this->SetY(-16);
        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line($margin['left'], $this->getPageHeight() - 16, $pageW - $margin['right'], $this->getPageHeight() - 16);
        $this->SetLineWidth(0.2);
        $this->SetDrawColor(0);

        $this->SetFont('helvetica', '', 7);
        $this->SetTextColor(130, 130, 130);
        $this->SetX($margin['left']);

        $shop_line = $info['shop_line'] ?? '';
        $this->Cell($pageW - $margin['left'] - $margin['right'] - 20, 5, $shop_line, 0, 0, 'L');

        // Numéro de page relatif (sans la couverture)
        $page_rel = $this->getPage() - 1;
        $this->Cell(20, 5, 'Page ' . $page_rel, 0, 0, 'R');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Générateur principal
// ─────────────────────────────────────────────────────────────────────────────

class CatalogPdfGenerator
{
    private Context $context;

    /** Fichiers temporaires créés (conversion webp→jpg) à nettoyer */
    private array $tempFiles = [];

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Point d'entrée public
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Génère le PDF du profil et le renvoie en téléchargement direct.
     */
    public function generate(int $id_profile): void
    {
        $profile = new CustomCatalogProfile($id_profile);
        if (!Validate::isLoadedObject($profile)) {
            throw new RuntimeException('Profil introuvable (id=' . $id_profile . ').');
        }

        $id_lang     = (int) $this->context->language->id;
        $products    = $this->getProducts($profile, $id_lang);
        $by_category = $this->groupByCategory($products);
        $shop_info   = $this->getShopInfo();
        $customer    = $profile->id_customer > 0 ? new Customer((int) $profile->id_customer) : null;

        $pdf = $this->buildPdf($profile, $by_category, $shop_info, $customer, $id_lang);

        // Vider tout buffer ouvert avant d'envoyer le binaire PDF
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $filename = 'catalogue_' . Tools::link_rewrite($profile->name) . '_' . date('Ymd') . '.pdf';
        $pdf->Output($filename, 'D');

        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }

        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Récupération des produits
    // ─────────────────────────────────────────────────────────────────────────

    private function getProducts(CustomCatalogProfile $profile, int $id_lang): array
    {
        $manufacturer_ids = $profile->getManufacturerIds();
        $where_manufacturer = '';
        if (!empty($manufacturer_ids)) {
            $where_manufacturer = ' AND p.id_manufacturer IN (' . implode(',', array_map('intval', $manufacturer_ids)) . ')';
        }

        $query = '
            SELECT
                p.id_product,
                pl.name                                                         AS product_name,
                pa.id_product_attribute,
                GROUP_CONCAT(DISTINCT agl.name ORDER BY agl.name SEPARATOR " / ") AS attribute_names,
                COALESCE(NULLIF(pa.reference, ""), p.reference)                 AS product_reference,
                p.reference                                                     AS product_reference_base,
                cl.name                                                         AS default_category_name,
                p.id_category_default,
                ml.name                                                         AS manufacturer_name,
                p.id_manufacturer,
                img.id_image
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON p.id_product = pl.id_product AND pl.id_lang = ' . $id_lang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute` pa
                ON p.id_product = pa.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON p.id_category_default = cl.id_category AND cl.id_lang = ' . $id_lang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                ON pa.id_product_attribute = pac.id_product_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute` a
                ON pac.id_attribute = a.id_attribute
            LEFT JOIN `' . _DB_PREFIX_ . 'attribute_lang` agl
                ON a.id_attribute = agl.id_attribute AND agl.id_lang = ' . $id_lang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` ml
                ON p.id_manufacturer = ml.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'image` img
                ON p.id_product = img.id_product AND img.cover = 1
            WHERE p.active = 1
            ' . $where_manufacturer . '
            GROUP BY p.id_product, pa.id_product_attribute
            ORDER BY cl.name ASC, p.id_product ASC, pa.id_product_attribute ASC';

        $rows = Db::getInstance()->executeS($query);
        if (!$rows) {
            return [];
        }

        if ($profile->show_prices) {
            $price_customer_id = $this->resolvePriceCustomerId($profile);
            foreach ($rows as &$row) {
                $row['product_price'] = Product::getPriceStatic(
                    (int) $row['id_product'],
                    false,
                    ($row['id_product_attribute'] > 0 ? (int) $row['id_product_attribute'] : null),
                    6,
                    null,
                    false,
                    true,
                    1,
                    false,
                    $price_customer_id ?: null
                );
            }
            unset($row);
        }

        return $rows;
    }

    /**
     * Détermine l'id_customer à passer à getPriceStatic selon le profil.
     * Retourne 0 si aucune remise à appliquer.
     */
    private function resolvePriceCustomerId(CustomCatalogProfile $profile): int
    {
        if (!$profile->use_discounts) {
            return 0; // prix catalogue, sans remise
        }
        if ($profile->id_customer > 0) {
            return (int) $profile->id_customer;
        }
        if ($profile->id_group > 0) {
            // Cherche un client du groupe sans prix spécifique pour représenter le groupe
            $row = Db::getInstance()->getRow('
                SELECT cg.id_customer
                FROM `' . _DB_PREFIX_ . 'customer_group` AS cg
                WHERE (
                    SELECT COUNT(sp.id_specific_price)
                    FROM `' . _DB_PREFIX_ . 'specific_price` AS sp
                    WHERE sp.id_customer = cg.id_customer
                ) = 0
                AND cg.id_group = ' . (int) $profile->id_group);
            return isset($row['id_customer']) ? (int) $row['id_customer'] : 0;
        }
        return 0;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Regroupement par catégorie → produit → déclinaisons
    // ─────────────────────────────────────────────────────────────────────────

    private function groupByCategory(array $rows): array
    {
        $categories = [];

        foreach ($rows as $row) {
            $cat_name  = $row['default_category_name'] ?: '—';
            $id_prod   = (int) $row['id_product'];
            $id_attr   = (int) $row['id_product_attribute'];

            if (!isset($categories[$cat_name])) {
                $categories[$cat_name] = [];
            }
            if (!isset($categories[$cat_name][$id_prod])) {
                $categories[$cat_name][$id_prod] = [
                    'id_product'   => $id_prod,
                    'name'         => $row['product_name'],
                    'reference'    => $row['product_reference_base'],
                    'id_image'     => (int) $row['id_image'],
                    'simple_price' => null,
                    'variants'     => [],
                ];
            }

            if ($id_attr > 0) {
                $categories[$cat_name][$id_prod]['variants'][] = [
                    'id_product_attribute' => $id_attr,
                    'attribute_names'      => $row['attribute_names'] ?? '',
                    'reference'            => $row['product_reference'],
                    'price'                => $row['product_price'] ?? null,
                ];
            } else {
                $categories[$cat_name][$id_prod]['simple_price'] = $row['product_price'] ?? null;
            }
        }

        return $categories;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infos boutique
    // ─────────────────────────────────────────────────────────────────────────

    private function getShopInfo(): array
    {
        $logo_file = Configuration::get('PS_LOGO');
        $logo_path = $logo_file ? _PS_IMG_DIR_ . $logo_file : '';

        $name    = (string) Configuration::get('PS_SHOP_NAME');
        $email   = (string) Configuration::get('PS_SHOP_EMAIL');
        $phone   = (string) Configuration::get('PS_SHOP_PHONE');
        $address = $this->getShopAddress();

        $footer_parts = array_filter([$name, $address, $phone, $email]);

        return [
            'name'        => $name,
            'email'       => $email,
            'phone'       => $phone,
            'logo_path'   => (file_exists($logo_path) ? $logo_path : ''),
            'footer_line' => implode('  |  ', $footer_parts),
        ];
    }

    private function getShopAddress(): string
    {
        $id_address = (int) Configuration::get('PS_SHOP_ADDR_ID');
        if ($id_address <= 0) {
            return '';
        }
        $address = new Address($id_address);
        if (!Validate::isLoadedObject($address)) {
            return '';
        }
        $parts = array_filter([
            $address->address1,
            $address->address2,
            trim($address->postcode . ' ' . $address->city),
        ]);
        return implode(', ', $parts);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Construction du PDF
    // ─────────────────────────────────────────────────────────────────────────

    private function buildPdf(
        CustomCatalogProfile $profile,
        array $by_category,
        array $shop_info,
        ?Customer $customer,
        int $id_lang
    ): CustomCatalogTCPDF {
        $pdf = new CustomCatalogTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setJpegQuality(72);

        // Métadonnées
        $pdf->SetCreator('Créa2média – CustomCatalogOnPdf');
        $pdf->SetAuthor($shop_info['name']);
        $pdf->SetTitle($profile->title ?: $profile->name);

        // Préparer les lignes pour le header des pages internes
        $price_mention = $this->getPriceMention($profile, $customer);
        $header_lines  = array_filter([
            $profile->title ?: $profile->name,
            $price_mention,
            $customer ? $this->formatCustomerLine($customer) : '',
            date('d/m/Y'),
        ]);
        $pdf->catalogHeaderInfo = [
            'logo_path' => $shop_info['logo_path'],
            'lines'     => array_values($header_lines),
        ];
        $pdf->catalogFooterInfo = [
            'shop_line' => $shop_info['footer_line'],
        ];

        $pdf->SetAutoPageBreak(true, 22);
        $pdf->SetMargins(15, 27, 15);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);

        // ── Page 1 : couverture ──────────────────────────────────────────────
        $pdf->AddPage();
        // Désactiver l'auto break : la barre décorative en bas dépasserait le seuil
        $pdf->SetAutoPageBreak(false);
        $this->renderCover($pdf, $profile, $shop_info, $price_mention, $customer);
        $pdf->SetAutoPageBreak(true, 22);

        // ── Pages produits ───────────────────────────────────────────────────
        $pdf->AddPage();
        $this->renderProducts($pdf, $by_category, $profile);

        return $pdf;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Page de couverture
    // ─────────────────────────────────────────────────────────────────────────

    private function renderCover(
        CustomCatalogTCPDF $pdf,
        CustomCatalogProfile $profile,
        array $shop_info,
        string $price_mention,
        ?Customer $customer
    ): void {
        $page_w = $pdf->getPageWidth();

        // ── Logo centré ──────────────────────────────────────────────────────
        if (!empty($shop_info['logo_path'])) {
            [$logo_w_px, $logo_h_px] = @getimagesize($shop_info['logo_path']) ?: [0, 0];
            $max_logo_w = 80; // mm
            $logo_w_mm  = $logo_w_px > 0 ? min($max_logo_w, $logo_w_px * 0.264583) : 50;
            $logo_x     = ($page_w - $logo_w_mm) / 2;
            $pdf->Image($shop_info['logo_path'], $logo_x, 55, $logo_w_mm, 0, '', '', 'T', false, 300);
        }

        // ── Titre principal ──────────────────────────────────────────────────
        $pdf->SetFont('helvetica', 'B', 30);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY(20, 120);
        $pdf->MultiCell($page_w - 40, 14, $profile->title ?: $profile->name, 0, 'C');

        // ── Mention prix ─────────────────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 13);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY(20, $pdf->GetY() + 6);
        $pdf->MultiCell($page_w - 40, 8, $price_mention, 0, 'C');

        // ── Info client (si applicable) ──────────────────────────────────────
        if ($customer) {
            $pdf->SetFont('helvetica', 'I', 11);
            $pdf->SetTextColor(70, 70, 70);
            $pdf->SetXY(20, $pdf->GetY() + 4);
            $pdf->MultiCell($page_w - 40, 7, $this->formatCustomerLine($customer), 0, 'C');
        }

        // ── Date ─────────────────────────────────────────────────────────────
        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY(20, $pdf->GetY() + 4);
        $pdf->MultiCell($page_w - 40, 7, date('d/m/Y'), 0, 'C');

        // ── Barre décorative en bas de couverture ────────────────────────────
        $page_h = $pdf->getPageHeight();
        $pdf->SetFillColor(44, 62, 80);
        $pdf->Rect(0, $page_h - 18, $page_w, 18, 'F');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->SetXY(15, $page_h - 13);
        $pdf->Cell($page_w - 30, 8, $shop_info['name'], 0, 0, 'L');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Pages produits
    // ─────────────────────────────────────────────────────────────────────────

    private function renderProducts(
        CustomCatalogTCPDF $pdf,
        array $by_category,
        CustomCatalogProfile $profile
    ): void {
        foreach ($by_category as $cat_name => $products) {
            $this->drawCategoryHeader($pdf, $cat_name);

            foreach ($products as $product) {
                $this->drawProduct($pdf, $product, $profile);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // En-tête de catégorie
    // ─────────────────────────────────────────────────────────────────────────

    private function drawCategoryHeader(CustomCatalogTCPDF $pdf, string $cat_name): void
    {
        $margin = $pdf->getOriginalMargins();
        $col_w  = $pdf->getPageWidth() - $margin['left'] - $margin['right'];

        // S'assurer qu'il y a assez de place pour le header + au moins un produit
        if ($pdf->GetY() > $pdf->getPageHeight() - 55) {
            $pdf->AddPage();
        } else {
            $pdf->Ln(5);
        }

        // Fond sombre
        $pdf->SetFillColor(44, 62, 80);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX($margin['left']);
        $pdf->Cell($col_w, 9, '  ' . $cat_name, 0, 1, 'L', true);

        $pdf->SetTextColor(30, 30, 30);
        $pdf->Ln(3);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ligne produit (parent + déclinaisons)
    // ─────────────────────────────────────────────────────────────────────────

    private function drawProduct(
        CustomCatalogTCPDF $pdf,
        array $product,
        CustomCatalogProfile $profile
    ): void {
        $has_variants = !empty($product['variants']);
        $img_path     = $this->prepareImageForPdf($this->getProductImagePath((int) $product['id_image']));
        $margin       = $pdf->getOriginalMargins();
        $col_w        = $pdf->getPageWidth() - $margin['left'] - $margin['right'];
        $img_cell_w   = 22;
        $img_size     = 16;
        $text_w       = $col_w - $img_cell_w - 2;

        // Estimer la hauteur totale du bloc pour décider du saut de page
        $nb_variants = count($product['variants']);
        $total_h     = $img_size + 4 + ($nb_variants * 9);
        if ($pdf->GetY() + $total_h > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
            $pdf->AddPage();
        }

        $y_start = $pdf->GetY();

        // ── Photo produit ────────────────────────────────────────────────
        if ($img_path !== '') {
            $pdf->Image($img_path, $margin['left'] + 1, $y_start + 1, $img_size, $img_size, '', '', 'T', false, 96);
        } else {
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Rect($margin['left'] + 1, $y_start + 1, $img_size, $img_size, 'F');
            $pdf->SetFillColor(0, 0, 0);
        }

        // ── Nom du produit ───────────────────────────────────────────────
        $x_text      = $margin['left'] + $img_cell_w;
        $price_col_w = $text_w * 0.35;
        $attr_col_w  = $text_w - $price_col_w;

        $pdf->SetXY($x_text, $y_start + 2);
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->MultiCell($text_w, 5, $product['name'], 0, 'L');

        // Produit simple : référence à gauche + prix à droite sur la même ligne
        if (!$has_variants) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(100, 100, 100);
            $pdf->SetX($x_text);
            $pdf->Cell($attr_col_w, 4, 'Réf : ' . $product['reference'], 0, 0, 'L');

            if ($profile->show_prices && $product['simple_price'] !== null) {
                $pdf->SetFont('helvetica', 'B', 8.5);
                $pdf->SetTextColor(44, 62, 80);
                $pdf->Cell($price_col_w, 4, $this->formatPrice((float) $product['simple_price']) . ' HT', 0, 1, 'R');
            } else {
                $pdf->Ln(4);
            }
        }
        // Produit déclinable : pas de référence globale sur le parent

        $y_after_text = $pdf->GetY();

        // ── Déclinaisons : commencent dès après le nom ───────────────────
        if ($has_variants) {
            $pdf->SetY($y_after_text);
            foreach ($product['variants'] as $idx => $variant) {
                $this->drawVariant($pdf, $variant, $profile, $margin, $img_cell_w, $col_w, $idx);
            }
            // Repousser le curseur sous l'image si les variantes étaient courtes
            $pdf->SetY(max($pdf->GetY(), $y_start + $img_size + 2));
        } else {
            $pdf->SetY(max($y_after_text, $y_start + $img_size + 2));
        }

        // Séparateur léger entre produits
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($margin['left'], $pdf->GetY() + 1, $pdf->getPageWidth() - $margin['right'], $pdf->GetY() + 1);
        $pdf->SetDrawColor(0);
        $pdf->Ln(3);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Ligne de déclinaison (indentée)
    // ─────────────────────────────────────────────────────────────────────────

    private function drawVariant(
        CustomCatalogTCPDF $pdf,
        array $variant,
        CustomCatalogProfile $profile,
        array $margin,
        float $img_cell_w,
        float $col_w,
        int $variant_index = 0
    ): void {
        $indent  = 5;
        $x_text  = $margin['left'] + $img_cell_w + $indent;
        $text_w  = $col_w - $img_cell_w - $indent;
        $row_h   = 8.5;

        if ($pdf->GetY() + $row_h > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
            $pdf->AddPage();
        }

        $y = $pdf->GetY();

        // Fond alterné démarrant après la colonne photo (ne couvre pas l'image du parent)
        if ($variant_index % 2 === 0) {
            $pdf->SetFillColor(245, 247, 250);
            $pdf->Rect($margin['left'] + $img_cell_w, $y, $col_w - $img_cell_w, $row_h, 'F');
            $pdf->SetFillColor(0, 0, 0);
        }

        // Trait vertical d'indentation
        $pdf->SetDrawColor(190, 190, 190);
        $pdf->SetLineWidth(0.4);
        $pdf->Line($margin['left'] + $img_cell_w + 1, $y + 1, $margin['left'] + $img_cell_w + 1, $y + $row_h - 1);
        $pdf->SetDrawColor(0);
        $pdf->SetLineWidth(0.2);

        $attr_w = $profile->show_prices ? ($text_w * 0.65) : $text_w;

        $pdf->SetXY($x_text, $y + 0.8);
        $pdf->SetFont('helvetica', '', 8.5);
        $pdf->SetTextColor(55, 55, 55);
        $pdf->Cell($attr_w, 4, $variant['attribute_names'], 0, 0, 'L');

        if ($profile->show_prices && $variant['price'] !== null) {
            $pdf->SetFont('helvetica', 'B', 8.5);
            $pdf->SetTextColor(44, 62, 80);
            $pdf->Cell($text_w - $attr_w, 4, $this->formatPrice((float) $variant['price']) . ' HT', 0, 0, 'R');
        }

        $pdf->SetXY($x_text, $y + 4.5);
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(150, 150, 150);
        $pdf->Cell($text_w, 3.5, 'Réf : ' . $variant['reference'], 0, 1, 'L');

        $pdf->SetY($y + $row_h);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Retourne le chemin absolu vers l'image produit (jpg/png en priorité).
     * Convertit les .webp via GD si nécessaire.
     */
    private function getProductImagePath(int $id_image): string
    {
        if ($id_image <= 0) {
            return '';
        }
        $folder = Image::getImgFolderStatic($id_image);
        $base   = _PS_IMG_DIR_ . 'p/' . $folder . $id_image;

        foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
            if (file_exists($base . $ext)) {
                return $base . $ext;
            }
        }

        return '';
    }

    /**
     * Redimensionne et compresse l'image source en JPEG 120px max via GD.
     * Gère jpg, png et webp. Retourne le chemin d'un fichier temporaire.
     */
    private function prepareImageForPdf(string $src_path, int $max_px = 120): string
    {
        if ($src_path === '') {
            return '';
        }

        $info = @getimagesize($src_path);
        if (!$info || !function_exists('imagecreatetruecolor')) {
            return $src_path;
        }

        [$w, $h, $type] = $info;

        $src_img = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($src_path),
            IMAGETYPE_PNG  => @imagecreatefrompng($src_path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src_path) : false,
            default        => false,
        };

        if (!$src_img) {
            return $type === IMAGETYPE_WEBP ? '' : $src_path;
        }

        $ratio = min(1.0, $max_px / max($w, $h, 1));
        $new_w = max(1, (int) round($w * $ratio));
        $new_h = max(1, (int) round($h * $ratio));

        $dst_img = imagecreatetruecolor($new_w, $new_h);
        $white   = imagecolorallocate($dst_img, 255, 255, 255);
        imagefill($dst_img, 0, 0, $white);
        imagecopyresampled($dst_img, $src_img, 0, 0, 0, 0, $new_w, $new_h, $w, $h);
        imagedestroy($src_img);

        $tmp = tempnam(sys_get_temp_dir(), 'ccpdf_img_') . '.jpg';
        imagejpeg($dst_img, $tmp, 72);
        imagedestroy($dst_img);

        $this->tempFiles[] = $tmp;
        return $tmp;
    }

    /**
     * Retourne la mention prix à afficher selon le profil.
     */
    private function getPriceMention(CustomCatalogProfile $profile, ?Customer $customer): string
    {
        if (!$profile->show_prices) {
            return 'Catalogue sans prix';
        }
        if ($profile->use_discounts && $customer) {
            return 'Tarifs HT personnalisés';
        }
        if ($profile->use_discounts && $profile->id_group > 0) {
            $group = new Group((int) $profile->id_group);
            $group_name = Validate::isLoadedObject($group) ? $group->name[(int) $this->context->language->id] : '';
            return 'Tarifs HT – Groupe ' . $group_name;
        }
        return 'Tarifs HT catalogue';
    }

    private function formatCustomerLine(Customer $customer): string
    {
        $parts = array_filter([$customer->email, $customer->company]);
        return implode('  –  ', $parts);
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, ',', ' ') . ' €';
    }
}
