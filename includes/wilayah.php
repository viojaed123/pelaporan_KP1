<?php
/**
 * includes/wilayah.php
 * Pemetaan lokasi (peta) untuk seluruh data aplikasi.
 *
 * Isi utama:
 *   - wilayah_norm()          menyamakan ejaan nama wilayah
 *   - wilayah_peta_url()      tautan Google Maps (cek lokasi langsung)
 *   - wilayah_geokode()       ambil koordinat dari OpenStreetMap (Nominatim)
 *   - wilayah_cari()          cari koordinat terbaik dari nama kab/kec/desa
 *   - wilayah_resolusi_tabel() isi garis_lintang/garis_bujur/wilayah_id
 *
 * Berkas ini sengaja hanya berisi fungsi murni + pembantu I/O terisolasi,
 * agar mudah diuji (lihat tests/WilayahTest.php).
 */

declare(strict_types=1);

if (!defined('APP_NAMA')) {
    require_once __DIR__ . '/config.php';
}

/** User-Agent resmi untuk Nominatim (wajib agar tidak diblokir). */
if (!defined('WILAYAH_NOMINATIM_UA')) {
    define('WILAYAH_NOMINATIM_UA', 'PelaporanPHR/1.0 (admin@pelaporan.local)');
}

/* ===============================================================
 * 1. NORMALISASI
 * ============================================================= */

if (!function_exists('wilayah_norm')) {
    /**
     * Normalisasi nama wilayah agar varian ejaan menyatu:
     * huruf kecil, tanpa tanda baca/awalan administratif, spasi tunggal.
     */
    function wilayah_norm(string $teks): string
    {
        $s = mb_strtolower(trim($teks), 'UTF-8');
        if ($s === '') {
            return '';
        }
        // Buang isi dalam tanda kurung + ganti pemisah baris dengan spasi
        $s = preg_replace('/\([^)]*\)/u', ' ', $s) ?? $s;
        $s = str_replace(["\r", "\n", "\t", "\u{00A0}", '/', '\\'], ' ', $s);
        // Buang awalan administratif sebagai kata utuh
        $s = preg_replace('/\b(kecamatan|kec|kelurahan|kel|kepenghuluan|desa|kabupaten|kab|esa)\b/u', ' ', $s) ?? $s;
        // Buang tanda baca
        $s = preg_replace('/[^a-z0-9 ]+/u', ' ', $s) ?? $s;
        // Rapatkan spasi
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('wilayah_bersih_nama')) {
    /** Bersihkan teks nama untuk ditampilkan (tanpa awalan "Desa "/"Kel. "). */
    function wilayah_bersih_nama(string $teks): string
    {
        $s = trim((string) $teks);
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        $s = preg_replace('/^\s*(desa|kelurahan|kel\.?|kepenghuluan|kampung|kecamatan|kec\.?)\s+/iu', '', $s) ?? $s;
        return trim($s);
    }
}

if (!function_exists('wilayah_unik')) {
    /**
     * Bersihkan + deduplikasi daftar nilai wilayah (mis. daftar kabupaten
     * untuk penyaring) tanpa peduli besar-kecil huruf maupun spasi berlebih.
     * Mencegah nilai seperti "Rokan Hilir" dan "rokan  hilir" tampil dua kali.
     *
     * @param list<string> $nilai nilai mentah dari basis data
     * @return list<string> nilai unik, diurutkan tanpa peduli besar-kecil huruf
     */
    function wilayah_unik(array $nilai): array
    {
        $lihat = [];
        $hasil = [];
        foreach ($nilai as $v) {
            $v = trim((string) $v);
            if ($v === '') {
                continue;
            }
            $norm = wilayah_norm($v);
            if ($norm === '' || isset($lihat[$norm])) {
                continue;
            }
            $lihat[$norm] = true;
            $hasil[] = $v;
        }
        natcasesort($hasil);
        return array_values($hasil);
    }
}

/* ===============================================================
 * 2. TAUTAN PETA (Google Maps) — untuk "cek lokasi langsung"
 * ============================================================= */

if (!function_exists('wilayah_peta_url')) {
    /**
     * Bangun tautan pencarian Google Maps dari potongan alamat.
     * Mengembalikan '' bila tidak ada potongan yang berarti.
     */
    function wilayah_peta_url(?string $lokasi = null, array $bagian = []): string
    {
        $potongan = [];
        if ($lokasi !== null && trim($lokasi) !== '') {
            $potongan[] = trim($lokasi);
        }
        foreach ($bagian as $b) {
            if (is_string($b) && trim($b) !== '') {
                $potongan[] = trim($b);
            }
        }
        // Buang duplikat (tanpa peduli huruf besar/kecil)
        $unik = [];
        $lihat = [];
        foreach ($potongan as $p) {
            $kunci = mb_strtolower($p, 'UTF-8');
            if (!isset($lihat[$kunci])) {
                $lihat[$kunci] = true;
                $unik[] = $p;
            }
        }
        if ($unik === []) {
            return '';
        }
        return 'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode(implode(', ', $unik));
    }
}

if (!function_exists('wilayah_titik_url')) {
    /** Tautan Google Maps ke koordinat pasti (lat,lng). */
    function wilayah_titik_url(float $lat, float $lng): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode(number_format($lat, 7, '.', '') . ',' . number_format($lng, 7, '.', ''));
    }
}

if (!function_exists('wilayah_koordinat_valid')) {
    /** Periksa rentang koordinat yang sah. */
    function wilayah_koordinat_valid(mixed $lat, mixed $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return false;
        }
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        $la = (float) $lat;
        $ln = (float) $lng;
        return $la >= -90 && $la <= 90 && $ln >= -180 && $ln <= 180 && !($la === 0.0 && $ln === 0.0);
    }
}

/* ===============================================================
 * 3. GEOKODE (OpenStreetMap Nominatim)
 * ============================================================= */

if (!function_exists('wilayah_geokode')) {
    /**
     * Cari koordinat satu alamat via Nominatim.
     * @return array{lat:float,lon:float,display:string,tipe:string}|null
     */
    function wilayah_geokode(string $q, int $timeout = 20): ?array
    {
        $q = trim($q);
        if ($q === '' || !function_exists('curl_init')) {
            return null;
        }
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'             => $q,
            'format'        => 'jsonv2',
            'limit'         => 1,
            'countrycodes'  => 'id',
            'addressdetails' => 0,
        ]);

        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT      => WILAYAH_NOMINATIM_UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body = curl_exec($ch);
        $kode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $kode !== 200) {
            return null;
        }
        $data = json_decode((string) $body, true);
        if (!is_array($data) || $data === []) {
            return null;
        }
        $r = $data[0];
        if (!isset($r['lat'], $r['lon'])) {
            return null;
        }
        $lat = (float) $r['lat'];
        $lon = (float) $r['lon'];
        if (!wilayah_koordinat_valid($lat, $lon)) {
            return null;
        }
        return [
            'lat'     => $lat,
            'lon'     => $lon,
            'display' => (string) ($r['display_name'] ?? ''),
            'tipe'    => (string) ($r['type'] ?? ''),
        ];
    }
}

/* ===============================================================
 * 4. PENYIMPANAN
 * ============================================================= */

if (!function_exists('wilayah_simpan')) {
    /**
     * Simpan/perbarui satu baris wilayah (kunci: level+nama_norm+kabupaten).
     * @return int id wilayah (0 bila gagal)
     */
    function wilayah_simpan(
        mysqli $conn,
        string $level,
        string $nama,
        ?string $kabupaten,
        ?string $kecamatan,
        ?float $lat,
        ?float $lng,
        string $sumber = 'nominatim',
        string $akurasi = 'sedang'
    ): int {
        $norm = wilayah_norm($nama);
        if ($norm === '' || !in_array($level, ['kabupaten', 'kecamatan', 'desa'], true)) {
            return 0;
        }
        $kab = $kabupaten !== null ? trim($kabupaten) : '';

        $sql = 'INSERT INTO wilayah
                    (level, nama, nama_norm, kabupaten, kecamatan, garis_lintang, garis_bujur, sumber, akurasi)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    nama = VALUES(nama),
                    kecamatan = VALUES(kecamatan),
                    garis_lintang = VALUES(garis_lintang),
                    garis_bujur = VALUES(garis_bujur),
                    sumber = VALUES(sumber),
                    akurasi = VALUES(akurasi)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        $kec = $kecamatan !== null ? trim($kecamatan) : '';
        $stmt->bind_param('sssssddss', $level, $nama, $norm, $kab, $kec, $lat, $lng, $sumber, $akurasi);
        $ok = $stmt->execute();
        $stmt->close();

        if (!$ok) {
            return 0;
        }
        if ($conn->insert_id > 0) {
            return (int) $conn->insert_id;
        }
        // Baris lama (ON DUPLICATE) — ambil id-nya
        $stmt = $conn->prepare('SELECT id FROM wilayah WHERE level = ? AND nama_norm = ? AND kabupaten = ? LIMIT 1');
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('sss', $level, $norm, $kab);
        $stmt->execute();
        $res = $stmt->get_result();
        $id = $res ? (int) ($res->fetch_assoc()['id'] ?? 0) : 0;
        $stmt->close();
        return $id;
    }
}

if (!function_exists('wilayah_alias_tambah')) {
    /** Tambahkan alias varian -> wilayah target (dicari berdasar level+nama). */
    function wilayah_alias_tambah(
        mysqli $conn,
        string $varian,
        string $levelTarget,
        string $namaTarget,
        string $catatan = ''
    ): bool {
        $varianNorm = wilayah_norm($varian);
        $targetNorm = wilayah_norm($namaTarget);
        if ($varianNorm === '' || $targetNorm === '') {
            return false;
        }
        $stmt = $conn->prepare('SELECT id FROM wilayah WHERE level = ? AND nama_norm = ? ORDER BY garis_lintang IS NULL, id LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $levelTarget, $targetNorm);
        $stmt->execute();
        $res = $stmt->get_result();
        $id = $res ? (int) ($res->fetch_assoc()['id'] ?? 0) : 0;
        $stmt->close();
        if ($id <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            'INSERT INTO wilayah_alias (varian_norm, wilayah_id, catatan) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE wilayah_id = VALUES(wilayah_id), catatan = VALUES(catatan)'
        );
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sis', $varianNorm, $id, $catatan);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('wilayah_alias_bawaan')) {
    /**
     * Daftar alias bawaan (memperbaiki ejaan yang sering salah di data).
     * @return list<array{varian:string,level:string,target:string,catatan:string}>
     */
    function wilayah_alias_bawaan(): array
    {
        return [
            ['varian' => 'Rokin Hilir',       'level' => 'kabupaten', 'target' => 'Rokan Hilir', 'catatan' => 'ejaan: Rokin Hilir'],
            ['varian' => 'Rontau kopar',      'level' => 'kecamatan', 'target' => 'Rantau Kopar', 'catatan' => 'ejaan: Rontau kopar'],
            ['varian' => 'TanahPutih Tanjung Melawan', 'level' => 'kecamatan', 'target' => 'Tanah Putih Tanjung Melawan', 'catatan' => 'spasi: TanahPutih'],
            ['varian' => 'Bagansinembah',     'level' => 'kecamatan', 'target' => 'Bagan Sinembah', 'catatan' => 'ejaan: Bagansinembah'],
            ['varian' => 'Tualang Muandau',   'level' => 'kecamatan', 'target' => 'Talang Muandau', 'catatan' => 'ejaan: Tualang Muandau'],
            ['varian' => 'Tapung HIlir',      'level' => 'kecamatan', 'target' => 'Tapung Hilir', 'catatan' => 'ejaan: HIlir'],
            ['varian' => 'Bukit kapur',       'level' => 'kecamatan', 'target' => 'Bukit Kapur', 'catatan' => 'ejaan: kapur'],
            ['varian' => 'Boncah Mahang Sebangar', 'level' => 'desa', 'target' => 'Boncah Mahang', 'catatan' => 'gabungan nama'],
            ['varian' => 'Melayu Besar Kota', 'level' => 'desa', 'target' => 'Melayu Besar', 'catatan' => 'gabungan nama'],
            ['varian' => 'Sintong Makmur',    'level' => 'desa', 'target' => 'Sintong Pusaka', 'catatan' => 'ejaan: Sintong Makmur'],
        ];
    }
}

if (!function_exists('wilayah_alias_terapkan_bawaan')) {
    /** Terapkan seluruh alias bawaan. @return int jumlah alias berhasil */
    function wilayah_alias_terapkan_bawaan(mysqli $conn): int
    {
        $n = 0;
        foreach (wilayah_alias_bawaan() as $a) {
            if (wilayah_alias_tambah($conn, $a['varian'], $a['level'], $a['target'], $a['catatan'])) {
                $n++;
            }
        }
        return $n;
    }
}

/* ===============================================================
 * 5. PENCARIAN KOORDINAT
 * ============================================================= */

if (!function_exists('wilayah_alias_peta')) {
    /** Muat peta alias (varian_norm => baris wilayah) sekali per proses. */
    function wilayah_alias_peta(mysqli $conn): array
    {
        static $peta = null;
        if ($peta !== null) {
            return $peta;
        }
        $peta = [];
        $res = $conn->query(
            'SELECT a.varian_norm, w.id, w.level, w.nama, w.nama_norm, w.kabupaten, w.kecamatan,
                    w.garis_lintang, w.garis_bujur, w.akurasi
               FROM wilayah_alias a
               JOIN wilayah w ON w.id = a.wilayah_id'
        );
        while ($res && ($r = $res->fetch_assoc())) {
            $peta[(string) $r['varian_norm']] = $r;
        }
        return $peta;
    }
}

if (!function_exists('wilayah_baris')) {
    /** Cari satu baris wilayah berdasar level + nama ternormalisasi. */
    function wilayah_baris(mysqli $conn, string $level, string $norm, ?string $kabupaten = null): ?array
    {
        if ($norm === '') {
            return null;
        }
        $kolom = 'id, level, nama, nama_norm, kabupaten, kecamatan, garis_lintang, garis_bujur, akurasi';

        // 1) Utamakan yang kabupaten-nya cocok
        if ($kabupaten !== null && trim($kabupaten) !== '') {
            $stmt = $conn->prepare(
                "SELECT $kolom FROM wilayah
                  WHERE level = ? AND nama_norm = ? AND (kabupaten = ? OR kabupaten = '' OR kabupaten IS NULL)
                  ORDER BY (kabupaten IS NULL OR kabupaten = ''), (garis_lintang IS NULL), id
                  LIMIT 1"
            );
            if ($stmt) {
                $kab = trim($kabupaten);
                $stmt->bind_param('sss', $level, $norm, $kab);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                if ($row) {
                    return $row;
                }
            }
        }

        // 2) Tanpa memperhatikan kabupaten
        $stmt = $conn->prepare(
            "SELECT $kolom FROM wilayah
              WHERE level = ? AND nama_norm = ?
              ORDER BY (garis_lintang IS NULL), id
              LIMIT 1"
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ss', $level, $norm);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('wilayah_cari')) {
    /**
     * Cari koordinat terbaik dari nama kabupaten/kecamatan/desa.
     * Urutan: desa -> kecamatan -> kabupaten; alias diterapkan lebih dulu.
     *
     * @return array<string,mixed>|null baris wilayah (mengandung garis_lintang/bujur)
     */
    function wilayah_cari(mysqli $conn, ?string $kab, ?string $kec, ?string $desa): ?array
    {
        $alias = wilayah_alias_peta($conn);
        $kabN  = wilayah_norm((string) $kab);
        $kecN  = wilayah_norm((string) $kec);
        $desaN = wilayah_norm((string) $desa);

        $kandidat = [];
        if ($desaN !== '') {
            $kandidat[] = ['desa', $desaN];
        }
        if ($kecN !== '') {
            $kandidat[] = ['kecamatan', $kecN];
        }
        if ($kabN !== '') {
            $kandidat[] = ['kabupaten', $kabN];
        }

        foreach ($kandidat as [$level, $norm]) {
            // Alias persis
            if (isset($alias[$norm]) && wilayah_koordinat_valid($alias[$norm]['garis_lintang'], $alias[$norm]['garis_bujur'])) {
                return $alias[$norm];
            }
            $row = wilayah_baris($conn, $level, $norm, $kab !== null ? $kab : null);
            if ($row !== null && wilayah_koordinat_valid($row['garis_lintang'], $row['garis_bujur'])) {
                return $row;
            }
        }
        return null;
    }
}

if (!function_exists('wilayah_cari_teks')) {
    /**
     * Cari koordinat dari teks alamat bebas (mis. "Desa Buluh Manis, Bathin Solapan").
     * Teks dipecah menurut koma, lalu tiap bagian dicoba sebagai desa/kecamatan/
     * kabupaten. Mengembalikan baris wilayah pertama yang punya koordinat.
     *
     * @return array<string,mixed>|null
     */
    function wilayah_cari_teks(mysqli $conn, string $teks): ?array
    {
        $teks = trim($teks);
        if ($teks === '') {
            return null;
        }

        // Pecah berdasar koma / titik koma
        $bagian = preg_split('/[,;]+/u', $teks) ?: [];
        $bagian = array_values(array_filter(array_map('trim', $bagian), static fn ($s) => $s !== ''));

        $coba = static function (?string $kab, ?string $kec, ?string $desa) use ($conn): ?array {
            return wilayah_cari($conn, $kab, $kec, $desa);
        };

        // 3 bagian: desa, kecamatan, kabupaten
        if (count($bagian) >= 3) {
            $w = $coba($bagian[2], $bagian[1], $bagian[0]);
            if ($w !== null) {
                return $w;
            }
        }
        // 2 bagian: desa/kelurahan, kecamatan
        if (count($bagian) >= 2) {
            $w = $coba(null, $bagian[1], $bagian[0]);
            if ($w !== null) {
                return $w;
            }
        }
        // Tiap bagian dicoba satu per satu (desa -> kecamatan -> kabupaten)
        foreach ($bagian as $b) {
            $w = $coba(null, null, $b);
            if ($w !== null) {
                return $w;
            }
        }
        return null;
    }
}

/* ===============================================================
 * 6. RESOLUSI TABEL (isi koordinat massal)
 * ============================================================= */

if (!function_exists('wilayah_resolusi_tabel')) {
    /**
     * Isi wilayah_id + garis_lintang/bujur pada tabel SRM/CID
     * berdasar kolom nama wilayahnya.
     *
     * @return array{ok:bool,pesan:string,total:int,terpetakan:int,tanpa_koordinat:int}
     */
    function wilayah_resolusi_tabel(mysqli $conn, string $tabel): array
    {
        $konfig = [
            'srm_kegiatan' => ['kab' => 'kabupaten_kota', 'kec' => 'kecamatan', 'desa' => 'kelurahan'],
            'cid_program'  => ['kab' => 'kabupaten_kota', 'kec' => 'kecamatan', 'desa' => 'desa'],
        ];
        if (!isset($konfig[$tabel])) {
            return ['ok' => false, 'pesan' => 'Tabel tidak dikenal.', 'total' => 0, 'terpetakan' => 0, 'tanpa_koordinat' => 0];
        }
        $k = $konfig[$tabel];

        $res = $conn->query("SELECT id, {$k['kab']} AS kab, {$k['kec']} AS kec, {$k['desa']} AS desa FROM {$tabel}");
        if (!$res) {
            return ['ok' => false, 'pesan' => 'Gagal membaca tabel.', 'total' => 0, 'terpetakan' => 0, 'tanpa_koordinat' => 0];
        }

        $upd = $conn->prepare("UPDATE {$tabel} SET wilayah_id = ?, garis_lintang = ?, garis_bujur = ? WHERE id = ?");
        if (!$upd) {
            return ['ok' => false, 'pesan' => 'Gagal menyiapkan UPDATE.', 'total' => 0, 'terpetakan' => 0, 'tanpa_koordinat' => 0];
        }

        $total = 0;
        $terpetakan = 0;
        while ($r = $res->fetch_assoc()) {
            $total++;
            $w = wilayah_cari($conn, $r['kab'] ?? null, $r['kec'] ?? null, $r['desa'] ?? null);
            if ($w === null) {
                continue;
            }
            $lat = (float) $w['garis_lintang'];
            $lng = (float) $w['garis_bujur'];
            $wid = (int) $w['id'];
            $id  = (int) $r['id'];
            $upd->bind_param('iddi', $wid, $lat, $lng, $id);
            if ($upd->execute()) {
                $terpetakan++;
            }
        }
        $upd->close();

        return [
            'ok' => true,
            'pesan' => 'Resolusi selesai.',
            'total' => $total,
            'terpetakan' => $terpetakan,
            'tanpa_koordinat' => $total - $terpetakan,
        ];
    }
}

/* ===============================================================
 * 7. GEOKODE TEKS + CACHE (untuk impor berkas)
 * ============================================================= */

if (!function_exists('wilayah_geokode_norm')) {
    /** Normalkan kunci cache geokode (huruf kecil, spasi tunggal). */
    function wilayah_geokode_norm(string $teks): string
    {
        $teks = mb_strtolower(trim($teks), 'UTF-8');
        $teks = preg_replace('/\s+/u', ' ', $teks) ?? $teks;
        return mb_substr($teks, 0, 191);
    }
}

if (!function_exists('wilayah_geokode_teks')) {
    /**
     * Resolusikan teks alamat menjadi koordinat, memakai urutan:
     *   1. cache `geokode_cache` (termasuk cache negatif),
     *   2. master `wilayah` (wilayah_cari_teks),
     *   3. Nominatim (jaringan).
     *
     * Panggilan Nominatim TIDAK boleh berada di dalam transaksi basis data.
     * Pemanggil yang memproses banyak baris wajib memberi jeda (>= 1 detik).
     *
     * @return array{lat:float,lng:float,wilayah_id:?int,sumber:string}|null
     */
    function wilayah_geokode_teks(mysqli $conn, string $teks, int $timeout = 20, bool $pakaiJaringan = true): ?array
    {
        $teks = trim($teks);
        if ($teks === '') {
            return null;
        }
        $norm = wilayah_geokode_norm($teks);
        if ($norm === '') {
            return null;
        }

        // 1. Cache
        if (function_exists('wilayah_koordinat_valid') && _wilayah_tabel_ada($conn, 'geokode_cache')) {
            $stmt = $conn->prepare(
                'SELECT garis_lintang, garis_bujur FROM geokode_cache WHERE kueri_norm = ? LIMIT 1'
            );
            if ($stmt) {
                $stmt->bind_param('s', $norm);
                $stmt->execute();
                $res = $stmt->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $stmt->close();
                if ($row !== null) {
                    if (wilayah_koordinat_valid($row['garis_lintang'] ?? null, $row['garis_bujur'] ?? null)) {
                        return [
                            'lat' => (float) $row['garis_lintang'],
                            'lng' => (float) $row['garis_bujur'],
                            'wilayah_id' => null,
                            'sumber' => 'cache',
                        ];
                    }
                    // Cache negatif: pernah dicoba, tidak ada hasil.
                    return null;
                }
            }
        }

        // 2. Master wilayah
        $w = wilayah_cari_teks($conn, $teks);
        if ($w === null) {
            // Teks tunggal (mis. "Bengkalis") - coba sebagai kabupaten/kecamatan/desa.
            $w = wilayah_cari($conn, $teks, null, null)
                ?? wilayah_cari($conn, null, $teks, null)
                ?? wilayah_cari($conn, null, null, $teks);
        }
        if ($w !== null && wilayah_koordinat_valid($w['garis_lintang'] ?? null, $w['garis_bujur'] ?? null)) {
            $lat = (float) $w['garis_lintang'];
            $lng = (float) $w['garis_bujur'];
            _wilayah_geokode_simpan($conn, $teks, $norm, $lat, $lng, (string) ($w['nama'] ?? ''), 'master');
            return ['lat' => $lat, 'lng' => $lng, 'wilayah_id' => (int) $w['id'], 'sumber' => 'master'];
        }

        // 3. Nominatim (jaringan)
        if (!$pakaiJaringan || !function_exists('wilayah_geokode')) {
            return null;
        }
        $g = wilayah_geokode($teks, $timeout);
        if ($g === null) {
            _wilayah_geokode_simpan($conn, $teks, $norm, null, null, null, 'nominatim');
            return null;
        }
        _wilayah_geokode_simpan($conn, $teks, $norm, $g['lat'], $g['lon'], $g['display'], 'nominatim');
        return ['lat' => $g['lat'], 'lng' => $g['lon'], 'wilayah_id' => null, 'sumber' => 'nominatim'];
    }
}

if (!function_exists('_wilayah_tabel_ada')) {
    /** Cek cepat keberadaan tabel (hasil di-cache per permintaan). */
    function _wilayah_tabel_ada(mysqli $conn, string $tabel): bool
    {
        static $cache = [];
        if (array_key_exists($tabel, $cache)) {
            return $cache[$tabel];
        }
        $nama = $conn->real_escape_string($tabel);
        $res = $conn->query("SHOW TABLES LIKE '{$nama}'");
        $ada = $res !== false && $res->num_rows > 0;
        return $cache[$tabel] = $ada;
    }
}

if (!function_exists('_wilayah_geokode_simpan')) {
    /** Simpan hasil geokode ke cache (idempoten). */
    function _wilayah_geokode_simpan(
        mysqli $conn,
        string $teks,
        string $norm,
        ?float $lat,
        ?float $lng,
        ?string $tampilan,
        string $sumber
    ): void {
        if (!_wilayah_tabel_ada($conn, 'geokode_cache')) {
            return;
        }
        $stmt = $conn->prepare(
            'INSERT INTO geokode_cache (kueri, kueri_norm, garis_lintang, garis_bujur, tampilan, sumber)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 garis_lintang = VALUES(garis_lintang),
                 garis_bujur = VALUES(garis_bujur),
                 tampilan = VALUES(tampilan),
                 sumber = VALUES(sumber)'
        );
        if (!$stmt) {
            return;
        }
        $kueri = mb_substr($teks, 0, 191);
        $tamp = $tampilan !== null ? mb_substr($tampilan, 0, 255) : null;
        $stmt->bind_param('ssddss', $kueri, $norm, $lat, $lng, $tamp, $sumber);
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('wilayah_tabel_lokasi')) {
    /**
     * Konfigurasi kolom lokasi per tabel (teks + koordinat).
     *
     * `teks`     : satu kolom teks lokasi (dipakai untuk kasus/laporan).
     * `teks_sql` : ekspresi SQL teks gabungan (dipakai SRM/CID yang lokasinya
     *              tersebar pada desa + kecamatan + kabupaten); bila null maka
     *              `teks` yang dipakai.
     * `wilayah`  : kolom wilayah_id, atau null bila tabel tak punya kolom itu.
     *
     * @return array{teks:string,lat:string,lng:string,wilayah:?string,teks_sql:?string}|null
     */
    function wilayah_tabel_lokasi(string $tabel): ?array
    {
        return match ($tabel) {
            'laporan_harian' => ['teks' => 'lokasi_teks', 'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => 'wilayah_id', 'teks_sql' => null],
            'absensi'        => ['teks' => 'lokasi_teks', 'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => 'wilayah_id', 'teks_sql' => null],
            'jadwal'         => ['teks' => 'lokasi',      'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => 'wilayah_id', 'teks_sql' => null],
            'kasus'          => ['teks' => 'nama_lokasi', 'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => null,         'teks_sql' => null],
            'srm_kegiatan'   => ['teks' => 'kelurahan',   'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => 'wilayah_id', 'teks_sql' => "CONCAT_WS(', ', NULLIF(TRIM(kelurahan), ''), NULLIF(TRIM(kecamatan), ''), NULLIF(TRIM(kabupaten_kota), ''))"],
            'cid_program'    => ['teks' => 'desa',        'lat' => 'garis_lintang', 'lng' => 'garis_bujur', 'wilayah' => 'wilayah_id', 'teks_sql' => "CONCAT_WS(', ', NULLIF(TRIM(desa), ''), NULLIF(TRIM(kecamatan), ''), NULLIF(TRIM(kabupaten_kota), ''))"],
            default          => null,
        };
    }
}

if (!function_exists('_wilayah_teks_expr')) {
    /** Ekspresi SQL teks lokasi untuk sebuah konfigurasi tabel. */
    function _wilayah_teks_expr(array $k): string
    {
        return (string) ($k['teks_sql'] ?? $k['teks']);
    }
}

if (!function_exists('wilayah_tabel_geokode')) {
    /** Daftar tabel yang ikut diproses geokode otomatis (urut prioritas). */
    function wilayah_tabel_geokode(): array
    {
        return ['kasus', 'laporan_harian', 'absensi', 'jadwal', 'srm_kegiatan', 'cid_program'];
    }
}

if (!function_exists('wilayah_menunggu_geokode')) {
    /** Hitung baris pada tabel yang punya teks lokasi tetapi belum berkoordinat. */
    function wilayah_menunggu_geokode(mysqli $conn, string $tabel): int
    {
        $k = wilayah_tabel_lokasi($tabel);
        if ($k === null) {
            return 0;
        }
        $teks = _wilayah_teks_expr($k);
        $sql = "SELECT COUNT(*) AS j FROM {$tabel}
                 WHERE {$k['lat']} IS NULL AND {$teks} IS NOT NULL AND TRIM({$teks}) <> ''";
        $res = $conn->query($sql);
        if (!$res) {
            return 0;
        }
        return (int) ($res->fetch_assoc()['j'] ?? 0);
    }
}

if (!function_exists('wilayah_batch_koordinat')) {
    /**
     * Isi koordinat untuk baris pada $tabel yang belum punya koordinat.
     * Membatasi jumlah baris per panggilan agar tidak memblokir permintaan
     * terlalu lama (Nominatim 1 permintaan/detik).
     *
     * @param list<int>|null $id batasi ke baris tertentu (null = semua)
     * @return array{ok:bool,pesan:string,diproses:int,terisi:int,gagal:int,tunggu:int}
     */
    function wilayah_batch_koordinat(
        mysqli $conn,
        string $tabel,
        ?array $id = null,
        int $maks = 15,
        int $jedaMikro = 1000000
    ): array {
        $k = wilayah_tabel_lokasi($tabel);
        if ($k === null) {
            return ['ok' => false, 'pesan' => 'Tabel tidak dikenal.', 'diproses' => 0, 'terisi' => 0, 'gagal' => 0, 'tunggu' => 0];
        }
        $maks = max(1, min(50, $maks));
        $teks = _wilayah_teks_expr($k);

        $sql = "SELECT id, {$teks} AS teks FROM {$tabel}
                 WHERE {$k['lat']} IS NULL AND {$teks} IS NOT NULL AND TRIM({$teks}) <> ''";
        $tipe = '';
        $param = [];
        if (is_array($id) && $id !== []) {
            $idBersih = array_values(array_filter(array_map('intval', $id), static fn ($v) => $v > 0));
            if ($idBersih !== []) {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($idBersih), '?')) . ')';
                $tipe = str_repeat('i', count($idBersih));
                $param = $idBersih;
            }
        }
        $sql .= ' ORDER BY id LIMIT ' . $maks;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'pesan' => 'Gagal menyiapkan kueri.', 'diproses' => 0, 'terisi' => 0, 'gagal' => 0, 'tunggu' => 0];
        }
        if ($tipe !== '') {
            $stmt->bind_param($tipe, ...$param);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $baris[] = $r;
        }
        $stmt->close();

        if ($baris === []) {
            return ['ok' => true, 'pesan' => 'Tidak ada baris yang menunggu geokode.', 'diproses' => 0, 'terisi' => 0, 'gagal' => 0, 'tunggu' => 0];
        }

        $upd = $k['wilayah'] !== null
            ? $conn->prepare("UPDATE {$tabel} SET {$k['lat']} = ?, {$k['lng']} = ?, {$k['wilayah']} = ? WHERE id = ?")
            : $conn->prepare("UPDATE {$tabel} SET {$k['lat']} = ?, {$k['lng']} = ? WHERE id = ?");
        if (!$upd) {
            return ['ok' => false, 'pesan' => 'Gagal menyiapkan UPDATE.', 'diproses' => 0, 'terisi' => 0, 'gagal' => 0, 'tunggu' => 0];
        }

        $terisi = 0;
        $gagal = 0;
        $pertama = true;
        foreach ($baris as $b) {
            // Jeda sebelum permintaan jaringan berikutnya (hormati 1 req/detik).
            if (!$pertama && $jedaMikro > 0) {
                usleep($jedaMikro);
            }
            $pertama = false;

            $hasil = wilayah_geokode_teks($conn, (string) $b['teks']);
            if ($hasil === null) {
                $gagal++;
                continue;
            }
            $lat = (float) $hasil['lat'];
            $lng = (float) $hasil['lng'];
            $wid = $hasil['wilayah_id'];
            $rowId = (int) $b['id'];
            // mysqli mengirim nilai PHP null sebagai SQL NULL untuk kolom wilayah_id.
            if ($k['wilayah'] !== null) {
                $upd->bind_param('ddii', $lat, $lng, $wid, $rowId);
            } else {
                $upd->bind_param('ddi', $lat, $lng, $rowId);
            }
            $upd->execute();
            $terisi++;
        }
        $upd->close();

        $tunggu = wilayah_menunggu_geokode($conn, $tabel);

        return [
            'ok' => true,
            'pesan' => sprintf('Geokode: %d terisi, %d gagal.', $terisi, $gagal),
            'diproses' => count($baris),
            'terisi' => $terisi,
            'gagal' => $gagal,
            'tunggu' => $tunggu,
        ];
    }
}

if (!function_exists('wilayah_menunggu_geokode_semua')) {
    /**
     * Hitung total baris yang masih menunggu geokode pada seluruh tabel.
     *
     * @return array{total:int,per_tabel:array<string,int>}
     */
    function wilayah_menunggu_geokode_semua(mysqli $conn): array
    {
        $per = [];
        $total = 0;
        foreach (wilayah_tabel_geokode() as $tabel) {
            $n = wilayah_menunggu_geokode($conn, $tabel);
            if ($n > 0) {
                $per[$tabel] = $n;
            }
            $total += $n;
        }
        return ['total' => $total, 'per_tabel' => $per];
    }
}

if (!function_exists('wilayah_batch_koordinat_berikutnya')) {
    /**
     * Lanjutkan geokode baris yang masih menunggu di SEMUA tabel (data
     * terbaru diprioritaskan). Dipanggil di luar transaksi basis data;
     * menghormati batas Nominatim 1 permintaan/detik.
     *
     * Baris yang gagal diingat di sesi selama 6 jam agar tidak dicoba
     * berulang-ulang pada setiap permintaan (cache negatif tetap dipakai
     * oleh wilayah_geokode_teks, jadi percobaan ulang pun tanpa jaringan).
     *
     * @param int $batas jumlah maksimum baris yang diproses pada panggilan ini
     * @return array{diproses:int,terisi:int,gagal:int,sisa:int}
     */
    function wilayah_batch_koordinat_berikutnya(mysqli $conn, int $batas = 15): array
    {
        $batas = max(1, min(50, $batas));
        $urut  = wilayah_tabel_geokode();

        // Peta "sudah dicoba & gagal" per tabel (id => waktu).
        $namaSesi = '_geokode_coba';
        $coba = (isset($_SESSION[$namaSesi]) && is_array($_SESSION[$namaSesi])) ? $_SESSION[$namaSesi] : [];
        $sekarang = time();
        foreach ($coba as $t => $daftar) {
            if (!is_array($daftar)) {
                unset($coba[$t]);
                continue;
            }
            foreach ($daftar as $id => $w) {
                if (($sekarang - (int) $w) > 21600) { // 6 jam
                    unset($coba[$t][$id]);
                }
            }
            if ($coba[$t] === []) {
                unset($coba[$t]);
            }
        }

        $diproses = 0;
        $terisi   = 0;
        $gagal    = 0;
        $sisa     = $batas;
        $pertama  = true;

        foreach ($urut as $tabel) {
            if ($sisa <= 0) {
                break;
            }
            $k = wilayah_tabel_lokasi($tabel);
            if ($k === null) {
                continue;
            }
            $teks = _wilayah_teks_expr($k);

            $res = $conn->query(
                "SELECT id, {$teks} AS teks FROM {$tabel}
                  WHERE {$k['lat']} IS NULL AND {$teks} IS NOT NULL AND TRIM({$teks}) <> ''
                  ORDER BY id DESC LIMIT 60"
            );
            if (!$res) {
                continue;
            }
            $baris = [];
            while ($r = $res->fetch_assoc()) {
                $id = (int) $r['id'];
                if (isset($coba[$tabel][$id])) {
                    continue; // sudah dicoba dan gagal sebelumnya
                }
                if (trim((string) $r['teks']) === '') {
                    continue;
                }
                $baris[] = $r;
                if (count($baris) >= $sisa) {
                    break;
                }
            }
            $res->free();

            if ($baris === []) {
                continue;
            }

            $upd = $k['wilayah'] !== null
                ? $conn->prepare("UPDATE {$tabel} SET {$k['lat']} = ?, {$k['lng']} = ?, {$k['wilayah']} = ? WHERE id = ?")
                : $conn->prepare("UPDATE {$tabel} SET {$k['lat']} = ?, {$k['lng']} = ? WHERE id = ?");
            if (!$upd) {
                continue;
            }

            foreach ($baris as $b) {
                if (!$pertama) {
                    usleep(1000000); // 1 detik (batas Nominatim)
                }
                $pertama = false;

                $id   = (int) $b['id'];
                $hasil = wilayah_geokode_teks($conn, (string) $b['teks']);
                $diproses++;
                $sisa--;

                if ($hasil === null) {
                    $gagal++;
                    $coba[$tabel][$id] = $sekarang;
                    continue;
                }

                $lat = (float) $hasil['lat'];
                $lng = (float) $hasil['lng'];
                if ($k['wilayah'] !== null) {
                    $wid = $hasil['wilayah_id'];
                    $upd->bind_param('ddii', $lat, $lng, $wid, $id);
                } else {
                    $upd->bind_param('ddi', $lat, $lng, $id);
                }
                if ($upd->execute()) {
                    $terisi++;
                    unset($coba[$tabel][$id]);
                } else {
                    $gagal++;
                }
            }
            $upd->close();

            if ($sisa <= 0) {
                break;
            }
        }

        $_SESSION[$namaSesi] = $coba;

        $sisaTotal = 0;
        foreach ($urut as $tabel) {
            $sisaTotal += wilayah_menunggu_geokode($conn, $tabel);
        }

        return ['diproses' => $diproses, 'terisi' => $terisi, 'gagal' => $gagal, 'sisa' => $sisaTotal];
    }
}
