<?php
/**
 * includes/impor_excel.php
 * Mesin impor laporan/absensi dari berkas Excel (.xlsx/.xls) atau CSV.
 *
 * Alur pemakaian (3 langkah, lihat impor_laporan.php):
 *   1. Unggah   -> impor_simpan_tmp()  + impor_baca()   (tampilkan pratinjau)
 *   2. Pemetaan -> impor_tebak_pemetaan() (kolom berkas -> medan tujuan)
 *   3. Simpan   -> impor_validasi() lalu impor_commit()
 *
 * Fungsi-fungsi murni (normalisasi tanggal/jam, tebak pemetaan, validasi)
 * sengaja tidak menyentuh basis data supaya mudah diuji (lihat tests/).
 *
 * Seluruh berkas ini ditulis dengan karakter ASCII saja.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

/* ---------------------------------------------------------------
 * Konfigurasi impor
 * ------------------------------------------------------------- */
if (!function_exists('impor_ekstensi_diizinkan')) {
    /** Ekstensi berkas yang diterima. */
    function impor_ekstensi_diizinkan(): array
    {
        return ['xlsx', 'xls', 'csv', 'txt'];
    }
}

if (!function_exists('impor_maks_bytes')) {
    function impor_maks_bytes(): int
    {
        return defined('IMPOR_MAKS_BYTES') ? (int) IMPOR_MAKS_BYTES : 8 * 1024 * 1024;
    }
}

if (!function_exists('impor_dir')) {
    /** Direktori kerja impor (di luar jangkauan web; `backups/` diblokir). */
    function impor_dir(): string
    {
        $dir = dirname(__DIR__) . '/backups/impor';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }
}

if (!function_exists('impor_bersihkan_lama')) {
    /** Hapus berkas kerja impor yang lebih tua dari $umurDetik. */
    function impor_bersihkan_lama(int $umurDetik = 86400): int
    {
        $dir = impor_dir();
        $n = 0;
        foreach (glob($dir . '/*') ?: [] as $berkas) {
            if (is_file($berkas) && (time() - (int) @filemtime($berkas)) > $umurDetik) {
                if (@unlink($berkas)) {
                    $n++;
                }
            }
        }
        return $n;
    }
}

/* ---------------------------------------------------------------
 * Definisi medan tujuan
 * ------------------------------------------------------------- */

if (!function_exists('impor_jenis_tersedia')) {
    /** Daftar jenis impor yang didukung: kunci => label. */
    function impor_jenis_tersedia(): array
    {
        return [
            'laporan_harian' => 'Laporan Harian',
            'absensi'        => 'Absensi',
        ];
    }
}

if (!function_exists('impor_jenis_untuk_role')) {
    /**
     * Daftar jenis impor yang boleh dipakai tiap peran.
     * Karyawan hanya data miliknya (laporan/absensi). Mentor & admin juga
     * boleh mengimpor data SRM & CID (berkas Excel khusus).
     *
     * @return array<string,string>
     */
    function impor_jenis_untuk_role(string $role): array
    {
        $daftar = impor_jenis_tersedia();
        if (in_array($role, ['admin', 'mentor'], true)) {
            $daftar['srm_cid'] = 'SRM & CID (Excel)';
        }
        return $daftar;
    }
}

if (!function_exists('impor_field')) {
    /**
     * Medan tujuan per jenis impor.
     * Setiap medan: label, wajib (bool), petunjuk (alias pencocokan otomatis).
     */
    function impor_field(string $jenis): array
    {
        if ($jenis === 'absensi') {
            return [
                'pengguna'          => ['label' => 'Pengguna (email / nama / ID)', 'wajib' => true,
                                        'petunjuk' => ['pengguna', 'user', 'email', 'nama', 'karyawan', 'peserta', 'nrp', 'user id']],
                'tanggal'           => ['label' => 'Tanggal', 'wajib' => true,
                                        'petunjuk' => ['tanggal', 'date', 'tgl', 'hari']],
                'jam_masuk'         => ['label' => 'Jam Masuk', 'wajib' => false,
                                        'petunjuk' => ['jam masuk', 'masuk', 'jam hadir', 'check in', 'checkin', 'in']],
                'jam_pulang'        => ['label' => 'Jam Pulang', 'wajib' => false,
                                        'petunjuk' => ['jam pulang', 'pulang', 'check out', 'checkout', 'out']],
                'status'            => ['label' => 'Status Kehadiran', 'wajib' => false,
                                        'petunjuk' => ['status', 'kehadiran', 'hadir', 'absen']],
                'keterangan'        => ['label' => 'Keterangan', 'wajib' => false,
                                        'petunjuk' => ['keterangan', 'catatan', 'note', 'notes', 'alasan']],
                'kegiatan_hari_ini' => ['label' => 'Kegiatan Hari Ini', 'wajib' => false,
                                        'petunjuk' => ['kegiatan hari ini', 'kegiatan', 'aktivitas', 'pekerjaan']],
                'kondisi_kesehatan' => ['label' => 'Kondisi Kesehatan', 'wajib' => false,
                                        'petunjuk' => ['kondisi kesehatan', 'kesehatan', 'health', 'kondisi']],
                'lokasi_teks'       => ['label' => 'Lokasi', 'wajib' => false,
                                        'petunjuk' => ['lokasi', 'lokasi teks', 'alamat', 'tempat', 'location', 'address', 'desa', 'kelurahan']],
                'garis_lintang'     => ['label' => 'Garis Lintang (latitude)', 'wajib' => false,
                                        'petunjuk' => ['garis lintang', 'lintang', 'latitude', 'lat']],
                'garis_bujur'       => ['label' => 'Garis Bujur (longitude)', 'wajib' => false,
                                        'petunjuk' => ['garis bujur', 'bujur', 'longitude', 'lng', 'lon', 'long']],
            ];
        }

        return [
            'pengguna'           => ['label' => 'Pengguna (email / nama / ID)', 'wajib' => true,
                                     'petunjuk' => ['pengguna', 'user', 'email', 'nama', 'karyawan', 'peserta', 'nrp', 'user id']],
            'tanggal'            => ['label' => 'Tanggal', 'wajib' => true,
                                     'petunjuk' => ['tanggal', 'date', 'tgl', 'hari']],
            'jam_mulai'          => ['label' => 'Jam Mulai', 'wajib' => false,
                                     'petunjuk' => ['jam mulai', 'mulai', 'start', 'jam start', 'dari', 'jam awal']],
            'jam_selesai'        => ['label' => 'Jam Selesai', 'wajib' => false,
                                     'petunjuk' => ['jam selesai', 'selesai', 'end', 'jam end', 'sampai', 'jam akhir']],
            'kegiatan'           => ['label' => 'Kegiatan', 'wajib' => true,
                                     'petunjuk' => ['kegiatan', 'judul', 'aktivitas', 'pekerjaan', 'activity', 'kegiatan utama']],
            'deskripsi'          => ['label' => 'Deskripsi', 'wajib' => false,
                                     'petunjuk' => ['deskripsi', 'uraian', 'detail', 'keterangan', 'rincian', 'description']],
            'hasil'              => ['label' => 'Hasil', 'wajib' => false,
                                     'petunjuk' => ['hasil', 'output', 'capaian', 'result']],
            'kendala'            => ['label' => 'Kendala', 'wajib' => false,
                                     'petunjuk' => ['kendala', 'hambatan', 'masalah', 'issue', 'problem']],
            'rencana_berikutnya' => ['label' => 'Rencana Berikutnya', 'wajib' => false,
                                     'petunjuk' => ['rencana berikutnya', 'rencana', 'plan', 'next', 'tindak lanjut', 'rencana selanjutnya']],
            'lokasi_teks'        => ['label' => 'Lokasi', 'wajib' => false,
                                     'petunjuk' => ['lokasi', 'lokasi teks', 'alamat', 'tempat', 'location', 'address', 'desa', 'kelurahan']],
            'garis_lintang'      => ['label' => 'Garis Lintang (latitude)', 'wajib' => false,
                                     'petunjuk' => ['garis lintang', 'lintang', 'latitude', 'lat']],
            'garis_bujur'        => ['label' => 'Garis Bujur (longitude)', 'wajib' => false,
                                     'petunjuk' => ['garis bujur', 'bujur', 'longitude', 'lng', 'lon', 'long']],
        ];
    }
}

if (!function_exists('impor_status_laporan')) {
    function impor_status_laporan(): array
    {
        return ['draft', 'dikirim', 'diperiksa'];
    }
}

if (!function_exists('impor_status_absensi')) {
    function impor_status_absensi(): array
    {
        return ['hadir', 'izin', 'sakit', 'alpa'];
    }
}

if (!function_exists('impor_kondisi_kesehatan')) {
    function impor_kondisi_kesehatan(): array
    {
        return ['sehat', 'kurang_sehat', 'sakit', 'pemulihan'];
    }
}

/* ---------------------------------------------------------------
 * 1. Unggah berkas
 * ------------------------------------------------------------- */

if (!function_exists('impor_simpan_tmp')) {
    /**
     * Pindahkan berkas unggahan ke direktori kerja impor dengan nama acak.
     *
     * @param array $file entri dari $_FILES
     * @return array{ok: bool, galat: string, token: string, berkas: string, ekstensi: string, nama_asli: string}
     */
    function impor_simpan_tmp(array $file): array
    {
        $gagal = static fn (string $pesan): array => [
            'ok' => false, 'galat' => $pesan, 'token' => '', 'berkas' => '',
            'ekstensi' => '', 'nama_asli' => '',
        ];

        if (!isset($file['error']) || is_array($file['error'])) {
            return $gagal('Berkas tidak valid.');
        }
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            return $gagal('Pilih berkas terlebih dahulu.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return $gagal('Gagal mengunggah berkas (kode ' . (int) $file['error'] . ').');
        }
        $nama = (string) ($file['name'] ?? '');
        $ekstensi = strtolower((string) pathinfo($nama, PATHINFO_EXTENSION));
        if (!in_array($ekstensi, impor_ekstensi_diizinkan(), true)) {
            return $gagal('Jenis berkas harus: ' . strtoupper(implode(', ', impor_ekstensi_diizinkan())) . '.');
        }
        if (!is_uploaded_file((string) $file['tmp_name'])) {
            return $gagal('Berkas unggahan tidak sah.');
        }
        $ukuran = (int) ($file['size'] ?? 0);
        if ($ukuran < 1) {
            return $gagal('Berkas kosong.');
        }
        if ($ukuran > impor_maks_bytes()) {
            return $gagal('Ukuran berkas melebihi batas ' . number_format(impor_maks_bytes() / 1048576, 1) . ' MB.');
        }

        impor_bersihkan_lama();

        $token = bin2hex(random_bytes(16));
        $berkas = impor_dir() . '/' . $token . '.' . $ekstensi;
        if (!@move_uploaded_file((string) $file['tmp_name'], $berkas)) {
            return $gagal('Gagal menyimpan berkas kerja di server.');
        }
        @chmod($berkas, 0664);

        return [
            'ok' => true, 'galat' => '', 'token' => $token, 'berkas' => $berkas,
            'ekstensi' => $ekstensi, 'nama_asli' => $nama,
        ];
    }
}

if (!function_exists('impor_berkas_dari_token')) {
    /**
     * Kembalikan path berkas kerja yang sah untuk token tertentu (anti traversal).
     */
    function impor_berkas_dari_token(string $token, string $ekstensi): ?string
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }
        if (!in_array($ekstensi, impor_ekstensi_diizinkan(), true)) {
            return null;
        }
        $berkas = impor_dir() . '/' . $token . '.' . $ekstensi;
        return is_file($berkas) ? $berkas : null;
    }
}

/* ---------------------------------------------------------------
 * 2. Baca isi berkas
 * ------------------------------------------------------------- */

if (!function_exists('impor_baca')) {
    /**
     * Baca berkas menjadi baris-baris (array of array of string).
     *
     * @return array{ok: bool, galat: string, header: list<string>, baris: list<list<string>>,
     *               sheet: string, daftar_sheet: list<string>, terpotong: bool}
     */
    function impor_baca(string $path, ?string $sheet = null, int $maksBaris = 5000): array
    {
        $kosong = [
            'ok' => false, 'galat' => '', 'header' => [], 'baris' => [],
            'sheet' => '', 'daftar_sheet' => [], 'terpotong' => false,
        ];

        if (!is_file($path)) {
            return array_merge($kosong, ['galat' => 'Berkas kerja tidak ditemukan.']);
        }

        $ekstensi = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ekstensi, ['csv', 'txt'], true)) {
            return impor_baca_csv($path, $maksBaris);
        }

        return impor_baca_spreadsheet($path, $sheet, $maksBaris);
    }
}

if (!function_exists('impor_baca_csv')) {
    function impor_baca_csv(string $path, int $maksBaris): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'galat' => 'Berkas CSV tidak dapat dibuka.', 'header' => [],
                    'baris' => [], 'sheet' => 'CSV', 'daftar_sheet' => ['CSV'], 'terpotong' => false];
        }

        // Deteksi pemisah dari baris pertama.
        $pertama = (string) fgets($fh);
        rewind($fh);
        $pemisah = impor_deteksi_pemisah($pertama);

        $isi = [];
        $terpotong = false;
        while (($row = fgetcsv($fh, 0, $pemisah)) !== false) {
            // Lewati baris yang seluruhnya kosong.
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }
            $isi[] = array_map(static fn ($v) => impor_ke_teks($v), $row);
            if (count($isi) > $maksBaris + 1) {
                $terpotong = true;
                break;
            }
        }
        fclose($fh);

        return impor_susun($isi, 'CSV', $terpotong);
    }
}

if (!function_exists('impor_deteksi_pemisah')) {
    /** Tebak pemisah CSV: koma, titik koma, atau tab. */
    function impor_deteksi_pemisah(string $baris): string
    {
        $kandidat = [',' => substr_count($baris, ','), ';' => substr_count($baris, ';'), "\t" => substr_count($baris, "\t")];
        arsort($kandidat);
        $pemisah = (string) array_key_first($kandidat);
        return ($kandidat[$pemisah] ?? 0) > 0 ? $pemisah : ',';
    }
}

if (!function_exists('impor_baca_spreadsheet')) {
    function impor_baca_spreadsheet(string $path, ?string $sheet, int $maksBaris): array
    {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (!is_file($autoload)) {
            return ['ok' => false, 'galat' => 'Pustaka PhpSpreadsheet tidak ditemukan pada folder vendor.',
                    'header' => [], 'baris' => [], 'sheet' => '', 'daftar_sheet' => [], 'terpotong' => false];
        }
        require_once $autoload;

        try {
            /** @var \PhpOffice\PhpSpreadsheet\Reader\IReader $reader */
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);

            $daftar = [];
            if (method_exists($reader, 'listWorksheetNames')) {
                try {
                    $daftar = array_values((array) $reader->listWorksheetNames($path));
                } catch (\Throwable $t) {
                    $daftar = [];
                }
            }

            $spreadsheet = $reader->load($path);
            $namaSheet = '';
            if ($sheet !== null && $sheet !== '' && $spreadsheet->sheetNameExists($sheet)) {
                $namaSheet = $sheet;
            } else {
                $namaSheet = $spreadsheet->getSheet(0)->getTitle();
            }
            $ws = $spreadsheet->getSheetByName($namaSheet) ?? $spreadsheet->getSheet(0);

            $raw = $ws->toArray(null, true, false, false);
            $isi = [];
            $terpotong = false;
            foreach ($raw as $row) {
                $vals = array_map(static fn ($v) => impor_ke_teks($v), (array) $row);
                if (count(array_filter($vals, static fn ($v) => $v !== '')) === 0) {
                    continue;
                }
                $isi[] = $vals;
                if (count($isi) > $maksBaris + 1) {
                    $terpotong = true;
                    break;
                }
            }
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            if ($daftar === []) {
                $daftar = [$namaSheet];
            }

            return impor_susun($isi, $namaSheet, $terpotong, $daftar);
        } catch (\Throwable $t) {
            return ['ok' => false, 'galat' => 'Berkas Excel tidak dapat dibaca: ' . $t->getMessage(),
                    'header' => [], 'baris' => [], 'sheet' => '', 'daftar_sheet' => [], 'terpotong' => false];
        }
    }
}

if (!function_exists('impor_ke_teks')) {
    /** Ubah nilai sel menjadi teks bersih (tangani DateTime & float). */
    function impor_ke_teks(mixed $nilai): string
    {
        if ($nilai === null) {
            return '';
        }
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d H:i:s');
        }
        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }
        if (is_float($nilai)) {
            // Hindari notasi ilmiah & nol ekor yang mengganggu.
            $teks = rtrim(rtrim(sprintf('%.10F', $nilai), '0'), '.');
            return $teks === '' ? '0' : $teks;
        }
        return trim((string) $nilai);
    }
}

if (!function_exists('impor_susun')) {
    /**
     * Susun header + baris dari matriks mentah.
     *
     * @param list<list<string>> $isi
     * @return array{ok: bool, galat: string, header: list<string>, baris: list<list<string>>,
     *               sheet: string, daftar_sheet: list<string>, terpotong: bool}
     */
    function impor_susun(array $isi, string $sheet, bool $terpotong, ?array $daftarSheet = null): array
    {
        if ($isi === []) {
            return ['ok' => false, 'galat' => 'Berkas tidak memuat baris data.',
                    'header' => [], 'baris' => [], 'sheet' => $sheet,
                    'daftar_sheet' => $daftarSheet ?? [$sheet], 'terpotong' => false];
        }

        $header = array_shift($isi);
        // Rapikan header: kosong -> "Kolom N"; pastikan unik.
        $lebar = 0;
        foreach (array_merge([$header], $isi) as $r) {
            $lebar = max($lebar, count($r));
        }
        $header = impor_rapikan_header($header, $lebar);

        $baris = [];
        foreach ($isi as $r) {
            $r = array_pad($r, $lebar, '');
            $baris[] = array_slice($r, 0, $lebar);
        }

        return ['ok' => true, 'galat' => '', 'header' => $header, 'baris' => $baris,
                'sheet' => $sheet, 'daftar_sheet' => $daftarSheet ?? [$sheet], 'terpotong' => $terpotong];
    }
}

if (!function_exists('impor_rapikan_header')) {
    /** Pastikan header punya nama dan tidak duplikat. */
    function impor_rapikan_header(array $header, int $lebar): array
    {
        $header = array_pad(array_values($header), $lebar, '');
        $dipakai = [];
        $hasil = [];
        foreach ($header as $i => $nama) {
            $nama = trim((string) $nama);
            if ($nama === '') {
                $nama = 'Kolom ' . ($i + 1);
            }
            $asas = $nama;
            $n = 2;
            while (in_array(mb_strtolower($nama), $dipakai, true)) {
                $nama = $asas . ' (' . $n . ')';
                $n++;
            }
            $dipakai[] = mb_strtolower($nama);
            $hasil[] = $nama;
        }
        return $hasil;
    }
}

/* ---------------------------------------------------------------
 * 3. Tebak pemetaan kolom
 * ------------------------------------------------------------- */

if (!function_exists('impor_normal_kunci')) {
    /** Normalisasi teks untuk pencocokan pemetaan. */
    function impor_normal_kunci(string $teks): string
    {
        $teks = mb_strtolower(trim($teks));
        $teks = preg_replace('/[^a-z0-9]+/u', ' ', $teks) ?? '';
        return trim(preg_replace('/\s+/', ' ', $teks) ?? '');
    }
}

if (!function_exists('impor_normal_email')) {
    /**
     * Normalisasi surel khusus pencocokan pengguna — hanya huruf kecil + rapikan
     * spasi, sehingga karakter '@' dan '.' tetap dipertahankan (menghindari
     * bentrok dengan pencocokan nama).
     */
    function impor_normal_email(string $teks): string
    {
        return mb_strtolower(trim($teks));
    }
}

if (!function_exists('impor_tebak_pemetaan')) {
    /**
     * Tebak pemetaan kolom berkas -> medan tujuan berdasarkan nama header.
     *
     * @param list<string> $header
     * @return array<string,int> medan => indeks kolom
     */
    function impor_tebak_pemetaan(array $header, string $jenis): array
    {
        $field = impor_field($jenis);
        $norm = [];
        foreach ($header as $i => $nama) {
            $norm[$i] = impor_normal_kunci((string) $nama);
        }

        $hasil = [];
        $terpakai = [];

        // Tahap 1: kecocokan persis alias.
        foreach ($field as $kunci => $def) {
            foreach ($def['petunjuk'] as $alias) {
                $aliasNorm = impor_normal_kunci($alias);
                foreach ($norm as $i => $h) {
                    if (isset($terpakai[$i])) {
                        continue;
                    }
                    if ($h === $aliasNorm) {
                        $hasil[$kunci] = $i;
                        $terpakai[$i] = true;
                        continue 3;
                    }
                }
            }
        }
        // Tahap 2: kecocokan terkandung (mengandung alias).
        foreach ($field as $kunci => $def) {
            if (isset($hasil[$kunci])) {
                continue;
            }
            foreach ($def['petunjuk'] as $alias) {
                $aliasNorm = impor_normal_kunci($alias);
                if ($aliasNorm === '') {
                    continue;
                }
                foreach ($norm as $i => $h) {
                    if (isset($terpakai[$i]) || $h === '') {
                        continue;
                    }
                    if (str_contains($h, $aliasNorm)) {
                        $hasil[$kunci] = $i;
                        $terpakai[$i] = true;
                        continue 3;
                    }
                }
            }
        }

        return $hasil;
    }
}

/* ---------------------------------------------------------------
 * 4. Normalisasi nilai
 * ------------------------------------------------------------- */

if (!function_exists('impor_norm_tanggal')) {
    /** Normalisasi berbagai format tanggal menjadi Y-m-d, atau null bila gagal. */
    function impor_norm_tanggal(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d');
        }

        $teks = trim((string) $nilai);
        if ($teks === '') {
            return null;
        }

        // Tanggal serial Excel (mis. 45000).
        if (preg_match('/^\d+(\.\d+)?$/', $teks)) {
            $num = (float) $teks;
            if ($num > 20000 && $num < 60000) {
                $tanggal = impor_serial_excel($num);
                if ($tanggal !== null) {
                    return $tanggal;
                }
            }
            // Format padat: yyyymmdd atau ddmmyyyy.
            if (preg_match('/^\d{8}$/', $teks)) {
                $coba = [];
                $coba[] = substr($teks, 0, 4) . '-' . substr($teks, 4, 2) . '-' . substr($teks, 6, 2);
                $coba[] = substr($teks, 4, 4) . '-' . substr($teks, 2, 2) . '-' . substr($teks, 0, 2);
                foreach ($coba as $kandidat) {
                    if (impor_tanggal_sah($kandidat)) {
                        return $kandidat;
                    }
                }
            }
            return null;
        }

        // Buang bagian waktu bila ada.
        $teks = preg_replace('/\s+.*$/', '', $teks) ?? $teks;
        $teks = str_replace(['.', '/'], '-', $teks);

        // Y-m-d atau Y-m-d H:i:s
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $teks, $m)) {
            $kandidat = sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
            return impor_tanggal_sah($kandidat) ? $kandidat : null;
        }

        // d-m-Y atau d-m-y (kebiasaan Indonesia).
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2,4})$/', $teks, $m)) {
            $d = (int) $m[1];
            $b = (int) $m[2];
            $t = (int) $m[3];
            if ($t < 100) {
                $t += 2000;
            }
            // Bila komponen pertama > 12, jelas format d-m.
            $kandidat = sprintf('%04d-%02d-%02d', $t, $b, $d);
            if (impor_tanggal_sah($kandidat)) {
                return $kandidat;
            }
            // Coba tafsir sebagai m-d-Y.
            $kandidat2 = sprintf('%04d-%02d-%02d', $t, $d, $b);
            if (impor_tanggal_sah($kandidat2)) {
                return $kandidat2;
            }
            return null;
        }

        // Nama bulan (mis. "15 Okt 2026" / "October 15, 2026").
        $ts = strtotime($teks);
        if ($ts !== false && $ts > 0) {
            return date('Y-m-d', $ts);
        }
        return null;
    }
}

if (!function_exists('impor_tanggal_sah')) {
    function impor_tanggal_sah(string $ymd): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}

if (!function_exists('impor_serial_excel')) {
    /** Konversi tanggal serial Excel ke Y-m-d. */
    function impor_serial_excel(float $serial): ?string
    {
        // 25569 = jumlah hari 1970-01-01 pada epoch Excel (1900).
        $detik = (int) round(($serial - 25569) * 86400);
        if ($detik <= 0) {
            return null;
        }
        return gmdate('Y-m-d', $detik);
    }
}

if (!function_exists('impor_norm_jam')) {
    /** Normalisasi nilai menjadi H:i:s, atau null bila gagal/kosong. */
    function impor_norm_jam(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('H:i:s');
        }

        $teks = trim((string) $nilai);
        if ($teks === '') {
            return null;
        }

        // Fraksi hari (mis. 0.3125 = 07:30).
        if (preg_match('/^0?\.\d+$/', $teks)) {
            $detik = (int) round(((float) $teks) * 86400);
            return impor_detik_ke_jam($detik % 86400);
        }

        // Angka bulat: jam saja, atau HHMM / HHMMSS.
        if (preg_match('/^\d+$/', $teks)) {
            if (strlen($teks) <= 2) {
                return impor_detik_ke_jam(((int) $teks) * 3600);
            }
            if (strlen($teks) === 3) {
                $j = (int) substr($teks, 0, 1);
                $mnt = (int) substr($teks, 1, 2);
                return impor_detik_ke_jam($j * 3600 + $mnt * 60);
            }
            if (strlen($teks) === 4) {
                $j = (int) substr($teks, 0, 2);
                $mnt = (int) substr($teks, 2, 2);
                return impor_detik_ke_jam($j * 3600 + $mnt * 60);
            }
            if (strlen($teks) === 6) {
                $j = (int) substr($teks, 0, 2);
                $mnt = (int) substr($teks, 2, 2);
                $d = (int) substr($teks, 4, 2);
                return impor_detik_ke_jam($j * 3600 + $mnt * 60 + $d);
            }
            return null;
        }

        $teks = str_replace(['.', ','], ':', $teks);
        if (preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?\s*([ap]\.?m\.?)?$/i', $teks, $m)) {
            $j = (int) $m[1];
            $mnt = (int) $m[2];
            $d = (int) ($m[3] ?? 0);
            $ampm = strtolower(str_replace('.', '', (string) ($m[4] ?? '')));
            if ($ampm === 'pm' && $j < 12) {
                $j += 12;
            }
            if ($ampm === 'am' && $j === 12) {
                $j = 0;
            }
            return impor_detik_ke_jam($j * 3600 + $mnt * 60 + $d);
        }

        $ts = strtotime($teks);
        if ($ts !== false) {
            return date('H:i:s', $ts);
        }
        return null;
    }
}

if (!function_exists('impor_detik_ke_jam')) {
    /** Ubah detik-dalam-hari menjadi H:i:s, atau null bila di luar rentang. */
    function impor_detik_ke_jam(int $detik): ?string
    {
        if ($detik < 0 || $detik >= 86400) {
            return null;
        }
        return sprintf('%02d:%02d:%02d', intdiv($detik, 3600), intdiv($detik % 3600, 60), $detik % 60);
    }
}

if (!function_exists('impor_norm_status_absensi')) {
    /** Normalisasi status kehadiran (hadir/izin/sakit/alpa). */
    function impor_norm_status_absensi(mixed $nilai): ?string
    {
        $teks = impor_normal_kunci((string) $nilai);
        if ($teks === '') {
            return null;
        }
        $peta = [
            'hadir' => 'hadir', 'h' => 'hadir', 'masuk' => 'hadir', 'present' => 'hadir', 'wfo' => 'hadir',
            'izin' => 'izin', 'i' => 'izin', 'cuti' => 'izin', 'leave' => 'izin', 'sakit_izin' => 'izin',
            'sakit' => 'sakit', 's' => 'sakit', 'sick' => 'sakit', 'demam' => 'sakit',
            'alpa' => 'alpa', 'a' => 'alpa', 'tanpa keterangan' => 'alpa', 'mangkir' => 'alpa', 'absen' => 'alpa',
        ];
        return $peta[$teks] ?? null;
    }
}

if (!function_exists('impor_norm_kondisi_kesehatan')) {
    function impor_norm_kondisi_kesehatan(mixed $nilai): ?string
    {
        $teks = impor_normal_kunci(str_replace('_', ' ', (string) $nilai));
        if ($teks === '') {
            return null;
        }
        $peta = [
            'sehat' => 'sehat', 'baik' => 'sehat', 'fit' => 'sehat', 'normal' => 'sehat', 'healthy' => 'sehat',
            'kurang sehat' => 'kurang_sehat', 'kurang' => 'kurang_sehat', 'lemas' => 'kurang_sehat', 'lelah' => 'kurang_sehat',
            'sakit' => 'sakit', 'demam' => 'sakit', 'flu' => 'sakit', 'ill' => 'sakit',
            'pemulihan' => 'pemulihan', 'recovery' => 'pemulihan', 'pulih' => 'pemulihan', 'pasca sakit' => 'pemulihan',
        ];
        return $peta[$teks] ?? null;
    }
}

if (!function_exists('impor_norm_koordinat')) {
    /**
     * Normalkan sepasang nilai koordinat dari berkas.
     * Mengembalikan lat/lng sebagai string 7 desimal bila keduanya sah,
     * atau string kosong bila tidak (agar resolusi teks dipakai).
     *
     * @return array{lat:string,lng:string}
     */
    function impor_norm_koordinat(mixed $lat, mixed $lng): array
    {
        $kosong = ['lat' => '', 'lng' => ''];
        $latS = trim((string) $lat);
        $lngS = trim((string) $lng);
        if ($latS === '' || $lngS === '') {
            return $kosong;
        }
        // Terima koma desimal (mis. "1,2345") -> titik.
        $latN = str_replace(',', '.', $latS);
        $lngN = str_replace(',', '.', $lngS);
        // Tolak nilai yang bukan angka (mis. "abc") atau kosong setelah normalisasi.
        if (!is_numeric($latN) || !is_numeric($lngN)) {
            return $kosong;
        }
        $latN = (float) $latN;
        $lngN = (float) $lngN;
        if (!function_exists('wilayah_koordinat_valid')) {
            return $kosong;
        }
        if (!wilayah_koordinat_valid($latN, $lngN)) {
            return $kosong;
        }
        return [
            'lat' => number_format($latN, 7, '.', ''),
            'lng' => number_format($lngN, 7, '.', ''),
        ];
    }
}

/* ---------------------------------------------------------------
 * 5. Validasi baris
 * ------------------------------------------------------------- */

if (!function_exists('impor_peta_pengguna')) {
    /**
     * Bangun peta pencarian pengguna: email/nama/id -> user_id.
     *
     * @return array<string,int>
     */
    function impor_peta_pengguna(mysqli $conn): array
    {
        $peta = [];
        $res = $conn->query('SELECT id, nama, email FROM users');
        if (!$res) {
            return $peta;
        }
        while ($row = $res->fetch_assoc()) {
            $id = (int) $row['id'];
            $peta['id:' . $id] = $id;
            $email = impor_normal_email((string) $row['email']);
            if ($email !== '') {
                $peta['email:' . $email] = $id;
            }
            $nama = impor_normal_kunci((string) $row['nama']);
            if ($nama !== '') {
                // Jangan timpa bila nama sama (pertahankan yang pertama).
                $peta['nama:' . $nama] ??= $id;
            }
        }
        $res->free();
        return $peta;
    }
}

if (!function_exists('impor_cari_pengguna')) {
    function impor_cari_pengguna(array $peta, string $nilai): ?int
    {
        $teks = trim($nilai);
        if ($teks === '') {
            return null;
        }
        $email = impor_normal_email($teks);
        if (isset($peta['email:' . $email])) {
            return $peta['email:' . $email];
        }
        $norm = impor_normal_kunci($teks);
        if (isset($peta['nama:' . $norm])) {
            return $peta['nama:' . $norm];
        }
        if (ctype_digit($teks) && isset($peta['id:' . (int) $teks])) {
            return $peta['id:' . (int) $teks];
        }
        return null;
    }
}

if (!function_exists('impor_ambil_kolom')) {
    /**
     * Ambil nilai kolom dari satu baris sesuai pemetaan medan.
     *
     * Kompatibel dengan dua bentuk pemetaan:
     *   - lama: medan => indeks kolom (int)
     *   - baru: medan => nama header berkas (string) — diketik manual oleh pengguna
     */
    function impor_ambil_kolom(array $baris, array $pemetaan, string $medan, array $header = []): string
    {
        if (!isset($pemetaan[$medan])) {
            return '';
        }
        $val = $pemetaan[$medan];
        $i = -1;
        if (is_int($val) || (is_string($val) && ctype_digit($val))) {
            $i = (int) $val;
        } elseif (is_string($val) && $val !== '') {
            $valNorm = mb_strtolower(trim($val));
            foreach ($header as $j => $h) {
                if (mb_strtolower(trim((string) $h)) === $valNorm) {
                    $i = $j;
                    break;
                }
            }
        }
        if ($i < 0 || !isset($baris[$i])) {
            return '';
        }
        return trim((string) $baris[$i]);
    }
}

if (!function_exists('impor_validasi')) {
    /**
     * Validasi seluruh baris tanpa menyentuh basis data.
     *
     * @param list<list<string>> $baris
     * @param array<string,int>  $pemetaan
     * @param array<string,int>  $petaPengguna
     * @param array{status_bawaan?:string,kondisi_bawaan?:string} $opsi
     * @return array{ok: bool, galat: list<string>, ringkas: array<string,int>, baris: list<array<string,mixed>>}
     */
    /**
     * Normalisasi pemetaan: ubah nama header menjadi indeks bila masih berbentuk string.
     * @param array<string,int|string> $pemetaan
     * @param list<string> $header
     * @return array<string,int>
     */
    function impor_normalisasi_pemetaan(array $pemetaan, array $header): array
    {
        $out = [];
        foreach ($pemetaan as $medan => $val) {
            if ($val === '' || $val === null) {
                continue;
            }
            if (is_int($val) || (is_string($val) && ctype_digit($val))) {
                $out[$medan] = (int) $val;
                continue;
            }
            $valNorm = mb_strtolower(trim((string) $val));
            if ($valNorm === '') {
                continue;
            }
            foreach ($header as $j => $h) {
                if (mb_strtolower(trim((string) $h)) === $valNorm) {
                    $out[$medan] = $j;
                    break;
                }
            }
        }
        return $out;
    }

    function impor_validasi(array $baris, array $pemetaan, string $jenis, array $petaPengguna, array $opsi = [], array $header = []): array
    {
        // Kompatibel: jika pemetaan berisi nama header, konversi ke indeks.
        $pemetaan = impor_normalisasi_pemetaan($pemetaan, $header);
        $field = impor_field($jenis);
        $galat = [];

        // Bila pengguna dipaksa (mis. karyawan hanya boleh data sendiri),
        // medan "pengguna" tidak perlu dipetakan.
        $paksaUser = isset($opsi['paksa_user_id']) ? (int) $opsi['paksa_user_id'] : 0;
        if ($paksaUser > 0) {
            unset($pemetaan['pengguna']);
        }

        // Kolom wajib harus terpetakan.
        foreach ($field as $kunci => $def) {
            if ($kunci === 'pengguna' && $paksaUser > 0) {
                continue;
            }
            if ($def['wajib'] && !isset($pemetaan[$kunci])) {
                $galat[] = 'Kolom wajib "' . $def['label'] . '" belum dipetakan.';
            }
        }
        if ($galat !== []) {
            return ['ok' => false, 'galat' => $galat, 'ringkas' => [], 'baris' => []];
        }

        $statusBawaan = (string) ($opsi['status_bawaan'] ?? '');
        $hasil = [];
        $nMasuk = $nGagal = 0;

        $nomor = 1; // nomor baris data (1 = baris data pertama)
        foreach ($baris as $r) {
            $nomor++;
            $pesan = [];
            $data = [];

            if ($paksaUser > 0) {
                // Karyawan: paksa pemilik data ke akun yang sedang masuk.
                $data['user_id'] = $paksaUser;
            } else {
                $nilaiUser = impor_ambil_kolom($r, $pemetaan, 'pengguna', $header);
                $userId = impor_cari_pengguna($petaPengguna, $nilaiUser);
                if ($nilaiUser === '') {
                    $pesan[] = 'Kolom pengguna kosong.';
                } elseif ($userId === null) {
                    $pesan[] = 'Pengguna "' . $nilaiUser . '" tidak ditemukan.';
                }
                $data['user_id'] = $userId;
            }

            $tanggal = impor_norm_tanggal(impor_ambil_kolom($r, $pemetaan, 'tanggal', $header));
            if ($tanggal === null) {
                $pesan[] = 'Tanggal tidak sah.';
            } else {
                $data['tanggal'] = $tanggal;
            }

            if ($jenis === 'absensi') {
                $data['jam_masuk'] = impor_norm_jam(impor_ambil_kolom($r, $pemetaan, 'jam_masuk', $header));
                $data['jam_pulang'] = impor_norm_jam(impor_ambil_kolom($r, $pemetaan, 'jam_pulang', $header));
                $status = impor_norm_status_absensi(impor_ambil_kolom($r, $pemetaan, 'status', $header));
                $data['status'] = $status ?? (string) ($opsi['status_bawaan'] ?? 'hadir');
                $data['keterangan'] = impor_ambil_kolom($r, $pemetaan, 'keterangan', $header);
                $data['kegiatan_hari_ini'] = impor_ambil_kolom($r, $pemetaan, 'kegiatan_hari_ini', $header);
                $kondisi = impor_norm_kondisi_kesehatan(impor_ambil_kolom($r, $pemetaan, 'kondisi_kesehatan', $header));
                $data['kondisi_kesehatan'] = $kondisi ?? (string) ($opsi['kondisi_bawaan'] ?? 'sehat');
                $lokasi = trim((string) impor_ambil_kolom($r, $pemetaan, 'lokasi_teks', $header));
                $data['lokasi_teks'] = $lokasi !== '' ? mb_substr($lokasi, 0, 191) : '';
            } else {
                $data['jam_mulai'] = impor_norm_jam(impor_ambil_kolom($r, $pemetaan, 'jam_mulai', $header));
                $data['jam_selesai'] = impor_norm_jam(impor_ambil_kolom($r, $pemetaan, 'jam_selesai', $header));
                $kegiatan = impor_ambil_kolom($r, $pemetaan, 'kegiatan', $header);
                if ($kegiatan === '') {
                    $pesan[] = 'Kolom kegiatan kosong.';
                } elseif (mb_strlen($kegiatan) > 255) {
                    $pesan[] = 'Judul kegiatan melebihi 255 karakter.';
                }
                $data['kegiatan'] = $kegiatan;
                $deskripsi = impor_ambil_kolom($r, $pemetaan, 'deskripsi', $header);
                $data['deskripsi'] = $deskripsi !== '' ? $deskripsi : $kegiatan;
                $data['hasil'] = impor_ambil_kolom($r, $pemetaan, 'hasil', $header);
                $data['kendala'] = impor_ambil_kolom($r, $pemetaan, 'kendala', $header);
                $data['rencana_berikutnya'] = impor_ambil_kolom($r, $pemetaan, 'rencana_berikutnya', $header);
                $data['status'] = in_array($statusBawaan, impor_status_laporan(), true) ? $statusBawaan : 'diperiksa';
                $lokasi = trim((string) impor_ambil_kolom($r, $pemetaan, 'lokasi_teks', $header));
                $data['lokasi_teks'] = $lokasi !== '' ? mb_substr($lokasi, 0, 191) : '';
            }

            // Koordinat asli dari berkas (bila ada). Bila salah satu tidak sah,
            // keduanya diabaikan dan resolusi dilakukan dari teks lokasi.
            $koord = impor_norm_koordinat(
                impor_ambil_kolom($r, $pemetaan, 'garis_lintang', $header),
                impor_ambil_kolom($r, $pemetaan, 'garis_bujur', $header)
            );
            $data['garis_lintang'] = $koord['lat'];
            $data['garis_bujur'] = $koord['lng'];

            $sah = $pesan === [];
            if ($sah) {
                $nMasuk++;
            } else {
                $nGagal++;
            }

            $hasil[] = [
                'nomor' => $nomor,
                'sah' => $sah,
                'pesan' => $pesan,
                'data' => $data,
                'mentah' => array_map(static fn ($v) => (string) $v, $r),
            ];
        }

        return [
            'ok' => true,
            'galat' => [],
            'ringkas' => ['total' => count($hasil), 'sah' => $nMasuk, 'gagal' => $nGagal, 'masuk' => 0, 'dilewati' => 0],
            'baris' => $hasil,
        ];
    }
}

/* ---------------------------------------------------------------
 * 6. Simpan ke basis data
 * ------------------------------------------------------------- */

if (!function_exists('impor_baris_serupa')) {
    /** Cari laporan yang sama (user+tanggal+kegiatan) untuk deteksi duplikat. */
    function impor_baris_serupa(mysqli $conn, int $userId, string $tanggal, string $kegiatan): ?int
    {
        $stmt = $conn->prepare(
            'SELECT id FROM laporan_harian
              WHERE user_id = ? AND tanggal = ? AND kegiatan = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iss', $userId, $tanggal, $kegiatan);
        $stmt->execute();
        $stmt->bind_result($id);
        $ada = $stmt->fetch();
        $stmt->close();
        return $ada ? (int) $id : null;
    }
}

if (!function_exists('impor_commit')) {
    /**
     * Simpan baris hasil validasi ke basis data dalam satu transaksi.
     *
     * @param list<array<string,mixed>> $analisis
     * @param array{duplikat?:string}   $opsi duplikat: 'lewati' | 'perbarui' | 'gandakan'
     * @return array{ok: bool, galat: string, masuk: int, diperbarui: int, dilewati: int, gagal: int, id: list<int>, id_semua: list<int>}
     */
    function impor_commit(mysqli $conn, array $analisis, string $jenis, array $opsi = []): array
    {
        $duplikat = (string) ($opsi['duplikat'] ?? 'lewati');
        if (!in_array($duplikat, ['lewati', 'perbarui', 'gandakan'], true)) {
            $duplikat = 'lewati';
        }

        $masuk = $diperbarui = $dilewati = $gagal = 0;
        $idBaru = [];
        $idTersentuh = [];
        $galat = '';

        /**
         * Resolusi koordinat, urutan prioritas:
         *   1. garis_lintang/garis_bujur asli dari berkas,
         *   2. pencarian master wilayah dari teks lokasi.
         * Bila keduanya gagal, koordinat dikosongkan (NULL) dan dibiarkan
         * untuk proses geokode terpisah SETELAH transaksi ini selesai
         * (Nominatim tidak boleh dipanggil di dalam transaksi).
         */
        $koord = static function (array $d) use ($conn): array {
            $latFile = trim((string) ($d['garis_lintang'] ?? ''));
            $lngFile = trim((string) ($d['garis_bujur'] ?? ''));
            if ($latFile !== '' && $lngFile !== ''
                && function_exists('wilayah_koordinat_valid')
                && wilayah_koordinat_valid((float) $latFile, (float) $lngFile)) {
                return [
                    number_format((float) $latFile, 7, '.', ''),
                    number_format((float) $lngFile, 7, '.', ''),
                    '',
                ];
            }

            $teks = trim((string) ($d['lokasi_teks'] ?? ''));
            if ($teks === '' || !function_exists('wilayah_cari_teks')) {
                return ['', '', ''];
            }
            $w = wilayah_cari_teks($conn, $teks);
            if ($w === null || !wilayah_koordinat_valid($w['garis_lintang'] ?? null, $w['garis_bujur'] ?? null)) {
                return ['', '', ''];
            }
            return [
                number_format((float) $w['garis_lintang'], 7, '.', ''),
                number_format((float) $w['garis_bujur'], 7, '.', ''),
                (string) (int) $w['id'],
            ];
        };

        $conn->begin_transaction();
        try {
            if ($jenis === 'absensi') {
                $sqlBaru = 'INSERT INTO absensi
                    (user_id, tanggal, jam_masuk, jam_pulang, status, keterangan, kegiatan_hari_ini, kondisi_kesehatan,
                     lokasi_teks, garis_lintang, garis_bujur, wilayah_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?,
                            NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))';
                $sqlUpdate = 'UPDATE absensi SET jam_masuk = ?, jam_pulang = ?, status = ?, keterangan = ?,
                    kegiatan_hari_ini = ?, kondisi_kesehatan = ?,
                    lokasi_teks = COALESCE(NULLIF(?, \'\'), lokasi_teks),
                    garis_lintang = COALESCE(NULLIF(?, \'\'), garis_lintang),
                    garis_bujur = COALESCE(NULLIF(?, \'\'), garis_bujur),
                    wilayah_id = COALESCE(NULLIF(?, \'\'), wilayah_id)
                    WHERE user_id = ? AND tanggal = ?';
                $stmtBaru = $conn->prepare($sqlBaru);
                $stmtUpdate = $conn->prepare($sqlUpdate);
                $stmtCek = $conn->prepare('SELECT id FROM absensi WHERE user_id = ? AND tanggal = ? LIMIT 1');
                if (!$stmtBaru || !$stmtUpdate || !$stmtCek) {
                    throw new \RuntimeException('Gagal menyiapkan kueri absensi.');
                }

                foreach ($analisis as $b) {
                    if (!$b['sah']) {
                        $gagal++;
                        continue;
                    }
                    $d = $b['data'];
                    [$latKo, $lngKo, $widKo] = $koord($d);
                    $adaId = impor_absensi_ada($stmtCek, (int) $d['user_id'], (string) $d['tanggal']);
                    if ($adaId !== null && $duplikat === 'lewati') {
                        $dilewati++;
                        continue;
                    }
                    if ($adaId !== null && $duplikat === 'perbarui') {
                        $stmtUpdate->bind_param(
                            'ssssssssssis',
                            $d['jam_masuk'], $d['jam_pulang'], $d['status'], $d['keterangan'],
                            $d['kegiatan_hari_ini'], $d['kondisi_kesehatan'],
                            $d['lokasi_teks'], $latKo, $lngKo, $widKo,
                            $d['user_id'], $d['tanggal']
                        );
                        $stmtUpdate->execute();
                        $diperbarui++;
                        $idTersentuh[] = $adaId;
                        continue;
                    }
                    // Baru, atau duplikat = 'gandakan' tak berlaku (unik). Perbarui bila ada.
                    if ($adaId !== null) {
                        $stmtUpdate->bind_param(
                            'ssssssssssis',
                            $d['jam_masuk'], $d['jam_pulang'], $d['status'], $d['keterangan'],
                            $d['kegiatan_hari_ini'], $d['kondisi_kesehatan'],
                            $d['lokasi_teks'], $latKo, $lngKo, $widKo,
                            $d['user_id'], $d['tanggal']
                        );
                        $stmtUpdate->execute();
                        $diperbarui++;
                        $idTersentuh[] = $adaId;
                        continue;
                    }
                    $stmtBaru->bind_param(
                        'isssssssssss',
                        $d['user_id'], $d['tanggal'], $d['jam_masuk'], $d['jam_pulang'],
                        $d['status'], $d['keterangan'], $d['kegiatan_hari_ini'], $d['kondisi_kesehatan'],
                        $d['lokasi_teks'], $latKo, $lngKo, $widKo
                    );
                    if ($stmtBaru->execute()) {
                        $masuk++;
                        $baruId = (int) $conn->insert_id;
                        $idBaru[] = $baruId;
                        $idTersentuh[] = $baruId;
                    } else {
                        $gagal++;
                    }
                }
                $stmtBaru->close();
                $stmtUpdate->close();
                $stmtCek->close();
            } else {
                $stmtBaru = $conn->prepare(
                    'INSERT INTO laporan_harian
                        (user_id, tanggal, jam_mulai, jam_selesai, kegiatan, deskripsi, hasil, kendala, rencana_berikutnya, status,
                         lokasi_teks, garis_lintang, garis_bujur, wilayah_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                             NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))'
                );
                $stmtUpdate = $conn->prepare(
                    'UPDATE laporan_harian SET jam_mulai = ?, jam_selesai = ?, deskripsi = ?, hasil = ?,
                        kendala = ?, rencana_berikutnya = ?, status = ?,
                        lokasi_teks = COALESCE(NULLIF(?, \'\'), lokasi_teks),
                        garis_lintang = COALESCE(NULLIF(?, \'\'), garis_lintang),
                        garis_bujur = COALESCE(NULLIF(?, \'\'), garis_bujur),
                        wilayah_id = COALESCE(NULLIF(?, \'\'), wilayah_id)
                        WHERE id = ?'
                );
                if (!$stmtBaru || !$stmtUpdate) {
                    throw new \RuntimeException('Gagal menyiapkan kueri laporan.');
                }

                foreach ($analisis as $b) {
                    if (!$b['sah']) {
                        $gagal++;
                        continue;
                    }
                    $d = $b['data'];
                    [$latKo, $lngKo, $widKo] = $koord($d);
                    $serupa = impor_baris_serupa($conn, (int) $d['user_id'], (string) $d['tanggal'], (string) $d['kegiatan']);
                    if ($serupa !== null && $duplikat === 'lewati') {
                        $dilewati++;
                        continue;
                    }
                    if ($serupa !== null && $duplikat === 'perbarui') {
                        $stmtUpdate->bind_param(
                            'sssssssssssi',
                            $d['jam_mulai'], $d['jam_selesai'], $d['deskripsi'], $d['hasil'],
                            $d['kendala'], $d['rencana_berikutnya'], $d['status'],
                            $d['lokasi_teks'], $latKo, $lngKo, $widKo, $serupa
                        );
                        $stmtUpdate->execute();
                        $diperbarui++;
                        $idTersentuh[] = $serupa;
                        continue;
                    }
                    $stmtBaru->bind_param(
                        'isssssssssssss',
                        $d['user_id'], $d['tanggal'], $d['jam_mulai'], $d['jam_selesai'],
                        $d['kegiatan'], $d['deskripsi'], $d['hasil'], $d['kendala'],
                        $d['rencana_berikutnya'], $d['status'],
                        $d['lokasi_teks'], $latKo, $lngKo, $widKo
                    );
                    if ($stmtBaru->execute()) {
                        $masuk++;
                        $baruId = (int) $conn->insert_id;
                        $idBaru[] = $baruId;
                        $idTersentuh[] = $baruId;
                    } else {
                        $gagal++;
                    }
                }
                $stmtBaru->close();
                $stmtUpdate->close();
            }

            $conn->commit();
        } catch (\Throwable $t) {
            $conn->rollback();
            return ['ok' => false, 'galat' => 'Impor dibatalkan: ' . $t->getMessage(),
                    'masuk' => 0, 'diperbarui' => 0, 'dilewati' => 0, 'gagal' => count($analisis),
                    'id' => [], 'id_semua' => []];
        }

        return ['ok' => true, 'galat' => $galat, 'masuk' => $masuk, 'diperbarui' => $diperbarui,
                'dilewati' => $dilewati, 'gagal' => $gagal, 'id' => $idBaru,
                'id_semua' => array_values(array_unique($idTersentuh))];
    }
}

if (!function_exists('impor_absensi_ada')) {
    /** Kembalikan id absensi bila (user,tanggal) sudah ada. */
    function impor_absensi_ada(mysqli_stmt $stmt, int $userId, string $tanggal): ?int
    {
        $stmt->bind_param('is', $userId, $tanggal);
        $stmt->execute();
        $stmt->bind_result($id);
        $ada = $stmt->fetch();
        $stmt->free_result();
        return $ada ? (int) $id : null;
    }
}

/* ---------------------------------------------------------------
 * 7. Riwayat & profil pemetaan
 * ------------------------------------------------------------- */

if (!function_exists('impor_catat_sejarah')) {
    function impor_catat_sejarah(
        mysqli $conn,
        int $userId,
        string $jenis,
        string $namaBerkas,
        int $jumlahBaris,
        int $masuk,
        int $dilewati,
        int $gagal,
        string $ringkasan = '',
        array $idSemua = [],
        string $sumber = ''
    ): void {
        try {
            $idBersih = array_values(array_filter(array_map('intval', $idSemua), static fn ($v) => $v > 0));
            $idJson = $idBersih !== [] ? json_encode($idBersih, JSON_UNESCAPED_UNICODE) : null;
            $sumber = $sumber !== '' ? mb_substr($sumber, 0, 40) : null;

            $stmt = $conn->prepare(
                'INSERT INTO impor_sejarah
                    (user_id, jenis, nama_berkas, jumlah_baris, jumlah_masuk, jumlah_dilewati, jumlah_gagal, ringkasan, id_semua, sumber)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('issiiiisss', $userId, $jenis, $namaBerkas, $jumlahBaris, $masuk, $dilewati, $gagal, $ringkasan, $idJson, $sumber);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $t) {
            // Riwayat bersifat opsional; jangan gagalkan impor.
        }
    }
}

if (!function_exists('impor_hapus_batch')) {
    /**
     * Hapus SATU batch impor beserta baris data yang dibuatnya.
     *
     * Batas aman:
     *   - hanya pemilik riwayat (atau admin) yang boleh menghapus,
     *   - hanya jenis `laporan`/`absensi` (jalur SRM/CID mengganti seluruh
     *     tabel, jadi tidak punya id per-batch),
     *   - hanya id pada kolom `id_semua` yang dibuat batch itu,
     *   - tabel data ditentukan dari jenis, bukan dari masukan pengguna.
     *
     * @return array{ok: bool, pesan: string, dihapus: int}
     */
    function impor_hapus_batch(mysqli $conn, int $sejarahId, int $userId, bool $admin = false): array
    {
        $gagal = static fn (string $p): array => ['ok' => false, 'pesan' => $p, 'dihapus' => 0];

        if ($sejarahId < 1) {
            return $gagal('Riwayat impor tidak sah.');
        }

        $stmt = $conn->prepare(
            'SELECT user_id, jenis, nama_berkas, id_semua FROM impor_sejarah WHERE id = ? LIMIT 1'
        );
        if (!$stmt) {
            return $gagal('Gagal membaca riwayat impor.');
        }
        $stmt->bind_param('i', $sejarahId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $gagal('Riwayat impor tidak ditemukan.');
        }
        if (!$admin && (int) $row['user_id'] !== $userId) {
            return $gagal('Anda hanya dapat menghapus riwayat impor milik sendiri.');
        }

        $jenis = (string) $row['jenis'];
        if (!in_array($jenis, ['laporan', 'absensi'], true)) {
            return $gagal('Impor jenis ini mengganti seluruh tabel sehingga tidak dapat dihapus per-batch.');
        }

        $ids = json_decode((string) ($row['id_semua'] ?? ''), true);
        if (!is_array($ids) || $ids === []) {
            return $gagal('Batch ini tidak menyimpan daftar baris (impor lama sebelum pencatatan id).');
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($ids === []) {
            return $gagal('Daftar baris batch kosong.');
        }

        $tabel = $jenis === 'absensi' ? 'absensi' : 'laporan_harian';
        $placeholder = implode(',', array_fill(0, count($ids), '?'));
        $tipe = str_repeat('i', count($ids));

        $conn->begin_transaction();
        try {
            $del = $conn->prepare("DELETE FROM `{$tabel}` WHERE id IN ({$placeholder})");
            if (!$del) {
                throw new \RuntimeException('Gagal menyiapkan perintah hapus.');
            }
            $del->bind_param($tipe, ...$ids);
            $del->execute();
            $dihapus = (int) $del->affected_rows;
            $del->close();

            $delR = $conn->prepare('DELETE FROM impor_sejarah WHERE id = ?');
            if (!$delR) {
                throw new \RuntimeException('Gagal menyiapkan perintah hapus riwayat.');
            }
            $delR->bind_param('i', $sejarahId);
            $delR->execute();
            $delR->close();

            $conn->commit();
        } catch (\Throwable $t) {
            $conn->rollback();
            return $gagal('Gagal menghapus batch: ' . $t->getMessage());
        }

        return [
            'ok' => true,
            'pesan' => sprintf('Batch "%s" dihapus: %d baris data dibatalkan.', (string) $row['nama_berkas'], $dihapus),
            'dihapus' => $dihapus,
        ];
    }
}

if (!function_exists('impor_daftar_profil')) {
    /** @return list<array<string,mixed>> */
    function impor_daftar_profil(mysqli $conn, int $userId, string $jenis): array
    {
        $hasil = [];
        $stmt = $conn->prepare(
            'SELECT id, nama, pemetaan FROM impor_profil WHERE user_id = ? AND jenis = ? ORDER BY nama'
        );
        if (!$stmt) {
            return $hasil;
        }
        $stmt->bind_param('is', $userId, $jenis);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $pemetaan = json_decode((string) $row['pemetaan'], true);
            $hasil[] = [
                'id' => (int) $row['id'],
                'nama' => (string) $row['nama'],
                'pemetaan' => is_array($pemetaan) ? $pemetaan : [],
            ];
        }
        $stmt->close();
        return $hasil;
    }
}

if (!function_exists('impor_simpan_profil')) {
    function impor_simpan_profil(mysqli $conn, int $userId, string $jenis, string $nama, array $pemetaan): bool
    {
        $nama = trim($nama);
        if ($nama === '' || mb_strlen($nama) > 100) {
            return false;
        }
        $json = json_encode($pemetaan, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $stmt = $conn->prepare(
            'INSERT INTO impor_profil (user_id, jenis, nama, pemetaan) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE pemetaan = VALUES(pemetaan)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('isss', $userId, $jenis, $nama, $json);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}
