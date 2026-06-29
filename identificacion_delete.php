<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$doc_id = (int)($_POST['doc_id'] ?? 0);
if ($doc_id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT d.id, d.path, d.expediente_id FROM documentos d WHERE d.id = ? AND d.is_identificacion = 1"
);
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: index.php');
    exit;
}

$expediente_id = (int)$doc['expediente_id'];

// Delete physical file
$abs = __DIR__ . '/' . ltrim($doc['path'], '/\\');
if (file_exists($abs)) {
    unlink($abs);
}

// Delete the documentos row
$pdo->prepare("DELETE FROM documentos WHERE id = ?")->execute([$doc_id]);

$_SESSION['flash'] = 'Identificacion eliminada correctamente.';
header('Location: expediente.php?id=' . $expediente_id);
exit;
