<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/koneksi.php';

// Jika sudah login, langsung arahkan ke halaman Kasir
if (isset($_SESSION['id_user'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit;
}

// Pembatasan percobaan login: maks. MAX_GAGAL kali per alamat IP dalam WINDOW_MENIT menit
const LOGIN_MAX_GAGAL    = 5;
const LOGIN_WINDOW_MENIT = 15;
// Hash palsu: dipakai agar waktu respons sama baik user ada maupun tidak
const LOGIN_HASH_PALSU = '$2y$10$zdUXfDT0EQvI.kv7jyaWaORx8Gk3NCxp4QRpNzA6zT/FqnKXfxJhC';

$ip    = substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
$error = '';

function login_terlalu_banyak_gagal(PDO $pdo, string $ip): bool
{
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts
                             WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL " . LOGIN_WINDOW_MENIT . " MINUTE)");
        $st->execute([$ip]);
        return (int)$st->fetchColumn() >= LOGIN_MAX_GAGAL;
    } catch (PDOException $e) {
        // Tabel belum dibuat (lihat database/migrations). Jangan blokir login.
        log_error($e, 'login_attempts');
        return false;
    }
}

function login_catat_gagal(PDO $pdo, string $ip): void
{
    try {
        $pdo->prepare("INSERT INTO login_attempts (ip_address) VALUES (?)")->execute([$ip]);
        $pdo->exec("DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)");
    } catch (PDOException $e) {
        log_error($e, 'login_attempts');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (!csrf_valid($_POST['csrf_token'] ?? '')) {
        $error = "Sesi form kedaluwarsa. Muat ulang halaman lalu coba lagi.";
    } elseif ($username === '' || $password === '') {
        $error = "Username dan password tidak boleh kosong!";
    } elseif (login_terlalu_banyak_gagal($pdo, $ip)) {
        $error = "Terlalu banyak percobaan login. Coba lagi dalam " . LOGIN_WINDOW_MENIT . " menit.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        $hash = $user ? $user['password'] : LOGIN_HASH_PALSU;
        $ok   = password_verify($password, $hash) && $user;

        if ($ok) {
            // Cegah session fixation: buat ID sesi baru setelah login
            session_regenerate_id(true);
            $_SESSION['id_user']  = $user['id_user'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role']     = $user['role'];

            try {
                $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?")->execute([$ip]);
            } catch (PDOException $e) {
                log_error($e, 'login_attempts');
            }

            header("Location: " . BASE_URL . "/index.php");
            exit;
        }

        login_catat_gagal($pdo, $ip);
        $error = "Username atau password salah!";
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - POS Kuliner</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, .1);
            width: 100%;
            max-width: 400px;
        }
        .brand-title {
            font-weight: 800;
            color: #d62828;
            text-align: center;
            margin-bottom: 30px;
        }
        .btn-login {
            background: linear-gradient(135deg, #d62828, #f77f00);
            color: white;
            font-weight: bold;
            border: none;
            border-radius: 12px;
            padding: 12px;
            transition: 0.3s;
        }
        .btn-login:hover {
            opacity: 0.9;
            color: white;
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="login-page">

<div class="login-card">
    <h3 class="brand-title">
        <i class="fa-solid fa-cash-register me-2"></i> POS Kuliner
    </h3>

    <?php if ($error): ?>
        <div class="alert alert-danger p-2 mb-3 text-center rounded-3">
            <i class="fa-solid fa-circle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <?= csrf_field() ?>
        <div class="mb-3">
            <label class="form-label fw-bold">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-user text-muted"></i></span>
                <input type="text" name="username" class="form-control border-start-0" required placeholder="Masukkan username">
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-bold">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light border-end-0"><i class="fa-solid fa-lock text-muted"></i></span>
                <input type="password" name="password" class="form-control border-start-0" required placeholder="Masukkan password">
            </div>
        </div>

        <button type="submit" class="btn btn-login w-100">
            LOGIN <i class="fa-solid fa-right-to-bracket ms-1"></i>
        </button>
    </form>
</div>

</body>
</html>