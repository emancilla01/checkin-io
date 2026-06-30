<?php

// PDO connection — requires config.php to be loaded first
require_once __DIR__ . '/../config.php';

$pdo = new PDO(
    "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
    $db_user,
    $db_pass,
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

define('UPLOAD_DIR',     $uploadDir);
define('OCR_TEMP_DIR',   $uploadTempDir);

function upload_absolute_path(string $stored_path): string {
    return UPLOAD_DIR . basename($stored_path);
}
