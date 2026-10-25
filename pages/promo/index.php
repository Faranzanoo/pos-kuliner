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

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Request tidak valid.";
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'tambah') {
            $kode_promo   = strtoupper(trim($_POST['kode_promo'] ?? ''));
            $nama_promo   = trim($_POST['nama_promo'] ?? '');
            $tipe_diskon  = $_POST['tipe_diskon'] ?? 'persen';
            $nilai_diskon = (int)($_POST['nilai_diskon'] ?? 0);
            $min_transaksi= (int)($_POST['min_transaksi'] ?? 0);
            $maks_diskon  = $_POST['maks_diskon'] !== '' ? (int)$_POST['maks_diskon'] : null;
            $kuota        = $_POST['kuota'] !== '' ? (int)$_POST['kuota'] : null;
            $tgl_mulai    = $_POST['tgl_mulai'] ?: null;
            $tgl_selesai  = $_POST['tgl_selesai'] ?: null;
            $status       = $_POST['status'] ?? 'aktif';

            if (empty($kode_promo) || empty($nama_promo) || $nilai_diskon <= 0) {
                $error = "Kode, nama, dan nilai diskon wajib diisi.";
            } else {
                try {
                    $pdo->prepare("
                        INSERT INTO promo (kode_promo, nama_promo, tipe_diskon, nilai_diskon, min_transaksi, maks_diskon, kuota, tgl_mulai, tgl_selesai, status)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$kode_promo, $nama_promo, $tipe_diskon, $nilai_diskon, $min_transaksi, $maks_diskon, $kuota, $tgl_mulai, $tgl_selesai, $status]);
                    $success = "Promo berhasil ditambahkan!";
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } catch (Exception $e) {
                    $error = "Kode promo sudah ada atau terjadi kesalahan.";
                }
            }
        }

        if ($action === 'toggle') {
            $id_promo = (int)($_POST['id_promo'] ?? 0);
            $status   = $_POST['status'] === 'aktif' ? 'nonaktif' : 'aktif';
            $pdo->prepare("UPDATE promo SET status = ? WHERE id_promo = ?")->execute([$status, $id_promo]);
            $success = "Status promo diperbarui!";
        }

        if ($action === 'hapus') {
            $id_promo = (int)($_POST['id_promo'] ?? 0);
            $pdo->prepare("DELETE FROM promo WHERE id_promo = ?")->execute([$id_promo]);
            $success = "Promo berhasil dihapus!";
        }
    }
}

$promos = $pdo->query("SELECT * FROM promo ORDER BY created_at DESC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kelola Promo - <?= htmlspecialchars($pengaturan['nama_toko']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; }
        .section-card { background: #fff; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.08); padding: 24px; margin-bottom: 24px; }
        .btn-primary { background: linear-gradient(135deg, #d62828, #f77f00); border: none; border-radius: 10px; font-weight: 600; }
        .btn-primary:hover { opacity: .92; }
        .badge-aktif { background: #e8f5e9; color: #2e7d32; padding: 4px 10px; border-radius: 999px; font-size: 12px; }
        .badge-nonaktif { background: #ffebee; color: #c62828; padding: 4px 10px; border-radius: 999px; font-size: 12px; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<main class="container py-4">
    <h4 class="fw-bold mb-4"><i class="fa-solid fa-tag me-2 text-danger"></i>Kelola Promo</h4>

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

    <!-- Form Tambah Promo -->
    <div class="section-card">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-plus me-2 text-danger"></i>Tambah Promo Baru</h5>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" value="tambah">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-bold">Kode Promo *</label>
                    <input type="text" name="kode_promo" class="form-control" placeholder="Contoh: DISKON10" required style="text-transform:uppercase;">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-bold">Nama Promo *</label>
                    <input type="text" name="nama_promo" class="form-control" placeholder="Contoh: Diskon 10%" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tipe Diskon *</label>
                    <select name="tipe_diskon" class="form-select" onchange="toggleMaksDiskon(this)">
                        <option value="persen">Persen (%)</option>
                        <option value="nominal">Nominal (Rp)</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Nilai Diskon *</label>
                    <input type="number" name="nilai_diskon" class="form-control" placeholder="Contoh: 10" required min="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold">Min. Transaksi (Rp)</label>
                    <input type="number" name="min_transaksi" class="form-control" value="0" min="0">
                </div>
                <div class="col-md-3" id="maksDiskonWrapper">
                    <label class="form-label fw-bold">Maks. Diskon (Rp)</label>
                    <input type="number" name="maks_diskon" class="form-control" placeholder="Kosong = tidak dibatasi" min="0">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Kuota</label>
                    <input type="number" name="kuota" class="form-control" placeholder="Kosong = unlimited" min="1">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tgl Mulai</label>
                    <input type="date" name="tgl_mulai" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Tgl Selesai</label>
                    <input type="date" name="tgl_selesai" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-bold">Status</label>
                    <select name="status" class="form-select">
                        <option value="aktif">Aktif</option>
                        <option value="nonaktif">Nonaktif</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary mt-3 px-4">
                <i class="fa-solid fa-plus me-2"></i>Tambah Promo
            </button>
        </form>
    </div>

    <!-- List Promo -->
    <div class="section-card">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-list me-2 text-danger"></i>Daftar Promo</h5>
        <?php if (count($promos) === 0): ?>
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-inbox fa-2x mb-2"></i>
                <p>Belum ada promo.</p>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead class="table-light">
                    <tr>
                        <th>Kode</th>
                        <th>Nama</th>
                        <th>Diskon</th>
                        <th>Min. Transaksi</th>
                        <th>Kuota</th>
                        <th>Periode</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promos as $p): ?>
                    <tr>
                        <td><code class="fw-bold text-danger"><?= htmlspecialchars($p['kode_promo']) ?></code></td>
                        <td><?= htmlspecialchars($p['nama_promo']) ?></td>
                        <td>
                            <?php if ($p['tipe_diskon'] === 'persen'): ?>
                                <?= $p['nilai_diskon'] ?>%
                                <?php if ($p['maks_diskon']): ?>
                                    <small class="text-muted">(maks Rp <?= number_format($p['maks_diskon'], 0, ',', '.') ?>)</small>
                                <?php endif; ?>
                            <?php else: ?>
                                Rp <?= number_format($p['nilai_diskon'], 0, ',', '.') ?>
                            <?php endif; ?>
                        </td>
                        <td>Rp <?= number_format($p['min_transaksi'], 0, ',', '.') ?></td>
                        <td>
                            <?= $p['terpakai'] ?> / <?= $p['kuota'] ?? '∞' ?>
                        </td>
                        <td>
                            <small>
                                <?= $p['tgl_mulai'] ? date('d/m/Y', strtotime($p['tgl_mulai'])) : '∞' ?>
                                —
                                <?= $p['tgl_selesai'] ? date('d/m/Y', strtotime($p['tgl_selesai'])) : '∞' ?>
                            </small>
                        </td>
                        <td>
                            <span class="<?= $p['status'] === 'aktif' ? 'badge-aktif' : 'badge-nonaktif' ?>">
                                <?= ucfirst($p['status']) ?>
                            </span>
                        </td>
                        <td>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id_promo" value="<?= $p['id_promo'] ?>">
                                <input type="hidden" name="status" value="<?= $p['status'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary rounded-3">
                                    <i class="fa-solid fa-power-off"></i>
                                </button>
                            </form>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Hapus promo <?= htmlspecialchars(addslashes($p['kode_promo'])) ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <input type="hidden" name="action" value="hapus">
                                <input type="hidden" name="id_promo" value="<?= $p['id_promo'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger rounded-3">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function toggleMaksDiskon(select) {
        const wrapper = document.getElementById('maksDiskonWrapper');
        wrapper.style.display = select.value === 'persen' ? 'block' : 'none';
    }
</script>
</body>
</html>