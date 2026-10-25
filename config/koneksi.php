<?php
// Koneksi database (PDO). Kredensial dibaca dari config/config.php.
require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    log_error($e, 'koneksi database');
    http_response_code(500);
    exit(APP_DEBUG
        ? 'Koneksi database gagal: ' . $e->getMessage()
        : 'Koneksi database gagal. Periksa config/config.php.');
}
