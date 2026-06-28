<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ocr/PdfFirstPageImageConverter.php';
require_once __DIR__ . '/includes/ocr/TesseractOcrService.php';
require_once __DIR__ . '/includes/ocr/RegisterCardTextParser.php';
auth_require();
auth_start_session();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed']);
    exit;
}

define('OCR_TEMP_DIR', __DIR__ . '/private/uploads-temp/');
if (!is_dir(OCR_TEMP_DIR)) mkdir(OCR_TEMP_DIR, 0755, true);

function cm_sanitize(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9.\-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

function cm_unique_path(string $dir, string $filename): string {
    $path = $dir . $filename;
    if (!file_exists($path)) return $path;
    $info = pathinfo($filename);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 2;
    while (file_exists($dir . $base . '_' . $i . $ext)) $i++;
    return $dir . $base . '_' . $i . $ext;
}

if (empty($_FILES['reg_card']['name']) || $_FILES['reg_card']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Archivo no recibido o con error de subida']);
    exit;
}

$file      = $_FILES['reg_card'];
$safe_name = cm_sanitize(basename($file['name']));
$temp_dest = cm_unique_path(OCR_TEMP_DIR, $safe_name);

if (!move_uploaded_file($file['tmp_name'], $temp_dest)) {
    echo json_encode(['status' => 'error', 'error' => 'No se pudo guardar el archivo temporalmente']);
    exit;
}

try {
    $imgDir    = OCR_TEMP_DIR . 'img_' . uniqid() . DIRECTORY_SEPARATOR;
    $converter = new PdfFirstPageImageConverter($imgDir);
    $imgPath   = $converter->convert($temp_dest);

    $tesseract = new TesseractOcrService();
    $rawText   = $tesseract->recognize($imgPath);

    if (file_exists($imgPath)) unlink($imgPath);
    if (is_dir($imgDir))       @rmdir($imgDir);

    $parser = new RegisterCardTextParser();
    $parsed = $parser->parse($rawText);

    $apellido      = $parsed['apellido']      ?? '';
    $nombre        = $parsed['nombre']        ?? '';
    $fecha_llegada = $parsed['fecha_llegada'] ?? '';
    $crs_no        = $parsed['crs_no']        ?? '';

    // Status: all 3 required fields must be non-empty for Listo
    $required_ok = $apellido !== '' && $nombre !== '' && $fecha_llegada !== '';
    $status = $required_ok ? 'listo' : 'incompleto';

    echo json_encode([
        'status'        => $status,
        'apellido'      => $apellido,
        'nombre'        => $nombre,
        'fecha_llegada' => $fecha_llegada,
        'crs_no'        => $crs_no,
        'temp_path'     => $temp_dest,
        'filename'      => basename($file['name']),
    ]);

} catch (Exception $e) {
    // OCR failed entirely — keep temp file so staff can still save manually
    echo json_encode([
        'status'    => 'error',
        'error'     => $e->getMessage(),
        'temp_path' => $temp_dest,
        'filename'  => basename($file['name']),
    ]);
}
