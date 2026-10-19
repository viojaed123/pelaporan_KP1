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

        /* Dipakai uji endpoint /bi/kasus (opsional agar uji lain tetap utuh). */
        if (! Schema::hasTable('kasus')) {
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
                $t->string('penanggung_jawab_teks')->nullable();
                $t->string('kategori')->nullable();
                $t->string('sumber')->nullable();
                $t->date('target_selesai')->nullable();
                $t->string('lampiran')->nullable();
                $t->timestamp('dibuat_pada')->nullable();
                $t->timestamp('diperbarui_pada')->nullable();
            });
        }
        if (! Schema::hasTable('kasus_penugasan')) {
            Schema::create('kasus_penugasan', function (Blueprint $t): void {
                $t->increments('id');
                $t->unsignedInteger('kasus_id');
                $t->unsignedInteger('user_id')->nullable();
                $t->string('nama_teks')->nullable();
                $t->string('peran')->nullable();
                $t->unsignedInteger('ditugaskan_oleh')->nullable();
                $t->timestamp('dibuat_pada')->nullable();
            });
        }
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
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-10', 'kegiatan' => 'A', 'status' => 'dikirim']);
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-10', 'kegiatan' => 'B', 'status' => 'diperiksa']);

        $this->getJson('/api/v1/rekap/harian?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.jumlah_laporan', 2)
            ->assertJsonPath('data.0.dikirim', 1)
            ->assertJsonPath('data.0.diperiksa', 1)
            /* Status yang tidak ada di data harus 0 (bukan kolom mati). */
            ->assertJsonPath('data.0.draft', 0)
            ->assertJsonPath('data.0.perlu_revisi', 0)
            ->assertJsonPath('data.0.diajukan', 0);
    }

    public function test_rekap_pengguna_menghitung_status_sah_dan_divisi(): void
    {
        $user = $this->buatPengguna();
        $user->divisi = 'Operasional';
        $user->save();
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-10', 'kegiatan' => 'A', 'status' => 'diperiksa']);
        LaporanHarian::create(['user_id' => $user->id, 'tanggal' => '2026-10-11', 'kegiatan' => 'B', 'status' => 'perlu_revisi']);

        $this->getJson('/api/v1/rekap/pengguna?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.pengguna', 'Pengguna API')
            ->assertJsonPath('data.0.divisi', 'Operasional')
            ->assertJsonPath('data.0.jumlah_laporan', 2)
            ->assertJsonPath('data.0.diperiksa', 1)
            ->assertJsonPath('data.0.perlu_revisi', 1)
            ->assertJsonPath('data.0.dikirim', 0);
    }

    public function test_bi_kasus_mengembalikan_baris_dengan_pic_colesce(): void
    {
        $mentor = $this->buatPengguna();
        $kasus = \App\Models\Kasus::create([
            'kode_kasus' => 'KAS-20261019-0001',
            'judul' => 'Pipa bocor',
            'tingkat_urgensi' => 'Tinggi',
            'status_kasus' => 'Dalam Proses',
            'progres' => 40,
            'kategori' => 'Infrastruktur',
            'dibuat_oleh' => $mentor->id,
            'penanggung_jawab_id' => null,
            'penanggung_jawab_teks' => 'Budi Santoso',
        ]);
        \App\Models\KasusPenugasan::create([
            'kasus_id' => (int) $kasus->id,
            'user_id' => null,
            'nama_teks' => 'Tim Lapangan',
            'peran' => 'Anggota Tim',
            'ditugaskan_oleh' => $mentor->id,
        ]);

        $res = $this->getJson('/api/v1/bi/kasus?token=' . self::TOKEN)
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.kode_kasus', 'KAS-20261019-0001')
            /* PIC tak cocok → tampil sebagai teks (paritas web/SPA/APK). */
            ->assertJsonPath('data.0.penanggung_jawab', 'Budi Santoso')
            ->assertJsonPath('data.0.penanggung_jawab_terdaftar', false)
            ->assertJsonPath('data.0.jumlah_anggota', 1);

        $this->assertSame('Dalam Proses', $res->json('data.0.status_kasus'));

        $this->getJson('/api/v1/bi/kasus')->assertUnauthorized();
        $this->getJson('/api/v1/bi/kasus?token=salah')->assertUnauthorized();
    }

    public function test_bi_kasus_memfilter_status_dan_urgensi(): void
    {
        $u = $this->buatPengguna();
        \App\Models\Kasus::create(['kode_kasus' => 'KAS-1', 'judul' => 'Satu', 'tingkat_urgensi' => 'Rendah', 'status_kasus' => 'Terbuka', 'dibuat_oleh' => $u->id]);
        \App\Models\Kasus::create(['kode_kasus' => 'KAS-2', 'judul' => 'Dua', 'tingkat_urgensi' => 'Kritis', 'status_kasus' => 'Selesai', 'dibuat_oleh' => $u->id]);

        $this->getJson('/api/v1/bi/kasus?token=' . self::TOKEN . '&status=Terbuka')
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.kode_kasus', 'KAS-1');

        $this->getJson('/api/v1/bi/kasus?token=' . self::TOKEN . '&urgensi=Kritis')
            ->assertOk()
            ->assertJsonPath('jumlah', 1)
            ->assertJsonPath('data.0.kode_kasus', 'KAS-2');
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
