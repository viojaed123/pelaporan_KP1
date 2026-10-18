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

        config(['keamanan.llm_base_url' => '', 'keamanan.llm_api_key' => '']);
        cache()->flush();
    }

    private function buatPengguna(): User
    {
        $u = new User();
        $u->nama = 'Pengguna Uji';
        $u->email = 'uji@contoh.com';
        $u->password = 'SandiRahasia123';
        $u->role = 'karyawan';
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
}
