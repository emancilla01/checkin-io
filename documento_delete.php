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

$doc_id = (int)($_POST['doc_id'] ?? 0);
if ($doc_id <= 0) {
    header('Location: index.php');
    exit;
}

// Load the documento and verify it belongs to a real expediente
$stmt = $pdo->prepare("SELECT * FROM documentos WHERE id = ?");
$stmt->execute([$doc_id]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: index.php');
    exit;
}

$expediente_id = (int)$doc['expediente_id'];

// Delete physical file from disk
$abs = __DIR__ . '/' . ltrim($doc['path'], '/\\');
if (file_exists($abs)) {
    unlink($abs);
}

// Delete DB row
$pdo->prepare("DELETE FROM documentos WHERE id = ?")->execute([$doc_id]);

$label = $doc['original_name'] ?? basename($doc['path']);
$_SESSION['flash'] = 'Documento eliminado: ' . $label;

header('Location: expediente.php?id=' . $expediente_id);
exit;
