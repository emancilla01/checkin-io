<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$expediente_id = (int)($_POST['expediente_id'] ?? 0);
if ($expediente_id <= 0) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT identificacion_path FROM expedientes WHERE id = ?");
$stmt->execute([$expediente_id]);
$exp = $stmt->fetch();

if (!$exp || empty($exp['identificacion_path'])) {
    header('Location: expediente.php?id=' . $expediente_id);
    exit;
}

// Delete physical file
$abs = __DIR__ . '/' . ltrim($exp['identificacion_path'], '/\\');
if (file_exists($abs)) {
    unlink($abs);
}

// Clear the path on the expediente row
$pdo->prepare("UPDATE expedientes SET identificacion_path = NULL WHERE id = ?")
    ->execute([$expediente_id]);

$_SESSION['flash'] = 'Identificacion eliminada correctamente.';
header('Location: expediente.php?id=' . $expediente_id);
exit;
