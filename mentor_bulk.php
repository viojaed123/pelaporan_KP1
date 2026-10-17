<?php
/**
 * mentor_bulk.php - keputusan massal (periksa / minta revisi) banyak laporan.
 *
 * Mentor & admin dapat memilih beberapa laporan pada mentor_dashboard.php lalu
 * menerapkan satu keputusan sekaligus. Setiap laporan tetap diproses lewat
 * laporan_putuskan() sehingga audit + notifikasi konsisten dengan aksi tunggal.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/notifikasi_helper.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: mentor_dashboard.php');
    exit;
}

csrf_cek_atau_matikan('mentor_bulk');

/* ---------------------------------------------------------------
 * Validasi masukan.
 * ------------------------------------------------------------- */

$keputusan = (string) ($_POST['keputusan'] ?? '');
if (!in_array($keputusan, ['diperiksa', 'perlu_revisi'], true)) {
    flash_set('gagal', 'Keputusan massal tidak valid.');
    header('Location: mentor_dashboard.php');
    exit;
}

$catatan = trim((string) ($_POST['catatan_mentor'] ?? ''));
if (strlen($catatan) > 5000) {
    flash_set('gagal', 'Catatan terlalu panjang. Maksimal 5000 karakter.');
    header('Location: mentor_dashboard.php');
    exit;
}

if ($keputusan === 'perlu_revisi' && $catatan === '') {
    flash_set('gagal', 'Catatan wajib diisi ketika meminta revisi massal.');
    header('Location: mentor_dashboard.php');
    exit;
}

$ids = $_POST['laporan_id'] ?? [];
if (!is_array($ids)) {
    $ids = [];
}

/* Bersihkan: hanya bilangan bulat positif, unik. */
$ids = array_values(array_unique(array_filter(
    array_map('intval', $ids),
    static fn (int $id): bool => $id > 0
)));

if (count($ids) === 0) {
    flash_set('info', 'Tidak ada laporan yang dipilih.');
    header('Location: mentor_dashboard.php');
    exit;
}

/* Batasi jumlah per permintaan agar tetap responsif. */
$batas = 200;
$ids   = array_slice($ids, 0, $batas);

/* ---------------------------------------------------------------
 * Proses setiap laporan.
 * ------------------------------------------------------------- */

$sukses = 0;
$lewat  = 0;
$pesanGagal = [];

foreach ($ids as $id) {
    $hasil = laporan_putuskan($conn, $id, $keputusan, $catatan);

    if ($hasil['ok']) {
        $sukses++;
    } else {
        $lewat++;
        if (count($pesanGagal) < 5) {
            $pesanGagal[] = $hasil['pesan'];
        }
    }
}

$aksiTeks = $keputusan === 'diperiksa' ? 'ditandai diperiksa' : 'dikembalikan untuk revisi';

if ($sukses > 0 && $lewat === 0) {
    flash_set('sukses', $sukses . ' laporan berhasil ' . $aksiTeks . '.');
} elseif ($sukses > 0 && $lewat > 0) {
    flash_set(
        'info',
        $sukses . ' laporan berhasil ' . $aksiTeks
        . ', ' . $lewat . ' dilewati (status sudah berubah).'
    );
} else {
    flash_set(
        'gagal',
        'Tidak ada laporan yang diproses. ' . ($pesanGagal[0] ?? 'Status mungkin sudah berubah.')
    );
}

header('Location: mentor_dashboard.php');
exit;
