<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji perintah `pengguna:audit` (read-only).
 *
 * Memastikan laporan menyebut akun uji, nama duplikat, dan tidak mengubah
 * data apa pun.
 */
class AuditPenggunaTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

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
    }

    private function pengguna(string $nama, string $email, string $peran, string $status): User
    {
        $u = new User();
        $u->nama = $nama;
        $u->email = $email;
        $u->password = 'SandiRahasia123!';
        $u->role = $peran;
        $u->status = $status;
        $u->save();

        return $u;
    }

    public function test_audit_melaporkan_akun_uji_dan_nama_duplikat(): void
    {
        $this->pengguna('Andi', 'andi@phr.co.id', 'karyawan', 'aktif');
        $this->pengguna('Budi', 'budi@phr.co.id', 'karyawan', 'aktif');
        $this->pengguna('Budi', 'budi2@phr.co.id', 'karyawan', 'aktif'); // nama duplikat
        $this->pengguna('Tes Satu', 'tes_1@uji.local', 'karyawan', 'menunggu');
        $this->pengguna('Admin', 'admin@phr.co.id', 'admin', 'aktif');

        $sebelum = User::count();

        $this->artisan('pengguna:audit')
            ->expectsOutputToContain('Akun uji (@uji.local): 1')
            ->expectsOutputToContain('Budi  (2 akun)')
            ->assertSuccessful();

        /* Read-only: tidak ada pengguna yang berubah/terhapus. */
        $this->assertSame($sebelum, User::count());
    }
}
