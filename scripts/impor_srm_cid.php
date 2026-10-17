<?php
/**
 * scripts/impor_srm_cid.php
 * Impor dataset SRM & CID dari berkas Excel lewat baris perintah (CLI).
 *
 * Cara pakai:
 *   php scripts/impor_srm_cid.php "C:\path\Dummy Data.xlsx"
 *   php scripts/impor_srm_cid.php --berkas="..." --jenis=srm
 *   php scripts/impor_srm_cid.php --berkas="..." --jenis=semua
 *
 * --jenis: semua (bawaan) | srm | cid
 * Impor bersifat ganti-semua per jenis.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Skrip ini hanya dapat dijalankan lewat baris perintah (CLI).');
}

require_once __DIR__ . '/../includes/bootstrap.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    fwrite(STDERR, "impor_srm_cid: koneksi database tidak tersedia.\n");
    exit(1);
}

/** Ambil nilai argumen --nama=nilai. */
function srm_cid_arg(string $nama, array $argv): ?string
{
    foreach ($argv as $a) {
        if (str_starts_with($a, '--' . $nama . '=')) {
            return substr($a, strlen($nama) + 3);
        }
    }
    return null;
}

$berkas = (string) (srm_cid_arg('berkas', $argv) ?? '');
$jenis  = (string) (srm_cid_arg('jenis', $argv) ?? 'semua');

// Posisi: argumen pertama yang bukan opsi dianggap path berkas
if ($berkas === '') {
    foreach (array_slice($argv, 1) as $a) {
        if ($a !== '' && !str_starts_with($a, '--')) {
            $berkas = $a;
            break;
        }
    }
}

if ($berkas === '') {
    fwrite(STDERR, "Penggunaan: php scripts/impor_srm_cid.php --berkas=\"C:\\path\\data.xlsx\" [--jenis=semua|srm|cid]\n");
    exit(1);
}

if (!in_array($jenis, ['semua', 'srm', 'cid'], true)) {
    fwrite(STDERR, "--jenis harus salah satu dari: semua, srm, cid.\n");
    exit(1);
}

if (!is_file($berkas)) {
    fwrite(STDERR, "Berkas tidak ditemukan: {$berkas}\n");
    exit(1);
}

fwrite(STDOUT, "Mengimpor {$berkas} (jenis: {$jenis}) ...\n");

$hasil = srm_cid_impor($conn, $berkas, $jenis);

if (empty($hasil['ok'])) {
    fwrite(STDERR, 'Gagal: ' . (string) ($hasil['pesan'] ?? 'tidak diketahui') . "\n");
    exit(1);
}

foreach (['srm', 'cid'] as $k) {
    if (!isset($hasil[$k]) || !is_array($hasil[$k])) {
        continue;
    }
    $r = $hasil[$k];
    $status = !empty($r['ok']) ? 'OK ' : 'GAGAL';
    fwrite(STDOUT, sprintf(
        "[%s] %-3s header baris %s -> masuk=%d gagal=%d %s\n",
        $status,
        strtoupper($k),
        (string) ($r['header_baris'] ?? '-'),
        (int) ($r['masuk'] ?? 0),
        (int) ($r['gagal'] ?? 0),
        (string) ($r['pesan'] ?? '')
    ));
}

$cSrm = (int) ($conn->query('SELECT COUNT(*) AS c FROM srm_kegiatan')->fetch_assoc()['c'] ?? 0);
$cCid = (int) ($conn->query('SELECT COUNT(*) AS c FROM cid_program')->fetch_assoc()['c'] ?? 0);
fwrite(STDOUT, "Total di basis data: SRM={$cSrm}, CID={$cCid}\n");

catat_audit(
    $conn, 'impor_srm_cid', null, null,
    'Impor SRM/CID lewat CLI (' . $jenis . ') dari ' . basename($berkas) . ': SRM=' . $cSrm . ', CID=' . $cCid,
    null, null
);
