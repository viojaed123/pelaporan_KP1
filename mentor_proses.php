<?php
/**
 * mentor_proses.php — simpan keputusan pemeriksaan mentor.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran mentor/admin + CSRF per-aksi
 *   · transisi status draft/dikirim -> diperiksa / perlu_revisi
 *   · keputusan penting dicatat lewat catat_audit()
 *   · hasil dikirim lewat flash message (PRG pattern)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/notifikasi_helper.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

// Hanya menerima permintaan POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: mentor_dashboard.php');
    exit;
}

// Periksa token keamanan formulir (skema per-aksi baru).
csrf_cek_atau_matikan('periksa_laporan');

/* ---------------------------------------------------------------
 * Validasi masukan.
 * ------------------------------------------------------------- */

$laporan_id = filter_input(INPUT_POST, 'laporan_id', FILTER_VALIDATE_INT);

if (!$laporan_id || $laporan_id < 1) {
    http_response_code(400);
    exit('ID laporan tidak valid.');
}

$keputusan = (string) ($_POST['keputusan'] ?? '');

if (!in_array($keputusan, ['diperiksa', 'perlu_revisi'], true)) {
    http_response_code(400);
    exit('Keputusan mentor tidak valid.');
}

$catatan = trim((string) ($_POST['catatan_mentor'] ?? ''));

if (strlen($catatan) > 5000) {
    http_response_code(400);
    exit('Catatan terlalu panjang. Maksimal 5000 karakter.');
}

if ($keputusan === 'perlu_revisi' && $catatan === '') {
    flash_set('gagal', 'Catatan wajib diisi ketika meminta revisi laporan.');
    header('Location: mentor_detail.php?id=' . $laporan_id);
    exit;
}

/* ---------------------------------------------------------------
 * Terapkan keputusan (logika bersama dengan aksi massal).
 * ------------------------------------------------------------- */

$hasil = laporan_putuskan($conn, (int) $laporan_id, $keputusan, $catatan);

if ($hasil['ok']) {
    flash_set(
        'sukses',
        $keputusan === 'diperiksa'
            ? 'Laporan berhasil ditandai sebagai diperiksa.'
            : 'Laporan berhasil dikembalikan untuk revisi.'
    );
} else {
    if ($hasil['kode'] === 'gagal') {
        error_log('Gagal memproses laporan mentor ID: ' . $laporan_id);
    }
    flash_set(
        'gagal',
        'Laporan tidak diproses karena mungkin sudah ditangani mentor lain atau statusnya telah berubah.'
    );
}

header('Location: mentor_detail.php?id=' . $laporan_id);
exit;
