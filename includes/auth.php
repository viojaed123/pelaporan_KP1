<?php
/**
 * includes/auth.php
 * Penjagaan sesi & peran (role) terpusat.
 *
 * Sebelumnya pengecekan role dibuat terpisah: sebagian file mempercayai
 * $_SESSION['role'], sebagian menanyakan ulang ke database. File ini
 * menyeragamkan keduanya.
 *
 * Pakai:
 *   require_once __DIR__ . '/includes/auth.php';
 *   wajib_login();                    // pastikan masuk
 *   wajib_role(['mentor','admin']);   // 403 bila bukan peran tersebut
 */

declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/helpers.php';

/* ---------------------------------------------------------------
 * Akses sesi
 * ------------------------------------------------------------- */

if (!function_exists('sesi_user_id')) {
    function sesi_user_id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }
}

if (!function_exists('sesi_role')) {
    function sesi_role(): string
    {
        return (string) ($_SESSION['role'] ?? '');
    }
}

if (!function_exists('sesi_nama')) {
    function sesi_nama(): string
    {
        return (string) ($_SESSION['nama'] ?? 'Pengguna');
    }
}

if (!function_exists('sudah_masuk')) {
    function sudah_masuk(): bool
    {
        return sesi_user_id() !== null;
    }
}

/* ---------------------------------------------------------------
 * Penjaga
 * ------------------------------------------------------------- */

if (!function_exists('wajib_login')) {
    function wajib_login(string $tujuan = 'login.php'): void
    {
        if (!sudah_masuk()) {
            header('Location: ' . $tujuan);
            exit;
        }
    }
}

if (!function_exists('wajib_role')) {
    /**
     * @param string[] $peran daftar peran yang diizinkan
     */
    function wajib_role(array $peran, ?string $tujuan = null, ?mysqli $conn = null): void
    {
        wajib_login();

        $role = sesi_role();

        // Untuk akses tulis, konfirmasikan peran terbaru dari database
        // agar peran yang baru saja diubah admin langsung berlaku.
        $uid = sesi_user_id();
        if ($conn instanceof mysqli && $uid !== null) {
            $stmt = $conn->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $uid);
                $stmt->execute();
                $stmt->bind_result($role_db);
                if ($stmt->fetch()) {
                    $role = (string) $role_db;
                    $_SESSION['role'] = $role;
                }
                $stmt->close();
            }
        }

        if (!in_array($role, $peran, true)) {
            if ($tujuan !== null) {
                header('Location: ' . $tujuan);
                exit;
            }
            if (!headers_sent()) {
                http_response_code(403);
            }
            exit('Anda tidak memiliki akses ke halaman ini.');
        }
    }
}

if (!function_exists('hanya_role')) {
    /** true bila sesi sekarang termasuk salah satu peran. */
    function hanya_role(array $peran): bool
    {
        return in_array(sesi_role(), $peran, true);
    }
}

/* ---------------------------------------------------------------
 * Data pengguna dari database
 * ------------------------------------------------------------- */

if (!function_exists('user_dari_db')) {
    function user_dari_db(mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare(
            'SELECT id, nama, email, role, divisi, status, telepon, foto_profil, created_at
             FROM users WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $baris ?: null;
    }
}

if (!function_exists('user_sandi_hash')) {
    /**
     * Ambil hash kata sandi seorang pengguna.
     *
     * Sengaja dipisah dari user_dari_db() agar nilai hash tidak ikut
     * beredar ke kode yang menampilkan (rendering) data pengguna.
     * Mengembalikan '' bila pengguna tidak ditemukan.
     */
    function user_sandi_hash(mysqli $conn, int $id): string
    {
        $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($hash);
        $ada = $stmt->fetch();
        $stmt->close();
        return $ada ? (string) $hash : '';
    }
}

if (!function_exists('avatar_kelas')) {
    function avatar_kelas(string $role): string
    {
        return match ($role) {
            'admin'   => 'avatar-admin',
            'mentor'  => 'avatar-mentor',
            default   => 'avatar-karyawan',
        };
    }
}

if (!function_exists('sesi_foto')) {
    /** Nama berkas foto profil pengguna aktif ('' bila belum ada). */
    function sesi_foto(): string
    {
        return (string) ($_SESSION['foto'] ?? '');
    }
}

if (!function_exists('user_foto')) {
    /** Ambil nama berkas foto profil dari basis data ('' bila tidak ada). */
    function user_foto(mysqli $conn, int $id): string
    {
        $stmt = $conn->prepare('SELECT foto_profil FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return '';
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->bind_result($foto);
        $ada = $stmt->fetch();
        $stmt->close();

        return ($ada && is_string($foto)) ? $foto : '';
    }
}

if (!function_exists('foto_profil_url')) {
    /**
     * URL relatif foto profil bila berkasnya ada, atau null.
     * Hanya nama berkas yang diterima (mencegah path traversal).
     */
    function foto_profil_url(string $foto): ?string
    {
        $foto = trim($foto);
        if ($foto === '' || basename($foto) !== $foto || str_contains($foto, '..')) {
            return null;
        }
        $berkas = dirname(__DIR__) . '/uploads/avatar/' . $foto;

        return is_file($berkas) ? 'uploads/avatar/' . rawurlencode($foto) : null;
    }
}

if (!function_exists('avatar_tampil')) {
    /**
     * Markup avatar pengguna: foto bulat bila ada, jika tidak inisial
     * berwarna berdasarkan peran. Aman untuk disisipkan ke HTML.
     *
     * @param string $kelas kelas dasar, mis. 'avatar avatar-kecil'
     */
    function avatar_tampil(
        string $nama,
        string $role,
        string $foto = '',
        string $kelas = 'avatar avatar-kecil'
    ): string {
        $url = foto_profil_url($foto);
        if ($url !== null) {
            return '<img class="' . e($kelas) . ' avatar-foto" src="' . e($url)
                . '" alt="Foto profil ' . e($nama) . '" loading="lazy">';
        }

        return '<span class="' . e($kelas) . ' ' . e(avatar_kelas($role)) . '">'
            . e(inisial($nama)) . '</span>';
    }
}

/* ---------------------------------------------------------------
 * Penuntasan login (dipanggil setelah kata sandi/2FA terverifikasi)
 * ------------------------------------------------------------- */

if (!function_exists('login_tuntaskan')) {
    /**
     * Regenerasi id sesi, isi sesi, catat audit, daftarkan perangkat,
     * lalu kirim ke halaman yang sesuai peran.
     *
     * @param array $user minimal ['id','nama','email','role']
     */
    function login_tuntaskan(mysqli $conn, array $user, string $metode = 'password'): never
    {
        $id    = (int) $user['id'];
        $nama  = (string) $user['nama'];
        $email = (string) $user['email'];
        $role  = (string) $user['role'];

        session_regenerate_id(true);

        $_SESSION['user_id'] = $id;
        $_SESSION['nama']    = $nama;
        $_SESSION['email']   = $email;
        $_SESSION['role']    = $role;
        $_SESSION['foto']    = user_foto($conn, $id);
        $_SESSION['_sesi_mulai']    = time();
        $_SESSION['_sesi_terakhir'] = time();

        // Sesi 2FA tidak boleh tersisa
        unset($_SESSION['tfa_uid'], $_SESSION['tfa_email'], $_SESSION['tfa_kirim']);

        user_security_pastikan($conn, $id);
        login_catat_sukses($conn, ip_klien(), $email, $id);
        sesi_daftar($conn, $id);

        catat_audit(
            $conn,
            'login',
            'users',
            $id,
            'Masuk sebagai ' . $role . ' via ' . $metode,
            null,
            ['metode' => $metode, 'role' => $role]
        );

        header('Location: ' . halaman_default_peran($role));
        exit;
    }
}
