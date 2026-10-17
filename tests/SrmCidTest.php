<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji fungsi murni mesin impor SRM & CID (includes/srm_cid.php).
 * Tidak menyentuh basis data.
 */
final class SrmCidTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    /* =====================================================
     * Normalisasi teks
     * ===================================================== */

    public function testBersihMemangkasDanMengosongkan(): void
    {
        $this->assertSame('Duri', srm_cid_bersih('  Duri  '));
        $this->assertNull(srm_cid_bersih(''));
        $this->assertNull(srm_cid_bersih('   '));
        $this->assertNull(srm_cid_bersih(null));
        $this->assertSame('1', srm_cid_bersih(true));
        $this->assertSame('0', srm_cid_bersih(false));
    }

    /* =====================================================
     * Angka: format Indonesia, rumus, ribuan
     * ===================================================== */

    public function testAngkaFormatUmum(): void
    {
        $this->assertSame(1234.0, srm_cid_angka(1234));
        $this->assertSame(12.5, srm_cid_angka(12.5));
        $this->assertSame(1000.0, srm_cid_angka('1000'));
        $this->assertNull(srm_cid_angka(''));
        $this->assertNull(srm_cid_angka(null));
    }

    public function testAngkaFormatIndonesia(): void
    {
        // Titik ribuan, koma desimal
        $this->assertSame(1234567.0, srm_cid_angka('1.234.567'));
        $this->assertSame(1.5, srm_cid_angka('1,5'));
        $this->assertSame(1234567.89, srm_cid_angka('1.234.567,89'));
        // Mata uang & spasi dibuang
        $this->assertSame(50000000.0, srm_cid_angka('Rp 50.000.000'));
    }

    public function testAngkaMengabaikanSelRumus(): void
    {
        $this->assertNull(srm_cid_angka('=R2+S2+T2+U2+V2'));
        $this->assertNull(srm_cid_angka('=AJ7+5000000'));
    }

    /* =====================================================
     * Bulan
     * ===================================================== */

    public function testBulanAngka(): void
    {
        $this->assertSame(1, cid_bulan_angka('Jan'));
        $this->assertSame(1, cid_bulan_angka('Januari'));
        $this->assertSame(8, cid_bulan_angka('Agu'));
        $this->assertSame(8, cid_bulan_angka('Agustus'));
        $this->assertSame(10, cid_bulan_angka('okt'));
        $this->assertSame(12, cid_bulan_angka('Des'));
        $this->assertNull(cid_bulan_angka(''));
        $this->assertNull(cid_bulan_angka('Triwulan'));
        $this->assertNull(cid_bulan_angka(null));
    }

    /* =====================================================
     * Normalisasi header
     * ===================================================== */

    public function testNormHeader(): void
    {
        $this->assertSame('status', srm_cid_norm_header('Status'));
        $this->assertSame('statuspersen', srm_cid_norm_header('Status (%)'));
        $this->assertSame('kabupatenkota', srm_cid_norm_header('Kabupaten/Kota'));
        $this->assertSame('areafield', srm_cid_norm_header('Area/Field'));
        $this->assertSame('penanamanpohonbibit', srm_cid_norm_header("Penanaman Pohon\n(Bibit)"));
        $this->assertSame('kelurahandesa', srm_cid_norm_header('Kelurahan/Desa'));
    }

    public function testPetaKolomDikenali(): void
    {
        $srm = srm_peta_kolom();
        $this->assertSame('tahun_intervensi', $srm['tahunintervensi']);
        $this->assertSame('persen_status', $srm['statuspersen']);
        $this->assertSame('status', $srm['status']);
        $this->assertSame('kabupaten_kota', $srm['kabupatenkota']);

        $cid = cid_peta_kolom();
        $this->assertSame('total_penerima_manfaat', $cid['totalpenerimamanfaatlangsung']);
        $this->assertSame('bulan_angka', $cid['monthnumber']);
        $this->assertSame('anggaran', $cid['anggaran']);
        $this->assertSame('kelompok_diberdayakan', $cid['kelompokdiperdayakan']);
    }

    public function testStatusDanPersenTidakBentrok(): void
    {
        // "Status" dan "Status (%)" harus memetakan ke kolom berbeda.
        $peta = srm_peta_kolom();
        $this->assertNotSame($peta['status'], $peta['statuspersen']);
    }

    /* =====================================================
     * Tanggal: serial Excel & teks
     * ===================================================== */

    public function testTanggalSerialExcel(): void
    {
        // 2025-01-01 = serial 45658
        $this->assertSame('2025-01-01', srm_cid_tanggal(45658));
    }

    public function testTanggalTeks(): void
    {
        $this->assertSame('2026-07-01', srm_cid_tanggal('01/07/2026'));
        $this->assertSame('2026-07-01', srm_cid_tanggal('2026-07-01'));
        $this->assertNull(srm_cid_tanggal(''));
        $this->assertNull(srm_cid_tanggal(null));
    }

    /* =====================================================
     * Pembantu tampilan
     * ===================================================== */

    public function testAngkaId(): void
    {
        $this->assertSame('1.234.567', srm_cid_angka_id(1234567));
        $this->assertSame('-', srm_cid_angka_id(null));
        $this->assertSame('2.308', srm_cid_angka_id(2308));
    }

    public function testRupiahRingkas(): void
    {
        $this->assertSame('Rp 250.000', srm_cid_rupiah(250000));
        $this->assertSame('Rp 12,9 jt', srm_cid_rupiah(12922300));
        $this->assertSame('Rp 1,50 M', srm_cid_rupiah(1500000000));
        $this->assertSame('-', srm_cid_rupiah(null));
    }
}
