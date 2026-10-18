<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tugas;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji modul pembagian tugas (mentor/admin → karyawan).
 *
 * Memakai sqlite :memory: dengan skema warisan (users) dari SkemaAuth,
 * ditambah tabel `tugas` yang dibuat mengikuti migrasi produksi.
 */
class TugasTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

        Schema::create('tugas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->unsignedInteger('ditugaskan_oleh');
            $t->string('judul', 200);
            $t->text('deskripsi')->nullable();
            $t->date('tenggat')->nullable();
            $t->enum('status', ['baru', 'dikerjakan', 'selesai', 'dibatalkan'])->default('baru');
            $t->timestamp('diselesaikan_pada')->nullable();
            $t->timestamps();
        });
    }

    private function buatPengguna(array $a = []): User
    {
        $u = new User();
        $u->nama = $a['nama'] ?? 'Pengguna Uji';
        $u->email = $a['email'] ?? 'uji@contoh.com';
        $u->password = $a['password'] ?? 'SandiRahasia123';
        $u->role = $a['role'] ?? 'karyawan';
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    public function test_karyawan_melihat_hanya_tugasnya(): void
    {
        $karyawan = $this->buatPengguna(['role' => 'karyawan']);
        $lain = $this->buatPengguna(['nama' => 'Karyawan Lain', 'email' => 'lain@contoh.com']);

        Tugas::create(['user_id' => $karyawan->id, 'ditugaskan_oleh' => 1, 'judul' => 'Tugas Saya']);
        Tugas::create(['user_id' => $lain->id, 'ditugaskan_oleh' => 1, 'judul' => 'Tugas Orang Lain']);

        $this->actingAs($karyawan)->get(route('tugas.index'))
            ->assertOk()
            ->assertSee('Tugas Saya')
            ->assertDontSee('Tugas Orang Lain');
    }

    public function test_mentor_dapat_menugaskan_tugas(): void
    {
        $mentor = $this->buatPengguna(['role' => 'mentor', 'email' => 'mentor@contoh.com']);
        $karyawan = $this->buatPengguna(['nama' => 'Budi', 'email' => 'budi@contoh.com']);

        $this->actingAs($mentor)->post(route('tugas.simpan'), [
            'user_id' => $karyawan->id,
            'judul' => 'Rekap lapangan',
            'deskripsi' => 'Kumpulkan data minggu ini',
            'tenggat' => now()->addDays(3)->toDateString(),
        ])->assertRedirect(route('tugas.index'));

        $this->assertDatabaseHas('tugas', [
            'user_id' => $karyawan->id,
            'ditugaskan_oleh' => $mentor->id,
            'judul' => 'Rekap lapangan',
            'status' => 'baru',
        ]);
    }

    public function test_karyawan_tidak_bisa_menugaskan(): void
    {
        $karyawan = $this->buatPengguna();
        $lain = $this->buatPengguna(['nama' => 'Lain', 'email' => 'lain@contoh.com']);

        $this->actingAs($karyawan)->post(route('tugas.simpan'), [
            'user_id' => $lain->id,
            'judul' => 'Tugas Curian',
        ])->assertForbidden();

        $this->assertDatabaseMissing('tugas', ['judul' => 'Tugas Curian']);
    }

    public function test_karyawan_bisa_menandai_tugas_selesai(): void
    {
        $karyawan = $this->buatPengguna();
        $tugas = Tugas::create(['user_id' => $karyawan->id, 'ditugaskan_oleh' => 1, 'judul' => 'Kerjakan']);

        $this->actingAs($karyawan)->post(route('tugas.status', $tugas->id), ['status' => 'selesai'])
            ->assertRedirect(route('tugas.index'));

        $this->assertDatabaseHas('tugas', ['id' => $tugas->id, 'status' => 'selesai']);
        $this->assertNotNull(Tugas::find($tugas->id)->diselesaikan_pada);
    }

    public function test_pengguna_lain_ditolak_mengubah_status_tugas(): void
    {
        $pemilik = $this->buatPengguna();
        $penyusup = $this->buatPengguna(['nama' => 'Penyusup', 'email' => 'penyusup@contoh.com', 'role' => 'karyawan']);
        $tugas = Tugas::create(['user_id' => $pemilik->id, 'ditugaskan_oleh' => 1, 'judul' => 'Rahasia']);

        $this->actingAs($penyusup)->post(route('tugas.status', $tugas->id), ['status' => 'selesai'])
            ->assertForbidden();

        $this->assertDatabaseHas('tugas', ['id' => $tugas->id, 'status' => 'baru']);
    }

    public function test_mentor_bisa_mengubah_status_tugas_anggota(): void
    {
        $mentor = $this->buatPengguna(['role' => 'mentor', 'email' => 'mentor@contoh.com']);
        $karyawan = $this->buatPengguna();
        $tugas = Tugas::create(['user_id' => $karyawan->id, 'ditugaskan_oleh' => $mentor->id, 'judul' => 'Dipantau']);

        $this->actingAs($mentor)->post(route('tugas.status', $tugas->id), ['status' => 'dikerjakan'])
            ->assertRedirect(route('tugas.index'));

        $this->assertDatabaseHas('tugas', ['id' => $tugas->id, 'status' => 'dikerjakan']);
    }

    public function test_tenggat_wajib_hari_ini_atau_lebih_baik(): void
    {
        $mentor = $this->buatPengguna(['role' => 'mentor', 'email' => 'mentor@contoh.com']);
        $karyawan = $this->buatPengguna();

        $this->actingAs($mentor)->post(route('tugas.simpan'), [
            'user_id' => $karyawan->id,
            'judul' => 'Tenggat Lampau',
            'tenggat' => now()->subDay()->toDateString(),
        ])->assertSessionHasErrors('tenggat');
    }
}
