<?php
/**
 * reset_sandi.php - buat kata sandi baru lewat tautan dari surel.
 *
 * Token diambil dari ?token=... (saat membuka halaman) dan dikirim ulang
 * lewat kolom tersembunyi saat menyimpan (diverifikasi ulang).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

$tokenMentah = (string) ($_POST['token'] ?? $_GET['token'] ?? '');

$pesan  = '';
$status = 'gagal';
$galat  = [];
$berhasil = false;

/* Validasi token (dipakai untuk GET maupun POST) */
$dataToken = token_ambil($conn, 'reset_sandi', $tokenMentah);

$user = null;
if ($dataToken !== null) {
    $stmt = $conn->prepare('SELECT id, nama, email FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $uid = $dataToken['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $res  = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}

if ($dataToken === null || $user === null) {
    $pesan  = 'Tautan atur ulang tidak valid atau sudah kedaluwarsa. Silakan minta tautan baru.';
    $status = 'gagal';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $baru   = (string) ($_POST['sandi_baru'] ?? '');
    $ulangi = (string) ($_POST['sandi_ulangi'] ?? '');

    if (!csrf_cek('reset_sandi')) {
        $pesan = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
    } elseif ($baru !== $ulangi) {
        $galat['sandi_ulangi'] = 'Konfirmasi kata sandi tidak sama.';
    } else {
        $uid = (int) $user['id'];
        $galatSandi = password_validasi($baru, [
            'email' => (string) $user['email'],
            'nama'  => (string) $user['nama'],
        ]);
        if ($galatSandi !== null) {
            $galat['sandi_baru'] = $galatSandi;
        } else {
            $riwayat = password_terpakai_sebelumnya($conn, $uid, $baru);
            if ($riwayat !== null) {
                $galat['sandi_baru'] = $riwayat;
            }
        }
    }

    if ($galat === [] && $pesan === '') {
        $uid  = (int) $user['id'];
        $hash = password_hash($baru, PASSWORD_DEFAULT);

        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $hash, $uid);
            $stmt->execute();
            $stmt->close();

            password_simpan_riwayat($conn, $uid, $hash);

            $up = $conn->prepare(
                'UPDATE user_security SET password_diubah_pada = NOW() WHERE user_id = ?'
            );
            if ($up) {
                $up->bind_param('i', $uid);
                $up->execute();
                $up->close();
            }

            // Cabut semua sesi agar perangkat lama harus masuk ulang
            sesi_cabut($conn, $uid);

            token_pakai($conn, $dataToken['id']);

            catat_audit(
                $conn, 'reset_sandi', 'users', $uid,
                'Kata sandi diatur ulang lewat tautan surel', null, null
            );

            $berhasil = true;
            $pesan  = 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru Anda.';
            $status = 'sukses';
        } else {
            $pesan = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atur Ulang Kata Sandi — <?= e(APP_SINGKAT) ?></title>
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
            <h2 class="judul-auth">Buat kata sandi baru.</h2>
            <p class="teks-auth">
                Pilih kata sandi yang kuat dan belum pernah dipakai. Setelah
                tersimpan, semua sesi di perangkat lain akan dicabut.
            </p>
            <ul class="daftar-fitur">
                <li><span class="cek"><?= ikon_sp('kunci') ?></span> Minimal <?= PASSWORD_MIN ?> karakter</li>
                <li><span class="cek"><?= ikon_sp('kunci') ?></span> Gabungan huruf, angka, simbol</li>
                <li><span class="cek">&#8635;</span> <?= PASSWORD_RIWAYAT ?> sandi terakhir tidak boleh diulang</li>
            </ul>
        </div>
        <div class="foot-auth">Tautan berlaku <?= (int) (RESET_SANDI_DETIK / 60) ?> menit sejak dikirim.</div>
    </aside>

    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <h1>Kata Sandi Baru</h1>
            <p class="pengantar">Masukkan kata sandi baru untuk akun Anda.</p>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'sukses' ? 'sukses'
                    : ($status === 'info' ? 'info' : 'gagal') ?>" role="alert">
                    <span class="ikon"><?= $status === 'sukses' ? '&#10004;'
                        : ($status === 'info' ? '&#8505;' : '&#10006;') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($berhasil): ?>
                <a class="tombol tombol-utama tombol-lebar tombol-besar" href="login.php">Masuk Sekarang</a>
            <?php elseif ($dataToken === null || $user === null): ?>
                <a class="tombol tombol-utama tombol-lebar tombol-besar" href="lupa_sandi.php">Minta Tautan Baru</a>
            <?php else: ?>
                <form method="POST" action="reset_sandi.php" novalidate>
                    <?= csrf_field('reset_sandi') ?>
                    <input type="hidden" name="token" value="<?= e($tokenMentah) ?>">

                    <div class="grup-form">
                        <label for="sandi_baru">Kata sandi baru</label>
                        <div class="field-input">
                            <input id="sandi_baru" name="sandi_baru" type="password"
                                   class="<?= isset($galat['sandi_baru']) ? 'galat' : '' ?>"
                                   data-sandi="ukuranSandi"
                                   minlength="<?= PASSWORD_MIN ?>" maxlength="200"
                                   autocomplete="new-password" required autofocus>
                            <button class="mata" type="button" data-mata="sandi_baru"
                                    aria-label="Tampilkan kata sandi"><?= ikon_sp('mata') ?></button>
                        </div>
                        <div class="kekuatan-sandi" id="ukuranSandi"><span></span><span></span><span></span><span></span></div>
                        <div class="label-kekuatan"></div>
                        <span class="bantuan">
                            Minimal <?= PASSWORD_MIN ?> karakter.
                        </span>
                        <?php if (isset($galat['sandi_baru'])): ?>
                            <span class="pesan-galat"><?= e($galat['sandi_baru']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="grup-form">
                        <label for="sandi_ulangi">Ulangi kata sandi baru</label>
                        <div class="field-input">
                            <input id="sandi_ulangi" name="sandi_ulangi" type="password"
                                   class="<?= isset($galat['sandi_ulangi']) ? 'galat' : '' ?>"
                                   autocomplete="new-password" required>
                            <button class="mata" type="button" data-mata="sandi_ulangi"
                                    aria-label="Tampilkan kata sandi"><?= ikon_sp('mata') ?></button>
                        </div>
                        <?php if (isset($galat['sandi_ulangi'])): ?>
                            <span class="pesan-galat"><?= e($galat['sandi_ulangi']) ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                        Simpan Kata Sandi Baru
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
