<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\KodeVerifikasi;
use App\Models\User;
use App\Models\UserSecurity;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji API mobile ( Sanctum token per pengguna ).
 *
 * Mencakup alur lengkap klien APK: masuk → token → data → keluar,
 * termasuk lapisan keamanan yang sama dengan web (penguncian, 2FA).
 */
class ApiMobileTest extends TestCase
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
            $t->string('lokasi_teks')->nullable();
            $t->decimal('garis_lintang', 10, 7)->nullable();
            $t->decimal('garis_bujur', 10, 7)->nullable();
            $t->unsignedInteger('wilayah_id')->nullable();
            $t->text('deskripsi')->nullable();
            $t->text('hasil')->nullable();
            $t->text('kendala')->nullable();
            $t->text('rencana_berikutnya')->nullable();
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
            $t->string('lokasi_teks')->nullable();
            $t->decimal('garis_lintang', 10, 7)->nullable();
            $t->decimal('garis_bujur', 10, 7)->nullable();
            $t->unsignedInteger('wilayah_id')->nullable();
            $t->string('kondisi_kesehatan')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('tugas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('ditugaskan_oleh')->nullable();
            $t->string('judul');
            $t->text('deskripsi')->nullable();
            $t->date('tenggat')->nullable();
            $t->string('status')->default('baru');
            $t->timestamp('diselesaikan_pada')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('kasus', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode_kasus')->nullable();
            $t->string('judul');
            $t->text('deskripsi')->nullable();
            $t->string('nama_lokasi')->nullable();
            $t->decimal('garis_lintang', 10, 7)->nullable();
            $t->decimal('garis_bujur', 10, 7)->nullable();
            $t->string('tingkat_urgensi')->nullable();
            $t->string('status_kasus')->nullable();
            $t->integer('progres')->default(0);
            $t->unsignedInteger('dibuat_oleh')->nullable();
            $t->unsignedInteger('penanggung_jawab_id')->nullable();
            $t->string('kategori')->nullable();
            $t->string('sumber')->nullable();
            $t->date('target_selesai')->nullable();
            $t->string('lampiran')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        Schema::create('kasus_penugasan', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('kasus_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('peran')->nullable();
            $t->unsignedInteger('ditugaskan_oleh')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('kasus_status_log', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('kasus_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('status_lama')->nullable();
            $t->string('status_baru')->nullable();
            $t->integer('progres')->nullable();
            $t->text('catatan')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('notifikasi', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('laporan_id')->nullable();
            $t->text('pesan');
            $t->string('link')->nullable();
            $t->boolean('sudah_dibaca')->default(false);
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

    private function buatPengguna(string $peran = 'karyawan', string $sandi = 'SandiRahasia123!'): User
    {
        $u = new User();
        $u->nama = 'Pengguna ' . ucfirst($peran);
        $u->email = $peran . '@contoh.com';
        $u->password = $sandi; // cast 'hashed' meng-hash otomatis
        $u->role = $peran;
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    /** Masuk lewat API dan kembalikan token Bearer. */
    private function masuk(User $user, string $sandi = 'SandiRahasia123!'): string
    {
        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => $sandi,
        ])->assertOk()->assertJsonPath('sukses', true);

        return (string) $res->json('token');
    }

    /* =================================================================
     * AUTH
     * =============================================================== */

    public function test_login_sukses_mengeluarkan_token_dan_profil(): void
    {
        $user = $this->buatPengguna();
        $token = $this->masuk($user);

        $this->assertNotSame('', $token);

        $this->withToken($token)
            ->getJson('/api/v1/auth/profil')
            ->assertOk()
            ->assertJsonPath('pengguna.email', $user->email);
    }

    public function test_login_sandi_salah_ditolak_401(): void
    {
        $user = $this->buatPengguna();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'SalahTotal999!',
        ])->assertStatus(401);
    }

    public function test_login_akun_menunggu_ditolak_403(): void
    {
        $user = $this->buatPengguna();
        $user->status = 'menunggu';
        $user->save();

        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123!',
        ])->assertStatus(403);
    }

    public function test_login_dikunci_setelah_percobaan_gagal_berturut(): void
    {
        $user = $this->buatPengguna();
        $maks = (int) config('keamanan.login_maks_gagal');

        for ($i = 0; $i < $maks; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email'    => $user->email,
                'password' => 'Salah' . $i . '!',
            ])->assertStatus(401);
        }

        /* Percobaan berikutnya -- walau sandi benar -- ditolak 429. */
        $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123!',
        ])->assertStatus(429);
    }

    public function test_alur_dua_faktor_membutuhkan_otp(): void
    {
        Mail::fake();

        $user = $this->buatPengguna();
        UserSecurity::create([
            'user_id'         => $user->id,
            'dua_faktor_aktif' => true,
        ]);

        $res = $this->postJson('/api/v1/auth/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123!',
        ])->assertOk()
            ->assertJsonPath('dua_faktor', true);

        $ticket = (string) $res->json('ticket');
        $this->assertNotSame('', $ticket);

        /* Token BELUM diterbitkan pada tahap ini. */
        $this->assertSame(0, PersonalAccessToken::count());

        /* Kode salah → ditolak, tiket masih berlaku. */
        $this->postJson('/api/v1/auth/verifikasi', [
            'ticket' => $ticket,
            'kode'   => '000000',
        ])->assertStatus(422);

        /* Kode benar dari surel palsu → token terbit. */
        $kode = null;
        Mail::assertSent(KodeVerifikasi::class, function (KodeVerifikasi $m) use (&$kode): bool {
            $kode = $m->kode;

            return true;
        });

        $this->postJson('/api/v1/auth/verifikasi', [
            'ticket' => $ticket,
            'kode'   => $kode,
        ])->assertOk()->assertJsonPath('sukses', true);

        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_keluar_mencabut_token(): void
    {
        $user = $this->buatPengguna();
        $token = $this->masuk($user);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('sukses', true);

        /* Token fisik sudah terhapus dari basis data. */
        $this->assertSame(0, PersonalAccessToken::count());

        /*
         * Guard auth menyimpan hasil autentikasi dalam satu aplikasi uji;
         * lupakan guard agar permintaan berikutnya benar-benar meminta
         * token ulang (seperti perilaku HTTP nyata yang baru-boot).
         */
        $this->app->make('auth')->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/profil')->assertStatus(401);
    }

    public function test_endpoint_terproteksi_menolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/ringkasan')->assertStatus(401);
        $this->getJson('/api/v1/laporan/riwayat')->assertStatus(401);
        $this->postJson('/api/v1/absensi/check-in')->assertStatus(401);
    }

    /* =================================================================
     * MODUL
     * =============================================================== */

    public function test_laporan_riwayat_hanya_milik_sendiri_dan_simpan(): void
    {
        $saya = $this->buatPengguna('karyawan');
        $lain = $this->buatPengguna('mentor');
        $token = $this->masuk($saya);

        \App\Models\LaporanHarian::create([
            'user_id' => $lain->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'Milik mentor',
        ]);

        $this->withToken($token)->postJson('/api/v1/laporan', [
            'tanggal'  => now()->toDateString(),
            'kegiatan' => 'Instalasi jaringan',
            'lokasi_teks' => 'Kantor Pusat',
        ])->assertStatus(201)->assertJsonPath('sukses', true);

        $res = $this->withToken($token)->getJson('/api/v1/laporan/riwayat')
            ->assertOk();

        $this->assertSame(1, $res->json('data.total'));
        $this->assertSame('Instalasi jaringan', $res->json('data.data.0.kegiatan'));
    }

    public function test_laporan_wajib_kegiatan(): void
    {
        $saya = $this->buatPengguna();
        $token = $this->masuk($saya);

        $this->withToken($token)->postJson('/api/v1/laporan', [
            'tanggal' => now()->toDateString(),
        ])->assertStatus(422);
    }

    public function test_alur_absensi_lengkap(): void
    {
        $saya = $this->buatPengguna();
        $token = $this->masuk($saya);

        /* Belum check-in → check-out ditolak. */
        $this->withToken($token)->postJson('/api/v1/absensi/check-out')
            ->assertStatus(422);

        /* Check-in sukses. */
        $this->withToken($token)->postJson('/api/v1/absensi/check-in', [
            'lokasi_teks' => 'Lapangan',
        ])->assertStatus(201)->assertJsonPath('absensi.status', 'hadir');

        /* Check-in ganda ditolak. */
        $this->withToken($token)->postJson('/api/v1/absensi/check-in')
            ->assertStatus(422);

        /* Check-out sukses. */
        $this->withToken($token)->postJson('/api/v1/absensi/check-out')
            ->assertOk();

        /* Check-out kedua ditolak. */
        $this->withToken($token)->postJson('/api/v1/absensi/check-out')
            ->assertStatus(422);
    }

    public function test_tugas_hanya_penerima_yang_boleh_mengubah_status(): void
    {
        $saya = $this->buatPengguna('karyawan');
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($saya);

        $tugasLain = \App\Models\Tugas::create([
            'user_id' => $mentor->id, 'judul' => 'Tugas mentor', 'status' => 'baru',
        ]);
        $tugasSaya = \App\Models\Tugas::create([
            'user_id' => $saya->id, 'judul' => 'Tugas saya', 'status' => 'baru',
        ]);

        /* Orang lain: 403. */
        $this->withToken($token)
            ->patchJson("/api/v1/tugas/{$tugasLain->id}/status", ['status' => 'selesai'])
            ->assertStatus(403);

        /* Penerima sendiri: boleh. */
        $this->withToken($token)
            ->patchJson("/api/v1/tugas/{$tugasSaya->id}/status", ['status' => 'dikerjakan'])
            ->assertOk()
            ->assertJsonPath('tugas.status', 'dikerjakan');

        /* Nilai status di luar daftar ditolak. */
        $this->withToken($token)
            ->patchJson("/api/v1/tugas/{$tugasSaya->id}/status", ['status' => 'acak'])
            ->assertStatus(422);
    }

    public function test_kasus_visibilitas_karyawan_terbatas(): void
    {
        $saya = $this->buatPengguna('karyawan');
        $token = $this->masuk($saya);

        /* Kasus yang tidak melibatkan saya. */
        $asing = \App\Models\Kasus::create([
            'kode_kasus' => 'K-001', 'judul' => 'Kasus asing',
            'dibuat_oleh' => 999, 'penanggung_jawab_id' => 999,
        ]);

        $this->withToken($token)->getJson('/api/v1/kasus')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->withToken($token)->getJson("/api/v1/kasus/{$asing->id}")
            ->assertStatus(403);

        /* Kasus yang melibatkan saya (sebagai penanggung jawab). */
        $sayaKasus = \App\Models\Kasus::create([
            'kode_kasus' => 'K-002', 'judul' => 'Kasus saya',
            'dibuat_oleh' => 999, 'penanggung_jawab_id' => $saya->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/kasus')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->withToken($token)->getJson("/api/v1/kasus/{$sayaKasus->id}")
            ->assertOk()
            ->assertJsonPath('kasus.judul', 'Kasus saya');
    }

    public function test_mentor_melihat_semua_kasus(): void
    {
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($mentor);

        \App\Models\Kasus::create([
            'kode_kasus' => 'K-100', 'judul' => 'Kasus umum',
            'dibuat_oleh' => 999, 'penanggung_jawab_id' => 999,
        ]);

        $this->withToken($token)->getJson('/api/v1/kasus')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_notifikasi_hanya_milik_sendiri(): void
    {
        $saya = $this->buatPengguna();
        $token = $this->masuk($saya);

        $n = \App\Models\Notifikasi::create([
            'user_id' => $saya->id, 'pesan' => 'Laporan diperiksa', 'sudah_dibaca' => false,
        ]);
        \App\Models\Notifikasi::create([
            'user_id' => 888, 'pesan' => 'Milik orang lain', 'sudah_dibaca' => false,
        ]);

        $this->withToken($token)->getJson('/api/v1/notifikasi')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('belum_dibaca', 1);

        $this->withToken($token)->postJson("/api/v1/notifikasi/{$n->id}/dibaca")
            ->assertOk();

        $this->withToken($token)->getJson('/api/v1/notifikasi')
            ->assertJsonPath('belum_dibaca', 0);
    }

    public function test_ringkasan_menghitung_data_pengguna_sendiri(): void
    {
        $saya = $this->buatPengguna();
        $token = $this->masuk($saya);

        \App\Models\LaporanHarian::create([
            'user_id' => $saya->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A',
        ]);
        \App\Models\LaporanHarian::create([
            'user_id' => $saya->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'B',
        ]);
        \App\Models\Absensi::create([
            'user_id' => $saya->id, 'tanggal' => now()->toDateString(),
            'jam_masuk' => '08:00:00', 'status' => 'hadir',
        ]);
        \App\Models\Tugas::create([
            'user_id' => $saya->id, 'judul' => 'T', 'status' => 'baru',
            'tenggat' => now()->subDay()->toDateString(),
        ]);

        $this->withToken($token)->getJson('/api/v1/ringkasan')
            ->assertOk()
            ->assertJsonPath('ringkasan.laporan_bulan_ini', 2)
            ->assertJsonPath('ringkasan.absensi_hari_ini.ada', true)
            ->assertJsonPath('ringkasan.tugas_aktif', 1)
            ->assertJsonPath('ringkasan.tugas_terlambat', 1);
    }

    public function test_ganti_password_mengikuti_riwayat_sandi(): void
    {
        $saya = $this->buatPengguna();
        $token = $this->masuk($saya);

        /* Sandi lama salah. */
        $this->withToken($token)->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'SalahLama!',
            'password_baru' => 'BaruSekali123!',
            'password_baru_confirmation' => 'BaruSekali123!',
        ])->assertStatus(422);

        /* Sandi lama benar → berhasil & token masih hidup. */
        $this->withToken($token)->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'SandiRahasia123!',
            'password_baru' => 'BaruPertama456!',
            'password_baru_confirmation' => 'BaruPertama456!',
        ])->assertOk()->assertJsonPath('sukses', true);

        /* Ganti sekali lagi -- riwayat kini memuat kedua hash tersebut. */
        $this->withToken($token)->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'BaruPertama456!',
            'password_baru' => 'BaruKedua789!',
            'password_baru_confirmation' => 'BaruKedua789!',
        ])->assertOk();

        /* Sandi yang SUDAH PERNAH dipakai (tercatat riwayat) ditolak. */
        $this->withToken($token)->postJson('/api/v1/auth/ganti-password', [
            'password_lama' => 'BaruKedua789!',
            'password_baru' => 'BaruPertama456!',
            'password_baru_confirmation' => 'BaruPertama456!',
        ])->assertStatus(422);

        $this->assertTrue(Hash::check('BaruKedua789!', (string) $saya->fresh()->password));
    }
}
