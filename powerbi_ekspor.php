<?php
/**
 * powerbi_ekspor.php — Unduh dataset siap-Power BI (khusus admin).
 *
 * Menyediakan:
 *   · Unduh multi-lembar .xlsx (satu lembar per VIEW).
 *   · Unduh .zip berisi satu berkas .csv per VIEW.
 *   · Unduh satu VIEW sebagai .csv.
 *   · Unduh berkas koneksi Power BI (.pbids) dengan host/basis data terisi.
 *
 * Hanya membaca VIEW analitik (bukan tabel operasional). Kueri memakai
 * koneksi aplikasi ini, sehingga daftar VIEW juga dapat diakses `bi_reader`.
 *
 * Batas akses: admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

/* ---------------------------------------------------------------
 * Daftar dataset (whitelist) — jangan pernah menerima nama view
 * dari masukan pengguna secara langsung.
 * ------------------------------------------------------------- */
function bi_datasets(): array
{
    return [
        ['view' => 'vw_dim_pengguna',           'label' => 'Dimensi Pengguna',            'kategori' => 'Dimensi'],
        ['view' => 'vw_dim_tanggal',            'label' => 'Dimensi Tanggal',             'kategori' => 'Dimensi'],
        ['view' => 'vw_laporan_harian',         'label' => 'Fakta Laporan Harian',        'kategori' => 'Fakta'],
        ['view' => 'vw_absensi',                'label' => 'Fakta Absensi',               'kategori' => 'Fakta'],
        ['view' => 'vw_jadwal',                 'label' => 'Fakta Jadwal',                'kategori' => 'Fakta'],
        ['view' => 'vw_komentar',               'label' => 'Fakta Komentar',              'kategori' => 'Fakta'],
        ['view' => 'vw_kasus',                  'label' => 'Fakta Kasus',                 'kategori' => 'Fakta'],
        ['view' => 'vw_pengingat',              'label' => 'Fakta Pengingat',             'kategori' => 'Fakta'],
        ['view' => 'vw_ringkasan_harian',       'label' => 'Ringkasan Harian',            'kategori' => 'Agregat'],
        ['view' => 'vw_rekap_bulanan',          'label' => 'Rekap Bulanan',               'kategori' => 'Agregat'],
        ['view' => 'vw_kehadiran_bulanan',      'label' => 'Kehadiran Bulanan',           'kategori' => 'Agregat'],
        ['view' => 'vw_produktivitas_mingguan', 'label' => 'Produktivitas Mingguan',      'kategori' => 'Agregat'],
        ['view' => 'vw_kepatuhan_harian',       'label' => 'Kepatuhan Harian',            'kategori' => 'Agregat'],
        ['view' => 'vw_kasus_per_urgensi',      'label' => 'Kasus per Urgensi',           'kategori' => 'Agregat'],
        ['view' => 'vw_status_laporan',         'label' => 'Distribusi Status Laporan',   'kategori' => 'Agregat'],
        ['view' => 'vw_wilayah',                'label' => 'Master Wilayah & Lokasi',      'kategori' => 'Dimensi'],
        ['view' => 'vw_srm',                    'label' => 'Fakta SRM',                    'kategori' => 'Data Sosial'],
        ['view' => 'vw_cid',                    'label' => 'Fakta CID',                    'kategori' => 'Data Sosial'],
        ['view' => 'vw_srm_per_area',           'label' => 'SRM per Area',                 'kategori' => 'Data Sosial'],
        ['view' => 'vw_srm_per_stakeholder',    'label' => 'SRM per Stakeholder',          'kategori' => 'Data Sosial'],
        ['view' => 'vw_cid_per_pilar',          'label' => 'CID per Pilar',                'kategori' => 'Data Sosial'],
        ['view' => 'vw_cid_per_wilayah',        'label' => 'CID per Wilayah',              'kategori' => 'Data Sosial'],
    ];
}

/** Cari definisi dataset berdasarkan nama view (whitelist). */
function bi_dataset(string $view): ?array
{
    foreach (bi_datasets() as $d) {
        if ($d['view'] === $view) {
            return $d;
        }
    }
    return null;
}

/** Netralkan formula injection pada sel CSV/Excel. */
if (!function_exists('amankan_sel')) {
    function amankan_sel(mixed $nilai): mixed
    {
        if (!is_string($nilai) || $nilai === '') {
            return $nilai;
        }
        if (in_array($nilai[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $nilai;
        }
        return $nilai;
    }
}

/** Ambil seluruh baris sebuah view (dengan batas aman). */
function bi_ambil(mysqli $conn, string $view, int $maks = 200000): array
{
    $d = bi_dataset($view);
    if ($d === null) {
        return ['kolom' => [], 'baris' => [], 'terpotong' => false];
    }
    $hasil = ['kolom' => [], 'baris' => [], 'terpotong' => false];
    // Nama view berasal dari whitelist -> aman disisipkan.
    $res = $conn->query('SELECT * FROM `' . $view . '` LIMIT ' . ($maks + 1));
    if (!$res) {
        return $hasil;
    }
    $fields = $res->fetch_fields();
    foreach ($fields as $f) {
        $hasil['kolom'][] = (string) $f->name;
    }
    $n = 0;
    while ($row = $res->fetch_assoc()) {
        $n++;
        if ($n > $maks) {
            $hasil['terpotong'] = true;
            break;
        }
        $hasil['baris'][] = $row;
    }
    $res->free();
    return $hasil;
}

/** Nama lembar Excel yang sah (maks 31 char, tanpa karakter terlarang). */
function bi_nama_lembar(string $view): string
{
    $nama = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', ' ', $view) ?? $view;
    $nama = trim($nama);
    return mb_substr($nama === '' ? 'Sheet' : $nama, 0, 31);
}

/** Kirim berkas sebagai unduhan lalu hentikan skrip. */
function bi_kirim_berkas(string $path, string $namaUnduh, string $mime): void
{
    if (!is_file($path)) {
        http_response_code(500);
        exit('Berkas hasil tidak ditemukan.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $namaUnduh . '"');
    header('Content-Length: ' . (string) filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    @unlink($path);
    exit;
}

/* ================================================================
 * Unduhan langsung (GET)
 * ================================================================ */
$unduh = (string) ($_GET['unduh'] ?? '');

/* ----- Satu view sebagai CSV ----- */
if ($unduh === 'csv') {
    csrf_cek_atau_matikan('bi_ekspor');
    $view = (string) ($_GET['view'] ?? '');
    $d = bi_dataset($view);
    if ($d === null) {
        http_response_code(404);
        exit('Dataset tidak dikenal.');
    }
    $data = bi_ambil($conn, $view);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $view . '_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8 dengan benar
    if ($data['kolom'] !== []) {
        fputcsv($out, $data['kolom'], ';');
    }
    foreach ($data['baris'] as $row) {
        $baris = [];
        foreach ($data['kolom'] as $k) {
            $baris[] = amankan_sel($row[$k] ?? '');
        }
        fputcsv($out, $baris, ';');
    }
    fclose($out);

    catat_audit($conn, 'bi_ekspor_csv', 'bi_view', null, 'Ekspor CSV ' . $view, null, null);
    exit;
}

/* ----- Berkas koneksi Power BI (.pbids) ----- */
if ($unduh === 'pbids') {
    csrf_cek_atau_matikan('bi_ekspor');
    $pbids = [
        'version' => '0.1',
        'connections' => [[
            'details' => [
                'protocol' => 'mysql',
                'address' => ['server' => (string) DB_HOST, 'database' => (string) DB_NAME],
            ],
            'options' => [],
            'mode' => 'Import',
        ]],
    ];
    $nama = 'pelaporan_' . date('Ymd') . '.pbids';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Cache-Control: no-store');
    echo json_encode($pbids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ----- Berkas koneksi Power BI (.pbids) lewat ODBC (disarankan) ----- */
if ($unduh === 'pbids_odbc') {
    csrf_cek_atau_matikan('bi_ekspor');
    $pbids = [
        'version' => '0.1',
        'connections' => [[
            'details' => [
                'protocol' => 'odbc',
                'address' => ['dsn' => 'PelaporanBI'],
            ],
            'mode' => 'Import',
        ]],
    ];
    $nama = 'pelaporan_odbc_' . date('Ymd') . '.pbids';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Cache-Control: no-store');
    echo json_encode($pbids, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ----- Berkas .zip berisi CSV per view (POST agar CSRF terverifikasi) ----- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['aksi'] ?? '') === 'unduh') {
    csrf_cek_atau_matikan('bi_ekspor');

    $format = (string) ($_POST['format'] ?? 'xlsx');
    $diminta = (array) ($_POST['dataset'] ?? []);
    $pilih = [];
    foreach (bi_datasets() as $d) {
        if (in_array($d['view'], $diminta, true)) {
            $pilih[] = $d;
        }
    }
    if ($pilih === []) {
        flash_set('gagal', 'Pilih minimal satu dataset.');
        header('Location: powerbi_ekspor.php');
        exit;
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        flash_set('gagal', 'Pustaka PhpSpreadsheet tidak ditemukan pada folder vendor.');
        header('Location: powerbi_ekspor.php');
        exit;
    }
    require_once $autoload;

    $tmpDir = sys_get_temp_dir();
    $stempel = date('Ymd_His');

    if ($format === 'zip') {
        if (!class_exists('ZipArchive')) {
            flash_set('gagal', 'Ekstensi PHP zip tidak aktif pada server ini.');
            header('Location: powerbi_ekspor.php');
            exit;
        }
        $zipPath = $tmpDir . '/dataset_pelaporan_' . $stempel . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            flash_set('gagal', 'Gagal membuat berkas zip.');
            header('Location: powerbi_ekspor.php');
            exit;
        }
        foreach ($pilih as $d) {
            $data = bi_ambil($conn, $d['view']);
            $csv = "\xEF\xBB\xBF";
            $buf = fopen('php://temp', 'r+');
            if ($data['kolom'] !== []) {
                fputcsv($buf, $data['kolom'], ';');
            }
            foreach ($data['baris'] as $row) {
                $baris = [];
                foreach ($data['kolom'] as $k) {
                    $baris[] = amankan_sel($row[$k] ?? '');
                }
                fputcsv($buf, $baris, ';');
            }
            rewind($buf);
            $csv .= (string) stream_get_contents($buf);
            fclose($buf);
            $zip->addFromString($d['view'] . '.csv', $csv);
        }
        $zip->close();
        catat_audit($conn, 'bi_ekspor_zip', 'bi_view', null,
            'Ekspor ' . count($pilih) . ' dataset sebagai CSV zip', null, null);
        bi_kirim_berkas($zipPath, 'dataset_pelaporan_' . $stempel . '.zip', 'application/zip');
    }

    /* ---- XLSX multi-lembar ---- */
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->removeSheetByIndex(0);
    $dipakai = [];
    foreach ($pilih as $d) {
        $data = bi_ambil($conn, $d['view']);
        $nama = bi_nama_lembar($d['view']);
        $n = 2;
        while (in_array($nama, $dipakai, true)) {
            $nama = bi_nama_lembar($d['view'] . '_' . $n);
            $n++;
        }
        $dipakai[] = $nama;

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle($nama);

        $kolom = $data['kolom'] === [] ? ['(tidak ada kolom)'] : $data['kolom'];
        $huruf = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($kolom));
        $sheet->setCellValue('A1', $d['label']);
        $sheet->mergeCells('A1:' . $huruf . '1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $kol = 1;
        foreach ($kolom as $judul) {
            $sheet->setCellValue(
                \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($kol) . '3',
                $judul
            );
            $kol++;
        }
        $barisKe = 4;
        foreach ($data['baris'] as $row) {
            $kol = 1;
            foreach ($kolom as $k) {
                $sheet->setCellValue(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($kol) . $barisKe,
                    amankan_sel($row[$k] ?? '')
                );
                $kol++;
            }
            $barisKe++;
        }
        $sheet->getStyle('A3:' . $huruf . '3')->getFont()->setBold(true);
        $sheet->freezePane('A4');
        foreach (range(1, count($kolom)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
        if ($data['terpotong']) {
            $sheet->setCellValue('A' . ($barisKe + 1), '(Dipotong: melebihi batas 200.000 baris)');
        }
    }

    $xlsxPath = $tmpDir . '/dataset_pelaporan_' . $stempel . '.xlsx';
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->setPreCalculateFormulas(false);
    $writer->save($xlsxPath);
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    catat_audit($conn, 'bi_ekspor_xlsx', 'bi_view', null,
        'Ekspor ' . count($pilih) . ' dataset sebagai xlsx multi-lembar', null, null);

    bi_kirim_berkas(
        $xlsxPath,
        'dataset_pelaporan_' . $stempel . '.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );
}

/* ================================================================
 * Tampilan
 * ================================================================ */
$datasets = bi_datasets();

// Hitung jumlah baris tiap view (untuk informasi).
$jumlah = [];
foreach ($datasets as $d) {
    $v = $d['view'];
    $res = $conn->query('SELECT COUNT(*) AS n FROM `' . $v . '`');
    $jumlah[$v] = $res ? (int) ($res->fetch_assoc()['n'] ?? 0) : -1;
    if ($res) {
        $res->free();
    }
}

$tokenCsrf = csrf_token('bi_ekspor');

$aksiTopbar = '<a class="tombol tombol-garis" href="impor_laporan.php">&#11014; Impor Data</a>';

mulai_halaman([
    'judul' => 'Ekspor Dataset Power BI',
    'crumb' => APP_SINGKAT,
    'aktif' => 'powerbi',
    'aksi'  => $aksiTopbar,
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Dataset Analitik</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Unduh data lewat halaman ini (mode <strong>Import</strong>), atau hubungkan Power BI
            langsung ke VIEW analitik memakai akun baca-saja <span class="text-mono">bi_reader</span>.
        </p>
    </div>
    <div class="kartu-badan">
        <form method="post" action="powerbi_ekspor.php">
            <?= csrf_field('bi_ekspor') ?>
            <input type="hidden" name="aksi" value="unduh">

            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px">
                <label>Format
                    <select name="format">
                        <option value="xlsx">Excel (.xlsx, satu lembar per dataset)</option>
                        <option value="zip">CSV terkompresi (.zip, satu berkas per dataset)</option>
                    </select>
                </label>
                <button class="tombol tombol-utama" type="submit">Unduh dataset terpilih</button>
                <span class="text-redup" style="font-size:13px">
                    <label style="display:inline-flex;gap:6px;align-items:center;cursor:pointer">
                        <input type="checkbox" onclick="document.querySelectorAll('input[name=\'dataset[]\']').forEach(function(c){c.checked=this.checked}.bind(this))">
                        Pilih semua
                    </label>
                </span>
            </div>

            <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th style="width:40px"></th>
                        <th>Dataset (VIEW)</th>
                        <th>Kategori</th>
                        <th class="text-kanan">Baris</th>
                        <th class="text-kanan">CSV</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($datasets as $d): ?>
                    <tr>
                        <td><input type="checkbox" name="dataset[]" value="<?= e($d['view']) ?>" checked></td>
                        <td>
                            <strong><?= e($d['label']) ?></strong><br>
                            <span class="text-mono text-redup" style="font-size:12px"><?= e($d['view']) ?></span>
                        </td>
                        <td><span class="badge badge-navy badge-tanpa-titik"><?= e($d['kategori']) ?></span></td>
                        <td class="text-kanan"><?= $jumlah[$d['view']] < 0 ? '—' : number_format($jumlah[$d['view']], 0, ',', '.') ?></td>
                        <td class="text-kanan">
                            <a class="tombol tombol-garis tombol-kecil"
                               href="powerbi_ekspor.php?unduh=csv&amp;csrf_token=<?= e($tokenCsrf) ?>&amp;view=<?= e($d['view']) ?>">CSV</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </form>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala"><h2>Koneksi langsung &amp; berkas bantu</h2></div>
    <div class="kartu-badan">
        <p style="margin:0 0 12px;display:flex;gap:8px;flex-wrap:wrap">
            <a class="tombol tombol-utama" href="powerbi_ekspor.php?unduh=pbids_odbc&amp;csrf_token=<?= e($tokenCsrf) ?>">
                <?= ikon_sp('dokumen') ?> Berkas koneksi ODBC (.pbids) &mdash; disarankan
            </a>
            <a class="tombol tombol-garis" href="powerbi_ekspor.php?unduh=pbids&amp;csrf_token=<?= e($tokenCsrf) ?>">
                <?= ikon_sp('dokumen') ?> Berkas koneksi MySQL (.pbids)
            </a>
            <a class="tombol tombol-garis" href="panduan_powerbi.php">
                <?= ikon_sp('buku') ?> Panduan Power BI (ODBC)
            </a>
        </p>
        <div class="bungkus-tabel">
        <table class="tabel">
            <tbody>
                <tr><th style="width:220px">Jalur disarankan</th><td><strong>ODBC</strong> (driver <span class="text-mono">MySQL ODBC</span> 64-bit) &mdash; tanpa MySQL Connector/NET.</td></tr>
                <tr><th>Nama DSN</th><td class="text-mono">PelaporanBI</td></tr>
                <tr><th>Daftar DSN</th><td><span class="text-mono">scripts\buat_dsn_powerbi.ps1</span></td></tr>
                <tr><th>Jalur alternatif</th><td>Konektor <em>MySQL database</em> (perlu MySQL Connector/NET).</td></tr>
                <tr><th>Server</th><td class="text-mono"><?= e((string) DB_HOST) ?>:3306</td></tr>
                <tr><th>Basis data</th><td class="text-mono"><?= e((string) DB_NAME) ?></td></tr>
                <tr><th>Pengguna baca-saja</th><td class="text-mono">bi_reader</td></tr>
                <tr><th>Sumber data</th><td>VIEW analitik (di atas), bukan tabel operasional.</td></tr>
                <tr><th>Mode yang disarankan</th><td>Import (segarkan manual / terjadwal).</td></tr>
            </tbody>
        </table>
        </div>
        <p class="text-redup" style="margin-top:12px;font-size:13px">
            Kata sandi <span class="text-mono">bi_reader</span> diatur pada berkas migrasi
            <span class="text-mono">database/migrations/010_user_db.sql</span> dan tidak ditampilkan di sini.
            Rincian langkah tersedia di halaman <span class="text-mono">Panduan Power BI (ODBC)</span>
            dan <span class="text-mono">powerbi/README.md</span>.
        </p>
    </div>
</div>

<?php
akhiri_halaman();
