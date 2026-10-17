<?php
/**
 * tools/pemeliharaan.php — tugas pemeliharaan berkala (CLI).
 *
 * Dijalankan oleh penjadwal (mis. Windows Task Scheduler / cron):
 *
 *   php tools\pemeliharaan.php
 *   php tools\pemeliharaan.php --hari-audit=365
 *   php tools\pemeliharaan.php --hari-notifikasi=90
 *
 * Tugas:
 *   1. Hapus token akun basi (kedaluwarsa/terpakai > 7 hari).
 *   2. (opsional) Pangkas audit_log lebih tua dari N hari.
 *   3. (opsional) Pangkas notifikasi lama yang sudah dibaca.
 *   4. Laporkan ukuran tabel penting.
 *
 * Hanya dapat dijalankan dari baris perintah. Akses lewat web ditolak.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan dari baris perintah (CLI).');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/akun.php';
require_once __DIR__ . '/../koneksi.php';

/** @var mysqli $conn */

/* --- Baca argumen sederhana --nama=nilai --- */
$opsi = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z\-]+)=(.+)$/', $arg, $m) === 1) {
        $opsi[$m[1]] = $m[2];
    }
}
$hariAudit      = isset($opsi['hari-audit']) ? max(0, (int) $opsi['hari-audit']) : 0;
$hariNotifikasi = isset($opsi['hari-notifikasi']) ? max(0, (int) $opsi['hari-notifikasi']) : 0;

$waktu = date('Y-m-d H:i:s');
echo "[{$waktu}] Pemeliharaan sistem pelaporan\n";

/* --- 1. Token basi --- */
$sebelum = (int) ($conn->query('SELECT COUNT(*) AS n FROM user_tokens')->fetch_assoc()['n'] ?? 0);
token_bersihkan($conn);
$sesudah = (int) ($conn->query('SELECT COUNT(*) AS n FROM user_tokens')->fetch_assoc()['n'] ?? 0);
echo sprintf("  Token akun       : %d -> %d (dihapus %d)\n", $sebelum, $sesudah, $sebelum - $sesudah);

/* --- 2. Pangkas audit lama (opsional) --- */
if ($hariAudit > 0) {
    $stmt = $conn->prepare('DELETE FROM audit_log WHERE dibuat_pada < DATE_SUB(NOW(), INTERVAL ? DAY)');
    if ($stmt) {
        $stmt->bind_param('i', $hariAudit);
        $stmt->execute();
        echo sprintf("  Audit lama       : %d baris dihapus (> %d hari)\n", $stmt->affected_rows, $hariAudit);
        $stmt->close();
    }
} else {
    echo "  Audit lama       : dilewati (pakai --hari-audit=N untuk memangkas)\n";
}

/* --- 3. Pangkas notifikasi lama yang sudah dibaca (opsional) --- */
if ($hariNotifikasi > 0) {
    $stmt = $conn->prepare(
        'DELETE FROM notifikasi
          WHERE sudah_dibaca = 1 AND created_at < DATE_SUB(NOW(), INTERVAL ? DAY)'
    );
    if ($stmt) {
        $stmt->bind_param('i', $hariNotifikasi);
        $stmt->execute();
        echo sprintf("  Notifikasi lama  : %d baris dihapus (> %d hari)\n", $stmt->affected_rows, $hariNotifikasi);
        $stmt->close();
    }
} else {
    echo "  Notifikasi lama  : dilewati (pakai --hari-notifikasi=N untuk memangkas)\n";
}

/* --- 4. Ringkasan ukuran tabel --- */
echo "  Ringkasan tabel  :\n";
$tabel = ['users', 'laporan_harian', 'absensi', 'kasus', 'audit_log', 'notifikasi', 'user_tokens'];
foreach ($tabel as $t) {
    $n = (int) ($conn->query("SELECT COUNT(*) AS n FROM `{$t}`")->fetch_assoc()['n'] ?? 0);
    echo sprintf("    - %-16s %d baris\n", $t, $n);
}

echo "[{$waktu}] Selesai.\n";
