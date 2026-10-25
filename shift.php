<?php
require_once __DIR__ . '/config/bootstrap.php';

if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
    }

    require_once __DIR__ . '/config/koneksi.php';
    
    // Ambil pengaturan toko
    $stmtPengaturan = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
    $pengaturan = $stmtPengaturan->fetch();
    
    $id_user = $_SESSION['id_user'];
    $username = $_SESSION['username'];
    

// Cek shift aktif milik user ini
$stmtShift = $pdo->prepare("SELECT * FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
$stmtShift->execute([$id_user]);
$shiftAktif = $stmtShift->fetch();

$error = '';
$rekapShift = null;

// Proses Start Shift
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !csrf_valid($_POST['csrf_token'] ?? '')) {
    $error = "Request tidak valid. Muat ulang halaman lalu coba lagi.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'start') {
        // Cek apakah sudah ada shift aktif dari user lain
        $stmtCek = $pdo->prepare("SELECT * FROM shift WHERE status = 'aktif' LIMIT 1");
        $stmtCek->execute();
        $shiftLain = $stmtCek->fetch();

        if ($shiftLain) {
            $error = "Masih ada shift aktif oleh <strong>" . e($shiftLain['nama_user']) . "</strong> sejak " . date('d/m/Y H:i', strtotime($shiftLain['waktu_mulai'])) . ". Selesaikan shift tersebut terlebih dahulu.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO shift (id_user, nama_user) VALUES (?, ?)");
            $stmt->execute([$id_user, $username]);
            header("Location: " . BASE_URL . "/index.php");
            exit;
        }
    }

    if ($_POST['action'] === 'end' && $shiftAktif) {
        // Ambil rekap transaksi selama shift ini
        $stmtRekap = $pdo->prepare("
            SELECT 
                COUNT(*) as total_transaksi,
                COALESCE(SUM(total), 0) as total_pendapatan,
                COALESCE(SUM(CASE WHEN metode_bayar = 'Cash' THEN total ELSE 0 END), 0) as total_cash,
                COALESCE(SUM(CASE WHEN metode_bayar = 'QRIS' THEN total ELSE 0 END), 0) as total_qris,
                COALESCE(SUM(CASE WHEN metode_bayar = 'Debit' THEN total ELSE 0 END), 0) as total_debit,
                COALESCE(SUM(CASE WHEN metode_bayar = 'Kredit' THEN total ELSE 0 END), 0) as total_kredit,
                COALESCE(SUM(CASE WHEN metode_bayar = 'Gojek' THEN total ELSE 0 END), 0) as total_gojek,
                COALESCE(SUM(CASE WHEN metode_bayar = 'GrabFood' THEN total ELSE 0 END), 0) as total_grabfood,
                COALESCE(SUM(CASE WHEN metode_bayar = 'ShopeeFood' THEN total ELSE 0 END), 0) as total_shopeefood
            FROM transaksi
            WHERE id_shift = ?
        ");
        $stmtRekap->execute([$shiftAktif['id_shift']]);
        $rekapTransaksi = $stmtRekap->fetch();

        // Ambil item terjual selama shift
        $stmtItem = $pdo->prepare("
            SELECT 
                dt.nama_menu,
                SUM(dt.qty) as total_qty,
                SUM(dt.subtotal) as total_subtotal
            FROM detail_transaksi dt
            INNER JOIN transaksi t ON dt.id_transaksi = t.id_transaksi
            WHERE t.id_shift = ?
            GROUP BY dt.nama_menu
            ORDER BY total_qty DESC
        ");
        $stmtItem->execute([$shiftAktif['id_shift']]);
        $itemTerjual = $stmtItem->fetchAll();

        // End shift
        $stmt = $pdo->prepare("UPDATE shift SET status = 'selesai', waktu_selesai = NOW() WHERE id_shift = ?");
        $stmt->execute([$shiftAktif['id_shift']]);

        $rekapShift = [
            'shift'     => $shiftAktif,
            'transaksi' => $rekapTransaksi,
            'items'     => $itemTerjual
        ];

        // Reset shift aktif
        $shiftAktif = null;
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shift - <?= htmlspecialchars($pengaturan['nama_toko']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: Arial, sans-serif; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; }
        .shift-card {
            max-width: 600px;
            margin: 60px auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,.1);
            padding: 40px;
            text-align: center;
        }
        .shift-icon {
            width: 80px; height: 80px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px;
            margin: 0 auto 20px;
        }
        .shift-icon.start { background: #e8f5e9; color: #2e7d32; }
        .shift-icon.active { background: #fff3e0; color: #f77f00; }
        .btn-start {
            background: linear-gradient(135deg, #2e7d32, #43a047);
            color: #fff; border: none;
            border-radius: 14px; font-size: 18px;
            font-weight: 800; padding: 14px 40px;
            width: 100%; margin-top: 20px;
        }
        .btn-start:hover { opacity: .92; color: #fff; }
        .btn-end {
            background: linear-gradient(135deg, #d62828, #f77f00);
            color: #fff; border: none;
            border-radius: 14px; font-size: 18px;
            font-weight: 800; padding: 14px 40px;
            width: 100%; margin-top: 10px;
        }
        .btn-end:hover { opacity: .92; color: #fff; }
        .btn-kasir {
            background: linear-gradient(135deg, #1565c0, #1976d2);
            color: #fff; border: none;
            border-radius: 14px; font-size: 16px;
            font-weight: 700; padding: 12px 40px;
            width: 100%; margin-top: 10px;
        }
        .btn-kasir:hover { opacity: .92; color: #fff; }
        .info-shift {
            background: #fff7ed;
            border-radius: 12px;
            padding: 16px;
            margin: 20px 0;
            text-align: left;
        }
        .rekap-card {
            max-width: 700px;
            margin: 30px auto;
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,.1);
            padding: 40px;
        }
        .metode-badge {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 999px;
            font-weight: 700;
            font-size: 13px;
        }
        .rekap-header {
            background: linear-gradient(135deg, #d62828, #f77f00);
            color: #fff;
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        @media print {
            .no-print { display: none !important; }
            body { background: #fff; }
            .rekap-card { box-shadow: none; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/config/navbar.php'; ?>

<div class="container">

    <?php if ($rekapShift): ?>
    <!-- Tampilan Rekap End Shift -->
    <div class="rekap-card">
        <div class="rekap-header">
            <i class="fa-solid fa-flag-checkered fa-2x mb-2"></i>
            <h3 class="fw-bold mb-1">Shift Selesai!</h3>
            <p class="mb-0">
                <?= date('d/m/Y H:i', strtotime($rekapShift['shift']['waktu_mulai'])) ?>
                → <?= date('d/m/Y H:i') ?>
            </p>
            <p class="mb-0">Kasir: <strong><?= htmlspecialchars($rekapShift['shift']['nama_user']) ?></strong></p>
        </div>

        <!-- Summary -->
        <div class="row g-3 mb-4">
            <div class="col-6">
                <div class="p-3 rounded-3 text-center" style="background:#e8f5e9;">
                    <div style="font-size:13px;color:#6c757d;font-weight:600;">TOTAL TRANSAKSI</div>
                    <div style="font-size:28px;font-weight:800;color:#2e7d32;">
                        <?= (int)$rekapShift['transaksi']['total_transaksi'] ?>
                    </div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-3 rounded-3 text-center" style="background:#fff3e0;">
                    <div style="font-size:13px;color:#6c757d;font-weight:600;">TOTAL PENDAPATAN</div>
                    <div style="font-size:22px;font-weight:800;color:#f77f00;">
                        Rp <?= number_format($rekapShift['transaksi']['total_pendapatan'], 0, ',', '.') ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Breakdown Metode Bayar -->
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-wallet me-2 text-danger"></i>Breakdown Metode Bayar</h5>
        <div class="table-responsive mb-4">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Metode</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $metodes = [
                        'Cash'      => ['total_cash',       '#e8f5e9', '#2e7d32', 'fa-money-bill'],
                        'QRIS'      => ['total_qris',       '#e3f2fd', '#1565c0', 'fa-qrcode'],
                        'Debit'     => ['total_debit',      '#f3e5f5', '#6a1b9a', 'fa-credit-card'],
                        'Kredit'    => ['total_kredit',     '#fce4ec', '#c62828', 'fa-credit-card'],
                        'Gojek'     => ['total_gojek',      '#e8f5e9', '#1b5e20', 'fa-motorcycle'],
                        'GrabFood'  => ['total_grabfood',   '#fff8e1', '#e65100', 'fa-car'],
                        'ShopeeFood'=> ['total_shopeefood', '#fce4ec', '#b71c1c', 'fa-bag-shopping'],
                    ];
                    foreach ($metodes as $nama => [$key, $bg, $color, $icon]):
                        $total = $rekapShift['transaksi'][$key];
                        if ($total <= 0) continue;
                    ?>
                    <tr>
                        <td>
                            <span class="metode-badge" style="background:<?= $bg ?>;color:<?= $color ?>;">
                                <i class="fa-solid <?= $icon ?> me-1"></i> <?= $nama ?>
                            </span>
                        </td>
                        <td class="text-end fw-bold">Rp <?= number_format($total, 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-warning">
                        <td><strong>Total Semua</strong></td>
                        <td class="text-end fw-bold">Rp <?= number_format($rekapShift['transaksi']['total_pendapatan'], 0, ',', '.') ?></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Item Terjual -->
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-bowl-food me-2 text-danger"></i>Item Terjual</h5>
        <?php if (count($rekapShift['items']) > 0): ?>
        <div class="table-responsive mb-4">
            <table class="table table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Menu</th>
                        <th class="text-center">Qty</th>
                        <th class="text-end">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rekapShift['items'] as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nama_menu']) ?></td>
                        <td class="text-center fw-bold"><?= (int)$item['total_qty'] ?></td>
                        <td class="text-end">Rp <?= number_format($item['total_subtotal'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <p class="text-muted">Tidak ada transaksi selama shift ini.</p>
        <?php endif; ?>

        <div class="d-flex gap-2 no-print">
            <button onclick="window.print()" class="btn btn-secondary rounded-3 fw-bold px-4">
                <i class="fa-solid fa-print me-2"></i>Cetak Rekap
            </button>
            <a href="<?= BASE_URL ?>/shift.php" class="btn btn-primary rounded-3 fw-bold px-4"
               style="background:linear-gradient(135deg,#d62828,#f77f00);border:none;">
                <i class="fa-solid fa-clock-rotate-left me-2"></i>Mulai Shift Baru
            </a>
        </div>
    </div>

    <?php elseif ($shiftAktif): ?>
    <!-- Shift Sedang Aktif -->
    <div class="shift-card">
        <div class="shift-icon active">
            <i class="fa-solid fa-clock"></i>
        </div>
        <h3 class="fw-bold">Shift Sedang Berjalan</h3>
        <p class="text-muted">Selamat bekerja, <strong><?= htmlspecialchars($username) ?>!</strong></p>

        <div class="info-shift">
            <div class="d-flex justify-content-between mb-2">
                <span class="text-muted">Mulai Shift</span>
                <strong><?= date('d/m/Y H:i', strtotime($shiftAktif['waktu_mulai'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between">
                <span class="text-muted">Kasir</span>
                <strong><?= htmlspecialchars($shiftAktif['nama_user']) ?></strong>
            </div>
        </div>

        <a href="<?= BASE_URL ?>/index.php" class="btn btn-kasir">
            <i class="fa-solid fa-desktop me-2"></i>Lanjut ke Kasir
        </a>

        <form method="POST" onsubmit="return confirm('Yakin ingin mengakhiri shift sekarang?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="end">
            <button type="submit" class="btn btn-end">
                <i class="fa-solid fa-flag-checkered me-2"></i>End Shift & Lihat Rekap
            </button>
        </form>
    </div>

    <?php else: ?>
    <!-- Belum Ada Shift -->
    <div class="shift-card">
        <div class="shift-icon start">
            <i class="fa-solid fa-play"></i>
        </div>
        <h3 class="fw-bold">Mulai Shift Kerja</h3>
        <p class="text-muted">Halo, <strong><?= htmlspecialchars($username) ?>!</strong><br>
        Tekan tombol di bawah untuk memulai shift dan mengakses kasir.</p>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger text-start">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="start">
            <button type="submit" class="btn btn-start">
                <i class="fa-solid fa-play me-2"></i>Mulai Shift Sekarang
            </button>
        </form>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>