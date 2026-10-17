<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Rules\KebijakanSandi;
use Tests\TestCase;

/**
 * Uji kebijakan kata sandi (porting includes/security.php §2).
 *
 * Murni logika -- tidak menyentuh basis data, sehingga cepat dan dapat
 * dijalankan kapan pun.
 */
class KebijakanSandiTest extends TestCase
{
    /*
    |----------------------------------------------------------------------
    | Panjang
    |----------------------------------------------------------------------
    */

    public function test_sandi_terlalu_pendek_ditolak(): void
    {
        $pesan = KebijakanSandi::periksa('Ab1!');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('minimal', $pesan);
    }

    public function test_sandi_pada_batas_minimum_diterima(): void
    {
        /* 8 karakter, tidak umum, tidak berulang, tidak memuat identitas. */
        $this->assertNull(KebijakanSandi::periksa('Kucing6721'));
    }

    public function test_sandi_terlalu_panjang_ditolak(): void
    {
        $pesan = KebijakanSandi::periksa(str_repeat('aB3', 70)); // 210 karakter

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('maksimal', $pesan);
    }

    /*
    |----------------------------------------------------------------------
    | Kata sandi umum
    |----------------------------------------------------------------------
    */

    public function test_kata_sandi_umum_ditolak(): void
    {
        /*
         * 'password' sudah cukup panjang (8), jadi penolakannya HARUS datang
         * dari daftar kata umum -- membuktikan urutan pemeriksaan benar.
         */
        $pesan = KebijakanSandi::periksa('password');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('umum', $pesan);
    }

    public function test_kata_sandi_umum_khas_aplikasi_ditolak(): void
    {
        /* Nama sistem/organisasi justru yang paling sering ditebak penyerang
           yang mengenal targetnya. */
        $this->assertStringContainsString('umum', (string) KebijakanSandi::periksa('pertamina123'));
        $this->assertStringContainsString('umum', (string) KebijakanSandi::periksa('pelaporan'));
    }

    public function test_kata_sandi_umum_tidak_peka_huruf_besar_kecil(): void
    {
        $this->assertStringContainsString('umum', (string) KebijakanSandi::periksa('PASSWORD'));
    }

    /*
    |----------------------------------------------------------------------
    | Pola
    |----------------------------------------------------------------------
    */

    public function test_satu_karakter_berulang_ditolak(): void
    {
        $this->assertStringContainsString('berulang', (string) KebijakanSandi::periksa('aaaaaaaa'));
        $this->assertStringContainsString('berulang', (string) KebijakanSandi::periksa('1111111111'));
    }

    /*
    |----------------------------------------------------------------------
    | Identitas pengguna
    |----------------------------------------------------------------------
    */

    public function test_sandi_tidak_boleh_memuat_bagian_lokal_surel(): void
    {
        $pesan = KebijakanSandi::periksa('Budi6721X', 'budi@perusahaan.com');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('surel', $pesan);
    }

    public function test_sandi_tidak_boleh_memuat_nama_lengkap(): void
    {
        /* Nama diperiksa APA ADANYA (huruf kecil, sudah di-trim), termasuk
           spasinya -- persis seperti sistem lama. Jadi sandi yang memuat
           "budi santoso" (dengan spasi) harus ditolak. */
        $pesan = KebijakanSandi::periksa('rahasia budi santoso 1', null, 'Budi Santoso');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('nama', $pesan);
    }

    public function test_pemeriksaan_nama_tidak_peka_huruf_besar_kecil(): void
    {
        $pesan = KebijakanSandi::periksa('xBUDI SANTOSOx', null, 'Budi Santoso');

        $this->assertNotNull($pesan);
        $this->assertStringContainsString('nama', $pesan);
    }

    public function test_nama_tanpa_spasi_belum_terdeteksi(): void
    {
        /* BATASAN YANG DIWARISI (sengaja dipertahankan agar setia):
           pemeriksaan nama memakai str_contains terhadap nama UTUH, sehingga
           "budisantoso" (tanpa spasi) belum tertangkap. Uji ini mengunci
           perilaku itu supaya perubahannya nanti (bila diinginkan) disadari,
           bukan terjadi diam-diam. */
        $this->assertNull(KebijakanSandi::periksa('xxbudisantosoxx', null, 'Budi Santoso'));
    }

    /*
    |----------------------------------------------------------------------
    | Integrasi rule (validate)
    |----------------------------------------------------------------------
    */

    public function test_dapat_dipakai_sebagai_rule_validasi(): void
    {
        $data = ['password' => 'password'];

        $validator = validator($data, [
            'password' => [new KebijakanSandi()],
        ]);

        $this->assertTrue($validator->fails());
        $this->assertNotNull($validator->errors()->first('password'));
    }

    public function test_rule_meloloskan_sandi_kuat(): void
    {
        $validator = validator(['password' => 'Kucing6721'], [
            'password' => [new KebijakanSandi('orang@contoh.com', 'Orang Lain')],
        ]);

        $this->assertFalse($validator->fails());
    }
}
