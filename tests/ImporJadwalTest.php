<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji fungsi murni mesin impor (includes/impor_excel.php) dan agenda
 * (includes/jadwal.php). Tidak menyentuh basis data.
 */
final class ImporJadwalTest extends TestCase
{
    /* =====================================================
     * Impor — definisi medan & pengaturan
     * ===================================================== */

    public function testEkstensiDanJenisImpor(): void
    {
        $this->assertSame(['xlsx', 'xls', 'csv', 'txt'], impor_ekstensi_diizinkan());
        $jenis = impor_jenis_tersedia();
        $this->assertArrayHasKey('laporan_harian', $jenis);
        $this->assertArrayHasKey('absensi', $jenis);
    }

    public function testMedanWajibPerJenis(): void
    {
        $lap = impor_field('laporan_harian');
        $this->assertTrue($lap['pengguna']['wajib']);
        $this->assertTrue($lap['tanggal']['wajib']);
        $this->assertTrue($lap['kegiatan']['wajib']);
        $this->assertFalse($lap['deskripsi']['wajib']);
        $this->assertArrayHasKey('lokasi_teks', $lap);
        $this->assertFalse($lap['lokasi_teks']['wajib']);

        $abs = impor_field('absensi');
        $this->assertTrue($abs['pengguna']['wajib']);
        $this->assertTrue($abs['tanggal']['wajib']);
        $this->assertFalse($abs['jam_masuk']['wajib']);
        $this->assertArrayHasKey('lokasi_teks', $abs);
        $this->assertFalse($abs['lokasi_teks']['wajib']);
    }

    /* =====================================================
     * Impor — normalisasi & header
     * ===================================================== */

    public function testNormalKunci(): void
    {
        $this->assertSame('jam mulai', impor_normal_kunci('Jam_Mulai'));
        $this->assertSame('rencana berikutnya', impor_normal_kunci('Rencana   Berikutnya!'));
        $this->assertSame('', impor_normal_kunci('   '));
    }

    public function testTebakPemetaanLaporan(): void
    {
        $header = ['Tanggal', 'Nama', 'Kegiatan', 'Jam Mulai', 'Jam Selesai', 'Deskripsi'];
        $peta = impor_tebak_pemetaan($header, 'laporan_harian');
        $this->assertSame(1, $peta['pengguna']);
        $this->assertSame(0, $peta['tanggal']);
        $this->assertSame(2, $peta['kegiatan']);
        $this->assertSame(3, $peta['jam_mulai']);
        $this->assertSame(4, $peta['jam_selesai']);
        $this->assertSame(5, $peta['deskripsi']);
    }

    public function testTebakPemetaanAbsensi(): void
    {
        $header = ['Tanggal', 'Email', 'Jam Masuk', 'Jam Pulang', 'Status', 'Keterangan', 'Kegiatan', 'Kesehatan'];
        $peta = impor_tebak_pemetaan($header, 'absensi');
        $this->assertSame(1, $peta['pengguna']);
        $this->assertSame(0, $peta['tanggal']);
        $this->assertSame(2, $peta['jam_masuk']);
        $this->assertSame(3, $peta['jam_pulang']);
        $this->assertSame(4, $peta['status']);
        $this->assertSame(5, $peta['keterangan']);
        $this->assertSame(6, $peta['kegiatan_hari_ini']);
        $this->assertSame(7, $peta['kondisi_kesehatan']);
    }

    public function testRapikanHeaderMengisiDanMenghilangkanDuplikat(): void
    {
        $hasil = impor_rapikan_header(['', 'Nama', 'nama'], 3);
        $this->assertSame('Kolom 1', $hasil[0]);
        $this->assertSame('Nama', $hasil[1]);
        $this->assertSame('nama (2)', $hasil[2]);
    }

    public function testSusunHeaderDanBaris(): void
    {
        $hasil = impor_susun([['A', 'B'], ['1', '2'], ['3']], 'CSV', false);
        $this->assertTrue($hasil['ok']);
        $this->assertSame(['A', 'B'], $hasil['header']);
        $this->assertCount(2, $hasil['baris']);
        $this->assertSame(['3', ''], $hasil['baris'][1]);
    }

    public function testSusunKosongGagal(): void
    {
        $hasil = impor_susun([], 'CSV', false);
        $this->assertFalse($hasil['ok']);
    }

    public function testDeteksiPemisah(): void
    {
        $this->assertSame(';', impor_deteksi_pemisah('a;b;c'));
        $this->assertSame(',', impor_deteksi_pemisah('a,b,c'));
        $this->assertSame("\t", impor_deteksi_pemisah("a\tb\tc"));
        $this->assertSame(',', impor_deteksi_pemisah('abc'));
    }

    /* =====================================================
     * Impor — tanggal & jam
     * ===================================================== */

    public function testNormTanggalFormatUmum(): void
    {
        $this->assertSame('2026-10-17', impor_norm_tanggal('2026-10-17'));
        $this->assertSame('2026-10-17', impor_norm_tanggal('17/10/2026'));
        $this->assertSame('2026-10-17', impor_norm_tanggal('17-10-2026'));
        $this->assertSame('2026-10-17', impor_norm_tanggal('20261017'));
        $this->assertSame('2026-10-17', impor_norm_tanggal('2026-10-17 08:30:00'));
        $this->assertSame('2024-01-01', impor_norm_tanggal(45292));
        $this->assertNull(impor_norm_tanggal('bukan tanggal'));
        $this->assertNull(impor_norm_tanggal(''));
    }

    public function testTanggalSah(): void
    {
        $this->assertTrue(impor_tanggal_sah('2026-02-28'));
        $this->assertFalse(impor_tanggal_sah('2026-02-30'));
        $this->assertFalse(impor_tanggal_sah('2026-13-01'));
    }

    public function testNormJamFormatUmum(): void
    {
        $this->assertSame('07:30:00', impor_norm_jam('07:30'));
        $this->assertSame('07:30:00', impor_norm_jam('7.30'));
        $this->assertSame('07:30:00', impor_norm_jam('0730'));
        $this->assertSame('07:00:00', impor_norm_jam('7'));
        $this->assertSame('07:30:00', impor_norm_jam('0.3125'));
        $this->assertSame('14:30:15', impor_norm_jam('14:30:15'));
        $this->assertSame('14:00:00', impor_norm_jam('2:00 PM'));
        $this->assertNull(impor_norm_jam(''));
        $this->assertNull(impor_norm_jam('25:00'));
    }

    public function testNormStatusAbsensi(): void
    {
        $this->assertSame('hadir', impor_norm_status_absensi('Hadir'));
        $this->assertSame('izin', impor_norm_status_absensi('izin'));
        $this->assertSame('sakit', impor_norm_status_absensi('SAKIT'));
        $this->assertNull(impor_norm_status_absensi('entah'));
        $this->assertNull(impor_norm_status_absensi(''));
    }

    public function testNormKondisiKesehatan(): void
    {
        $this->assertSame('sehat', impor_norm_kondisi_kesehatan('Sehat'));
        $this->assertSame('kurang_sehat', impor_norm_kondisi_kesehatan('kurang_sehat'));
        $this->assertSame('pemulihan', impor_norm_kondisi_kesehatan('pulih'));
        $this->assertNull(impor_norm_kondisi_kesehatan('entah'));
    }

    public function testKeTeks(): void
    {
        $this->assertSame('', impor_ke_teks(null));
        $this->assertSame('1', impor_ke_teks(true));
        $this->assertSame('0', impor_ke_teks(false));
        $this->assertSame('3.5', impor_ke_teks(3.5));
        $this->assertSame('teks', impor_ke_teks('  teks  '));
    }

    /* =====================================================
     * Impor — pengguna & validasi
     * ===================================================== */

    public function testCariPengguna(): void
    {
        $peta = ['email:budi@x.id' => 5, 'id:7' => 7, 'nama:budi' => 5];
        $this->assertSame(5, impor_cari_pengguna($peta, 'Budi@X.id'));
        $this->assertSame(5, impor_cari_pengguna($peta, 'Budi'));
        $this->assertSame(7, impor_cari_pengguna($peta, '7'));
        $this->assertNull(impor_cari_pengguna($peta, 'zzz'));
        $this->assertNull(impor_cari_pengguna($peta, ''));
    }

    public function testAmbilKolom(): void
    {
        $baris = ['budi@x.id', '2026-10-17', 'Rapat'];
        $peta = ['pengguna' => 0, 'tanggal' => 1, 'kegiatan' => 2];
        $this->assertSame('budi@x.id', impor_ambil_kolom($baris, $peta, 'pengguna'));
        $this->assertSame('', impor_ambil_kolom($baris, $peta, 'deskripsi'));
    }

    public function testValidasiLaporanSukses(): void
    {
        $pemetaan = ['pengguna' => 0, 'tanggal' => 1, 'kegiatan' => 2];
        $petaPengguna = ['email:budi@x.id' => 5];
        $baris = [['budi@x.id', '2026-10-17', 'Rapat koordinasi']];

        $hasil = impor_validasi($baris, $pemetaan, 'laporan_harian', $petaPengguna, ['status_bawaan' => 'diperiksa']);
        $this->assertTrue($hasil['ok']);
        $this->assertSame(1, $hasil['ringkas']['total']);
        $this->assertSame(1, $hasil['ringkas']['sah']);
        $this->assertSame(0, $hasil['ringkas']['gagal']);
        $this->assertTrue($hasil['baris'][0]['sah']);
        $this->assertSame(5, $hasil['baris'][0]['data']['user_id']);
        $this->assertSame('diperiksa', $hasil['baris'][0]['data']['status']);
        // Deskripsi kosong diisi dari judul kegiatan.
        $this->assertSame('Rapat koordinasi', $hasil['baris'][0]['data']['deskripsi']);
    }

    public function testValidasiPenggunaTidakDitemukanGagal(): void
    {
        $pemetaan = ['pengguna' => 0, 'tanggal' => 1, 'kegiatan' => 2];
        $baris = [['tidak-ada@x.id', '2026-10-17', 'Rapat']];

        $hasil = impor_validasi($baris, $pemetaan, 'laporan_harian', []);
        $this->assertTrue($hasil['ok']);
        $this->assertSame(0, $hasil['ringkas']['sah']);
        $this->assertSame(1, $hasil['ringkas']['gagal']);
        $this->assertFalse($hasil['baris'][0]['sah']);
        $this->assertStringContainsString('tidak ditemukan', implode(' ', $hasil['baris'][0]['pesan']));
    }

    public function testValidasiKolomWajibBelumDipetakanGagal(): void
    {
        $hasil = impor_validasi([['a']], ['tanggal' => 0], 'laporan_harian', []);
        $this->assertFalse($hasil['ok']);
        $this->assertNotEmpty($hasil['galat']);
    }

    public function testValidasiAbsensiStatusBawaan(): void
    {
        $pemetaan = ['pengguna' => 0, 'tanggal' => 1];
        $petaPengguna = ['id:9' => 9];
        $baris = [['9', '2026-10-17']];

        $hasil = impor_validasi($baris, $pemetaan, 'absensi', $petaPengguna, [
            'status_bawaan' => 'izin',
            'kondisi_bawaan' => 'pemulihan',
        ]);
        $this->assertTrue($hasil['ok']);
        $this->assertTrue($hasil['baris'][0]['sah']);
        $this->assertSame('izin', $hasil['baris'][0]['data']['status']);
        $this->assertSame('pemulihan', $hasil['baris'][0]['data']['kondisi_kesehatan']);
    }

    public function testTokenBerkasMenolakYangTidakAman(): void
    {
        $this->assertNull(impor_berkas_dari_token('../rahasia', 'xlsx'));
        $this->assertNull(impor_berkas_dari_token('abc', 'xlsx'));
        $this->assertNull(impor_berkas_dari_token(str_repeat('a', 32), 'php'));
        // Token berformat benar tetapi berkas tidak ada.
        $this->assertNull(impor_berkas_dari_token(str_repeat('f', 32), 'xlsx'));
    }

    /* =====================================================
     * Jadwal — fungsi murni
     * ===================================================== */

    public function testStatusJadwal(): void
    {
        $this->assertSame(['rencana', 'selesai', 'batal'], jadwal_status_tersedia());
        $this->assertSame('Direncanakan', jadwal_label_status('rencana'));
        $this->assertSame('Selesai', jadwal_label_status('selesai'));
        $this->assertSame('Dibatalkan', jadwal_label_status('batal'));
        $this->assertSame('jadwal-rencana', jadwal_kelas_status('rencana'));
        $this->assertSame('jadwal-selesai', jadwal_kelas_status('selesai'));
        $this->assertSame('jadwal-batal', jadwal_kelas_status('batal'));
    }

    public function testNormalMulai(): void
    {
        $this->assertSame('2026-10-17 08:00:00', jadwal_normal_mulai('2026-10-17'));
        $this->assertSame('2026-10-17 09:30:00', jadwal_normal_mulai('2026-10-17 09:30'));
        $this->assertSame('2026-10-17 09:30:00', jadwal_normal_mulai('2026-10-17T09:30:00'));
        $this->assertSame('2026-10-17 14:30:00', jadwal_normal_mulai('17/10/2026 14:30'));
        $this->assertNull(jadwal_normal_mulai(''));
        $this->assertNull(jadwal_normal_mulai('bukan waktu'));
    }

    public function testValidasiJadwalSukses(): void
    {
        $hasil = jadwal_validasi([
            'judul' => 'Rapat mingguan',
            'mulai' => '2026-10-17 09:00',
            'selesai' => '2026-10-17 10:00',
            'pengingat_menit' => 15,
        ]);
        $this->assertTrue($hasil['ok']);
        $this->assertSame('Rapat mingguan', $hasil['data']['judul']);
        $this->assertSame('2026-10-17 09:00:00', $hasil['data']['mulai']);
        $this->assertSame('2026-10-17 10:00:00', $hasil['data']['selesai']);
        $this->assertSame(15, $hasil['data']['pengingat_menit']);
        $this->assertSame('rencana', $hasil['data']['status']);
    }

    public function testValidasiJadwalJudulKosongGagal(): void
    {
        $hasil = jadwal_validasi(['judul' => '', 'mulai' => '2026-10-17 09:00']);
        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('judul', $hasil['galat']);
    }

    public function testValidasiJadwalSelesaiSebelumMulaiGagal(): void
    {
        $hasil = jadwal_validasi([
            'judul' => 'Rapat',
            'mulai' => '2026-10-17 10:00',
            'selesai' => '2026-10-17 09:00',
        ]);
        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('selesai', $hasil['galat']);
    }

    public function testValidasiJadwalPengingatDiLuarBatasGagal(): void
    {
        $hasil = jadwal_validasi([
            'judul' => 'Rapat',
            'mulai' => '2026-10-17 10:00',
            'pengingat_menit' => 99999,
        ]);
        $this->assertFalse($hasil['ok']);
        $this->assertArrayHasKey('pengingat_menit', $hasil['galat']);
    }

    public function testValidasiJadwalStatusTakDikenalJadiRencana(): void
    {
        $hasil = jadwal_validasi([
            'judul' => 'Rapat',
            'mulai' => '2026-10-17 10:00',
            'status' => 'entah',
        ]);
        $this->assertSame('rencana', $hasil['data']['status']);
    }

    public function testNamaHariDanBulanIndonesia(): void
    {
        $this->assertSame('Senin', jadwal_hari_id(1));
        $this->assertSame('Minggu', jadwal_hari_id(0));
        $this->assertSame('Oktober', jadwal_bulan_id(10));
        $this->assertSame('', jadwal_bulan_id(0));
    }
}
