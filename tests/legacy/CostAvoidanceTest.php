<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CostAvoidanceTest extends TestCase
{
    public function testDaftarKategoriTetap(): void
    {
        $kategori = cost_avoidance_kategori_daftar();

        $this->assertCount(9, $kategori);
        $this->assertContains('Negosiasi Pengadaan', $kategori);
        $this->assertContains('Substitusi Material', $kategori);
        $this->assertContains('Pengurangan Downtime', $kategori);
        $this->assertContains('Lainnya', $kategori);
        $this->assertSame(array_values(array_unique($kategori)), $kategori);
    }

    public function testDaftarStatusDanLabel(): void
    {
        $this->assertSame(['draft', 'diajukan', 'disetujui', 'ditolak'], cost_avoidance_status_daftar());
        $this->assertSame('Draft', cost_avoidance_label_status('draft'));
        $this->assertSame('Menunggu Verifikasi', cost_avoidance_label_status('diajukan'));
        $this->assertSame('Disetujui', cost_avoidance_label_status('disetujui'));
        $this->assertSame('Ditolak', cost_avoidance_label_status('ditolak'));
    }

    public function testKelasStatus(): void
    {
        $this->assertSame('ca-st-draft', cost_avoidance_kelas_status('draft'));
        $this->assertSame('ca-st-diajukan', cost_avoidance_kelas_status('diajukan'));
        $this->assertSame('ca-st-disetujui', cost_avoidance_kelas_status('disetujui'));
        $this->assertSame('ca-st-ditolak', cost_avoidance_kelas_status('ditolak'));
    }

    public function testFormatRupiah(): void
    {
        $this->assertSame('Rp 1.500.000', cost_avoidance_rupiah(1500000));
        $this->assertSame('Rp 0', cost_avoidance_rupiah(0));
        $this->assertSame('Rp 2.500', cost_avoidance_rupiah('2500.4'));
        $this->assertSame('Rp 999.999.999.999', cost_avoidance_rupiah(999999999999));
    }

    public function testFormatRingkas(): void
    {
        $this->assertSame('500', cost_avoidance_ringkas_rupiah(500));
        $this->assertSame('2 rb', cost_avoidance_ringkas_rupiah(2000));
        $this->assertSame('1,5 jt', cost_avoidance_ringkas_rupiah(1500000));
        $this->assertSame('2,50 M', cost_avoidance_ringkas_rupiah(2500000000));
        $this->assertSame('1,00 T', cost_avoidance_ringkas_rupiah(1000000000000));
    }

    public function testParsingAngkaGayaIndonesia(): void
    {
        $this->assertSame(1500000.0, cost_avoidance_angka('1.500.000'));
        $this->assertSame(1500000.0, cost_avoidance_angka('Rp 1.500.000'));
        $this->assertSame(1500000.5, cost_avoidance_angka('1.500.000,50'));
        $this->assertSame(1500000.5, cost_avoidance_angka('1500000.5'));
        $this->assertSame(2500.0, cost_avoidance_angka('2500'));
        $this->assertNull(cost_avoidance_angka(''));
        $this->assertNull(cost_avoidance_angka('abc'));
    }

    public function testNormalisasiPeriode(): void
    {
        $this->assertSame('2026-08-01', cost_avoidance_normal_periode('2026-08'));
        $this->assertSame('2026-08-01', cost_avoidance_normal_periode('2026-08-17'));
        $this->assertNull(cost_avoidance_normal_periode('2026-13'));
        $this->assertNull(cost_avoidance_normal_periode(''));
    }

    public function testValidasiMenolakRealisasiMelebihiEstimasi(): void
    {
        $hasil = cost_avoidance_validasi([
            'judul' => 'Negosiasi vendor',
            'kategori' => 'Negosiasi Pengadaan',
            'periode' => '2026-08',
            'estimasi' => '1000000',
            'realisasi' => '1500000',
        ]);

        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('realisasi', $hasil['galat']);
    }

    public function testValidasiMenolakKategoriTidakDikenal(): void
    {
        $hasil = cost_avoidance_validasi([
            'judul' => 'Contoh',
            'kategori' => 'Kategori Karangan',
            'periode' => '2026-08',
            'estimasi' => '100',
        ]);

        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('kategori', $hasil['galat']);
    }

    public function testValidasiMenolakJudulKosong(): void
    {
        $hasil = cost_avoidance_validasi([
            'judul' => '   ',
            'kategori' => 'Lainnya',
            'periode' => '2026-08',
            'estimasi' => '100',
        ]);

        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('judul', $hasil['galat']);
    }

    public function testValidasiMenghitungNilaiAvoidance(): void
    {
        $hasil = cost_avoidance_validasi([
            'judul' => 'Substitusi material',
            'kategori' => 'Substitusi Material',
            'periode' => '2026-08',
            'estimasi' => '2.000.000',
            'realisasi' => '750.000',
            'unit' => 'Divisi Teknik',
            'deskripsi' => 'Material alternatif',
        ]);

        $this->assertTrue($hasil['ok']);
        $this->assertSame([], $hasil['galat']);
        $this->assertSame('2026-08-01', $hasil['data']['periode']);
        $this->assertSame(1250000.0, $hasil['data']['nilai_avoidance']);
        $this->assertSame(2000000.0, $hasil['data']['estimasi']);
        $this->assertSame(750000.0, $hasil['data']['realisasi']);
    }

    public function testValidasiRealisasiKosongDianggapNol(): void
    {
        $hasil = cost_avoidance_validasi([
            'judul' => 'Pekerjaan in-house',
            'kategori' => 'Pekerjaan In-house',
            'periode' => '2026-07',
            'estimasi' => '500000',
            'realisasi' => '',
        ]);

        $this->assertTrue($hasil['ok']);
        $this->assertSame(0.0, $hasil['data']['realisasi']);
        $this->assertSame(500000.0, $hasil['data']['nilai_avoidance']);
    }

    public function testVisibilitasDanHakUbah(): void
    {
        $row = ['id' => 1, 'dibuat_oleh' => 5, 'status' => 'draft'];

        $this->assertTrue(cost_avoidance_boleh_lihat($row, 5, 'karyawan'));
        $this->assertFalse(cost_avoidance_boleh_lihat($row, 6, 'karyawan'));
        $this->assertTrue(cost_avoidance_boleh_lihat($row, 6, 'mentor'));

        $this->assertTrue(cost_avoidance_boleh_ubah($row, 5, 'karyawan'));
        $this->assertFalse(cost_avoidance_boleh_ubah($row, 6, 'mentor'));
        $this->assertTrue(cost_avoidance_boleh_ubah($row, 6, 'admin'));

        $row['status'] = 'diajukan';
        $this->assertFalse(cost_avoidance_boleh_ubah($row, 5, 'karyawan'));

        $row['status'] = 'ditolak';
        $this->assertTrue(cost_avoidance_boleh_ubah($row, 5, 'karyawan'));
    }

    public function testHakVerifikasi(): void
    {
        $row = ['id' => 1, 'dibuat_oleh' => 5, 'status' => 'diajukan'];

        $this->assertFalse(cost_avoidance_boleh_verifikasi($row, 5, 'karyawan'));
        $this->assertFalse(cost_avoidance_boleh_verifikasi($row, 5, 'mentor'));
        $this->assertTrue(cost_avoidance_boleh_verifikasi($row, 5, 'admin'));
        $this->assertTrue(cost_avoidance_boleh_verifikasi($row, 6, 'mentor'));

        $row['status'] = 'draft';
        $this->assertFalse(cost_avoidance_boleh_verifikasi($row, 6, 'mentor'));
    }

    public function testPeriodeLabel(): void
    {
        $this->assertSame('Agustus 2026', cost_avoidance_periode_label('2026-08-01'));
        $this->assertSame('Januari 2025', cost_avoidance_periode_label('2025-01-01'));
        $this->assertSame('-', cost_avoidance_periode_label(null));
    }
}
