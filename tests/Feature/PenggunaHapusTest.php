<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji perintah `pengguna:hapus` (pembersihan akun ganda sebelum Go-Live).
 *
 * Sasaran:
 *   - tanpa `--yakin` perintah hanya melaporkan (read-only);
 *   - akun yang masih memiliki data bisnis ditolak tanpa `--paksakan`;
 *   - kasus di mana akun sekadar PIC TIDAK ikut terhapus -- hanya
 *     `penanggung_jawab_id` dikosongkan, nama ketikan tetap tersimpan;
 *   - kasus yang DIBUAT akun itu ikut terhapus lengkap dengan turunannya;
 *   - jejak sistem (percobaan login, notifikasi, token API) ikut terhapus
 *     dan penghapusan tercatat pada audit_log.
 */
class PenggunaHapusTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

        Schema::create('absensi', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('jenis')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('kasus', function (Blueprint $t): void {
            $t->id();
            $t->string('kode_kasus')->nullable();
            $t->string('judul');
            $t->integer('dibuat_oleh')->nullable();
            $t->integer('penanggung_jawab_id')->nullable();
            $t->string('penanggung_jawab_teks')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('kasus_penugasan', function (Blueprint $t): void {
            $t->id();
            $t->integer('kasus_id');
            $t->integer('user_id')->nullable();
            $t->string('nama_teks')->nullable();
            $t->string('peran')->nullable();
        });

        Schema::create('notifikasi', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->integer('laporan_id')->nullable();
            $t->string('pesan');
            $t->string('link')->nullable();
            $t->boolean('sudah_dibaca')->default(false);
            $t->timestamp('created_at')->nullable();
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
    }

    private function pengguna(string $email = 'duplikat@phr.co.id', string $peran = 'karyawan', string $status = 'aktif'): User
    {
        $u = new User();
        $u->nama = 'Vio Fahendra';
        $u->email = $email;
        $u->password = 'SandiRahasia123!';
        $u->role = $peran;
        $u->status = $status;
        $u->save();

        return $u;
    }

    public function test_tanpa_yakin_hanya_melaporkan(): void
    {
        $u = $this->pengguna();
        DB::table('absensi')->insert(['user_id' => $u->id, 'jenis' => 'kegiatan']);

        $this->artisan('pengguna:hapus', ['id' => $u->id])
            ->expectsOutputToContain('Data bisnis milik akun ini:')
            ->expectsOutputToContain('absensi.user_id')
            ->expectsOutputToContain('Mode laporan (read-only)')
            ->assertSuccessful();

        /* Read-only: tidak ada yang berubah. */
        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertDatabaseHas('absensi', ['user_id' => $u->id]);
    }

    public function test_akun_bersih_terhapus_beserta_jejaknya(): void
    {
        $u = $this->pengguna();

        DB::table('login_attempts')->insert(['email' => $u->email, 'user_id' => $u->id, 'ip_address' => '127.0.0.1']);
        DB::table('notifikasi')->insert(['user_id' => $u->id, 'pesan' => 'Halo', 'sudah_dibaca' => 0]);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id'   => $u->id,
            'name'           => 'apk',
            'token'          => str_repeat('a', 64),
        ]);

        $this->artisan('pengguna:hapus', ['id' => $u->id, '--yakin' => true])
            ->expectsOutputToContain('beserta')
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->assertDatabaseMissing('login_attempts', ['user_id' => $u->id]);
        $this->assertDatabaseMissing('notifikasi', ['user_id' => $u->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $u->id]);

        /* Penghapusan tercatat pada audit trail. */
        $this->assertDatabaseHas('audit_log', [
            'aksi'      => 'hapus_pengguna',
            'tabel_ref' => 'users',
            'baris_id'  => $u->id,
        ]);
    }

    public function test_menolak_akun_yang_masih_punya_data(): void
    {
        $u = $this->pengguna();
        DB::table('absensi')->insert(['user_id' => $u->id, 'jenis' => 'kegiatan']);

        $this->artisan('pengguna:hapus', ['id' => $u->id, '--yakin' => true])
            ->expectsOutputToContain('masih memiliki data bisnis')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $u->id]);
        $this->assertDatabaseHas('absensi', ['user_id' => $u->id]);
    }

    public function test_data_terhapus_bila_paksakan(): void
    {
        $u = $this->pengguna();
        DB::table('absensi')->insert(['user_id' => $u->id, 'jenis' => 'kegiatan']);

        $this->artisan('pengguna:hapus', ['id' => $u->id, '--yakin' => true, '--paksakan' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $u->id]);
        $this->assertDatabaseMissing('absensi', ['user_id' => $u->id]);
    }

    public function test_kasus_pic_dilepas_bukan_dihapus(): void
    {
        $pembuat = $this->pengguna('pembuat@phr.co.id');
        $target  = $this->pengguna();

        DB::table('kasus')->insert([
            'kode_kasus'          => 'KAS-20261019-AAAA',
            'judul'               => 'Kasus milik pembuat lain',
            'dibuat_oleh'         => $pembuat->id,
            'penanggung_jawab_id' => $target->id,
            'penanggung_jawab_teks' => 'Vio Fahendra',
        ]);

        $this->artisan('pengguna:hapus', ['id' => $target->id, '--yakin' => true])
            ->expectsOutputToContain('hanya sebagai PIC')
            ->assertSuccessful();

        /* Kasus orang lain tetap ada; hanya tautan PIC-nya yang dilepas. */
        $this->assertDatabaseHas('kasus', ['id' => 1, 'judul' => 'Kasus milik pembuat lain']);
        $this->assertDatabaseMissing('users', ['id' => $target->id]);

        $kasus = DB::table('kasus')->where('judul', 'Kasus milik pembuat lain')->first();
        $this->assertNotNull($kasus);
        $this->assertNull($kasus->penanggung_jawab_id);
        /* Nama ketikan tetap tersimpan (bukan dihapus). */
        $this->assertSame('Vio Fahendra', $kasus->penanggung_jawab_teks);
    }

    public function test_kasus_yang_dibuat_ikut_terhapus_beserta_turunannya(): void
    {
        $u = $this->pengguna();

        DB::table('kasus')->insert([
            'kode_kasus'  => 'KAS-20261019-BBBB',
            'judul'       => 'Kasus si pembuat',
            'dibuat_oleh' => $u->id,
        ]);
        DB::table('kasus_penugasan')->insert([
            'kasus_id'  => 1,
            'user_id'   => $u->id,
            'nama_teks' => 'Vio Fahendra',
            'peran'     => 'Anggota Tim',
        ]);

        /* Kasus buatan akun = data bisnis, jadi wajib --paksakan. */
        $this->artisan('pengguna:hapus', ['id' => $u->id, '--yakin' => true])
            ->expectsOutputToContain('kasus.dibuat_oleh')
            ->assertFailed();

        $this->artisan('pengguna:hapus', ['id' => $u->id, '--yakin' => true, '--paksakan' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('kasus', ['judul' => 'Kasus si pembuat']);
        $this->assertDatabaseMissing('kasus_penugasan', ['kasus_id' => 1]);
        $this->assertDatabaseMissing('users', ['id' => $u->id]);
    }

    public function test_menolak_admin_terakhir(): void
    {
        $admin = $this->pengguna('admin@phr.co.id', 'admin');
        $this->pengguna();

        $this->artisan('pengguna:hapus', ['id' => $admin->id, '--yakin' => true])
            ->expectsOutputToContain('ADMIN terakhir')
            ->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_id_tidak_dikenal_ditolak(): void
    {
        $this->pengguna();

        $this->artisan('pengguna:hapus', ['id' => 9999, '--yakin' => true])
            ->expectsOutputToContain('tidak ditemukan')
            ->assertFailed();
    }
}
