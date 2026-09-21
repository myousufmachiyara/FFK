<?php

namespace App\Http\Controllers\Concerns;

/**
 * The bits of chrome every printed document shares — the navy header band
 * with the company mark and contact details, the footer strip, and the
 * signature block.
 *
 * These used to be copy-pasted into each controller's print() method,
 * which meant a change to the phone numbers was a change in six places.
 */
trait RendersInvoicePdf
{
    /** Phone contacts printed in the header and footer. */
    protected function companyContacts(): array
    {
        return [
            'Farooq Fulara'        => '0320-2788117',
            'Hamiz Farooq Fulara'  => '0335-0023574',
        ];
    }

    /**
     * A telephone glyph. TCPDF's core Helvetica has no such character, so
     * the icon is drawn with DejaVu Sans (bundled with TCPDF) and the text
     * around it stays in Helvetica.
     */
    protected function pdfContactIcon(\TCPDF $pdf, float $x, float $y, float $size = 9): void
    {
        $pdf->SetFont('dejavusans', '', $size);
        $pdf->SetXY($x, $y);
        $pdf->Cell(6, 5, "\u{260E}", 0, 0, 'C');
    }

    /**
     * Navy header band: logo, company name, and a contact block led by a
     * telephone icon.
     */
    protected function pdfCompanyHeader(\TCPDF $pdf): void
    {
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, 0, 210, 32, 'F');

        $logoPath = public_path('assets/img/ff-logo.jpg');
        $nameX = 12;
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 12, 5, 22);
            $nameX = 38;
        }

        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 19);
        $pdf->SetXY($nameX, 8);
        $pdf->Cell(120, 8, 'FAROOQ FULARA', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 11);
        $pdf->SetXY($nameX, 17);
        $pdf->SetTextColor(201, 162, 75);
        $pdf->Cell(120, 6, '(KARACHI)', 0, 1, 'L');

        // ── Contact block ───────────────────────────────────────────
        $pdf->SetTextColor(255, 255, 255);
        $this->pdfContactIcon($pdf, 113, 10.5, 11);

        $pdf->SetFont('helvetica', '', 8);
        $y = 8;
        foreach ($this->companyContacts() as $name => $number) {
            $pdf->SetXY(120, $y);
            $pdf->Cell(80, 5, $name . ': ' . $number, 0, 1, 'R');
            $y += 5;
        }

        $pdf->SetTextColor(0, 0, 0);
    }

    /** Gold document-type label on the left, boxed invoice info on the right. */
    protected function pdfTitleBar(\TCPDF $pdf, string $label, string $infoHtml, int $fontSize = 15): void
    {
        $pdf->SetFillColor(201, 162, 75);
        $pdf->Rect(10, 38, 90, 12, 'F');
        $pdf->SetFont('helvetica', 'B', $fontSize);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY(10, 40.5);
        $pdf->Cell(90, 7, '  ' . $label, 0, 0, 'L');
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetXY(105, 38);
        $pdf->writeHTMLCell(95, 12, 105, 38, $infoHtml, 1, 1);
    }

    /** A navy section heading bar, full width or half. */
    protected function pdfSectionHeading(\TCPDF $pdf, float $x, float $y, float $w, string $label, bool $lineBreak = false): void
    {
        $pdf->SetFillColor(27, 58, 92);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, 7, '  ' . $label, 1, $lineBreak ? 1 : 0, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
    }

    protected function pdfSignature(\TCPDF $pdf): void
    {
        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 20;
        if ($ySign > 250) {
            $pdf->AddPage();
            $ySign = 30;
        }

        $pdf->Line(140, $ySign, 195, $ySign);
        $pdf->SetXY(140, $ySign + 1);
        $pdf->Cell(55, 5, 'Authorized Signature', 0, 1, 'C');
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(140);
        $pdf->Cell(55, 5, 'FAROOQ FULARA (KARACHI)', 0, 0, 'C');
    }

    protected function pdfFooterBand(\TCPDF $pdf): void
    {
        $footY = 282;
        $pdf->SetFillColor(27, 58, 92);
        $pdf->Rect(0, $footY, 210, 15, 'F');
        $pdf->SetTextColor(255, 255, 255);

        $parts = [];
        foreach ($this->companyContacts() as $name => $number) {
            $parts[] = $name . ': ' . $number;
        }
        $parts[] = 'Karachi, Pakistan';

        $pdf->SetFont('dejavusans', '', 8);
        $pdf->SetXY(10, $footY + 4);
        $pdf->Cell(190, 5, "\u{260E}  " . implode('   |   ', $parts), 0, 1, 'C');
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetTextColor(0, 0, 0);
    }

    /**
     * Item name and variation read as one thing on a printed line, so they
     * are printed as one "Description" column rather than two.
     */
    protected function itemDescription($item): string
    {
        $name = $item->product->name ?? '-';
        $sku  = $item->variation->sku ?? null;

        return $sku ? $name . ' — ' . $sku : $name;
    }

    /** A fresh A4 portrait document with this app's standard setup. */
    protected function newInvoicePdf(string $title): \TCPDF
    {
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('Farooq Fulara (Karachi)');
        $pdf->SetAuthor('Farooq Fulara (Karachi)');
        $pdf->SetTitle($title);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 25);
        $pdf->AddPage();

        return $pdf;
    }
}
