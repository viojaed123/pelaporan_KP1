<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji fungsi murni pemetaan lokasi (includes/wilayah.php).
 */
final class WilayahTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        $autoload = __DIR__ . '/../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }

    public function testWilayahNormMenyederhanakanNama(): void
    {
        $this->assertSame('buluh manis', wilayah_norm('Desa Buluh Manis'));
        $this->assertSame('bathin solapan', wilayah_norm('Bathin Solapan  '));
        $this->assertSame('boncah mahang', wilayah_norm('  Desa   Boncah Mahang  '));
        $this->assertSame('sintong makmur', wilayah_norm('esa/Kepenghuluan Sintong Makmur'));
        $this->assertSame('rokan hilir', wilayah_norm('Rokan Hilir'));
    }

    public function testWilayahNormMenghapusIsiKurung(): void
    {
        $this->assertSame('dumai', wilayah_norm('Dumai (Kota)'));
    }

    public function testWilayahPetaUrlMengembalikanUrlValid(): void
    {
        $url = wilayah_peta_url('Desa Buluh Manis, Bathin Solapan');
        $this->assertNotSame('', $url);
        $this->assertStringContainsString('maps/search', $url);
        $this->assertStringContainsString(rawurlencode('Desa Buluh Manis, Bathin Solapan'), $url);
    }

    public function testWilayahPetaUrlKosongBilaTakAdaIsi(): void
    {
        $this->assertSame('', wilayah_peta_url(null, []));
        $this->assertSame('', wilayah_peta_url('   ', []));
    }

    public function testWilayahTitikUrlMembentukKoordinat(): void
    {
        $url = wilayah_titik_url(1.2787247, 101.0852849);
        $this->assertStringContainsString('maps/search', $url);
        $this->assertStringContainsString('1.2787247,101.0852849', str_replace(['%2C', '%2E'], [',', '.'], $url) ?: $url);
    }

    public function testWilayahKoordinatValid(): void
    {
        $this->assertTrue(wilayah_koordinat_valid(0.5, 100.5));
        $this->assertFalse(wilayah_koordinat_valid(0.0, 0.0));
        $this->assertFalse(wilayah_koordinat_valid(null, 100));
        $this->assertFalse(wilayah_koordinat_valid(91, 0));
        $this->assertFalse(wilayah_koordinat_valid(0, 181));
    }

    public function testWilayahBersihNamaMenghapusAwalan(): void
    {
        $this->assertSame('Buluh Manis', wilayah_bersih_nama('Desa Buluh Manis'));
        $this->assertSame('Pematang Pudu', wilayah_bersih_nama('Kel. Pematang Pudu'));
        $this->assertSame('Rantau Kopar', wilayah_bersih_nama('  Kecamatan Rantau Kopar  '));
    }
}
