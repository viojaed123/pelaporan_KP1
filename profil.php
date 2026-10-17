<?php
/**
 * profil.php — Pusat Akun & Keamanan.
 *
 * Fitur:
 *   1. Perbarui identitas (nama, divisi)
 *   2. Ganti kata sandi (kebijakan + larangan kata sandi lama)
 *   3. Kelola verifikasi dua faktor (aktifkan/nonaktifkan, kode cadangan)
 *   4. Daftar sesi aktif per perangkat + cabut sesi
 *   5. (Admin) Setel ulang kata sandi pengguna lain
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid  = (int) sesi_user_id();
$role = sesi_role();
$user = user_dari_db($conn, $uid);

if ($user === null) {
    header('Location: logout.php');
    exit;
}

user_security_pastikan($conn, $uid);

/* ---------------------------------------------------------------
 * Ekspor daftar pengguna (admin) — CSV
 * ------------------------------------------------------------- */
if ($role === 'admin' && (string) ($_GET['ekspor'] ?? '') === 'pengguna') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pengguna_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM agar Excel membaca UTF-8
    fputcsv($out, ['ID', 'Nama', 'Email', 'Peran', 'Status', 'Divisi', 'Telepon', 'Dibuat']);

    $q = $conn->query(
        'SELECT id, nama, email, role, status, divisi, telepon, created_at
           FROM users ORDER BY role, nama'
    );
    while ($q && ($b = $q->fetch_assoc())) {
        fputcsv($out, [
            (int) $b['id'],
            (string) $b['nama'],
            (string) $b['email'],
            (string) $b['role'],
            (string) ($b['status'] ?? ''),
            (string) ($b['divisi'] ?? ''),
            (string) ($b['telepon'] ?? ''),
            (string) ($b['created_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

// Hash kata sandi diambil terpisah dari data tampilan (lihat auth.php).
$sandiHash = user_sandi_hash($conn, $uid);

$galat = [];
$sukses = '';

/* ================================================================
 * Penanganan POST
 * ============================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    csrf_cek_atau_matikan('profil_' . $aksi);

    switch ($aksi) {

        /* ---------- 1. Perbarui identitas ---------- */
        case 'perbarui_profil': {
            $nama    = trim((string) ($_POST['nama'] ?? ''));
            $divisi  = trim((string) ($_POST['divisi'] ?? ''));
            $telepon = trim((string) ($_POST['telepon'] ?? ''));

            if (mb_strlen($nama) < 3 || mb_strlen($nama) > 100) {
                $galat['nama'] = 'Nama harus 3–100 karakter.';
            }
            if (mb_strlen($divisi) > 100) {
                $galat['divisi'] = 'Nama divisi maksimal 100 karakter.';
            }
            if ($telepon !== '' && preg_match('/^[0-9+()\-\s\.]{6,30}$/', $telepon) !== 1) {
                $galat['telepon'] = 'Nomor telepon tidak valid (6–30 digit).';
            }

            if ($galat === []) {
                $lama = [
                    'nama'    => (string) $user['nama'],
                    'divisi'  => (string) ($user['divisi'] ?? ''),
                    'telepon' => (string) ($user['telepon'] ?? ''),
                ];
                /* Normalkan ke format WhatsApp (62...) agar notifikasi terkirim. */
                $teleponNorm = $telepon === '' ? '' : wa_normalkan_nomor($telepon);
                $stmt = $conn->prepare('UPDATE users SET nama = ?, divisi = ?, telepon = ? WHERE id = ?');
                if ($stmt) {
                    $divisiDb  = $divisi === '' ? null : $divisi;
                    $teleponDb = $teleponNorm === '' ? null : $teleponNorm;
                    $stmt->bind_param('sssi', $nama, $divisiDb, $teleponDb, $uid);
                    $stmt->execute();
                    $stmt->close();

                    $_SESSION['nama'] = $nama;

                    catat_audit(
                        $conn, 'perbarui_profil', 'users', $uid,
                        'Memperbarui identitas akun', $lama,
                        ['nama' => $nama, 'divisi' => $divisiDb, 'telepon' => $teleponDb]
                    );

                    flash_set('sukses', 'Identitas akun berhasil diperbarui.');
                    header('Location: profil.php');
                    exit;
                }
                $galat['umum'] = 'Gagal memperbarui identitas.';
            }
            break;
        }

        /* ---------- 1b. Unggah foto profil ---------- */
        case 'unggah_foto': {
            $batasBita = 2 * 1024 * 1024; // 2 MB

            if (!isset($_FILES['foto']) || !is_array($_FILES['foto'])) {
                $galat['foto'] = 'Tidak ada berkas yang diunggah.';
                break;
            }

            $f = $_FILES['foto'];
            $kodeGalat = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);

            if ($kodeGalat !== UPLOAD_ERR_OK) {
                $galat['foto'] = pesan_galat_unggah($kodeGalat);
                break;
            }

            $ukuran = (int) ($f['size'] ?? 0);
            if ($ukuran <= 0) {
                $galat['foto'] = 'Berkas kosong atau tidak valid.';
                break;
            }
            if ($ukuran > $batasBita) {
                $galat['foto'] = 'Ukuran foto maksimal 2 MB.';
                break;
            }

            $tmp = (string) ($f['tmp_name'] ?? '');

            /* Pastikan benar-benar gambar & tentukan formatnya dari isi berkas. */
            $info = @getimagesize($tmp);
            if ($info === false) {
                $galat['foto'] = 'Berkas bukan gambar yang valid.';
                break;
            }

            $petaMime = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
            ];
            $mime = (string) ($info['mime'] ?? '');
            if (!isset($petaMime[$mime])) {
                $galat['foto'] = 'Format foto harus JPG, PNG, atau WEBP.';
                break;
            }

            $dir = __DIR__ . '/uploads/avatar';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                $galat['foto'] = 'Folder penyimpanan foto tidak dapat dibuat.';
                break;
            }

            $namaBaru = 'u' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $petaMime[$mime];
            $tujuan   = $dir . '/' . $namaBaru;

            if (!@move_uploaded_file($tmp, $tujuan)) {
                $galat['foto'] = 'Gagal menyimpan foto. Silakan coba lagi.';
                break;
            }
            @chmod($tujuan, 0644);

            $fotoLama = (string) ($user['foto_profil'] ?? '');

            $stmt = $conn->prepare('UPDATE users SET foto_profil = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $namaBaru, $uid);
                $stmt->execute();
                $stmt->close();
            }

            /* Hapus berkas lama bila ada dan namanya aman. */
            if ($fotoLama !== '' && basename($fotoLama) === $fotoLama) {
                $berkasLama = $dir . '/' . $fotoLama;
                if (is_file($berkasLama)) {
                    @unlink($berkasLama);
                }
            }

            $_SESSION['foto'] = $namaBaru;

            catat_audit(
                $conn, 'unggah_foto_profil', 'users', $uid,
                'Memperbarui foto profil',
                ['foto_profil' => $fotoLama === '' ? null : $fotoLama],
                ['foto_profil' => $namaBaru]
            );

            flash_set('sukses', 'Foto profil berhasil diperbarui.');
            header('Location: profil.php');
            exit;
        }

        /* ---------- 1c. Hapus foto profil ---------- */
        case 'hapus_foto': {
            $fotoLama = (string) ($user['foto_profil'] ?? '');

            if ($fotoLama === '') {
                flash_set('info', 'Belum ada foto profil untuk dihapus.');
                header('Location: profil.php');
                exit;
            }

            $stmt = $conn->prepare('UPDATE users SET foto_profil = NULL WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->close();
            }

            if (basename($fotoLama) === $fotoLama) {
                $berkasLama = __DIR__ . '/uploads/avatar/' . $fotoLama;
                if (is_file($berkasLama)) {
                    @unlink($berkasLama);
                }
            }

            $_SESSION['foto'] = '';

            catat_audit(
                $conn, 'hapus_foto_profil', 'users', $uid,
                'Menghapus foto profil',
                ['foto_profil' => $fotoLama],
                ['foto_profil' => null]
            );

            flash_set('sukses', 'Foto profil berhasil dihapus. Avatar kembali memakai inisial.');
            header('Location: profil.php');
            exit;
        }

        /* ---------- 2. Ganti kata sandi ---------- */
        case 'ganti_sandi': {
            $lama    = (string) ($_POST['sandi_lama'] ?? '');
            $baru    = (string) ($_POST['sandi_baru'] ?? '');
            $ulangi  = (string) ($_POST['sandi_ulangi'] ?? '');

            if (!password_verify($lama, $sandiHash)) {
                $galat['sandi_lama'] = 'Kata sandi saat ini tidak sesuai.';
            }
            if ($baru !== $ulangi) {
                $galat['sandi_ulangi'] = 'Konfirmasi kata sandi tidak sama.';
            }

            $galatSandi = password_validasi($baru, [
                'email' => (string) $user['email'],
                'nama'  => (string) $user['nama'],
            ]);
            if ($galatSandi !== null) {
                $galat['sandi_baru'] = $galatSandi;
            } elseif (password_verify($baru, $sandiHash)) {
                $galat['sandi_baru'] = 'Kata sandi baru tidak boleh sama dengan yang sekarang.';
            } else {
                $riwayat = password_terpakai_sebelumnya($conn, $uid, $baru);
                if ($riwayat !== null) {
                    $galat['sandi_baru'] = $riwayat;
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

                    // Cabut sesi lain agar perangkat lama harus masuk ulang
                    sesi_cabut($conn, $uid);
                    sesi_daftar($conn, $uid);

                    catat_audit($conn, 'ganti_sandi', 'users', $uid, 'Kata sandi diubah', null, null);

                    flash_set('sukses', 'Kata sandi berhasil diubah. Sesi di perangkat lain telah dicabut.');
                    header('Location: profil.php');
                    exit;
                }
                $galat['umum'] = 'Gagal mengubah kata sandi.';
            }
            break;
        }

        /* ---------- 3a. Minta kode untuk aktifkan 2FA ---------- */
        case '2fa_minta': {
            try {
                $kode  = otp_buat($conn, $uid, (string) $user['email']);
                $kirim = otp_kirim((string) $user['email'], $kode, (string) $user['nama']);
                unset($kode);

                $_SESSION['profil_2fa_pending'] = 1;

                if ($kirim['ok']) {
                    flash_set('info', 'Kode verifikasi dikirim ke ' . $user['email'] . '. Masukkan kode untuk mengaktifkan 2FA.');
                } else {
                    flash_set('gagal', 'Gagal mengirim kode: ' . (string) $kirim['galat']);
                }
            } catch (Throwable $t) {
                flash_set('gagal', 'Gagal menyiapkan kode verifikasi.');
            }
            header('Location: profil.php');
            exit;
        }

        /* ---------- 3b. Aktifkan 2FA dengan kode ---------- */
        case '2fa_aktifkan': {
            $kode = trim((string) ($_POST['kode'] ?? ''));
            $hasil = otp_verifikasi($conn, $uid, $kode);

            if (!$hasil['ok']) {
                $galat['kode'] = (string) $hasil['pesan'];
            } else {
                user_security_set_2fa($conn, $uid, true);
                unset($_SESSION['profil_2fa_pending']);

                $kodeCadangan = backup_codes_buat($conn, $uid);
                $_SESSION['kode_cadangan_baru'] = $kodeCadangan;

                catat_audit($conn, 'aktifkan_2fa', 'user_security', $uid, 'Verifikasi dua faktor diaktifkan', null, null);

                flash_set('sukses', 'Verifikasi dua faktor berhasil diaktifkan. Simpan kode cadangan Anda!');
                header('Location: profil.php');
                exit;
            }
            break;
        }

        /* ---------- 3c. Nonaktifkan 2FA ---------- */
        case '2fa_matikan': {
            $sandi = (string) ($_POST['sandi'] ?? '');

            if (!password_verify($sandi, $sandiHash)) {
                $galat['sandi'] = 'Kata sandi tidak sesuai.';
            } else {
                user_security_set_2fa($conn, $uid, false);
                $conn->query('DELETE FROM backup_codes WHERE user_id = ' . $uid);

                catat_audit($conn, 'matikan_2fa', 'user_security', $uid, 'Verifikasi dua faktor dimatikan', null, null);

                flash_set('info', 'Verifikasi dua faktor telah dinonaktifkan.');
                header('Location: profil.php');
                exit;
            }
            break;
        }

        /* ---------- 3d. Buat ulang kode cadangan ---------- */
        case '2fa_kode_cadangan': {
            $sandi = (string) ($_POST['sandi'] ?? '');

            if (!password_verify($sandi, $sandiHash)) {
                $galat['sandi'] = 'Kata sandi tidak sesuai.';
            } else {
                $_SESSION['kode_cadangan_baru'] = backup_codes_buat($conn, $uid);
                catat_audit($conn, 'buat_kode_cadangan', 'backup_codes', $uid, 'Kode cadangan dibuat ulang', null, null);
                flash_set('sukses', 'Kode cadangan baru dibuat. Kode lama tidak berlaku lagi.');
                header('Location: profil.php');
                exit;
            }
            break;
        }

        /* ---------- 4. Cabut sesi ---------- */
        case 'cabut_sesi': {
            $idSesi = filter_input(INPUT_POST, 'sesi_id', FILTER_VALIDATE_INT);
            if ($idSesi) {
                $stmt = $conn->prepare(
                    'UPDATE sesi_aktif SET dicabut = 1 WHERE id = ? AND user_id = ?'
                );
                if ($stmt) {
                    $stmt->bind_param('ii', $idSesi, $uid);
                    $stmt->execute();
                    $stmt->close();
                    catat_audit($conn, 'cabut_sesi', 'sesi_aktif', $idSesi, 'Sesi perangkat dicabut', null, null);
                }
            }
            flash_set('sukses', 'Sesi perangkat telah dicabut.');
            header('Location: profil.php');
            exit;
        }

        case 'cabut_semua': {
            sesi_cabut($conn, $uid);
            sesi_daftar($conn, $uid);
            catat_audit($conn, 'cabut_semua_sesi', 'sesi_aktif', $uid, 'Semua sesi lain dicabut', null, null);
            flash_set('sukses', 'Semua sesi lain telah dicabut.');
            header('Location: profil.php');
            exit;
        }

        /* ---------- 5. (Admin) setel ulang kata sandi pengguna ---------- */
        case 'reset_sandi_pengguna': {
            if ($role !== 'admin') {
                $galat['umum'] = 'Hanya admin yang dapat melakukan tindakan ini.';
                break;
            }

            $idTarget = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $sandiBaru = (string) ($_POST['sandi_pengguna'] ?? '');

            $target = $idTarget ? user_dari_db($conn, (int) $idTarget) : null;

            if ($target === null) {
                $galat['umum'] = 'Pengguna tidak ditemukan.';
            } else {
                $g = password_validasi($sandiBaru, [
                    'email' => (string) $target['email'],
                    'nama'  => (string) $target['nama'],
                ]);
                if ($g !== null) {
                    $galat['sandi_pengguna'] = $g;
                } else {
                    $hash = password_hash($sandiBaru, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                    if ($stmt) {
                        $tid = (int) $target['id'];
                        $stmt->bind_param('si', $hash, $tid);
                        $stmt->execute();
                        $stmt->close();

                        password_simpan_riwayat($conn, $tid, $hash);

                        // Paksa pengguna tersebut masuk ulang di semua perangkat
                        sesi_cabut($conn, $tid);

                        catat_audit(
                            $conn, 'reset_sandi_pengguna', 'users', $tid,
                            'Admin menyetel ulang kata sandi ' . $target['nama'], null, null
                        );

                        flash_set('sukses', 'Kata sandi ' . $target['nama'] . ' berhasil disetel ulang. Sesi pengguna tersebut dicabut.');
                        header('Location: profil.php');
                        exit;
                    }
                    $galat['umum'] = 'Gagal menyetel ulang kata sandi.';
                }
            }
            break;
        }

        /* ---------- 6. (Admin) ubah status akun pengguna ---------- */
        case 'ubah_status_pengguna': {
            if ($role !== 'admin') {
                $galat['umum'] = 'Hanya admin yang dapat melakukan tindakan ini.';
                break;
            }

            $idTarget = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $statusBaru = (string) ($_POST['status_baru'] ?? '');

            if (!in_array($statusBaru, ['aktif', 'nonaktif'], true)) {
                $galat['umum'] = 'Status tidak valid.';
                break;
            }
            if ($idTarget && (int) $idTarget === $uid) {
                $galat['umum'] = 'Anda tidak dapat mengubah status akun Anda sendiri.';
                break;
            }

            $target = $idTarget ? user_dari_db($conn, (int) $idTarget) : null;
            if ($target === null) {
                $galat['umum'] = 'Pengguna tidak ditemukan.';
                break;
            }

            $tid = (int) $target['id'];
            $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $statusBaru, $tid);
                $stmt->execute();
                $stmt->close();

                if ($statusBaru === 'nonaktif') {
                    sesi_cabut($conn, $tid);   // keluarkan dari semua perangkat
                }

                catat_audit(
                    $conn, 'ubah_status_pengguna', 'users', $tid,
                    'Admin mengubah status ' . $target['nama'] . ' menjadi ' . $statusBaru,
                    ['status' => (string) ($target['status'] ?? '')],
                    ['status' => $statusBaru]
                );

                flash_set('sukses', 'Status akun ' . $target['nama'] . ' kini: ' . $statusBaru . '.');
                header('Location: profil.php');
                exit;
            }
            $galat['umum'] = 'Gagal mengubah status akun.';
            break;
        }

        /* ---------- 7. (Admin) verifikasi akun manual ---------- */
        case 'verifikasi_pengguna': {
            if ($role !== 'admin') {
                $galat['umum'] = 'Hanya admin yang dapat melakukan tindakan ini.';
                break;
            }

            $idTarget = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
            $target = $idTarget ? user_dari_db($conn, (int) $idTarget) : null;
            if ($target === null) {
                $galat['umum'] = 'Pengguna tidak ditemukan.';
                break;
            }

            $tid = (int) $target['id'];
            $stmt = $conn->prepare(
                "UPDATE users SET status = 'aktif', email_verified_at = NOW() WHERE id = ?"
            );
            if ($stmt) {
                $stmt->bind_param('i', $tid);
                $stmt->execute();
                $stmt->close();

                catat_audit(
                    $conn, 'verifikasi_pengguna', 'users', $tid,
                    'Admin memverifikasi akun ' . $target['nama'], null, ['status' => 'aktif']
                );

                flash_set('sukses', 'Akun ' . $target['nama'] . ' berhasil diverifikasi & diaktifkan.');
                header('Location: profil.php');
                exit;
            }
            $galat['umum'] = 'Gagal memverifikasi akun.';
            break;
        }

        default:
            $galat['umum'] = 'Tindakan tidak dikenal.';
    }
}

/* ================================================================
 * Data tampilan
 * ============================================================= */

$dua_faktor = user_2fa_aktif($conn, $uid);

$kodeCadanganBaru = $_SESSION['kode_cadangan_baru'] ?? null;
unset($_SESSION['kode_cadangan_baru']);

$jumlahKodeCadangan = 0;
if ($dua_faktor) {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM backup_codes WHERE user_id = ? AND dipakai = 0');
    if ($stmt) {
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->bind_result($jumlahKodeCadangan);
        $stmt->fetch();
        $stmt->close();
    }
}

// Sesi aktif
$sesi = [];
$stmt = $conn->prepare(
    'SELECT id, sesi_hash, ip_address, user_agent, dicabut, dibuat_pada, terakhir_aktif
     FROM sesi_aktif WHERE user_id = ? AND dicabut = 0
     ORDER BY terakhir_aktif DESC'
);
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($b = $res->fetch_assoc()) {
        $sesi[] = $b;
    }
    $stmt->close();
}
$hashSesiIni = hash('sha256', session_id());

// Daftar pengguna (admin)
$daftarPengguna = [];
if ($role === 'admin') {
    $res = $conn->query('SELECT id, nama, email, role, divisi, telepon, status FROM users ORDER BY role, nama');
    if ($res) {
        while ($b = $res->fetch_assoc()) {
            $daftarPengguna[] = $b;
        }
    }
}

$logKeamanan = [];
$stmt = $conn->prepare(
    "SELECT aksi, ringkasan, ip_address, dibuat_pada
     FROM audit_log
     WHERE user_id = ? AND aksi IN
       ('login','logout','login_2fa_diminta','login_2fa_berhasil','login_2fa_gagal',
        'ganti_sandi','aktifkan_2fa','matikan_2fa','cabut_sesi','cabut_semua_sesi')
     ORDER BY id DESC LIMIT 8"
);
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($b = $res->fetch_assoc()) {
        $logKeamanan[] = $b;
    }
    $stmt->close();
}

/* label aksi audit */
function label_aksi_audit(string $aksi): string
{
    return match ($aksi) {
        'login'                => 'Masuk',
        'logout'               => 'Keluar',
        'login_2fa_diminta'    => 'Kode 2FA diminta',
        'login_2fa_berhasil'   => '2FA berhasil',
        'login_2fa_gagal'      => '2FA gagal',
        'ganti_sandi'          => 'Kata sandi diubah',
        'aktifkan_2fa'         => '2FA diaktifkan',
        'matikan_2fa'          => '2FA dinonaktifkan',
        'cabut_sesi'           => 'Sesi dicabut',
        'cabut_semua_sesi'     => 'Semua sesi dicabut',
        'perbarui_profil'      => 'Identitas diperbarui',
        'verifikasi_email'     => 'Verifikasi surel',
        'reset_sandi'          => 'Kata sandi direset',
        'ubah_status_pengguna' => 'Status pengguna diubah',
        'verifikasi_pengguna'  => 'Pengguna diverifikasi',
        'komentar_laporan'     => 'Komentar laporan',
        default                => ucfirst(str_replace('_', ' ', $aksi)),
    };
}

$pending2fa = (int) ($_SESSION['profil_2fa_pending'] ?? 0) === 1;

mulai_halaman([
    'judul' => 'Akun & Keamanan',
    'crumb' => 'Pengaturan',
    'aktif' => 'profil',
]);
?>

<?php if (isset($galat['umum'])): ?>
    <div class="alert alert-gagal" role="alert">
        <span class="ikon">✕</span><span><?= e($galat['umum']) ?></span>
    </div>
<?php endif; ?>

<div class="grid grid-2">

    <!-- ============ KOLOM KIRI ============ -->
    <div>
        <!-- Identitas -->
        <section class="kartu">
            <div class="kartu-kepala">
                <?= avatar_tampil((string) $user['nama'], $role, (string) ($user['foto_profil'] ?? ''), 'avatar avatar-besar') ?>
                <div class="flex-1">
                    <h3 style="margin-bottom:2px"><?= e((string) $user['nama']) ?></h3>
                    <span class="text-redup text-kecil"><?= e((string) $user['email']) ?>
                        · <span class="badge badge-navy badge-tanpa-titik"><?= e(ucfirst($role)) ?></span>
                    </span>
                </div>
            </div>
            <div class="kartu-badan">
                <form method="POST" action="profil.php" novalidate>
                    <?= csrf_field('profil_perbarui_profil') ?>
                    <input type="hidden" name="aksi" value="perbarui_profil">

                    <div class="grup-form">
                        <label for="nama">Nama lengkap</label>
                        <input id="nama" name="nama" type="text" maxlength="100"
                               value="<?= e((string) $user['nama']) ?>"
                               class="<?= isset($galat['nama']) ? 'galat' : '' ?>" required>
                        <?php if (isset($galat['nama'])): ?>
                            <span class="pesan-galat"><?= e($galat['nama']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="grup-form">
                        <label for="divisi">Divisi / unit kerja</label>
                        <input id="divisi" name="divisi" type="text" maxlength="100"
                               value="<?= e((string) ($user['divisi'] ?? '')) ?>"
                               class="<?= isset($galat['divisi']) ? 'galat' : '' ?>"
                               placeholder="Contoh: Teknologi Informasi">
                        <?php if (isset($galat['divisi'])): ?>
                            <span class="pesan-galat"><?= e($galat['divisi']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="grup-form">
                        <label for="telepon">Nomor telepon (WhatsApp)</label>
                        <input id="telepon" name="telepon" type="tel" maxlength="30"
                               value="<?= e((string) ($user['telepon'] ?? '')) ?>"
                               class="<?= isset($galat['telepon']) ? 'galat' : '' ?>"
                               placeholder="08xxxxxxxxxx">
                        <span class="bantuan">Opsional. Dipakai untuk notifikasi WhatsApp saat laporan diperiksa/direvisi.</span>
                        <?php if (isset($galat['telepon'])): ?>
                            <span class="pesan-galat"><?= e($galat['telepon']) ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="tombol tombol-utama" type="submit">Simpan Perubahan</button>
                </form>
            </div>
        </section>

        <!-- Foto profil -->
        <section class="kartu" style="margin-top:18px">
            <div class="kartu-kepala">
                <div class="flex-1">
                    <h3><?= ikon_sp('gambar') ?> Foto Profil</h3>
                    <span class="text-redup text-kecil">
                        Opsional. Format JPG, PNG, atau WEBP maksimal 2 MB.
                    </span>
                </div>
            </div>
            <div class="kartu-badan">
                <?php $fotoAktif = foto_profil_url((string) ($user['foto_profil'] ?? '')); ?>
                <div class="d-flex gap-2 flex-wrap" style="align-items:center;margin-bottom:14px">
                    <?= avatar_tampil((string) $user['nama'], $role, (string) ($user['foto_profil'] ?? ''), 'avatar avatar-besar') ?>
                    <div class="flex-1">
                        <strong><?= e((string) $user['nama']) ?></strong><br>
                        <span class="text-redup text-kecil">
                            <?= $fotoAktif !== null
                                ? 'Foto profil aktif.'
                                : 'Belum ada foto. Avatar memakai inisial nama.' ?>
                        </span>
                    </div>
                </div>

                <?php if (isset($galat['foto'])): ?>
                    <div class="alert alert-gagal" role="alert">
                        <span class="ikon">&#10005;</span><span><?= e($galat['foto']) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="profil.php" enctype="multipart/form-data" style="margin-bottom:10px">
                    <?= csrf_field('profil_unggah_foto') ?>
                    <input type="hidden" name="aksi" value="unggah_foto">
                    <div class="grup-form">
                        <label for="foto">Pilih berkas foto</label>
                        <input id="foto" name="foto" type="file"
                               accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <button class="tombol tombol-utama" type="submit">Unggah Foto</button>
                </form>

                <?php if ($fotoAktif !== null): ?>
                    <form method="POST" action="profil.php"
                          onsubmit="return confirm('Hapus foto profil? Tindakan ini tidak dapat dibatalkan.');">
                        <?= csrf_field('profil_hapus_foto') ?>
                        <input type="hidden" name="aksi" value="hapus_foto">
                        <button class="tombol tombol-bahaya" type="submit">Hapus Foto</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <!-- Ganti kata sandi -->
        <section class="kartu">
            <div class="kartu-kepala">
                <h3><?= ikon_sp('kunci') ?> Ganti Kata Sandi</h3>
            </div>
            <div class="kartu-badan">
                <form method="POST" action="profil.php" novalidate>
                    <?= csrf_field('profil_ganti_sandi') ?>
                    <input type="hidden" name="aksi" value="ganti_sandi">

                    <div class="grup-form">
                        <label for="sandi_lama">Kata sandi saat ini</label>
                        <div class="field-input">
                            <input id="sandi_lama" name="sandi_lama" type="password"
                                   class="<?= isset($galat['sandi_lama']) ? 'galat' : '' ?>"
                                   autocomplete="current-password" required>
                            <button class="mata" type="button" data-mata="sandi_lama" aria-label="Tampilkan"><?= ikon_sp('mata') ?></button>
                        </div>
                        <?php if (isset($galat['sandi_lama'])): ?>
                            <span class="pesan-galat"><?= e($galat['sandi_lama']) ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="grup-form">
                        <label for="sandi_baru">Kata sandi baru</label>
                        <div class="field-input">
                            <input id="sandi_baru" name="sandi_baru" type="password"
                                   class="<?= isset($galat['sandi_baru']) ? 'galat' : '' ?>"
                                   data-sandi="ukuranSandi"
                                   minlength="<?= PASSWORD_MIN ?>" maxlength="200"
                                   autocomplete="new-password" required>
                            <button class="mata" type="button" data-mata="sandi_baru" aria-label="Tampilkan"><?= ikon_sp('mata') ?></button>
                        </div>
                        <div class="kekuatan-sandi" id="ukuranSandi"><span></span><span></span><span></span><span></span></div>
                        <div class="label-kekuatan"></div>
                        <span class="bantuan">
                            Minimal <?= PASSWORD_MIN ?> karakter, memuat minimal 3 dari:
                            huruf kecil, huruf besar, angka, simbol. Tidak boleh sama dengan
                            <?= PASSWORD_RIWAYAT ?> kata sandi terakhir.
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
                            <button class="mata" type="button" data-mata="sandi_ulangi" aria-label="Tampilkan"><?= ikon_sp('mata') ?></button>
                        </div>
                        <?php if (isset($galat['sandi_ulangi'])): ?>
                            <span class="pesan-galat"><?= e($galat['sandi_ulangi']) ?></span>
                        <?php endif; ?>
                    </div>

                    <button class="tombol tombol-utama" type="submit">Ubah Kata Sandi</button>
                    <p class="text-redup text-kecil mt-2 mb-0">
                        Semua sesi di perangkat lain akan otomatis dicabut setelah kata sandi berubah.
                    </p>
                </form>
            </div>
        </section>
    </div>

    <!-- ============ KOLOM KANAN ============ -->
    <div>
        <!-- Keamanan: 2FA -->
        <section class="kartu">
            <div class="kartu-kepala">
                <h3><?= ikon_sp('perisai') ?> Verifikasi Dua Faktor</h3>
                <span class="badge <?= $dua_faktor ? 'badge-hijau' : 'badge-merah' ?>" style="margin-left:auto">
                    <?= $dua_faktor ? 'Aktif' : 'Nonaktif' ?>
                </span>
            </div>
            <div class="kartu-badan">
                <?php if ($kodeCadanganBaru !== null): ?>
                    <div class="alert alert-peringatan">
                        <span class="ikon">!</span>
                        <span>
                            <strong>Simpan kode cadangan ini sekarang</strong>
                            Kode hanya ditampilkan sekali. Gunakan bila surel tidak dapat diakses.
                        </span>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-bottom:16px">
                        <?php foreach ($kodeCadanganBaru as $kc): ?>
                            <code class="text-mono"
                                  style="background:var(--abu-100);padding:8px 10px;border-radius:6px;text-align:center;letter-spacing:1px">
                                <?= e((string) $kc) ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!$dua_faktor): ?>
                    <p class="text-redup">
                        Setelah diaktifkan, setiap kali masuk Anda akan diminta kode 6 angka
                        yang dikirim ke <strong><?= e((string) $user['email']) ?></strong>.
                    </p>

                    <?php if ($pending2fa): ?>
                        <form method="POST" action="profil.php" novalidate>
                            <?= csrf_field('profil_2fa_aktifkan') ?>
                            <input type="hidden" name="aksi" value="2fa_aktifkan">
                            <div class="grup-form">
                                <label for="kode">Kode verifikasi (6 angka)</label>
                                <input id="kode" name="kode" type="text" inputmode="numeric"
                                       maxlength="6" pattern="[0-9]{6}" placeholder="000000"
                                       class="text-mono <?= isset($galat['kode']) ? 'galat' : '' ?>"
                                       style="letter-spacing:6px;text-align:center;font-size:20px" required autofocus>
                                <?php if (isset($galat['kode'])): ?>
                                    <span class="pesan-galat"><?= e($galat['kode']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <button class="tombol tombol-sukses" type="submit">Verifikasi &amp; Aktifkan</button>
                                <button class="tombol tombol-garis" type="submit"
                                        formaction="profil.php" name="aksi" value="2fa_minta">Kirim Ulang Kode</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <form method="POST" action="profil.php">
                            <?= csrf_field('profil_2fa_minta') ?>
                            <input type="hidden" name="aksi" value="2fa_minta">
                            <button class="tombol tombol-sukses" type="submit">Aktifkan Verifikasi Dua Faktor</button>
                        </form>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="rincian mb-3">
                        <div class="baris">
                            <span class="kunci">Status</span>
                            <span class="nilai"><span class="badge badge-hijau badge-tanpa-titik">Terlindungi</span></span>
                        </div>
                        <div class="baris">
                            <span class="kunci">Kode cadangan tersisa</span>
                            <span class="nilai"><?= (int) $jumlahKodeCadangan ?> dari 10</span>
                        </div>
                    </div>

                    <?php if ($jumlahKodeCadangan === 0): ?>
                        <div class="alert alert-peringatan">
                            <span class="ikon">!</span>
                            <span>Kode cadangan Anda habis. Buat ulang agar tetap bisa masuk bila surel bermasalah.</span>
                        </div>
                    <?php endif; ?>

                    <div class="grid grid-2" style="gap:10px">
                        <form method="POST" action="profil.php" novalidate>
                            <?= csrf_field('profil_2fa_kode_cadangan') ?>
                            <input type="hidden" name="aksi" value="2fa_kode_cadangan">
                            <div class="grup-form" style="margin-bottom:8px">
                                <label for="sandi_cadangan" class="text-kecil">Kata sandi (untuk buat ulang kode)</label>
                                <input id="sandi_cadangan" name="sandi" type="password" required
                                       autocomplete="current-password">
                            </div>
                            <button class="tombol tombol-garis tombol-lebar" type="submit">Buat Ulang Kode Cadangan</button>
                        </form>

                        <form method="POST" action="profil.php" novalidate
                              data-konfirmasi="Nonaktifkan verifikasi dua faktor?">
                            <?= csrf_field('profil_2fa_matikan') ?>
                            <input type="hidden" name="aksi" value="2fa_matikan">
                            <div class="grup-form" style="margin-bottom:8px">
                                <label for="sandi_matikan" class="text-kecil">Kata sandi (untuk menonaktifkan)</label>
                                <input id="sandi_matikan" name="sandi" type="password" required
                                       autocomplete="current-password">
                            </div>
                            <button class="tombol tombol-bahaya tombol-lebar" type="submit">Nonaktifkan 2FA</button>
                        </form>
                    </div>

                    <?php if (isset($galat['sandi'])): ?>
                        <span class="pesan-galat mt-2"><?= e($galat['sandi']) ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Sesi aktif -->
        <section class="kartu">
            <div class="kartu-kepala">
                <div class="flex-1">
                    <h3><?= ikon_sp('komputer') ?> Sesi Aktif</h3>
                    <span class="text-redup text-kecil">Perangkat yang sedang masuk dengan akun Anda.</span>
                </div>
                <?php if (count($sesi) > 1): ?>
                    <form method="POST" action="profil.php"
                          data-konfirmasi="Cabut semua sesi lain?">
                        <?= csrf_field('profil_cabut_semua') ?>
                        <input type="hidden" name="aksi" value="cabut_semua">
                        <button class="tombol tombol-bahaya tombol-kecil" type="submit">Cabut Sesi Lain</button>
                    </form>
                <?php endif; ?>
            </div>
            <div class="kartu-badan padat">
                <div class="bungkus-tabel">
                    <table class="tabel tabel-padat">
                        <thead>
                            <tr>
                                <th>Perangkat</th>
                                <th>Alamat IP</th>
                                <th>Terakhir Aktif</th>
                                <th class="text-kanan">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($sesi === []): ?>
                            <tr><td colspan="4" class="tabel-kosong">Tidak ada sesi aktif tercatat.</td></tr>
                        <?php else: foreach ($sesi as $s): ?>
                            <?php $iniSesi = hash_equals($hashSesiIni, (string) $s['sesi_hash']); ?>
                            <tr>
                                <td>
                                    <span class="potong-2 text-kecil"><?= e(potong((string) ($s['user_agent'] ?? 'Perangkat tidak dikenal'), 60)) ?></span>
                                    <?php if ($iniSesi): ?>
                                        <span class="badge badge-hijau badge-tanpa-titik" style="margin-left:4px">Perangkat ini</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-mono text-kecil"><?= e((string) ($s['ip_address'] ?? '-')) ?></td>
                                <td class="text-kecil text-redup"><?= e(tanggal_id((string) $s['terakhir_aktif'], 'd M Y H:i')) ?></td>
                                <td class="text-kanan">
                                    <?php if (!$iniSesi): ?>
                                        <form method="POST" action="profil.php" style="display:inline">
                                            <?= csrf_field('profil_cabut_sesi') ?>
                                            <input type="hidden" name="aksi" value="cabut_sesi">
                                            <input type="hidden" name="sesi_id" value="<?= (int) $s['id'] ?>">
                                            <button class="tombol tombol-bahaya tombol-kecil" type="submit">Cabut</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-redup text-kecil">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- Log keamanan -->
        <section class="kartu">
            <div class="kartu-kepala"><h3><?= ikon_sp('jam') ?> Aktivitas Keamanan Terakhir</h3></div>
            <div class="kartu-badan padat">
                <div class="bungkus-tabel">
                    <table class="tabel tabel-padat">
                        <tbody>
                        <?php if ($logKeamanan === []): ?>
                            <tr><td class="tabel-kosong">Belum ada aktivitas keamanan tercatat.</td></tr>
                        <?php else: foreach ($logKeamanan as $l): ?>
                            <tr>
                                <td>
                                    <strong class="text-kecil"><?= e(label_aksi_audit((string) $l['aksi'])) ?></strong><br>
                                    <span class="text-redup text-kecil"><?= e((string) ($l['ringkasan'] ?? '')) ?></span>
                                </td>
                                <td class="text-kanan text-kecil text-redup" style="white-space:nowrap">
                                    <?= e(tanggal_id((string) $l['dibuat_pada'], 'd M Y H:i')) ?><br>
                                    <span class="text-mono"><?= e((string) ($l['ip_address'] ?? '-')) ?></span>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>
</div>

<?php if ($role === 'admin'): ?>
    <!-- ============ ADMIN: setel ulang kata sandi pengguna ============ -->
    <section class="kartu">
        <div class="kartu-kepala">
            <h3><?= ikon_sp('grup') ?> Administrasi Pengguna</h3>
            <span class="text-redup text-kecil" style="margin-left:auto">
                <?= count($daftarPengguna) ?> pengguna terdaftar
            </span>
            <a class="tombol tombol-garis tombol-kecil" style="margin-left:12px"
               href="profil.php?ekspor=pengguna">Ekspor CSV</a>
        </div>
        <div class="kartu-badan padat">
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Email</th>
                            <th>Peran</th>
                            <th>Status</th>
                            <th class="text-kanan">Tindakan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($daftarPengguna as $p): ?>
                        <?php
                        $pStatus = (string) ($p['status'] ?? 'aktif');
                        $pKelas = match ($pStatus) {
                            'aktif'    => 'badge-hijau',
                            'menunggu' => 'badge-kuning',
                            default    => 'badge-merah',
                        };
                        $pLabel = match ($pStatus) {
                            'aktif'    => 'Aktif',
                            'menunggu' => 'Menunggu verifikasi',
                            default    => 'Nonaktif',
                        };
                        ?>
                        <tr>
                            <td>
                                <span class="avatar avatar-kecil <?= avatar_kelas((string) $p['role']) ?>"
                                      style="display:inline-grid;vertical-align:middle;margin-right:6px">
                                    <?= e(inisial((string) $p['nama'])) ?>
                                </span>
                                <?= e((string) $p['nama']) ?>
                                <?php if ((string) ($p['divisi'] ?? '') !== ''): ?>
                                    <br><span class="text-redup text-kecil"><?= e((string) $p['divisi']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-kecil text-redup">
                                <?= e((string) $p['email']) ?>
                                <?php if ((string) ($p['telepon'] ?? '') !== ''): ?>
                                    <br><span class="text-mono"><?= e((string) $p['telepon']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-navy badge-tanpa-titik"><?= e(ucfirst((string) $p['role'])) ?></span></td>
                            <td>
                                <span class="badge <?= $pKelas ?> badge-tanpa-titik"><?= e($pLabel) ?></span>
                            </td>
                            <td class="text-kanan">
                                <div class="d-flex gap-2 justify-end" style="flex-wrap:wrap">
                                    <?php if ($pStatus !== 'aktif'): ?>
                                        <form method="POST" action="profil.php"
                                              data-konfirmasi="Verifikasi & aktifkan akun ini?">
                                            <?= csrf_field('profil_verifikasi_pengguna') ?>
                                            <input type="hidden" name="aksi" value="verifikasi_pengguna">
                                            <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                                            <button class="tombol tombol-kecil tombol-utama" type="submit">
                                                &#10004; Verifikasi
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ((int) $p['id'] !== $uid): ?>
                                        <form method="POST" action="profil.php"
                                              data-konfirmasi="<?= $pStatus === 'aktif'
                                                  ? 'Nonaktifkan akun ini?'
                                                  : 'Aktifkan kembali akun ini?' ?>">
                                            <?= csrf_field('profil_ubah_status_pengguna') ?>
                                            <input type="hidden" name="aksi" value="ubah_status_pengguna">
                                            <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                                            <input type="hidden" name="status_baru"
                                                   value="<?= $pStatus === 'aktif' ? 'nonaktif' : 'aktif' ?>">
                                            <button class="tombol tombol-kecil" type="submit">
                                                <?= $pStatus === 'aktif' ? 'Nonaktifkan' : 'Aktifkan' ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="POST" action="profil.php" class="d-flex gap-2"
                                          data-konfirmasi="Setel ulang kata sandi pengguna ini?">
                                        <?= csrf_field('profil_reset_sandi_pengguna') ?>
                                        <input type="hidden" name="aksi" value="reset_sandi_pengguna">
                                        <input type="hidden" name="user_id" value="<?= (int) $p['id'] ?>">
                                        <input name="sandi_pengguna" type="text" required
                                               minlength="<?= PASSWORD_MIN ?>" maxlength="200"
                                               placeholder="Sandi baru" style="width:150px"
                                               autocomplete="off">
                                        <button class="tombol tombol-kecil" type="submit">Setel</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kartu-kaki">
            <span class="text-redup text-kecil">
                Kata sandi yang disetel wajib memenuhi kebijakan keamanan. Sesi pengguna yang direset
                atau dinonaktifkan akan langsung dicabut. Anda tidak dapat menonaktifkan akun sendiri.
            </span>
        </div>
    </section>
<?php endif; ?>

<?php akhiri_halaman(); ?>
