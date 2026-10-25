<?php
/**
 * Detail satu transaksi (dipakai halaman Laporan: modal detail & cetak ulang struk).
 * Halaman Laporan memang terbuka untuk semua role yang sudah login.
 */
require_once __DIR__ . '/../config/bootstrap.php';

require_login_json();
require_once __DIR__ . '/../config/koneksi.php';

$idTransaksi = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($idTransaksi <= 0) {
    json_response(['status' => 'error', 'message' => 'ID tidak valid.'], 400);
}

try {
    $st = $pdo->prepare("
        SELECT t.*, u.username
        FROM transaksi t
        LEFT JOIN users u ON t.id_user = u.id_user
        WHERE t.id_transaksi = ?
    ");
    $st->execute([$idTransaksi]);
    $transaksi = $st->fetch();

    if (!$transaksi) {
        json_response(['status' => 'error', 'message' => 'Transaksi tidak ditemukan.'], 404);
    }

    $st = $pdo->prepare("SELECT * FROM detail_transaksi WHERE id_transaksi = ? ORDER BY id_detail ASC");
    $st->execute([$idTransaksi]);

    json_response(['status' => 'success', 'transaksi' => $transaksi, 'items' => $st->fetchAll()]);
} catch (Throwable $e) {
    log_error($e, 'detail_transaksi');
    json_response(['status' => 'error', 'message' => 'Terjadi kesalahan.'], 500);
}
