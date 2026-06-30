<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();
auth_require_role(['admin', 'editor']);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$expediente_id = (int)($_POST['expediente_id'] ?? 0);
$signature_raw = $_POST['signature'] ?? '';

if ($expediente_id <= 0 || $signature_raw === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Datos incompletos']);
    exit;
}

if (!preg_match('/^data:image\/png;base64,/', $signature_raw)) {
    http_response_code(422);
    echo json_encode(['error' => 'Formato de firma invalido']);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT * FROM documentos WHERE expediente_id = ? AND is_merged = 1 LIMIT 1"
);
$stmt->execute([$expediente_id]);
$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    echo json_encode(['error' => 'Documento combinado no encontrado']);
    exit;
}

$pdf_abs = upload_absolute_path($doc['path']);
if (!file_exists($pdf_abs)) {
    http_response_code(404);
    echo json_encode(['error' => 'Archivo PDF no encontrado en el servidor']);
    exit;
}

$png_data = base64_decode(preg_replace('/^data:image\/png;base64,/', '', $signature_raw));
if ($png_data === false || strlen($png_data) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Firma invalida (datos corruptos)']);
    exit;
}

$tmp_sig = tempnam(sys_get_temp_dir(), 'firma_') . '.png';
file_put_contents($tmp_sig, $png_data);

try {
    require_once __DIR__ . '/vendor/autoload.php';
    if (!class_exists('FPDF')) {
        class_alias(\Fpdf\Fpdf::class, 'FPDF');
    }

    $fpdi = new \setasign\Fpdi\Fpdi();
    $page_count = $fpdi->setSourceFile($pdf_abs);

    for ($i = 1; $i <= $page_count; $i++) {
        $tpl  = $fpdi->importPage($i);
        $size = $fpdi->getTemplateSize($tpl);
        $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
        $fpdi->useTemplate($tpl);

        // Stamp signature on every page: x=70mm, y=(page_height - 45mm), width=99mm
        $sig_y = $size['height'] - 45;
        $fpdi->Image($tmp_sig, 70, $sig_y, 99, 0, 'PNG');
    }

    $fpdi->Output('F', $pdf_abs);

    $pdo->prepare("UPDATE documentos SET signed_at = NOW() WHERE id = ?")
        ->execute([$doc['id']]);

    @unlink($tmp_sig);
    echo json_encode(['ok' => true]);

} catch (\Exception $e) {
    @unlink($tmp_sig);
    http_response_code(500);
    echo json_encode(['error' => 'Error al procesar la firma: ' . $e->getMessage()]);
}
