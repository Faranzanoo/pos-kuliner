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
    header("Location: " . BASE_URL . "/index.php?pesan_error=Akses+Ditolak!+Halaman+tersebut+hanya+untuk+Admin.");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

// Ambil data pengaturan
$stmt = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
$pengaturan = $stmt->fetch();

// Proses form simpan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Request tidak valid. Silakan coba lagi.";
    } else {
        $nama_toko    = trim($_POST['nama_toko'] ?? '');
        $tagline      = trim($_POST['tagline'] ?? '');
        $alamat       = trim($_POST['alamat'] ?? '');
        $no_telp      = trim($_POST['no_telp'] ?? '');
        $footer_struk = trim($_POST['footer_struk'] ?? '');

        if (empty($nama_toko)) {
            $error = "Nama toko tidak boleh kosong.";
        } else {
            $stmt = $pdo->prepare("
                UPDATE pengaturan 
                SET nama_toko = ?, tagline = ?, alamat = ?, no_telp = ?, footer_struk = ?
                WHERE id = ?
            ");
            if ($stmt->execute([$nama_toko, $tagline, $alamat, $no_telp, $footer_struk, $pengaturan['id']])) {
                $success = "Pengaturan berhasil disimpan!";
                // Refresh data
                $stmt = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
                $pengaturan = $stmt->fetch();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $error = "Gagal menyimpan pengaturan.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pengaturan - POS Kuliner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; letter-spacing: .3px; }
        .form-container {
            max-width: 600px;
            margin: 30px auto;
            background: #fff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 28px rgba(0,0,0,.08);
        }
        .form-label { font-weight: 600; color: #212529; }
        .btn-primary {
            background: linear-gradient(135deg, #d62828, #f77f00);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 10px 20px;
        }
        .btn-primary:hover { opacity: .92; }
        .alert { border-radius: 12px; border: none; }
        .preview-box {
            background: #fff7ed;
            border: 2px dashed #f77f00;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            font-family: Arial, sans-serif;
        }
        .preview-box h5 { font-weight: 800; margin-bottom: 2px; }
        .preview-box p { margin: 0; font-size: 13px; color: #555; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<main class="container py-4">
    <div class="form-container">

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fa-solid fa-check-circle me-2"></i>
                <?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fa-solid fa-exclamation-circle me-2"></i>
                <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <h2 class="mb-4 fw-bold">
            <i class="fa-solid fa-gear me-2 text-danger"></i>
            Pengaturan Toko
        </h2>

        <!-- Preview Struk -->
        <div class="preview-box mb-4">
            <h5 id="prev_nama_toko"><?= htmlspecialchars($pengaturan['nama_toko']) ?></h5>
            <p id="prev_tagline"><?= htmlspecialchars($pengaturan['tagline']) ?></p>
            <small class="text-muted">— preview struk —</small>
            <p id="prev_alamat" class="mt-1"><?= htmlspecialchars($pengaturan['alamat']) ?></p>
            <p id="prev_no_telp"><?= htmlspecialchars($pengaturan['no_telp']) ?></p>
            <hr>
            <p id="prev_footer"><?= htmlspecialchars($pengaturan['footer_struk']) ?></p>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

            <div class="mb-3">
                <label class="form-label">Nama Toko *</label>
                <input type="text" name="nama_toko" class="form-control"
                       value="<?= htmlspecialchars($pengaturan['nama_toko']) ?>"
                       oninput="document.getElementById('prev_nama_toko').innerText = this.value"
                       required>
            </div>

            <div class="mb-3">
                <label class="form-label">Tagline / Jenis Usaha</label>
                <input type="text" name="tagline" class="form-control"
                       value="<?= htmlspecialchars($pengaturan['tagline']) ?>"
                       oninput="document.getElementById('prev_tagline').innerText = this.value"
                       placeholder="Contoh: Ayam Goreng & Kulit Krispi">
            </div>

            <div class="mb-3">
                <label class="form-label">Alamat</label>
                <input type="text" name="alamat" class="form-control"
                       value="<?= htmlspecialchars($pengaturan['alamat']) ?>"
                       oninput="document.getElementById('prev_alamat').innerText = this.value"
                       placeholder="Contoh: Jl. Merdeka No. 10, Jakarta">
            </div>

            <div class="mb-3">
                <label class="form-label">No. Telepon</label>
                <input type="text" name="no_telp" class="form-control"
                       value="<?= htmlspecialchars($pengaturan['no_telp']) ?>"
                       oninput="document.getElementById('prev_no_telp').innerText = this.value"
                       placeholder="Contoh: 08123456789">
            </div>

            <div class="mb-4">
                <label class="form-label">Pesan Footer Struk</label>
                <input type="text" name="footer_struk" class="form-control"
                       value="<?= htmlspecialchars($pengaturan['footer_struk']) ?>"
                       oninput="document.getElementById('prev_footer').innerText = this.value"
                       placeholder="Contoh: Terima kasih, selamat makan!">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-save me-2"></i> Simpan Pengaturan
            </button>
        </form>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>