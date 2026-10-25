<?php
require_once __DIR__ . '/config/bootstrap.php';
if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/config/koneksi.php';

// Generate CSRF token kalau belum ada — WAJIB sebelum dipakai di APP object
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmtPengaturan = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
$pengaturan = $stmtPengaturan->fetch();

// Cek shift untuk kasir
$shiftAktif = null;
if ($_SESSION['role'] === 'kasir') {
    $stmtShift = $pdo->prepare("SELECT * FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
    $stmtShift->execute([$_SESSION['id_user']]);
    $shiftAktif = $stmtShift->fetch();
    if (!$shiftAktif) {
        header("Location: " . BASE_URL . "/shift.php");
        exit;
    }
}

// Cek meja dari parameter URL
$id_meja = isset($_GET['meja']) ? (int)$_GET['meja'] : 0;
$mejaNow = null;
$orderAktif = null;
$cartAwal = [];

if ($id_meja > 0) {
    $stmtMeja = $pdo->prepare("SELECT * FROM meja WHERE id_meja = ?");
    $stmtMeja->execute([$id_meja]);
    $mejaNow = $stmtMeja->fetch();

    if ($mejaNow) {
        $stmtOrder = $pdo->prepare("SELECT * FROM order_aktif WHERE id_meja = ?");
        $stmtOrder->execute([$id_meja]);
        $orderAktif = $stmtOrder->fetch();

        if ($orderAktif) {
            // Ada order aktif di meja ini
            if ($orderAktif['id_user'] == $_SESSION['id_user']) {
                // Order milik user ini — load cart
                $cartAwal = json_decode($orderAktif['cart'], true) ?? [];
            } else {
                // Order milik user lain
                // Kasir tidak boleh masuk, admin boleh ambil alih
                if ($_SESSION['role'] === 'admin') {
                    // Admin ambil alih: update id_user ke admin ini
                    $pdo->prepare("UPDATE order_aktif SET id_user = ?, id_shift = ? WHERE id_meja = ?")
                        ->execute([$_SESSION['id_user'], null, $id_meja]);
                    // Refresh order aktif
                    $stmtOrder = $pdo->prepare("SELECT * FROM order_aktif WHERE id_meja = ?");
                    $stmtOrder->execute([$id_meja]);
                    $orderAktif = $stmtOrder->fetch();
                    $cartAwal = json_decode($orderAktif['cart'], true) ?? [];
                } else {
                    // Kasir tidak bisa masuk meja yang dikunci user lain
                    header("Location: " . BASE_URL . "/pages/meja/index.php");
                    exit;
                }
            }
        } else {
    // Jangan INSERT dulu — biarkan kosong
    // Record order_aktif baru dibuat saat kasir/admin pertama kali tambah item
    // lewat autoSimpanOrder() di kasir.js
    $orderAktif = null;
    $cartAwal = [];
        }
    }
} else {
    header("Location: " . BASE_URL . "/pages/meja/index.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM menu WHERE status = 'aktif' ORDER BY kategori ASC, nama_menu ASC");
$stmt->execute();
$menus = $stmt->fetchAll();

// Ambil semua varian untuk menu yang aktif
$stmtVarian = $pdo->query("
    SELECT * FROM menu_varian 
    WHERE status = 'aktif' 
    ORDER BY id_menu ASC, id_varian ASC
");
$semuaVarian = $stmtVarian->fetchAll();

// Kelompokkan varian per id_menu
$varianPerMenu = [];
foreach ($semuaVarian as $v) {
    $varianPerMenu[$v['id_menu']][] = $v;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pengaturan['nama_toko']) ?> - Meja <?= $mejaNow['nomor_meja'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: Arial, sans-serif; }
        .sold-out { cursor: not-allowed !important; }
        .menu-img { position: relative; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; letter-spacing: .3px; }
        .menu-card { cursor: pointer; border: none; border-radius: 18px; transition: .2s ease; overflow: hidden; background: #fff; height: 100%; }
        .menu-card:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(0,0,0,.12); }
        .menu-img { height: 110px; background: linear-gradient(135deg, #fff3e0, #ffe0b2); display: flex; align-items: center; justify-content: center; color: #d62828; font-size: 40px; }
        .menu-name { font-weight: 700; color: #212529; min-height: 42px; }
        .menu-price { font-weight: 800; color: #d62828; font-size: 18px; }
        .cart-panel { background: #fff; border-radius: 22px; box-shadow: 0 10px 28px rgba(0,0,0,.08); position: sticky; top: 90px; }
        .cart-item { border-bottom: 1px solid #eee; padding: 12px 0; }
        .qty-btn { width: 34px; height: 34px; border-radius: 50%; border: none; background: #f1f3f5; font-weight: bold; }
        .qty-btn:hover { background: #ffe0b2; }
        .btn-bayar { background: linear-gradient(135deg, #d62828, #f77f00); color: #fff; border: none; border-radius: 16px; font-size: 18px; font-weight: 800; padding: 14px; }
        .btn-bayar:hover { color: #fff; opacity: .92; }
        .btn-simpan { background: linear-gradient(135deg, #1565c0, #1976d2); color: #fff; border: none; border-radius: 16px; font-size: 15px; font-weight: 700; padding: 10px; }
        .btn-simpan:hover { color: #fff; opacity: .92; }
        .summary-box { background: #fff7ed; border-radius: 16px; padding: 16px; }
        .empty-cart { color: #adb5bd; text-align: center; padding: 30px 10px; }
        .kategori-badge { font-size: 12px; background: #fff3e0; color: #d62828; border-radius: 999px; padding: 4px 10px; display: inline-block; margin-bottom: 8px; }
        .shift-badge { background: rgba(255,255,255,.2); border-radius: 999px; padding: 4px 12px; font-size: 13px; }
        .tab-kategori .nav-link { border-radius: 999px; font-weight: 600; color: #6c757d; border: 2px solid #dee2e6; margin-right: 6px; padding: 6px 16px; }
        .tab-kategori .nav-link.active { background: linear-gradient(135deg, #d62828, #f77f00); color: #fff; border-color: transparent; }
        .meja-info { background: rgba(255,255,255,.15); border-radius: 999px; padding: 4px 14px; font-size: 13px; font-weight: 700; }
        @media (max-width: 991px) { .cart-panel { position: static; margin-top: 20px; } }
        @media print {
            body * { visibility: hidden; }
            #strukArea, #strukArea * { visibility: visible; }
            #strukArea { position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm no-print">
    <div class="container-fluid px-4">
        <a class="navbar-brand brand-title" href="<?= BASE_URL ?>/pages/meja/index.php">
            <i class="fa-solid fa-arrow-left me-2"></i>
            <?= htmlspecialchars($pengaturan['nama_toko']) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto fw-bold align-items-center">
                <li class="nav-item me-2">
                    <span class="meja-info">
                        <i class="fa-solid fa-table me-1"></i> Meja <?= $mejaNow['nomor_meja'] ?>
                    </span>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/pages/laporan/index.php">
                        <i class="fa-solid fa-chart-line me-1"></i> Laporan
                    </a>
                </li>
                <?php if ($_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/pages/menu/index.php">
                        <i class="fa-solid fa-burger me-1"></i> Kelola Menu
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/pages/promo/index.php">
                        <i class="fa-solid fa-tag me-1"></i> Promo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>/pages/pengaturan/index.php">
                        <i class="fa-solid fa-gear me-1"></i> Pengaturan
                    </a>
                </li>
                <?php endif; ?>
                <?php if ($_SESSION['role'] === 'kasir' && $shiftAktif): ?>
                <li class="nav-item d-flex align-items-center mx-2">
                    <a href="<?= BASE_URL ?>/shift.php" class="nav-link shift-badge">
                        <i class="fa-solid fa-clock me-1 text-warning"></i>
                        <?= date('H:i', strtotime($shiftAktif['waktu_mulai'])) ?>
                        <span class="badge bg-warning text-dark ms-1">End Shift</span>
                    </a>
                </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                        <i class="fa-solid fa-right-from-bracket me-1 text-danger"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<main class="container-fluid py-4 no-print">
    <?php if (isset($_GET['pesan_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            <?= htmlspecialchars($_GET['pesan_error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                <div>
                    <h3 class="fw-bold mb-1">Daftar Menu</h3>
                    <p class="text-muted mb-0">Klik menu untuk masuk ke keranjang.</p>
                </div>
                <div class="input-group" style="max-width: 280px;">
                    <span class="input-group-text bg-white"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="text" id="searchMenu" class="form-control" placeholder="Cari menu...">
                </div>
            </div>

            <!-- Tab Kategori -->
            <ul class="nav tab-kategori mb-3 flex-wrap">
                <li class="nav-item">
                    <a class="nav-link active" href="#" onclick="filterKategori('semua', this); return false;">
                        <i class="fa-solid fa-th me-1"></i> Semua
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="filterKategori('Makanan', this); return false;">
                        🍗 Makanan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="filterKategori('Minuman', this); return false;">
                        🥤 Minuman
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" onclick="filterKategori('Paket', this); return false;">
                        📦 Paket
                    </a>
                </li>
            </ul>

            <div class="row g-3" id="menuGrid">
                <?php foreach ($menus as $menu):
                    $isSoldOut    = $menu['is_stok_aktif'] && $menu['stok'] !== null && $menu['stok'] <= 0;
                    $punya_varian = !empty($varianPerMenu[$menu['id_menu']]);
                ?>
                    <div class="col-6 col-md-4 col-xl-3 menu-wrapper"
                        data-name="<?= htmlspecialchars(strtolower($menu['nama_menu'])) ?>"
                        data-kategori="<?= htmlspecialchars($menu['kategori']) ?>"
                        data-id="<?= $menu['id_menu'] ?>"
                        data-nama="<?= htmlspecialchars($menu['nama_menu'], ENT_QUOTES) ?>"
                        data-harga="<?= (int)$menu['harga'] ?>"
                        data-varian="<?= $punya_varian ? htmlspecialchars(json_encode($varianPerMenu[$menu['id_menu']]), ENT_QUOTES) : '[]' ?>"
                        data-soldout="<?= $isSoldOut ? '1' : '0' ?>">
                        <div class="card menu-card <?= $isSoldOut ? 'sold-out' : '' ?>"
                            onclick="handleMenuClickFromEl(this)">
                            <div class="menu-img" style="<?= $isSoldOut ? 'filter:grayscale(1);opacity:.6;' : '' ?>">
                                <?php if (!empty($menu['gambar'])): ?>
                                    <img src="<?= BASE_URL ?>/assets/img/menu/<?= htmlspecialchars($menu['gambar']) ?>"
                                        alt="<?= htmlspecialchars($menu['nama_menu']) ?>"
                                        style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <i class="fa-solid fa-bowl-food"></i>
                                <?php endif; ?>
                                <?php if ($isSoldOut): ?>
                                    <div style="position:absolute;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.4);display:flex;align-items:center;justify-content:center;">
                                        <span style="background:#d62828;color:#fff;font-weight:800;padding:4px 12px;border-radius:999px;font-size:13px;">SOLD OUT</span>
                                    </div>
                                <?php endif; ?>
                                <?php if ($punya_varian && !$isSoldOut): ?>
                                    <div style="position:absolute;top:8px;right:8px;">
                                        <span style="background:#7b1fa2;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">VARIAN</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="card-body p-3">
                                <span class="kategori-badge"><?= htmlspecialchars($menu['kategori']) ?></span>
                                <div class="menu-name"><?= htmlspecialchars($menu['nama_menu']) ?></div>
                                <div class="menu-price mt-2">
                                    Rp <?= number_format($menu['harga'], 0, ',', '.') ?>
                                    <?php if ($menu['is_stok_aktif'] && $menu['stok'] !== null && $menu['stok'] > 0): ?>
                                        <span style="font-size:11px;color:#6c757d;font-weight:400;"> (sisa <?= $menu['stok'] ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="cart-panel p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h4 class="fw-bold mb-0">
                        <i class="fa-solid fa-cart-shopping me-2 text-danger"></i>
                        Meja <?= $mejaNow['nomor_meja'] ?>
                    </h4>
                    <button class="btn btn-sm btn-outline-danger" onclick="clearCart()">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>

                <div id="cartItems">
                    <div class="empty-cart">
                        <i class="fa-solid fa-basket-shopping fa-3x mb-3"></i>
                        <p class="mb-0">Belum ada item dipilih.</p>
                    </div>
                </div>

                <div class="summary-box mt-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal</span><strong id="subtotalText">Rp 0</strong>
                    </div>
                    <div id="promoBox" style="display:none;">
                        <div class="d-flex justify-content-between mb-2 text-success">
                            <span id="promoNama">Diskon</span>
                            <strong id="promoDiskon">- Rp 0</strong>
                        </div>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span>Total</span><strong id="totalText">Rp 0</strong>
                    </div>

                    <!-- Input Promo -->
                    <div class="input-group mb-2">
                        <input type="text" id="kodePromo" class="form-control"
                               placeholder="Kode promo (opsional)"
                               style="text-transform:uppercase;">
                        <button class="btn btn-outline-secondary" onclick="terapkanPromo()" type="button">
                            <i class="fa-solid fa-tag"></i> Pakai
                        </button>
                        <button class="btn btn-outline-danger" id="btnHapusPromo"
                                onclick="hapusPromo()" type="button" style="display:none;">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <div id="promoMessage" class="mb-2"></div>

                    <label for="uangBayar" class="form-label mt-1">Uang Pelanggan</label>
                    <input type="number" id="uangBayar" class="form-control form-control-lg"
                           placeholder="Masukkan nominal" oninput="calculateChange()">
                    <div class="d-flex justify-content-between mt-3">
                        <span>Kembalian</span>
                        <strong id="kembalianText" class="text-success">Rp 0</strong>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-3">
                    <button class="btn btn-simpan flex-fill" onclick="simpanOrder()">
                        <i class="fa-solid fa-floppy-disk me-1"></i> Simpan
                    </button>
                    <button class="btn btn-bayar flex-fill" onclick="processPayment()">
                        <i class="fa-solid fa-print me-1"></i> Bayar
                    </button>
                </div>
                <div id="paymentMessage" class="mt-3"></div>
            </div>
        </div>
    </div>
</main>

<section id="strukArea" style="display:none;">
    <div style="max-width:320px;margin:auto;font-family:Arial,sans-serif;">
        <h3 style="text-align:center;margin-bottom:4px;"><?= htmlspecialchars($pengaturan['nama_toko']) ?></h3>
        <p style="text-align:center;margin:0;"><?= htmlspecialchars($pengaturan['tagline']) ?></p>
        <?php if (!empty($pengaturan['alamat'])): ?>
            <p style="text-align:center;margin:0;font-size:12px;"><?= htmlspecialchars($pengaturan['alamat']) ?></p>
        <?php endif; ?>
        <?php if (!empty($pengaturan['no_telp'])): ?>
            <p style="text-align:center;margin:0;font-size:12px;">Telp: <?= htmlspecialchars($pengaturan['no_telp']) ?></p>
        <?php endif; ?>
        <hr>
        <p style="margin:0;">Kode: <span id="strukKode"></span></p>
        <p style="margin:0;">Tanggal: <span id="strukTanggal"></span></p>
        <p style="margin:0;">Kasir: <?= htmlspecialchars($_SESSION['username']) ?></p>
        <p style="margin:0;">Meja: <?= $mejaNow['nomor_meja'] ?></p>
        <hr>
        <div id="strukItems"></div>
        <hr>
        <p style="display:flex;justify-content:space-between;"><strong>Total</strong><strong id="strukTotal"></strong></p>
        <p style="display:flex;justify-content:space-between;"><span>Bayar</span><span id="strukBayar"></span></p>
        <p style="display:flex;justify-content:space-between;"><span>Kembalian</span><span id="strukKembalian"></span></p>
        <p style="display:flex;justify-content:space-between;"><span>Metode</span><span id="strukMetode"></span></p>
        <hr>
        <p style="text-align:center;"><?= htmlspecialchars($pengaturan['footer_struk']) ?></p>
    </div>
</section>

<div class="modal fade" id="logoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title fw-bold">
                    <i class="fa-solid fa-right-from-bracket me-2"></i>Konfirmasi Keluar
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <i class="fa-solid fa-circle-question text-danger mb-3" style="font-size:3rem;"></i>
                <h5 class="fw-bold mb-2">Apakah Anda yakin ingin keluar?</h5>
                <p class="text-muted mb-0">Anda harus login kembali untuk mengakses sistem.</p>
            </div>
            <div class="modal-footer border-0 justify-content-center pb-4">
                <button type="button" class="btn btn-secondary px-4 fw-bold rounded-3" data-bs-dismiss="modal">Batal</button>
                <a href="<?= BASE_URL ?>/logout.php" class="btn btn-danger px-4 fw-bold rounded-3">Ya, Keluar</a>
            </div>
        </div>
    </div>
</div>

<script>
    const APP = {
        baseUrl:       '<?= BASE_URL ?>',
        ajaxUrl:       '<?= BASE_URL ?>/ajax/simpan_transaksi.php',
        simpanOrderUrl:'<?= BASE_URL ?>/ajax/simpan_order.php',
        cekPromoUrl:   '<?= BASE_URL ?>/ajax/cek_promo.php',
        csrfToken:     '<?= e(csrf_token()) ?>',
        idMeja:        <?= (int)$id_meja ?>,
        cartAwal:      <?= json_encode($cartAwal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };
</script>
<script src="<?= BASE_URL ?>/assets/js/kasir.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>