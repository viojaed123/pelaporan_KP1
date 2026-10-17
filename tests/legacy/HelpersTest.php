<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    /* ---------- Escaping ---------- */

    public function testEscapingHtml(): void
    {
        $this->assertSame('&amp;', e('&'));
        $this->assertSame('&lt;div&gt;', e('<div>'));
        $this->assertSame('&quot;', e('"'));
        $this->assertSame('&#039;', e("'"));
        $this->assertSame('Aman', e('Aman'));
    }

    public function testAliasH(): void
    {
        $this->assertSame(e('<b>'), h('<b>'));
    }

    /* ---------- Label & kelas status laporan ---------- */

    public function testLabelStatus(): void
    {
        $this->assertSame('Draft', label_status('draft'));
        $this->assertSame('Menunggu Pemeriksaan', label_status('dikirim'));
        $this->assertSame('Diperiksa', label_status('diperiksa'));
        $this->assertSame('Perlu Revisi', label_status('perlu_revisi'));
        $this->assertSame('Foobar', label_status('foobar'));
        $this->assertSame('Belum ada status', label_status(''));
    }

    public function testKelasStatus(): void
    {
        $this->assertSame('status-draft', kelas_status('draft'));
        $this->assertSame('status-dikirim', kelas_status('dikirim'));
        $this->assertSame('status-diperiksa', kelas_status('diperiksa'));
        $this->assertSame('status-revisi', kelas_status('perlu_revisi'));
        $this->assertSame('status-lain', kelas_status('xxx'));
    }

    public function testLabelStatusKirim(): void
    {
        $this->assertSame('Menunggu persetujuan', label_status_kirim('menunggu'));
        $this->assertSame('Disetujui', label_status_kirim('disetujui'));
        $this->assertSame('Terkirim', label_status_kirim('terkirim'));
        $this->assertSame('Gagal', label_status_kirim('gagal'));
        $this->assertSame('Dibatalkan', label_status_kirim('dibatalkan'));
    }

    public function testKelasStatusKirim(): void
    {
        $this->assertSame('badge-kuning', kelas_status_kirim('menunggu'));
        $this->assertSame('badge-biru', kelas_status_kirim('disetujui'));
        $this->assertSame('badge-hijau', kelas_status_kirim('terkirim'));
        $this->assertSame('badge-merah', kelas_status_kirim('gagal'));
        $this->assertSame('badge-navy', kelas_status_kirim('dibatalkan'));
    }

    public function testLabelAbsensi(): void
    {
        $this->assertSame('Hadir', label_status_absensi('hadir'));
        $this->assertSame('Izin', label_status_absensi('izin'));
        $this->assertSame('Sakit', label_status_absensi('sakit'));
        $this->assertSame('Alpa', label_status_absensi('alpa'));
    }

    public function testKelasUrgensi(): void
    {
        $this->assertSame('urgensi-tinggi', kelas_urgensi('TINGGI'));
        $this->assertSame('urgensi-rendah', kelas_urgensi(' rendah '));
    }

    public function testKondisiKesehatan(): void
    {
        $this->assertSame('Sehat', label_kondisi_kesehatan('sehat'));
        $this->assertSame('Kurang Sehat', label_kondisi_kesehatan('kurang_sehat'));
        $this->assertSame('Sedang Sakit', label_kondisi_kesehatan('sakit'));
        $this->assertSame('Dalam Pemulihan', label_kondisi_kesehatan(' PEMULIHAN '));
        $this->assertSame('Belum dilaporkan', label_kondisi_kesehatan(''));
    }

    public function testBadgeKondisiKesehatan(): void
    {
        $this->assertSame('badge-hijau', badge_kondisi_kesehatan('sehat'));
        $this->assertSame('badge-kuning', badge_kondisi_kesehatan('kurang_sehat'));
        $this->assertSame('badge-merah', badge_kondisi_kesehatan('sakit'));
        $this->assertSame('badge-biru', badge_kondisi_kesehatan('pemulihan'));
        $this->assertSame('badge-navy', badge_kondisi_kesehatan('entah'));
    }

    public function testDaftarDanValidasiKondisiKesehatan(): void
    {
        $daftar = daftar_kondisi_kesehatan();
        $this->assertArrayHasKey('sehat', $daftar);
        $this->assertArrayHasKey('sakit', $daftar);

        $this->assertSame('sakit', kondisi_kesehatan_valid('sakit'));
        $this->assertSame('pemulihan', kondisi_kesehatan_valid(' PEMULIHAN '));
        // Nilai tak dikenal / non-string -> default
        $this->assertSame('sehat', kondisi_kesehatan_valid('terbang'));
        $this->assertSame('sehat', kondisi_kesehatan_valid(null));
        $this->assertSame('sehat', kondisi_kesehatan_valid(['x']));
        $this->assertSame('sehat', kondisi_kesehatan_valid(''));
    }

    public function testPesanGalatUnggah(): void
    {
        $this->assertStringContainsString('melebihi', pesan_galat_unggah(UPLOAD_ERR_INI_SIZE));
        $this->assertSame('Tidak ada berkas yang diunggah.', pesan_galat_unggah(UPLOAD_ERR_NO_FILE));
        $this->assertStringContainsString('kesalahan', pesan_galat_unggah(999));
    }

    public function testFotoProfilUrlMenolakNamaTidakAman(): void
    {
        // Kosong dan upaya path traversal selalu ditolak.
        $this->assertNull(foto_profil_url(''));
        $this->assertNull(foto_profil_url('../config.php'));
        $this->assertNull(foto_profil_url('sub/foto.jpg'));
        $this->assertNull(foto_profil_url('..'));
        // Nama berkas yang tidak ada -> null (tidak melempar galat).
        $this->assertNull(foto_profil_url('tidak-ada_' . bin2hex(random_bytes(4)) . '.jpg'));
    }

    public function testAvatarTampilFallbackInisial(): void
    {
        // Tanpa foto -> memakai avatar inisial berwarna sesuai peran.
        $html = avatar_tampil('Budi Santoso', 'admin', '', 'avatar avatar-kecil');
        $this->assertStringContainsString('<span', $html);
        $this->assertStringContainsString('avatar-admin', $html);
        $this->assertStringContainsString('>B<', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testLabelStatusKasus(): void
    {
        $this->assertSame('Terbuka', label_status_kasus('terbuka'));
        $this->assertSame('Dalam Proses', label_status_kasus('dalam proses'));
        $this->assertSame('Selesai', label_status_kasus('selesai'));
        $this->assertSame('aneh', label_status_kasus('aneh'));
    }

    /* ---------- Format ---------- */

    public function testTanggalId(): void
    {
        $this->assertSame('-', tanggal_id(null));
        $this->assertSame('-', tanggal_id(''));
        $this->assertSame('1 Januari 2024', tanggal_id('2024-01-01'));
        $this->assertSame('15 Oktober 2026', tanggal_id('2026-10-15'));
        $this->assertSame('2024-01-01', tanggal_id('2024-01-01', 'Y-m-d'));
    }

    public function testInisial(): void
    {
        $this->assertSame('A', inisial('  andi budiman  '));
        $this->assertSame('Z', inisial('zaki'));
        $this->assertSame('?', inisial(''));
        $this->assertSame('?', inisial('   '));
    }

    public function testPotong(): void
    {
        $this->assertSame('halo', potong('halo', 10));
        $this->assertSame('ini…', potong('ini adalah teks panjang', 3));
        $this->assertSame('', potong(null, 5));
        $this->assertSame('', potong('   ', 5));
    }

    /* ---------- Warna korporat ---------- */

    public function testWarnaKonstan(): void
    {
        $this->assertSame('#005EB8', WARNA_BIRU);
        $this->assertSame('#003D7D', WARNA_BIRU_TUA);
        $this->assertSame('#EB1C24', WARNA_MERAH);
        $this->assertSame('#159447', WARNA_HIJAU);
        $this->assertSame('#F5F5F5', WARNA_BG);
    }
}
