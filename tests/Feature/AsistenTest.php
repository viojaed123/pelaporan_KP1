<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\LaporanHarian;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji Asisten AI: jawaban database, kunci mati LLM, dan ketahanan chat.
 *
 * Sasaran Fase F (perbaikan "web menutup saat minta cari berita"):
 * permintaan tidak boleh pernah menggantung pada HTTP keluar -- LLM
 * dinonaktifkan otomatis setelah kegagalan pertama.
 */
class AsistenTest extends TestCase
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
            $t->string('kegiatan');
            $t->text('kendala')->nullable();
            $t->string('status')->default('diperiksa');
            $t->timestamps();
        });

        Schema::create('chat_sessions', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->string('judul')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        Schema::create('chat_messages', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('session_id');
            $t->string('peran');
            $t->text('isi');
            $t->text('tool_calls')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        /* Tabel pendukung skill asisten (absensi, tugas, kasus, jadwal). */
        Schema::create('absensi', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('jam_masuk')->nullable();
            $t->string('jam_pulang')->nullable();
            $t->string('status')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('tugas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->string('judul');
            $t->date('tenggat')->nullable();
            $t->string('status')->default('baru');
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('kasus', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode_kasus')->nullable();
            $t->string('judul');
            $t->string('tingkat_urgensi')->nullable();
            $t->string('status_kasus')->nullable();
            $t->integer('progres')->default(0);
            $t->unsignedInteger('dibuat_oleh')->nullable();
            $t->unsignedInteger('penanggung_jawab_id')->nullable();
            $t->string('penanggung_jawab_teks')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        Schema::create('kasus_penugasan', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('kasus_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('nama_teks')->nullable();
            $t->string('peran')->nullable();
        });

        Schema::create('jadwal', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->string('judul');
            $t->string('lokasi')->nullable();
            $t->timestamp('mulai')->nullable();
            $t->timestamp('selesai')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        config(['keamanan.llm_base_url' => '', 'keamanan.llm_api_key' => '']);
        cache()->flush();
    }

    private function buatPengguna(string $peran = 'karyawan', string $nama = ''): User
    {
        $u = new User();
        $u->nama = $nama !== '' ? $nama : 'Pengguna Uji';
        $u->email = $peran . '_' . uniqid() . '@contoh.com';
        $u->password = 'SandiRahasia123';
        $u->role = $peran;
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    public function test_pertanyaan_laporan_dijawab_dari_database(): void
    {
        $user = $this->buatPengguna();
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A']);
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'B']);

        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'berapa laporan bulan ini'])
            ->assertOk()
            ->assertJsonPath('sukses', true);

        $this->assertStringContainsString('Total laporan', $res->json('jawaban'));
        $this->assertStringContainsString('2', $res->json('jawaban'));
    }

    public function test_tanpa_konfigurasi_llm_tidak_pernah_memanggil_http(): void
    {
        $user = $this->buatPengguna();
        // Bila kode nekat memanggil provider, fake ini melempar galat dan test gagal.
        Http::fake(function () {
            $this->fail('HTTP keluar dipanggil walau LLM belum dikonfigurasi.');
        });

        $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'cari berita hari ini'])
            ->assertOk()
            ->assertJsonPath('sukses', true);
    }

    public function test_key_ditolak_provider_mengunci_llm_mati(): void
    {
        $user = $this->buatPengguna();
        config(['keamanan.llm_base_url' => 'https://contoh-ai.test/v1', 'keamanan.llm_api_key' => 'kunci-salah']);

        Http::fake(['*' => Http::response(['error' => ['code' => 'invalid_api_key']], 401)]);

        $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'halo'])
            ->assertOk();

        $this->assertTrue((bool) cache()->get('asisten:llm_mati'), 'Kunci mati LLM harus aktif setelah 401.');

        // Pesan kedua: benar-benar tanpa HTTP (provider sudah dikunci).
        Http::fake(function () {
            $this->fail('HTTP masih dipanggil setelah kunci mati LLM.');
        });
        $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'halo lagi'])
            ->assertOk();
    }

    public function test_riwayat_percakapan_tercatat(): void
    {
        $user = $this->buatPengguna();
        $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'halo'])->assertOk();

        $this->assertSame(1, ChatSession::where('user_id', $user->id)->count());
        $this->assertSame(2, ChatMessage::count()); // user + assistant
    }

    public function test_pesan_kosong_ditolak_validasi(): void
    {
        $user = $this->buatPengguna();
        $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => '  '])
            ->assertStatus(422);
    }

    /* =================================================================
     * SKILL ATLAS (registry)
     * =============================================================== */

    public function test_skill_bantuan_mendaftar_seluruh_skill(): void
    {
        $user = $this->buatPengguna();
        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'bantuan'])
            ->assertOk();

        $jawaban = (string) $res->json('jawaban');
        foreach (\App\Http\Controllers\AsistenController::SKILLS as $skill) {
            $this->assertStringContainsString($skill, $jawaban, "Skill '$skill' tidak terdaftar di bantuan.");
        }
    }

    public function test_skill_kasus_menghitung_status_dan_yang_terbuka(): void
    {
        $user = $this->buatPengguna();
        \App\Models\Kasus::create([
            'kode_kasus' => 'KAS-A', 'judul' => 'Pipa bocor', 'status_kasus' => 'Terbuka',
            'tingkat_urgensi' => 'Tinggi', 'progres' => 10, 'dibuat_oleh' => $user->id,
        ]);
        \App\Models\Kasus::create([
            'kode_kasus' => 'KAS-B', 'judul' => 'Jalan rusak', 'status_kasus' => 'Selesai',
            'tingkat_urgensi' => 'Rendah', 'progres' => 100, 'dibuat_oleh' => $user->id,
        ]);

        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'berapa kasus terbuka'])
            ->assertOk();

        $jawaban = (string) $res->json('jawaban');
        $this->assertStringContainsString('Total kasus', $jawaban);
        $this->assertStringContainsString('Terbuka: 1', $jawaban);
        $this->assertStringContainsString('Selesai: 1', $jawaban);
        $this->assertStringContainsString('Belum selesai (Terbuka + Dalam Proses): **1**', $jawaban);
    }

    public function test_skill_tugas_menghitung_aktif_dan_terlambat(): void
    {
        $user = $this->buatPengguna();
        \App\Models\Tugas::create(['user_id' => $user->id, 'judul' => 'Kunjungan desa', 'status' => 'baru', 'tenggat' => now()->subDay()->toDateString()]);
        \App\Models\Tugas::create(['user_id' => $user->id, 'judul' => 'Laporan mingguan', 'status' => 'selesai']);

        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'tugas saya'])
            ->assertOk();

        $jawaban = (string) $res->json('jawaban');
        $this->assertStringContainsString('Tugas aktif: **1**', $jawaban);
        $this->assertStringContainsString('terlambat: **1**', $jawaban);
        $this->assertStringContainsString('selesai: **1**', $jawaban);
    }

    public function test_skill_rekap_karyawan_khusus_mentor_admin(): void
    {
        $karyawan = $this->buatPengguna('karyawan', 'Andi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');

        LaporanHarian::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A', 'status' => 'diperiksa']);
        LaporanHarian::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'B', 'status' => 'perlu_revisi']);

        $mentorRes = $this->actingAs($mentor)->postJson(route('asisten.kirim'), ['pesan' => 'rekap karyawan bulan ini'])
            ->assertOk();
        $this->assertStringContainsString('Andi: **2** laporan', (string) $mentorRes->json('jawaban'));
        $this->assertStringContainsString('perlu revisi 1', (string) $mentorRes->json('jawaban'));

        $karyawanRes = $this->actingAs($karyawan)->postJson(route('asisten.kirim'), ['pesan' => 'rekap karyawan'])
            ->assertOk();
        $this->assertStringContainsString('mentor/admin', (string) $karyawanRes->json('jawaban'));
    }

    public function test_skill_ringkasan_menggabungkan_laporan_absensi_kasus_tugas(): void
    {
        $user = $this->buatPengguna();
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A', 'kendala' => 'sinyal hilang']);
        \App\Models\Absensi::create(['user_id' => $user->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        \App\Models\Kasus::create(['kode_kasus' => 'KAS-R', 'judul' => 'Ringkas', 'status_kasus' => 'Dalam Proses', 'dibuat_oleh' => $user->id]);
        \App\Models\Tugas::create(['user_id' => $user->id, 'judul' => 'Tugas R', 'status' => 'dikerjakan']);

        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'ringkas 7 hari terakhir'])
            ->assertOk();

        $jawaban = (string) $res->json('jawaban');
        $this->assertStringContainsString('Laporan: **1**', $jawaban);
        $this->assertStringContainsString('kendala', $jawaban);
        $this->assertStringContainsString('Absensi hadir: 1', $jawaban);
        $this->assertStringContainsString('Kasus belum selesai: **1**', $jawaban);
        $this->assertStringContainsString('Tugas aktif: **1**', $jawaban);
    }

    public function test_skill_agenda_menampilkan_jadwal_dan_absensi_hari_ini(): void
    {
        $user = $this->buatPengguna();
        \App\Models\Jadwal::create([
            'user_id' => $user->id, 'judul' => 'Koordinasi mitra',
            'lokasi' => 'Kantor Desa', 'mulai' => now()->setTime(9, 0), 'selesai' => now()->setTime(10, 0),
        ]);
        \App\Models\Absensi::create(['user_id' => $user->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir', 'jam_masuk' => '08:15:00']);

        $res = $this->actingAs($user)->postJson(route('asisten.kirim'), ['pesan' => 'jadwal hari ini'])
            ->assertOk();

        $jawaban = (string) $res->json('jawaban');
        $this->assertStringContainsString('Koordinasi mitra', $jawaban);
        $this->assertStringContainsString('Kantor Desa', $jawaban);
        $this->assertStringContainsString('Absensi hari ini: hadir', $jawaban);
    }

    public function test_skill_kasus_karyawan_dipersempit_ke_yang_melibatkannya(): void
    {
        $andi = $this->buatPengguna('karyawan', 'Andi');
        $budi = $this->buatPengguna('karyawan', 'Budi');

        \App\Models\Kasus::create(['kode_kasus' => 'KAS-BUDI', 'judul' => 'Milik Budi', 'status_kasus' => 'Terbuka', 'dibuat_oleh' => $budi->id]);

        $res = $this->actingAs($andi)->postJson(route('asisten.kirim'), ['pesan' => 'berapa jumlah kasus saya'])
            ->assertOk();

        $this->assertStringContainsString('Total kasus yang melibatkan Anda: **0**', (string) $res->json('jawaban'));
    }
}
