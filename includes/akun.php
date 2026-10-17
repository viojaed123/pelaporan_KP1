<?php
/**
 * includes/akun.php
 * Token satu-pakai untuk verifikasi surel & atur ulang kata sandi,
 * beserta pengiriman surelnya.
 *
 * Token disimpan dalam bentuk hash SHA-256; nilai mentah hanya dikirim
 * lewat surel dan tidak pernah disimpan.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

if (!function_exists('token_buat')) {
    /**
     * Buat token baru untuk pengguna. Token lama dengan jenis sama yang
     * belum dipakai langsung dibatalkan.
     *
     * @param string $jenis 'verifikasi_email' | 'reset_sandi'
     * @return string token mentah (kirim ke pengguna, jangan disimpan)
     */
    function token_buat(mysqli $conn, int $userId, string $jenis, int $masaDetik): string
    {
        if (!in_array($jenis, ['verifikasi_email', 'reset_sandi'], true)) {
            throw new InvalidArgumentException('Jenis token tidak dikenal.');
        }

        $mentah = bin2hex(random_bytes(32));
        $hash   = hash('sha256', $mentah);
        $sampai = date('Y-m-d H:i:s', time() + max(60, $masaDetik));
        $ip     = ip_klien();

        // Batalkan token lama yang belum dipakai
        $stmt = $conn->prepare(
            'UPDATE user_tokens SET dipakai = 1, dipakai_pada = NOW()
             WHERE user_id = ? AND jenis = ? AND dipakai = 0'
        );
        if ($stmt) {
            $stmt->bind_param('is', $userId, $jenis);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare(
            'INSERT INTO user_tokens (user_id, jenis, token_hash, kedaluwarsa, ip_address)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan token.');
        }
        $stmt->bind_param('issss', $userId, $jenis, $hash, $sampai, $ip);
        $stmt->execute();
        $stmt->close();

        return $mentah;
    }
}

if (!function_exists('token_ambil')) {
    /**
     * Ambil token valid (belum dipakai & belum kedaluwarsa).
     *
     * @return array{id:int, user_id:int}|null
     */
    function token_ambil(mysqli $conn, string $jenis, string $mentah): ?array
    {
        $mentah = trim($mentah);
        if ($mentah === '' || preg_match('/^[a-f0-9]{64}$/', $mentah) !== 1) {
            return null;
        }

        $hash = hash('sha256', $mentah);
        $stmt = $conn->prepare(
            'SELECT id, user_id FROM user_tokens
             WHERE token_hash = ? AND jenis = ? AND dipakai = 0 AND kedaluwarsa >= NOW()
             LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $hash, $jenis);
        $stmt->execute();
        $res   = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$baris) {
            return null;
        }
        return ['id' => (int) $baris['id'], 'user_id' => (int) $baris['user_id']];
    }
}

if (!function_exists('token_pakai')) {
    /** Tandai token sebagai terpakai (sekali pakai). */
    function token_pakai(mysqli $conn, int $tokenId): void
    {
        $stmt = $conn->prepare(
            'UPDATE user_tokens SET dipakai = 1, dipakai_pada = NOW()
             WHERE id = ? AND dipakai = 0'
        );
        if ($stmt) {
            $stmt->bind_param('i', $tokenId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('token_bersihkan')) {
    /** Hapus token basi (kedaluwarsa/terpakai lebih dari 7 hari). */
    function token_bersihkan(mysqli $conn): void
    {
        try {
            $conn->query(
                'DELETE FROM user_tokens
                 WHERE (dipakai = 1 OR kedaluwarsa < NOW())
                   AND dibuat_pada < DATE_SUB(NOW(), INTERVAL 7 DAY)'
            );
        } catch (Throwable $t) {
            // diabaikan
        }
    }
}

if (!function_exists('basis_url')) {
    /** Basis URL absolut aplikasi, mis. http://localhost/pelaporan */
    function basis_url(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $skema = $https ? 'https' : 'http';
        $host  = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $dir   = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return $skema . '://' . $host . $dir;
    }
}

if (!function_exists('tautan_token')) {
    /** Tautan absolut berisi token, mis. .../verifikasi_email.php?token=abc */
    function tautan_token(string $halaman, string $mentah): string
    {
        return basis_url() . '/' . ltrim($halaman, '/') . '?token=' . urlencode($mentah);
    }
}

if (!function_exists('email_verifikasi_kirim')) {
    /** Kirim surel berisi tautan verifikasi akun. */
    function email_verifikasi_kirim(string $ke, string $nama, string $tautan): array
    {
        $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">'
            . 'Halo ' . e($nama) . ', terima kasih telah mendaftar. '
            . 'Klik tombol berikut untuk mengaktifkan akun Anda:</p>'
            . '<p style="margin:0 0 18px;text-align:center;">'
            . '<a href="' . e($tautan) . '" style="display:inline-block;background:'
            . e(WARNA_BIRU) . ';color:#FFFFFF;text-decoration:none;font-weight:700;'
            . 'padding:12px 28px;border-radius:8px;">Verifikasi Akun</a></p>'
            . '<p style="margin:0 0 6px;color:#64748b;font-size:13px;">'
            . 'Tautan berlaku ' . (int) (VERIFIKASI_EMAIL_DETIK / 3600) . ' jam. '
            . 'Bila tombol tidak berfungsi, salin tautan ini ke peramban:</p>'
            . '<p style="margin:0 0 6px;font-size:12px;word-break:break-all;">'
            . '<a href="' . e($tautan) . '">' . e($tautan) . '</a></p>';

        $html = template_surat(
            'Verifikasi Alamat Surel',
            $badan,
            'Bila Anda tidak merasa mendaftar, abaikan surel ini.'
        );
        $teks = "Halo {$nama},\n\nVerifikasi akun Anda dengan membuka tautan berikut:\n{$tautan}\n\n"
            . 'Tautan berlaku ' . (int) (VERIFIKASI_EMAIL_DETIK / 3600) . " jam.\n";

        return kirim_email($ke, 'Verifikasi Akun - ' . APP_NAMA, $html, $teks);
    }
}

if (!function_exists('akun_hitung_aksi')) {
    /**
     * Hitung berapa kali sebuah aksi akun dilakukan dari satu IP dalam jendela.
     * Sumber data: tabel login_attempts (kolom alasan).
     */
    function akun_hitung_aksi(mysqli $conn, string $ip, string $alasan, int $jendelaDetik): int
    {
        $batas = date('Y-m-d H:i:s', time() - max(1, $jendelaDetik));
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = ? AND alasan = ? AND dibuat_pada >= ?'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('sss', $ip, $alasan, $batas);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return (int) $n;
    }
}

if (!function_exists('akun_catat_aksi')) {
    /** Catat percobaan aksi akun (untuk pembatas laju). */
    function akun_catat_aksi(mysqli $conn, string $ip, ?string $email, string $alasan): void
    {
        try {
            $emailDb = $email !== null && $email !== '' ? strtolower(trim($email)) : null;
            $ua = user_agent_klien();
            $stmt = $conn->prepare(
                'INSERT INTO login_attempts (email, user_id, ip_address, user_agent, berhasil, alasan)
                 VALUES (?, NULL, ?, ?, 0, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('ssss', $emailDb, $ip, $ua, $alasan);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $t) {
            error_log('akun_catat_aksi: ' . $t->getMessage());
        }
    }
}

if (!function_exists('email_reset_sandi_kirim')) {
    /** Kirim surel berisi tautan atur ulang kata sandi. */
    function email_reset_sandi_kirim(string $ke, string $nama, string $tautan): array
    {
        $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">'
            . 'Halo ' . e($nama) . ', kami menerima permintaan atur ulang kata sandi. '
            . 'Klik tombol berikut untuk membuat kata sandi baru:</p>'
            . '<p style="margin:0 0 18px;text-align:center;">'
            . '<a href="' . e($tautan) . '" style="display:inline-block;background:'
            . e(WARNA_BIRU) . ';color:#FFFFFF;text-decoration:none;font-weight:700;'
            . 'padding:12px 28px;border-radius:8px;">Atur Ulang Kata Sandi</a></p>'
            . '<p style="margin:0 0 6px;color:#64748b;font-size:13px;">'
            . 'Tautan berlaku ' . (int) (RESET_SANDI_DETIK / 60) . ' menit. '
            . 'Bila Anda tidak meminta ini, abaikan surel ini; kata sandi tidak berubah.</p>'
            . '<p style="margin:0 0 6px;font-size:12px;word-break:break-all;">'
            . '<a href="' . e($tautan) . '">' . e($tautan) . '</a></p>';

        $html = template_surat(
            'Atur Ulang Kata Sandi',
            $badan,
            'Jangan bagikan tautan ini kepada siapa pun.'
        );
        $teks = "Halo {$nama},\n\nAtur ulang kata sandi Anda lewat tautan berikut:\n{$tautan}\n\n"
            . 'Tautan berlaku ' . (int) (RESET_SANDI_DETIK / 60) . " menit.\n";

        return kirim_email($ke, 'Atur Ulang Kata Sandi - ' . APP_NAMA, $html, $teks);
    }
}
