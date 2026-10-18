<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Kasus;
use App\Models\KasusPenugasan;
use App\Models\Notifikasi;
use App\Models\User;
use App\Services\CocokPengguna;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji modul kasus PIC/anggota TEKS BEBAS (Fase C+D).
 *
 * Sasaran:
 *   - pembuat kasus MENGETIK bebas nama PIC & anggota (tanpa dropdown);
 *   - nama yang cocok pengguna terdaftar otomatis memperoleh user_id
 *     (notifikasi & hak akses jalan); yang tidak cocok tetap teks murni;
 *   - pencocokan nama berawalan HANYA diterima bila unik (tidak boleh
 *     pernah menugaskan orang yang salah);
 *   - visibilitas & sinkronisasi anggota mengikuti aturan legacy.
 */
class KasusTeksTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanSkemaAuth();

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

        Schema::create('kasus_status_log', function (Blueprint $t): void {
            $t->increments('id');
            $t->unsignedInteger('kasus_id');
            $t->unsignedInteger('user_id')->nullable();
            $t->string('status_lama')->nullable();
            $t->string('status_baru')->nullable();
            $t->integer('progres')->nullable();
            $t->text('catatan')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
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

    private function buatPengguna(string $nama, string $email, string $peran = 'karyawan'): User
    {
        $u = new User();
        $u->nama = $nama;
        $u->email = $email;
        $u->password = 'SandiRahasia123!';
        $u->role = $peran;
        $u->status = 'aktif';
        $u->save();

        return $u;
    }

    /* =================================================================
     * PENCOCOKAN (service)
     * =============================================================== */

    public function test_cocok_nama_persis_dan_surel(): void
    {
        $budi = $this->buatPengguna('Budi Santoso', 'budi@contoh.com');
        $cocok = app(CocokPengguna::class);

        $this->assertSame($budi->id, $cocok->cocok('budi santoso')['user_id']);
        $this->assertSame($budi->id, $cocok->cocok('BUDI  SANTOSO')['user_id']);
        $this->assertSame($budi->id, $cocok->cocok('budi@contoh.com')['user_id']);
    }

    public function test_cocok_awalan_hanya_bila_unik(): void
    {
        $satu = $this->buatPengguna('Sri Wahyuni', 'sri@contoh.com');
        $this->buatPengguna('Sri Lestari', 'lestari@contoh.com');
        $cocok = app(CocokPengguna::class);

        /* "Sri" → dua kandidat → ambigu → TIDAK dicocokkan. */
        $this->assertNull($cocok->cocok('Sri')['user_id']);

        /* "Sri W" → unik → cocok. */
        $this->assertSame($satu->id, $cocok->cocok('Sri W')['user_id']);
    }

    public function test_cocok_banyak_memecah_baris_menghindari_duplikat(): void
    {
        $budi = $this->buatPengguna('Budi', 'budi@contoh.com');
        $cocok = app(CocokPengguna::class);

        $hasil = $cocok->cocokBanyak("Budi\nbudi@contoh.com\nTidak Dikenal\n\n");

        $this->assertCount(2, $hasil);           // Budi dua kali → satu baris
        $this->assertSame($budi->id, $hasil[0]['user_id']);
        $this->assertNull($hasil[1]['user_id']); // "Tidak Dikenal" → teks murni
        $this->assertSame('Tidak Dikenal', $hasil[1]['teks']);
    }

    /* =================================================================
     * SIMPAN KASUS (form teks bebas)
     * =============================================================== */

    public function test_simpan_kasus_mencocokkan_pic_dan_anggota_teks_bebas(): void
    {
        $saya   = $this->buatPengguna('Pembuat Kasus', 'pembuat@contoh.com', 'mentor');
        $pic    = $this->buatPengguna('Andi Wijaya', 'andi@contoh.com');
        $anggota = $this->buatPengguna('Sari Indah', 'sari@contoh.com');

        $this->actingAs($saya)->post(route('kasus.simpan'), [
            'judul'            => 'Banjir permukiman',
            'tingkat_urgensi'  => 'Tinggi',
            'status_kasus'     => 'Terbuka',
            'progres'          => 0,
            'penanggung_jawab' => 'andi wijaya',           // ketik bebas, huruf kecil
            'anggota'          => "Sari Indah\nOrang Asing", // ketik bebas, multi-baris
        ])->assertRedirect(route('kasus.index'));

        $kasus = Kasus::first();
        $this->assertNotNull($kasus);
        $this->assertStringStartsWith('KAS-', (string) $kasus->kode_kasus);

        /* PIC tercocok → user_id terisi, teks tetap tersimpan. */
        $this->assertSame($pic->id, $kasus->penanggung_jawab_id);
        $this->assertSame('andi wijaya', $kasus->penanggung_jawab_teks);

        /* Anggota: Sari tercocok (user_id + teks); Orang Asing teks murni. */
        $sari = KasusPenugasan::where('nama_teks', 'Sari Indah')->first();
        $this->assertSame($anggota->id, $sari->user_id);

        $asing = KasusPenugasan::where('nama_teks', 'Orang Asing')->first();
        $this->assertNotNull($asing);
        $this->assertNull($asing->user_id);

        /* Notifikasi terkirim ke pihak terlibat (PIC & anggota tercocok),
           kecuali pembuat sendiri. */
        $this->assertSame(1, Notifikasi::where('user_id', $pic->id)->count());
        $this->assertSame(1, Notifikasi::where('user_id', $anggota->id)->count());
        $this->assertSame(0, Notifikasi::where('user_id', $saya->id)->count());

        /* Log status awal tercatat. */
        $this->assertSame(1, \App\Models\KasusStatusLog::count());
    }

    public function test_pic_yang_tidak_dikenal_tetap_tersimpan_sebagai_teks(): void
    {
        $saya = $this->buatPengguna('Pembuat', 'p@contoh.com', 'mentor');

        $this->actingAs($saya)->post(route('kasus.simpan'), [
            'judul'            => 'Kasus tanpa PIC terdaftar',
            'tingkat_urgensi'  => 'Rendah',
            'status_kasus'     => 'Terbuka',
            'progres'          => 10,
            'penanggung_jawab' => 'Kepala Desa Fiktif',
        ])->assertRedirect(route('kasus.index'));

        $kasus = Kasus::first();
        $this->assertNull($kasus->penanggung_jawab_id);
        $this->assertSame('Kepala Desa Fiktif', $kasus->penanggung_jawab_teks);
    }

    public function test_edit_menyesuaikan_anggota_yang_dihapus_dari_ketikan(): void
    {
        $saya  = $this->buatPengguna('Mentor Edit', 'edit@contoh.com', 'mentor');
        $andi  = $this->buatPengguna('Andi', 'andi2@contoh.com');
        $sari  = $this->buatPengguna('Sari', 'sari2@contoh.com');

        $this->actingAs($saya)->post(route('kasus.simpan'), [
            'judul' => 'Kasus sinkron tim', 'tingkat_urgensi' => 'Sedang',
            'status_kasus' => 'Terbuka', 'progres' => 0,
            'anggota' => "Andi\nSari",
        ]);
        $kasus = Kasus::first();
        $this->assertSame(2, $kasus->anggota()->count());

        /* Edit: "Sari" dihapus dari ketikan, "Andi" tetap + satu baru. */
        $this->actingAs($saya)->post(route('kasus.perbarui', $kasus->id), [
            'judul' => 'Kasus sinkron tim', 'tingkat_urgensi' => 'Sedang',
            'status_kasus' => 'Dalam Proses', 'progres' => 40,
            'anggota' => "Andi\nBudi Baru",
        ])->assertRedirect(route('kasus.detail', $kasus->id));

        $this->assertSame(2, $kasus->anggota()->count());
        $this->assertNull(KasusPenugasan::where('nama_teks', 'Sari')->first());
        $this->assertNotNull(KasusPenugasan::where('nama_teks', 'Budi Baru')->first());

        /* Perubahan status tercatat di log. */
        $log = \App\Models\KasusStatusLog::where('kasus_id', $kasus->id)->orderByDesc('id')->first();
        $this->assertSame('Dalam Proses', $log->status_baru);
        $this->assertSame(40, (int) $log->progres);
    }

    /* =================================================================
     * VISIBILITAS
     * =============================================================== */

    public function test_karyawan_hanya_melihat_kasus_yang_melibatkannya(): void
    {
        $saya  = $this->buatPengguna('Karyawan Saya', 'saya@contoh.com');
        $mentor = $this->buatPengguna('Mentor', 'mentor@contoh.com', 'mentor');
        $this->actingAs($saya)->post(route('kasus.simpan'), [
            'judul' => 'Kasus saya', 'tingkat_urgensi' => 'Rendah',
            'status_kasus' => 'Terbuka', 'progres' => 0,
        ]);
        $kasus = Kasus::first();

        /* Kasus lain yang tidak melibatkan saya. */
        $asing = Kasus::create([
            'kode_kasus' => 'KAS-ASING', 'judul' => 'Kasus orang lain',
            'tingkat_urgensi' => 'Rendah', 'status_kasus' => 'Terbuka',
            'dibuat_oleh' => 999, 'penanggung_jawab_id' => 999,
        ]);

        /* Saya hanya melihat kasus saya. */
        $this->actingAs($saya)->get(route('kasus.index'))
            ->assertOk()
            ->assertSee('Kasus saya')
            ->assertDontSee('Kasus orang lain');

        /* Akses langsung ke kasus asing ditolak 403. */
        $this->actingAs($saya)->get(route('kasus.detail', $asing->id))
            ->assertStatus(403);

        /* Mentor melihat semua. */
        $this->actingAs($mentor)->get(route('kasus.index'))
            ->assertOk()
            ->assertSee('Kasus orang lain');
    }

    public function test_form_menampilkan_isian_teks_bebas_bukan_dropdown(): void
    {
        $saya = $this->buatPengguna('Form', 'form@contoh.com', 'mentor');

        $this->actingAs($saya)->get(route('kasus.buat'))
            ->assertOk()
            ->assertSee('name="penanggung_jawab"', false)
            ->assertSee('name="anggota"', false)
            ->assertDontSee('name="penanggung_jawab_id"', false)
            ->assertDontSee('name="anggota[]"', false);
    }

    /* =================================================================
     * API (dipakai SPA React & APK mobile)
     * =============================================================== */

    private function tokenApi(User $u): string
    {
        return $u->createToken('uji')->plainTextToken;
    }

    public function test_api_mentor_membuat_kasus_teks_bebas(): void
    {
        $mentor = $this->buatPengguna('API Mentor', 'apimentor@contoh.com', 'mentor');
        $pic    = $this->buatPengguna('PIC Satu', 'pic1@contoh.com');

        $this->withToken($this->tokenApi($mentor))->postJson('/api/v1/kasus', [
            'judul'            => 'Kasus lewat API',
            'tingkat_urgensi'  => 'Tinggi',
            'status_kasus'     => 'Terbuka',
            'progres'          => 0,
            'penanggung_jawab' => 'pic satu',
            'anggota'          => "PIC Satu\nWarga Non Sistem",
        ])->assertStatus(201)->assertJsonPath('sukses', true);

        $kasus = Kasus::first();
        $this->assertSame($pic->id, $kasus->penanggung_jawab_id);
        $this->assertSame(2, $kasus->anggota()->count());
        $this->assertNull(KasusPenugasan::where('nama_teks', 'Warga Non Sistem')->first()->user_id);
    }

    public function test_api_karyawan_ditolak_membuat_kasus(): void
    {
        $karyawan = $this->buatPengguna('Kary Api', 'karyapi@contoh.com');

        $this->withToken($this->tokenApi($karyawan))->postJson('/api/v1/kasus', [
            'judul' => 'Tidak boleh', 'tingkat_urgensi' => 'Rendah',
            'status_kasus' => 'Terbuka', 'progres' => 0,
        ])->assertStatus(403);
    }
}
