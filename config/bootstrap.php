<?php
/**
 * Bootstrap — dimuat paling awal oleh setiap halaman.
 * Memuat konfigurasi, mengatur zona waktu & error, memulai sesi aman,
 * dan menyediakan fungsi bantu (config/functions.php).
 */
if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);
define('APP_ROOT', dirname(__DIR__));

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Konfigurasi belum ada. Salin config/config.example.php menjadi config/config.php lalu sesuaikan.');
}
require_once $configFile;
require_once __DIR__ . '/functions.php';

if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', false);
}
if (!defined('BASE_URL')) {
    define('BASE_URL', detect_base_url());
}
date_default_timezone_set(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'Asia/Jakarta');

// Pengganti php_flag di .htaccess (yang bisa membuat error 500 di FastCGI)
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

start_secure_session();
