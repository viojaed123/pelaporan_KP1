<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\CekPeran;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Uji penjaga peran (porting wajib_role() dari includes/auth.php).
 *
 * Uji ini SENGAJA tidak memakai database: middleware hanya membaca atribut
 * role pada objek User yang sudah terautentikasi, sehingga cukup dibuat di
 * memori. Dengan begitu test tidak bergantung pada migrasi tabel legacy
 * dan tetap aman dijalankan kapan pun.
 */
class CekPeranTest extends TestCase
{
    /**
     * Pasang rute terlindung penjaga peran sekali per pengujian.
     *
     * Rute ini meniru halaman sistem lama yang memanggil
     * wajib_role(['mentor','admin']) di puncak berkasnya.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', CekPeran::class . ':mentor,admin'])
            ->get('/uji/terlindung-peran', fn () => response('OK ditampilkan'));
    }

    /**
     * Bangun pengguna di memori tanpa menyentuh database.
     *
     * Properti diset LANGSUNG, bukan lewat konstruktor array. Model User
     * sengaja tidak punya $fillable (agar mass-assignment diblokir bawaan),
     * sehingga new User(['role' => ...]) akan melempar MassAssignmentException.
     * Pemilihan langsung tepat di sini karena yang diuji adalah middleware,
     * bukan pembuatan model.
     */
    private function penggunaDenganPeran(string $peran): User
    {
        $user = new User();
        $user->role = $peran;
        $user->id = 1;
        $user->nama = 'Pengguna Uji';

        return $user;
    }

    public function test_admin_dapat_mengakses(): void
    {
        $this->actingAs($this->penggunaDenganPeran('admin'));

        $this->get('/uji/terlindung-peran')
            ->assertOk()
            ->assertSee('OK ditampilkan');
    }

    public function test_mentor_dapat_mengakses(): void
    {
        $this->actingAs($this->penggunaDenganPeran('mentor'));

        $this->get('/uji/terlindung-peran')->assertOk();
    }

    public function test_karyawan_ditolak_dengan_403(): void
    {
        $this->actingAs($this->penggunaDenganPeran('karyawan'));

        $this->get('/uji/terlindung-peran')
            ->assertForbidden()
            ->assertSee('Anda tidak memiliki akses ke halaman ini.');
    }

    public function test_peran_tidak_dikenal_ditolak(): void
    {
        /* Nilai enum di luar admin/karyawan/mentor tidak boleh lolos. */
        $this->actingAs($this->penggunaDenganPeran('superuser'));

        $this->get('/uji/terlindung-peran')->assertForbidden();
    }

    public function test_pengguna_belum_masuk_dialihkan_ke_login(): void
    {
        /* Tidak ada Auth::login() -- middleware harus mengalihkan, bukan 403. */
        $this->get('/uji/terlindung-peran')
            ->assertRedirect(route('login'));
    }

    public function test_daftar_peran_dengan_spasi_tetap_bekerja(): void
    {
        /*
         * Sistem lama memanggil wajib_role(['mentor','admin']) tanpa spasi,
         * tetapi penulisan 'role:mentor, admin' (dengan spati) seharusnya
         * tetap diterima. Middleware memangkas spasi berlebih.
         */
        Route::middleware(['web', CekPeran::class . ':mentor, admin'])
            ->get('/uji/terlindung-peran-spasi', fn () => response('OK spasi'));

        $this->actingAs($this->penggunaDenganPeran('mentor'));

        $this->get('/uji/terlindung-peran-spasi')->assertOk();
    }
}
