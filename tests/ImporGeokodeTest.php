<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji perilaku impor yang baru: jenis per peran, normalisasi koordinat,
 * validasi dengan pengguna yang dipaksa (karyawan hanya data sendiri),
 * dan normalisasi kunci geokode.
 *
 * Semua fungsi yang diuji bersifat murni (tidak menyentuh basis data).
 */
final class ImporGeokodeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /* =====================================================
     * Jenis impor per peran
     * ===================================================== */

    public function testKaryawanHanyaBolehLaporanDanAbsensi(): void
    {
        $jenis = impor_jenis_untuk_role('karyawan');
        $this->assertArrayHasKey('laporan_harian', $jenis);
        $this->assertArrayHasKey('absensi', $jenis);
        $this->assertArrayNotHasKey('srm_cid', $jenis);
    }

    public function testMentorDanAdminBolehSrmCid(): void
    {
        foreach (['mentor', 'admin'] as $peran) {
            $jenis = impor_jenis_untuk_role($peran);
            $this->assertArrayHasKey('laporan_harian', $jenis);
            $this->assertArrayHasKey('absensi', $jenis);
            $this->assertArrayHasKey('srm_cid', $jenis);
        }
    }

    public function testJenisTidakDikenalTetapDasar(): void
    {
        $jenis = impor_jenis_untuk_role('tamu');
        $this->assertArrayNotHasKey('srm_cid', $jenis);
        $this->assertSame(impor_jenis_tersedia(), impor_jenis_untuk_role('tamu'));
    }

    /* =====================================================
     * Normalisasi koordinat dari berkas
     * ===================================================== */

    public function testKoordinatSahDikembalikan(): void
    {
        $this->assertSame(['lat' => '1.2787247', 'lng' => '101.0852849'],
            impor_norm_koordinat('1,2787247', '101.0852849'));
        $this->assertSame(['lat' => '-1.5000000', 'lng' => '100.0000000'],
            impor_norm_koordinat('-1.5', '100'));
    }

    public function testKoordinatKosongAtauSalahDikosongkan(): void
    {
        $this->assertSame(['lat' => '', 'lng' => ''], impor_norm_koordinat('', ''));
        $this->assertSame(['lat' => '', 'lng' => ''], impor_norm_koordinat('999', '999'));
        $this->assertSame(['lat' => '', 'lng' => ''], impor_norm_koordinat('abc', '100'));
        // 0,0 bukan koordinat yang sah.
        $this->assertSame(['lat' => '', 'lng' => ''], impor_norm_koordinat('0', '0'));
        // Salah satu kosong -> keduanya dikosongkan.
        $this->assertSame(['lat' => '', 'lng' => ''], impor_norm_koordinat('1.5', ''));
    }

    /* =====================================================
     * Validasi dengan pengguna yang dipaksa
     * ===================================================== */

    public function testKaryawanTanpaKolomPenggunaTetapSah(): void
    {
        $baris = [
            ['Survei Sumur A', '2026-10-10', 'Bengkalis', '1.7697227', '101.9124384'],
            ['Kunjungan Warga', '2026-10-11', 'Pekanbaru', '', ''],
        ];
        $pemetaan = [
            'kegiatan' => 0,
            'tanggal' => 1,
            'lokasi_teks' => 2,
            'garis_lintang' => 3,
            'garis_bujur' => 4,
        ];

        $hasil = impor_validasi($baris, $pemetaan, 'laporan_harian', [], ['paksa_user_id' => 42]);

        $this->assertTrue($hasil['ok']);
        $this->assertSame(2, $hasil['ringkas']['sah']);
        foreach ($hasil['baris'] as $b) {
            $this->assertTrue($b['sah']);
            $this->assertSame(42, $b['data']['user_id']);
        }
        // Baris pertama memakai koordinat berkas, baris kedua dikosongkan.
        $this->assertSame('1.7697227', $hasil['baris'][0]['data']['garis_lintang']);
        $this->assertSame('101.9124384', $hasil['baris'][0]['data']['garis_bujur']);
        $this->assertSame('', $hasil['baris'][1]['data']['garis_lintang']);
        $this->assertSame('', $hasil['baris'][1]['data']['garis_bujur']);
    }

    public function testTanpaPaksaPenggunaWajibDipetakan(): void
    {
        $baris = [['', 'Survei', '2026-10-10']];
        $pemetaan = ['kegiatan' => 1, 'tanggal' => 2];

        // Kolom "pengguna" wajib -> validasi gagal saat tidak dipetakan.
        $hasil = impor_validasi($baris, $pemetaan, 'laporan_harian', []);
        $this->assertFalse($hasil['ok']);

        // Bila dipaksa, kegagalan itu hilang.
        $hasilPaksa = impor_validasi($baris, $pemetaan, 'laporan_harian', [], ['paksa_user_id' => 7]);
        $this->assertTrue($hasilPaksa['ok']);
        $this->assertSame(7, $hasilPaksa['baris'][0]['data']['user_id']);
    }

    /* =====================================================
     * Kunci geokode
     * ===================================================== */

    public function testGeokodeNormMenurunkanHurufDanSpasi(): void
    {
        $this->assertSame('desa buluh manis', wilayah_geokode_norm('  Desa   Buluh   Manis '));
        $this->assertSame('bengkalis', wilayah_geokode_norm('BENGKALIS'));
        $this->assertSame('', wilayah_geokode_norm('   '));
    }

    public function testGeokodeNormDipotong191Karakter(): void
    {
        $panjang = str_repeat('a', 300);
        $this->assertSame(191, mb_strlen(wilayah_geokode_norm($panjang)));
    }
}
