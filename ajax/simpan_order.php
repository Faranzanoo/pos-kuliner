<?php
/**
 * Simpan / perbarui order aktif sebuah meja (belum dibayar).
 * Isi keranjang disaring ke field yang dikenal saja; harga final tetap
 * dihitung ulang dari database saat pembayaran.
 */
require_once __DIR__ . '/../config/bootstrap.php';

require_login_json();
require_post_json();
csrf_require_header();
require_once __DIR__ . '/../config/koneksi.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || !isset($input['id_meja'])) {
    json_response(['status' => 'error', 'message' => 'Data tidak valid.'], 400);
}

$idMeja = (int)$input['id_meja'];
$idUser = (int)$_SESSION['id_user'];
$role   = $_SESSION['role'] ?? '';

// Saring isi keranjang
$cart  = [];
$total = 0;
foreach ((array)($input['cart'] ?? []) as $item) {
    if (!is_array($item)) {
        continue;
    }
    $id  = (int)($item['id'] ?? 0);
    $idv = !empty($item['id_varian']) ? (int)$item['id_varian'] : null;
    $qty = (int)($item['qty'] ?? 0);
    if ($id <= 0 || $qty <= 0 || $qty > 999) {
        continue;
    }
    $price = max(0, (int)($item['price'] ?? 0));
    $cart[] = [
        'key'       => $idv ? "{$id}_{$idv}" : (string)$id,
        'id'        => $id,
        'name'      => mb_substr((string)($item['name'] ?? ''), 0, 150),
        'price'     => $price,
        'qty'       => $qty,
        'id_varian' => $idv,
    ];
    $total += $price * $qty;
}
if (count($cart) > 100) {
    json_response(['status' => 'error', 'message' => 'Terlalu banyak item.'], 422);
}

try {
    $st = $pdo->prepare("SELECT id_meja FROM meja WHERE id_meja = ?");
    $st->execute([$idMeja]);
    if (!$st->fetch()) {
        json_response(['status' => 'error', 'message' => 'Meja tidak ditemukan.'], 404);
    }

    // Shift diambil dari database, bukan dari browser
    $idShift = null;
    if ($role === 'kasir') {
        $st = $pdo->prepare("SELECT id_shift FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
        $st->execute([$idUser]);
        $shift = $st->fetch();
        $idShift = $shift ? (int)$shift['id_shift'] : null;
    }

    $st = $pdo->prepare("SELECT id_order, id_user FROM order_aktif WHERE id_meja = ?");
    $st->execute([$idMeja]);
    $existing = $st->fetch();

    // Kasir tidak boleh menimpa order milik pengguna lain
    if ($existing && $role !== 'admin' && (int)$existing['id_user'] !== $idUser) {
        json_response(['status' => 'error', 'message' => 'Meja ini sedang dipegang pengguna lain.'], 403);
    }

    $cartJson = json_encode($cart, JSON_UNESCAPED_UNICODE);

    if ($existing) {
        if (count($cart) === 0) {
            $pdo->prepare("DELETE FROM order_aktif WHERE id_meja = ?")->execute([$idMeja]);
        } else {
            $pdo->prepare("UPDATE order_aktif SET cart = ?, total = ?, id_user = ?, updated_at = NOW() WHERE id_meja = ?")
                ->execute([$cartJson, $total, $idUser, $idMeja]);
        }
    } elseif (count($cart) > 0) {
        $pdo->prepare("INSERT INTO order_aktif (id_meja, id_shift, id_user, cart, total) VALUES (?, ?, ?, ?, ?)")
            ->execute([$idMeja, $idShift, $idUser, $cartJson, $total]);
    }

    $pdo->prepare("UPDATE meja SET status = ? WHERE id_meja = ?")
        ->execute([count($cart) > 0 ? 'terisi' : 'kosong', $idMeja]);

    json_response(['status' => 'success', 'message' => 'Order disimpan.']);
} catch (Throwable $e) {
    log_error($e, 'simpan_order');
    json_response(['status' => 'error', 'message' => 'Gagal menyimpan order.'], 500);
}
