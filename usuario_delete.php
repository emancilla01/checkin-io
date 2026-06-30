<?php
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
auth_require();
auth_start_session();

if (auth_role() !== 'admin') {
    $_SESSION['flash'] = 'Acceso restringido.';
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: usuarios.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$me = (int)$_SESSION['user_id'];

if ($id <= 0) {
    $_SESSION['flash'] = 'ID de usuario invalido.';
    header('Location: usuarios.php');
    exit;
}

if ($id === $me) {
    $_SESSION['flash'] = 'No puedes eliminar tu propia cuenta.';
    header('Location: usuarios.php');
    exit;
}

$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

$_SESSION['flash'] = 'Usuario eliminado correctamente.';
header('Location: usuarios.php');
exit;
