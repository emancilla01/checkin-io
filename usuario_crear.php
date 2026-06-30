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

$nombre   = trim($_POST['nombre']   ?? '');
$username = trim($_POST['username'] ?? '');
$password = $_POST['password']      ?? '';
$password2 = $_POST['password2']   ?? '';
$role     = $_POST['role']          ?? 'viewer';

$allowed_roles = ['admin', 'editor', 'viewer'];

$errors = [];
if ($nombre   === '') $errors[] = 'El nombre es obligatorio.';
if ($username === '') $errors[] = 'El username es obligatorio.';
if ($password === '') $errors[] = 'La contrasena es obligatoria.';
if ($password !== $password2) $errors[] = 'Las contrasenas no coinciden.';
if (!in_array($role, $allowed_roles, true)) $errors[] = 'Rol invalido.';

if (empty($errors)) {
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$username]);
    if ($check->fetch()) {
        $errors[] = "El username '{$username}' ya esta en uso.";
    }
}

if (!empty($errors)) {
    $_SESSION['flash'] = implode(' ', $errors);
    header('Location: usuarios.php');
    exit;
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO users (username, nombre, password_hash, role) VALUES (?, ?, ?, ?)")
    ->execute([$username, $nombre, $hash, $role]);

$_SESSION['flash'] = "Usuario '{$username}' creado correctamente.";
header('Location: usuarios.php');
exit;
