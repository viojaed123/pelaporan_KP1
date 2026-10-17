<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Uji fungsi murni modul Pelacakan Kasus (includes/kasus.php) dan
 * konfigurasi tabel geokode (includes/wilayah.php).
 *
 * Catatan: uji visibilitas bergantung pada keadaan sesi. Pada lingkungan
 * CLI/jalankan uji, sesi kosong -> peran '' dan user_id null, sehingga
 * pemakai tanpa identitas tidak melihat baris apa pun ('0 = 1').
 */
final class KasusTest extends TestCase
{
    protected function setUp(): void
    {
        // Pastikan keadaan sesi bersih untuk setiap uji.
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testDaftarPilihanBerisiNilaiWajib(): void
    {
        $this->assertSame(['Terbuka', 'Dalam Proses', 'Selesai'], kasus_status_daftar());
        $this->assertSame(['Rendah', 'Sedang', 'Tinggi', 'Kritis'], kasus_urgensi_daftar());
        $this->assertContains('Lingkungan', kasus_kategori_daftar());
        $this->assertNotEmpty(kasus_kategori_daftar());
    }

    public function testLabelSumber(): void
    {
        $this->assertSame('Manual', kasus_label_sumber('Manual'));
        $this->assertSame('SRM', kasus_label_sumber('srm'));
        $this->assertSame('CID', kasus_label_sumber('CID'));
        $this->assertSame('Manual', kasus_label_sumber(''));
    }

    public function testKelasStatusKasus(): void
    {
        $this->assertSame('kasus-terbuka', kelas_status_kasus('Terbuka'));
        $this->assertSame('kasus-dalam-proses', kelas_status_kasus('Dalam Proses'));
        $this->assertSame('kasus-selesai', kelas_status_kasus('Selesai'));
        $this->assertSame('badge-navy', kelas_status_kasus('Tidak Dikenal'));
    }

    public function testVisibilitasMentorMelihatSemua(): void
    {
        $_SESSION['role'] = 'mentor';
        $_SESSION['user_id'] = 2;
        $this->assertSame('', kasus_sql_visibilitas('k'));
        $this->assertTrue(kasus_peran_penuh('admin'));
        $this->assertTrue(kasus_peran_penuh('mentor'));
        $this->assertFalse(kasus_peran_penuh('karyawan'));
    }

    public function testVisibilitasKaryawanTerbatasPadaKepemilikan(): void
    {
        $_SESSION['role'] = 'karyawan';
        $_SESSION['user_id'] = 5;

        $sql = kasus_sql_visibilitas('k');
        $this->assertStringContainsString('k.dibuat_oleh = 5', $sql);
        $this->assertStringContainsString('k.penanggung_jawab_id = 5', $sql);
        $this->assertStringContainsString('kasus_penugasan', $sql);
        $this->assertStringContainsString('kx.user_id = 5', $sql);
    }

    public function testVisibilitasTanpaAlias(): void
    {
        $_SESSION['role'] = 'karyawan';
        $_SESSION['user_id'] = 9;
        $sql = kasus_sql_visibilitas('');
        $this->assertStringContainsString('dibuat_oleh = 9', $sql);
        $this->assertStringNotContainsString('.dibuat_oleh', $sql);
    }

    public function testVisibilitasTanpaIdentitasMenolakSemua(): void
    {
        $_SESSION = [];
        $this->assertSame('0 = 1', kasus_sql_visibilitas('k'));
    }

    public function testIzinLihatDanKelolaKonsisten(): void
    {
        $kasus = ['id' => 7, 'dibuat_oleh' => 5, 'penanggung_jawab_id' => 8];

        // Mentor/admin: selalu boleh.
        $this->assertTrue(kasus_izin_lihat_dasar($kasus, 1, 'mentor'));
        $this->assertTrue(kasus_izin_lihat_dasar($kasus, 1, 'admin'));
        // Pembuat dan PIC boleh.
        $this->assertTrue(kasus_izin_lihat_dasar($kasus, 5, 'karyawan'));
        $this->assertTrue(kasus_izin_lihat_dasar($kasus, 8, 'karyawan'));
        // Karyawan tak terkait tidak boleh (tanpa menyentuh DB).
        $this->assertFalse(kasus_izin_lihat_dasar($kasus, 99, 'karyawan'));

        // $conn tidak dipakai oleh kasus_izin_hapus(); cukup handle kosong.
        $conn = mysqli_init();
        $this->assertTrue(kasus_izin_hapus($conn, $kasus, 1, 'mentor'));
        $this->assertTrue(kasus_izin_hapus($conn, $kasus, 5, 'karyawan'));
        // PIC (bukan pembuat) tidak boleh menghapus bila bukan mentor/admin.
        $this->assertFalse(kasus_izin_hapus($conn, $kasus, 8, 'karyawan'));
        $this->assertFalse(kasus_izin_hapus($conn, $kasus, 99, 'karyawan'));
    }

    public function testTabelLokasiSrmCidMemakaiTeksGabungan(): void
    {
        $srm = wilayah_tabel_lokasi('srm_kegiatan');
        $this->assertNotNull($srm);
        $this->assertNotNull($srm['teks_sql']);
        $this->assertStringContainsString('kelurahan', $srm['teks_sql']);
        $this->assertStringContainsString('kecamatan', $srm['teks_sql']);

        $cid = wilayah_tabel_lokasi('cid_program');
        $this->assertNotNull($cid);
        $this->assertStringContainsString('desa', $cid['teks_sql']);

        // Ekspresi teks = teks_sql bila ada, kalau tidak kolom teks tunggal.
        $this->assertSame($srm['teks_sql'], _wilayah_teks_expr($srm));

        $kasus = wilayah_tabel_lokasi('kasus');
        $this->assertNotNull($kasus);
        $this->assertNull($kasus['teks_sql']);
        $this->assertSame('nama_lokasi', _wilayah_teks_expr($kasus));
        $this->assertNull($kasus['wilayah']);
    }

    public function testDaftarTabelGeokodeMencakupEnamTabel(): void
    {
        $tabel = wilayah_tabel_geokode();
        $this->assertCount(6, $tabel);
        foreach (['kasus', 'laporan_harian', 'absensi', 'jadwal', 'srm_kegiatan', 'cid_program'] as $t) {
            $this->assertContains($t, $tabel);
        }
    }

    public function testTabelTidakDikenalMengembalikanNull(): void
    {
        $this->assertNull(wilayah_tabel_lokasi('tabel_ngawur'));
    }
}
