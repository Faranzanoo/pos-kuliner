<?php
/**
 * Pratinjau promo di keranjang. Perhitungan resmi tetap dilakukan ulang di
 * ajax/simpan_transaksi.php saat pembayaran.
 */
require_once __DIR__ . '/../config/bootstrap.php';

require_login_json();
require_post_json();
csrf_require_header();
require_once __DIR__ . '/../config/koneksi.php';

$input     = json_decode(file_get_contents('php://input'), true);
$kodePromo = strtoupper(trim((string)($input['kode_promo'] ?? '')));
$total     = max(0, (int)($input['total'] ?? 0));

if ($kodePromo === '') {
    json_response(['status' => 'error', 'message' => 'Kode promo tidak boleh kosong.'], 422);
}

try {
    $st = $pdo->prepare("SELECT * FROM promo WHERE kode_promo = ?");
    $st->execute([$kodePromo]);
    $promo = $st->fetch();

    if (!$promo) {
        json_response(['status' => 'error', 'message' => 'Kode promo tidak ditemukan atau sudah tidak aktif.'], 422);
    }
    $err = promo_cek_error($promo, $total);
    if ($err !== null) {
        json_response(['status' => 'error', 'message' => $err], 422);
    }

    $diskon = promo_hitung_diskon($promo, $total);
    json_response([
        'status'      => 'success',
        'id_promo'    => (int)$promo['id_promo'],
        'nama_promo'  => $promo['nama_promo'],
        'diskon'      => $diskon,
        'total_bayar' => $total - $diskon,
        'message'     => 'Promo berhasil diterapkan!',
    ]);
} catch (Throwable $e) {
    log_error($e, 'cek_promo');
    json_response(['status' => 'error', 'message' => 'Terjadi kesalahan.'], 500);
}
