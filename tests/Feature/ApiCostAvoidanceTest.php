<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostAvoidance;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

class ApiCostAvoidanceTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

        Schema::create('cost_avoidance', function (Blueprint $t): void {
            $t->increments('id');
            $t->string('kode', 20)->unique();
            $t->string('judul', 200);
            $t->string('kategori', 80);
            $t->text('deskripsi')->nullable();
            $t->string('unit', 120)->nullable();
            $t->date('periode');
            $t->decimal('estimasi', 15, 2)->default(0);
            $t->decimal('realisasi', 15, 2)->default(0);
            $t->decimal('nilai_avoidance', 15, 2)->default(0);
            $t->string('status')->default('draft');
            $t->string('catatan_verifikasi', 255)->nullable();
            $t->unsignedInteger('dibuat_oleh');
            $t->unsignedInteger('diverifikasi_oleh')->nullable();
            $t->dateTime('diajukan_pada')->nullable();
            $t->dateTime('diverifikasi_pada')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        Schema::create('laporan_harian', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->date('tanggal');
            $t->string('kegiatan');
            $t->string('status')->nullable();
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
            $t->timestamp('created_at')->nullable();
            $t->timestamp('updated_at')->nullable();
        });

        Schema::create('tugas', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('user_id');
            $t->string('judul');
            $t->string('status')->default('baru');
            $t->date('tenggat')->nullable();
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

    private function pengguna(string $peran = 'karyawan', string $nama = ''): User
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
            'email' => $user->email,
            'password' => 'SandiRahasia123!',
        ])->assertOk();

        return (string) $res->json('token');
    }

    private function hdr(string $token): array
    {
        $this->app->make('auth')->forgetGuards();

        return ['Authorization' => 'Bearer ' . $token];
    }

    private function muatan(array $ganti = []): array
    {
        return array_merge([
            'judul' => 'Negosiasi harga vendor',
            'kategori' => 'Negosiasi Pengadaan',
            'periode' => '2026-08',
            'estimasi' => 2000000,
            'realisasi' => 750000,
            'unit' => 'Divisi Teknik',
            'deskripsi' => 'Harga satuan turun setelah negosiasi.',
        ], $ganti);
    }

    public function test_karyawan_mencatat_draft_dan_nilai_dihitung(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $token = $this->masuk($andi);

        $res = $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($token))
            ->assertCreated()
            ->assertJsonPath('sukses', true)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.nilai_avoidance', 1250000)
            ->assertJsonPath('data.periode', '2026-08')
            ->assertJsonPath('data.boleh_ubah', true)
            ->assertJsonPath('data.boleh_verifikasi', false);

        $this->assertMatchesRegularExpression('/^CA-\d{4}-\d{4}$/', (string) $res->json('data.kode'));
        $this->assertSame(1, CostAvoidance::count());
        $this->assertSame('draft', CostAvoidance::first()->status);
    }

    public function test_kode_berurutan_dalam_tahun_yang_sama(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $token = $this->masuk($andi);

        $satu = $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($token))->assertCreated();
        $dua = $this->postJson('/api/v1/cost-avoidance', $this->muatan(['judul' => 'Kedua']), $this->hdr($token))->assertCreated();

        $this->assertSame(
            (int) substr((string) $satu->json('data.kode'), -4) + 1,
            (int) substr((string) $dua->json('data.kode'), -4)
        );
    }

    public function test_realisasi_melebihi_estimasi_ditolak(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $token = $this->masuk($andi);

        $this->postJson('/api/v1/cost-avoidance', $this->muatan(['realisasi' => 2500000]), $this->hdr($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['realisasi']);

        $this->assertSame(0, CostAvoidance::count());
    }

    public function test_kategori_di_luar_daftar_ditolak(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $token = $this->masuk($andi);

        $this->postJson('/api/v1/cost-avoidance', $this->muatan(['kategori' => 'Karangan']), $this->hdr($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kategori']);
    }

    public function test_karyawan_hanya_melihat_data_sendiri(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $budi = $this->pengguna('karyawan', 'Budi');
        $mentor = $this->pengguna('mentor');

        CostAvoidance::create([
            'kode' => 'CA-2026-0001', 'judul' => 'Punya Andi', 'kategori' => 'Lainnya',
            'periode' => '2026-08-01', 'estimasi' => 100, 'realisasi' => 0,
            'nilai_avoidance' => 100, 'status' => 'draft', 'dibuat_oleh' => $andi->id,
        ]);
        CostAvoidance::create([
            'kode' => 'CA-2026-0002', 'judul' => 'Punya Budi', 'kategori' => 'Lainnya',
            'periode' => '2026-08-01', 'estimasi' => 200, 'realisasi' => 0,
            'nilai_avoidance' => 200, 'status' => 'draft', 'dibuat_oleh' => $budi->id,
        ]);

        $res = $this->getJson('/api/v1/cost-avoidance', $this->hdr($this->masuk($andi)))
            ->assertOk()
            ->assertJsonPath('sukses', true);

        $this->assertCount(1, $res->json('data.data'));
        $this->assertSame('Punya Andi', $res->json('data.data.0.judul'));

        $resMentor = $this->getJson('/api/v1/cost-avoidance', $this->hdr($this->masuk($mentor)))->assertOk();
        $this->assertCount(2, $resMentor->json('data.data'));
    }

    public function test_alur_ajukan_dan_setujui_menambah_total(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $mentor = $this->pengguna('mentor', 'Mentor Satu');
        $tokenAndi = $this->masuk($andi);
        $tokenMentor = $this->masuk($mentor);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($tokenAndi))
            ->assertOk()
            ->assertJsonPath('data.status', 'diajukan');

        $this->postJson("/api/v1/cost-avoidance/{$id}/verifikasi", ['keputusan' => 'setujui'], $this->hdr($tokenMentor))
            ->assertOk()
            ->assertJsonPath('data.status', 'disetujui')
            ->assertJsonPath('data.verifikator', 'Mentor Satu');

        $this->getJson('/api/v1/cost-avoidance/ringkasan', $this->hdr($tokenAndi))
            ->assertOk()
            ->assertJsonPath('ringkasan.total_disetujui', 1250000)
            ->assertJsonPath('ringkasan.jumlah.disetujui', 1)
            ->assertJsonPath('ringkasan.per_kategori.0.nama', 'Negosiasi Pengadaan');
    }

    public function test_mentor_tidak_boleh_memverifikasi_data_sendiri(): void
    {
        $mentor = $this->pengguna('mentor', 'Mentor Satu');
        $token = $this->masuk($mentor);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($token))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($token))->assertOk();

        $this->postJson("/api/v1/cost-avoidance/{$id}/verifikasi", ['keputusan' => 'setujui'], $this->hdr($token))
            ->assertForbidden();
    }

    public function test_admin_boleh_memverifikasi_data_sendiri(): void
    {
        $admin = $this->pengguna('admin', 'Admin Satu');
        $token = $this->masuk($admin);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($token))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($token))->assertOk();

        $this->postJson("/api/v1/cost-avoidance/{$id}/verifikasi", ['keputusan' => 'setujui'], $this->hdr($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'disetujui');
    }

    public function test_tolak_wajib_catatan(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $mentor = $this->pengguna('mentor', 'Mentor Satu');
        $tokenAndi = $this->masuk($andi);
        $tokenMentor = $this->masuk($mentor);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))->json('data.id');
        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($tokenAndi))->assertOk();

        $this->postJson("/api/v1/cost-avoidance/{$id}/verifikasi", ['keputusan' => 'tolak'], $this->hdr($tokenMentor))
            ->assertStatus(422);

        $this->postJson(
            "/api/v1/cost-avoidance/{$id}/verifikasi",
            ['keputusan' => 'tolak', 'catatan' => 'Bukti belum lengkap.'],
            $this->hdr($tokenMentor)
        )
            ->assertOk()
            ->assertJsonPath('data.status', 'ditolak')
            ->assertJsonPath('data.catatan_verifikasi', 'Bukti belum lengkap.');
    }

    public function test_karyawan_tidak_boleh_memverifikasi(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $budi = $this->pengguna('karyawan', 'Budi');
        $tokenAndi = $this->masuk($andi);
        $tokenBudi = $this->masuk($budi);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))->json('data.id');
        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($tokenAndi))->assertOk();

        $this->postJson("/api/v1/cost-avoidance/{$id}/verifikasi", ['keputusan' => 'setujui'], $this->hdr($tokenBudi))
            ->assertForbidden();
    }

    public function test_ubah_data_mengembalikan_status_ke_draft(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $mentor = $this->pengguna('mentor', 'Mentor Satu');
        $tokenAndi = $this->masuk($andi);
        $tokenMentor = $this->masuk($mentor);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))->json('data.id');
        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($tokenAndi))->assertOk();
        $this->postJson(
            "/api/v1/cost-avoidance/{$id}/verifikasi",
            ['keputusan' => 'tolak', 'catatan' => 'Perbaiki periode.'],
            $this->hdr($tokenMentor)
        )->assertOk();

        $this->putJson("/api/v1/cost-avoidance/{$id}", $this->muatan([
            'judul' => 'Sudah diperbaiki',
            'estimasi' => 3000000,
            'realisasi' => 1000000,
        ]), $this->hdr($tokenAndi))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.judul', 'Sudah diperbaiki')
            ->assertJsonPath('data.nilai_avoidance', 2000000)
            ->assertJsonPath('data.catatan_verifikasi', null)
            ->assertJsonPath('data.verifikator', null);
    }

    public function test_ubah_ditolak_setelah_disetujui(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $mentor = $this->pengguna('mentor', 'Mentor Satu');
        $tokenAndi = $this->masuk($andi);
        $tokenMentor = $this->masuk($mentor);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))->json('data.id');
        $this->postJson("/api/v1/cost-avoidance/{$id}/ajukan", [], $this->hdr($tokenAndi))->assertOk();
        $this->postJson(
            "/api/v1/cost-avoidance/{$id}/verifikasi",
            ['keputusan' => 'setujui'],
            $this->hdr($tokenMentor)
        )->assertOk();

        $this->putJson("/api/v1/cost-avoidance/{$id}", $this->muatan(), $this->hdr($tokenAndi))->assertForbidden();
        $this->deleteJson("/api/v1/cost-avoidance/{$id}", [], $this->hdr($tokenAndi))->assertForbidden();
    }

    public function test_hapus_hanya_untuk_draft_milik_sendiri(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');
        $budi = $this->pengguna('karyawan', 'Budi');
        $tokenAndi = $this->masuk($andi);
        $tokenBudi = $this->masuk($budi);

        $id = (int) $this->postJson('/api/v1/cost-avoidance', $this->muatan(), $this->hdr($tokenAndi))->json('data.id');

        $this->deleteJson("/api/v1/cost-avoidance/{$id}", [], $this->hdr($tokenBudi))->assertForbidden();
        $this->deleteJson("/api/v1/cost-avoidance/{$id}", [], $this->hdr($tokenAndi))->assertOk();

        $this->assertSame(0, CostAvoidance::count());
    }

    public function test_ringkasan_menghitung_hanya_yang_disetujui(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');

        foreach ([
            ['CA-2026-0001', 'draft', 100000],
            ['CA-2026-0002', 'diajukan', 200000],
            ['CA-2026-0003', 'disetujui', 300000],
            ['CA-2026-0004', 'disetujui', 500000],
            ['CA-2026-0005', 'ditolak', 700000],
        ] as [$kode, $status, $nilai]) {
            CostAvoidance::create([
                'kode' => $kode, 'judul' => 'Data ' . $kode, 'kategori' => 'Lainnya',
                'periode' => '2026-08-01', 'estimasi' => $nilai, 'realisasi' => 0,
                'nilai_avoidance' => $nilai, 'status' => $status, 'dibuat_oleh' => $andi->id,
            ]);
        }

        $this->getJson('/api/v1/cost-avoidance/ringkasan', $this->hdr($this->masuk($andi)))
            ->assertOk()
            ->assertJsonPath('ringkasan.total_disetujui', 800000)
            ->assertJsonPath('ringkasan.jumlah.draft', 1)
            ->assertJsonPath('ringkasan.jumlah.diajukan', 1)
            ->assertJsonPath('ringkasan.jumlah.disetujui', 2)
            ->assertJsonPath('ringkasan.jumlah.ditolak', 1)
            ->assertJsonPath('ringkasan.ada', true)
            ->assertJsonPath('ringkasan.per_kategori.0.nilai', 800000);
    }

    public function test_ringkasan_masuk_ke_dasbor(): void
    {
        $andi = $this->pengguna('karyawan', 'Andi');

        CostAvoidance::create([
            'kode' => 'CA-2026-0009', 'judul' => 'Disetujui', 'kategori' => 'Lainnya',
            'periode' => '2026-08-01', 'estimasi' => 400000, 'realisasi' => 0,
            'nilai_avoidance' => 400000, 'status' => 'disetujui', 'dibuat_oleh' => $andi->id,
        ]);

        $this->getJson('/api/v1/ringkasan', $this->hdr($this->masuk($andi)))
            ->assertOk()
            ->assertJsonPath('ringkasan.cost_avoidance.total_disetujui', 400000)
            ->assertJsonPath('ringkasan.cost_avoidance.disetujui', 1)
            ->assertJsonPath('ringkasan.cost_avoidance.diajukan', 0);
    }

    public function test_tanpa_login_ditolak(): void
    {
        $this->getJson('/api/v1/cost-avoidance')->assertUnauthorized();
        $this->postJson('/api/v1/cost-avoidance', $this->muatan())->assertUnauthorized();
    }
}
