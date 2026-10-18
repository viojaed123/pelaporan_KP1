<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\LaporanHarian;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji perintah Artisan `pengingat:harian` (porting pengingat harian lama).
 *
 * Fokus: hanya karyawan yang BELUM mengisi laporan yang diingatkan;
 * status izin/sakit dilewati; notifikasi dibuat sekali (idempoten);
 * mode kering tidak menulis apa pun.
 */
class PengingatHarianTest extends TestCase
{
    use SkemaAuth;

    private const TANGGAL = '2026-10-15';

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
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('absensi', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('status')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
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

        cache()->flush();
    }

    private function karyawan(string $nama): User
    {
        $u = new User();
        $u->nama = $nama;
        $u->email = strtolower($nama) . '@contoh.com';
        $u->password = 'SandiRahasia123!';
        $u->role = 'karyawan';
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    public function test_mengingatkan_yang_belum_lapor_dan_idempoten(): void
    {
        $andi = $this->karyawan('Andi');
        $budi = $this->karyawan('Budi');
        $cici = $this->karyawan('Cici');

        /* Budi sudah mengisi laporan; Cici izin (tidak wajib lapor). */
        LaporanHarian::create([
            'user_id' => $budi->id, 'tanggal' => self::TANGGAL, 'kegiatan' => 'Sudah lapor',
        ]);
        Absensi::create([
            'user_id' => $cici->id, 'tanggal' => self::TANGGAL, 'status' => 'izin',
        ]);

        $this->artisan('pengingat:harian', ['--tanggal' => self::TANGGAL])
            ->assertSuccessful();

        /* Hanya Andi yang diingatkan. */
        $this->assertSame(1, Notifikasi::where('user_id', $andi->id)->count());
        $this->assertSame(0, Notifikasi::where('user_id', $budi->id)->count());
        $this->assertSame(0, Notifikasi::where('user_id', $cici->id)->count());

        $pesan = (string) Notifikasi::where('user_id', $andi->id)->value('pesan');
        $this->assertStringContainsString('Pengingat:', $pesan);
        $this->assertStringContainsString('15 Oktober 2026', $pesan);

        /* Dijalankan ulang: tidak menggandakan notifikasi. */
        $this->artisan('pengingat:harian', ['--tanggal' => self::TANGGAL])
            ->assertSuccessful();

        $this->assertSame(1, Notifikasi::where('user_id', $andi->id)->count());
    }

    public function test_mode_kering_tidak_menulis_notifikasi(): void
    {
        $this->karyawan('Andi');

        $this->artisan('pengingat:harian', ['--tanggal' => self::TANGGAL, '--kering' => true])
            ->assertSuccessful();

        $this->assertSame(0, Notifikasi::count());
    }

    public function test_tanggal_tidak_valid_gagal(): void
    {
        $this->karyawan('Andi');

        $this->artisan('pengingat:harian', ['--tanggal' => '15-10-2026'])
            ->assertFailed();

        $this->assertSame(0, Notifikasi::count());
    }
}
