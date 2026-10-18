<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\LaporanHarian;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji API analitik (Fase C): rekap laporan, rekap absensi, ringkasan
 * kegiatan, dan kalender aktivitas.
 *
 * Menyusul aturan peran aplikasi lama:
 *   - rekap laporan/absensi  → khusus mentor & admin (karyawan 403);
 *   - ringkasan & kalender   → semua pengguna, karyawan otomatis
 *                              dipersempit ke datanya sendiri.
 */
class ApiAnalitikTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

        Schema::create('laporan_harian', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('jam_mulai')->nullable();
            $t->string('jam_selesai')->nullable();
            $t->string('kegiatan');
            $t->text('kendala')->nullable();
            $t->string('status')->nullable();
            $t->text('catatan_mentor')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('absensi', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('jam_masuk')->nullable();
            $t->string('jam_pulang')->nullable();
            $t->string('status')->nullable();
            $t->string('keterangan')->nullable();
            $t->string('kegiatan_hari_ini')->nullable();
            $t->string('kondisi_kesehatan')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $t): void {
            $t->id();
            $t->morphs('tokenable');
            $t->string('name');
            $t->string('token', 64)->unique();
            $t->text('abilities')->nullable();
            $t->timestamp('last_used_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        cache()->flush();
    }

    private function buatPengguna(string $peran = 'karyawan', string $nama = ''): User
    {
        $u = new User();
        $u->nama = $nama !== '' ? $nama : 'Pengguna ' . ucfirst($peran);
        $u->email = $peran . '_' . uniqid() . '@contoh.com';
        $u->password = 'SandiRahasia123!';
        $u->role = $peran;
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    private function masuk(User $user): string
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123!',
        ])->assertOk()->assertJsonPath('sukses', true);

        return (string) $res->json('token');
    }

    private function laporan(User $u, string $status, string $tanggal = ''): LaporanHarian
    {
        return LaporanHarian::create([
            'user_id'  => $u->id,
            'tanggal'  => $tanggal !== '' ? $tanggal : now()->toDateString(),
            'kegiatan' => 'Kegiatan ' . $status,
            'status'   => $status,
        ]);
    }

    /* =================================================================
     * REKAP LAPORAN
     * =============================================================== */

    public function test_rekap_laporan_menghitung_status_dan_memfilter(): void
    {
        $a = $this->buatPengguna('karyawan', 'Andi');
        $b = $this->buatPengguna('karyawan', 'Budi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        $this->laporan($a, 'dikirim');
        $this->laporan($b, 'diperiksa');

        $res = $this->withToken($token)
            ->getJson('/api/v1/rekap/laporan')
            ->assertOk()
            ->assertJsonPath('sukses', true)
            ->assertJsonPath('ringkasan.total', 2)
            ->assertJsonPath('ringkasan.dikirim', 1)
            ->assertJsonPath('ringkasan.diperiksa', 1);

        /* Pemilih karyawan disertakan untuk filter (hanya karyawan). */
        $this->assertCount(2, $res->json('daftar_karyawan'));

        /* Filter status mempersempit hasil. */
        $this->withToken($token)
            ->getJson('/api/v1/rekap/laporan?status=diperiksa')
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 1)
            ->assertJsonPath('data.total', 1);
    }

    public function test_rekap_laporan_ditolak_untuk_karyawan(): void
    {
        $saya = $this->buatPengguna('karyawan');
        $token = $this->masuk($saya);

        $this->withToken($token)
            ->getJson('/api/v1/rekap/laporan')
            ->assertStatus(403);
    }

    /* =================================================================
     * REKAP ABSENSI
     * =============================================================== */

    public function test_rekap_absensi_ringkasan_dan_per_peserta(): void
    {
        $a = $this->buatPengguna('karyawan', 'Andi');
        $b = $this->buatPengguna('karyawan', 'Budi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        Absensi::create(['user_id' => $a->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        Absensi::create(['user_id' => $a->id, 'tanggal' => now()->subDay()->toDateString(), 'status' => 'izin']);
        Absensi::create(['user_id' => $b->id, 'tanggal' => now()->toDateString(), 'status' => 'sakit']);

        $res = $this->withToken($token)
            ->getJson('/api/v1/rekap/absensi?awal=' . now()->subDays(3)->toDateString())
            ->assertOk()
            ->assertJsonPath('sukses', true)
            ->assertJsonPath('ringkasan.total', 3)
            ->assertJsonPath('ringkasan.hadir', 1)
            ->assertJsonPath('ringkasan.izin', 1)
            ->assertJsonPath('ringkasan.sakit', 1);

        /* Rekap per peserta memuat Andi dengan 2 catatan. */
        $perPeserta = collect($res->json('per_peserta'))->firstWhere('nama', 'Andi');
        $this->assertNotNull($perPeserta);
        $this->assertSame(2, $perPeserta['total_catatan']);
        $this->assertSame(1, $perPeserta['hadir']);
        $this->assertSame(1, $perPeserta['izin']);
    }

    public function test_rekap_absensi_ditolak_untuk_karyawan(): void
    {
        $saya = $this->buatPengguna('karyawan');
        $token = $this->masuk($saya);

        $this->withToken($token)
            ->getJson('/api/v1/rekap/absensi')
            ->assertStatus(403);
    }

    /* =================================================================
     * RINGKASAN KEGIATAN
     * =============================================================== */

    public function test_ringkasan_kegiatan_karyawan_dibatasi_ke_dirinya(): void
    {
        $saya = $this->buatPengguna('karyawan', 'Andi');
        $lain = $this->buatPengguna('karyawan', 'Budi');
        $token = $this->masuk($saya);

        $this->laporan($saya, 'dikirim');
        $this->laporan($lain, 'dikirim');

        $this->withToken($token)
            ->getJson('/api/v1/ringkasan-kegiatan?periode=mingguan')
            ->assertOk()
            ->assertJsonPath('stat.jml', 1)
            ->assertJsonPath('sasaran_id', $saya->id)
            ->assertJsonPath('daftar_karyawan', []);
    }

    public function test_ringkasan_kegiatan_mentor_melihat_seluruh_karyawan(): void
    {
        $a = $this->buatPengguna('karyawan', 'Andi');
        $b = $this->buatPengguna('karyawan', 'Budi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        $this->laporan($a, 'dikirim');
        $this->laporan($b, 'perlu_revisi');

        $res = $this->withToken($token)
            ->getJson('/api/v1/ringkasan-kegiatan?periode=mingguan')
            ->assertOk()
            ->assertJsonPath('stat.jml', 2)
            ->assertJsonPath('sasaran', 'Seluruh Karyawan')
            ->assertJsonPath('stat.revisi', 1);

        $this->assertNull($res->json('sasaran_id'));
        $this->assertCount(2, $res->json('daftar_karyawan'));
    }

    /* =================================================================
     * KALENDER
     * =============================================================== */

    public function test_kalender_memuat_aktivitas_dan_absensi_harian(): void
    {
        $saya = $this->buatPengguna('karyawan', 'Andi');
        $token = $this->masuk($saya);

        $hariIni = now()->toDateString();
        $this->laporan($saya, 'dikirim');
        Absensi::create([
            'user_id' => $saya->id, 'tanggal' => $hariIni,
            'status' => 'hadir', 'jam_masuk' => '08:00:00',
        ]);

        $res = $this->withToken($token)
            ->getJson('/api/v1/kalender')
            ->assertOk()
            ->assertJsonPath('bulan', now()->format('Y-m'))
            ->assertJsonPath('sasaran_id', $saya->id);

        $hari = collect($res->json('hari'))->firstWhere('tanggal', $hariIni);
        $this->assertNotNull($hari);
        $this->assertSame(1, $hari['jml']);

        $absensi = $res->json('absensi');
        $this->assertArrayHasKey($hariIni, $absensi);
        $this->assertSame('hadir', $absensi[$hariIni]['status']);
    }

    public function test_kalender_mentor_agregat_menghitung_karyawan_aktif(): void
    {
        $a = $this->buatPengguna('karyawan', 'Andi');
        $b = $this->buatPengguna('karyawan', 'Budi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        $this->laporan($a, 'dikirim');
        $this->laporan($b, 'diperiksa');

        $res = $this->withToken($token)
            ->getJson('/api/v1/kalender')
            ->assertOk()
            ->assertJsonPath('sasaran', 'Seluruh Karyawan');

        $this->assertNull($res->json('sasaran_id'));

        $hari = collect($res->json('hari'))->firstWhere('tanggal', now()->toDateString());
        $this->assertNotNull($hari);
        $this->assertSame(2, $hari['jml']);
        $this->assertSame(2, $hari['org']);
    }

    /* =================================================================
     * AUTENTIKASI
     * =============================================================== */

    public function test_endpoint_analitik_menolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/rekap/laporan')->assertStatus(401);
        $this->getJson('/api/v1/rekap/absensi')->assertStatus(401);
        $this->getJson('/api/v1/ringkasan-kegiatan')->assertStatus(401);
        $this->getJson('/api/v1/kalender')->assertStatus(401);
    }
}
