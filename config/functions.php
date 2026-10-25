<?php
/**
 * Fungsi bantu bersama. Dimuat oleh config/bootstrap.php.
 */

/** Exception yang pesannya aman ditampilkan ke pengguna. */
class UserFacingException extends RuntimeException {}

/** Escape output HTML. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Deteksi folder aplikasi relatif terhadap document root web server. */
function detect_base_url(): string
{
    $root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $app  = realpath(dirname(__DIR__));
    if ($root === false || $app === false) {
        return '';
    }
    $root = rtrim(str_replace('\\', '/', $root), '/');
    $app  = rtrim(str_replace('\\', '/', $app), '/');
    if ($app === $root) {
        return '';
    }
    if (stripos($app, $root . '/') === 0) {
        return substr($app, strlen($root));
    }
    return '';
}

/** Mulai sesi dengan cookie yang lebih aman. */
function start_secure_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

    session_name('POSKULINERSID');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Catat error ke log server (bukan ke layar pengguna). */
function log_error(Throwable $e, string $context = ''): void
{
    error_log('[POS Kuliner] ' . $context . ': ' . $e->getMessage()
        . ' @ ' . $e->getFile() . ':' . $e->getLine());
}

/* ------------------------------ CSRF ------------------------------ */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid($token): bool
{
    return is_string($token)
        && $token !== ''
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Untuk endpoint AJAX: token dikirim lewat header X-CSRF-Token. */
function csrf_require_header(): void
{
    if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        json_response(['status' => 'error', 'message' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
    }
}

/* ------------------------------ JSON ------------------------------ */

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function require_login_json(): void
{
    if (!isset($_SESSION['id_user'])) {
        json_response(['status' => 'error', 'message' => 'Sesi habis. Silakan login kembali.'], 401);
    }
}

function require_post_json(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_response(['status' => 'error', 'message' => 'Metode tidak diizinkan.'], 405);
    }
}

function rupiah($angka): string
{
    return 'Rp ' . number_format((int)$angka, 0, ',', '.');
}

/* ------------------------------ Promo ----------------------------- */

/** Kembalikan pesan error jika promo tidak bisa dipakai, atau null jika valid. */
function promo_cek_error(array $promo, int $subtotal): ?string
{
    if (($promo['status'] ?? '') !== 'aktif') {
        return 'Kode promo tidak ditemukan atau sudah tidak aktif.';
    }
    $today = date('Y-m-d');
    if (!empty($promo['tgl_mulai']) && $today < $promo['tgl_mulai']) {
        return 'Promo belum dimulai.';
    }
    if (!empty($promo['tgl_selesai']) && $today > $promo['tgl_selesai']) {
        return 'Promo sudah berakhir.';
    }
    if ($promo['kuota'] !== null && (int)$promo['terpakai'] >= (int)$promo['kuota']) {
        return 'Kuota promo sudah habis.';
    }
    if ($subtotal < (int)$promo['min_transaksi']) {
        return 'Minimum transaksi ' . rupiah($promo['min_transaksi']) . ' untuk promo ini.';
    }
    return null;
}

function promo_hitung_diskon(array $promo, int $subtotal): int
{
    if ($promo['tipe_diskon'] === 'persen') {
        $diskon = intdiv($subtotal * (int)$promo['nilai_diskon'], 100);
        if ($promo['maks_diskon'] !== null) {
            $diskon = min($diskon, (int)$promo['maks_diskon']);
        }
    } else {
        $diskon = (int)$promo['nilai_diskon'];
    }
    return max(0, min($diskon, $subtotal));
}

/* ------------------------------ Upload ---------------------------- */

/**
 * Simpan gambar menu dengan aman.
 * - tipe ditentukan dari ISI file (bukan dari nama / header browser)
 * - ekstensi file baru ditentukan dari tipe tersebut, bukan dari nama asli
 * - nama file diacak
 * - kalau ekstensi GD aktif, gambar di-encode ulang: metadata & payload
 *   tersembunyi (mis. kode PHP di dalam komentar PNG) ikut terbuang, dan
 *   file yang rusak / palsu ditolak
 *
 * @return array{ok:bool, file:?string, error:?string}
 */
function simpan_gambar_menu(array $file, string $dir): array
{
    $gagal = fn(string $pesan) => ['ok' => false, 'file' => null, 'error' => $pesan];

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return $gagal('Upload gagal. Coba pilih file lagi.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return $gagal('Ukuran file terlalu besar (maks 2MB).');
    }
    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) {
        return $gagal('Upload tidak valid.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ekstensi = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ];
    $info = @getimagesize($tmp);
    if (!isset($ekstensi[$mime]) || $info === false) {
        return $gagal('Tipe file tidak diizinkan. Gunakan JPG, PNG, GIF, atau WebP.');
    }
    // Batasi dimensi (cegah "decompression bomb": file kecil, piksel raksasa)
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 25000000) {
        return $gagal('Dimensi gambar tidak valid atau terlalu besar (maks 25 megapiksel).');
    }

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return $gagal('Folder upload tidak bisa dibuat.');
    }
    $nama   = 'menu_' . bin2hex(random_bytes(12)) . '.' . $ekstensi[$mime];
    $tujuan = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $nama;

    if (function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($img === false) {
            return $gagal('File gambar rusak atau tidak valid.');
        }
        // Foto dari HP: terapkan orientasi EXIF sebelum metadata dibuang
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif  = @exif_read_data($tmp);
            $sudut = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
            if ($sudut !== 0 && ($putar = imagerotate($img, $sudut, 0)) !== false) {
                imagedestroy($img);
                $img = $putar;
            }
        }
        switch ($mime) {
            case 'image/jpeg':
                $ok = imagejpeg($img, $tujuan, 88);
                break;
            case 'image/png':
                imagealphablending($img, false);
                imagesavealpha($img, true);
                $ok = imagepng($img, $tujuan, 6);
                break;
            case 'image/gif':
                $ok = imagegif($img, $tujuan);
                break;
            default: // webp
                $ok = function_exists('imagewebp') ? imagewebp($img, $tujuan, 85) : false;
        }
        imagedestroy($img);
        if ($ok) {
            return ['ok' => true, 'file' => $nama, 'error' => null];
        }
        @unlink($tujuan);
        if ($mime !== 'image/webp') {
            return $gagal('Gagal memproses gambar.');
        }
        // WebP tanpa dukungan GD: jatuh ke penyimpanan biasa di bawah
    }

    if (!move_uploaded_file($tmp, $tujuan)) {
        return $gagal('Gagal menyimpan file. Pastikan folder assets/img/menu/ bisa ditulis.');
    }
    return ['ok' => true, 'file' => $nama, 'error' => null];
}

/** Hapus file gambar menu lama dengan aman (hanya di dalam folder upload). */
function hapus_gambar_menu(?string $nama, string $dir): void
{
    if ($nama === null || $nama === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $nama)) {
        return;
    }
    $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $nama;
    if (is_file($path)) {
        @unlink($path);
    }
}
