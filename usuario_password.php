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

$id        = (int)($_POST['id']       ?? 0);
$password  = $_POST['password']       ?? '';
$password2 = $_POST['password2']      ?? '';

if ($id <= 0) {
    $_SESSION['flash'] = 'ID de usuario invalido.';
    header('Location: usuarios.php');
    exit;
}

if ($password === '') {
    $_SESSION['flash'] = 'La contrasena no puede estar vacia.';
    header('Location: usuarios.php');
    exit;
}

if ($password !== $password2) {
    $_SESSION['flash'] = 'Las contrasenas no coinciden.';
    header('Location: usuarios.php');
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);

$_SESSION['flash'] = 'Contrasena actualizada correctamente.';
header('Location: usuarios.php');
exit;
