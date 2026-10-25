<?php
require_once __DIR__ . '/../../config/bootstrap.php';

// Cek login
if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Cek role admin
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . BASE_URL . "/index.php?pesan_error=Akses+Ditolak!+Halaman+tersebut+hanya+untuk+Admin.");
    exit;
}

// Aksi destruktif hanya lewat POST + token CSRF (bukan lewat link/GET)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

$id_menu = (int)($_POST['id'] ?? 0);
$success = false;
$error   = '';

if (!csrf_valid($_POST['csrf_token'] ?? '')) {
    $error = "Request tidak valid. Muat ulang halaman lalu coba lagi.";
} elseif ($id_menu <= 0) {
    $error = "ID Menu tidak valid.";
} else {
    try {
        $stmt = $pdo->prepare("SELECT gambar FROM menu WHERE id_menu = ?");
        $stmt->execute([$id_menu]);
        $menu = $stmt->fetch();

        if (!$menu) {
            $error = "Menu tidak ditemukan.";
        } else {
            // Hapus data dulu; file gambar baru dihapus kalau database berhasil
            $pdo->prepare("DELETE FROM menu WHERE id_menu = ?")->execute([$id_menu]);
            hapus_gambar_menu($menu['gambar'], __DIR__ . '/../../assets/img/menu');
            $success = true;
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            // Foreign key: menu sudah pernah terjual, riwayat transaksi harus tetap utuh
            $error = "Menu ini sudah punya riwayat transaksi sehingga tidak bisa dihapus. Ubah statusnya menjadi nonaktif lewat Edit.";
        } else {
            log_error($e, 'hapus menu');
            $error = "Terjadi kesalahan saat menghapus menu.";
        }
    }
}

$pesan = $success ? 'success=' . urlencode('Menu berhasil dihapus') : 'error=' . urlencode($error);
header("Location: index.php?$pesan");
exit;
