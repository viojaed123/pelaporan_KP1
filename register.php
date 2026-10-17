<?php
/**
 * register.php — pendaftaran akun karyawan.
 * Penguatan: CSRF, kebijakan kata sandi, validasi panjang, pembatas laju
 * pendaftaran per IP, pencatatan audit, pengalihan otomatis ke halaman masuk.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

$pesan  = '';
$status = 'gagal';
$galatKolom = [];

// Nilai lama agar tidak perlu mengetik ulang
$namaLama  = (string) ($_POST['nama'] ?? '');
$emailLama = (string) ($_POST['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama     = trim($namaLama);
    $email    = trim($emailLama);
    $password = (string) ($_POST['password'] ?? '');
    $ulangi   = (string) ($_POST['ulangi'] ?? '');

    if (!csrf_cek('register')) {
        $pesan = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } else {
        // Pembatas laju pendaftaran: maksimal 5 per jam per IP
        $ip = ip_klien();
        $batas = date('Y-m-d H:i:s', time() - 3600);
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND alasan LIKE "daftar%" AND dibuat_pada >= ?'
        );
        $jumlahDaftar = 0;
        if ($stmt) {
            $stmt->bind_param('ss', $ip, $batas);
            $stmt->execute();
            $stmt->bind_result($jumlahDaftar);
            $stmt->fetch();
            $stmt->close();
        }

        if ((int) $jumlahDaftar >= 5) {
            $pesan = 'Terlalu banyak percobaan pendaftaran dari jaringan Anda. Coba lagi dalam satu jam.';
        } elseif ($nama === '' || $email === '' || $password === '') {
            $pesan = 'Semua kolom wajib diisi.';
        } elseif (mb_strlen($nama) > 100) {
            $galatKolom['nama'] = 'Nama maksimal 100 karakter.';
        } elseif (mb_strlen($nama) < 3) {
            $galatKolom['nama'] = 'Nama minimal 3 karakter.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            $galatKolom['email'] = 'Format alamat email tidak valid.';
        } elseif ($password !== $ulangi) {
            $galatKolom['ulangi'] = 'Konfirmasi kata sandi tidak sama.';
        } else {
            $galatSandi = password_validasi($password, ['email' => $email, 'nama' => $nama]);

            if ($galatSandi !== null) {
                $galatKolom['password'] = $galatSandi;
            } else {
                $cek = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
                $ada = false;
                if ($cek) {
                    $cek->bind_param('s', $email);
                    $cek->execute();
                    $cek->store_result();
                    $ada = $cek->num_rows > 0;
                    $cek->close();
                }

                if ($ada) {
                    $galatKolom['email'] = 'Alamat email sudah terdaftar.';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $role = 'karyawan';
                    $statusAkun = 'aktif';   // langsung dapat masuk (tanpa verifikasi surel)

                    $stmt = $conn->prepare(
                        'INSERT INTO users (nama, email, password, role, status, email_verified_at)
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    );

                    if ($stmt && $stmt->bind_param('sssss', $nama, $email, $hash, $role, $statusAkun)
                        && $stmt->execute()) {
                        $uid = (int) $conn->insert_id;
                        $stmt->close();

                        user_security_pastikan($conn, $uid);
                        password_simpan_riwayat($conn, $uid, $hash);
                        login_catat_sukses($conn, $ip, $email, $uid);
                        pendaftaran_catat($conn, $ip, $email, true);

                        catat_audit(
                            $conn, 'daftar', 'users', $uid,
                            'Pendaftaran akun baru: ' . $nama, null,
                            ['nama' => $nama, 'email' => $email, 'role' => 'karyawan', 'status' => 'aktif']
                        );

                        flash_set(
                            'sukses',
                            'Pendaftaran berhasil. Akun Anda langsung aktif — silakan masuk dengan '
                            . 'email dan kata sandi Anda.'
                        );
                        header('Location: login.php');
                        exit;
                    }

                    if ($stmt) {
                        $stmt->close();
                    }
                    $pesan = 'Pendaftaran gagal. Silakan coba lagi.';
                }
            }
        }

        // Catat percobaan (untuk pembatas laju pendaftaran)
        pendaftaran_catat($conn, $ip, $email, false);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buat Akun — <?= e(APP_SINGKAT) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
</head>
<body>
<div class="layar-auth">
    <aside class="panel-kiri-auth">
        <div class="merek-auth">
            <?= logo_phr() ?>
            <span>
                <span class="nama"><?= e(APP_SINGKAT) ?></span><br>
                <span class="sub"><?= e(APP_ORGANISASI) ?></span>
            </span>
        </div>

        <div>
            <h2 class="judul-auth">Mulai catat kegiatan hari ini.</h2>
            <p class="teks-auth">
                Pendaftaran terbuka untuk karyawan. Akun baru langsung aktif dan
                dapat masuk serta mengirim laporan harian tanpa menunggu verifikasi.
            </p>
            <ul class="daftar-fitur">
                <li><span class="cek">✓</span> Laporan harian + unggah bukti foto</li>
                <li><span class="cek">✓</span> Absensi &amp; rekap siap unduh</li>
                <li><span class="cek">✓</span> Catatan revisi langsung dari mentor</li>
            </ul>
        </div>

        <div class="foot-auth">Butuh peran mentor/admin? Hubungi pengelola sistem.</div>
    </aside>

    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <h1>Buat Akun</h1>
            <p class="pengantar">Daftar untuk mulai menggunakan sistem pelaporan kegiatan.</p>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'sukses' ? 'sukses' : 'gagal' ?>" role="alert">
                    <span class="ikon"><?= $status === 'sukses' ? '✓' : '✕' ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" autocomplete="on" novalidate>
                <?= csrf_field('register') ?>

                <div class="grup-form">
                    <label for="nama">Nama lengkap <span class="wajib">*</span></label>
                    <input id="nama" name="nama" type="text" maxlength="100"
                           value="<?= e($namaLama) ?>"
                           class="<?= isset($galatKolom['nama']) ? 'galat' : '' ?>"
                           placeholder="Nama sesuai identitas" required autofocus>
                    <?php if (isset($galatKolom['nama'])): ?>
                        <span class="pesan-galat"><?= e($galatKolom['nama']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="grup-form">
                    <label for="email">Alamat email <span class="wajib">*</span></label>
                    <input id="email" name="email" type="email" maxlength="150"
                           value="<?= e($emailLama) ?>"
                           class="<?= isset($galatKolom['email']) ? 'galat' : '' ?>"
                           autocomplete="username"
                           placeholder="nama@perusahaan.co.id" required>
                    <?php if (isset($galatKolom['email'])): ?>
                        <span class="pesan-galat"><?= e($galatKolom['email']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="grup-form">
                    <label for="password">Kata sandi <span class="wajib">*</span></label>
                    <div class="field-input">
                        <input id="password" name="password" type="password"
                               class="<?= isset($galatKolom['password']) ? 'galat' : '' ?>"
                               data-sandi="ukuranSandi"
                               autocomplete="new-password"
                               minlength="<?= PASSWORD_MIN ?>" maxlength="200"
                               placeholder="Minimal <?= PASSWORD_MIN ?> karakter" required>
                        <button class="mata" type="button" data-mata="password"
                                aria-label="Tampilkan kata sandi"><?= ikon_sp('mata') ?></button>
                    </div>
                    <div class="kekuatan-sandi" id="ukuranSandi"><span></span><span></span><span></span><span></span></div>
                    <div class="label-kekuatan"></div>
                    <span class="bantuan">
                        Minimal <?= PASSWORD_MIN ?> karakter. Hindari kata sandi yang mudah ditebak.
                    </span>
                    <?php if (isset($galatKolom['password'])): ?>
                        <span class="pesan-galat"><?= e($galatKolom['password']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="grup-form">
                    <label for="ulangi">Ulangi kata sandi <span class="wajib">*</span></label>
                    <div class="field-input">
                        <input id="ulangi" name="ulangi" type="password"
                               class="<?= isset($galatKolom['ulangi']) ? 'galat' : '' ?>"
                               autocomplete="new-password"
                               placeholder="Ketik ulang kata sandi" required>
                        <button class="mata" type="button" data-mata="ulangi"
                                aria-label="Tampilkan kata sandi"><?= ikon_sp('mata') ?></button>
                    </div>
                    <?php if (isset($galatKolom['ulangi'])): ?>
                        <span class="pesan-galat"><?= e($galatKolom['ulangi']) ?></span>
                    <?php endif; ?>
                </div>

                <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                    Daftar Sekarang
                </button>
            </form>

            <p class="tautan-bawah">Sudah punya akun? <a href="login.php">Masuk</a></p>
            <p class="tautan-bawah" style="margin-top:6px">
                <a href="index.php">← Kembali ke halaman utama</a>
            </p>
        </main>
    </section>
</div>

<script src="assets/js/app.js" defer></script>
</body>
</html>
