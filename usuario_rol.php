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

$id   = (int)($_POST['id']   ?? 0);
$role = $_POST['role'] ?? '';

$allowed_roles = ['admin', 'editor', 'viewer'];

if ($id <= 0 || !in_array($role, $allowed_roles, true)) {
    $_SESSION['flash'] = 'Datos invalidos.';
    header('Location: usuarios.php');
    exit;
}

$pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);

$_SESSION['flash'] = 'Rol actualizado correctamente.';
header('Location: usuarios.php');
exit;
