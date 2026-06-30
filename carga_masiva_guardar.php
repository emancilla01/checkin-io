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

$body = file_get_contents('php://input');
$rows = json_decode($body, true);

if (!is_array($rows) || empty($rows)) {
    http_response_code(400);
    echo json_encode(['error' => 'No se recibieron registros']);
    exit;
}

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

function cmg_sanitize(string $name): string {
    $name = mb_strtolower($name, 'UTF-8');
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
              'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u'];
    $name = strtr($name, $map);
    $name = preg_replace('/[^a-z0-9.\-]/', '_', $name);
    $name = preg_replace('/_+/', '_', $name);
    return trim($name, '_');
}

function cmg_unique_path(string $dir, string $filename): string {
    $path = $dir . $filename;
    if (!file_exists($path)) return $path;
    $info = pathinfo($filename);
    $base = $info['filename'];
    $ext  = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 2;
    while (file_exists($dir . $base . '_' . $i . $ext)) $i++;
    return $dir . $base . '_' . $i . $ext;
}

$saved   = 0;
$skipped = 0;
$results = [];

foreach ($rows as $idx => $row) {
    $apellido      = trim($row['apellido']      ?? '');
    $nombre        = trim($row['nombre']        ?? '');
    $fecha_llegada = trim($row['fecha_llegada'] ?? '');
    $crs_no        = trim($row['crs_no']        ?? '');
    $temp_path     = $row['temp_path']          ?? '';

    if ($apellido === '' || $nombre === '' || $fecha_llegada === '') {
        $results[] = ['index' => $idx, 'success' => false, 'error' => 'Campos requeridos faltantes'];
        $skipped++;
        continue;
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO expedientes (nombre, apellido, fecha_llegada, crs_no) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$nombre, $apellido, $fecha_llegada, $crs_no !== '' ? $crs_no : null]);
        $expediente_id = (int)$pdo->lastInsertId();

        if ($temp_path !== '' && file_exists($temp_path)) {
            $safe_name = cmg_sanitize(basename($temp_path));
            $dest      = cmg_unique_path(UPLOAD_DIR, $safe_name);
            rename($temp_path, $dest);
            $rel_path  = 'uploads/' . basename($dest);
            $pdo->prepare(
                "INSERT INTO documentos (expediente_id, path, original_name, is_merged) VALUES (?, ?, ?, 0)"
            )->execute([$expediente_id, $rel_path, $safe_name]);
        }

        $results[] = ['index' => $idx, 'success' => true, 'expediente_id' => $expediente_id];
        $saved++;

    } catch (Exception $e) {
        $results[] = ['index' => $idx, 'success' => false, 'error' => $e->getMessage()];
        $skipped++;
    }
}

echo json_encode([
    'saved'   => $saved,
    'skipped' => $skipped,
    'results' => $results,
]);
