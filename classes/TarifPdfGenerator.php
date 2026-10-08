<?php
/**
 * Génération PDF d'un tarif client.
 *
 * Le document reprend le logo et le nom du tarif, le client, la date, puis la
 * liste des produits groupés par section. Conformément aux règles du module,
 * seul le prix final HT est affiché (jamais le prix d'origine ni la réduction).
 *
 * @author Créa2média
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/CustomCatalogTarif.php';
require_once _PS_MODULE_DIR_ . 'customcatalogonpdf/classes/TarifService.php';

class TarifTCPDF extends TCPDF
{
    public array $tarifHeaderInfo = [];
    public array $tarifFooterInfo = [];

    public function Header(): void
    {
        if ($this->getPage() <= 1) {
            return;
        }

        $info = $this->tarifHeaderInfo;
        $pageW = $this->getPageWidth();
        $margin = $this->getOriginalMargins();

        if (!empty($info['logo_path']) && file_exists($info['logo_path'])) {
            $this->Image($info['logo_path'], $margin['left'], 4, 28, 0, '', '', 'T', false, 300);
        }

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

        $this->SetDrawColor(200, 200, 200);
        $this->SetLineWidth(0.3);
        $this->Line($margin['left'], 22, $pageW - $margin['right'], 22);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.2);
    }

    public function Footer(): void
    {
        if ($this->getPage() <= 1) {
            return;
        }

        $info = $this->tarifFooterInfo;
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
        $this->Cell($pageW - $margin['left'] - $margin['right'] - 20, 5, $info['shop_line'] ?? '', 0, 0, 'L');
        $this->Cell(20, 5, 'Page ' . ($this->getPage() - 1), 0, 0, 'R');
    }
}

class TarifPdfGenerator
{
    private Context $context;
    private TarifService $service;
    private array $tempFiles = [];

    public function __construct(TarifService $service)
    {
        $this->context = Context::getContext();
        $this->service = $service;
    }

    public function generate(int $idTarif): void
    {
        $data = $this->service->getTarifData($idTarif);
        if ($data === null) {
            throw new RuntimeException('Tarif introuvable (id=' . $idTarif . ').');
        }

        $pdf = $this->buildPdf($data);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        /** @var CustomCatalogTarif $tarif */
        $tarif = $data['tarif'];
        $slug = Tools::link_rewrite($tarif->name) ?: ('tarif_' . (int) $tarif->id);
        $pdf->Output('tarif_' . $slug . '_' . date('Ymd') . '.pdf', 'D');

        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }

        exit;
    }

    private function buildPdf(array $data): TarifTCPDF
    {
        /** @var CustomCatalogTarif $tarif */
        $tarif = $data['tarif'];
        $shopInfo = $this->getShopInfo();
        $logoPath = $tarif->getLogoPath() ?: $shopInfo['logo_path'];
        $customerLine = $this->getCustomerLine($data['customer']);

        $pdf = new TarifTCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setJpegQuality(72);
        $pdf->SetCreator('Créa2média – CustomCatalogOnPdf');
        $pdf->SetAuthor($shopInfo['name']);
        $pdf->SetTitle($tarif->name);

        $headerLines = array_filter([
            $tarif->name,
            $customerLine,
            date('d/m/Y'),
        ]);
        $pdf->tarifHeaderInfo = [
            'logo_path' => $logoPath,
            'lines' => array_values($headerLines),
        ];
        $pdf->tarifFooterInfo = ['shop_line' => $shopInfo['footer_line']];

        $pdf->SetAutoPageBreak(true, 22);
        $pdf->SetMargins(15, 27, 15);
        $pdf->setPrintHeader(true);
        $pdf->setPrintFooter(true);

        // Couverture
        $pdf->AddPage();
        $pdf->SetAutoPageBreak(false);
        $this->renderCover($pdf, $tarif, $shopInfo, $logoPath, $customerLine);
        $pdf->SetAutoPageBreak(true, 22);

        // Contenu
        $pdf->AddPage();
        $this->renderSections($pdf, $data['sections']);

        return $pdf;
    }

    private function renderCover(
        TarifTCPDF $pdf,
        CustomCatalogTarif $tarif,
        array $shopInfo,
        string $logoPath,
        string $customerLine
    ): void {
        $pageW = $pdf->getPageWidth();

        if ($logoPath !== '' && file_exists($logoPath)) {
            [$logoWpx] = @getimagesize($logoPath) ?: [0];
            $logoWmm = $logoWpx > 0 ? min(80, $logoWpx * 0.264583) : 50;
            $pdf->Image($logoPath, ($pageW - $logoWmm) / 2, 55, $logoWmm, 0, '', '', 'T', false, 300);
        }

        $pdf->SetFont('helvetica', 'B', 30);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->SetXY(20, 120);
        $pdf->MultiCell($pageW - 40, 14, $tarif->name, 0, 'C');

        $pdf->SetFont('helvetica', '', 13);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY(20, $pdf->GetY() + 6);
        $pdf->MultiCell($pageW - 40, 8, 'Tarif HT', 0, 'C');

        if ($customerLine !== '') {
            $pdf->SetFont('helvetica', 'I', 11);
            $pdf->SetTextColor(70, 70, 70);
            $pdf->SetXY(20, $pdf->GetY() + 4);
            $pdf->MultiCell($pageW - 40, 7, $customerLine, 0, 'C');
        }

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetTextColor(120, 120, 120);
        $pdf->SetXY(20, $pdf->GetY() + 4);
        $pdf->MultiCell($pageW - 40, 7, date('d/m/Y'), 0, 'C');

        $pageH = $pdf->getPageHeight();
        $pdf->SetFillColor(44, 62, 80);
        $pdf->Rect(0, $pageH - 18, $pageW, 18, 'F');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(200, 200, 200);
        $pdf->SetXY(15, $pageH - 13);
        $pdf->Cell($pageW - 30, 8, $shopInfo['name'], 0, 0, 'L');
    }

    private function renderSections(TarifTCPDF $pdf, array $sections): void
    {
        foreach ($sections as $section) {
            if (empty($section['lines'])) {
                continue;
            }

            $title = (string) $section['title'];
            if ($title !== '') {
                $this->drawSectionHeader($pdf, $title);
            }

            foreach ($section['lines'] as $line) {
                $this->drawLine($pdf, $line);
            }
        }
    }

    private function drawSectionHeader(TarifTCPDF $pdf, string $title): void
    {
        $margin = $pdf->getOriginalMargins();
        $colW = $pdf->getPageWidth() - $margin['left'] - $margin['right'];

        if ($pdf->GetY() > $pdf->getPageHeight() - 55) {
            $pdf->AddPage();
        } else {
            $pdf->Ln(5);
        }

        $pdf->SetFillColor(44, 62, 80);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->SetX($margin['left']);
        $pdf->Cell($colW, 9, '  ' . $title, 0, 1, 'L', true);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->Ln(3);
    }

    private function drawLine(TarifTCPDF $pdf, array $line): void
    {
        $margin = $pdf->getOriginalMargins();
        $colW = $pdf->getPageWidth() - $margin['left'] - $margin['right'];
        $imgCellW = 22;
        $imgSize = 16;
        $textW = $colW - $imgCellW - 2;
        $priceColW = $textW * 0.30;
        $infoColW = $textW - $priceColW;

        $totalH = $imgSize + 4;
        if ($pdf->GetY() + $totalH > $pdf->getPageHeight() - $pdf->getBreakMargin()) {
            $pdf->AddPage();
        }

        $yStart = $pdf->GetY();

        // Image produit
        $imgPath = $this->prepareImageForPdf($this->getProductImagePath((int) ($line['id_image'] ?? 0)));
        if ($imgPath !== '') {
            $imgX = $margin['left'] + 1;
            $imgY = $yStart + 1;
            [$srcW, $srcH] = @getimagesize($imgPath) ?: [0, 0];
            if ($srcW > 0 && $srcH > 0) {
                if ($srcW >= $srcH) {
                    $drawW = $imgSize;
                    $drawH = $imgSize * ($srcH / $srcW);
                } else {
                    $drawH = $imgSize;
                    $drawW = $imgSize * ($srcW / $srcH);
                }
                $drawX = $imgX + (($imgSize - $drawW) / 2);
                $drawY = $imgY + (($imgSize - $drawH) / 2);
                $pdf->Image($imgPath, $drawX, $drawY, $drawW, $drawH, '', '', 'T', false, 96);
            } else {
                $pdf->Image($imgPath, $imgX, $imgY, $imgSize, $imgSize, '', '', 'T', false, 96);
            }
        } else {
            $pdf->SetFillColor(230, 230, 230);
            $pdf->Rect($margin['left'] + 1, $yStart + 1, $imgSize, $imgSize, 'F');
            $pdf->SetFillColor(0, 0, 0);
        }

        $xText = $margin['left'] + $imgCellW;

        // Nom + déclinaison
        $name = $line['name'];
        if (!empty($line['attribute_names'])) {
            $name .= ' - ' . $line['attribute_names'];
        }
        $pdf->SetXY($xText, $yStart + 2);
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->SetTextColor(30, 30, 30);
        $pdf->MultiCell($infoColW, 5, $name, 0, 'L');

        // Référence + EAN
        $meta = array_filter([
            !empty($line['reference']) ? 'Réf : ' . $line['reference'] : '',
            !empty($line['ean13']) ? 'EAN : ' . $line['ean13'] : '',
        ]);
        $pdf->SetX($xText);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(100, 100, 100);
        $pdf->MultiCell($infoColW, 4, implode('  |  ', $meta), 0, 'L');

        // Prix final (à droite, aligné en haut du bloc)
        $pdf->SetXY($xText + $infoColW, $yStart + 2);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetTextColor(44, 62, 80);
        $pdf->Cell($priceColW, 6, $this->formatPrice((float) $line['final_price']) . ' HT', 0, 0, 'R');

        $pdf->SetY(max($pdf->GetY(), $yStart + $imgSize + 2));

        $pdf->SetDrawColor(220, 220, 220);
        $pdf->SetLineWidth(0.2);
        $pdf->Line($margin['left'], $pdf->GetY() + 1, $pdf->getPageWidth() - $margin['right'], $pdf->GetY() + 1);
        $pdf->SetDrawColor(0);
        $pdf->Ln(3);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Infos boutique + images
    // ─────────────────────────────────────────────────────────────────────────

    private function getShopInfo(): array
    {
        $logoFile = Configuration::get('PS_LOGO');
        $logoPath = $logoFile ? _PS_IMG_DIR_ . $logoFile : '';
        $name = (string) Configuration::get('PS_SHOP_NAME');
        $email = (string) Configuration::get('PS_SHOP_EMAIL');
        $phone = (string) Configuration::get('PS_SHOP_PHONE');

        $footerParts = array_filter([$name, $phone, $email]);

        return [
            'name' => $name,
            'logo_path' => (file_exists($logoPath) ? $logoPath : ''),
            'footer_line' => implode('  |  ', $footerParts),
        ];
    }

    private function getCustomerLine(?array $customer): string
    {
        return $customer['text'] ?? '';
    }

    private function getProductImagePath(int $idImage): string
    {
        if ($idImage <= 0) {
            return '';
        }
        $folder = Image::getImgFolderStatic($idImage);
        $base = _PS_IMG_DIR_ . 'p/' . $folder . $idImage;

        foreach (['.jpg', '.jpeg', '.png', '.webp'] as $ext) {
            if (file_exists($base . $ext)) {
                return $base . $ext;
            }
        }

        return '';
    }

    private function prepareImageForPdf(string $srcPath, int $maxPx = 120): string
    {
        if ($srcPath === '') {
            return '';
        }

        $info = @getimagesize($srcPath);
        if (!$info || !function_exists('imagecreatetruecolor')) {
            return $srcPath;
        }

        [$w, $h, $type] = $info;

        $srcImg = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($srcPath),
            IMAGETYPE_PNG => @imagecreatefrompng($srcPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false,
            default => false,
        };

        if (!$srcImg) {
            return $type === IMAGETYPE_WEBP ? '' : $srcPath;
        }

        $ratio = min(1.0, $maxPx / max($w, $h, 1));
        $newW = max(1, (int) round($w * $ratio));
        $newH = max(1, (int) round($h * $ratio));

        $dstImg = imagecreatetruecolor($newW, $newH);
        $white = imagecolorallocate($dstImg, 255, 255, 255);
        imagefill($dstImg, 0, 0, $white);
        imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $newW, $newH, $w, $h);
        imagedestroy($srcImg);

        $tmp = tempnam(sys_get_temp_dir(), 'cctarif_img_') . '.jpg';
        imagejpeg($dstImg, $tmp, 72);
        imagedestroy($dstImg);

        $this->tempFiles[] = $tmp;

        return $tmp;
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, ',', ' ') . ' €';
    }
}
