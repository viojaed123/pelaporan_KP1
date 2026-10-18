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
 * Uji endpoint API Power BI (/api/v1/*).
 *
 * Token di-set lewat config (sama seperti .env POWERBI_API_TOKEN).
 * Tabel laporan_harian & absensi dibuat mengikuti skema produksi secara
 * minimal di sqlite :memory:.
 */
class ApiLaporanTest extends TestCase
{
    use SkemaAuth;

    private const TOKEN = 'token-uji-rahasia';

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();
        config(['keamanan.powerbi_token' => self::TOKEN]);

        Schema::create('laporan_harian', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('jam_mulai', 8)->nullable();
            $t->string('jam_selesai', 8)->nullable();
            $t->string('kegiatan');
            $t->string('lokasi_teks')->nullable();
            $t->decimal('garis_lintang', 10, 7)->nullable();
            $t->decimal('garis_bujur', 10, 7)->nullable();
            $t->unsignedInteger('wilayah_id')->nullable();
            $t->text('deskripsi')->nullable();
            $t->text('hasil')->nullable();
            $t->text('kendala')->nullable();
            $t->text('rencana_berikutnya')->nullable();
            $t->string('status')->default('diperiksa');
            $t->string('catatan_mentor')->nullable();
            $t->timestamps();
        });

        Schema::create('absensi', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('jam_masuk', 8)->nullable();
            $t->string('jam_pulang', 8)->nullable();
            $t->string('status')->default('hadir');
            $t->string('keterangan')->nullable();
            $t->text('kegiatan_hari_ini')->nullable();
            $t->string('lokasi_teks')->nullable();
            $t->decimal('garis_lintang', 10, 7)->nullable();
            $t->decimal('garis_bujur', 10, 7)->nullable();
            $t->unsignedInteger('wilayah_id')->nullable();
            $t->string('kondisi_kesehatan')->nullable();
            $t->timestamps();
        });
    }

    private function buatPengguna(): User
    {
        $u = new User();
        $u->nama = 'Pengguna API';
        $u->email = 'api@contoh.com';
        $u->password = 'SandiRahasia123';
        $u->role = 'karyawan';
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    public function test_tanpa_token_ditolak_401(): void
    {
        $this->getJson('/api/v1/laporan')->assertUnauthorized();
    }

    public function test_token_salah_ditolak_401(): void
    {
        $this->getJson('/api/v1/laporan?token=salah')->assertUnauthorized();
    }

    public function test_token_kosong_pada_config_menonaktifkan_api(): void
    {
        config(['keamanan.powerbi_token' => '']);
        $this->getJson('/api/v1/laporan?token=' . self::TOKEN)->assertUnauthorized();
    }

    public function test_laporan_dikembalikan_dalam_bentuk_datar(): void
    {
        $user = $this->buatPengguna();
        LaporanHarian::create([
            'user_id' => $user->id,
            'tanggal' => '2026-10-01',
            'kegiatan' => 'Inspeksi jalur pipa',
            'status' => 'disetujui',
        ]);

        $res = $this->getJson('/api/v1/laporan?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.kegiatan', 'Inspeksi jalur pipa')
            ->assertJsonPath('data.0.pengguna', 'Pengguna API');

        $this->assertSame('2026-10-01', $res->json('data.0.tanggal'));
    }

    public function test_filter_rentang_tanggal_bekerja(): void
    {
        $user = $this->buatPengguna();
        foreach (['2026-09-20', '2026-10-05'] as $tgl) {
            LaporanHarian::create(['user_id' => $user->id, 'tanggal' => $tgl, 'kegiatan' => 'Kegiatan ' . $tgl]);
        }

        $this->getJson('/api/v1/laporan?token=' . self::TOKEN . '&dari=2026-10-01')
            ->assertOk()
            ->assertJsonPath('jumlah', 1);
    }

    public function test_rekap_harian_menghitung_per_status(): void
    {
        $user = $this->buatPengguna();
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-10', 'kegiatan' => 'A', 'status' => 'disetujui']);
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-10', 'kegiatan' => 'B', 'status' => 'diperiksa']);

        $this->getJson('/api/v1/rekap/harian?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.jumlah_laporan', 2)
            ->assertJsonPath('data.0.disetujui', 1)
            ->assertJsonPath('data.0.diperiksa', 1);
    }

    public function test_absensi_dikembalikan(): void
    {
        $user = $this->buatPengguna();
        Absensi::create(['user_id' => $user->id, 'tanggal' => '2026-10-12', 'status' => 'hadir']);

        $this->getJson('/api/v1/absensi?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.status', 'hadir');
    }
}
