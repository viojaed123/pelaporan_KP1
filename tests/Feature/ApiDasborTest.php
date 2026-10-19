<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Absensi;
use App\Models\Kasus;
use App\Models\KasusPenugasan;
use App\Models\LaporanHarian;
use App\Models\Tugas;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji API dasbor (kartu + tren + donut per peran), rekap kasus
 * (mentor/admin), dan ekspor XLSX.
 *
 * Skema dibuat minimal di sqlite :memory: mengikuti nama kolom produksi.
 */
class ApiDasborTest extends TestCase
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
            $t->text('hasil')->nullable();
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
            $t->string('penanggung_jawab_teks')->nullable();
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
            $t->string('nama_teks')->nullable();
            $t->string('peran')->nullable();
            $t->unsignedInteger('ditugaskan_oleh')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
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

    private function header(string $token): array
    {
        return ['Authorization' => 'Bearer ' . $token];
    }

    /* =================================================================
     * DASBOR
     * =============================================================== */

    public function test_dasbor_karyawan_hanya_melihat_datanya_sendiri(): void
    {
        $andi = $this->buatPengguna('karyawan', 'Andi');
        $budi = $this->buatPengguna('karyawan', 'Budi');
        $token = $this->masuk($andi);

        LaporanHarian::create(['user_id' => $andi->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A', 'status' => 'diperiksa']);
        LaporanHarian::create(['user_id' => $budi->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'B', 'status' => 'draft']);
        Absensi::create(['user_id' => $andi->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        Absensi::create(['user_id' => $budi->id, 'tanggal' => now()->toDateString(), 'status' => 'alpa']);
        Tugas::create(['user_id' => $andi->id, 'judul' => 'Tugas A', 'status' => 'baru', 'tenggat' => now()->subDay()->toDateString()]);
        /* Kasus yang TIDAK melibatkan Andi tidak boleh ikut. */
        Kasus::create(['kode_kasus' => 'KAS-X', 'judul' => 'Budi saja', 'status_kasus' => 'Terbuka', 'dibuat_oleh' => $budi->id]);
        $kasusAndi = Kasus::create(['kode_kasus' => 'KAS-Y', 'judul' => 'Punya Andi', 'status_kasus' => 'Dalam Proses', 'progres' => 50, 'dibuat_oleh' => $andi->id]);

        $res = $this->getJson('/api/v1/dasbor', ['Authorization' => 'Bearer ' . $token])
            ->assertOk()
            ->assertJsonPath('sukses', true)
            ->assertJsonPath('peran', 'karyawan')
            ->assertJsonPath('kartu.laporan_bulan_ini', 1)
            ->assertJsonPath('kartu.tugas_aktif', 1)
            ->assertJsonPath('kartu.tugas_terlambat', 1)
            ->assertJsonPath('kartu.kasus_total', 1)
            ->assertJsonPath('kartu.kasus_terbuka', 1)
            ->assertJsonPath('kartu.kasus_progres_rata', 50)
            ->assertJsonPath('kartu.absensi_hari_ini.hadir', 1)
            ->assertJsonPath('kartu.absensi_hari_ini.alpa', 0)
            ->assertJsonPath('kartu.persen_hadir_bulan', 100);

        $this->assertCount(30, $res->json('tren'));
        $this->assertSame(now()->toDateString(), $res->json('tren.29.tanggal'));
        $this->assertSame(1, $res->json('tren.29.total'));
        $this->assertSame(1, $res->json('tren.29.diperiksa'));
        $this->assertNotNull($kasusAndi->id);
        $this->assertSame(1, collect($res->json('donut_status'))->firstWhere('status', 'diperiksa')['jumlah']);
    }

    public function test_dasbor_mentor_melihat_data_global_dan_daftar_perlu_revisi(): void
    {
        $karyawan = $this->buatPengguna('karyawan', 'Andi');
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        LaporanHarian::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A', 'status' => 'perlu_revisi']);
        LaporanHarian::create(['user_id' => $karyawan->id, 'tanggal' => now()->subDay()->toDateString(), 'kegiatan' => 'B', 'status' => 'dikirim']);
        Absensi::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        Kasus::create(['kode_kasus' => 'KAS-1', 'judul' => 'Satu', 'status_kasus' => 'Terbuka', 'tingkat_urgensi' => 'Kritis', 'dibuat_oleh' => $mentor->id]);

        $res = $this->getJson('/api/v1/dasbor', $this->header($token))
            ->assertOk()
            ->assertJsonPath('peran', 'mentor')
            ->assertJsonPath('kartu.perlu_revisi_bulan_ini', 1)
            ->assertJsonPath('kartu.karyawan_total', 1)
            ->assertJsonPath('kartu.karyawan_hadir_hari', 1)
            ->assertJsonPath('kartu.kasus_total', 1);

        $this->assertCount(1, $res->json('perlu_revisi'));
        $this->assertSame('Andi', $res->json('perlu_revisi.0.karyawan'));
        $this->assertSame(1, $res->json('kasus.per_urgensi.Kritis'));
    }

    public function test_dasbor_karyawan_ditolak_tanpa_login(): void
    {
        $this->getJson('/api/v1/dasbor')->assertUnauthorized();
    }

    /* =================================================================
     * REKAP KASUS
     * =============================================================== */

    public function test_rekap_kasus_menghitung_agregat_per_pic_dan_terlambat(): void
    {
        $mentor = $this->buatPengguna('mentor', 'Mentor Satu');
        $pic = $this->buatPengguna('karyawan', 'Citra');
        $token = $this->masuk($mentor);

        /* 1. PIC terdaftar, selesai. */
        Kasus::create([
            'kode_kasus' => 'KAS-A', 'judul' => 'Selesai', 'status_kasus' => 'Selesai',
            'tingkat_urgensi' => 'Rendah', 'progres' => 100, 'kategori' => 'Lainnya',
            'dibuat_oleh' => $mentor->id, 'penanggung_jawab_id' => $pic->id,
        ]);
        /* 2. PIC teks bebas, terlambat (target lewat, belum selesai). */
        Kasus::create([
            'kode_kasus' => 'KAS-B', 'judul' => 'Terlambat', 'status_kasus' => 'Dalam Proses',
            'tingkat_urgensi' => 'Tinggi', 'progres' => 30, 'kategori' => 'Infrastruktur',
            'dibuat_oleh' => $mentor->id, 'penanggung_jawab_id' => null,
            'penanggung_jawab_teks' => 'Bagus', 'target_selesai' => now()->subDays(3)->toDateString(),
        ]);
        /* 3. Tanpa PIC, terbuka. */
        Kasus::create([
            'kode_kasus' => 'KAS-C', 'judul' => 'Tanpa PIC', 'status_kasus' => 'Terbuka',
            'tingkat_urgensi' => 'Sedang', 'progres' => 0,
            'dibuat_oleh' => $mentor->id,
        ]);

        $res = $this->getJson('/api/v1/rekap/kasus', $this->header($token))
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 3)
            ->assertJsonPath('ringkasan.selesai', 1)
            ->assertJsonPath('ringkasan.dalam_proses', 1)
            ->assertJsonPath('ringkasan.terbuka', 1)
            ->assertJsonPath('ringkasan.terlambat', 1)
            ->assertJsonPath('ringkasan.tanpa_pic', 1)
            ->assertJsonPath('ringkasan.progres_rata', 43)
            ->assertJsonPath('per_urgensi.0.urgensi', 'Tinggi')
            ->assertJsonPath('per_urgensi.0.jumlah', 1);

        /* Per PIC: nama pengguna terdaftar & teks bebas tampil apa adanya. */
        $namaPj = collect($res->json('per_penanggung_jawab'))->pluck('nama');
        $this->assertTrue($namaPj->contains('Citra'));
        $this->assertTrue($namaPj->contains('Bagus'));
        $this->assertTrue($namaPj->contains('Belum Ditentukan'));
        $this->assertCount(3, $res->json('data.data'));

        /* Filter status berlaku. */
        $this->getJson('/api/v1/rekap/kasus?status=Terbuka', $this->header($token))
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 1);
    }

    public function test_rekap_kasus_ditolak_untuk_karyawan(): void
    {
        $karyawan = $this->buatPengguna('karyawan');
        $token = $this->masuk($karyawan);

        $this->getJson('/api/v1/rekap/kasus', $this->header($token))->assertForbidden();
    }

    /* =================================================================
     * REKAP LAPORAN (agregat tambahan) & ABSENSI (persen hadir)
     * =============================================================== */

    public function test_rekap_laporan_memuat_agregat_harian_dan_per_pengguna(): void
    {
        $andi = $this->buatPengguna('karyawan', 'Andi');
        $budi = $this->buatPengguna('karyawan', 'Budi');
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($mentor);

        LaporanHarian::create(['user_id' => $andi->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'A', 'status' => 'diperiksa']);
        LaporanHarian::create(['user_id' => $andi->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'B', 'status' => 'perlu_revisi']);
        LaporanHarian::create(['user_id' => $budi->id, 'tanggal' => now()->subDay()->toDateString(), 'kegiatan' => 'C', 'status' => 'draft']);

        $res = $this->getJson('/api/v1/rekap/laporan', $this->header($token))->assertOk();

        $hariIni = collect($res->json('agregat_harian'))->firstWhere('tanggal', now()->toDateString());
        $this->assertSame(2, $hariIni['total']);
        $this->assertSame(1, $hariIni['diperiksa']);
        $this->assertSame(1, $hariIni['perlu_revisi']);

        $perAndi = collect($res->json('per_pengguna'))->firstWhere('karyawan', 'Andi');
        $this->assertNotNull($perAndi, 'Baris per_pengguna Andi tidak ditemukan: ' . json_encode($res->json('per_pengguna')));
        $this->assertSame(2, $perAndi['total'], 'Isi per_pengguna: ' . json_encode($res->json('per_pengguna')));
        $this->assertSame(1, $perAndi['perlu_revisi']);
        $this->assertSame(50, $perAndi['persen_revisi']);
    }

    public function test_rekap_absensi_memuat_persen_hadir_per_peserta(): void
    {
        $andi = $this->buatPengguna('karyawan', 'Andi');
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($mentor);

        Absensi::create(['user_id' => $andi->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        Absensi::create(['user_id' => $andi->id, 'tanggal' => now()->subDay()->toDateString(), 'status' => 'sakit']);

        $res = $this->getJson('/api/v1/rekap/absensi', $this->header($token))->assertOk();
        $baris = collect($res->json('per_peserta'))->firstWhere('nama', 'Andi');
        $this->assertSame(2, $baris['total_catatan']);
        $this->assertSame(1, $baris['hadir']);
        $this->assertSame(50, $baris['persen_hadir']);
    }

    /* =================================================================
     * EKSPOR XLSX
     * =============================================================== */

    public function test_ekspor_laporan_menghasilkan_xlsx_asli(): void
    {
        $karyawan = $this->buatPengguna('karyawan', 'Andi');
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($mentor);

        LaporanHarian::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'kegiatan' => 'Inspeksi pipa', 'status' => 'diperiksa']);

        $res = $this->get('/api/v1/ekspor/laporan', $this->header($token));
        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            (string) $res->headers->get('Content-Type')
        );
        $this->assertStringContainsString('rekap_laporan_', (string) $res->headers->get('Content-Disposition'));

        /* .xlsx adalah zip: berkas harus diawali "PK". */
        $isi = $res->streamedContent();
        $this->assertStringStartsWith('PK', $isi);
        $this->assertGreaterThan(1000, strlen($isi));
    }

    public function test_ekspor_absensi_dan_kasus_bekerja_dan_karyawan_ditolak(): void
    {
        $mentor = $this->buatPengguna('mentor');
        $karyawan = $this->buatPengguna('karyawan');
        $tokenMentor = $this->masuk($mentor);
        $tokenKaryawan = $this->masuk($karyawan);

        Absensi::create(['user_id' => $karyawan->id, 'tanggal' => now()->toDateString(), 'status' => 'hadir']);
        Kasus::create(['kode_kasus' => 'KAS-Z', 'judul' => 'Ekspor', 'status_kasus' => 'Terbuka', 'dibuat_oleh' => $mentor->id]);

        foreach (['/api/v1/ekspor/absensi', '/api/v1/ekspor/kasus'] as $url) {
            $res = $this->get($url, $this->header($tokenMentor))->assertOk();
            $this->assertStringStartsWith('PK', $res->streamedContent());

            $karyawanRes = $this->getJson($url, $this->header($tokenKaryawan));
            config(['app.debug_suji' => true]);
            $karyawanRes = $this->getJson($url, $this->header($tokenKaryawan));
            config(['app.debug_suji' => false]);
            if ($karyawanRes->baseResponse->getStatusCode() !== 403) {
                dump($url, $karyawanRes->baseResponse->getStatusCode());
            }
            $karyawanRes->assertForbidden();
        }
    }

    public function test_ekspor_menolak_query_tanpa_sah(): void
    {
        $mentor = $this->buatPengguna('mentor');
        $token = $this->masuk($mentor);

        $this->getJson('/api/v1/ekspor/laporan?tanggal_mulai=bukan-tanggal', $this->header($token))
            ->assertStatus(422);
    }
}
