<?php
/**
 * scripts/buat_admin.php
 * Membuat atau mengatur ulang akun administrator lewat baris perintah (CLI).
 *
 * Penggunaan:
 *   php scripts/buat_admin.php
 *   php scripts/buat_admin.php --email=admin@pelaporan.local --nama=Administrator
 *   php scripts/buat_admin.php --email=admin@pelaporan.local --sandi='RahasiaKu2026'
 *   php scripts/buat_admin.php --email=admin@pelaporan.local --tanpa-sandi
 *   php scripts/buat_admin.php --email=admin@pelaporan.local --sandi='...' --paksa
 *
 * Opsi:
 *   --nama=NAMA      Nama tampilan (bawaan: Administrator)
 *   --email=EMAIL    Surel akun admin (bawaan: admin@pelaporan.local)
 *   --sandi=SANDI    Kata sandi. Bila kosong dan akun BARU, sandi acak dibuat.
 *   --tanpa-sandi    Jangan ubah sandi akun yang sudah ada.
 *   --paksa          Lewati pemeriksaan kebijakan sandi (khusus operator).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Skrip ini hanya untuk baris perintah (CLI).\n");
}

require_once dirname(__DIR__) . '/koneksi.php';
require_once dirname(__DIR__) . '/includes/security.php';

/* ---------------------------------------------------------------
 * Baca opsi
 * ------------------------------------------------------------- */
$opsi = [
    'nama'        => 'Administrator',
    'email'       => 'admin@pelaporan.local',
    'sandi'       => null,
    'tanpa_sandi' => false,
    'paksa'       => false,
];

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--nama=')) {
        $opsi['nama'] = trim(substr($arg, 7));
    } elseif (str_starts_with($arg, '--email=')) {
        $opsi['email'] = trim(substr($arg, 8));
    } elseif (str_starts_with($arg, '--sandi=')) {
        $opsi['sandi'] = (string) substr($arg, 8);
    } elseif ($arg === '--tanpa-sandi') {
        $opsi['tanpa_sandi'] = true;
    } elseif ($arg === '--paksa') {
        $opsi['paksa'] = true;
    } elseif ($arg === '--bantu' || $arg === '--help' || $arg === '-h') {
        echo "Penggunaan: php scripts/buat_admin.php [opsi]\n";
        echo "  --nama=NAMA     Nama tampilan (bawaan Administrator)\n";
        echo "  --email=EMAIL   Surel admin (bawaan admin@pelaporan.local)\n";
        echo "  --sandi=SANDI   Kata sandi (akun baru tanpa ini -> sandi acak)\n";
        echo "  --tanpa-sandi   Jangan ubah sandi akun yang sudah ada\n";
        echo "  --paksa         Lewati pemeriksaan kebijakan sandi\n";
        exit(0);
    } else {
        fwrite(STDERR, "Opsi tidak dikenal: $arg\n");
        exit(1);
    }
}

$email = strtolower($opsi['email']);
$nama  = $opsi['nama'] !== '' ? $opsi['nama'] : 'Administrator';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Surel tidak sah: $email\n");
    exit(1);
}
if (mb_strlen($nama) > 100) {
    $nama = mb_substr($nama, 0, 100);
}
if (mb_strlen($email) > 150) {
    fwrite(STDERR, "Surel terlalu panjang (maks 150 karakter).\n");
    exit(1);
}

echo "== Buat / atur ulang akun administrator ==\n\n";
echo "Surel : $email\n";
echo "Nama  : $nama\n\n";

/* ---------------------------------------------------------------
 * Cari akun yang ada
 * ------------------------------------------------------------- */
$idAda = 0;
$stmt = $conn->prepare('SELECT id, role, status FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    fwrite(STDERR, "Gagal menyiapkan kueri: {$conn->error}\n");
    exit(1);
}
$stmt->bind_param('s', $email);
$stmt->execute();
$res = $stmt->get_result();
$baris = $res ? $res->fetch_assoc() : null;
$stmt->close();

if ($baris) {
    $idAda = (int) $baris['id'];
    echo "Status: akun SUDAH ADA (id=$idAda, role={$baris['role']}, status={$baris['status']}).\n";
} else {
    echo "Status: akun BELUM ADA (akan dibuat baru).\n";
}

/* ---------------------------------------------------------------
 * Tentukan kata sandi
 * ------------------------------------------------------------- */
$sandi = $opsi['sandi'];
$gantiSandi = true;

if ($sandi === null || $sandi === '') {
    if ($idAda > 0) {
        if ($opsi['tanpa_sandi']) {
            $gantiSandi = false;
            $sandi = '';
        } else {
            // Akun lama & sandi tidak diberikan -> jangan sentuh sandi.
            $gantiSandi = false;
            $sandi = '';
            echo "Catatan: sandi TIDAK diubah (tidak ada --sandi).\n";
        }
    } else {
        // Akun baru tanpa --sandi -> buat sandi acak yang kuat.
        $sandi = acak_sandi(16);
        echo "Catatan: sandi acak dibuat untuk akun baru.\n";
    }
}

if ($gantiSandi && $sandi !== '') {
    if (!$opsi['paksa']) {
        $galat = password_validasi($sandi, ['email' => $email, 'nama' => $nama]);
        if ($galat !== null) {
            fwrite(STDERR, "Kata sandi ditolak: $galat\n");
            fwrite(STDERR, "Gunakan sandi lain, atau tambahkan --paksa bila memang disengaja.\n");
            exit(1);
        }
    }
}

/* ---------------------------------------------------------------
 * Simpan
 * ------------------------------------------------------------- */
$hash = $gantiSandi && $sandi !== '' ? password_hash($sandi, PASSWORD_DEFAULT) : null;

if ($idAda > 0) {
    if ($hash !== null) {
        $stmt = $conn->prepare(
            'UPDATE users SET nama = ?, password = ?, role = \'admin\', status = \'aktif\',
                    email_verified_at = COALESCE(email_verified_at, NOW())
              WHERE id = ?'
        );
        if (!$stmt) {
            fwrite(STDERR, "Gagal menyiapkan kueri ubah: {$conn->error}\n");
            exit(1);
        }
        $stmt->bind_param('ssi', $nama, $hash, $idAda);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            fwrite(STDERR, "Gagal memperbarui akun: {$conn->error}\n");
            exit(1);
        }
        password_simpan_riwayat($conn, $idAda, $hash);
        echo "\nAkun administrator diperbarui (nama + sandi + role + status).\n";
    } else {
        $stmt = $conn->prepare(
            'UPDATE users SET nama = ?, role = \'admin\', status = \'aktif\',
                    email_verified_at = COALESCE(email_verified_at, NOW())
              WHERE id = ?'
        );
        if (!$stmt) {
            fwrite(STDERR, "Gagal menyiapkan kueri ubah: {$conn->error}\n");
            exit(1);
        }
        $stmt->bind_param('si', $nama, $idAda);
        $ok = $stmt->execute();
        $stmt->close();
        if (!$ok) {
            fwrite(STDERR, "Gagal memperbarui akun: {$conn->error}\n");
            exit(1);
        }
        echo "\nAkun administrator diperbarui (nama + role + status; sandi tidak diubah).\n";
    }
} else {
    $stmt = $conn->prepare(
        'INSERT INTO users (nama, email, password, role, status, email_verified_at)
         VALUES (?, ?, ?, \'admin\', \'aktif\', NOW())'
    );
    if (!$stmt) {
        fwrite(STDERR, "Gagal menyiapkan kueri simpan: {$conn->error}\n");
        exit(1);
    }
    $stmt->bind_param('sss', $nama, $email, $hash);
    $ok = $stmt->execute();
    $idBaru = $ok ? (int) $conn->insert_id : 0;
    $stmt->close();
    if (!$ok) {
        fwrite(STDERR, "Gagal membuat akun: {$conn->error}\n");
        exit(1);
    }
    password_simpan_riwayat($conn, $idBaru, (string) $hash);
    echo "\nAkun administrator DIBUAT (id=$idBaru).\n";
}

/* ---------------------------------------------------------------
 * Ringkasan kredensial
 * ------------------------------------------------------------- */
echo "\n--- Kredensial ---\n";
echo "Surel       : $email\n";
if ($gantiSandi && $sandi !== '') {
    echo "Kata sandi  : $sandi\n";
} else {
    echo "Kata sandi  : (tidak diubah)\n";
}
echo "Alamat masuk: /login.php  (setelah masuk diarahkan ke mentor_dashboard.php)\n";
echo "\nSimpan kredensial ini di tempat yang aman.\n";

/* ---------------------------------------------------------------
 * Utilitas
 * ------------------------------------------------------------- */
/**
 * Buat kata sandi acak dengan campuran huruf besar/kecil, angka, simbol.
 */
function acak_sandi(int $panjang = 16): string
{
    $hurufKecil = 'abcdefghijkmnopqrstuvwxyz';
    $hurufBesar = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $angka      = '23456789';
    $simbol     = '!@#$%^&*-_=+';
    $semua      = $hurufKecil . $hurufBesar . $angka . $simbol;

    $sandi = $hurufKecil[random_int(0, strlen($hurufKecil) - 1)]
           . $hurufBesar[random_int(0, strlen($hurufBesar) - 1)]
           . $angka[random_int(0, strlen($angka) - 1)]
           . $simbol[random_int(0, strlen($simbol) - 1)];

    for ($i = strlen($sandi); $i < $panjang; $i++) {
        $sandi .= $semua[random_int(0, strlen($semua) - 1)];
    }

    // Acak urutan agar 4 karakter pertama tidak selalu berpola.
    return str_shuffle($sandi);
}
