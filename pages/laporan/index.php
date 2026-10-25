<?php
require_once __DIR__ . '/../../config/bootstrap.php';

if (!isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/login.php");
    exit;
}

require_once __DIR__ . '/../../config/koneksi.php';

$stmtPengaturan = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
$pengaturan = $stmtPengaturan->fetch();

$shiftAktif = null;
if ($_SESSION['role'] === 'kasir') {
    $stmtShiftNav = $pdo->prepare("SELECT * FROM shift WHERE id_user = ? AND status = 'aktif' LIMIT 1");
    $stmtShiftNav->execute([$_SESSION['id_user']]);
    $shiftAktif = $stmtShiftNav->fetch();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$tanggal_mulai   = isset($_GET['tanggal_mulai'])   ? $_GET['tanggal_mulai']   : date('Y-m-d');
$tanggal_selesai = isset($_GET['tanggal_selesai']) ? $_GET['tanggal_selesai'] : date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_mulai))   $tanggal_mulai   = date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_selesai)) $tanggal_selesai = date('Y-m-d');

// Summary hari ini
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) as total_pendapatan, COUNT(*) as total_transaksi
    FROM transaksi WHERE DATE(tanggal) = CURDATE()
");
$stmt->execute();
$summary_hari_ini = $stmt->fetch();

// Item terlaris hari ini
$stmt = $pdo->prepare("
    SELECT dt.nama_menu, SUM(dt.qty) as total_qty
    FROM detail_transaksi dt
    INNER JOIN transaksi t ON dt.id_transaksi = t.id_transaksi
    WHERE DATE(t.tanggal) = CURDATE()
    GROUP BY dt.nama_menu ORDER BY total_qty DESC LIMIT 1
");
$stmt->execute();
$item_terlaris = $stmt->fetch();

// Kuantitas per item periode filter
$stmtItem = $pdo->prepare("
    SELECT dt.nama_menu, SUM(dt.qty) as total_terjual, SUM(dt.qty * dt.harga) as subtotal_pendapatan
    FROM detail_transaksi dt
    INNER JOIN transaksi t ON dt.id_transaksi = t.id_transaksi
    WHERE DATE(t.tanggal) BETWEEN ? AND ?
    GROUP BY dt.nama_menu ORDER BY total_terjual DESC
");
$stmtItem->execute([$tanggal_mulai, $tanggal_selesai]);
$laporan_items = $stmtItem->fetchAll();

// Breakdown metode bayar periode filter
$stmtMetode = $pdo->prepare("
    SELECT 
        metode_bayar,
        COUNT(*) as jumlah_transaksi,
        COALESCE(SUM(total), 0) as total_pendapatan
    FROM transaksi
    WHERE DATE(tanggal) BETWEEN ? AND ?
    GROUP BY metode_bayar
    ORDER BY total_pendapatan DESC
");
$stmtMetode->execute([$tanggal_mulai, $tanggal_selesai]);
$breakdown_metode = $stmtMetode->fetchAll();

// Riwayat transaksi — tambah kolom metode_bayar, nomor_meja, diskon
$stmt = $pdo->prepare("
    SELECT 
        id_transaksi, kode_transaksi, tanggal,
        nomor_meja, metode_bayar,
        total_sebelum_diskon, diskon, total,
        bayar, kembalian
    FROM transaksi
    WHERE DATE(tanggal) BETWEEN ? AND ?
    ORDER BY tanggal DESC
");
$stmt->execute([$tanggal_mulai, $tanggal_selesai]);
$riwayat = $stmt->fetchAll();

// Summary periode
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(total), 0) as total_pendapatan, COUNT(*) as total_transaksi
    FROM transaksi WHERE DATE(tanggal) BETWEEN ? AND ?
");
$stmt->execute([$tanggal_mulai, $tanggal_selesai]);
$summary_periode = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Laporan Penjualan - <?= htmlspecialchars($pengaturan['nama_toko']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .navbar { background: linear-gradient(135deg, #d62828, #f77f00); }
        .brand-title { font-weight: 800; letter-spacing: .3px; }
        .metric-card { background: #fff; border: none; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.08); padding: 24px; transition: .3s ease; }
        .metric-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.12); }
        .metric-icon { width: 60px; height: 60px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px; }
        .metric-icon.pendapatan { background: #fff3e0; color: #f77f00; }
        .metric-icon.transaksi { background: #e8f5e9; color: #2e7d32; }
        .metric-icon.terlaris { background: #f3e5f5; color: #7b1fa2; }
        .metric-label { font-size: 13px; color: #6c757d; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
        .metric-value { font-size: 28px; font-weight: 800; color: #212529; margin-top: 8px; }
        .filter-section { background: #fff; padding: 20px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.08); margin-bottom: 24px; }
        .btn-filter { background: linear-gradient(135deg, #d62828, #f77f00); border: none; border-radius: 12px; font-weight: 600; padding: 10px 20px; }
        .btn-filter:hover { color: #fff; opacity: .92; }
        .table-container { background: #fff; padding: 24px; border-radius: 16px; box-shadow: 0 4px 12px rgba(0,0,0,.08); overflow-x: auto; margin-bottom: 24px; }
        .table { margin-bottom: 0; }
        .table thead { background: #f8f9fa; }
        .table thead th { font-weight: 700; border-bottom: 2px solid #dee2e6; color: #212529; }
        .table tbody tr { border-bottom: 1px solid #dee2e6; transition: .2s; }
        .table tbody tr:hover { background: #f8f9fa; }
        .kode-transaksi { font-family: 'Courier New', monospace; font-weight: 600; color: #d62828; cursor: pointer; text-decoration: underline dotted; }
        .kode-transaksi:hover { color: #f77f00; }
        .tanggal-transaksi { color: #6c757d; font-size: 13px; }
        .harga-positif { color: #2e7d32; font-weight: 600; }
        .alert { border-radius: 12px; border: none; }
        .btn-print { background: #6c757d; border: none; border-radius: 12px; font-weight: 600; padding: 10px 20px; }
        .btn-print:hover { opacity: .92; }
        .metode-badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
        .metode-Cash { background: #e8f5e9; color: #2e7d32; }
        .metode-QRIS { background: #e3f2fd; color: #1565c0; }
        .metode-Debit { background: #f3e5f5; color: #6a1b9a; }
        .metode-Kredit { background: #fce4ec; color: #c62828; }
        .metode-Gojek { background: #e8f5e9; color: #1b5e20; }
        .metode-GrabFood { background: #fff8e1; color: #e65100; }
        .metode-ShopeeFood { background: #fce4ec; color: #b71c1c; }
        .breakdown-card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; }
        .breakdown-amount { font-size: 20px; font-weight: 800; color: #212529; }
        .breakdown-count { font-size: 12px; color: #6c757d; }
        @media print {
            .no-print, .navbar, .filter-section, .btn-print { display: none !important; }
            body { background: #fff; }
            .metric-card, .table-container { box-shadow: none; page-break-inside: avoid; }
        }
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state-icon { font-size: 64px; color: #dee2e6; margin-bottom: 16px; }

        /* Struk cetak ulang */
        #strukCetakUlang { display: none; }
        @media print {
            #strukCetakUlang { display: block !important; }
            .main-content { display: none !important; }
        }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/../../config/navbar.php'; ?>

<div class="main-content">
<main class="container-fluid py-4">

    <!-- Summary Hari Ini -->
    <div class="row g-4 mb-4">
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-icon pendapatan"><i class="fa-solid fa-wallet"></i></div>
                <div class="metric-label">Total Pendapatan Hari Ini</div>
                <div class="metric-value harga-positif">Rp <?= number_format($summary_hari_ini['total_pendapatan'], 0, ',', '.') ?></div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-icon transaksi"><i class="fa-solid fa-receipt"></i></div>
                <div class="metric-label">Total Transaksi Hari Ini</div>
                <div class="metric-value"><?= (int)$summary_hari_ini['total_transaksi'] ?> Transaksi</div>
            </div>
        </div>
        <div class="col-lg-4 col-md-6">
            <div class="metric-card">
                <div class="metric-icon terlaris"><i class="fa-solid fa-star"></i></div>
                <div class="metric-label">Item Terlaris Hari Ini</div>
                <div class="metric-value"><?= $item_terlaris ? htmlspecialchars($item_terlaris['nama_menu']) : 'Belum Ada' ?></div>
            </div>
        </div>
    </div>

    <!-- Filter -->
    <div class="filter-section no-print">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-filter me-2"></i>Filter Laporan</h5>
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label fw-bold">Tanggal Mulai</label>
                <input type="date" name="tanggal_mulai" class="form-control" value="<?= htmlspecialchars($tanggal_mulai) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label fw-bold">Tanggal Selesai</label>
                <input type="date" name="tanggal_selesai" class="form-control" value="<?= htmlspecialchars($tanggal_selesai) ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-filter w-100">
                    <i class="fa-solid fa-magnifying-glass me-2"></i>Filter
                </button>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-print w-100" onclick="window.print()">
                    <i class="fa-solid fa-print me-2"></i>Cetak
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Periode -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="alert alert-info mb-0">
                <strong>Total Pendapatan (<?= date('d/m/Y', strtotime($tanggal_mulai)) ?> - <?= date('d/m/Y', strtotime($tanggal_selesai)) ?>):</strong>
                <span class="float-end harga-positif">Rp <?= number_format($summary_periode['total_pendapatan'], 0, ',', '.') ?></span>
            </div>
        </div>
        <div class="col-md-6">
            <div class="alert alert-info mb-0">
                <strong>Total Transaksi:</strong>
                <span class="float-end"><?= (int)$summary_periode['total_transaksi'] ?></span>
            </div>
        </div>
    </div>

    <!-- Breakdown Metode Bayar -->
    <?php if (count($breakdown_metode) > 0): ?>
    <div class="table-container mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-wallet me-2 text-danger"></i>Breakdown Metode Pembayaran</h5>
        
<div class="d-flex flex-wrap gap-3">
    <?php foreach ($breakdown_metode as $m): ?>
    <div class="flex-fill" style="min-width: 150px; max-width: 100%;">
        <div class="breakdown-card h-100">
            <span class="metode-badge metode-<?= $m['metode_bayar'] ?> mb-2 d-inline-block">
                <?= $m['metode_bayar'] ?>
            </span>
            <div class="breakdown-amount">Rp <?= number_format($m['total_pendapatan'], 0, ',', '.') ?></div>
            <div class="breakdown-count"><?= $m['jumlah_transaksi'] ?> transaksi</div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
        
    </div>
    <?php endif; ?>

    <!-- Kuantitas Per Item -->
    <div class="table-container mb-4">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-box-open me-2 text-danger"></i>Kuantitas Penjualan Per Item</h5>
        <?php if (count($laporan_items) > 0): ?>
            <table class="table table-hover table-striped" id="tabelItemTerjual">
                <thead>
                    <tr>
                        <th>Nama Item / Menu</th>
                        <th class="text-center" style="width:200px;">Total Terjual (Qty)</th>
                        <th class="text-end" style="width:250px;">Subtotal Pendapatan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($laporan_items as $item): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($item['nama_menu']) ?></strong></td>
                        <td class="text-center fw-bold"><?= number_format($item['total_terjual'], 0, ',', '.') ?></td>
                        <td class="text-end harga-positif">Rp <?= number_format($item['subtotal_pendapatan'], 0, ',', '.') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="text-center py-3 text-muted">Tidak ada data item yang terjual pada periode ini.</div>
        <?php endif; ?>
    </div>

    <!-- Riwayat Transaksi -->
    <div class="table-container">
        <h5 class="fw-bold mb-3"><i class="fa-solid fa-history me-2 text-danger"></i>Riwayat Transaksi</h5>
        <p class="text-muted small mb-3">Klik kode transaksi untuk melihat detail dan cetak ulang struk.</p>

        <?php if (count($riwayat) > 0): ?>
            <table class="table table-hover" id="tabelRiwayat">
                <thead>
                    <tr>
                        <th style="width:160px;">Tanggal</th>
                        <th>Kode Transaksi</th>
                        <th style="width:80px;">Meja</th>
                        <th style="width:120px;">Metode</th>
                        <th style="width:120px;">Total</th>
                        <th style="width:100px;">Diskon</th>
                        <th style="width:120px;">Bayar</th>
                        <th style="width:120px;">Kembalian</th>
                        <th style="width:80px;" class="no-print">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($riwayat as $trx): ?>
                    <tr>
                        <td>
                            <div><strong><?= date('d/m/Y', strtotime($trx['tanggal'])) ?></strong></div>
                            <div class="tanggal-transaksi"><?= date('H:i:s', strtotime($trx['tanggal'])) ?></div>
                        </td>
                        <td>
                            <span class="kode-transaksi" onclick="lihatDetail(<?= $trx['id_transaksi'] ?>)">
                                <?= htmlspecialchars($trx['kode_transaksi']) ?>
                            </span>
                        </td>
                        <td class="text-center">
                            <?= $trx['nomor_meja'] ? '<span class="badge bg-secondary">Meja '.(int)$trx['nomor_meja'].'</span>' : '-' ?>
                        </td>
                        <td>
                            <span class="metode-badge metode-<?= $trx['metode_bayar'] ?>">
                                <?= htmlspecialchars($trx['metode_bayar']) ?>
                            </span>
                        </td>
                        <td><strong class="harga-positif">Rp <?= number_format($trx['total'], 0, ',', '.') ?></strong></td>
                        <td>
                            <?php if ($trx['diskon'] > 0): ?>
                                <span class="text-danger fw-bold">- Rp <?= number_format($trx['diskon'], 0, ',', '.') ?></span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td>Rp <?= number_format($trx['bayar'], 0, ',', '.') ?></td>
                        <td><span class="harga-positif">Rp <?= number_format($trx['kembalian'], 0, ',', '.') ?></span></td>
                        <td class="no-print">
                            <button class="btn btn-sm btn-outline-danger rounded-3"
                                    onclick="lihatDetail(<?= $trx['id_transaksi'] ?>)">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon"><i class="fa-solid fa-inbox"></i></div>
                <h4 class="fw-bold mb-2">Belum Ada Transaksi</h4>
                <p class="text-muted">Tidak ada transaksi untuk rentang tanggal yang dipilih.</p>
            </div>
        <?php endif; ?>
    </div>

</main>
</div>

<!-- Modal Detail Transaksi -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0" style="background:linear-gradient(135deg,#d62828,#f77f00);">
                <h5 class="modal-title fw-bold text-white">
                    <i class="fa-solid fa-receipt me-2"></i>Detail Transaksi
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="modalDetailBody">
                <div class="text-center py-4">
                    <i class="fa-solid fa-spinner fa-spin fa-2x text-muted"></i>
                    <p class="mt-2 text-muted">Memuat data...</p>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button class="btn btn-secondary rounded-3 fw-bold" data-bs-dismiss="modal">Tutup</button>
                <button class="btn fw-bold rounded-3 text-white no-print" id="btnCetakUlang"
                    style="background:linear-gradient(135deg,#d62828,#f77f00);border:none;"
                    onclick="cetakUlang()">
                    <i class="fa-solid fa-print me-2"></i>Cetak Ulang Struk
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Area cetak ulang struk (hidden, hanya muncul saat print) -->
<div id="strukCetakUlang"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>

<script>
// Escape teks dari database sebelum dimasukkan ke innerHTML
function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]
    ));
}
// DataTables
$(document).ready(function() {
    $('#tabelRiwayat').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json' },
        order: [[0, 'desc']]
    });
    $('#tabelItemTerjual').DataTable({
        language: { url: '//cdn.datatables.net/plug-ins/1.13.7/i18n/id.json' },
        order: [[1, 'desc']],
        pageLength: 5,
        lengthMenu: [5, 10, 25, 50]
    });
});

// Simpan data transaksi aktif untuk cetak ulang
let transaksiAktif = null;

// Nama toko dari PHP untuk struk
const TOKO = {
    nama:    <?= json_encode($pengaturan['nama_toko']) ?>,
    tagline: <?= json_encode($pengaturan['tagline']) ?>,
    alamat:  <?= json_encode($pengaturan['alamat'] ?? '') ?>,
    telp:    <?= json_encode($pengaturan['no_telp'] ?? '') ?>,
    footer:  <?= json_encode($pengaturan['footer_struk'] ?? 'Terima kasih') ?>
};

function formatRupiah(n) {
    return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function lihatDetail(id_transaksi) {
    // Reset modal
    document.getElementById('modalDetailBody').innerHTML = `
        <div class="text-center py-4">
            <i class="fa-solid fa-spinner fa-spin fa-2x text-muted"></i>
            <p class="mt-2 text-muted">Memuat data...</p>
        </div>`;
    new bootstrap.Modal(document.getElementById('modalDetail')).show();

    // Fetch detail transaksi
fetch(`<?= BASE_URL ?>/ajax/detail_transaksi.php?id=${id_transaksi}`)
        .then(r => r.json())
        .then(data => {
            if (data.status !== 'success') {
                document.getElementById('modalDetailBody').innerHTML = `
                    <div class="alert alert-danger">Gagal memuat data transaksi.</div>`;
                return;
            }

            transaksiAktif = data;
            const trx   = data.transaksi;
            const items = data.items;

            const itemsHtml = items.map(item => `
                <tr>
                    <td>${esc(item.nama_menu)}</td>
                    <td class="text-center">${item.qty}</td>
                    <td class="text-end">Rp ${Number(item.harga).toLocaleString('id-ID')}</td>
                    <td class="text-end fw-bold">Rp ${Number(item.subtotal).toLocaleString('id-ID')}</td>
                </tr>
            `).join('');

            const diskonRow = trx.diskon > 0 ? `
                <tr class="table-success">
                    <td colspan="3"><strong>Diskon</strong></td>
                    <td class="text-end text-danger fw-bold">- Rp ${Number(trx.diskon).toLocaleString('id-ID')}</td>
                </tr>` : '';

            document.getElementById('modalDetailBody').innerHTML = `
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small">Kode Transaksi</div>
                            <div class="fw-bold" style="font-family:monospace;color:#d62828;">${esc(trx.kode_transaksi)}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small">Tanggal & Waktu</div>
                            <div class="fw-bold">${new Date(trx.tanggal).toLocaleString('id-ID')}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small">Nomor Meja</div>
                            <div class="fw-bold">${trx.nomor_meja ? 'Meja ' + trx.nomor_meja : '-'}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small">Metode Bayar</div>
                            <div class="fw-bold">${esc(trx.metode_bayar)}</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3" style="background:#f8f9fa;">
                            <div class="text-muted small">Kasir</div>
                            <div class="fw-bold">${esc(trx.username ?? '-')}</div>
                        </div>
                    </div>
                </div>

                <h6 class="fw-bold mb-2">Detail Pesanan</h6>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th>Menu</th>
                                <th class="text-center" style="width:60px;">Qty</th>
                                <th class="text-end" style="width:120px;">Harga</th>
                                <th class="text-end" style="width:120px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                            ${diskonRow}
                            <tr class="table-warning">
                                <td colspan="3"><strong>Total</strong></td>
                                <td class="text-end fw-bold text-success">Rp ${Number(trx.total).toLocaleString('id-ID')}</td>
                            </tr>
                            <tr>
                                <td colspan="3">Bayar</td>
                                <td class="text-end">Rp ${Number(trx.bayar).toLocaleString('id-ID')}</td>
                            </tr>
                            <tr>
                                <td colspan="3">Kembalian</td>
                                <td class="text-end fw-bold text-success">Rp ${Number(trx.kembalian).toLocaleString('id-ID')}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            `;
        })
        .catch(() => {
            document.getElementById('modalDetailBody').innerHTML = `
                <div class="alert alert-danger">Terjadi kesalahan. Coba lagi.</div>`;
        });
}

function cetakUlang() {
    if (!transaksiAktif) return;

    const trx   = transaksiAktif.transaksi;
    const items = transaksiAktif.items;

    const itemsStruk = items.map(item => `
        <div style="margin-bottom:6px;">
            <div>${esc(item.nama_menu)}</div>
            <div style="display:flex;justify-content:space-between;">
                <span>${item.qty} x Rp ${Number(item.harga).toLocaleString('id-ID')}</span>
                <span>Rp ${Number(item.subtotal).toLocaleString('id-ID')}</span>
            </div>
        </div>
    `).join('');

    const diskonStruk = trx.diskon > 0 ? `
        <p style="display:flex;justify-content:space-between;color:#c62828;">
            <span>Diskon</span><span>- Rp ${Number(trx.diskon).toLocaleString('id-ID')}</span>
        </p>` : '';

    document.getElementById('strukCetakUlang').innerHTML = `
        <div style="max-width:320px;margin:auto;font-family:Arial,sans-serif;">
            <h3 style="text-align:center;margin-bottom:4px;">${TOKO.nama}</h3>
            <p style="text-align:center;margin:0;">${TOKO.tagline}</p>
            ${TOKO.alamat ? `<p style="text-align:center;margin:0;font-size:12px;">${TOKO.alamat}</p>` : ''}
            ${TOKO.telp ? `<p style="text-align:center;margin:0;font-size:12px;">Telp: ${TOKO.telp}</p>` : ''}
            <hr>
            <p style="margin:0;">Kode: ${esc(trx.kode_transaksi)}</p>
            <p style="margin:0;">Tanggal: ${new Date(trx.tanggal).toLocaleString('id-ID')}</p>
            <p style="margin:0;">Kasir: ${esc(trx.username ?? '-')}</p>
            ${trx.nomor_meja ? `<p style="margin:0;">Meja: ${trx.nomor_meja}</p>` : ''}
            <hr>
            ${itemsStruk}
            <hr>
            ${diskonStruk}
            <p style="display:flex;justify-content:space-between;"><strong>Total</strong><strong>Rp ${Number(trx.total).toLocaleString('id-ID')}</strong></p>
            <p style="display:flex;justify-content:space-between;"><span>Bayar</span><span>Rp ${Number(trx.bayar).toLocaleString('id-ID')}</span></p>
            <p style="display:flex;justify-content:space-between;"><span>Kembalian</span><span>Rp ${Number(trx.kembalian).toLocaleString('id-ID')}</span></p>
            <p style="display:flex;justify-content:space-between;"><span>Metode</span><span>${esc(trx.metode_bayar)}</span></p>
            <hr>
            <p style="text-align:center;">${TOKO.footer}</p>
        </div>
    `;

    // Tutup modal lalu print
    bootstrap.Modal.getInstance(document.getElementById('modalDetail')).hide();
    setTimeout(() => window.print(), 300);
}
</script>
</body>
</html>