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

$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

// Ambil semua menu
$stmt = $pdo->prepare("
    SELECT * FROM menu 
    ORDER BY kategori ASC, nama_menu ASC
");
$stmt->execute();
$menus = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manajemen Menu - POS Kuliner</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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

        .header-section {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            margin-bottom: 24px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .06);
        }

        .btn-tambah {
            background: linear-gradient(135deg, #d62828, #f77f00);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            padding: 12px 20px;
        }

        .btn-tambah:hover {
            color: #fff;
            opacity: .92;
        }

        .table-container {
            background: #fff;
            padding: 24px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, .06);
            overflow-x: auto;
        }

        .table {
            margin-bottom: 0;
        }

        .table thead {
            background: #f8f9fa;
        }

        .table thead th {
            font-weight: 700;
            border-bottom: 2px solid #dee2e6;
            color: #212529;
        }

        .menu-img {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 8px;
        }

        .badge-kategori {
            font-size: 12px;
            padding: 6px 10px;
            border-radius: 999px;
        }

        .badge-makanan {
            background: #fff3e0;
            color: #d62828;
        }

        .badge-minuman {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-paket {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-aktif {
            background: #e8f5e9;
            color: #2e7d32;
            font-size: 11px;
        }

        .badge-nonaktif {
            background: #ffebee;
            color: #c62828;
            font-size: 11px;
        }

        .btn-aksi {
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 8px;
        }

        .btn-edit {
            background: #ffc107;
            color: #000;
            border: none;
        }

        .btn-edit:hover {
            opacity: .85;
        }

        .btn-hapus {
            background: #dc3545;
            color: #fff;
            border: none;
        }

        .btn-hapus:hover {
            opacity: .85;
        }

        .alert {
            border-radius: 12px;
            border: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }

        .empty-state-icon {
            font-size: 64px;
            color: #dee2e6;
            margin-bottom: 16px;
        }
    </style>
</head>

<body>

<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<main class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
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

            <div class="header-section">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h2 class="fw-bold mb-1">
                            <i class="fa-solid fa-burger me-2"></i>
                            Daftar Menu
                        </h2>
                        <p class="text-muted mb-0">Total: <?= count($menus) ?> menu</p>
                    </div>
                    <a href="tambah.php" class="btn btn-tambah">
                        <i class="fa-solid fa-plus me-2"></i>
                        Tambah Menu
                    </a>
                </div>
            </div>

            <?php if (count($menus) > 0): ?>
                <div class="table-container">
                    <table class="table table-hover" id="tabelMenu">
                        <thead>
                            <tr>
                                <th style="width: 60px;">Foto</th>
                                <th>Nama Menu</th>
                                <th style="width: 100px;">Kategori</th>
                                <th style="width: 120px;">Harga</th>
                                <th style="width: 80px;">Status</th>
                                <th style="width: 140px;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($menus as $menu): ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($menu['gambar'])): ?>
                                            <img src="../../assets/img/menu/<?= htmlspecialchars($menu['gambar']) ?>"
                                                 alt="<?= htmlspecialchars($menu['nama_menu']) ?>"
                                                 class="menu-img">
                                        <?php else: ?>
                                            <div style="width: 50px; height: 50px; background: #f1f3f5; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #adb5bd;">
                                                <i class="fa-solid fa-image"></i>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($menu['nama_menu']) ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-kategori badge-<?= strtolower($menu['kategori']) ?>">
                                            <?= htmlspecialchars($menu['kategori']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong>Rp <?= number_format($menu['harga'], 0, ',', '.') ?></strong>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $menu['status'] ?>">
                                            <?= ucfirst($menu['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="edit.php?id=<?= (int)$menu['id_menu'] ?>"
                                        class="btn btn-sm btn-edit btn-aksi">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>
                                            Edit
                                        </a>
                                        <a href="varian.php?id=<?= (int)$menu['id_menu'] ?>"
                                        class="btn btn-sm btn-aksi"
                                        style="background:#7b1fa2;color:#fff;border:none;">
                                            <i class="fa-solid fa-sliders me-1"></i>
                                            Stok & Varian
                                        </a>
                                        <button class="btn btn-sm btn-hapus btn-aksi"
                                                data-id="<?= (int)$menu['id_menu'] ?>"
                                                data-nama="<?= e($menu['nama_menu']) ?>"
                                                onclick="confirmDelete(this)">
                                            <i class="fa-solid fa-trash me-1"></i>
                                            Hapus
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <div class="empty-state">
                        <div class="empty-state-icon">
                            <i class="fa-solid fa-inbox"></i>
                        </div>
                        <h4 class="fw-bold mb-2">Belum Ada Menu</h4>
                        <p class="text-muted mb-4">Mulai tambahkan menu untuk memulai operasional kasir.</p>
                        <a href="tambah.php" class="btn btn-tambah">
                            <i class="fa-solid fa-plus me-2"></i>
                            Tambah Menu Pertama
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

    <form id="formHapus" method="post" action="hapus.php" class="d-none">
        <?= csrf_field() ?>
        <input type="hidden" name="id" id="hapusId" value="">
    </form>

    <script>
        // 3. Script untuk menyalakan DataTables
        $(document).ready(function() {
            $('#tabelMenu').DataTable({
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json' // Bahasa Indonesia
                }
            });
        });

        // 4. Konfirmasi hapus -> kirim lewat form POST (dengan token CSRF)
        function confirmDelete(btn) {
            const id = btn.dataset.id;
            const nama = btn.dataset.nama;
            if (confirm(`Apakah Anda yakin ingin menghapus menu "${nama}"?\n\nFoto juga akan dihapus dari server.`)) {
                document.getElementById('hapusId').value = id;
                document.getElementById('formHapus').submit();
            }
        }
    </script>
</body>
</html>