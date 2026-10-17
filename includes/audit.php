<?php
/**
 * includes/audit.php
 * Riwayat perubahan data (audit trail) — "siapa mengubah apa, kapan,
 * dari nilai apa menjadi nilai apa".
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('catat_audit')) {
    /**
     * @param array|null $lama baris sebelum diubah (opsional)
     * @param array|null $baru baris sesudah diubah (opsional)
     */
    function catat_audit(
        mysqli $conn,
        string $aksi,
        ?string $tabel = null,
        ?int $barisId = null,
        ?string $ringkasan = null,
        ?array $lama = null,
        ?array $baru = null
    ): void {
        try {
            $userId = sesi_user_id();
            $ip     = ip_klien();
            $ua     = user_agent_klien();

            $dipilihLama = $lama !== null ? _audit_saring($lama) : null;
            $dipilihBaru = $baru !== null ? _audit_saring($baru) : null;

            $stmt = $conn->prepare(
                'INSERT INTO audit_log
                    (user_id, aksi, tabel_ref, baris_id, ringkasan,
                     data_lama, data_baru, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }

            $jsonLama = $dipilihLama !== null
                ? json_encode($dipilihLama, JSON_UNESCAPED_UNICODE) : null;
            $jsonBaru = $dipilihBaru !== null
                ? json_encode($dipilihBaru, JSON_UNESCAPED_UNICODE) : null;

            $stmt->bind_param(
                'ississsss',
                $userId,
                $aksi,
                $tabel,
                $barisId,
                $ringkasan,
                $jsonLama,
                $jsonBaru,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $t) {
            error_log('audit: ' . $t->getMessage());
        }
    }
}

/**
 * Buang kolom sensitif sebelum dicatat ke audit.
 */
function _audit_saring(array $baris): array
{
    $rahasia = ['password', 'kode_hash', 'password_hash', 'ingat_token_hash', 'sesi_hash'];
    foreach ($rahasia as $k) {
        unset($baris[$k]);
    }
    return $baris;
}

if (!function_exists('audit_baris')) {
    /**
     * Ambil satu baris untuk dibandingkan, lalu catat perubahannya.
     * Contoh: audit_baris($conn, 'users', 5, 'ubah_profil', 'Profil diperbarui', $sebelum);
     */
    function audit_baris(
        mysqli $conn,
        string $tabel,
        int $id,
        string $aksi,
        ?string $ringkasan = null,
        ?array $sebelum = null,
        ?array $sesudah = null
    ): void {
        $kunci = match ($tabel) {
            'users', 'laporan_harian', 'kasus', 'absensi' => 'id',
            default => 'id',
        };

        if ($sebelum === null) {
            $sebelum = _audit_ambil($conn, $tabel, $kunci, $id);
        }
        if ($sesudah === null) {
            $sesudah = _audit_ambil($conn, $tabel, $kunci, $id);
        }

        catat_audit($conn, $aksi, $tabel, $id, $ringkasan, $sebelum, $sesudah);
    }
}

function _audit_ambil(mysqli $conn, string $tabel, string $kunci, int $id): ?array
{
    // nama tabel harus identitas yang aman (dipanggil dari kode, bukan input)
    if (preg_match('/^[a-z_]+$/i', $tabel) !== 1) {
        return null;
    }
    $stmt = $conn->prepare("SELECT * FROM `{$tabel}` WHERE `{$kunci}` = ? LIMIT 1");
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
