<?php
/**
 * verifikasi_email.php - aktifkan akun melalui tautan pada surel.
 *
 * Menangani dua hal:
 *   1. GET ?token=...  -> memverifikasi tautan dan mengaktifkan akun.
 *   2. POST kirim_ulang -> mengirim ulang tautan verifikasi.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

$pesan  = '';
$status = 'gagal';      // gagal | info | sukses
$suksesAktif = false;

/* ---------------------------------------------------------------
 * 1. Verifikasi lewat tautan
 * ------------------------------------------------------------- */
$tokenMentah = (string) ($_GET['token'] ?? '');
if ($tokenMentah !== '') {
    $dataToken = token_ambil($conn, 'verifikasi_email', $tokenMentah);

    if ($dataToken === null) {
        $pesan  = 'Tautan verifikasi tidak valid atau sudah kedaluwarsa. '
            . 'Masukkan surel Anda untuk meminta tautan baru.';
        $status = 'gagal';
    } else {
        $uidVerifikasi = $dataToken['user_id'];

        $up = $conn->prepare(
            "UPDATE users SET status = 'aktif', email_verified_at = NOW()
             WHERE id = ? AND status = 'menunggu'"
        );
        if ($up) {
            $up->bind_param('i', $uidVerifikasi);
            $up->execute();
            $diubah = $up->affected_rows;
            $up->close();
        } else {
            $diubah = 0;
        }

        token_pakai($conn, $dataToken['id']);

        if ($diubah === 1) {
            catat_audit(
                $conn, 'verifikasi_email', 'users', $uidVerifikasi,
                'Surel akun diverifikasi', null, ['status' => 'aktif']
            );
            $pesan  = 'Akun Anda berhasil diverifikasi. Silakan masuk.';
            $status = 'sukses';
            $suksesAktif = true;
            unset($_SESSION['verifikasi_email_tujuan']);
        } else {
            // Sudah aktif sebelumnya atau status berubah
            $pesan  = 'Tautan sudah dipakai atau akun sudah aktif. Silakan masuk.';
            $status = 'info';
        }
    }
}

/* ---------------------------------------------------------------
 * 2. Kirim ulang tautan
 * ------------------------------------------------------------- */
$emailLama = (string) ($_SESSION['verifikasi_email_tujuan'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi  = (string) ($_POST['aksi'] ?? 'kirim_ulang');
    $email = trim((string) ($_POST['email'] ?? ''));
    $emailLama = $email;

    if ($aksi === 'kirim_ulang') {
        if (!csrf_cek('verifikasi_email')) {
            $pesan  = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
            $status = 'gagal';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pesan  = 'Masukkan alamat surel yang valid.';
            $status = 'gagal';
        } else {
            $ip = ip_klien();
            $alasan = 'verifikasi-surel';

            if (akun_hitung_aksi($conn, $ip, $alasan, 3600) >= 5) {
                $pesan  = 'Terlalu banyak permintaan dari jaringan Anda. Coba lagi dalam satu jam.';
                $status = 'gagal';
            } else {
                akun_catat_aksi($conn, $ip, $email, $alasan);

                $stmt = $conn->prepare(
                    "SELECT id, nama FROM users WHERE email = ? AND status = 'menunggu' LIMIT 1"
                );
                $info = null;
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $res  = $stmt->get_result();
                    $info = $res ? $res->fetch_assoc() : null;
                    $stmt->close();
                }

                if ($info) {
                    try {
                        token_bersihkan($conn);
                        $tok = token_buat($conn, (int) $info['id'], 'verifikasi_email', VERIFIKASI_EMAIL_DETIK);
                        $tautan = tautan_token('verifikasi_email.php', $tok);
                        unset($tok);
                        email_verifikasi_kirim($email, (string) $info['nama'], $tautan);
                    } catch (Throwable $t) {
                        error_log('verifikasi_email kirim ulang: ' . $t->getMessage());
                    }
                }

                // Pesan umum (tidak membocorkan apakah surel terdaftar)
                $pesan  = 'Jika alamat surel tersebut terdaftar dan belum diverifikasi, '
                    . 'kami telah mengirim tautan verifikasi. Periksa kotak masuk dan folder spam.';
                $status = 'info';
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
    <title>Verifikasi Surel — <?= e(APP_SINGKAT) ?></title>
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
            <h2 class="judul-auth">Aktifkan akun Anda.</h2>
            <p class="teks-auth">
                Demi keamanan, akun baru harus diverifikasi lewat surel sebelum
                dapat dipakai. Masukkan surel Anda untuk menerima tautan aktivasi.
            </p>
            <ul class="daftar-fitur">
                <li><span class="cek"><?= ikon_sp('surat') ?></span> Tautan berlaku <?= (int) (VERIFIKASI_EMAIL_DETIK / 3600) ?> jam</li>
                <li><span class="cek"><?= ikon_sp('kunci') ?></span> Hanya berlaku untuk satu akun</li>
                <li><span class="cek"><?= ikon_sp('centang') ?></span> Sekali klik, akun langsung aktif</li>
            </ul>
        </div>
        <div class="foot-auth">Belum menerima surel? Periksa folder spam atau kirim ulang.</div>
    </aside>

    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <h1>Verifikasi Surel</h1>
            <p class="pengantar">Kirim ulang tautan verifikasi bila belum diterima.</p>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'sukses' ? 'sukses'
                    : ($status === 'info' ? 'info' : 'gagal') ?>" role="alert">
                    <span class="ikon"><?= $status === 'sukses' ? '&#10004;'
                        : ($status === 'info' ? '&#8505;' : '&#10006;') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($suksesAktif): ?>
                <a class="tombol tombol-utama tombol-lebar tombol-besar" href="login.php">Masuk Sekarang</a>
            <?php else: ?>
                <form method="POST" action="verifikasi_email.php" novalidate>
                    <?= csrf_field('verifikasi_email') ?>
                    <input type="hidden" name="aksi" value="kirim_ulang">

                    <div class="grup-form">
                        <label for="email">Alamat surel</label>
                        <input id="email" name="email" type="email" maxlength="150"
                               value="<?= e($emailLama) ?>"
                               autocomplete="username"
                               placeholder="nama@perusahaan.co.id"
                               required autofocus>
                    </div>

                    <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                        Kirim Ulang Tautan
                    </button>
                </form>
            <?php endif; ?>

            <p class="tautan-bawah">
                <a href="login.php">&larr; Kembali ke halaman masuk</a>
            </p>
        </main>
    </section>
</div>

<script src="assets/js/app.js" defer></script>
</body>
</html>
