<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$id        = (int)($_POST['id'] ?? 0);
$habitacion = trim($_POST['habitacion'] ?? '');

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'ID invalido']);
    exit;
}

if (mb_strlen($habitacion) > 20) {
    http_response_code(422);
    echo json_encode(['error' => 'Habitacion demasiado larga (max 20 caracteres)']);
    exit;
}

$stmt = $pdo->prepare("UPDATE expedientes SET habitacion = ? WHERE id = ?");
$stmt->execute([$habitacion !== '' ? $habitacion : null, $id]);

if ($stmt->rowCount() === 0) {
    // rowCount can be 0 if value didn't change — verify the row exists
    $check = $pdo->prepare("SELECT id FROM expedientes WHERE id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Expediente no encontrado']);
        exit;
    }
}

echo json_encode(['ok' => true]);
