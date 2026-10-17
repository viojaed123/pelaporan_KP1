<?php
/**
 * includes/security.php
 * Penguatan keamanan login:
 *   1. Rate limit + penguncian akun (percobaan berulang)
 *   2. Kebijakan kata sandi (panjang, riwayat, daftar umum)
 *   3. 2FA OTP sekali pakai lewat surat + kode cadangan
 *   4. Pelacakan sesi aktif per perangkat
 *   5. Pencatat percobaan login (audit)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/mailer.php';

/* ===============================================================
 * 1. RATE LIMIT & LOCKOUT
 * ============================================================= */

/** Kata sandi umum yang tidak boleh dipakai. */
const KATA_SANDI_UMUM = [
    'password', 'password1', 'password123', '123456', '12345678', '123456789',
    'qwerty', 'qwerty123', 'abc123', 'iloveyou', 'admin', 'admin123',
    'letmein', 'welcome', 'monkey', 'dragon', 'sunshine', 'princess',
    'football', 'baseball', 'master', 'shadow', 'changeme', 'secret',
    'pelaporan', 'laporan123', 'pertamina', 'pertamina123', 'phr123',
    'rahasia', 'sandiku', 'katasantiku', 'woipro123',
];

if (!function_exists('login_kunci_sisa')) {
    /**
     * Sisa detik penguncian untuk sebuah kunci ('ip:...' atau 'email:...').
     * @return int detik tersisa; 0 bila tidak terkunci
     */
    function login_kunci_sisa(mysqli $conn, string $kunci): int
    {
        $stmt = $conn->prepare(
            'SELECT terkunci_sampai FROM login_locks
             WHERE kunci = ? AND terkunci_sampai IS NOT NULL
             LIMIT 1'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('s', $kunci);
        $stmt->execute();
        $stmt->bind_result($sampai);
        $ada = $stmt->fetch();
        $stmt->close();

        if (!$ada || $sampai === null) {
            return 0;
        }
        $sisa = strtotime($sampai) - time();
        return $sisa > 0 ? $sisa : 0;
    }
}

if (!function_exists('login_terkunci')) {
    /**
     * Periksa apakah kunci terkunci (IP atau email).
     * @return array{terkunci: bool, sisa: int, pesan: ?string}
     */
    function login_terkunci(mysqli $conn, string $ip, string $email): array
    {
        foreach (['ip:' . $ip, 'email:' . strtolower($email)] as $kunci) {
            $sisa = login_kunci_sisa($conn, $kunci);
            if ($sisa > 0) {
                $menit = (int) ceil($sisa / 60);
                $pesan = $menit >= 60
                    ? 'Terlalu banyak percobaan gagal. Coba lagi dalam '
                        . ceil($menit / 60) . ' jam.'
                    : 'Terlalu banyak percobaan gagal. Coba lagi dalam '
                        . $menit . ' menit.';
                return ['terkunci' => true, 'sisa' => $sisa, 'pesan' => $pesan];
            }
        }
        return ['terkunci' => false, 'sisa' => 0, 'pesan' => null];
    }
}

if (!function_exists('login_catat_kegagalan')) {
    /**
     * Catat satu kegagalan; kunci kunci bila melewati ambang.
     * Diterapkan untuk kunci IP **dan** email agar "spraying" antar-akun
     * dari satu IP maupun "brute force" satu akun dari banyak IP dua-duanya
     * tetap terkena batas.
     */
    function login_catat_kegagalan(mysqli $conn, string $ip, string $email, ?int $userId = null): void
    {
        $email = strtolower(trim($email));

        $kunciList = ['ip:' . $ip];
        if ($email !== '') {
            $kunciList[] = 'email:' . $email;
        }

        foreach ($kunciList as $kunci) {
            try {
                // Naikkan penghitung
                $stmt = $conn->prepare(
                    'INSERT INTO login_locks (kunci, gagal, percobaan_pertama)
                     VALUES (?, 1, NOW())
                     ON DUPLICATE KEY UPDATE gagal = gagal + 1'
                );
                if (!$stmt) {
                    continue;
                }
                $stmt->bind_param('s', $kunci);
                $stmt->execute();
                $stmt->close();

                // Ambil jumlah kegagalan
                $stmt = $conn->prepare('SELECT gagal FROM login_locks WHERE kunci = ?');
                $stmt->bind_param('s', $kunci);
                $stmt->execute();
                $stmt->bind_result($gagal);
                $stmt->fetch();
                $stmt->close();

                $gagal = (int) $gagal;

                if ($gagal >= LOGIN_MAKS_GAGAL) {
                    $lebih = $gagal - LOGIN_MAKS_GAGAL;
                    $indeks = min($lebih, count(LOGIN_BACKOFF_DETIK) - 1);
                    $durasi = LOGIN_BACKOFF_DETIK[$indeks];

                    $sampai = date('Y-m-d H:i:s', time() + $durasi);
                    $stmt = $conn->prepare(
                        'UPDATE login_locks SET terkunci_sampai = ? WHERE kunci = ?'
                    );
                    if ($stmt) {
                        $stmt->bind_param('ss', $sampai, $kunci);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            } catch (Throwable $t) {
                error_log('lockout: ' . $t->getMessage());
            }
        }

        // Rekam ke tabel riwayat percobaan
        try {
            $alasan = 'gagal';
            $stmt = $conn->prepare(
                'INSERT INTO login_attempts (email, user_id, ip_address, user_agent, berhasil, alasan)
                 VALUES (?, ?, ?, ?, 0, ?)'
            );
            if ($stmt) {
                $emailDb = $email !== '' ? $email : null;
                $ua = user_agent_klien();
                $stmt->bind_param('sisss', $emailDb, $userId, $ip, $ua, $alasan);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $t) {
            error_log('login_attempts: ' . $t->getMessage());
        }
    }
}

if (!function_exists('login_reset_kunci')) {
    /** Bersihkan kunci & penghitung setelah login berhasil. */
    function login_reset_kunci(mysqli $conn, string $ip, string $email): void
    {
        $email = strtolower(trim($email));
        foreach (['ip:' . $ip, 'email:' . $email] as $kunci) {
            $stmt = $conn->prepare('DELETE FROM login_locks WHERE kunci = ?');
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $kunci);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('login_catat_sukses')) {
    function login_catat_sukses(mysqli $conn, string $ip, string $email, int $userId): void
    {
        login_reset_kunci($conn, $ip, $email);

        try {
            $alasan = 'sukses';
            $emailDb = strtolower(trim($email));
            $ua = user_agent_klien();
            $stmt = $conn->prepare(
                'INSERT INTO login_attempts (email, user_id, ip_address, user_agent, berhasil, alasan)
                 VALUES (?, ?, ?, ?, 1, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('sisss', $emailDb, $userId, $ip, $ua, $alasan);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $t) {
            error_log('login_attempts: ' . $t->getMessage());
        }

        // Bersihkan percobaan lama agar tabel tidak membesar tanpa batas
        try {
            $conn->query(
                'DELETE FROM login_attempts WHERE dibuat_pada < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 500'
            );
        } catch (Throwable $t) {
            // diabaikan
        }
    }
}

if (!function_exists('pendaftaran_catat')) {
    /**
     * Catat percobaan pendaftaran tanpa mengunci akun siapa pun.
     * Sumber hitungan pembatas laju pendaftaran per IP.
     */
    function pendaftaran_catat(mysqli $conn, string $ip, string $email, bool $sukses = false): void
    {
        try {
            $alasan = $sukses ? 'daftar-sukses' : 'daftar-gagal';
            $emailDb = strtolower(trim($email));
            if ($emailDb === '') {
                $emailDb = null;
            }
            $ua = user_agent_klien();
            $berhasil = $sukses ? 1 : 0;

            $stmt = $conn->prepare(
                'INSERT INTO login_attempts (email, user_id, ip_address, user_agent, berhasil, alasan)
                 VALUES (?, NULL, ?, ?, ?, ?)'
            );
            if ($stmt) {
                $stmt->bind_param('sisis', $emailDb, $ip, $ua, $berhasil, $alasan);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $t) {
            error_log('pendaftaran_catat: ' . $t->getMessage());
        }
    }
}

if (!function_exists('login_uji_rate_limit')) {
    /**
     * Cek gabungan rate limit berdasarkan riwayat (bukan hanya tabel kunci),
     * untuk menangkap pola yang lolos dari penghitung.
     * @return int jumlah kegagalan dalam jendela
     */
    function login_hitung_gagal(mysqli $conn, string $ip, string $email): int
    {
        $email = strtolower(trim($email));
        $batas = date('Y-m-d H:i:s', time() - LOGIN_JENDELA_DETIK);

        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE berhasil = 0
               AND dibuat_pada >= ?
               AND (ip_address = ? OR (? <> "" AND email = ?))'
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('ssss', $batas, $ip, $email, $email);
        $stmt->execute();
        $stmt->bind_result($jumlah);
        $stmt->fetch();
        $stmt->close();

        return (int) $jumlah;
    }
}

/* ===============================================================
 * 2. KEBIJAKAN KATA SANDI
 * ============================================================= */

if (!function_exists('password_validasi')) {
    /**
     * @param array $konteks ['email'=>..., 'nama'=>...] untuk menolak
     *                        kata sandi yang memuat identitas pengguna
     * @return string|null pesan kesalahan, atau null bila lolos
     */
    function password_validasi(string $sandi, array $konteks = []): ?string
    {
        if (strlen($sandi) < PASSWORD_MIN) {
            return 'Kata sandi minimal ' . PASSWORD_MIN . ' karakter.';
        }
        if (strlen($sandi) > 200) {
            return 'Kata sandi maksimal 200 karakter.';
        }

        // 1) Daftar kata sandi umum — diperiksa lebih dulu agar pesan lebih
        //    akurat dan cek ini benar-benar tercapai (bukan tertutup aturan keragaman).
        if (in_array(strtolower($sandi), KATA_SANDI_UMUM, true)) {
            return 'Kata sandi terlalu umum dan mudah ditebak.';
        }

        // 2) Satu karakter berulang seluruhnya
        if ($sandi !== '' && preg_match('/^(.)\1+$/', $sandi) === 1) {
            return 'Kata sandi tidak boleh terdiri dari satu karakter berulang.';
        }

        // 3) Tolak yang memuat identitas
        $email = strtolower(trim((string) ($konteks['email'] ?? '')));
        if ($email !== '') {
            $lokal = explode('@', $email)[0];
            if ($lokal !== '' && strlen($lokal) >= 4 && str_contains(strtolower($sandi), $lokal)) {
                return 'Kata sandi tidak boleh memuat alamat surel Anda.';
            }
        }
        $nama = strtolower(trim((string) ($konteks['nama'] ?? '')));
        if ($nama !== '' && strlen($nama) >= 5 && str_contains(strtolower($sandi), $nama)) {
            return 'Kata sandi tidak boleh memuat nama Anda.';
        }

        return null;
    }
}

if (!function_exists('password_simpan_riwayat')) {
    /** Simpan hash sandi terbaru ke riwayat (panggil setelah ganti sandi). */
    function password_simpan_riwayat(mysqli $conn, int $userId, string $hash): void
    {
        try {
            $stmt = $conn->prepare(
                'INSERT INTO password_history (user_id, password_hash) VALUES (?, ?)'
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('is', $userId, $hash);
            $stmt->execute();
            $stmt->close();

            // Batasi hanya N terakhir
            $stmt = $conn->prepare(
                'DELETE FROM password_history
                 WHERE user_id = ?
                   AND id NOT (
                     SELECT id FROM (
                       SELECT id FROM password_history
                       WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) PASSWORD_RIWAYAT . '
                     ) x
                   )'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $userId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        } catch (Throwable $t) {
            error_log('password_history: ' . $t->getMessage());
        }
    }
}

if (!function_exists('password_terpakai_sebelumnya')) {
    /** @return string|null pesan bila sandi pernah dipakai sebelumnya */
    function password_terpakai_sebelumnya(mysqli $conn, int $userId, string $sandiBaru): ?string
    {
        $stmt = $conn->prepare(
            'SELECT password_hash FROM password_history
             WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int) PASSWORD_RIWAYAT
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($baris = $res ? $res->fetch_assoc() : null) {
            if (password_verify($sandiBaru, (string) $baris['password_hash'])) {
                $stmt->close();
                return 'Kata sandi tidak boleh sama dengan '
                    . PASSWORD_RIWAYAT . ' kata sandi terakhir Anda.';
            }
        }
        $stmt->close();
        return null;
    }
}

/* ===============================================================
 * 3. 2FA OTP
 * ============================================================= */

if (!function_exists('otp_buat')) {
    /**
     * Buat kode OTP 6 digit untuk pengguna (menonaktifkan OTP lama sejenis).
     * @param string $jenis 'login' (2FA) atau 'reset_sandi'
     * @return string kode mentah — hanya dikirim lewat surat, tidak pernah disimpan
     */
    function otp_buat(mysqli $conn, int $userId, string $tujuan, string $jenis = 'login'): string
    {
        $jenis = in_array($jenis, ['login', 'reset_sandi'], true) ? $jenis : 'login';

        $kode = (string) random_int(10 ** (OTP_PANJANG - 1), (10 ** OTP_PANJANG) - 1);
        $hash = password_hash($kode, PASSWORD_DEFAULT);
        $kadaluarsa = date('Y-m-d H:i:s', time() + OTP_DETIK);

        // Batalkan OTP lama yang belum dipakai (jenis yang sama saja)
        $stmt = $conn->prepare('UPDATE user_otp SET dipakai = 1 WHERE user_id = ? AND jenis = ? AND dipakai = 0');
        if ($stmt) {
            $stmt->bind_param('is', $userId, $jenis);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare(
            'INSERT INTO user_otp (user_id, jenis, kode_hash, tujuan, kedaluwarsa)
             VALUES (?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Gagal menyiapkan kode verifikasi.');
        }
        $stmt->bind_param('issss', $userId, $jenis, $hash, $tujuan, $kadaluarsa);
        $stmt->execute();
        $stmt->close();

        return $kode;
    }
}

if (!function_exists('otp_kirim')) {
    /**
     * Kirim kode OTP via surat elektronik.
     * @return array{ok: bool, galat: ?string, mode: string}
     */
    function otp_kirim(string $ke, string $kode, string $nama): array
    {
        $isiTeks = "Halo {$nama},\n\nKode verifikasi dua faktor Anda: {$kode}\n"
            . "Berlaku selama " . (int) (OTP_DETIK / 60) . " menit.\n\n"
            . "Jika Anda tidak meminta kode ini, abaikan pesan ini dan segera ganti kata sandi.\n";

        $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">'
            . e($nama) . ', gunakan kode berikut untuk menyelesaikan proses masuk:</p>'
            . '<div style="font-family:Consolas,Menlo,monospace;font-size:30px;letter-spacing:8px;'
            . 'font-weight:700;color:' . e(WARNA_BIRU_TUA) . ';background:#F1F5F9;'
            . 'padding:16px 0;text-align:center;border-radius:8px;margin-bottom:14px;">'
            . e($kode) . '</div>'
            . '<p style="margin:0 0 6px;color:#64748b;font-size:13px;">Berlaku selama '
            . (int) (OTP_DETIK / 60) . ' menit. Jangan bagikan kode ini kepada siapa pun.</p>';

        $html = template_surat(
            'Kode Verifikasi Dua Faktor',
            $badan,
            'Pesan ini dikirim otomatis oleh ' . e(APP_NAMA) . '. Jangan balas pesan ini.'
        );

        return kirim_email($ke, 'Kode Verifikasi — ' . APP_NAMA, $html, $isiTeks);
    }
}

if (!function_exists('otp_verifikasi')) {
    /**
     * Verifikasi kode OTP.
     * @param string $jenis 'login' (2FA) atau 'reset_sandi'
     * @return array{ok: bool, pesan: string, sisa: ?int}
     */
    function otp_verifikasi(mysqli $conn, int $userId, string $kode, string $jenis = 'login'): array
    {
        $jenis = in_array($jenis, ['login', 'reset_sandi'], true) ? $jenis : 'login';
        $kode = trim($kode);
        if ($kode === '' || strlen($kode) !== OTP_PANJANG || !ctype_digit($kode)) {
            return ['ok' => false, 'pesan' => 'Kode harus ' . OTP_PANJANG . ' angka.', 'sisa' => null];
        }

        $stmt = $conn->prepare(
            'SELECT id, kode_hash, percobaan, dipakai, kedaluwarsa
             FROM user_otp
             WHERE user_id = ? AND jenis = ? AND dipakai = 0
             ORDER BY id DESC LIMIT 1'
        );
        if (!$stmt) {
            return ['ok' => false, 'pesan' => 'Verifikasi gagal. Coba lagi.', 'sisa' => null];
        }
        $stmt->bind_param('is', $userId, $jenis);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($baris === null) {
            return ['ok' => false, 'pesan' => 'Tidak ada kode aktif. Minta kode baru.', 'sisa' => null];
        }

        if (strtotime((string) $baris['kedaluwarsa']) < time()) {
            return ['ok' => false, 'pesan' => 'Kode sudah kedaluwarsa. Minta kode baru.', 'sisa' => null];
        }

        if ((int) $baris['percobaan'] >= OTP_MAKS_COBA) {
            return [
                'ok'   => false,
                'pesan' => 'Terlalu banyak percobaan. Minta kode baru.',
                'sisa' => null,
            ];
        }

        // Naikkan penghitung percobaan terlebih dahulu (mencegah race)
        $stmt = $conn->prepare('UPDATE user_otp SET percobaan = percobaan + 1 WHERE id = ?');
        if ($stmt) {
            $id = (int) $baris['id'];
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        if (!password_verify($kode, (string) $baris['kode_hash'])) {
            $sisa = OTP_MAKS_COBA - ((int) $baris['percobaan'] + 1);
            $sisa = max($sisa, 0);
            return [
                'ok'    => false,
                'pesan' => 'Kode tidak sesuai. Sisa percobaan: ' . $sisa . '.',
                'sisa'  => $sisa,
            ];
        }

        // Tandai terpakai (sekali pakai)
        $id = (int) $baris['id'];
        $stmt = $conn->prepare('UPDATE user_otp SET dipakai = 1 WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
        }

        return ['ok' => true, 'pesan' => 'Verifikasi berhasil.', 'sisa' => null];
    }
}

if (!function_exists('backup_codes_buat')) {
    /**
     * Buat 10 kode cadangan sekali tampil (untuk cadangan bila surel bermasalah).
     * @return string[] kode mentah
     */
    function backup_codes_buat(mysqli $conn, int $userId): array
    {
        // Hapus yang lama
        $stmt = $conn->prepare('DELETE FROM backup_codes WHERE user_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }

        $kode = [];
        $stmt = $conn->prepare(
            'INSERT INTO backup_codes (user_id, kode_hash) VALUES (?, ?)'
        );

        for ($i = 0; $i < 10; $i++) {
            // Format: XXXXX-XXXXX (lebih mudah diketik)
            $mentah = strtoupper(
                bin2hex(random_bytes(3)) . '-' . substr(bin2hex(random_bytes(3)), 0, 4)
            );
            $hash = password_hash($mentah, PASSWORD_DEFAULT);

            if ($stmt) {
                $stmt->bind_param('is', $userId, $hash);
                $stmt->execute();
            }
            $kode[] = $mentah;
        }

        if ($stmt) {
            $stmt->close();
        }

        return $kode;
    }
}

if (!function_exists('backup_codes_pakai')) {
    /** Pakai satu kode cadangan. @return bool */
    function backup_codes_pakai(mysqli $conn, int $userId, string $kode): bool
    {
        $kode = strtoupper(trim($kode));
        if ($kode === '') {
            return false;
        }

        $stmt = $conn->prepare(
            'SELECT id, kode_hash FROM backup_codes
             WHERE user_id = ? AND dipakai = 0'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($baris = $res ? $res->fetch_assoc() : null) {
            if (password_verify($kode, (string) $baris['kode_hash'])) {
                $stmt->close();
                $id = (int) $baris['id'];
                $up = $conn->prepare(
                    'UPDATE backup_codes SET dipakai = 1, dipakai_pada = NOW() WHERE id = ?'
                );
                if ($up) {
                    $up->bind_param('i', $id);
                    $up->execute();
                    $up->close();
                }
                return true;
            }
        }

        $stmt->close();
        return false;
    }
}

if (!function_exists('user_2fa_aktif')) {
    function user_2fa_aktif(mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare('SELECT dua_faktor_aktif FROM user_security WHERE user_id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($aktif);
        $ada = $stmt->fetch();
        $stmt->close();
        return $ada && (int) $aktif === 1;
    }
}

if (!function_exists('user_security_pastikan')) {
    /** Pastikan baris user_security ada (dipanggil saat login pertama). */
    function user_security_pastikan(mysqli $conn, int $userId): void
    {
        $stmt = $conn->prepare(
            'INSERT IGNORE INTO user_security (user_id) VALUES (?)'
        );
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('user_security_set_2fa')) {
    function user_security_set_2fa(mysqli $conn, int $userId, bool $aktif): void
    {
        user_security_pastikan($conn, $userId);
        $v = $aktif ? 1 : 0;
        $stmt = $conn->prepare(
            'UPDATE user_security SET dua_faktor_aktif = ?, diperbarui_pada = NOW() WHERE user_id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('ii', $v, $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

/* ===============================================================
 * 4. SESI AKTIF PER PERANGKAT
 * ============================================================= */

if (!function_exists('sesi_daftar')) {
    /** Catat sesi perangkat & terapkan batas sesi bersamaan. */
    function sesi_daftar(mysqli $conn, int $userId): void
    {
        try {
            $token = session_id() !== '' ? hash('sha256', session_id()) : bin2hex(random_bytes(32));
            $ip = ip_klien();
            $ua = user_agent_klien();

            $stmt = $conn->prepare(
                'INSERT INTO sesi_aktif (user_id, sesi_hash, ip_address, user_agent)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE dicabut = 0, terakhir_aktif = NOW()'
            );
            if ($stmt) {
                $stmt->bind_param('isss', $userId, $token, $ip, $ua);
                $stmt->execute();
                $stmt->close();
            }

            // Cabut sesi lama melebihi batas (sisakan yang terbaru)
            $stmt = $conn->prepare(
                'UPDATE sesi_aktif SET dicabut = 1
                 WHERE user_id = ? AND dicabut = 0 AND sesi_hash <> ?
                 ORDER BY terakhir_aktif ASC
                 LIMIT ' . max(0, SESI_MAKS_AKTIF - 1)
            );
            if ($stmt) {
                $stmt->bind_param('is', $userId, $token);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare(
                'UPDATE sesi_aktif SET terakhir_aktif = NOW() WHERE sesi_hash = ?'
            );
            if ($stmt) {
                $stmt->bind_param('s', $token);
                $stmt->execute();
                $stmt->close();
            }

            // Bersihkan sesi basi
            $conn->query(
                'DELETE FROM sesi_aktif WHERE terakhir_aktif < DATE_SUB(NOW(), INTERVAL 1 DAY)'
            );
        } catch (Throwable $t) {
            error_log('sesi_aktif: ' . $t->getMessage());
        }
    }
}

if (!function_exists('sesi_cabut')) {
    function sesi_cabut(mysqli $conn, int $userId): void
    {
        $stmt = $conn->prepare('UPDATE sesi_aktif SET dicabut = 1 WHERE user_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('sesi_cabut_sesi_ini')) {
    function sesi_cabut_sesi_ini(mysqli $conn): void
    {
        if (session_id() === '') {
            return;
        }
        $token = hash('sha256', session_id());
        $stmt = $conn->prepare('UPDATE sesi_aktif SET dicabut = 1 WHERE sesi_hash = ?');
        if ($stmt) {
            $stmt->bind_param('s', $token);
            $stmt->execute();
            $stmt->close();
        }
    }
}
