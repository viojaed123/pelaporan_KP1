<?php
/**
 * status.php — Status & kesehatan sistem (khusus admin).
 *
 * Menampilkan ringkasan kondisi: PHP, basis data, penyimpanan, direktori
 * tulis, layanan AI, mode surel, dan antrean penting. Tambahkan
 * ?format=json untuk pemantauan otomatis (monitoring).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

$json = (string) ($_GET['format'] ?? '') === 'json';

/* ---------------------------------------------------------------
 * Kumpulkan pemeriksaan
 * ------------------------------------------------------------- */

/** @var array<int, array{grup:string, nama:string, nilai:string, status:string}> $cek */
$cek = [];

$tambah = static function (string $grup, string $nama, string $nilai, string $status = 'ok') use (&$cek): void {
    $cek[] = ['grup' => $grup, 'nama' => $nama, 'nilai' => $nilai, 'status' => $status];
};

/* --- PHP --- */
$tambah('PHP', 'Versi PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.1', '>=') ? 'ok' : 'peringatan');
$extPerlu = ['mysqli', 'curl', 'mbstring', 'openssl', 'json'];
$extHilang = [];
foreach ($extPerlu as $ext) {
    if (!extension_loaded($ext)) {
        $extHilang[] = $ext;
    }
}
$tambah(
    'PHP',
    'Ekstensi wajib',
    $extHilang === [] ? 'lengkap (' . implode(', ', $extPerlu) . ')' : 'HILANG: ' . implode(', ', $extHilang),
    $extHilang === [] ? 'ok' : 'gagal'
);
$tambah('PHP', 'Batas memori', (string) ini_get('memory_limit'));
$tambah('PHP', 'Batas unggah', (string) ini_get('upload_max_filesize'));
$tambah('PHP', 'Zona waktu', date_default_timezone_get());

/* --- Basis data --- */
$versiDb = '-';
$res = $conn->query('SELECT VERSION() AS v');
if ($res) {
    $b = $res->fetch_assoc();
    $versiDb = (string) ($b['v'] ?? '-');
    $res->free();
}
$tambah('Basis data', 'Koneksi', 'tersambung', 'ok');
$tambah('Basis data', 'Versi server', $versiDb);

$hitung = static function (mysqli $conn, string $tabel): int {
    if (preg_match('/^[a-z_]+$/', $tabel) !== 1) {
        return -1;
    }
    $r = $conn->query("SELECT COUNT(*) AS n FROM `{$tabel}`");
    if (!$r) {
        return -1;
    }
    $b = $r->fetch_assoc();
    $r->free();
    return (int) ($b['n'] ?? -1);
};

$jmlUsers = $hitung($conn, 'users');
$tambah('Basis data', 'Akun pengguna', (string) $jmlUsers);

$jmlMenunggu = 0;
$r = $conn->query("SELECT COUNT(*) AS n FROM users WHERE status = 'menunggu'");
if ($r) {
    $b = $r->fetch_assoc();
    $jmlMenunggu = (int) ($b['n'] ?? 0);
    $r->free();
}
$tambah(
    'Basis data',
    'Akun menunggu verifikasi',
    (string) $jmlMenunggu,
    $jmlMenunggu > 0 ? 'peringatan' : 'ok'
);

$tambah('Basis data', 'Laporan harian', (string) $hitung($conn, 'laporan_harian'));
$tambah('Basis data', 'Absensi', (string) $hitung($conn, 'absensi'));
$tambah('Basis data', 'Kasus', (string) $hitung($conn, 'kasus'));
$tambah('Basis data', 'Notifikasi', (string) $hitung($conn, 'notifikasi'));
$tambah('Basis data', 'Baris audit', (string) $hitung($conn, 'audit_log'));

/* --- Penyimpanan --- */
foreach ([
    'backups'          => dirname(__DIR__) . '/backups',
    'backups/db'       => BACKUP_DIR,
    'backups/mail-log' => MAIL_LOG_DIR,
    'uploads'          => UPLOAD_DIR,
    // Dump terjadwal disimpan DI LUAR webroot (lihat tools/backup_db.php).
    'cadangan (luar webroot)' => getenv('PHR_BACKUP_DIR') ?: 'C:\\pelaporan-cadangan',
] as $label => $dir) {
    if (!is_dir($dir)) {
        $tambah('Penyimpanan', $label, 'tidak ada', 'peringatan');
        continue;
    }
    $tambah(
        'Penyimpanan',
        $label,
        is_writable($dir) ? 'dapat ditulis' : 'TIDAK dapat ditulis',
        is_writable($dir) ? 'ok' : 'gagal'
    );
}

$df = @disk_free_space(dirname(__DIR__));
if ($df !== false) {
    $gb = $df / (1024 ** 3);
    $tambah(
        'Penyimpanan',
        'Sisa ruang disk',
        number_format($gb, 1, ',', '.') . ' GB',
        $gb < 1 ? 'peringatan' : 'ok'
    );
}

/* Cadangan terakhir */
$cadanganTerakhir = 'belum ada';
$umurCadanganJam = null;
$berkasCadangan = glob(BACKUP_DIR . '/*.sql') ?: [];
if ($berkasCadangan !== []) {
    usort($berkasCadangan, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $tsBaru = filemtime($berkasCadangan[0]);
    $cadanganTerakhir = date('d M Y H:i', (int) $tsBaru);
    $umurCadanganJam = (int) ((time() - $tsBaru) / 3600);
}
$tambah(
    'Penyimpanan',
    'Cadangan terakhir',
    $cadanganTerakhir . ($umurCadanganJam !== null ? ' (' . $umurCadanganJam . ' jam lalu)' : ''),
    ($umurCadanganJam === null || $umurCadanganJam > 48) ? 'peringatan' : 'ok'
);

/* --- Surel --- */
$modeSurel = (SMTP_HOST === '') ? 'mode pengembangan (tulis ke berkas log)' : 'SMTP (' . SMTP_HOST . ')';
$tambah('Surel', 'Mode pengiriman', $modeSurel, SMTP_HOST === '' ? 'peringatan' : 'ok');
$tambah('Surel', 'Pengirim', SMTP_FROM !== '' ? SMTP_FROM : '(belum diatur)', SMTP_FROM !== '' ? 'ok' : 'peringatan');

/* --- WhatsApp --- */
$tambah(
    'WhatsApp',
    'Gateway',
    wa_aktif() ? WA_GATEWAY : 'nonaktif',
    wa_aktif() ? 'ok' : 'peringatan'
);

/* --- Layanan AI --- */
if (AI_AKTIF) {
    $aiStatus = 'tidak terjangkau';
    $aiKode = 0;
    try {
        $ch = curl_init(rtrim(AI_BASE_URL, '/') . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => ['X-Service-Key: ' . AI_SERVICE_KEY],
        ]);
        curl_exec($ch);
        $aiKode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($aiKode >= 200 && $aiKode < 300) {
            $aiStatus = 'aktif (HTTP ' . $aiKode . ')';
        } elseif ($aiKode > 0) {
            $aiStatus = 'merespons HTTP ' . $aiKode;
        }
    } catch (Throwable $t) {
        $aiStatus = 'galat: ' . $t->getMessage();
    }
    $tambah(
        'Layanan AI',
        'Endpoint ' . AI_BASE_URL,
        $aiStatus,
        ($aiKode >= 200 && $aiKode < 300) ? 'ok' : 'peringatan'
    );
} else {
    $tambah('Layanan AI', 'Status', 'dinonaktifkan (AI_AKTIF=false)', 'info');
}

/* --- Pengingat terakhir --- */
$pengingatTerakhir = 'belum pernah';
$r = $conn->query('SELECT MAX(waktu_kirim) AS w FROM pengingat');
if ($r) {
    $b = $r->fetch_assoc();
    if (!empty($b['w'])) {
        $pengingatTerakhir = date('d M Y H:i', (int) strtotime((string) $b['w']));
    }
    $r->free();
}
$tambah('Operasional', 'Pengingat harian terakhir', $pengingatTerakhir);

/* ---------------------------------------------------------------
 * Ringkasan status keseluruhan
 * ------------------------------------------------------------- */
$jmlGagal = 0;
$jmlPeringatan = 0;
foreach ($cek as $c) {
    if ($c['status'] === 'gagal') {
        $jmlGagal++;
    } elseif ($c['status'] === 'peringatan') {
        $jmlPeringatan++;
    }
}
$keseluruhan = $jmlGagal > 0 ? 'gagal' : ($jmlPeringatan > 0 ? 'peringatan' : 'ok');

/* ---------------------------------------------------------------
 * Keluaran JSON (untuk pemantauan otomatis)
 * ------------------------------------------------------------- */
if ($json) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'waktu'      => date('c'),
        'aplikasi'   => APP_NAMA,
        'versi'      => APP_VERSI,
        'keseluruhan' => $keseluruhan,
        'gagal'      => $jmlGagal,
        'peringatan' => $jmlPeringatan,
        'pemeriksaan' => $cek,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ---------------------------------------------------------------
 * Tampilan HTML
 * ------------------------------------------------------------- */
$ikonStatus = static fn(string $s): string => match ($s) {
    'ok'        => ikon_sp('centang'),
    'peringatan' => ikon_sp('peringatan'),
    'gagal'     => ikon_sp('silang'),
    default     => ikon_sp('info'),
};
$labelStatus = static fn(string $s): string => match ($s) {
    'ok'        => 'Normal',
    'peringatan' => 'Perhatian',
    'gagal'     => 'Bermasalah',
    default     => 'Info',
};
$badgeStatus = static fn(string $s): string => match ($s) {
    'ok'        => 'badge-hijau',
    'peringatan' => 'badge-kuning',
    'gagal'     => 'badge-merah',
    default     => 'badge-navy',
};

// Kelompokkan
$grup = [];
foreach ($cek as $c) {
    $grup[$c['grup']][] = $c;
}

mulai_halaman([
    'judul' => 'Status Sistem',
    'crumb' => 'Administrasi',
    'aktif' => 'status',
    'aksi'  => '<a class="tombol tombol-kecil" href="status.php?format=json" target="_blank" rel="noopener">JSON</a>',
]);
?>

<div class="kartu">
    <div class="kartu-badan d-flex" style="align-items:center;gap:16px;flex-wrap:wrap">
        <span class="avatar avatar-besar <?= $keseluruhan === 'ok' ? 'avatar-admin' : 'avatar-mentor' ?>"
              style="font-size:22px"><?= $ikonStatus($keseluruhan) ?></span>
        <div class="flex-1">
            <h3 style="margin:0 0 2px">Kondisi sistem: <?= e($labelStatus($keseluruhan)) ?></h3>
            <span class="text-redup text-kecil">
                <?= e(APP_NAMA) ?> v<?= e(APP_VERSI) ?> &middot; diperiksa <?= e(date('d M Y H:i')) ?>
                &middot; <?= count($cek) ?> pemeriksaan
            </span>
        </div>
        <div class="d-flex gap-2" style="flex-wrap:wrap">
            <span class="badge badge-hijau badge-tanpa-titik">Normal: <?= count($cek) - $jmlGagal - $jmlPeringatan ?></span>
            <span class="badge badge-kuning badge-tanpa-titik">Perhatian: <?= $jmlPeringatan ?></span>
            <span class="badge badge-merah badge-tanpa-titik">Bermasalah: <?= $jmlGagal ?></span>
        </div>
    </div>
</div>

<?php foreach ($grup as $namaGrup => $items): ?>
    <section class="kartu">
        <div class="kartu-kepala">
            <h3><?= e($namaGrup) ?></h3>
        </div>
        <div class="kartu-badan padat">
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <tbody>
                    <?php foreach ($items as $it): ?>
                        <tr>
                            <td style="width:40%"><?= e($it['nama']) ?></td>
                            <td class="text-mono text-kecil"><?= e($it['nilai']) ?></td>
                            <td class="text-kanan" style="width:140px">
                                <span class="badge <?= $badgeStatus($it['status']) ?> badge-tanpa-titik">
                                    <?= $ikonStatus($it['status']) ?> <?= e($labelStatus($it['status'])) ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
<?php endforeach; ?>

<?php akhiri_halaman(); ?>
