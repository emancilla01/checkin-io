<?php

function auth_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function auth_attempt(PDO $pdo, string $username, string $password): bool {
    $stmt = $pdo->prepare("SELECT id, username, nombre, password_hash, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    auth_start_session();
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['username']  = $user['username'];
    $_SESSION['nombre']    = $user['nombre'];
    $_SESSION['role']      = $user['role'];
    return true;
}

function auth_check(): bool {
    auth_start_session();
    return !empty($_SESSION['user_id']);
}

function auth_require(): void {
    if (!auth_check()) {
        header('Location: login.php');
        exit;
    }
}

function auth_role(): string {
    auth_start_session();
    return $_SESSION['role'] ?? '';
}

function auth_require_role(array $allowed_roles): void {
    auth_start_session();
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, $allowed_roles, true)) {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Acceso restringido.'];
        header('Location: index.php');
        exit;
    }
}

function auth_logout(): void {
    auth_start_session();
    $_SESSION = [];
    session_destroy();
}
