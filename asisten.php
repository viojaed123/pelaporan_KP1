<?php
/**
 * asisten.php — Asisten AI (halaman penuh).
 *
 * Menyediakan ruang percakapan luas dengan layanan AI (Python/FastAPI +
 * Google Gemini sebagai model utama, Ollama Qwen 2.5 sebagai cadangan lokal).
 * Berbeda dari panel mengapung di sudut kanan
 * bawah, halaman ini menampilkan riwayat percakapan terakhir, kartu
 * kemampuan, dan status layanan.
 *
 * Pengiriman pesan dilakukan oleh assets/js/app.js (initChatPenuh) ke
 * api_chat.php memakai token CSRF pada <meta name="csrf-token">.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid  = (int) sesi_user_id();
$nama = sesi_nama();
$role = sesi_role();

/* ----------------------------------------------------------------
 * Muat percakapan terakhir milik pengguna (bila ada)
 * ------------------------------------------------------------- */
$sesiId  = 0;
$riwayat = [];

$stmt = $conn->prepare(
    'SELECT id FROM chat_sessions WHERE user_id = ? ORDER BY diperbarui_pada DESC LIMIT 1'
);
if ($stmt) {
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->bind_result($sid);
    if ($stmt->fetch()) {
        $sesiId = (int) $sid;
    }
    $stmt->close();
}

if ($sesiId > 0) {
    $stmt = $conn->prepare(
        'SELECT peran, isi FROM chat_messages WHERE session_id = ? ORDER BY id ASC LIMIT 40'
    );
    if ($stmt) {
        $stmt->bind_param('i', $sesiId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $riwayat[] = $b;
        }
        $stmt->close();
    }
}

/* Ambil status rinci layanan (/health): model aktif & penyedia (Gemini/Ollama). */
$aiSehat  = AI_AKTIF ? ai_panggil('health', [], 6, 'GET') : ['ok' => false, 'data' => []];
$aiSiap   = (bool) ($aiSehat['ok'] ?? false);
$aiData   = is_array($aiSehat['data'] ?? null) ? $aiSehat['data'] : [];
$aiModel  = (string) ($aiData['model'] ?? '-');
$aiGemini = (bool) ($aiData['gemini'] ?? false);
$aiLabel  = $aiGemini ? 'Gemini · Google Search' : 'Ollama (lokal)';

/** Saran pertanyaan sesuai peran pengguna. */
$saran = $role === 'karyawan'
    ? [
        'Ringkas kegiatan saya minggu ini',
        'Laporan saya sudah lengkap?',
        'Apa agenda saya minggu ini?',
        'Buat narasi laporan bulanan saya',
        'Statistik kegiatan saya bulan ini',
    ]
    : [
        'Laporan siapa yang belum diperiksa?',
        'Rekap kegiatan tim minggu ini',
        'Ringkas laporan bulan ini untuk pimpinan',
        'Apa agenda saya minggu ini?',
        'Buat narasi laporan bulanan',
    ];

$kemampuan = [
    ['ikon' => 'cari', 'judul' => 'Cari & ubah catatan', 'teks' => 'Temukan kegiatan tertentu dan perbarui bila perlu.'],
    ['ikon' => 'centang', 'judul' => 'Periksa kelengkapan', 'teks' => 'Pastikan setiap laporan memuat semua bagian wajib.'],
    ['ikon' => 'grafik', 'judul' => 'Statistik & tren', 'teks' => 'Ringkasan jumlah kegiatan, tren mingguan/bulanan.'],
    ['ikon' => 'tulis', 'judul' => 'Narasi laporan', 'teks' => 'Susun narasi rapi untuk laporan mingguan/bulanan.'],
    ['ikon' => 'kalender', 'judul' => 'Jadwal & pengingat', 'teks' => 'Buat, ubah, dan ingatkan agenda kegiatan Anda.'],
    ['ikon' => 'globe', 'judul' => 'Cari di internet', 'teks' => 'Telusuri berita, peraturan, atau istilah terkini.'],
    ['ikon' => 'kirim', 'judul' => 'Kirim surel & WhatsApp', 'teks' => 'Susun pengiriman pesan (perlu persetujuan Anda).'],
    ['ikon' => 'floppy', 'judul' => 'Cadangan & pemulihan', 'teks' => 'Bantu mengelola pencadangan data kegiatan.'],
];

mulai_halaman([
    'judul'   => 'Asisten AI',
    'crumb'   => 'Lainnya',
    'aktif'   => 'asisten',
    'aksi'    => '<span class="badge ' . ($aiSiap ? 'badge-hijau' : 'badge-merah') . ' badge-tanpa-titik">'
               . ($aiSiap ? '● Layanan aktif' : '● Layanan nonaktif') . '</span>',
]);
?>

<div class="grid grid-asisten" style="grid-template-columns:minmax(0,2fr) minmax(280px,1fr);align-items:start;gap:18px">

    <!-- ============ Ruang percakapan ============ -->
    <section class="kartu kartu-asisten" style="display:flex;flex-direction:column">
        <div class="kartu-kepala">
            <span class="avatar avatar-kecil" style="background:var(--biru-600);color:#fff">AI</span>
            <div class="flex-1">
                <h3 style="margin:0;font-size:15px">Asisten Pelaporan</h3>
                <span class="text-redup text-kecil"><?= $aiSiap ? e($aiModel) . ' · ' . e($aiLabel) : 'Menunggu layanan AI' ?></span>
            </div>
        </div>

        <div class="badan" id="asistenBadan" data-sesi="<?= $sesiId ?>"
             style="overflow-y:auto;padding:18px;display:flex;flex-direction:column;gap:12px;background:var(--abu-50)">
            <?php if ($riwayat === []): ?>
                <div class="pesan-chat bot">
                    Halo <strong><?= e($nama) ?></strong>!<br><br>
                    Saya asisten laporan kegiatan harian Anda. Saya dapat membantu:
                    mencari dan mengubah catatan kegiatan, memeriksa kelengkapan laporan,
                    menyusun narasi, membuat statistik, serta merangkum mingguan/bulanan.
                    <br><br>Apa yang ingin Anda kerjakan hari ini?
                </div>
            <?php else: foreach ($riwayat as $r): ?>
                <div class="pesan-chat <?= $r['peran'] === 'user' ? 'user' : 'bot' ?>"><?= e((string) $r['isi']) ?></div>
            <?php endforeach; endif; ?>
        </div>

        <div class="saran-chat" id="asistenSaran" style="padding:12px 18px 10px">
            <?php foreach ($saran as $s): ?>
                <button type="button" data-saran="<?= e($s) ?>"><?= e($s) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="kaki" style="border-top:1px solid var(--garis);padding:12px 18px 16px;display:flex;gap:10px;align-items:flex-end">
            <textarea id="asistenInput" rows="1" placeholder="Tulis pesan… (Enter untuk kirim, Shift+Enter baris baru)"
                      aria-label="Pesan untuk asisten"
                      style="flex:1;resize:none;min-height:44px;max-height:160px"></textarea>
            <button class="tombol tombol-utama" type="button" id="asistenKirim" aria-label="Kirim">Kirim</button>
        </div>
    </section>

    <!-- ============ Kolom samping ============ -->
    <div>
        <?php if (!$aiSiap): ?>
            <div class="alert alert-peringatan">
                <span class="ikon">!</span>
                <span>
                    <strong>Layanan AI belum aktif</strong>
                    Jalankan layanan Python (<span class="text-mono">ai-service</span>) pada
                    <span class="text-mono"><?= e(AI_BASE_URL) ?></span> agar asisten dapat menjawab.
                </span>
            </div>
        <?php endif; ?>

        <section class="kartu">
            <div class="kartu-kepala"><h3>✦ Kemampuan Asisten</h3></div>
            <div class="kartu-badan">
                <div style="display:grid;gap:10px">
                    <?php foreach ($kemampuan as $k): ?>
                        <button type="button" class="kotak-catatan" data-saran="<?= e($k['judul'] . ': ' . $k['teks']) ?>"
                                style="text-align:left;cursor:pointer;border:1px solid var(--garis);background:#fff;display:flex;gap:10px;align-items:flex-start;padding:11px 12px;border-radius:var(--radius-md);width:100%">
                            <span class="kemampuan-ikon"><?= ikon_sp($k['ikon']) ?></span>
                            <span>
                                <strong class="text-kecil" style="display:block"><?= e($k['judul']) ?></strong>
                                <span class="text-redup text-kecil"><?= e($k['teks']) ?></span>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="kartu">
            <div class="kartu-kepala"><h3>ℹ Tentang</h3></div>
            <div class="kartu-badan">
                <div class="rincian">
                    <div class="baris"><span class="kunci">Model</span><span class="nilai"><?= e($aiModel) ?></span></div>
                    <div class="baris"><span class="kunci">Penyedia</span><span class="nilai"><?= e($aiLabel) ?></span></div>
                    <div class="baris"><span class="kunci">Status</span>
                        <span class="nilai">
                            <span class="badge <?= $aiSiap ? 'badge-hijau' : 'badge-merah' ?> badge-tanpa-titik">
                                <?= $aiSiap ? 'Terhubung' : 'Tidak terhubung' ?>
                            </span>
                        </span>
                    </div>
                </div>
                <p class="text-redup text-kecil mt-3 mb-0">
                    Model berjalan di Google Gemini (bila kunci aktif) dengan cadangan lokal
                    Ollama. Data aplikasi tidak dikirim ke internet kecuali saat pencarian
                    web; percakapan disimpan pada basis data dan hanya dapat diakses
                    oleh akun Anda.
                </p>
            </div>
        </section>
    </div>
</div>

<?php akhiri_halaman(['chat' => false]); ?>
