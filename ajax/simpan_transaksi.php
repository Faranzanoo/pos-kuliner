<?php
/**
 * Simpan transaksi pembayaran.
 *
 * Keamanan: browser hanya mengirim APA yang dibeli (id menu, id varian, qty),
 * metode bayar, uang bayar, dan id promo. Harga, nama, subtotal, diskon,
 * total, kembalian, shift, dan nomor meja SEMUA dihitung / diambil ulang
 * dari database di server, jadi tidak bisa dimanipulasi dari browser.
 */
require_once __DIR__ . '/../config/bootstrap.php';

require_login_json();
require_post_json();
csrf_require_header();
require_once __DIR__ . '/../config/koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['cart']) || !is_array($input['cart'])) {
    json_response(['status' => 'error', 'message' => 'Keranjang kosong atau data tidak valid.'], 400);
}

$metodeValid = ['Cash', 'QRIS', 'Debit', 'Kredit', 'Gojek', 'GrabFood', 'ShopeeFood'];
$metodeBayar = in_array($input['metode_bayar'] ?? '', $metodeValid, true) ? $input['metode_bayar'] : 'Cash';
$bayar       = (int)($input['bayar'] ?? 0);
$idMeja      = (int)($input['id_meja'] ?? 0);
$idPromo     = !empty($input['id_promo']) ? (int)$input['id_promo'] : null;

$idUser = (int)$_SESSION['id_user'];
$role   = $_SESSION['role'] ?? '';

try {
    // Gabungkan item yang sama & validasi bentuk data
    $baris = [];
    foreach ($input['cart'] as $item) {
        if (!is_array($item)) {
            throw new UserFacingException('Data item tidak valid.');
        }
        $idMenu   = (int)($item['id'] ?? 0);
        $idVarian = !empty($item['id_varian']) ? (int)$item['id_varian'] : 0;
        $qty      = (int)($item['qty'] ?? 0);
        if ($idMenu <= 0 || $qty <= 0 || $qty > 999) {
            throw new UserFacingException('Data item tidak valid.');
        }
        $k = $idMenu . '_' . $idVarian;
        if (!isset($baris[$k])) {
            $baris[$k] = ['id_menu' => $idMenu, 'id_varian' => $idVarian, 'qty' => 0];
        }
        $baris[$k]['qty'] += $qty;
        if ($baris[$k]['qty'] > 999) {
            throw new UserFacingException('Jumlah item terlalu besar.');
        }
    }
    if (count($baris) > 100) {
        throw new UserFacingException('Terlalu banyak item dalam satu transaksi.');
    }

    $pdo->beginTransaction();

    // Shift diambil dari database (kasir wajib punya shift aktif)
    $idShift = null;
    if ($role === 'kasir') {
        $st = $pdo->prepare("SELECT id_shift FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
        $st->execute([$idUser]);
        $shift = $st->fetch();
        if (!$shift) {
            throw new UserFacingException('Anda belum memulai shift.');
        }
        $idShift = (int)$shift['id_shift'];
    }

    // Meja dari database; kasir tidak boleh membayar order milik kasir lain
    $nomorMeja = null;
    if ($idMeja > 0) {
        $st = $pdo->prepare("SELECT nomor_meja FROM meja WHERE id_meja = ?");
        $st->execute([$idMeja]);
        $meja = $st->fetch();
        if (!$meja) {
            throw new UserFacingException('Meja tidak ditemukan.');
        }
        $nomorMeja = (int)$meja['nomor_meja'];

        if ($role !== 'admin') {
            $st = $pdo->prepare("SELECT id_user FROM order_aktif WHERE id_meja = ?");
            $st->execute([$idMeja]);
            $order = $st->fetch();
            if ($order && (int)$order['id_user'] !== $idUser) {
                throw new UserFacingException('Meja ini sedang dipegang pengguna lain.');
            }
        }
    }

    // Harga & stok diambil dari database. Baris dikunci (FOR UPDATE) agar
    // dua kasir yang membayar bersamaan tidak membuat stok negatif.
    $stMenu   = $pdo->prepare("SELECT id_menu, nama_menu, harga, status, stok, is_stok_aktif FROM menu WHERE id_menu = ? FOR UPDATE");
    $stVarian = $pdo->prepare("SELECT id_varian, nama_varian, harga_tambah, status, stok, is_stok_aktif FROM menu_varian WHERE id_varian = ? AND id_menu = ? FOR UPDATE");

    $subtotal = 0;
    $detail   = [];
    foreach ($baris as $b) {
        $stMenu->execute([$b['id_menu']]);
        $menu = $stMenu->fetch();
        if (!$menu || $menu['status'] !== 'aktif') {
            throw new UserFacingException('Ada menu yang sudah tidak tersedia. Muat ulang halaman kasir.');
        }
        $harga = (int)$menu['harga'];
        $nama  = $menu['nama_menu'];

        if ($b['id_varian'] > 0) {
            $stVarian->execute([$b['id_varian'], $b['id_menu']]);
            $varian = $stVarian->fetch();
            if (!$varian || $varian['status'] !== 'aktif') {
                throw new UserFacingException('Ada varian menu yang sudah tidak tersedia. Muat ulang halaman kasir.');
            }
            $harga += (int)$varian['harga_tambah'];
            $nama  .= ' - ' . $varian['nama_varian'];
            if ($varian['is_stok_aktif'] && $varian['stok'] !== null && (int)$varian['stok'] < $b['qty']) {
                throw new UserFacingException("Stok {$nama} tidak cukup (sisa " . max(0, (int)$varian['stok']) . ').');
            }
        } else {
            if ($menu['is_stok_aktif'] && $menu['stok'] !== null && (int)$menu['stok'] < $b['qty']) {
                throw new UserFacingException("Stok {$nama} tidak cukup (sisa " . max(0, (int)$menu['stok']) . ').');
            }
        }

        $sub = $harga * $b['qty'];
        $subtotal += $sub;
        $detail[] = [
            'id_menu'   => $b['id_menu'],
            'id_varian' => $b['id_varian'],
            'nama'      => $nama,
            'harga'     => $harga,
            'qty'       => $b['qty'],
            'subtotal'  => $sub,
        ];
    }

    // Promo divalidasi ulang di server
    $diskon    = 0;
    $namaPromo = null;
    if ($idPromo) {
        $st = $pdo->prepare("SELECT * FROM promo WHERE id_promo = ? FOR UPDATE");
        $st->execute([$idPromo]);
        $promo = $st->fetch();
        if (!$promo) {
            throw new UserFacingException('Promo tidak ditemukan.');
        }
        $err = promo_cek_error($promo, $subtotal);
        if ($err !== null) {
            throw new UserFacingException($err);
        }
        $diskon    = promo_hitung_diskon($promo, $subtotal);
        $namaPromo = $promo['nama_promo'];
    }

    $total = $subtotal - $diskon;
    if ($bayar < $total) {
        throw new UserFacingException('Uang pembayaran kurang. Total yang benar: ' . rupiah($total) . '.');
    }
    $kembalian = $bayar - $total;

    // Simpan transaksi
    $kode = 'TRX-' . time() . random_int(100, 999);
    $pdo->prepare("
        INSERT INTO transaksi
            (kode_transaksi, id_shift, id_user, id_meja, nomor_meja, total, bayar, kembalian,
             metode_bayar, id_promo, diskon, total_sebelum_diskon)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $kode, $idShift, $idUser, $idMeja ?: null, $nomorMeja, $total, $bayar, $kembalian,
        $metodeBayar, $idPromo, $diskon, $subtotal,
    ]);
    $idTransaksi = (int)$pdo->lastInsertId();

    $stDetail = $pdo->prepare("
        INSERT INTO detail_transaksi (id_transaksi, id_menu, nama_menu, harga, qty, subtotal)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    foreach ($detail as $d) {
        $stDetail->execute([$idTransaksi, $d['id_menu'], $d['nama'], $d['harga'], $d['qty'], $d['subtotal']]);

        // Kurangi stok (sudah dicek & dikunci di atas)
        if ($d['id_varian'] > 0) {
            $pdo->prepare("UPDATE menu_varian SET stok = stok - ? WHERE id_varian = ? AND is_stok_aktif = 1 AND stok IS NOT NULL")
                ->execute([$d['qty'], $d['id_varian']]);
        } else {
            $pdo->prepare("UPDATE menu SET stok = stok - ? WHERE id_menu = ? AND is_stok_aktif = 1 AND stok IS NOT NULL")
                ->execute([$d['qty'], $d['id_menu']]);
        }
    }

    if ($idPromo) {
        $pdo->prepare("UPDATE promo SET terpakai = terpakai + 1 WHERE id_promo = ?")->execute([$idPromo]);
    }

    // Kosongkan meja
    if ($idMeja > 0) {
        $pdo->prepare("UPDATE meja SET status = 'kosong' WHERE id_meja = ?")->execute([$idMeja]);
        $pdo->prepare("DELETE FROM order_aktif WHERE id_meja = ?")->execute([$idMeja]);
    }

    // Menu/varian yang stoknya habis otomatis dinonaktifkan
    $pdo->query("UPDATE menu SET status = 'nonaktif' WHERE is_stok_aktif = 1 AND stok IS NOT NULL AND stok <= 0");
    $pdo->query("UPDATE menu_varian SET status = 'nonaktif' WHERE is_stok_aktif = 1 AND stok IS NOT NULL AND stok <= 0");

    $pdo->commit();

    json_response([
        'status'         => 'success',
        'message'        => 'Transaksi berhasil disimpan.',
        'kode_transaksi' => $kode,
        'subtotal'       => $subtotal,
        'diskon'         => $diskon,
        'nama_promo'     => $namaPromo,
        'total'          => $total,
        'bayar'          => $bayar,
        'kembalian'      => $kembalian,
        'items'          => array_map(fn($d) => [
            'name' => $d['nama'], 'qty' => $d['qty'], 'price' => $d['harga'], 'subtotal' => $d['subtotal'],
        ], $detail),
    ]);
} catch (UserFacingException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['status' => 'error', 'message' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    log_error($e, 'simpan_transaksi');
    json_response(['status' => 'error', 'message' => 'Terjadi kesalahan saat menyimpan transaksi.'], 500);
}
