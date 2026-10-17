<?php
/**
 * rekap_absensi.php — rekap & ekspor absensi peserta (mentor/admin).
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga peran terpusat: wajib_role(['mentor','admin'])
 *   · layout terpusat — <style> inline 300+ baris dihapus
 *   · verifikasi CSRF untuk permintaan POST
 *   · filter tanggal, rekap per peserta, dan ekspor CSV/XLSX dipertahankan
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_role(['mentor', 'admin'], null, $conn);

$user_id = (int) sesi_user_id();

// Profil penampil (dipakai pada footer halaman).
$akun = user_dari_db($conn, $user_id);
if ($akun === null) {
    $akun = ['nama' => sesi_nama(), 'role' => sesi_role()];
}

// Semua perubahan data (bila ada) wajib membawa token CSRF.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('rekap_absensi');
}

/** Validasi tanggal YYYY-MM-DD. */
function tanggalValid(mixed $tanggal): bool
{
    if (!is_string($tanggal)) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date !== false
        && $date->format('Y-m-d') === $tanggal;
}

$tanggalAwal = $_GET['awal'] ?? date('Y-m-01');
$tanggalAkhir = $_GET['akhir'] ?? date('Y-m-d');

if (!is_string($tanggalAwal) || !tanggalValid($tanggalAwal)) {
    $tanggalAwal = date('Y-m-01');
}

if (!is_string($tanggalAkhir) || !tanggalValid($tanggalAkhir)) {
    $tanggalAkhir = date('Y-m-d');
}

if ($tanggalAwal > $tanggalAkhir) {
    [$tanggalAwal, $tanggalAkhir] = [$tanggalAkhir, $tanggalAwal];
}

// Ambil data absensi sesuai periode.
$sql = "
    SELECT
        a.id,
        a.tanggal,
        u.nama,
        u.email,
        a.jam_masuk,
        a.jam_pulang,
        a.status,
        a.keterangan,
        a.kegiatan_hari_ini,
        a.kondisi_kesehatan
    FROM absensi a
    INNER JOIN users u ON u.id = a.user_id
    WHERE a.tanggal BETWEEN ? AND ?
    ORDER BY a.tanggal ASC, u.nama ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Query rekap absensi gagal disiapkan.');
    http_response_code(500);
    exit('Terjadi kesalahan saat menyiapkan rekap absensi.');
}

$stmt->bind_param('ss', $tanggalAwal, $tanggalAkhir);
$stmt->execute();

$result = $stmt->get_result();
$dataAbsensi = [];

while ($result && ($row = $result->fetch_assoc())) {
    $dataAbsensi[] = $row;
}

$stmt->close();

// Ringkasan status berdasarkan catatan absensi pada periode terpilih.
$jumlahStatus = [
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpa' => 0
];

foreach ($dataAbsensi as $baris) {
    if (isset($jumlahStatus[$baris['status']])) {
        $jumlahStatus[$baris['status']]++;
    }
}

$totalData = count($dataAbsensi);

// Ringkasan kondisi kesehatan yang dilaporkan (swa-laporan saat absen).
$jumlahKesehatan = array_fill_keys(array_keys(daftar_kondisi_kesehatan()), 0);
foreach ($dataAbsensi as $baris) {
    $kunciKondisi = kondisi_kesehatan_valid($baris['kondisi_kesehatan'] ?? 'sehat');
    $jumlahKesehatan[$kunciKondisi] = ($jumlahKesehatan[$kunciKondisi] ?? 0) + 1;
}


/* =========================================
   REKAP ABSENSI PER PESERTA
   Mengikuti rentang tanggal yang dipilih
========================================= */

$sqlRekapPeserta = "
    SELECT
        u.id AS user_id,
        u.nama,
        u.email,
        COUNT(a.id) AS total_catatan,
        SUM(CASE WHEN a.status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN a.status = 'izin' THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN a.status = 'sakit' THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN a.status = 'alpa' THEN 1 ELSE 0 END) AS alpa
    FROM users u
    LEFT JOIN absensi a
        ON a.user_id = u.id
        AND a.tanggal BETWEEN ? AND ?
    WHERE u.role = 'karyawan'
    GROUP BY u.id, u.nama, u.email
    ORDER BY u.nama ASC
";

$stmtRekap = $conn->prepare($sqlRekapPeserta);

if (!$stmtRekap) {
    error_log('Query rekap per peserta gagal disiapkan.');
    http_response_code(500);
    exit('Terjadi kesalahan saat menyiapkan rekap per peserta.');
}

$stmtRekap->bind_param('ss', $tanggalAwal, $tanggalAkhir);
$stmtRekap->execute();

$resultRekap = $stmtRekap->get_result();
$dataRekapPeserta = [];

while ($resultRekap && ($rowRekap = $resultRekap->fetch_assoc())) {
    $dataRekapPeserta[] = $rowRekap;
}

$stmtRekap->close();


/* =========================================
   EKSPOR EXCEL ASLI (.XLSX)
========================================= */

if (($_GET['ekspor'] ?? '') === 'xlsx') {
    $autoload = __DIR__ . '/vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        exit('PhpSpreadsheet belum ditemukan. Periksa folder vendor.');
    }

    require_once $autoload;

    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Rekap Absensi');

        $sheet->setCellValue('A1', 'REKAP ABSENSI KARYAWAN');
        $sheet->setCellValue(
            'A2',
            'Periode: ' . $tanggalAwal . ' sampai ' . $tanggalAkhir
        );
        $sheet->setCellValue('A3', 'Total Catatan: ' . $totalData);

        $sheet->fromArray([
            [
                'No.',
                'Tanggal',
                'Nama Peserta',
                'Email',
                'Jam Masuk',
                'Jam Pulang',
                'Status',
                'Kondisi Kesehatan',
                'Kegiatan Hari Ini',
                'Keterangan'
            ]
        ], null, 'A5');

        $nomorBaris = 6;

        foreach ($dataAbsensi as $index => $baris) {
            $nilaiBaris = [
                (string) ($index + 1),
                (string) ($baris['tanggal'] ?? ''),
                (string) ($baris['nama'] ?? ''),
                (string) ($baris['email'] ?? ''),
                (string) ($baris['jam_masuk'] ?? ''),
                (string) ($baris['jam_pulang'] ?? ''),
                ucfirst((string) ($baris['status'] ?? '')),
                label_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? 'sehat')),
                (string) ($baris['kegiatan_hari_ini'] ?? ''),
                (string) ($baris['keterangan'] ?? '')
            ];

            foreach ($nilaiBaris as &$nilai) {
                if (preg_match('/^[\s]*[=+\-@]/u', $nilai)) {
                    $nilai = "'" . $nilai;
                }
            }
            unset($nilai);

            $sheet->fromArray(
                $nilaiBaris,
                null,
                'A' . $nomorBaris
            );

            $nomorBaris++;
        }

        $sheet->getStyle('A5:J5')->getFont()->setBold(true);

        foreach (range('A', 'J') as $kolom) {
            $sheet->getColumnDimension($kolom)->setAutoSize(true);
        }

        $namaFile = 'rekap_absensi_' . $tanggalAwal
            . '_sampai_' . $tanggalAkhir . '.xlsx';

        // Buat file di memori sebelum mengirim header unduhan.
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $output = fopen('php://temp', 'w+b');

        if ($output === false) {
            throw new \RuntimeException('Gagal menyiapkan file Excel.');
        }

        $writer->save($output);
        rewind($output);
        $isiFile = stream_get_contents($output);
        fclose($output);

        if ($isiFile === false || $isiFile === '') {
            throw new \RuntimeException('File Excel kosong.');
        }

        $spreadsheet->disconnectWorksheets();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $namaFile . '"');
        header('Content-Length: ' . strlen($isiFile));
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');

        echo $isiFile;
        exit;
    } catch (\Throwable $e) {
        error_log('Ekspor XLSX gagal: ' . $e->getMessage());
        http_response_code(500);
        exit('Gagal membuat file Excel. Periksa log error PHP untuk detailnya.');
    }
}


/* Ekspor CSV untuk Microsoft Excel */
if (($_GET['ekspor'] ?? '') === 'excel') {
    $namaFile = 'rekap_absensi_' . $tanggalAwal
        . '_sampai_' . $tanggalAkhir . '.csv';

    header('Content-Type: text/csv; charset=UTF-8');
    header(
        'Content-Disposition: attachment; filename="' . $namaFile . '"'
    );
    header('Cache-Control: no-store, no-cache, must-revalidate');

    // BOM untuk membantu Excel membaca UTF-8.
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    if ($output === false) {
        http_response_code(500);
        exit('Gagal membuat file ekspor.');
    }

    // Menulis CSV dengan pemisah titik koma.
    $tulisBaris = static function ($output, array $kolom): void {
        foreach ($kolom as &$nilai) {
            $nilai = (string) ($nilai ?? '');

            // Mencegah formula Excel yang berpotensi berbahaya.
            if (preg_match('/^[\s]*[=+\-@]/u', $nilai)) {
                $nilai = "'" . $nilai;
            }
        }
        unset($nilai);

        fputcsv($output, $kolom, ';', '"', '');
    };

    $tulisBaris($output, ['REKAP ABSENSI KARYAWAN']);
    $tulisBaris($output, [
        'Periode',
        $tanggalAwal . ' sampai ' . $tanggalAkhir
    ]);
    $tulisBaris($output, ['Total Catatan', $totalData]);
    $tulisBaris($output, []);

    $tulisBaris($output, [
        'No.',
        'Tanggal',
        'Nama Peserta',
        'Email',
        'Jam Masuk',
        'Jam Pulang',
        'Status',
        'Kondisi Kesehatan',
        'Kegiatan Hari Ini',
        'Keterangan'
    ]);

    foreach ($dataAbsensi as $index => $baris) {
        $tulisBaris($output, [
            $index + 1,
            $baris['tanggal'] ?? '',
            $baris['nama'] ?? '',
            $baris['email'] ?? '',
            $baris['jam_masuk'] ?? '',
            $baris['jam_pulang'] ?? '',
            ucfirst((string) ($baris['status'] ?? '')),
            label_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? 'sehat')),
            $baris['kegiatan_hari_ini'] ?? '',
            $baris['keterangan'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

$tautanPeriode = 'rekap_absensi.php?awal=' . rawurlencode($tanggalAwal)
    . '&akhir=' . rawurlencode($tanggalAkhir);

mulai_halaman([
    'judul' => 'Rekap Absensi',
    'crumb' => ucfirst($akun['role'] ?? sesi_role()),
    'aksi'  => '<button type="button" class="tombol tombol-kecil" onclick="window.print()">' . ikon_sp('cetak') . ' Cetak</button>',
]);
?>

<!-- ================= FILTER ================= -->
<form method="get" action="rekap_absensi.php" class="filter-bar">
    <div class="grup-form">
        <label class="label-form" for="awal">Tanggal Awal</label>
        <input type="date" id="awal" name="awal" value="<?= e($tanggalAwal) ?>" required>
    </div>

    <div class="grup-form">
        <label class="label-form" for="akhir">Tanggal Akhir</label>
        <input type="date" id="akhir" name="akhir" value="<?= e($tanggalAkhir) ?>" required>
    </div>

    <div class="aksi-filter">
        <button type="submit" class="tombol tombol-utama">Tampilkan</button>
        <a href="rekap_absensi.php" class="tombol">Bulan Ini</a>
    </div>
</form>

<!-- ================= AKSI ================= -->
<div class="d-flex gap-2 flex-wrap no-print" style="margin-bottom:18px">
    <a class="tombol tombol-sukses" href="<?= e($tautanPeriode) ?>&ekspor=excel">
        <?= ikon_sp('unduh') ?> Ekspor Excel (CSV)
    </a>
    <a class="tombol tombol-sukses" href="<?= e($tautanPeriode) ?>&ekspor=xlsx">
        <?= ikon_sp('grafik') ?> Unduh Excel (.xlsx)
    </a>
    <a href="mentor_absensi.php" class="tombol">← Kembali ke Absensi</a>
    <a href="mentor_dashboard.php" class="tombol">Dashboard Mentor</a>
</div>

<!-- ================= RINGKASAN ================= -->
<section class="grid grid-4">
    <div class="stat navy">
        <span class="ikon">▤</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $totalData ?></div>
            <div class="keterangan">Total Catatan</div>
        </div>
    </div>
    <div class="stat hijau">
        <span class="ikon">✓</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahStatus['hadir'] ?></div>
            <div class="keterangan">Hadir</div>
        </div>
    </div>
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('surat') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahStatus['izin'] ?></div>
            <div class="keterangan">Izin</div>
        </div>
    </div>
    <div class="stat kuning">
        <span class="ikon">✚</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahStatus['sakit'] ?></div>
            <div class="keterangan">Sakit</div>
        </div>
    </div>
    <div class="stat merah">
        <span class="ikon">✕</span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahStatus['alpa'] ?></div>
            <div class="keterangan">Alpa</div>
        </div>
    </div>
</section>

<!-- ================= RINGKASAN KONDISI KESEHATAN ================= -->
<section class="kartu" style="margin-top:18px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Ringkasan Kondisi Kesehatan</h3>
            <span class="text-redup text-kecil">
                Swa-laporan karyawan saat absen, periode <?= e($tanggalAwal) ?> sampai <?= e($tanggalAkhir) ?>.
            </span>
        </div>
    </div>
    <div class="kartu-badan">
        <div class="d-flex gap-2 flex-wrap">
            <?php foreach (daftar_kondisi_kesehatan() as $nilai => $label): ?>
                <span class="badge <?= e(badge_kondisi_kesehatan($nilai)) ?>">
                    <?= e($label) ?>: <?= (int) ($jumlahKesehatan[$nilai] ?? 0) ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ================= REKAP PER PESERTA ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Rekap Absensi Per Peserta</h3>
            <span class="text-redup text-kecil">
                Ringkasan kehadiran setiap peserta, periode
                <?= e($tanggalAwal) ?> sampai <?= e($tanggalAkhir) ?>.
            </span>
        </div>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Peserta</th>
                        <th>Email</th>
                        <th>Total</th>
                        <th>Hadir</th>
                        <th>Izin</th>
                        <th>Sakit</th>
                        <th>Alpa</th>
                        <th>% Hadir</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($dataRekapPeserta) > 0): ?>
                    <?php foreach ($dataRekapPeserta as $index => $peserta): ?>
                        <?php
                            $totalCatatan = (int) $peserta['total_catatan'];
                            $jumlahHadir = (int) $peserta['hadir'];
                            $persentaseHadir = $totalCatatan > 0
                                ? ($jumlahHadir / $totalCatatan) * 100
                                : 0;
                        ?>
                        <tr>
                            <td><?= (int) ($index + 1) ?></td>
                            <td><strong><?= e($peserta['nama']) ?></strong></td>
                            <td class="text-kecil text-redup"><?= e($peserta['email']) ?></td>
                            <td><?= $totalCatatan ?></td>
                            <td><span class="badge absensi-hadir"><?= $jumlahHadir ?></span></td>
                            <td><span class="badge absensi-izin"><?= (int) $peserta['izin'] ?></span></td>
                            <td><span class="badge absensi-sakit"><?= (int) $peserta['sakit'] ?></span></td>
                            <td><span class="badge absensi-alpa"><?= (int) $peserta['alpa'] ?></span></td>
                            <td><strong><?= e(number_format($persentaseHadir, 1, ',', '.')) ?>%</strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('grup') ?></span>
                            Belum ada akun peserta ber-role karyawan.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= DATA ABSENSI ================= -->
<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Data Absensi</h3>
            <span class="text-redup text-kecil">
                Periode <?= e($tanggalAwal) ?> sampai <?= e($tanggalAkhir) ?>
                · <?= (int) $totalData ?> catatan.
            </span>
        </div>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Tanggal</th>
                        <th>Nama Peserta</th>
                        <th>Email</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Status</th>
                        <th>Kesehatan</th>
                        <th>Kegiatan hari ini</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($totalData > 0): ?>
                    <?php foreach ($dataAbsensi as $index => $baris): ?>
                        <tr>
                            <td><?= (int) ($index + 1) ?></td>
                            <td class="text-kecil text-redup"><?= e($baris['tanggal']) ?></td>
                            <td><?= e($baris['nama']) ?></td>
                            <td class="text-kecil text-redup"><?= e($baris['email']) ?></td>
                            <td><?= e(($baris['jam_masuk'] ?? '') ?: '-') ?></td>
                            <td><?= e(($baris['jam_pulang'] ?? '') ?: '-') ?></td>
                            <td>
                                <span class="badge absensi-<?= e(strtolower(trim((string) $baris['status']))) ?>">
                                    <?= e(label_status_absensi((string) $baris['status'])) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= e(badge_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? ''))) ?>">
                                    <?= e(label_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? ''))) ?>
                                </span>
                            </td>
                            <td class="text-kecil" style="max-width:260px;white-space:pre-wrap">
                                <?= e(($baris['kegiatan_hari_ini'] ?? '') ?: '-') ?>
                            </td>
                            <td><?= e(($baris['keterangan'] ?? '') ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('kalender') ?></span>
                            Belum ada data absensi pada periode ini.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<div class="text-redup text-kecil akhir-halaman" style="margin-top:8px">
    Sistem Pelaporan Harian PHR &copy; <?= e(date('Y')) ?>
    · Dicetak oleh: <?= e($akun['nama'] ?? sesi_nama()) ?>
</div>

<?php akhiri_halaman(); ?>
