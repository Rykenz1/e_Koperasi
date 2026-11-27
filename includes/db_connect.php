<?php
// db_connect.php

// Start the session here so you don't have to repeat session_start() on every page either
if (session_status() === PHP_SESSION_NONE) {
    // Detect HTTPS directly, or behind an ALB / reverse proxy.
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $is_https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// Read from environment variables (for AWS EC2/RDS), fallback to local XAMPP defaults
$servername  = getenv('DB_HOST') ?: "127.0.0.1";
$db_username = getenv('DB_USER') ?: "root";
$db_password = getenv('DB_PASS') ?: "";
$dbname      = getenv('DB_NAME') ?: "e_koperasi_db";

$conn = new mysqli($servername, $db_username, $db_password, $dbname);

if ($conn->connect_error) {
    // Log the technical error internally instead of exposing DB details to users
    error_log("Database connection failed: " . $conn->connect_error);
    die("Database connection error. Please try again later.");
}

// Ensure proper UTF-8 character encoding
$conn->set_charset("utf8mb4");
?>