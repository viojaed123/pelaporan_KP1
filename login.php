<?php
/**
 * login.php — halaman masuk dengan penguatan keamanan:
 *   · token CSRF
 *   · pembatas laju + penguncian akun (rate limit / lockout)
 *   · pesan kesalahan umum (tidak membocorkan email mana yang terdaftar)
 *   · waktu verifikasi disamakan bila email tidak ditemukan
 *   · regenerasi id sesi
 *   · 2FA OTP via surel (bila diaktifkan)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Sudah masuk? Langsung ke halaman peran.
if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

$pesan  = '';
$status = 'gagal';   // gagal | info | sukses
$tampilkanVerifikasi = false;

$flashLogin = flash_ambil();
if (is_array($flashLogin)) {
    $jenisFlash = (string) ($flashLogin['jenis'] ?? 'info');
    $status = in_array($jenisFlash, ['sukses', 'gagal', 'info'], true) ? $jenisFlash : 'info';
    $pesan  = (string) ($flashLogin['pesan'] ?? '');
} elseif (isset($_GET['alasan'])) {
    $status = 'info';
    $pesan = match ($_GET['alasan']) {
        'sesi_habis'   => 'Sesi Anda berakhir demi keamanan. Silakan masuk kembali.',
        'keluar'       => 'Anda telah keluar dengan aman.',
        'akses'        => 'Anda harus masuk terlebih dahulu untuk mengakses halaman tersebut.',
        'kadaluarsa'   => 'Tautan sudah tidak berlaku. Silakan masuk kembali.',
        default        => 'Silakan masuk untuk melanjutkan.',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_cek('login')) {
        $pesan  = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $status = 'gagal';
    } elseif ($email === '' || $password === '') {
        $pesan  = 'Masukkan email dan kata sandi.';
        $status = 'gagal';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pesan  = 'Format alamat email tidak valid.';
        $status = 'gagal';
    } else {
        $ip = ip_klien();

        // 1) Penguncian akibat percobaan berulang
        $kunci = login_terkunci($conn, $ip, $email);
        if ($kunci['terkunci']) {
            $pesan  = (string) $kunci['pesan'];
            $status = 'gagal';
        } else {
            // 2) Cari akun
            $stmt = $conn->prepare(
                'SELECT id, nama, email, password, role, status
                 FROM users WHERE email = ? LIMIT 1'
            );
            $user = null;
            $hash = null;

            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $res  = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }

            $sandiSah = false;

            if ($user !== null) {
                $hash = (string) $user['password'];
                $sandiSah = password_verify($password, $hash);

                // Sandi lama yang masih hash lemah -> naikkan ke bcrypt
                if ($sandiSah && !password_get_info($hash)['algo']) {
                    $baru = password_hash($password, PASSWORD_DEFAULT);
                    $up = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if ($up) {
                        $uid = (int) $user['id'];
                        $up->bind_param('si', $baru, $uid);
                        $up->execute();
                        $up->close();
                    }
                }
            } else {
                // Samakan waktu respons agar email tidak bisa ditebak
                password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
            }

            if ($user !== null && $sandiSah) {
                $statusAkun = (string) ($user['status'] ?? 'aktif');

                if ($statusAkun !== 'aktif') {
                    // Kredensial benar, tetapi akun belum/tidak aktif.
                    if ($statusAkun === 'menunggu') {
                        $pesan  = 'Akun Anda belum diverifikasi. Buka tautan verifikasi di surel Anda, '
                            . 'atau minta tautan baru di halaman Verifikasi Surel.';
                        $status = 'info';
                        $tampilkanVerifikasi = true;
                        $_SESSION['verifikasi_email_tujuan'] = (string) $user['email'];
                    } else {
                        $pesan  = 'Akun Anda sedang dinonaktifkan. Hubungi administrator.';
                        $status = 'gagal';
                    }
                } elseif (user_2fa_aktif($conn, (int) $user['id'])) {
                    // 3) 2FA — bila aktif, jangan buat sesi penuh dulu
                    session_regenerate_id(true);

                    $_SESSION['tfa_uid']   = (int) $user['id'];
                    $_SESSION['tfa_email'] = (string) $user['email'];
                    $_SESSION['tfa_kirim'] = 0;

                    try {
                        $kode = otp_buat($conn, (int) $user['id'], (string) $user['email']);
                        $kirim = otp_kirim((string) $user['email'], $kode, (string) $user['nama']);

                        $_SESSION['tfa_kirim'] = 1;
                        unset($kode);

                        if (!$kirim['ok']) {
                            error_log('OTP gagal dikirim: ' . (string) $kirim['galat']);
                        }
                    } catch (Throwable $t) {
                        error_log('OTP: ' . $t->getMessage());
                    }

                    catat_audit(
                        $conn, 'login_2fa_diminta', 'users',
                        (int) $user['id'], 'Permintaan kode 2FA', null, null
                    );

                    header('Location: dua_faktor.php');
                    exit;
                } else {
                    // 4) Tanpa 2FA -> langsung selesai
                    login_tuntaskan($conn, $user, 'password');
                }
            } else {
                // 5) Gagal
                login_catat_kegagalan($conn, $ip, $email, $user !== null ? (int) $user['id'] : null);

                $gagal = login_hitung_gagal($conn, $ip, $email);
                $sisa  = LOGIN_MAKS_GAGAL - $gagal;

                if ($sisa <= 0) {
                    $pesan  = 'Terlalu banyak percobaan gagal. Akun sementara dikunci.';
                    $status = 'gagal';
                } elseif ($gagal >= 3) {
                    $pesan  = 'Email atau kata sandi tidak sesuai. Sisa percobaan sebelum terkunci: ' . $sisa . '.';
                    $status = 'gagal';
                } else {
                    $pesan  = 'Email atau kata sandi tidak sesuai.';
                    $status = 'gagal';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= e(APP_SINGKAT) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
</head>
<body>
<div class="layar-auth">

    <!-- ---------------- Panel kiri: identitas ---------------- -->
    <aside class="panel-kiri-auth">
        <div class="merek-auth">
            <?= logo_phr() ?>
            <span>
                <span class="nama"><?= e(APP_SINGKAT) ?></span><br>
                <span class="sub"><?= e(APP_ORGANISASI) ?></span>
            </span>
        </div>

        <div>
            <h2 class="judul-auth">Catat, periksa, dan laporkan kegiatan harian dalam satu tempat.</h2>
            <p class="teks-auth">
                Sistem pelaporan kegiatan terintegrasi untuk karyawan, mentor,
                dan pengelola — lengkap dengan absensi, pelacakan kasus, serta
                asisten kecerdasan buatan.
            </p>

            <ul class="daftar-fitur">
                <li><span class="cek">✓</span> Laporan harian dengan bukti foto &amp; verifikasi mentor</li>
                <li><span class="cek">✓</span> Absensi dan rekap otomatis siap ekspor</li>
                <li><span class="cek">✓</span> Verifikasi dua faktor lewat kode di surel</li>
                <li><span class="cek">✓</span> Asisten AI untuk narasi, statistik, dan pemeriksaan kelengkapan</li>
            </ul>
        </div>

        <div class="foot-auth">&copy; <?= date('Y') ?> <?= e(APP_ORGANISASI) ?>. Seluruh data terlindungi.</div>
    </aside>

    <!-- ---------------- Panel kanan: formulir ---------------- -->
    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <h1>Selamat Datang</h1>
            <p class="pengantar">Masuk untuk mengelola laporan kegiatan harian Anda.</p>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'sukses' ? 'sukses'
                    : ($status === 'info' ? 'info' : 'gagal') ?>" role="alert">
                    <span class="ikon"><?= $status === 'sukses' ? '✓'
                        : ($status === 'info' ? 'ℹ' : '✕') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($tampilkanVerifikasi): ?>
                <p class="tautan-bawah" style="margin-top:0">
                    <a href="verifikasi_email.php">Kirim ulang tautan verifikasi surel</a>
                </p>
            <?php endif; ?>

            <form method="POST" action="login.php" autocomplete="on" novalidate>
                <?= csrf_field('login') ?>

                <div class="grup-form">
                    <label for="email">Alamat email</label>
                    <input id="email" name="email" type="email"
                           value="<?= e($_POST['email'] ?? '') ?>"
                           autocomplete="username"
                           maxlength="150"
                           placeholder="nama@perusahaan.co.id"
                           required autofocus>
                </div>

                <div class="grup-form">
                    <label for="password">Kata sandi</label>
                    <div class="field-input">
                        <input id="password" name="password" type="password"
                               autocomplete="current-password"
                               placeholder="••••••••"
                               required>
                        <button class="mata" type="button" data-mata="password"
                                aria-label="Tampilkan kata sandi"><?= ikon_sp('mata') ?></button>
                    </div>
                </div>

                <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                    Masuk
                </button>
            </form>

            <p class="tautan-bawah">
                Belum punya akun? <a href="register.php">Daftar di sini</a>
            </p>
            <p class="tautan-bawah" style="margin-top:6px">
                <a href="lupa_sandi.php">Lupa kata sandi? Gunakan kode OTP</a>
            </p>
            <p class="tautan-bawah" style="margin-top:6px">
                <a href="index.php">← Kembali ke halaman utama</a>
            </p>
        </main>
    </section>
</div>

<script src="assets/js/app.js" defer></script>
</body>
</html>
