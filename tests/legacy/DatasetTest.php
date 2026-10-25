<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji mesin "dataset generik" (includes/impor_dataset.php) — fungsi murni saja.
 *
 * Fokus utama: keamanan. Kunci kolom dipakai sebagai nama alur JSON
 * (JSON_EXTRACT), jadi normalisasi & validasi kunci diuji ketat di sini.
 */
final class DatasetTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /* =====================================================
     * Normalisasi kunci kolom (pertahanan terhadap injeksi)
     * ===================================================== */

    public function testNormKunciDasar(): void
    {
        $this->assertSame('judul_issue', dataset_norm_kunci('Judul Issue'));
        $this->assertSame('nama_pic', dataset_norm_kunci('  Nama PIC  '));
        $this->assertSame('kabupaten_kota', dataset_norm_kunci('Kabupaten/Kota'));
        $this->assertSame('rp_anggaran', dataset_norm_kunci('Rp. Anggaran!!!'));
    }

    public function testNormKunciDiawaliAngkaDiberiPrefiks(): void
    {
        $this->assertSame('k_2026', dataset_norm_kunci('2026'));
        $this->assertSame('k_1_kuartal', dataset_norm_kunci('1 Kuartal'));
    }

    public function testNormKunciKosongMenjadiKolom(): void
    {
        $this->assertSame('kolom', dataset_norm_kunci(''));
        $this->assertSame('kolom', dataset_norm_kunci('   '));
        $this->assertSame('kolom', dataset_norm_kunci('!!!///'));
    }

    public function testNormKunciSelaluAmanUntukSql(): void
    {
        // Termasuk upaya injeksi lewat nama kolom.
        $jahat = [
            "nama'; DROP TABLE users;--",
            'a"b`c',
            'kolom$%^&*()',
            '../../etc/passwd',
            'SELECT * FROM users',
            "x' OR '1'='1",
        ];
        foreach ($jahat as $label) {
            $kunci = dataset_norm_kunci($label);
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_]{1,64}$/',
                $kunci,
                "Kunci dari label berbahaya harus aman: {$label} -> {$kunci}"
            );
        }
    }

    public function testNormKunciPanjangDipotong(): void
    {
        $kunci = dataset_norm_kunci(str_repeat('a', 300));
        $this->assertLessThanOrEqual(64, strlen($kunci));
    }

    public function testNormKunciDeduplikasi(): void
    {
        $terpakai = [];
        $a = dataset_norm_kunci('Nama', $terpakai);
        $b = dataset_norm_kunci('Nama', $terpakai);
        $c = dataset_norm_kunci('nama', $terpakai);

        $this->assertSame('nama', $a);
        $this->assertSame('nama_2', $b);
        $this->assertSame('nama_3', $c);
    }

    public function testKunciSah(): void
    {
        $this->assertTrue(dataset_kunci_sah('nama_pic'));
        $this->assertTrue(dataset_kunci_sah('a'));
        $this->assertTrue(dataset_kunci_sah('k_2026'));
        $this->assertTrue(dataset_kunci_sah(str_repeat('a', 64)));

        $this->assertFalse(dataset_kunci_sah(''));
        $this->assertFalse(dataset_kunci_sah(str_repeat('a', 65)));
        $this->assertFalse(dataset_kunci_sah('Nama'));
        $this->assertFalse(dataset_kunci_sah('nama-pic'));
        $this->assertFalse(dataset_kunci_sah("nama'; DROP"));
        $this->assertFalse(dataset_kunci_sah('nama kunci'));
        $this->assertFalse(dataset_kunci_sah('nama.kunci'));
    }

    /* =====================================================
     * Tebak tipe kolom
     * ===================================================== */

    public function testTebakTipeAngka(): void
    {
        $this->assertSame('angka', dataset_tebak_tipe(['1', '2', '3', '4']));
        $this->assertSame('angka', dataset_tebak_tipe(['1.500', '2.750', '3.000']));
        $this->assertSame('angka', dataset_tebak_tipe(['1000', '2000', '3000', '4000', '']));
    }

    public function testTebakTipeTanggalTeks(): void
    {
        $this->assertSame('tanggal', dataset_tebak_tipe(['2026-01-05', '2026-02-11', '2026-03-20']));
        $this->assertSame('tanggal', dataset_tebak_tipe(['05/01/2026', '11/02/2026', '20/03/2026']));
        $this->assertSame('tanggal', dataset_tebak_tipe(['5 Januari 2026', '11 Februari 2026']));
    }

    public function testTebakTipeTanggalSerialExcel(): void
    {
        // Serial Excel: angka murni 20000..60000 -> harus dianggap tanggal,
        // bukan angka biasa (karena lolos uji angka juga).
        $this->assertSame('tanggal', dataset_tebak_tipe(['45000', '45001', '45002']));
    }

    public function testTebakTipeTeks(): void
    {
        $this->assertSame('teks', dataset_tebak_tipe(['Duri', 'Mandau', 'Pinggir']));
        $this->assertSame('teks', dataset_tebak_tipe(['A', 'B', 'C', 'D']));
    }

    public function testTebakTipeKolomKosongSeluruhnya(): void
    {
        $this->assertSame('teks', dataset_tebak_tipe(['', '', '']));
        $this->assertSame('teks', dataset_tebak_tipe([]));
    }

    public function testTebakTipeCampuranMayoritasMenang(): void
    {
        // 4 angka, 1 teks -> angka (>= 60%).
        $this->assertSame('angka', dataset_tebak_tipe(['1', '2', '3', '4', 'catatan']));
        // 3 teks, 1 angka -> teks.
        $this->assertSame('teks', dataset_tebak_tipe(['Duri', 'Mandau', 'Pinggir', '1']));
    }

    /* =====================================================
     * Normalisasi tanggal (untuk sumbu tren grafik)
     * ===================================================== */

    public function testTanggalNamaBulanIndonesia(): void
    {
        $this->assertSame('2026-01-05', dataset_tanggal_nama_bulan('5 Januari 2026'));
        $this->assertSame('2026-02-11', dataset_tanggal_nama_bulan('11 Feb 2026'));
        $this->assertSame('2026-05-01', dataset_tanggal_nama_bulan('Mei 2026'));
        $this->assertNull(dataset_tanggal_nama_bulan('Duri'));
        $this->assertNull(dataset_tanggal_nama_bulan('2026-01-05'));
    }

    public function testTanggalkanMenerimaSemuaBentukUmum(): void
    {
        $this->assertSame('2026-01-05', dataset_tanggalkan('2026-01-05'));
        $this->assertSame('2026-01-05', dataset_tanggalkan('05/01/2026'));
        $this->assertSame('2026-01-05', dataset_tanggalkan('5 Januari 2026'));
        $this->assertSame('2023-03-15', dataset_tanggalkan('45000'));
        $this->assertNull(dataset_tanggalkan('Duri'));
        $this->assertNull(dataset_tanggalkan(''));
    }

    /* =====================================================
     * Daftar kunci profil (dipakai untuk memvalidasi kolom)
     * ===================================================== */

    public function testProfilSemuaKunciSahUntukDipakaiDiSql(): void
    {
        $profil = dataset_profil_kolom(
            ["Nama'; DROP TABLE users;--", 'Kota/Kab.', '2026', 'Rp. Anggaran (%)'],
            [['a', 'b', 'c', 'd']]
        );
        foreach ($profil as $k) {
            $this->assertTrue(
                dataset_kunci_sah((string) $k['kunci']),
                "Kunci profil harus sah: {$k['kunci']}"
            );
        }
    }

    /* =====================================================
     * Profil kolom
     * ===================================================== */

    public function testProfilKolomMenghitungKosongDanTipe(): void
    {
        $header = ['Nama PIC', 'Anggaran', 'Tanggal', 'Area'];
        $baris = [
            ['Andi', '1500', '2026-01-05', 'Duri'],
            ['', '2500', '', 'Mandau'],
            ['Budi', '', '2026-03-01', ''],
        ];

        $profil = dataset_profil_kolom($header, $baris);
        $this->assertCount(4, $profil);

        $this->assertSame('nama_pic', $profil[0]['kunci']);
        $this->assertSame('Nama PIC', $profil[0]['label']);
        $this->assertSame('teks', $profil[0]['tipe']);
        $this->assertSame(1, $profil[0]['kosong']);

        $this->assertSame('anggaran', $profil[1]['kunci']);
        $this->assertSame('angka', $profil[1]['tipe']);
        $this->assertSame(1, $profil[1]['kosong']);

        $this->assertSame('tanggal', $profil[2]['tipe']);
        $this->assertSame(1, $profil[2]['kosong']);

        $this->assertSame('area', $profil[3]['kunci']);
        $this->assertSame('teks', $profil[3]['tipe']);
        $this->assertSame(1, $profil[3]['kosong']);
    }

    public function testProfilKolomLabelKosongPakaiKunci(): void
    {
        $profil = dataset_profil_kolom(['', 'Nama'], [['a', 'b']]);
        $this->assertSame('kolom', $profil[0]['kunci']);
        $this->assertSame('kolom', $profil[0]['label']);
    }

    public function testProfilKolomHeaderDuplikatUnik(): void
    {
        $profil = dataset_profil_kolom(['Nama', 'Nama', 'Nama'], [['a', 'b', 'c']]);
        $kunci = array_column($profil, 'kunci');
        $this->assertSame(['nama', 'nama_2', 'nama_3'], $kunci);
        $this->assertSame(count($kunci), count(array_unique($kunci)));
    }

    /* =====================================================
     * Baris -> JSON
     * ===================================================== */

    public function testBarisKeJson(): void
    {
        $profil = [
            ['kunci' => 'nama'],
            ['kunci' => 'angka'],
        ];
        $json = dataset_baris_ke_json(['Andi', '100'], $profil);
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertSame('Andi', $data['nama']);
        $this->assertSame('100', $data['angka']);
    }

    public function testBarisKeJsonKolomKurangMenjadiKosong(): void
    {
        $profil = [['kunci' => 'a'], ['kunci' => 'b']];
        $data = json_decode(dataset_baris_ke_json(['x'], $profil), true);
        $this->assertSame('x', $data['a']);
        $this->assertSame('', $data['b']);
    }

    public function testBarisKeJsonAmanTerhadapUnicode(): void
    {
        $profil = [['kunci' => 'catatan']];
        $json = dataset_baris_ke_json(['Mêngõng “kutipan”'], $profil);
        $data = json_decode($json, true);
        $this->assertSame('Mêngõng “kutipan”', $data['catatan']);
        // JSON_HEX tidak dipakai di sini, tapi harus valid JSON apa pun isinya.
        $this->assertNotNull($data);
    }

    /* =====================================================
     * Slug
     * ===================================================== */

    public function testSlug(): void
    {
        $this->assertSame('data-anggaran-2026', dataset_slug('Data Anggaran 2026'));
        $this->assertSame('dataset', dataset_slug('!!!'));
        $this->assertSame('srm-sosial', dataset_slug('  SRM / Sosial  '));
    }

    public function testSlugUnik(): void
    {
        $terpakai = [];
        $a = dataset_slug('Data', $terpakai);
        $b = dataset_slug('Data', $terpakai);
        $this->assertSame('data', $a);
        $this->assertSame('data-2', $b);
    }

    /* =====================================================
     * Batas ukuran
     * ===================================================== */

    public function testBatasMaksPositif(): void
    {
        $this->assertGreaterThan(0, dataset_jumlah_maks_baris());
        $this->assertGreaterThan(0, dataset_jumlah_maks_kolom());
    }
}
