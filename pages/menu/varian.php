<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/koneksi.php';

$shiftAktif = null;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'kasir') {
    $stmtShiftNav = $pdo->prepare("SELECT * FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
    $stmtShiftNav->execute([$_SESSION['id_user']]);
    $shiftAktif = $stmtShiftNav->fetch();
}

if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . BASE_URL . "/index.php?pesan_error=Akses+Ditolak!");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$stmtPengaturan = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
$pengaturan = $stmtPengaturan->fetch();

$id_menu = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id_menu <= 0) {
    header("Location: " . BASE_URL . "/pages/menu/index.php");
    exit;
}

$stmtMenu = $pdo->prepare("SELECT * FROM menu WHERE id_menu = ?");
$stmtMenu->execute([$id_menu]);
$menu = $stmtMenu->fetch();
if (!$menu) {
    header("Location: " . BASE_URL . "/pages/menu/index.php");
    exit;
}

$error = '';
$success = '';

// Proses form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Request tidak valid.";
    } else {
        $action = $_POST['action'] ?? '';

        // Update stok menu utama
        if ($action === 'update_stok') {
            $is_stok_aktif = isset($_POST['is_stok_aktif']) ? 1 : 0;
            $stok = $is_stok_aktif && $_POST['stok'] !== '' ? (int)$_POST['stok'] : null;
            $pdo->prepare("UPDATE menu SET is_stok_aktif = ?, stok = ? WHERE id_menu = ?")
                ->execute([$is_stok_aktif, $stok, $id_menu]);
            $success = "Pengaturan stok berhasil disimpan!";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Tambah varian
        if ($action === 'tambah_varian') {
            $nama_varian   = trim($_POST['nama_varian'] ?? '');
            $harga_tambah  = (int)($_POST['harga_tambah'] ?? 0);
            $is_stok_varian = isset($_POST['is_stok_varian']) ? 1 : 0;
            $stok_varian   = $is_stok_varian && $_POST['stok_varian'] !== '' ? (int)$_POST['stok_varian'] : null;

            if (empty($nama_varian)) {
                $error = "Nama varian tidak boleh kosong.";
            } else {
                $pdo->prepare("INSERT INTO menu_varian (id_menu, nama_varian, harga_tambah, stok, is_stok_aktif) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$id_menu, $nama_varian, $harga_tambah, $stok_varian, $is_stok_varian]);
                $success = "Varian berhasil ditambahkan!";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        }

        // Hapus varian
        if ($action === 'hapus_varian') {
            $id_varian = (int)($_POST['id_varian'] ?? 0);
            if ($id_varian > 0) {
                $pdo->prepare("DELETE FROM menu_varian WHERE id_varian = ? AND id_menu = ?")
                    ->execute([$id_varian, $id_menu]);
                $success = "Varian berhasil dihapus!";
            }
        }

        // Update status varian
        if ($action === 'toggle_varian') {
            $id_varian = (int)($_POST['id_varian'] ?? 0);
            $status    = $_POST['status'] === 'aktif' ? 'nonaktif' : 'aktif';
            $pdo->prepare("UPDATE menu_varian SET status = ? WHERE id_varian = ? AND id_menu = ?")
                ->execute([$status, $id_varian, $id_menu]);
            $success = "Status varian diperbarui!";
        }

        // Update stok varian
        if ($action === 'update_stok_varian') {
            $id_varian      = (int)($_POST['id_varian'] ?? 0);
            $is_stok_varian = isset($_POST['is_stok_varian']) ? 1 : 0;
            $stok_varian    = $is_stok_varian && $_POST['stok_varian'] !== '' ? (int)$_POST['stok_varian'] : null;
            $pdo->prepare("UPDATE menu_varian SET is_stok_aktif = ?, stok = ? WHERE id_varian = ? AND id_menu = ?")
                ->execute([$is_stok_varian, $stok_varian, $id_varian, $id_menu]);
            $success = "Stok varian diperbarui!";
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        // Refresh data menu
        $stmtMenu->execute([$id_menu]);
        $menu = $stmtMenu->fetch();
    }
}

// Ambil varian
$stmtVarian = $pdo->prepare("SELECT * FROM menu_varian WHERE id_menu = ? ORDER BY id_varian ASC");
$stmtVarian->execute([$id_menu]);
$variants = $stmtVarian->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Stok & Varian - <?= htmlspecialchars($menu['nama_menu']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; }
        .section-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            padding: 24px;
            margin-bottom: 24px;
        }
        .form-label { font-weight: 600; }
        .btn-primary {
            background: linear-gradient(135deg, #d62828, #f77f00);
            border: none; border-radius: 10px; font-weight: 600;
        }
        .btn-primary:hover { opacity: .92; }
        .varian-row {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 14px 16px;
            margin-bottom: 10px;
        }
        .harga-varian { color: #d62828; font-weight: 700; }
        .badge-aktif { background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 999px; font-size: 12px; }
        .badge-nonaktif { background: #ffebee; color: #c62828; padding: 4px 10px; border-radius: 999px; font-size: 12px; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<main class="container py-4">
    <h4 class="fw-bold mb-4">
        <i class="fa-solid fa-sliders me-2 text-danger"></i>
        Stok & Varian — <?= htmlspecialchars($menu['nama_menu']) ?>
        <span class="fs-6 text-muted fw-normal ms-2">Rp <?= number_format($menu['harga'], 0, ',', '.') ?> (harga dasar)</span>
    </h4>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show rounded-3">
            <i class="fa-solid fa-check-circle me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show rounded-3">
            <i class="fa-solid fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Section Stok Menu Utama -->
    <div class="section-card">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-box me-2 text-danger"></i>Pengaturan Stok Menu</h5>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="update_stok">

            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="is_stok_aktif" name="is_stok_aktif"
                    <?= $menu['is_stok_aktif'] ? 'checked' : '' ?>
                    onchange="toggleStokInput('stokInput', this)">
                <label class="form-check-label fw-bold" for="is_stok_aktif">Aktifkan batas stok untuk menu ini</label>
            </div>

            <div id="stokInput" style="display:<?= $menu['is_stok_aktif'] ? 'block' : 'none' ?>;">
                <div class="mb-3" style="max-width:200px;">
                    <label class="form-label">Jumlah Stok Hari Ini</label>
                    <input type="number" name="stok" class="form-control" min="0"
                           value="<?= $menu['stok'] ?? '' ?>" placeholder="Contoh: 20">
                    <small class="text-muted">Kosongkan jika tidak ingin membatasi.</small>
                </div>
            </div>

            <button type="submit" class="btn btn-primary px-4">
                <i class="fa-solid fa-save me-2"></i>Simpan Stok
            </button>
        </form>
    </div>

    <!-- Section Varian -->
    <div class="section-card">
        <h5 class="fw-bold mb-1"><i class="fa-solid fa-list me-2 text-danger"></i>Varian Menu</h5>
        <p class="text-muted mb-4">Harga varian = harga dasar + harga tambah (bisa minus untuk yang lebih murah).</p>

        <?php if (count($variants) === 0): ?>
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-inbox fa-2x mb-2"></i>
                <p>Belum ada varian. Tambahkan di bawah.</p>
            </div>
        <?php else: ?>
            <?php foreach ($variants as $v): ?>
            <div class="varian-row">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <strong><?= htmlspecialchars($v['nama_varian']) ?></strong>
                        <span class="ms-2 <?= $v['status'] === 'aktif' ? 'badge-aktif' : 'badge-nonaktif' ?>">
                            <?= ucfirst($v['status']) ?>
                        </span>
                        <div class="harga-varian mt-1">
                            Rp <?= number_format($menu['harga'] + $v['harga_tambah'], 0, ',', '.') ?>
                            <?php if ($v['harga_tambah'] != 0): ?>
                                <small class="text-muted fw-normal">
                                    (<?= $v['harga_tambah'] > 0 ? '+' : '' ?> Rp <?= number_format($v['harga_tambah'], 0, ',', '.') ?>)
                                </small>
                            <?php endif; ?>
                        </div>
                        <?php if ($v['is_stok_aktif']): ?>
                            <div class="mt-1">
                                <small class="text-muted">Stok: 
                                    <?php if ($v['stok'] !== null): ?>
                                        <strong class="<?= $v['stok'] == 0 ? 'text-danger' : 'text-success' ?>">
                                            <?= $v['stok'] ?> tersisa
                                        </strong>
                                    <?php else: ?>
                                        Tidak dibatasi
                                    <?php endif; ?>
                                </small>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2">
                        <!-- Toggle status -->
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="toggle_varian">
                            <input type="hidden" name="id_varian" value="<?= $v['id_varian'] ?>">
                            <input type="hidden" name="status" value="<?= $v['status'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">
                                <i class="fa-solid fa-power-off"></i>
                            </button>
                        </form>
                        <!-- Hapus -->
                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Hapus varian <?= htmlspecialchars(addslashes($v['nama_varian'])) ?>?')">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <input type="hidden" name="action" value="hapus_varian">
                            <input type="hidden" name="id_varian" value="<?= $v['id_varian'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger rounded-3">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Update stok varian -->
                <form method="POST" class="mt-3 pt-3 border-top">
                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                    <input type="hidden" name="action" value="update_stok_varian">
                    <input type="hidden" name="id_varian" value="<?= $v['id_varian'] ?>">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="stok_aktif_<?= $v['id_varian'] ?>"
                                name="is_stok_varian" <?= $v['is_stok_aktif'] ? 'checked' : '' ?>
                                onchange="toggleStokInput('stokVarian_<?= $v['id_varian'] ?>', this)">
                            <label class="form-check-label" for="stok_aktif_<?= $v['id_varian'] ?>">Batas stok</label>
                        </div>
                        <div id="stokVarian_<?= $v['id_varian'] ?>" style="display:<?= $v['is_stok_aktif'] ? 'flex' : 'none' ?>;gap:8px;align-items:center;">
                            <input type="number" name="stok_varian" class="form-control form-control-sm"
                                   style="width:100px;" min="0" value="<?= $v['stok'] ?? '' ?>" placeholder="Stok">
                            <button type="submit" class="btn btn-sm btn-primary px-3">Simpan</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Form tambah varian -->
        <div class="mt-4 pt-3 border-top">
            <h6 class="fw-bold mb-3">+ Tambah Varian Baru</h6>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="tambah_varian">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Nama Varian *</label>
                        <input type="text" name="nama_varian" class="form-control"
                               placeholder="Contoh: Paha Atas + Nasi" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Harga Tambah (Rp)</label>
                        <input type="number" name="harga_tambah" class="form-control"
                               value="0" placeholder="0 = sama, -2000 = lebih murah">
                        <small class="text-muted">0 = harga sama dengan menu dasar</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Stok Awal (opsional)</label>
                        <div class="d-flex gap-2 align-items-center">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" id="is_stok_varian_baru"
                                    name="is_stok_varian"
                                    onchange="toggleStokInput('stokVarianBaru', this)">
                            </div>
                            <div id="stokVarianBaru" style="display:none;">
                                <input type="number" name="stok_varian" class="form-control form-control-sm"
                                       style="width:100px;" min="0" placeholder="Stok">
                            </div>
                        </div>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary mt-3 px-4">
                    <i class="fa-solid fa-plus me-2"></i>Tambah Varian
                </button>
            </form>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleStokInput(id, checkbox) {
        document.getElementById(id).style.display = checkbox.checked ? 'flex' : 'none';
        if (!checkbox.checked) {
            const input = document.querySelector('#' + id + ' input[type="number"]');
            if (input) input.value = '';
        }
    }
</script>
</body>
</html>