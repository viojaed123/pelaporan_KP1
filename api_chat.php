<?php
/**
 * api_chat.php — endpoint JSON untuk panel asisten AI.
 * Metode: POST { "pesan": "...", "session_id": 123 }
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function balas_json(array $data, int $kode = 200): never
{
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!sudah_masuk()) {
    balas_json(['ok' => false, 'galat' => 'Sesi berakhir. Silakan masuk kembali.'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    balas_json(['ok' => false, 'galat' => 'Metode tidak diizinkan.'], 405);
}

// --- CSRF (header X-CSRF-Token dari app.js) ---
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
if (!csrf_cek('global', is_string($token) ? $token : '')) {
    balas_json(['ok' => false, 'galat' => 'Token keamanan tidak valid. Muat ulang halaman.'], 403);
}

// --- Baca body JSON ---
$badan = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($badan)) {
    $badan = $_POST;
}

$pesan = trim((string) ($badan['pesan'] ?? ''));
if ($pesan === '') {
    balas_json(['ok' => false, 'galat' => 'Pesan kosong.'], 400);
}
if (mb_strlen($pesan) > 4000) {
    balas_json(['ok' => false, 'galat' => 'Pesan terlalu panjang (maks 4000 karakter).'], 400);
}

// --- Pembatas laju sederhana: 30 pesan / menit per sesi ---
$sekarang = time();
$riwayat = $_SESSION['chat_riwayat'] ?? [];
if (!is_array($riwayat)) {
    $riwayat = [];
}
$riwayat = array_values(array_filter(array_map('intval', $riwayat), fn ($t) => $t > $sekarang - 60));
if (count($riwayat) >= 30) {
    balas_json(['ok' => false, 'galat' => 'Terlalu banyak pesan. Tunggu sebentar.'], 429);
}
$riwayat[] = $sekarang;
$_SESSION['chat_riwayat'] = $riwayat;

// --- Simpan pesan pengguna + ambil riwayat ---
$uid = sesi_user_id();
$sid = (int) ($badan['session_id'] ?? 0);

// Lanjutkan sesi yang dikirim klien bila masih milik pengguna ini.
// (Dulu sesi SELALU dibuat baru tiap pesan — ingatan percakapan hilang
//  dan riwayat di asisten.php terpotong tiap satu pertukaran.)
if ($sid > 0) {
    $stmt = $conn->prepare('SELECT id FROM chat_sessions WHERE id = ? AND user_id = ?');
    if ($stmt) {
        $stmt->bind_param('ii', $sid, $uid);
        $stmt->execute();
        $ada = $stmt->get_result()->fetch_row();
        $stmt->close();
        if (!$ada) {
            $sid = 0;
        }
    } else {
        $sid = 0;
    }
}

// Percakapan pertama (atau sesi lama tidak sah) — buat sesi baru.
if ($sid <= 0) {
    $stmt = $conn->prepare(
        'INSERT INTO chat_sessions (user_id, judul) VALUES (?, ?)'
    );
    $judul = mb_substr($pesan, 0, 60, 'UTF-8');
    if ($stmt) {
        $stmt->bind_param('is', $uid, $judul);
        $stmt->execute();
        $sid = (int) $conn->insert_id;
        $stmt->close();
    }
}

if ($sid > 0) {
    $stmt = $conn->prepare(
        'INSERT INTO chat_messages (session_id, peran, isi) VALUES (?, "user", ?)'
    );
    if ($stmt) {
        $stmt->bind_param('is', $sid, $pesan);
        $stmt->execute();
        $stmt->close();
    }
}

// --- Ambil konteks laporan pengguna untuk AI ---
$konteks = [];
$batas = date('Y-m-d', strtotime('-30 days'));
$stmt = $conn->prepare(
    'SELECT COUNT(*) jumlah,
            SUM(status = "dikirim") menunggu,
            SUM(status = "diperiksa") diperiksa,
            SUM(status = "perlu_revisi") perlu_revisi,
            MAX(tanggal) terakhir
     FROM laporan_harian WHERE user_id = ? AND tanggal >= ?'
);
if ($stmt) {
    $stmt->bind_param('is', $uid, $batas);
    $stmt->execute();
    $res = $stmt->get_result();
    $baris = $res ? $res->fetch_assoc() : null;
    if ($baris) {
        $konteks['ringkas_30_hari'] = $baris;
    }
    $stmt->close();
}

// Tambah konteks absensi 30 hari terakhir
$stmt = $conn->prepare(
    'SELECT COUNT(*) total,
            SUM(status="hadir") hadir,
            SUM(status="izin") izin,
            SUM(status="sakit") sakit,
            SUM(status="alpa") alpa
     FROM absensi WHERE user_id = ? AND tanggal >= ?'
);
if ($stmt) {
    $stmt->bind_param('is', $uid, $batas);
    $stmt->execute();
    $res = $stmt->get_result();
    $baris = $res ? $res->fetch_assoc() : null;
    if ($baris) {
        $konteks['ringkas_absensi_30_hari'] = $baris;
    }
    $stmt->close();
}

// Tambah konteks kasus yang ditugaskan (untuk mentor/admin) atau dibuat
if ($role === 'mentor' || $role === 'admin') {
    $stmt = $conn->prepare("SELECT COUNT(*) total, SUM(status='terbuka') terbuka, SUM(status='dalam_proses') dalam_proses FROM kasus WHERE mentor_id = ? AND DATE(dibuat_pada) >= ?");
    if ($stmt) {
        $stmt->bind_param('is', $uid, $batas);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        if ($baris) {
            $konteks['ringkas_kasus'] = $baris;
        }
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) total, SUM(status='terbuka') terbuka, SUM(status='dalam_proses') dalam_proses FROM kasus WHERE user_id = ? AND DATE(dibuat_pada) >= ?");
    if ($stmt) {
        $stmt->bind_param('is', $uid, $batas);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        if ($baris) {
            $konteks['ringkas_kasus'] = $baris;
        }
        $stmt->close();
    }
}

// Tambah jadwal mendatang
$stmt = $conn->prepare("SELECT judul, tanggal_mulai, lokasi FROM jadwal WHERE user_id = ? AND tanggal_mulai >= CURDATE() ORDER BY tanggal_mulai ASC LIMIT 5");
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $res = $stmt->get_result();
    $jadwal = [];
    while ($row = $res->fetch_assoc()) {
        $jadwal[] = $row;
    }
    if ($jadwal) {
        $konteks['jadwal_mendatang'] = $jadwal;
    }
    $stmt->close();
}

// --- Panggil layanan AI ---
$hasil = ai_panggil('/chat', [
    'pesan'    => $pesan,
    'user_id'  => $uid,
    'role'     => sesi_role(),
    'nama'     => sesi_nama(),
    'session'  => $sid,
    'konteks'  => $konteks,
], AI_TIMEOUT_DETIK);

if (!$hasil['ok']) {
    balas_json([
        'ok'   => false,
        'balasan' => 'Layanan AI sedang tidak tersedia. Silakan coba lagi nanti. (' . ($hasil['galat'] ?? 'koneksi gagal') . ')',
        'galat' => $hasil['galat'],
    ], 503);
}

$balasan = (string) ($hasil['data']['balasan'] ?? $hasil['data']['jawaban'] ?? '');
if ($balasan === '') {
    $balasan = 'Maaf, saya belum mendapat jawaban dari model.';
}

if ($sid > 0) {
    $stmt = $conn->prepare(
        'INSERT INTO chat_messages (session_id, peran, isi) VALUES (?, "assistant", ?)'
    );
    if ($stmt) {
        $stmt->bind_param('is', $sid, $balasan);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = $conn->prepare('UPDATE chat_sessions SET diperbarui_pada = NOW() WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $stmt->close();
    }
}

$sumber = $hasil['data']['sumber'] ?? ($hasil['data']['meta']['sumber'] ?? null);

balas_json([
    'ok'       => true,
    'balasan'  => $balasan,
    'session'  => $sid,
    'sumber'   => $sumber,
]);
