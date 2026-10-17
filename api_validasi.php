<?php
/**
 * api_validasi.php — endpoint JSON pemeriksaan kelengkapan laporan oleh AI.
 *
 * Metode: POST { "laporan": { tanggal, jam_mulai, jam_selesai, kegiatan,
 *                             deskripsi, hasil, kendala, rencana_berikutnya } }
 *
 * Keamanan (meniru api_chat.php):
 *   · hanya POST
 *   · wajib login
 *   · token CSRF aksi 'global' (meta name="csrf-token")
 *   · panjang isian dibatasi
 *   · pembatas laju per sesi
 *
 * Bila layanan AI tidak aktif, endpoint tetap membalas struktur yang sama
 * (ok=false) sehingga klien tidak perlu mengurus dua bentuk balasan.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/ai_client.php';

header('Content-Type: application/json; charset=utf-8');

function balas_json_validasi(array $data, int $kode = 200): never
{
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sudah_masuk()) {
    balas_json_validasi(['ok' => false, 'galat' => 'Sesi berakhir. Silakan masuk kembali.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    balas_json_validasi(['ok' => false, 'galat' => 'Metode tidak diizinkan.'], 405);
}

// --- CSRF (header X-CSRF-Token dari app.js) ---
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!csrf_cek('global', is_string($token) ? $token : '')) {
    balas_json_validasi(['ok' => false, 'galat' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
}

// --- Baca body JSON ---
$badan = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($badan)) {
    $badan = $_POST;
}

$mentah = $badan['laporan'] ?? $badan;
if (!is_array($mentah)) {
    balas_json_validasi(['ok' => false, 'galat' => 'Data laporan tidak valid.'], 400);
}

// --- Ambil hanya kolom yang dikenal, pangkas panjangnya ---
$bidang = ['tanggal', 'jam_mulai', 'jam_selesai', 'kegiatan', 'deskripsi', 'hasil', 'kendala', 'rencana_berikutnya'];
$laporan = [];
foreach ($bidang as $k) {
    $nilai = $mentah[$k] ?? ($k === 'rencana_berikutnya' ? ($mentah['rencana'] ?? '') : '');
    $laporan[$k] = mb_substr(trim((string) $nilai), 0, 5000, 'UTF-8');
}

// Minimal harus ada kegiatan atau deskripsi untuk diperiksa.
if ($laporan['kegiatan'] === '' && $laporan['deskripsi'] === '') {
    balas_json_validasi(['ok' => false, 'galat' => 'Isi nama kegiatan atau deskripsi terlebih dahulu.'], 400);
}

// --- Pembatas laju sederhana: 30 pemeriksaan / menit per sesi ---
$sekarang = time();
$riwayat = $_SESSION['validasi_ai_riwayat'] ?? [];
if (!is_array($riwayat)) {
    $riwayat = [];
}
$riwayat = array_values(array_filter(array_map('intval', $riwayat), fn ($t) => $t > $sekarang - 60));
if (count($riwayat) >= 30) {
    balas_json_validasi(['ok' => false, 'galat' => 'Terlalu banyak pemeriksaan. Tunggu sebentar.'], 429);
}
$riwayat[] = $sekarang;
$_SESSION['validasi_ai_riwayat'] = $riwayat;

// --- Panggil layanan AI ---
$hasil = ai_validasi($laporan);

if (!$hasil['ok']) {
    balas_json_validasi([
        'ok'    => false,
        'galat' => $hasil['galat'] ?: 'Layanan AI sedang tidak tersedia.',
        'balasan' => 'Pemeriksaan AI tidak tersedia saat ini. Laporan tetap dapat disimpan.',
    ], 503);
}

$data = is_array($hasil['data']) ? $hasil['data'] : [];
$verdict = is_array($data['verdict'] ?? null) ? $data['verdict'] : [];

balas_json_validasi([
    'ok'      => true,
    'verdict' => $verdict,
    'balasan' => (string) ($data['balasan'] ?? ''),
    'model'   => $data['model'] ?? null,
    'mode'    => $data['mode'] ?? 'model',
]);
