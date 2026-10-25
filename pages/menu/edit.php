<?php
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/koneksi.php';

$shiftAktif = null;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'kasir') {
    $stmtShiftNav = $pdo->prepare("SELECT * FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
    $stmtShiftNav->execute([$_SESSION['id_user']]);
    $shiftAktif = $stmtShiftNav->fetch();
}

// Layer 1: Cek apakah sudah login? Jika belum, tendang ke halaman login
if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

// Layer 2: Cek apakah role-nya ADMIN? Jika BUKAN, tendang balik ke halaman kasir depan
if ($_SESSION['role'] !== 'admin') {
    header("Location: " . BASE_URL . "/index.php?pesan_error=Akses+Ditolak!+Halaman+tersebut+hanya+untuk+Admin.");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

// Generate CSRF token jika belum ada
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$id_menu = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$menu = null;
$error = '';
$success = '';

// Ambil data menu berdasarkan ID
if ($id_menu > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu WHERE id_menu = ?");
    $stmt->execute([$id_menu]);
    $menu = $stmt->fetch();

    if (!$menu) {
        $error = "Menu tidak ditemukan.";
    }
} else {
    $error = "ID Menu tidak valid.";
}

// Proses Form Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {

    // Validasi CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = "Request tidak valid. Silakan coba lagi.";
    }

    if (empty($error)) {
        $nama_menu = isset($_POST['nama_menu']) ? trim($_POST['nama_menu']) : '';
        $kategori = isset($_POST['kategori']) ? $_POST['kategori'] : 'Makanan';
        $harga = isset($_POST['harga']) ? (int)$_POST['harga'] : 0;
        $status = isset($_POST['status']) ? $_POST['status'] : 'aktif';
        $gambar = $menu['gambar']; // Default: gunakan gambar lama

        // Validasi
        if (empty($nama_menu)) {
            $error = "Nama menu tidak boleh kosong.";
        } elseif ($harga <= 0) {
            $error = "Harga harus lebih dari 0.";
        } elseif (!in_array($kategori, ['Makanan', 'Minuman', 'Paket'])) {
            $error = "Kategori tidak valid.";
        } elseif (!in_array($status, ['aktif', 'nonaktif'])) {
            $error = "Status tidak valid.";
        }

        // Proses upload foto jika ada (tipe & ekstensi ditentukan dari ISI file)
        $gambar_lama = $menu['gambar'];
        if (empty($error) && isset($_FILES['gambar']) && $_FILES['gambar']['error'] !== UPLOAD_ERR_NO_FILE) {
            $hasil = simpan_gambar_menu($_FILES['gambar'], __DIR__ . '/../../assets/img/menu');
            if ($hasil['ok']) {
                $gambar = $hasil['file'];
            } else {
                $error = $hasil['error'];
            }
        }

        // Update ke database jika tidak ada error
        if (empty($error)) {
            $stmt = $pdo->prepare(
                "UPDATE menu 
                 SET nama_menu = ?, kategori = ?, harga = ?, status = ?, gambar = ? 
                 WHERE id_menu = ?"
            );

            if ($stmt->execute([$nama_menu, $kategori, $harga, $status, $gambar, $id_menu])) {
                if ($gambar !== $gambar_lama) {
                    hapus_gambar_menu($gambar_lama, __DIR__ . '/../../assets/img/menu');
                }
                $success = "Menu berhasil diperbarui.";
                // Refresh data dari database
                $stmt = $pdo->prepare("SELECT * FROM menu WHERE id_menu = ?");
                $stmt->execute([$id_menu]);
                $menu = $stmt->fetch();
                // Regenerate CSRF token setelah sukses
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                if ($gambar !== $gambar_lama) {
                    hapus_gambar_menu($gambar, __DIR__ . '/../../assets/img/menu');
                }
                $error = "Gagal update menu. Silakan coba lagi.";
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
    <title>Edit Menu - POS Kuliner</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    
    <style>
        body {
            background: #f8f9fa;
        }

        .navbar {
            background: linear-gradient(135deg, #d62828, #f77f00);
        }

        .brand-title {
            font-weight: 800;
            letter-spacing: .3px;
        }

        .form-container {
            max-width: 600px;
            margin: 30px auto;
            background: #fff;
            padding: 30px;
            border-radius: 16px;
            box-shadow: 0 10px 28px rgba(0, 0, 0, .08);
        }

        .form-label {
            font-weight: 600;
            color: #212529;
        }

        .btn-primary {
            background: linear-gradient(135deg, #d62828, #f77f00);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 10px 20px;
        }

        .btn-primary:hover {
            opacity: .92;
        }

        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 10px 20px;
        }

        .btn-secondary:hover {
            opacity: .92;
        }

        .preview-img {
            max-width: 200px;
            max-height: 200px;
            margin-top: 10px;
            border-radius: 12px;
            border: 2px solid #dee2e6;
        }

        .alert {
            border-radius: 12px;
            border: none;
        }

        .file-input-wrapper {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }

        .file-input-wrapper input[type="file"] {
            position: absolute;
            left: -9999px;
        }

        .file-label {
            display: inline-block;
            padding: 8px 12px;
            background: #f1f3f5;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            cursor: pointer;
            transition: .2s;
        }

        .file-label:hover {
            background: #fff3e0;
            border-color: #f77f00;
        }

        .no-image {
            width: 100%;
            height: 150px;
            background: linear-gradient(135deg, #fff3e0, #ffe0b2);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            color: #d62828;
            font-size: 48px;
        }
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

        <?php if ($menu): ?>
            <h2 class="mb-4 fw-bold">
                <i class="fa-solid fa-pen-to-square me-2 text-danger"></i>
                Edit Menu
            </h2>

            <form method="POST" enctype="multipart/form-data">
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">

                <!-- Nama Menu -->
                <div class="mb-3">
                    <label for="nama_menu" class="form-label">Nama Menu *</label>
                    <input type="text"
                           id="nama_menu"
                           name="nama_menu"
                           class="form-control"
                           value="<?= htmlspecialchars($menu['nama_menu']) ?>"
                           required>
                </div>

                <!-- Kategori -->
                <div class="mb-3">
                    <label for="kategori" class="form-label">Kategori *</label>
                    <select id="kategori" name="kategori" class="form-select">
                        <option value="Makanan" <?= $menu['kategori'] === 'Makanan' ? 'selected' : '' ?>>
                            Makanan
                        </option>
                        <option value="Minuman" <?= $menu['kategori'] === 'Minuman' ? 'selected' : '' ?>>
                            Minuman
                        </option>
                        <option value="Paket" <?= $menu['kategori'] === 'Paket' ? 'selected' : '' ?>>
                            Paket
                        </option>
                    </select>
                </div>

                <!-- Harga -->
                <div class="mb-3">
                    <label for="harga" class="form-label">Harga (Rp) *</label>
                    <input type="number"
                           id="harga"
                           name="harga"
                           class="form-control"
                           value="<?= (int)$menu['harga'] ?>"
                           min="1"
                           required>
                </div>

                <!-- Status -->
                <div class="mb-3">
                    <label for="status" class="form-label">Status *</label>
                    <select id="status" name="status" class="form-select">
                        <option value="aktif" <?= $menu['status'] === 'aktif' ? 'selected' : '' ?>>
                            Aktif
                        </option>
                        <option value="nonaktif" <?= $menu['status'] === 'nonaktif' ? 'selected' : '' ?>>
                            Nonaktif
                        </option>
                    </select>
                </div>

                <!-- Foto Menu -->
                <div class="mb-4">
                    <label class="form-label">Foto Menu</label>

                    <?php if (!empty($menu['gambar'])): ?>
                        <div>
                            <p class="text-muted mb-2">Foto saat ini:</p>
                            <img src="../../assets/img/menu/<?= htmlspecialchars($menu['gambar']) ?>"
                                 alt="<?= htmlspecialchars($menu['nama_menu']) ?>"
                                 class="preview-img">
                        </div>
                    <?php else: ?>
                        <div class="no-image">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mt-3 mb-2">
                        <small>Ganti foto (JPG, PNG, GIF, WebP - max 2MB):</small>
                    </p>
                    <div class="file-input-wrapper">
                        <label class="file-label" for="gambar">
                            <i class="fa-solid fa-cloud-arrow-up me-2"></i>
                            Pilih File
                        </label>
                        <input type="file"
                               id="gambar"
                               name="gambar"
                               accept="image/*"
                               onchange="previewImage(this)">
                    </div>
                    <p id="fileName" class="text-muted mt-2 mb-0"></p>
                    <img id="previewNew" style="display:none;" class="preview-img mt-3">
                </div>

                <!-- Tombol -->
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-save me-2"></i>
                        Simpan Perubahan
                    </button>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fa-solid fa-times me-2"></i>
                        Batal
                    </a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<script>
    function previewImage(input) {
        const file = input.files[0];
        const fileNameEl = document.getElementById('fileName');
        const previewEl = document.getElementById('previewNew');

        if (file) {
            fileNameEl.textContent = 'File dipilih: ' + file.name;

            const reader = new FileReader();
            reader.onload = function (e) {
                previewEl.src = e.target.result;
                previewEl.style.display = 'block';
            };
            reader.readAsDataURL(file);
        } else {
            fileNameEl.textContent = '';
            previewEl.style.display = 'none';
        }
    }
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>