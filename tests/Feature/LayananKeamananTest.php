<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\KodeVerifikasi;
use App\Models\AuditLog;
use App\Models\PasswordHistory;
use App\Services\AuditLogger;
use App\Services\KeamananLogin;
use App\Services\KodeCadangan;
use App\Services\Otp;
use App\Services\RiwayatSandi;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji lapis keamanan yang diport dari includes/security.php:
 * penguncian login, OTP, kode cadangan, riwayat kata sandi, dan audit.
 *
 * Semuanya berjalan di basis data uji di memori (lihat SkemaAuth).
 */
class LayananKeamananTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanSkemaAuth();
    }

    /*
    |----------------------------------------------------------------------
    | OTP (security.php §3)
    |----------------------------------------------------------------------
    */

    public function test_otp_enam_angka_dibuat_dan_diverifikasi(): void
    {
        $otp  = app(Otp::class);
        $kode = $otp->buat(1, 'uji@contoh.com');

        $this->assertMatchesRegularExpression('/^\d{6}$/', $kode);

        $hasil = $otp->verifikasi(1, $kode);

        $this->assertTrue($hasil['ok']);
    }

    public function test_otp_hanya_bisa_dipakai_sekali(): void
    {
        $otp  = app(Otp::class);
        $kode = $otp->buat(1, 'uji@contoh.com');

        $this->assertTrue($otp->verifikasi(1, $kode)['ok']);

        /* Percobaan kedua dengan kode yang SAMA harus gagal: tidak ada kode
           aktif lagi setelah yang pertama dipakai. */
        $hasil = $otp->verifikasi(1, $kode);

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('Tidak ada kode aktif', $hasil['pesan']);
    }

    public function test_otp_salah_ditolak_dan_menghitung_sisa(): void
    {
        $otp = app(Otp::class);
        $otp->buat(1, 'uji@contoh.com');

        /* '000000' tidak mungkin cocok: kode selalu 6 digit dimulai dari 1. */
        $hasil = $otp->verifikasi(1, '000000');

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('tidak sesuai', $hasil['pesan']);
        $this->assertLessThan((int) config('keamanan.otp_maks_coba'), (int) $hasil['sisa']);
    }

    public function test_otp_bukan_angka_ditolak_tanpa_menghitung_percobaan(): void
    {
        $otp  = app(Otp::class);
        $kode = $otp->buat(1, 'uji@contoh.com');

        /* Salah panjang/format: ditolak lebih dulu, dan kode asli tetap sah. */
        $this->assertFalse($otp->verifikasi(1, 'abc')['ok']);
        $this->assertTrue($otp->verifikasi(1, $kode)['ok']);
    }

    public function test_otp_kedaluwarsa_setelah_batas_waktu(): void
    {
        config(['keamanan.otp_detik' => 300]);

        $otp  = app(Otp::class);
        $kode = $otp->buat(1, 'uji@contoh.com');

        $this->travel(6)->minutes();

        $hasil = $otp->verifikasi(1, $kode);

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('kedaluwarsa', $hasil['pesan']);
    }

    public function test_otp_terkunci_setelah_maksimal_percobaan(): void
    {
        config(['keamanan.otp_maks_coba' => 3]);

        $otp  = app(Otp::class);
        $kode = $otp->buat(1, 'uji@contoh.com');

        for ($i = 0; $i < 3; $i++) {
            $otp->verifikasi(1, '000000');
        }

        /* Kode yang BENAR pun ditolak: batas percobaan berlaku lebih dulu.
           Inilah yang mencegah penebakan tanpa batas. */
        $hasil = $otp->verifikasi(1, $kode);

        $this->assertFalse($hasil['ok']);
        $this->assertStringContainsString('Terlalu banyak percobaan', $hasil['pesan']);
    }

    public function test_otp_baru_menonaktifkan_kode_lama(): void
    {
        $otp   = app(Otp::class);
        $lama  = $otp->buat(1, 'uji@contoh.com');
        $baru  = $otp->buat(1, 'uji@contoh.com');

        /* Kode lama tidak boleh lagi berlaku setelah kode baru diminta. */
        $this->assertFalse($otp->verifikasi(1, $lama)['ok']);
        $this->assertTrue($otp->verifikasi(1, $baru)['ok']);
    }

    public function test_otp_kirim_mengantre_surel(): void
    {
        Mail::fake();

        $hasil = app(Otp::class)->kirim('uji@contoh.com', '123456', 'Pengguna Uji');

        $this->assertTrue($hasil['ok']);
        Mail::assertSent(KodeVerifikasi::class, function (KodeVerifikasi $mail): bool {
            return $mail->kode === '123456' && $mail->hasTo('uji@contoh.com');
        });
    }

    public function test_otp_kirim_menolak_surel_tidak_valid(): void
    {
        Mail::fake();

        $hasil = app(Otp::class)->kirim('bukan-surel', '123456', 'Uji');

        $this->assertFalse($hasil['ok']);
        Mail::assertNothingSent();
    }

    /*
    |----------------------------------------------------------------------
    | Kode cadangan (security.php §3)
    |----------------------------------------------------------------------
    */

    public function test_kode_cadangan_dibuat_sepuluh(): void
    {
        $svc  = app(KodeCadangan::class);
        $kode = $svc->buat(1);

        $this->assertCount(10, $kode);
        $this->assertSame(10, $svc->sisa(1));

        /* Format XXXXXX-XXXX (6 heksadesimal, tanda hubung, 4 heksadesimal). */
        $this->assertMatchesRegularExpression('/^[0-9A-F]{6}-[0-9A-F]{4}$/', $kode[0]);
    }

    public function test_kode_cadangan_sekali_pakai(): void
    {
        $svc  = app(KodeCadangan::class);
        $kode = $svc->buat(1);

        $this->assertTrue($svc->pakai(1, $kode[0]));
        $this->assertSame(9, $svc->sisa(1));

        /* Kode yang sudah dipakai tidak boleh berlaku lagi. */
        $this->assertFalse($svc->pakai(1, $kode[0]));
    }

    public function test_kode_cadangan_salah_ditolak(): void
    {
        $svc = app(KodeCadangan::class);
        $svc->buat(1);

        $this->assertFalse($svc->pakai(1, 'ABCDEF-0123'));
        $this->assertSame(10, $svc->sisa(1));
    }

    public function test_kode_cadangan_baru_menghapus_yang_lama(): void
    {
        $svc  = app(KodeCadangan::class);
        $lama = $svc->buat(1);
        $svc->buat(1);

        $this->assertSame(10, $svc->sisa(1), 'Set baru harus tetap 10 kode.');
        $this->assertFalse($svc->pakai(1, $lama[0]), 'Kode dari set lama harus tidak berlaku.');
    }

    /*
    |----------------------------------------------------------------------
    | Riwayat kata sandi (security.php §2)
    |----------------------------------------------------------------------
    */

    public function test_riwayat_menolak_sandi_yang_pernah_dipakai(): void
    {
        $svc = app(RiwayatSandi::class);
        $svc->simpan(1, Hash::make('SandiLama1'));

        $this->assertNotNull($svc->terpakaiSebelumnya(1, 'SandiLama1'));
        $this->assertNull($svc->terpakaiSebelumnya(1, 'SandiBaru2'));
    }

    public function test_riwayat_hanya_menyimpan_sejumlah_terakhir(): void
    {
        config(['keamanan.password_riwayat' => 3]);

        $svc = app(RiwayatSandi::class);

        foreach (['A1', 'A2', 'A3', 'A4', 'A5'] as $sandi) {
            $svc->simpan(1, Hash::make($sandi));
        }

        $this->assertSame(3, PasswordHistory::where('user_id', 1)->count());

        /* Yang paling lama sudah dibuang, tidak lagi dianggap "pernah dipakai". */
        $this->assertNull($svc->terpakaiSebelumnya(1, 'A1'));
        $this->assertNotNull($svc->terpakaiSebelumnya(1, 'A5'));
    }

    /*
    |----------------------------------------------------------------------
    | Penguncian login (security.php §1)
    |----------------------------------------------------------------------
    */

    public function test_penguncian_muncul_setelah_ambang_terlampaui(): void
    {
        config([
            'keamanan.login_maks_gagal'    => 3,
            'keamanan.login_backoff_detik' => [60],
        ]);

        $svc = app(KeamananLogin::class);

        $svc->catatGagal('10.0.0.1', 'a@contoh.com');
        $svc->catatGagal('10.0.0.1', 'a@contoh.com');

        $this->assertFalse($svc->cekTerkunci('10.0.0.1', 'a@contoh.com')['terkunci']);

        $svc->catatGagal('10.0.0.1', 'a@contoh.com');

        $cek = $svc->cekTerkunci('10.0.0.1', 'a@contoh.com');

        $this->assertTrue($cek['terkunci']);
        $this->assertGreaterThan(0, $cek['sisa']);
        $this->assertStringContainsString('Coba lagi dalam', $cek['pesan']);
    }

    public function test_penguncian_berlaku_untuk_ip_maupun_surel_terpisah(): void
    {
        config([
            'keamanan.login_maks_gagal'    => 2,
            'keamanan.login_backoff_detik' => [60],
        ]);

        $svc = app(KeamananLogin::class);

        /* Dua kegagalan dari IP yang SAMA tetapi surel BERBEDA tetap mengunci
         * IP tersebut -- mencegah satu IP menebak banyak akun. */
        $svc->catatGagal('10.0.0.9', 'a@contoh.com');
        $svc->catatGagal('10.0.0.9', 'b@contoh.com');

        $this->assertTrue($svc->cekTerkunci('10.0.0.9', 'c@contoh.com')['terkunci']);
    }

    public function test_reset_kunci_membuka_penguncian(): void
    {
        config([
            'keamanan.login_maks_gagal'    => 2,
            'keamanan.login_backoff_detik' => [60],
        ]);

        $svc = app(KeamananLogin::class);

        $svc->catatGagal('10.0.0.5', 'a@contoh.com');
        $svc->catatGagal('10.0.0.5', 'a@contoh.com');

        $this->assertTrue($svc->cekTerkunci('10.0.0.5', 'a@contoh.com')['terkunci']);

        $svc->resetKunci('10.0.0.5', 'a@contoh.com');

        $this->assertFalse($svc->cekTerkunci('10.0.0.5', 'a@contoh.com')['terkunci']);
    }

    public function test_hitung_gagal_membaca_riwayat(): void
    {
        $svc = app(KeamananLogin::class);

        $svc->catatGagal('10.0.0.2', 'b@contoh.com');
        $svc->catatGagal('10.0.0.2', 'b@contoh.com');

        $this->assertSame(2, $svc->hitungGagal('10.0.0.2', 'b@contoh.com'));
    }

    public function test_catat_sukses_menghapus_penguncian(): void
    {
        config([
            'keamanan.login_maks_gagal'    => 2,
            'keamanan.login_backoff_detik' => [60],
        ]);

        $svc = app(KeamananLogin::class);

        $svc->catatGagal('10.0.0.7', 'a@contoh.com');
        $svc->catatGagal('10.0.0.7', 'a@contoh.com');

        $svc->catatSukses('10.0.0.7', 'a@contoh.com', 1);

        $this->assertFalse($svc->cekTerkunci('10.0.0.7', 'a@contoh.com')['terkunci']);
    }

    /*
    |----------------------------------------------------------------------
    | Audit (audit.php)
    |----------------------------------------------------------------------
    */

    public function test_audit_menyaring_kolom_rahasia(): void
    {
        app(AuditLogger::class)->catat(
            'ubah_pengguna',
            'users',
            1,
            'Mengubah data pengguna',
            ['password' => 'SANGAT-RAHASIA', 'nama' => 'Budi'],
            ['kode_hash' => 'HASH-RAHASIA', 'role' => 'admin'],
        );

        $baris = AuditLog::first();

        $this->assertNotNull($baris);
        $this->assertStringNotContainsString('SANGAT-RAHASIA', (string) $baris->data_lama);
        $this->assertStringNotContainsString('HASH-RAHASIA', (string) $baris->data_baru);

        /* Data yang tidak rahasia tetap tercatat -- audit tidak boleh
           kehilangan konteks hanya karena ada satu kolom rahasia. */
        $this->assertStringContainsString('Budi', (string) $baris->data_lama);
        $this->assertStringContainsString('admin', (string) $baris->data_baru);
    }

    public function test_audit_menyimpan_json_yang_sah(): void
    {
        app(AuditLogger::class)->catat('uji', 'users', 1, 'Ringkasan', null, ['a' => 1]);

        $baris = AuditLog::first();

        $this->assertIsArray(json_decode((string) $baris->data_baru, true));
        $this->assertSame(1, json_decode((string) $baris->data_baru, true)['a']);
    }
}
