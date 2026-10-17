<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Uji konfigurasi inti & identitas visual korporat PHR.
 *
 * Warna-warna ini adalah identitas visual resmi Pertamina Hulu Rokan dan
 * harus sama di seluruh antarmuka. Uji ini menahannya agar tidak hilang
 * diam-diam -- khususnya karena pada berkas .env karakter '#' memulai
 * KOMENTAR, sehingga warna tanpa tanda kutip (WARNA_BIRU=#005EB8) akan
 * terbaca kosong dan identitas visual lenyap tanpa satu pun error.
 */
class KonfigurasiTest extends TestCase
{
    public function test_zona_waktu_adalah_wib(): void
    {
        /*
         * Seluruh data lama memakai WIB. Salah menyetel ke UTC akan
         * menggeser absensi/jadwal 7 jam tanpa terlihat di basis data.
         */
        $this->assertSame('Asia/Jakarta', config('app.timezone'));
    }

    public function test_bahasa_indonesia_aktif(): void
    {
        $this->assertSame('id', config('app.locale'));
    }

    /**
     * Uji tiap warna korporat.
     */
    #[DataProvider('warnaKorporat')]
    public function test_warna_korporat_benar(string $kunci, string $harap): void
    {
        $this->assertSame(
            $harap,
            config('keamanan.' . $kunci),
            "Warna korporat '$kunci' berubah. Periksa apakah nilainya di .env " .
            'tidak dikutip (karakter # akan dianggap komentar).'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function warnaKorporat(): array
    {
        return [
            'biru'     => ['warna_biru', '#005EB8'],
            'biru tua' => ['warna_biru_tua', '#003D7D'],
            'merah'    => ['warna_merah', '#EB1C24'],
            'hijau'    => ['warna_hijau', '#159447'],
            'latar'    => ['warna_bg', '#F5F5F5'],
        ];
    }

    public function test_batas_sesi_bernilai_masuk_akal(): void
    {
        $idle = (int) config('keamanan.sesi_idle_detik');
        $absolut = (int) config('keamanan.sesi_absolut_detik');

        $this->assertSame(1800, $idle, 'Batas diam harus 30 menit (1800 detik).');
        $this->assertSame(28800, $absolut, 'Batas usia mutlak harus 8 jam (28800 detik).');
        $this->assertLessThan(
            $absolut,
            $idle,
            'Batas diam harus lebih pendek dari usia mutlak, jika tidak aturan ' .
            'diam tidak akan pernah tercapai lebih dulu.'
        );
    }

    public function test_pembatas_laju_login_masuk_akal(): void
    {
        $this->assertSame(5, (int) config('keamanan.login_maks_gagal'));
        $this->assertGreaterThanOrEqual(
            3,
            (int) config('keamanan.login_maks_gagal'),
            'Ambang terlalu rendah akan mengunci pengguna sah yang salah mengetik.'
        );

        $backoff = config('keamanan.login_backoff_detik');
        $this->assertIsArray($backoff);
        $this->assertNotEmpty($backoff);

        /*
         * Penundaan harus MENAIK: tiap kegagalan beruntun menanggung waktu
         * tunggu lebih lama. Array yang menurun berarti penyerang semakin
         * diuntungkan -- kebalikan dari yang diinginkan.
         */
        $naik = true;
        for ($i = 1; $i < count($backoff); $i++) {
            if ($backoff[$i] <= $backoff[$i - 1]) {
                $naik = false;
                break;
            }
        }
        $this->assertTrue($naik, 'Penundaan penguncian harus menaik: ' . implode(',', $backoff));
    }

    public function test_kebijakan_sandi_sesuai_sistem_lama(): void
    {
        $this->assertSame(8, (int) config('keamanan.password_min'));
        $this->assertSame(3, (int) config('keamanan.password_riwayat'));
    }
}
