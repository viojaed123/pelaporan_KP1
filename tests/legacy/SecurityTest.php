<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    /* ---------- Kebijakan kata sandi ---------- */

    public function testSandiTerlaluPendek(): void
    {
        $this->assertSame(
            'Kata sandi minimal ' . PASSWORD_MIN . ' karakter.',
            password_validasi('Abc1!')
        );
    }

    public function testSandiTerlaluPanjang(): void
    {
        $panjang = str_repeat('Aa1!', 60); // 240 karakter
        $this->assertSame('Kata sandi maksimal 200 karakter.', password_validasi($panjang));
    }

    public function testSandiTanpaKeragamanDiterima(): void
    {
        // Kebijakan kini: panjang minimal saja (tanpa aturan 3-dari-4 jenis karakter).
        $this->assertNull(password_validasi('abcdefghijkl'));   // hanya huruf kecil
        $this->assertNull(password_validasi('rahasia1'));       // 8 karakter sederhana
    }

    public function testSandiUmumDitolak(): void
    {
        // 'password123' ada dalam daftar umum
        $this->assertSame(
            'Kata sandi terlalu umum dan mudah ditebak.',
            password_validasi('password123')
        );
    }

    public function testSandiSatuKarakterBerulangDitolak(): void
    {
        $this->assertSame(
            'Kata sandi tidak boleh terdiri dari satu karakter berulang.',
            password_validasi('aaaaaaaaaaaa')
        );
        $this->assertSame(
            'Kata sandi tidak boleh terdiri dari satu karakter berulang.',
            password_validasi('!!!!!!!!!!!!')
        );
    }

    public function testSandiTidakBolehMemuatEmail(): void
    {
        $galat = password_validasi('viojaed123A!x', ['email' => 'viojaed123@gmail.com']);
        $this->assertSame('Kata sandi tidak boleh memuat alamat surel Anda.', $galat);
    }

    public function testSandiTidakBolehMemuatNama(): void
    {
        $galat = password_validasi('xZakiLubis1!', ['nama' => 'zakilubis']);
        $this->assertSame('Kata sandi tidak boleh memuat nama Anda.', $galat);
    }

    public function testSandiKuatDiterima(): void
    {
        $this->assertNull(password_validasi('KucingOren2024!'));
        $this->assertNull(password_validasi('Hujan#Bulan9'));
    }

    /* ---------- Normalisasi nomor WhatsApp ---------- */

    public function testWaNormalkanNomor(): void
    {
        $this->assertSame('6281234567890', wa_normalkan_nomor('0812-3456-7890'));
        $this->assertSame('6281234567890', wa_normalkan_nomor('81234567890'));
        $this->assertSame('6281234567890', wa_normalkan_nomor('+62 812 3456 7890'));
        $this->assertSame('6281234567890', wa_normalkan_nomor('62812 3456 7890'));
        $this->assertSame('', wa_normalkan_nomor(''));
        $this->assertSame('', wa_normalkan_nomor('abc'));
    }

    public function testWaAktifDefaultNonaktif(): void
    {
        // Di config bawaan WA_GATEWAY kosong -> nonaktif
        $this->assertIsBool(wa_aktif());
    }
}
