<?php

require_once __DIR__ . '/vendor/autoload.php';

use setasign\Fpdi\Fpdi;

// --- Hardcoded sample file paths ---
$recognitionPdf = __DIR__ . '/samples/recognition.pdf'; // set to null or '' to skip
$regCardPdfs    = [
    __DIR__ . '/samples/regcard1.pdf',
    __DIR__ . '/samples/regcard2.pdf',
];
$contractPdf    = __DIR__ . '/samples/contract.pdf';
$outputPdf      = __DIR__ . '/merged.pdf';
// ------------------------------------

$pdf = new Fpdi();

$filesToMerge = [];

if (!empty($recognitionPdf) && file_exists($recognitionPdf)) {
    $filesToMerge[] = $recognitionPdf;
} elseif (!empty($recognitionPdf)) {
    echo "Warning: recognition PDF not found: $recognitionPdf\n";
}

foreach ($regCardPdfs as $path) {
    if (file_exists($path)) {
        $filesToMerge[] = $path;
    } else {
        echo "Warning: reg card PDF not found: $path\n";
    }
}

if (file_exists($contractPdf)) {
    $filesToMerge[] = $contractPdf;
} else {
    die("Error: contract PDF not found: $contractPdf\n");
}

if (empty($filesToMerge)) {
    die("Error: no valid PDF files to merge.\n");
}

foreach ($filesToMerge as $file) {
    $pageCount = $pdf->setSourceFile($file);
    for ($i = 1; $i <= $pageCount; $i++) {
        $tpl  = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($tpl);
        $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
        $pdf->useTemplate($tpl);
    }
}

$pdf->Output('F', $outputPdf);
echo "Merged PDF saved to: $outputPdf\n";
