<?php
/**
 * PdfService — Generaties a PDF for an invoice using Dompdf.
 * Note: Requires 'composer require dompdf/dompdf'
 */

namespace Horsterwold\Services;

require_once __DIR__ . '/../../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Exception;

class PdfService
{
    /**
     * Create a PDF from a billing result
     */
    public function generateInvoicePdf(array $billingData): string
    {
        if (!class_exists('Dompdf\Dompdf')) {
            throw new Exception("Dompdf not installed. Please run 'composer require dompdf/dompdf' on the server.");
        }

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        
        // Load Template
        ob_start();
        include __DIR__ . '/../templates/invoice_pdf.php';
        $html = ob_get_clean();

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdfOutput = $dompdf->output();
        $filename = "factuur_lot" . $billingData['lot_id'] . "_" . date('Ymd_His') . ".pdf";
        $path = __DIR__ . "/../../public/uploads/invoices/" . $filename;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $pdfOutput);
        
        return $filename;
    }
}
