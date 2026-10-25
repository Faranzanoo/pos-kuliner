<?php
require_once __DIR__ . '/../../config/bootstrap.php';
if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

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

// Handle bersihkan meja — admin bisa paksa kosongkan meja yang terkunci
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bersihkan'])
    && $_SESSION['role'] === 'admin' && csrf_valid($_POST['csrf_token'] ?? '')) {
    $id_bersihkan = (int)$_POST['bersihkan'];
    $pdo->prepare("DELETE FROM order_aktif WHERE id_meja = ?")->execute([$id_bersihkan]);
    $pdo->prepare("UPDATE meja SET status = 'kosong' WHERE id_meja = ?")->execute([$id_bersihkan]);
    header("Location: " . BASE_URL . "/pages/meja/index.php");
    exit;
}

// Ambil semua meja beserta info order aktif
$stmt = $pdo->query("
    SELECT m.*,
           o.id_order, o.total, o.id_user as user_order, o.updated_at,
           u.username as nama_kasir
    FROM meja m
    LEFT JOIN order_aktif o ON m.id_meja = o.id_meja
    LEFT JOIN users u ON o.id_user = u.id_user
    ORDER BY m.nomor_meja ASC
");
$mejas = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pilih Meja - <?= htmlspecialchars($pengaturan['nama_toko']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: Arial, sans-serif; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; }
        .meja-card {
            border-radius: 16px;
            border: none;
            cursor: pointer;
            transition: .2s ease;
            height: 140px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 12px rgba(0,0,0,.08);
            position: relative;
        }
        .meja-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.15); }
        .meja-kosong { background: linear-gradient(135deg, #e8f5e9, #c8e6c9); color: #2e7d32; }
        .meja-terisi { background: linear-gradient(135deg, #ffebee, #ffcdd2); color: #c62828; }
        .meja-locked { background: linear-gradient(135deg, #fff3e0, #ffe0b2); color: #e65100; cursor: not-allowed; }
        .meja-number { font-size: 32px; font-weight: 800; }
        .meja-status { font-size: 12px; font-weight: 600; margin-top: 4px; }
        .meja-total { font-size: 13px; font-weight: 700; margin-top: 4px; }
        .legend-dot { width: 14px; height: 14px; border-radius: 50%; display: inline-block; }

        @media (min-width: 992px) {
            .col-custom-5 { flex: 0 0 20%; max-width: 20%; }
        }

        .btn-bersihkan {
            font-size: 11px;
            padding: 3px 8px;
            margin-top: 6px;
            width: 100%;
            border-radius: 8px;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-1"><i class="fa-solid fa-table me-2 text-danger"></i>Pilih Meja</h3>
            <p class="text-muted mb-0">Klik meja untuk mulai atau lanjutkan order.</p>
        </div>
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <span><span class="legend-dot" style="background:#4caf50;"></span> Kosong</span>
            <span><span class="legend-dot" style="background:#e53935;"></span> Terisi</span>
            <span><span class="legend-dot" style="background:#fb8c00;"></span> Dikunci kasir lain</span>
        </div>
    </div>

    <div class="row g-3">
        <?php foreach ($mejas as $meja):
            $isMyOrder    = $meja['id_order'] && $meja['user_order'] == $_SESSION['id_user'];
            $isOtherOrder = $meja['id_order'] && $meja['user_order'] != $_SESSION['id_user'];

            if ($meja['status'] === 'kosong') {
                $cardClass  = 'meja-kosong';
                $statusText = 'Kosong';
                $icon       = 'fa-circle-check';
                $clickable  = true;
            } elseif ($isMyOrder) {
                $cardClass  = 'meja-terisi';
                $statusText = 'Order Saya';
                $icon       = 'fa-utensils';
                $clickable  = true;
            } elseif ($isOtherOrder) {
                $cardClass  = 'meja-locked';
                $statusText = 'Oleh: ' . htmlspecialchars($meja['nama_kasir'] ?? '-');
                $icon       = 'fa-lock';
                $clickable  = false;
            } else {
                // Status terisi tapi tidak ada order_aktif — data tidak konsisten
                // Anggap kosong dan bisa diklik
                $cardClass  = 'meja-kosong';
                $statusText = 'Kosong';
                $icon       = 'fa-circle-check';
                $clickable  = true;
            }
        ?>
        <div class="col-6 col-sm-4 col-custom-5">
            <?php if ($clickable): ?>
            <a href="<?= BASE_URL ?>/index.php?meja=<?= $meja['id_meja'] ?>" class="text-decoration-none">
            <?php endif; ?>
                <div class="meja-card <?= $cardClass ?>">
                    <i class="fa-solid <?= $icon ?> fa-lg mb-1"></i>
                    <div class="meja-number"><?= $meja['nomor_meja'] ?></div>
                    <div class="meja-status"><?= $statusText ?></div>
                    <?php if ($meja['total'] > 0): ?>
                        <div class="meja-total">Rp <?= number_format($meja['total'], 0, ',', '.') ?></div>
                    <?php endif; ?>
                </div>
            <?php if ($clickable): ?>
            </a>
            <?php endif; ?>

            <?php if ($isOtherOrder && $_SESSION['role'] === 'admin'): ?>
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Batalkan order di Meja <?= (int)$meja['nomor_meja'] ?>? Order yang belum dibayar akan hilang.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="bersihkan" value="<?= (int)$meja['id_meja'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-bersihkan">
                        <i class="fa-solid fa-broom me-1"></i>Bersihkan
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>