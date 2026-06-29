<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/includes/ocr/PdfFirstPageImageConverter.php';
require_once __DIR__ . '/includes/ocr/TesseractOcrService.php';
require_once __DIR__ . '/includes/ocr/RegisterCardTextParser.php';

$pdf = __DIR__ . '/private/uploads-temp/arronte_fernando.pdf';

echo "<pre>";
echo "=== PHP version: " . PHP_VERSION . "\n";
echo "=== SAPI: " . php_sapi_name() . "\n";
echo "=== Running as user: ";
if (function_exists('posix_getpwuid')) {
    $u = posix_getpwuid(posix_geteuid());
    echo $u['name'] ?? 'unknown';
} else {
    exec('whoami', $wo); echo implode('', $wo);
}
echo "\n\n";

echo "=== PATH seen by PHP:\n" . getenv('PATH') . "\n\n";

// --- STEP 1: pdftoppm binary resolution ---
$reflection = new ReflectionClass('PdfFirstPageImageConverter');
$resolve    = $reflection->getMethod('resolveBinary');
$resolve->setAccessible(true);
$tmpInst    = $reflection->newInstanceWithoutConstructor();
$binary     = $resolve->invoke($tmpInst);
echo "=== pdftoppm binary resolved to: $binary\n";
echo "=== Binary file_exists: " . (file_exists($binary) ? 'yes' : 'NO (PATH lookup)') . "\n";

// Quick which/where to confirm
exec('where pdftoppm 2>&1', $whereOut, $whereCode);
echo "=== where pdftoppm: [exit $whereCode] " . implode(' | ', $whereOut) . "\n\n";

// --- STEP 1: Run conversion ---
echo "=== PDF: $pdf\n";
echo "=== PDF exists: " . (file_exists($pdf) ? 'yes' : 'NO') . "\n\n";

$imgDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_diag2_' . uniqid() . DIRECTORY_SEPARATOR;
echo "=== Image output dir: $imgDir\n";

$exception1 = null;
$imgPath    = null;
try {
    $converter = new PdfFirstPageImageConverter($imgDir);
    $imgPath   = $converter->convert($pdf);
    echo "=== Converter returned path: $imgPath\n";
    echo "=== Image file_exists: " . (file_exists($imgPath) ? 'yes' : 'NO') . "\n";
    echo "=== Image filesize: " . (file_exists($imgPath) ? filesize($imgPath) . ' bytes' : 'N/A') . "\n";
} catch (Exception $e) {
    $exception1 = $e;
    echo "=== STEP 1 EXCEPTION: " . $e->getMessage() . "\n";
}

// List everything in the temp dir regardless
echo "\n=== All files in $imgDir:\n";
if (is_dir($imgDir)) {
    foreach (scandir($imgDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $fp = $imgDir . $f;
        echo "  $f  size=" . filesize($fp) . " bytes\n";
    }
} else {
    echo "  (dir does not exist)\n";
}

if ($exception1 !== null) {
    echo "\n=== Cannot proceed to Step 2 (Step 1 failed)\n";
    echo "</pre>"; exit;
}

// --- STEP 2: Tesseract on the image ---
echo "\n\n=== STEP 2: Tesseract\n";
$tess = new TesseractOcrService();
$tr   = new ReflectionClass($tess);
$rb   = $tr->getMethod('resolveBinary'); $rb->setAccessible(true);
$rl   = $tr->getMethod('resolveLang');   $rl->setAccessible(true);
$tessBin  = $rb->invoke($tess);
$tessLang = $rl->invoke($tess, $tessBin);
echo "=== Tesseract binary: $tessBin\n";
echo "=== Tesseract exists: " . (file_exists($tessBin) ? 'yes' : 'NO') . "\n";
echo "=== Language: $tessLang\n\n";

$exception2 = null;
$rawText    = '';
try {
    $rawText = $tess->recognize($imgPath);
    echo "=== RAW TEXT (" . strlen($rawText) . " chars):\n";
    echo htmlspecialchars($rawText);
    echo "\n=== END RAW TEXT\n";
} catch (Exception $e) {
    $exception2 = $e;
    echo "=== STEP 2 EXCEPTION: " . $e->getMessage() . "\n";
}

// Clean up
if ($imgPath && file_exists($imgPath)) unlink($imgPath);
if (is_dir($imgDir)) @rmdir($imgDir);

if ($exception2 !== null) {
    echo "\n=== Cannot proceed to Step 3 (Step 2 failed)\n";
    echo "</pre>"; exit;
}

// --- STEP 3: Parser ---
echo "\n\n=== STEP 3: Parser\n";
$parser = new RegisterCardTextParser();
$parsed = $parser->parse($rawText);
echo "=== Parsed result:\n";
var_dump($parsed);

echo "</pre>";
