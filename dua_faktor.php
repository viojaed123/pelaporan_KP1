<?php
/**
 * dua_faktor.php — verifikasi kode OTP 6 digit (atau kode cadangan)
 * sebelum sesi penuh dibuat.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$uid = (int) ($_SESSION['tfa_uid'] ?? 0);
$email = (string) ($_SESSION['tfa_email'] ?? '');

if ($uid < 1 || $email === '') {
    header('Location: login.php');
    exit;
}

$pesan  = '';
$status = 'gagal';
$sisaKirim = (int) ($_SESSION['tfa_kirim'] ?? 0);
$user = user_dari_db($conn, $uid);
if ($user === null) {
    unset($_SESSION['tfa_uid'], $_SESSION['tfa_email']);
    header('Location: login.php?alasan=kadaluarsa');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? 'verifikasi');

    if (!csrf_cek('dua_faktor')) {
        $pesan = 'Formulir kedaluwarsa. Muat ulang halaman.';
    } elseif ($aksi === 'kirim_ulang') {
        $kirimUlang = (int) ($_SESSION['tfa_kirim_ulang'] ?? 0);
        if ($kirimUlang >= 3) {
            $status = 'info';
            $pesan  = 'Batas pengiriman ulang tercapai. Gunakan kode cadangan atau hubungi admin.';
        } else {
            try {
                $kode = otp_buat($conn, $uid, $email);
                $kirim = otp_kirim($email, $kode, (string) $user['nama']);
                unset($kode);
                $_SESSION['tfa_kirim_ulang'] = $kirimUlang + 1;
                $_SESSION['tfa_kirim'] = 1;
                $status = 'info';
                $pesan  = $kirim['ok']
                    ? 'Kode baru telah dikirim ke ' . potong($email, 30) . '.'
                    : 'Gagal mengirim kode: ' . (string) $kirim['galat'];
            } catch (Throwable $t) {
                $status = 'gagal';
                $pesan  = 'Gagal menyiapkan kode. Coba lagi sebentar lagi.';
            }
        }
    } else {
        $kode = trim((string) ($_POST['kode'] ?? ''));

        if ($kode === '') {
            $pesan = 'Masukkan kode verifikasi.';
        } else {
            $hasil = otp_verifikasi($conn, $uid, $kode);

            if (!$hasil['ok'] && strlen($kode) === 11 && strpos($kode, '-') !== false) {
                // Format kode cadangan: XXXXXX-XXXX (6 heksadesimal + '-' + 4 heksadesimal)
                if (backup_codes_pakai($conn, $uid, $kode)) {
                    $hasil = ['ok' => true, 'pesan' => 'Kode cadangan diterima.'];
                }
            }

            if ($hasil['ok']) {
                catat_audit(
                    $conn, 'login_2fa_berhasil', 'users', $uid,
                    'Verifikasi dua faktor berhasil', null, null
                );
                login_tuntaskan($conn, $user, '2fa');
            } else {
                catat_audit(
                    $conn, 'login_2fa_gagal', 'users', $uid,
                    'Verifikasi dua faktor gagal', null, null
                );

                $pesan  = (string) $hasil['pesan'];
                $status = 'gagal';
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
    <title>Verifikasi Dua Faktor — <?= e(APP_SINGKAT) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <style>
        .kode-masuk {
            font-family: var(--font-mono);
            font-size: 30px;
            letter-spacing: 12px;
            text-align: center;
            padding-left: 24px;
            font-weight: 700;
        }
    </style>
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
            <h2 class="judul-auth">Langkah terakhir.</h2>
            <p class="teks-auth">
                Kami baru saja mengirim kode 6 angka ke
                <strong><?= e($email) ?></strong>.
                Masukkan kode tersebut untuk menyelesaikan proses masuk.
            </p>
            <ul class="daftar-fitur">
                <li><span class="cek"><?= ikon_sp('jam') ?></span> Kode berlaku <?= (int) (OTP_DETIK / 60) ?> menit</li>
                <li><span class="cek"><?= ikon_sp('kunci') ?></span> Kode hanya berlaku satu kali</li>
                <li><span class="cek"><?= ikon_sp('surat') ?></span> Tidak menerima surel? Pakai kode cadangan</li>
            </ul>
        </div>

        <div class="foot-auth">Tidak meminta kode ini? Segera ganti kata sandi Anda.</div>
    </aside>

    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <h1>Verifikasi Dua Faktor</h1>
            <p class="pengantar">
                Masukkan kode dari surel Anda. Jika mengaktifkan kode cadangan,
                ketik format <strong>XXXXX-XXXXX</strong>.
            </p>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'info' ? 'info' : 'gagal' ?>" role="alert">
                    <span class="ikon"><?= $status === 'info' ? 'ℹ' : '✕' ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="dua_faktor.php" autocomplete="one-time-code">
                <?= csrf_field('dua_faktor') ?>
                <input type="hidden" name="aksi" value="verifikasi">

                <div class="grup-form">
                    <label for="kode">Kode verifikasi</label>
                    <input class="kode-masuk" id="kode" name="kode" type="text"
                           inputmode="numeric" maxlength="12"
                           placeholder="000000"
                           pattern="[0-9\- ]{6,12}"
                           autocomplete="one-time-code"
                           required autofocus>
                    <span class="bantuan">6 angka — tanpa spasi atau tanda baca lain.</span>
                </div>

                <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                    Verifikasi &amp; Masuk
                </button>
            </form>

            <form method="POST" action="dua_faktor.php" style="margin-top:14px">
                <?= csrf_field('dua_faktor') ?>
                <input type="hidden" name="aksi" value="kirim_ulang">
                <button class="tombol tombol-lebar" type="submit">
                    ↻ Kirim ulang kode (sisa <?= max(0, 3 - (int) ($_SESSION['tfa_kirim_ulang'] ?? 0)) ?>)
                </button>
            </form>

            <p class="tautan-bawah">
                <a href="login.php">← Kembali ke halaman masuk</a>
            </p>
        </main>
    </section>
</div>

<script src="assets/js/app.js" defer></script>
</body>
</html>
