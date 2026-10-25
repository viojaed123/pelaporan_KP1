<?php
/**
 * includes/impor_dataset.php
 * Impor "dataset generik": menerima berkas dengan kolom APA PUN dan menyimpan
 * isinya sebagai JSON, tanpa perlu skema tetap.
 *
 * Berbeda dari `impor_excel.php` (jenis tetap + pemetaan ke tabel tetap),
 * di sini header berkas LANGSUNG menjadi kolom dataset. Tujuannya agar data
 * apa pun yang diimpor bisa langsung divisualkan di analisis_dataset.php.
 *
 * Keamanan (PENTING):
 *   - Kunci kolom dinormalisasi ke ^[a-z0-9_]{1,64}$ sehingga aman dipakai
 *     sebagai nama alur JSON (JSON_EXTRACT) tanpa risiko injeksi.
 *   - Nilai tidak pernah dirangkai ke SQL; selalu lewat prepared statement
 *     di sisi pemanggil (halaman visualisasi).
 *   - Batas baris & kolom mencegah beban berlebih.
 */

declare(strict_types=1);

require_once __DIR__ . '/impor_excel.php';
require_once __DIR__ . '/srm_cid.php';

/** Batas aman ukuran dataset (JSON per baris; skala ratusan–ribuan). */
if (!defined('DATASET_MAKS_BARIS')) {
    define('DATASET_MAKS_BARIS', 50000);
}
if (!defined('DATASET_MAKS_KOLOM')) {
    define('DATASET_MAKS_KOLOM', 100);
}

if (!function_exists('dataset_jumlah_maks_baris')) {
    function dataset_jumlah_maks_baris(): int
    {
        return (int) DATASET_MAKS_BARIS;
    }
}

if (!function_exists('dataset_jumlah_maks_kolom')) {
    function dataset_jumlah_maks_kolom(): int
    {
        return (int) DATASET_MAKS_KOLOM;
    }
}

if (!function_exists('dataset_norm_kunci')) {
    /**
     * Normalisasi label header menjadi kunci kolom yang aman.
     *
     * Hasil hanya berisi huruf kecil, angka, dan garis bawah (^[a-z0-9_]+$),
     * panjang 1..64. Kunci inilah yang dipakai sebagai nama alur JSON, jadi
     * normalisasi ini adalah lapisan pertahanan utama terhadap injeksi.
     *
     * @param array<string,bool> $terpakai kunci yang sudah dipakai (deduplikasi)
     */
    function dataset_norm_kunci(string $label, array &$terpakai = []): string
    {
        $s = mb_strtolower(trim($label));
        // Ganti semua karakter non-alfanumerik (termasuk aksen & tanda baca) dengan "_".
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s) ?? '';
        $s = trim($s, '_');
        if ($s === '') {
            $s = 'kolom';
        }
        $s = mb_substr($s, 0, 64);
        // Pastikan tidak diawali angka (lebih rapi sebagai kunci JSON).
        if (preg_match('/^[0-9]/', $s) === 1) {
            $s = 'k_' . $s;
        }
        $s = mb_substr($s, 0, 64);

        // Deduplikasi: kolom1, kolom1_2, kolom1_3, ...
        $asli = $s;
        $n = 1;
        while (isset($terpakai[$s])) {
            $n++;
            $s = mb_substr($asli, 0, 64 - strlen((string) $n) - 1) . '_' . $n;
        }
        $terpakai[$s] = true;

        return $s;
    }
}

if (!function_exists('dataset_kunci_sah')) {
    /** Apakah kunci aman dipakai sebagai nama alur JSON/SQL. */
    function dataset_kunci_sah(string $kunci): bool
    {
        return preg_match('/^[a-z0-9_]{1,64}$/', $kunci) === 1;
    }
}

if (!function_exists('dataset_tebak_tipe')) {
    /**
     * Tebak tipe kolom dari daftar nilai mentah: 'angka' | 'tanggal' | 'teks'.
     *
     * Aturan: hanya nilai tidak kosong yang dihitung. Tipe menang bila
     * mayoritas (>= 60%) nilai tidak kosong cocok.
     *
     * @param list<string> $nilai
     */
    function dataset_tebak_tipe(array $nilai): string
    {
        $ada = 0;
        $angka = 0;
        $tanggal = 0;

        foreach ($nilai as $v) {
            $s = trim((string) $v);
            if ($s === '') {
                continue;
            }
            $ada++;
            if (srm_cid_angka($s) !== null) {
                $angka++;
            }
            // Tanggal diuji setelah angka; serial tanggal Excel berupa angka
            // murni, jadi cek pola teks tanggal lebih dulu agar tidak keliru.
            if (dataset_terlihat_tanggal($s)) {
                $tanggal++;
            }
        }

        if ($ada === 0) {
            return 'teks';
        }

        $rasioTanggal = $tanggal / $ada;
        $rasioAngka   = $angka / $ada;

        // Tanggal menang lebih dulu: serial Excel juga lolos uji angka.
        if ($rasioTanggal >= 0.6) {
            return 'tanggal';
        }
        if ($rasioAngka >= 0.6) {
            return 'angka';
        }

        return 'teks';
    }
}

if (!function_exists('dataset_terlihat_tanggal')) {
    /**
     * Cek apakah sebuah nilai tampak seperti tanggal (bukan sekadar angka).
     * Menerima format teks umum Indonesia/ISO dan serial tanggal Excel.
     */
    function dataset_terlihat_tanggal(string $s): bool
    {
        $s = trim($s);
        if ($s === '') {
            return false;
        }

        // Pola teks tanggal yang jelas: mengandung pemisah tanggal.
        if (preg_match('/^\d{1,4}[\/\-.]\d{1,2}[\/\-.]\d{1,4}$/', $s) === 1) {
            return true;
        }
        // "12 Januari 2026" / "12 Jan 2026" / "Januari 2026".
        if (dataset_tanggal_nama_bulan($s) !== null) {
            return true;
        }
        // Serial tanggal Excel: angka 20000..60000 (kisaran 1954..2064).
        if (is_numeric($s)) {
            $f = (float) $s;
            return $f >= 20000 && $f <= 60000 && floor($f) === $f;
        }

        return false;
    }
}

if (!function_exists('dataset_tanggal_nama_bulan')) {
    /**
     * Normalisasi tanggal yang memakai NAMA BULAN Indonesia/Inggris ke
     * 'Y-m-d', atau null bila bukan tanggal semacam itu.
     *
     * Contoh: "5 Januari 2026", "11 Feb 2026", "Januari 2026" (-> hari 1).
     */
    function dataset_tanggal_nama_bulan(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }

        // <hari> <nama bulan> <tahun>
        if (preg_match('/^(\d{1,2})[\s\-]+([A-Za-z]{3,})[\s\-,]+(\d{4})$/u', $s, $m) === 1) {
            $bulan = cid_bulan_angka($m[2]);
            if ($bulan === null) {
                return null;
            }
            return sprintf('%04d-%02d-%02d', (int) $m[3], $bulan, (int) $m[1]);
        }

        // <nama bulan> <tahun> (tanpa hari -> hari 1)
        if (preg_match('/^([A-Za-z]{3,})[\s\-]+(\d{4})$/u', $s, $m) === 1) {
            $bulan = cid_bulan_angka($m[1]);
            if ($bulan === null) {
                return null;
            }
            return sprintf('%04d-%02d-01', (int) $m[2], $bulan);
        }

        return null;
    }
}

if (!function_exists('dataset_tanggalkan')) {
    /**
     * Normalisasi nilai apa pun menjadi 'Y-m-d' bila memungkinkan, atau null.
     * Dipakai saat membangun sumbu tren grafik.
     */
    function dataset_tanggalkan(string $s): ?string
    {
        $namaBulan = dataset_tanggal_nama_bulan($s);
        if ($namaBulan !== null) {
            return $namaBulan;
        }

        return srm_cid_tanggal($s);
    }
}

if (!function_exists('dataset_profil_kolom')) {
    /**
     * Bangun profil kolom dari header + baris.
     *
     * @param list<string>       $header label header asli
     * @param list<list<string>> $baris  baris data (kolom sejajar dengan header)
     * @return list<array{kunci:string,label:string,tipe:string,kosong:int}>
     */
    function dataset_profil_kolom(array $header, array $baris): array
    {
        $terpakai = [];
        $profil = [];

        foreach ($header as $i => $label) {
            $kunci = dataset_norm_kunci((string) $label, $terpakai);

            $nilaiKolom = [];
            $kosong = 0;
            foreach ($baris as $r) {
                $v = isset($r[$i]) ? trim((string) $r[$i]) : '';
                if ($v === '') {
                    $kosong++;
                }
                $nilaiKolom[] = $v;
            }

            $profil[] = [
                'kunci'  => $kunci,
                'label'  => trim((string) $label) !== '' ? trim((string) $label) : $kunci,
                'tipe'   => dataset_tebak_tipe($nilaiKolom),
                'kosong' => $kosong,
            ];
        }

        return $profil;
    }
}

if (!function_exists('dataset_baris_ke_json')) {
    /**
     * Ubah satu baris data menjadi JSON {"<kunci>": "<nilai>", ...}.
     *
     * @param list<string>                          $baris
     * @param list<array{kunci:string}>             $profil
     */
    function dataset_baris_ke_json(array $baris, array $profil): string
    {
        $data = [];
        foreach ($profil as $i => $k) {
            $nilai = isset($baris[$i]) ? trim((string) $baris[$i]) : '';
            $data[$k['kunci']] = $nilai;
        }
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }
}

if (!function_exists('dataset_slug')) {
    /** Slug URL dari nama dataset (aman, unik). */
    function dataset_slug(string $nama, array &$terpakai = []): string
    {
        $s = mb_strtolower(trim($nama));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        $s = trim($s, '-');
        if ($s === '') {
            $s = 'dataset';
        }
        $s = mb_substr($s, 0, 140);

        $asli = $s;
        $n = 1;
        while (isset($terpakai[$s])) {
            $n++;
            $s = substr($asli, 0, 140 - strlen((string) $n) - 1) . '-' . $n;
        }
        $terpakai[$s] = true;

        return $s;
    }
}

if (!function_exists('dataset_simpan')) {
    /**
     * Simpan dataset baru (transaksional).
     *
     * @param list<string>       $header
     * @param list<list<string>> $baris
     * @return array{ok:bool, galat:string, id:int, jumlah_baris:int, jumlah_kolom:int}
     */
    function dataset_simpan(
        mysqli $conn,
        string $nama,
        array $header,
        array $baris,
        int $userId = 0,
        string $namaBerkas = '',
        string $deskripsi = ''
    ): array {
        $nama = trim($nama);
        if ($nama === '') {
            return ['ok' => false, 'galat' => 'Nama dataset wajib diisi.', 'id' => 0, 'jumlah_baris' => 0, 'jumlah_kolom' => 0];
        }
        if ($header === []) {
            return ['ok' => false, 'galat' => 'Berkas tidak memiliki baris header.', 'id' => 0, 'jumlah_baris' => 0, 'jumlah_kolom' => 0];
        }
        if (count($header) > dataset_jumlah_maks_kolom()) {
            return ['ok' => false, 'galat' => 'Jumlah kolom melebihi batas '
                . dataset_jumlah_maks_kolom() . '.', 'id' => 0, 'jumlah_baris' => 0, 'jumlah_kolom' => 0];
        }
        if (count($baris) > dataset_jumlah_maks_baris()) {
            return ['ok' => false, 'galat' => 'Jumlah baris melebihi batas '
                . number_format(dataset_jumlah_maks_baris(), 0, ',', '.') . '.', 'id' => 0, 'jumlah_baris' => 0, 'jumlah_kolom' => 0];
        }

        $profil = dataset_profil_kolom($header, $baris);
        $kolomJson = json_encode($profil, JSON_UNESCAPED_UNICODE);
        if ($kolomJson === false) {
            $kolomJson = '[]';
        }

        // Slug unik terhadap yang sudah ada di DB.
        $terpakai = [];
        $res = $conn->query('SELECT slug FROM dataset');
        while ($res && ($x = $res->fetch_assoc())) {
            $terpakai[(string) $x['slug']] = true;
        }
        $slug = dataset_slug($nama, $terpakai);

        $deskripsi = trim($deskripsi);
        $deskripsi = $deskripsi !== '' ? mb_substr($deskripsi, 0, 255) : null;
        $namaBerkas = trim($namaBerkas);
        $namaBerkas = $namaBerkas !== '' ? mb_substr($namaBerkas, 0, 255) : null;
        $uid = $userId > 0 ? $userId : null;
        $nBaris = count($baris);
        $nKolom = count($profil);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'INSERT INTO dataset (nama, slug, deskripsi, user_id, jumlah_baris, jumlah_kolom, kolom_json, nama_berkas)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan simpan dataset: ' . $conn->error);
            }
            $stmt->bind_param('sssiiiss', $nama, $slug, $deskripsi, $uid, $nBaris, $nKolom, $kolomJson, $namaBerkas);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('Gagal menyimpan dataset: ' . $conn->error);
            }
            $datasetId = (int) $conn->insert_id;
            $stmt->close();

            if ($nBaris > 0) {
                $stmtR = $conn->prepare(
                    'INSERT INTO dataset_baris (dataset_id, nomor, data_json) VALUES (?, ?, ?)'
                );
                if (!$stmtR) {
                    throw new RuntimeException('Gagal menyiapkan simpan baris: ' . $conn->error);
                }
                $nomor = 0;
                foreach ($baris as $r) {
                    $nomor++;
                    $dataJson = dataset_baris_ke_json($r, $profil);
                    $stmtR->bind_param('iis', $datasetId, $nomor, $dataJson);
                    if (!$stmtR->execute()) {
                        $stmtR->close();
                        throw new RuntimeException('Gagal menyimpan baris ke-' . $nomor . ': ' . $conn->error);
                    }
                }
                $stmtR->close();
            }

            $conn->commit();
        } catch (Throwable $t) {
            $conn->rollback();
            return ['ok' => false, 'galat' => $t->getMessage(), 'id' => 0, 'jumlah_baris' => 0, 'jumlah_kolom' => 0];
        }

        return ['ok' => true, 'galat' => '', 'id' => $datasetId, 'jumlah_baris' => $nBaris, 'jumlah_kolom' => $nKolom];
    }
}

if (!function_exists('dataset_daftar')) {
    /**
     * Daftar dataset untuk pemilih di UI.
     * @return list<array<string,mixed>>
     */
    function dataset_daftar(mysqli $conn): array
    {
        $out = [];
        $tabelAda = $conn->query("SHOW TABLES LIKE 'dataset'");
        if (!$tabelAda || $tabelAda->num_rows === 0) {
            return $out;
        }
        $res = $conn->query(
            'SELECT d.id, d.nama, d.slug, d.jumlah_baris, d.jumlah_kolom, d.dibuat_pada,
                    u.nama AS pemilik
             FROM dataset d LEFT JOIN users u ON u.id = d.user_id
             ORDER BY d.id DESC'
        );
        while ($res && ($x = $res->fetch_assoc())) {
            $out[] = $x;
        }

        return $out;
    }
}

if (!function_exists('dataset_ambil')) {
    /** Ambil satu dataset (beserta profil kolom terurai) atau null. */
    function dataset_ambil(mysqli $conn, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $stmt = $conn->prepare(
            'SELECT d.id, d.nama, d.slug, d.deskripsi, d.user_id, d.jumlah_baris, d.jumlah_kolom,
                    d.kolom_json, d.nama_berkas, d.dibuat_pada, u.nama AS pemilik
             FROM dataset d LEFT JOIN users u ON u.id = d.user_id
             WHERE d.id = ? LIMIT 1'
        );
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($baris === null) {
            return null;
        }

        $kolom = json_decode((string) ($baris['kolom_json'] ?? '[]'), true);
        $baris['kolom'] = is_array($kolom) ? $kolom : [];

        return $baris;
    }
}

if (!function_exists('dataset_hapus')) {
    /** Hapus dataset + seluruh barisnya (transaksional). */
    function dataset_hapus(mysqli $conn, int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare('DELETE FROM dataset_baris WHERE dataset_id = ?');
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan hapus baris.');
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare('DELETE FROM dataset WHERE id = ?');
            if (!$stmt) {
                throw new RuntimeException('Gagal menyiapkan hapus dataset.');
            }
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Throwable $t) {
            $conn->rollback();
            return false;
        }

        return true;
    }
}
