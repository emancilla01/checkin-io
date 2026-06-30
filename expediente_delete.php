<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();
auth_require_role(['admin', 'editor']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

// Load expediente for file paths
$stmt = $pdo->prepare("SELECT * FROM expedientes WHERE id = ?");
$stmt->execute([$id]);
$exp = $stmt->fetch();

if (!$exp) {
    header('Location: index.php');
    exit;
}

// Delete documento files from disk first
$docs = $pdo->prepare("SELECT path FROM documentos WHERE expediente_id = ?");
$docs->execute([$id]);
foreach ($docs->fetchAll() as $doc) {
    $abs = upload_absolute_path($doc['path']);
    if (file_exists($abs)) {
        unlink($abs);
    }
}

// Delete identificacion file from disk
if (!empty($exp['identificacion_path'])) {
    $abs = upload_absolute_path($exp['identificacion_path']);
    if (file_exists($abs)) {
        unlink($abs);
    }
}

// FK cascade removes documentos rows; delete the expediente row
$pdo->prepare("DELETE FROM expedientes WHERE id = ?")->execute([$id]);

auth_start_session();
$_SESSION['flash'] = "Expediente eliminado correctamente.";
header('Location: index.php');
exit;
