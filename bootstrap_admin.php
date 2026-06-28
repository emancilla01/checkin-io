<?php
// ONE-TIME bootstrap tool — delete this file after use. Do NOT deploy to production.

require_once __DIR__ . '/includes/db.php';

echo "=== IO Admin Bootstrap ===" . PHP_EOL;

echo "Username: ";
$username = trim(fgets(STDIN));

echo "Nombre (real name): ";
$nombre = trim(fgets(STDIN));

// Hide password input on Windows via PowerShell; fall back to plain prompt
echo "Password: ";
$password = null;
if (PHP_OS_FAMILY === 'Windows') {
    $ps = 'powershell -Command "$p = Read-Host -AsSecureString; ' .
          '[Runtime.InteropServices.Marshal]::PtrToStringAuto(' .
          '[Runtime.InteropServices.Marshal]::SecureStringToBSTR($p))"';
    $password = trim(shell_exec($ps));
    echo PHP_EOL;
} else {
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo PHP_EOL;
}

if ($username === '') {
    echo "Error: username cannot be empty." . PHP_EOL;
    exit(1);
}
if ($nombre === '') {
    echo "Error: nombre cannot be empty." . PHP_EOL;
    exit(1);
}
if ($password === '' || $password === null) {
    echo "Error: password cannot be empty." . PHP_EOL;
    exit(1);
}

// Check for duplicate
$stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    echo "Error: a user with username '{$username}' already exists." . PHP_EOL;
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("INSERT INTO users (username, nombre, password_hash, role) VALUES (?, ?, ?, 'admin')");
$stmt->execute([$username, $nombre, $hash]);

echo "Success: admin user '{$username}' ({$nombre}) created (role: admin)." . PHP_EOL;
