<?php
// config/navbar.php
// Pastikan $pengaturan dan $shiftAktif sudah ada sebelum include file ini



$currentPage = basename($_SERVER['PHP_SELF']);
$currentDir  = basename(dirname($_SERVER['PHP_SELF']));
?>

<?php
// Fallback kalau $pengaturan belum di-set di halaman yang include ini
if (!isset($pengaturan)) {
    $stmtNav = $pdo->query("SELECT * FROM pengaturan LIMIT 1");
    $pengaturan = $stmtNav->fetch();
}
if (!isset($shiftAktif)) {
    $shiftAktif = null;
}
?>

<nav class="navbar navbar-expand-lg navbar-dark shadow-sm no-print">
    <div class="container-fluid px-4">
        <a class="navbar-brand brand-title" href="<?= BASE_URL ?>/pages/meja/index.php">
            <i class="fa-solid fa-cash-register me-2"></i>
            <?= htmlspecialchars($pengaturan['nama_toko']) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto fw-bold align-items-center">

                <!-- Meja (semua role) -->
                <li class="nav-item">
                    <a class="nav-link <?= $currentDir === 'meja' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/pages/meja/index.php">
                        <i class="fa-solid fa-table me-1"></i> Meja
                    </a>
                </li>

                <!-- Laporan (semua role) -->
                <li class="nav-item">
                    <a class="nav-link <?= $currentDir === 'laporan' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/pages/laporan/index.php">
                        <i class="fa-solid fa-chart-line me-1"></i> Laporan
                    </a>
                </li>

                <!-- Menu khusus admin -->
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= $currentDir === 'menu' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/pages/menu/index.php">
                        <i class="fa-solid fa-burger me-1"></i> Kelola Menu
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentDir === 'promo' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/pages/promo/index.php">
                        <i class="fa-solid fa-tag me-1"></i> Promo
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentDir === 'pengaturan' ? 'active' : '' ?>"
                       href="<?= BASE_URL ?>/pages/pengaturan/index.php">
                        <i class="fa-solid fa-gear me-1"></i> Pengaturan
                    </a>
                </li>
                <?php endif; ?>

                <!-- Info shift kasir -->
                <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'kasir' && !empty($shiftAktif)): ?>
                <li class="nav-item d-flex align-items-center mx-2">
                    <a href="<?= BASE_URL ?>/shift.php" class="nav-link"
                       style="background:rgba(255,255,255,.2);border-radius:999px;padding:4px 12px;font-size:13px;">
                        <i class="fa-solid fa-clock me-1 text-warning"></i>
                        <?= date('H:i', strtotime($shiftAktif['waktu_mulai'])) ?>
                        <span class="badge bg-warning text-dark ms-1">End Shift</span>
                    </a>
                </li>
                <?php endif; ?>

                <!-- Logout -->
                <li class="nav-item">
                    <a class="nav-link" href="#" data-bs-toggle="modal" data-bs-target="#logoutModal">
                        <i class="fa-solid fa-right-from-bracket me-1 text-danger"></i> Logout
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Modal Logout (universal) -->
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