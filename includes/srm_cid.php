<?php
/**
 * includes/srm_cid.php
 * Impor & normalisasi dataset Social Relations Management (SRM) dan
 * Community Involvement/Development (CID) dari berkas Excel.
 *
 * Fungsi murni (tanpa efek samping) dipisahkan agar mudah diuji:
 *   srm_cid_bersih()      -> teks bersih / null
 *   srm_cid_angka()       -> angka (menangani format Indonesia)
 *   srm_cid_tanggal()     -> 'Y-m-d' dari serial Excel atau teks
 *   cid_bulan_angka()     -> 1..12 dari nama bulan
 *   srm_cid_norm_header() -> kunci kolom ternormalisasi
 *
 * Impor utama:
 *   srm_cid_impor($conn, $berkas, $jenis)  -> ringkasan hasil
 */

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/* ===============================================================
 * 1. FUNGSI MURNI
 * ============================================================= */

if (!function_exists('srm_cid_bersih')) {
    /** Bersihkan teks: pangkas spasi; kosong -> null. */
    function srm_cid_bersih(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if (is_bool($nilai)) {
            return $nilai ? '1' : '0';
        }
        $s = trim((string) $nilai);
        return $s === '' ? null : $s;
    }
}

if (!function_exists('srm_cid_angka')) {
    /**
     * Ubah nilai menjadi angka (float) atau null.
     * Menangani format Indonesia ("1.234.567", "1,5") dan format umum.
     */
    function srm_cid_angka(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }
        if (is_int($nilai) || is_float($nilai)) {
            return (float) $nilai;
        }
        $s = trim((string) $nilai);
        if ($s === '') {
            return null;
        }
        // Abaikan sel berisi rumus (mis. "=R2+S2+..."): bukan angka tersimpan
        if (str_starts_with($s, '=')) {
            return null;
        }
        // Sisakan digit, titik, koma, dan tanda minus
        $s = preg_replace('/[^0-9.,\-]/u', '', $s) ?? '';
        if ($s === '' || $s === '-') {
            return null;
        }

        $adaTitik = str_contains($s, '.');
        $adaKoma  = str_contains($s, ',');

        if ($adaTitik && $adaKoma) {
            // Titik = pemisah ribuan, koma = desimal
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($adaKoma) {
            $s = str_replace(',', '.', $s);
        } elseif ($adaTitik && preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s) === 1) {
            // Pola ribuan: 1.234 / 12.345.678
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? (float) $s : null;
    }
}

if (!function_exists('srm_cid_tanggal')) {
    /**
     * Ubah nilai menjadi 'Y-m-d' atau null.
     * Mendukung serial tanggal Excel dan beberapa format teks.
     */
    function srm_cid_tanggal(mixed $nilai): ?string
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_int($nilai) || is_float($nilai) || (is_string($nilai) && is_numeric($nilai))) {
            $f = (float) $nilai;
            if ($f > 0 && $f < 2958466) {   // batas aman serial tanggal Excel
                try {
                    return ExcelDate::excelToDateTimeObject($f)->format('Y-m-d');
                } catch (Throwable $t) {
                    // lanjut ke upaya teks
                }
            }
        }

        $s = trim((string) $nilai);
        if ($s === '') {
            return null;
        }
        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y', 'm/d/Y'] as $fmt) {
            $dt = DateTime::createFromFormat($fmt, $s);
            if ($dt instanceof DateTime) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}

if (!function_exists('cid_bulan_angka')) {
    /** Nama bulan (Jan..Des / Januari..Desember) -> angka 1..12. */
    function cid_bulan_angka(?string $bulan): ?int
    {
        if ($bulan === null) {
            return null;
        }
        $b = strtolower(trim($bulan));
        if ($b === '') {
            return null;
        }
        $peta = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5,
            'jun' => 6, 'jul' => 7, 'agu' => 8, 'aug' => 8, 'ags' => 8, 'sep' => 9,
            'okt' => 10, 'oct' => 10, 'nov' => 11, 'des' => 12, 'dec' => 12,
        ];
        return $peta[substr($b, 0, 3)] ?? null;
    }
}

if (!function_exists('srm_cid_norm_header')) {
    /** Normalisasi label header menjadi kunci kolom. */
    function srm_cid_norm_header(string $label): string
    {
        $s = mb_strtolower(trim($label));
        $s = str_replace('%', ' persen ', $s);
        $s = preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
        return $s;
    }
}

/* ===============================================================
 * 2. PEMETAAN HEADER -> KOLOM TABEL
 * ============================================================= */

if (!function_exists('srm_peta_kolom')) {
    /** @return array<string,string> kunci-header-ternormalisasi => nama kolom */
    function srm_peta_kolom(): array
    {
        return [
            'tahunintervensi'                => 'tahun_intervensi',
            'areafield'                      => 'area_field',
            'tanggal'                        => 'tanggal',
            'waktu'                          => 'waktu',
            'pic'                            => 'pic',
            'kategorikegiatan'               => 'kategori_kegiatan',
            'klasifikasiissue'               => 'klasifikasi_issue',
            'judulissue'                     => 'judul_issue',
            'detailissue'                    => 'detail_issue',
            'kelurahdesa'                    => 'kelurahan',
            'kelurahandesa'                  => 'kelurahan',
            'kecamatan'                      => 'kecamatan',
            'kabupatenkota'                  => 'kabupaten_kota',
            'jenisstakeholder'               => 'jenis_stakeholder',
            'stakeholderyangmenghadiri'      => 'stakeholder_hadir',
            'actionplan'                     => 'action_plan',
            'timphr'                         => 'tim_phr',
            'valuecreation'                  => 'value_creation',
            'status'                         => 'status',
            'statuspersen'                   => 'persen_status',
            'dokumentasi'                    => 'dokumentasi',
        ];
    }
}

if (!function_exists('cid_peta_kolom')) {
    /** @return array<string,string> kunci-header-ternormalisasi => nama kolom */
    function cid_peta_kolom(): array
    {
        return [
            'tahunintervensi'                     => 'tahun_intervensi',
            'judulprogram'                        => 'judul_program',
            'pilarprogram'                        => 'pilar_program',
            'desa'                                => 'desa',
            'desaasetoperasi'                     => 'desa_aset_operasi',
            'desarkbor'                           => 'desa_rk_bor',
            'desakonfliksosial'                   => 'desa_konflik_sosial',
            'kodedesa'                            => 'kode_desa',
            'kecamatan'                           => 'kecamatan',
            'kodekecamatan'                       => 'kode_kecamatan',
            'kabupatenkota'                       => 'kabupaten_kota',
            'kodekabupatenkota'                   => 'kode_kabupaten_kota',
            'kelompokdiperdayakan'                => 'kelompok_diberdayakan',
            'kelompokdiberdayakan'                => 'kelompok_diberdayakan',
            'totalpenerimamanfaatlangsung'        => 'total_penerima_manfaat',
            'perempuan'                           => 'perempuan',
            'lakilaki'                            => 'laki_laki',
            'ibuhamil'                            => 'ibu_hamil',
            'balita'                              => 'balita',
            'disabilitas'                         => 'disabilitas',
            'potensipeningkatanekonomibulan'      => 'potensi_peningkatan_ekonomi',
            'potensipenghematanoperasionalkelompok' => 'potensi_penghematan_operasional',
            'penanamanpohonbibit'                 => 'penanaman_pohon',
            'penanamanpohon'                      => 'penanaman_pohon',
            'luaspenanamanha'                     => 'luas_penanaman_ha',
            'sampahterkelolahkgbulan'             => 'sampah_terkelola_kg',
            'sampahterkelolakgbulan'              => 'sampah_terkelola_kg',
            'peningkatankualitaskesehatan'        => 'peningkatan_kualitas_kesehatan',
            'penyerapanpenciptaanlapangankerja'   => 'penyerapan_lapangan_kerja',
            'peningkatankapasitas'                => 'peningkatan_kapasitas',
            'peningkatankapasitaspelatihan'       => 'peningkatan_kapasitas',
            'pembentukankelompokbaru'             => 'pembentukan_kelompok_baru',
            'kerjasamastakeholder'                => 'kerjasama_stakeholder',
            'bulan'                               => 'bulan',
            'monthnumber'                         => 'bulan_angka',
            'program'                             => 'program',
            'anggaran'                            => 'anggaran',
        ];
    }
}

/* ===============================================================
 * 3. PEMBACAAN LEMBAR
 * ============================================================= */

if (!function_exists('srm_cid_cari_lembar')) {
    /** Temukan lembar berdasarkan nama (tanpa peduli huruf besar/kecil). */
    function srm_cid_cari_lembar($buku, array $kandidat)
    {
        foreach ($buku->getWorksheetIterator() as $lembar) {
            foreach ($kandidat as $nama) {
                if (strcasecmp(trim((string) $lembar->getTitle()), $nama) === 0) {
                    return $lembar;
                }
            }
        }
        return null;
    }
}

if (!function_exists('srm_cid_deteksi_header')) {
    /**
     * Deteksi baris header pada 12 baris pertama dan petakan kolom.
     * @return array{baris:int, kolom:array<string,int>}
     */
    function srm_cid_deteksi_header($lembar, array $peta): array
    {
        $maksBaris  = min((int) $lembar->getHighestRow(), 12);
        $maksKolom  = Coordinate::columnIndexFromString((string) $lembar->getHighestColumn());
        $terbaik    = ['baris' => 0, 'kolom' => []];

        for ($r = 1; $r <= $maksBaris; $r++) {
            $kolom = [];
            for ($c = 1; $c <= $maksKolom; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c) . $r;
                $label = srm_cid_norm_header((string) $lembar->getCell($coord)->getValue());
                if ($label !== '' && isset($peta[$label]) && !in_array($peta[$label], $kolom, true)) {
                    $kolom[$peta[$label]] = $c;
                }
            }
            if (count($kolom) > count($terbaik['kolom'])) {
                $terbaik = ['baris' => $r, 'kolom' => $kolom];
            }
        }

        return $terbaik;
    }
}

if (!function_exists('srm_cid_nilai_sel')) {
    /**
     * Ambil nilai sebuah sel berdasar nomor kolom & baris.
     * Bila sel berisi RUMUS, kembalikan nilai hasil hitung yang tersimpan
     * di berkas (cache), bukan teks rumusnya.
     */
    function srm_cid_nilai_sel($lembar, int $kolom, int $baris): mixed
    {
        $sel = $lembar->getCell(Coordinate::stringFromColumnIndex($kolom) . $baris);
        $nilai = $sel->getValue();
        if (is_string($nilai) && str_starts_with(ltrim($nilai), '=')) {
            return $sel->getOldCalculatedValue();
        }
        return $nilai;
    }
}

/* ===============================================================
 * 3b. PEMBANTU TAMPILAN
 * ============================================================= */

if (!function_exists('srm_cid_angka_id')) {
    /** Angka dengan pemisah ribuan gaya Indonesia; null -> '-'. */
    function srm_cid_angka_id(mixed $nilai, int $desimal = 0): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }
        return number_format((float) $nilai, $desimal, ',', '.');
    }
}

if (!function_exists('srm_cid_rupiah')) {
    /** Nilai rupiah ringkas: "Rp 1,2 M" / "Rp 5,0 jt" / "Rp 250.000". */
    function srm_cid_rupiah(mixed $nilai): string
    {
        if ($nilai === null || $nilai === '') {
            return '-';
        }
        $n = (float) $nilai;
        $a = abs($n);
        if ($a >= 1_000_000_000_000) {
            return 'Rp ' . number_format($n / 1_000_000_000_000, 2, ',', '.') . ' T';
        }
        if ($a >= 1_000_000_000) {
            return 'Rp ' . number_format($n / 1_000_000_000, 2, ',', '.') . ' M';
        }
        if ($a >= 1_000_000) {
            return 'Rp ' . number_format($n / 1_000_000, 1, ',', '.') . ' jt';
        }
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}

/* ===============================================================
 * 4. IMPOR UTAMA
 * ============================================================= */

if (!function_exists('srm_cid_impor_srm')) {
    /** Impor lembar SRM (ganti-semua). @return array<string,mixed> */
    function srm_cid_impor_srm(mysqli $conn, $buku): array
    {
        $lembar = srm_cid_cari_lembar($buku, ['SRM']);
        if ($lembar === null) {
            return ['ok' => false, 'pesan' => 'Lembar "SRM" tidak ditemukan.', 'masuk' => 0, 'gagal' => 0];
        }

        $deteksi = srm_cid_deteksi_header($lembar, srm_peta_kolom());
        $hdr = (int) $deteksi['baris'];
        $kolom = $deteksi['kolom'];

        if ($hdr === 0 || $kolom === []) {
            return ['ok' => false, 'pesan' => 'Header lembar SRM tidak dikenali.', 'masuk' => 0, 'gagal' => 0];
        }

        $teks = ['area_field', 'waktu', 'pic', 'kategori_kegiatan', 'klasifikasi_issue',
                 'judul_issue', 'detail_issue', 'kelurahan', 'kecamatan', 'kabupaten_kota',
                 'jenis_stakeholder', 'stakeholder_hadir', 'action_plan', 'tim_phr',
                 'value_creation', 'status', 'dokumentasi'];

        $tipe = 'i' . str_repeat('s', 17) . 'dsi';

        $urut = ['tahun_intervensi', 'tanggal', 'area_field', 'waktu', 'pic', 'kategori_kegiatan',
                 'klasifikasi_issue', 'judul_issue', 'detail_issue', 'kelurahan', 'kecamatan',
                 'kabupaten_kota', 'jenis_stakeholder', 'stakeholder_hadir', 'action_plan',
                 'tim_phr', 'value_creation', 'status', 'persen_status', 'dokumentasi', 'baris_sumber'];

        $sql = 'INSERT INTO srm_kegiatan (' . implode(', ', $urut) . ') VALUES ('
            . implode(', ', array_fill(0, count($urut), '?')) . ')';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'pesan' => 'Gagal menyiapkan perintah: ' . $conn->error, 'masuk' => 0, 'gagal' => 0];
        }

        $conn->begin_transaction();
        $masuk = 0;
        $gagal = 0;

        try {
            $conn->query('DELETE FROM srm_kegiatan');

            $barisAkhir = (int) $lembar->getHighestRow();
            for ($r = $hdr + 1; $r <= $barisAkhir; $r++) {
                // Lewati baris kosong
                $adaIsi = false;
                foreach ($kolom as $c) {
                    $raw = srm_cid_nilai_sel($lembar, $c, $r);
                    if ($raw !== null && trim((string) $raw) !== '') {
                        $adaIsi = true;
                        break;
                    }
                }
                if (!$adaIsi) {
                    continue;
                }

                $nilai = [];
                $nilai['tahun_intervensi'] = isset($kolom['tahun_intervensi'])
                    ? srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom['tahun_intervensi'], $r)) : null;
                $nilai['tahun_intervensi'] = $nilai['tahun_intervensi'] !== null ? (int) $nilai['tahun_intervensi'] : null;
                $nilai['tanggal'] = isset($kolom['tanggal'])
                    ? srm_cid_tanggal(srm_cid_nilai_sel($lembar, $kolom['tanggal'], $r)) : null;

                foreach ($teks as $f) {
                    $nilai[$f] = isset($kolom[$f])
                        ? srm_cid_bersih(srm_cid_nilai_sel($lembar, $kolom[$f], $r)) : null;
                }

                $persen = isset($kolom['persen_status'])
                    ? srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom['persen_status'], $r)) : null;
                $nilai['persen_status'] = $persen;
                $nilai['baris_sumber'] = $r;

                // Tanpa judul/area sama sekali -> lewati
                if (($nilai['judul_issue'] ?? null) === null && ($nilai['area_field'] ?? null) === null) {
                    continue;
                }

                $vals = [];
                foreach ($urut as $f) {
                    $vals[] = $nilai[$f] ?? null;
                }

                try {
                    $stmt->bind_param($tipe, ...$vals);
                    $stmt->execute();
                    $masuk++;
                } catch (Throwable $t) {
                    $gagal++;
                    error_log('srm import baris ' . $r . ': ' . $t->getMessage());
                }
            }

            $conn->commit();
        } catch (Throwable $t) {
            $conn->rollback();
            $stmt->close();
            return ['ok' => false, 'pesan' => 'Impor SRM gagal: ' . $t->getMessage(), 'masuk' => 0, 'gagal' => 0];
        }

        $stmt->close();
        return ['ok' => true, 'pesan' => 'Impor SRM selesai.', 'header_baris' => $hdr, 'masuk' => $masuk, 'gagal' => $gagal];
    }
}

if (!function_exists('srm_cid_impor_cid')) {
    /** Impor lembar CID (ganti-semua). @return array<string,mixed> */
    function srm_cid_impor_cid(mysqli $conn, $buku): array
    {
        $lembar = srm_cid_cari_lembar($buku, ['C&D', 'CID', 'CND']);
        if ($lembar === null) {
            return ['ok' => false, 'pesan' => 'Lembar "C&D"/"CID" tidak ditemukan.', 'masuk' => 0, 'gagal' => 0];
        }

        $deteksi = srm_cid_deteksi_header($lembar, cid_peta_kolom());
        $hdr = (int) $deteksi['baris'];
        $kolom = $deteksi['kolom'];

        if ($hdr === 0 || $kolom === []) {
            return ['ok' => false, 'pesan' => 'Header lembar CID tidak dikenali.', 'masuk' => 0, 'gagal' => 0];
        }

        $urut = ['tahun_intervensi', 'judul_program', 'pilar_program', 'desa', 'desa_aset_operasi',
                 'desa_rk_bor', 'desa_konflik_sosial', 'kode_desa', 'kecamatan', 'kode_kecamatan',
                 'kabupaten_kota', 'kode_kabupaten_kota', 'kelompok_diberdayakan',
                 'total_penerima_manfaat', 'perempuan', 'laki_laki', 'ibu_hamil', 'balita',
                 'disabilitas', 'potensi_peningkatan_ekonomi', 'potensi_penghematan_operasional',
                 'penanaman_pohon', 'luas_penanaman_ha', 'sampah_terkelola_kg',
                 'peningkatan_kualitas_kesehatan', 'penyerapan_lapangan_kerja',
                 'peningkatan_kapasitas', 'pembentukan_kelompok_baru', 'kerjasama_stakeholder',
                 'bulan', 'bulan_angka', 'program', 'anggaran', 'baris_sumber'];

        $teks = ['judul_program', 'pilar_program', 'desa', 'desa_aset_operasi', 'desa_rk_bor',
                 'desa_konflik_sosial', 'kode_desa', 'kecamatan', 'kode_kecamatan', 'kabupaten_kota',
                 'kode_kabupaten_kota', 'kelompok_diberdayakan', 'kerjasama_stakeholder', 'bulan', 'program'];
        $bulat = ['total_penerima_manfaat', 'perempuan', 'laki_laki', 'ibu_hamil', 'balita', 'disabilitas',
                  'penanaman_pohon', 'peningkatan_kualitas_kesehatan', 'penyerapan_lapangan_kerja',
                  'peningkatan_kapasitas', 'pembentukan_kelompok_baru'];
        $desimal = ['potensi_peningkatan_ekonomi', 'potensi_penghematan_operasional',
                    'luas_penanaman_ha', 'sampah_terkelola_kg', 'anggaran'];

        $tipe = 'i' . str_repeat('s', 12) . str_repeat('i', 6) . 'dd' . 'i' . 'dd'
            . str_repeat('i', 4) . 'ssi' . 'sdi';

        $sql = 'INSERT INTO cid_program (' . implode(', ', $urut) . ') VALUES ('
            . implode(', ', array_fill(0, count($urut), '?')) . ')';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'pesan' => 'Gagal menyiapkan perintah: ' . $conn->error, 'masuk' => 0, 'gagal' => 0];
        }

        $conn->begin_transaction();
        $masuk = 0;
        $gagal = 0;

        try {
            $conn->query('DELETE FROM cid_program');

            $barisAkhir = (int) $lembar->getHighestRow();
            for ($r = $hdr + 1; $r <= $barisAkhir; $r++) {
                $adaIsi = false;
                foreach ($kolom as $c) {
                    $raw = srm_cid_nilai_sel($lembar, $c, $r);
                    if ($raw !== null && trim((string) $raw) !== '') {
                        $adaIsi = true;
                        break;
                    }
                }
                if (!$adaIsi) {
                    continue;
                }

                $nilai = [];
                $nilai['tahun_intervensi'] = isset($kolom['tahun_intervensi'])
                    ? srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom['tahun_intervensi'], $r)) : null;
                $nilai['tahun_intervensi'] = $nilai['tahun_intervensi'] !== null ? (int) $nilai['tahun_intervensi'] : null;

                foreach ($teks as $f) {
                    $nilai[$f] = isset($kolom[$f])
                        ? srm_cid_bersih(srm_cid_nilai_sel($lembar, $kolom[$f], $r)) : null;
                }
                foreach ($bulat as $f) {
                    $a = isset($kolom[$f]) ? srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom[$f], $r)) : null;
                    $nilai[$f] = $a !== null ? (int) round($a) : null;
                }
                foreach ($desimal as $f) {
                    $nilai[$f] = isset($kolom[$f]) ? srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom[$f], $r)) : null;
                }

                // "Total Penerima Manfaat Langsung" sering berupa RUMUS
                // (=Perempuan+Laki-Laki+Ibu Hamil+Balita+Disabilitas).
                // Bila tidak terbaca sebagai angka, hitung dari komponennya.
                if (($nilai['total_penerima_manfaat'] ?? null) === null) {
                    $jumlahKomponen = 0;
                    $adaKomponen = false;
                    foreach (['perempuan', 'laki_laki', 'ibu_hamil', 'balita', 'disabilitas'] as $k) {
                        if (($nilai[$k] ?? null) !== null) {
                            $jumlahKomponen += (int) $nilai[$k];
                            $adaKomponen = true;
                        }
                    }
                    if ($adaKomponen) {
                        $nilai['total_penerima_manfaat'] = $jumlahKomponen;
                    }
                }

                $nilai['bulan_angka'] = isset($kolom['bulan_angka'])
                    ? (int) (srm_cid_angka(srm_cid_nilai_sel($lembar, $kolom['bulan_angka'], $r)) ?? 0) : null;
                if (($nilai['bulan_angka'] === null || $nilai['bulan_angka'] === 0) && ($nilai['bulan'] ?? null) !== null) {
                    $nilai['bulan_angka'] = cid_bulan_angka($nilai['bulan']);
                }
                if ($nilai['bulan_angka'] === 0) {
                    $nilai['bulan_angka'] = null;
                }
                $nilai['baris_sumber'] = $r;

                if (($nilai['judul_program'] ?? null) === null && ($nilai['pilar_program'] ?? null) === null) {
                    continue;
                }

                $vals = [];
                foreach ($urut as $f) {
                    $vals[] = $nilai[$f] ?? null;
                }

                try {
                    $stmt->bind_param($tipe, ...$vals);
                    $stmt->execute();
                    $masuk++;
                } catch (Throwable $t) {
                    $gagal++;
                    error_log('cid import baris ' . $r . ': ' . $t->getMessage());
                }
            }

            $conn->commit();
        } catch (Throwable $t) {
            $conn->rollback();
            $stmt->close();
            return ['ok' => false, 'pesan' => 'Impor CID gagal: ' . $t->getMessage(), 'masuk' => 0, 'gagal' => 0];
        }

        $stmt->close();
        return ['ok' => true, 'pesan' => 'Impor CID selesai.', 'header_baris' => $hdr, 'masuk' => $masuk, 'gagal' => $gagal];
    }
}

if (!function_exists('srm_cid_impor')) {
    /**
     * Impor berkas Excel SRM/CID (ganti-semua per jenis).
     *
     * @param string $jenis 'semua' | 'srm' | 'cid'
     * @return array<string,mixed> ringkasan
     */
    function srm_cid_impor(mysqli $conn, string $berkas, string $jenis = 'semua'): array
    {
        if (!is_file($berkas)) {
            return ['ok' => false, 'pesan' => 'Berkas tidak ditemukan.'];
        }

        // Muat pustaka PhpSpreadsheet (Composer) bila belum tersedia.
        if (!class_exists(IOFactory::class)) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) {
                return ['ok' => false, 'pesan' => 'Pustaka PhpSpreadsheet tidak ditemukan pada folder vendor.'];
            }
            require_once $autoload;
        }

        try {
            $reader = IOFactory::createReaderForFile($berkas);
            $reader->setReadDataOnly(true);
            $buku = $reader->load($berkas);
        } catch (Throwable $t) {
            return ['ok' => false, 'pesan' => 'Gagal membaca berkas: ' . $t->getMessage()];
        }

        $hasil = ['ok' => true, 'pesan' => 'Impor selesai.'];
        if ($jenis === 'semua' || $jenis === 'srm') {
            $hasil['srm'] = srm_cid_impor_srm($conn, $buku);
        }
        if ($jenis === 'semua' || $jenis === 'cid') {
            $hasil['cid'] = srm_cid_impor_cid($conn, $buku);
        }

        $buku->disconnectWorksheets();
        unset($buku);

        return $hasil;
    }
}
