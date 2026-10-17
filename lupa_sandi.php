<?php
/**
 * lupa_sandi.php - atur ulang kata sandi memakai KODE OTP lewat surel.
 *
 * Alur 3 langkah (tanpa tautan):
 *   1. Masukkan surel  -> kami kirim kode OTP 6 angka.
 *   2. Masukkan kode   -> diverifikasi sekali pakai.
 *   3. Buat kata sandi baru.
 *
 * Pesan tidak membocorkan apakah sebuah surel terdaftar atau tidak.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

/** Samarkan sebagian alamat surel untuk ditampilkan. */
if (!function_exists('mask_surel')) {
    function mask_surel(string $email): string
    {
        $pos = strrpos($email, '@');
        if ($pos === false || $pos < 1) {
            return $email;
        }
        $lokal = substr($email, 0, $pos);
        $domain = substr($email, $pos);
        $awal = substr($lokal, 0, 1);
        return $awal . str_repeat('*', max(1, strlen($lokal) - 1)) . $domain;
    }
}

$pesan  = '';
$status = 'info';   // info | sukses | gagal
$galat  = [];
$emailLama = '';

$uid         = (int) ($_SESSION['reset_uid'] ?? 0);
$emailSesi   = (string) ($_SESSION['reset_email'] ?? '');
$diverifikasi = (bool) ($_SESSION['reset_verified'] ?? false);

// Langkah saat ini berdasarkan status sesi
if ($diverifikasi && $uid > 0) {
    $langkah = 3;
} elseif ($uid > 0) {
    $langkah = 2;
} else {
    $langkah = 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['langkah'] ?? '');

    if (!csrf_cek('lupa_sandi')) {
        $pesan  = 'Formulir kedaluwarsa. Muat ulang halaman lalu coba lagi.';
        $status = 'gagal';
    } elseif ($aksi === '1') {
        /* ---------- Langkah 1: minta kode OTP ---------- */
        $email = trim((string) ($_POST['email'] ?? ''));
        $emailLama = $email;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $pesan  = 'Masukkan alamat surel yang valid.';
            $status = 'gagal';
            $langkah = 1;
        } else {
            $ip = ip_klien();
            $alasan = 'reset-otp';

            if (akun_hitung_aksi($conn, $ip, $alasan, 3600) >= 5) {
                $pesan  = 'Terlalu banyak permintaan dari jaringan Anda. Coba lagi dalam satu jam.';
                $status = 'gagal';
                $langkah = 1;
            } else {
                akun_catat_aksi($conn, $ip, $email, $alasan);

                $stmt = $conn->prepare('SELECT id, nama, email, status FROM users WHERE email = ? LIMIT 1');
                $info = null;
                if ($stmt) {
                    $stmt->bind_param('s', $email);
                    $stmt->execute();
                    $res  = $stmt->get_result();
                    $info = $res ? $res->fetch_assoc() : null;
                    $stmt->close();
                }

                if ($info && (string) $info['status'] !== 'nonaktif') {
                    try {
                        $kode = otp_buat($conn, (int) $info['id'], (string) $info['email'], 'reset_sandi');
                        otp_kirim((string) $info['email'], $kode, (string) $info['nama']);
                        unset($kode);

                        $_SESSION['reset_uid']   = (int) $info['id'];
                        $_SESSION['reset_email'] = (string) $info['email'];
                        unset($_SESSION['reset_verified']);

                        catat_audit(
                            $conn, 'reset_sandi_otp_diminta', 'users', (int) $info['id'],
                            'Permintaan kode OTP atur ulang kata sandi', null, null
                        );
                    } catch (Throwable $t) {
                        error_log('reset_sandi otp: ' . $t->getMessage());
                    }
                } else {
                    // Tetap lanjut ke langkah 2 tanpa membocorkan keberadaan akun
                    unset($_SESSION['reset_uid'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
                }

                $langkah = 2;
                $pesan  = 'Jika alamat surel tersebut terdaftar, kami telah mengirim kode OTP 6 angka. '
                    . 'Kode berlaku ' . (int) (OTP_DETIK / 60) . ' menit. Periksa kotak masuk dan folder spam.';
                $status = 'info';
            }
        }
    } elseif ($aksi === '2') {
        /* ---------- Langkah 2: verifikasi kode OTP ---------- */
        $kode = (string) ($_POST['kode'] ?? '');
        $uid  = (int) ($_SESSION['reset_uid'] ?? 0);

        if ($uid <= 0) {
            $pesan  = 'Sesi atur ulang tidak ditemukan. Mulai lagi dari awal.';
            $status = 'gagal';
            $langkah = 1;
        } else {
            $v = otp_verifikasi($conn, $uid, $kode, 'reset_sandi');
            if ($v['ok']) {
                $_SESSION['reset_verified'] = true;
                $langkah = 3;
                $pesan  = 'Kode benar. Silakan buat kata sandi baru Anda.';
                $status = 'sukses';
            } else {
                $pesan  = (string) $v['pesan'];
                $status = 'gagal';
                $langkah = 2;
            }
        }
    } elseif ($aksi === '3') {
        /* ---------- Langkah 3: simpan kata sandi baru ---------- */
        $uid          = (int) ($_SESSION['reset_uid'] ?? 0);
        $diverifikasi = (bool) ($_SESSION['reset_verified'] ?? false);
        $baru   = (string) ($_POST['sandi_baru'] ?? '');
        $ulangi = (string) ($_POST['sandi_ulangi'] ?? '');

        if ($uid <= 0 || !$diverifikasi) {
            $pesan  = 'Sesi tidak valid atau sudah kedaluwarsa. Mulai lagi dari awal.';
            $status = 'gagal';
            $langkah = 1;
            unset($_SESSION['reset_uid'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
        } else {
            $stmt = $conn->prepare('SELECT id, nama, email FROM users WHERE id = ? LIMIT 1');
            $user = null;
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $res  = $stmt->get_result();
                $user = $res ? $res->fetch_assoc() : null;
                $stmt->close();
            }

            if ($user === null) {
                $pesan  = 'Akun tidak ditemukan. Mulai lagi dari awal.';
                $status = 'gagal';
                $langkah = 1;
                unset($_SESSION['reset_uid'], $_SESSION['reset_email'], $_SESSION['reset_verified']);
            } elseif ($baru !== $ulangi) {
                $galat['sandi_ulangi'] = 'Konfirmasi kata sandi tidak sama.';
            } else {
                $g = password_validasi($baru, [
                    'email' => (string) $user['email'],
                    'nama'  => (string) $user['nama'],
                ]);
                if ($g !== null) {
                    $galat['sandi_baru'] = $g;
                } else {
                    $r = password_terpakai_sebelumnya($conn, $uid, $baru);
                    if ($r !== null) {
                        $galat['sandi_baru'] = $r;
                    }
                }
            }

            if ($galat === []) {
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

                    // Tandai seluruh OTP reset sudah terpakai
                    $jenis = 'reset_sandi';
                    $up = $conn->prepare('UPDATE user_otp SET dipakai = 1 WHERE user_id = ? AND jenis = ?');
                    if ($up) {
                        $up->bind_param('is', $uid, $jenis);
                        $up->execute();
                        $up->close();
                    }

                    catat_audit(
                        $conn, 'reset_sandi', 'users', $uid,
                        'Kata sandi diatur ulang lewat kode OTP surel', null, null
                    );

                    unset($_SESSION['reset_uid'], $_SESSION['reset_email'], $_SESSION['reset_verified']);

                    $pesan  = 'Kata sandi berhasil diperbarui. Silakan masuk dengan kata sandi baru Anda.';
                    $status = 'sukses';
                    $langkah = 0;
                } else {
                    $pesan  = 'Gagal memperbarui kata sandi. Silakan coba lagi.';
                    $status = 'gagal';
                }
            } else {
                $langkah = 3;
            }
        }
    }
}

if ($emailSesi !== '') {
    $emailTampil = mask_surel($emailSesi);
} else {
    $emailTampil = '';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lupa Kata Sandi — <?= e(APP_SINGKAT) ?></title>
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
            <h2 class="judul-auth">Lupa kata sandi?</h2>
            <p class="teks-auth">
                Masukkan alamat surel akun Anda. Kami akan mengirim kode OTP untuk
                membuat kata sandi baru dengan aman.
            </p>
            <ul class="daftar-fitur">
                <li><span class="cek"><?= ikon_sp('surat') ?></span> Kode OTP 6 angka dikirim ke surel</li>
                <li><span class="cek"><?= ikon_sp('kunci') ?></span> Berlaku <?= (int) (OTP_DETIK / 60) ?> menit &amp; sekali pakai</li>
                <li><span class="cek"><?= ikon_sp('pengguna') ?></span> Sesi lain dicabut setelah sandi berganti</li>
            </ul>
        </div>
        <div class="foot-auth">Belum punya akun? <a href="register.php">Daftar di sini</a>.</div>
    </aside>

    <section class="panel-kanan-auth">
        <main class="kartu-auth">
            <?php if ($langkah === 0): ?>
                <h1>Kata Sandi Diperbarui</h1>
                <p class="pengantar">Kata sandi baru Anda sudah tersimpan.</p>
            <?php elseif ($langkah === 2): ?>
                <h1>Masukkan Kode OTP</h1>
                <p class="pengantar">
                    Kode 6 angka dikirim ke
                    <strong><?= e($emailTampil !== '' ? $emailTampil : 'surel Anda') ?></strong>.
                </p>
            <?php elseif ($langkah === 3): ?>
                <h1>Kata Sandi Baru</h1>
                <p class="pengantar">Masukkan kata sandi baru untuk akun Anda.</p>
            <?php else: ?>
                <h1>Atur Ulang Kata Sandi</h1>
                <p class="pengantar">Kami akan mengirim kode OTP ke surel Anda.</p>
            <?php endif; ?>

            <?php if ($pesan !== ''): ?>
                <div class="alert alert-<?= $status === 'sukses' ? 'sukses'
                    : ($status === 'info' ? 'info' : 'gagal') ?>" role="alert">
                    <span class="ikon"><?= $status === 'sukses' ? '&#10004;'
                        : ($status === 'info' ? '&#8505;' : '&#10006;') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($langkah === 0): ?>
                <a class="tombol tombol-utama tombol-lebar tombol-besar" href="login.php">Masuk Sekarang</a>

            <?php elseif ($langkah === 2): ?>
                <form method="POST" action="lupa_sandi.php" novalidate>
                    <?= csrf_field('lupa_sandi') ?>
                    <input type="hidden" name="langkah" value="2">

                    <div class="grup-form">
                        <label for="kode">Kode OTP</label>
                        <input id="kode" name="kode" type="text" inputmode="numeric"
                               pattern="[0-9]*" maxlength="<?= OTP_PANJANG ?>"
                               autocomplete="one-time-code"
                               placeholder="<?= str_repeat('0', OTP_PANJANG) ?>"
                               required autofocus>
                    </div>

                    <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                        Verifikasi Kode
                    </button>
                </form>

                <form method="POST" action="lupa_sandi.php" style="margin-top:10px">
                    <?= csrf_field('lupa_sandi') ?>
                    <input type="hidden" name="langkah" value="1">
                    <input type="hidden" name="email" value="<?= e($emailSesi) ?>">
                    <button class="tombol tombol-garis tombol-lebar" type="submit">
                        Kirim Ulang Kode
                    </button>
                </form>

                <p class="tautan-bawah">
                    <a href="lupa_sandi.php">Ganti alamat surel</a>
                </p>

            <?php elseif ($langkah === 3): ?>
                <form method="POST" action="lupa_sandi.php" novalidate>
                    <?= csrf_field('lupa_sandi') ?>
                    <input type="hidden" name="langkah" value="3">

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
                        <span class="bantuan">Minimal <?= PASSWORD_MIN ?> karakter.</span>
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

            <?php else: ?>
                <form method="POST" action="lupa_sandi.php" novalidate>
                    <?= csrf_field('lupa_sandi') ?>
                    <input type="hidden" name="langkah" value="1">

                    <div class="grup-form">
                        <label for="email">Alamat surel</label>
                        <input id="email" name="email" type="email" maxlength="150"
                               value="<?= e($emailLama) ?>"
                               autocomplete="username"
                               placeholder="nama@perusahaan.co.id"
                               required autofocus>
                    </div>

                    <button class="tombol tombol-utama tombol-lebar tombol-besar" type="submit">
                        Kirim Kode OTP
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
