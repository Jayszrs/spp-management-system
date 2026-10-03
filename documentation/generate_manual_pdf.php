<?php
/** Regenerate the public user manual from its editable HTML source. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

$source = __DIR__ . '/manual_penggunaan_sistemspp.html';
$target = __DIR__ . '/manual_penggunaan_sistemspp.pdf';
$html = file_get_contents($source);
if ($html === false || !str_contains($html, 'Manual Penggunaan SistemSPP')) {
    throw new RuntimeException('Sumber manual tidak dapat dibaca.');
}

$options = new \Dompdf\Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);
$options->setDefaultMediaType('print');
$options->set('defaultFont', 'DejaVu Sans');

$pdf = new \Dompdf\Dompdf($options);
$pdf->loadHtml($html, 'UTF-8');
$pdf->setPaper('A4', 'portrait');
$pdf->render();
$bytes = $pdf->output();
if (!str_starts_with($bytes, '%PDF-') || strlen($bytes) < 10000) {
    throw new RuntimeException('PDF manual tidak valid.');
}
if (file_put_contents($target, $bytes) !== strlen($bytes)) {
    throw new RuntimeException('PDF manual gagal disimpan.');
}
echo "Manual PDF diperbarui: {$target}\n";
