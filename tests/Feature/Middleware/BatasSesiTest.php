<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Http\Middleware\BatasSesi;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Uji batas waktu sesi (porting includes/session.php).
 *
 * Dua aturan diuji, persis nilai config.php lama:
 *   idle     : SESI_IDLE_DETIK  = 1800 detik (30 menit)
 *   absolut  : SESI_ABSOLUT_DETIK = 28800 detik (8 jam)
 *
 * Middleware ini membaca penanda _sesi_mulai/_sesi_terakhir dari sesi, sehingga
 * pengujian menyetelnya lewat withSession() dan memanipulasi waktu dengan
 * menaruh nilai di masa lalu. Tidak diperlukan database.
 */
class BatasSesiTest extends TestCase
{
    /** Nilai .env / config keamanan yang dipakai middleware. */
    private int $batasIdle;

    private int $batasAbsolut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->batasIdle    = (int) config('keamanan.sesi_idle_detik');
        $this->batasAbsolut = (int) config('keamanan.sesi_absolut_detik');

        /* Rute terautentikasi. 'auth' memastikan Auth::check() benar, lalu
         * BatasSesi (dalam grup web) memeriksa batas waktu. */
        Route::middleware(['web', 'auth'])
            ->get('/uji/terlindung-sesi', fn () => response('OK sesi'));
    }

    private function pengguna(): User
    {
        /* Properti diset langsung (bukan lewat konstruktor array) karena
         * model User sengaja tanpa $fillable -- lihat catatan di
         * CekPeranTest::penggunaDenganPeran(). */
        $user = new User();
        $user->role = 'karyawan';
        $user->id = 1;

        return $user;
    }

    /* ---------------------------------------------------------------- */
    /* Sesi masih sah                                                   */
    /* ---------------------------------------------------------------- */

    public function test_sesi_baru_tidak_dikeluarkan(): void
    {
        /* Tidak menyetel penanda sama sekali: middleware menganggap baru. */
        $this->actingAs($this->pengguna())
            ->get('/uji/terlindung-sesi')
            ->assertOk()
            ->assertSee('OK sesi');
    }

    public function test_sesi_aktif_tetap_dilayani(): void
    {
        /* Aktivitas terakhir 10 menit lalu -- masih di bawah batas idle. */
        $this->actingAs($this->pengguna())
            ->withSession([
                '_sesi_mulai'    => time() - 3600,   // login 1 jam lalu
                '_sesi_terakhir' => time() - 600,    // aktif 10 menit lalu
            ])
            ->get('/uji/terlindung-sesi')
            ->assertOk();
    }

    /* ---------------------------------------------------------------- */
    /* Idle timeout                                                     */
    /* ---------------------------------------------------------------- */

    public function test_sesi_dikeluarkan_setelah_diam_melebihi_batas(): void
    {
        /* Diam (idle) selama batasIdle + 60 detik, tetapi usia total masih
         * di bawah batas absolut -- ini menguji aturan IDLE secara khusus. */
        $idleTerlaluLama = $this->batasIdle + 60;

        $this->actingAs($this->pengguna())
            ->withSession([
                '_sesi_mulai'    => time() - ($idleTerlaluLama + 600),
                '_sesi_terakhir' => time() - $idleTerlaluLama,
            ])
            ->get('/uji/terlindung-sesi')
            ->assertRedirect(route('login', ['alasan' => BatasSesi::ALASAN_SESI_HABIS]));
    }

    /* ---------------------------------------------------------------- */
    /* Usia mutlak                                                      */
    /* ---------------------------------------------------------------- */

    public function test_sesi_dikeluarkan_setelah_usia_mutlak_terlampaui(): void
    {
        /*
         * Usia total melebihi batas absolut, TETAPI aktivitas terakhir sangat
         * baru (detik ini). Ini menguji bahwa aturan absolut bekerja secara
         * mandiri -- tanpa ini, pengguna yang terus aktif bisa menahan sesi
         * terbuka tanpa batas.
         */
        $usiaTerlaluLama = $this->batasAbsolut + 600;

        $this->actingAs($this->pengguna())
            ->withSession([
                '_sesi_mulai'    => time() - $usiaTerlaluLama,
                '_sesi_terakhir' => time(),          // baru saja aktif
            ])
            ->get('/uji/terlindung-sesi')
            ->assertRedirect(route('login', ['alasan' => BatasSesi::ALASAN_SESI_HABIS]));
    }

    /* ---------------------------------------------------------------- */
    /* Batas tepat di ambang                                            */
    /* ---------------------------------------------------------------- */

    public function test_sesi_tepat_pada_batas_idle_belum_dikeluarkan(): void
    {
        /*
         * Persis pada batas (==) seharusnya MASIH dilayani: aturan lama memakai
         * pembanding KETAT (idle > SESI_IDLE_DETIK), sehingga nilai tepat di
         * ambang belum dianggap melebihi. Mengunci agar penambahan operator
         * >= tidak mengubah perilaku diam-diam.
         */
        $this->actingAs($this->pengguna())
            ->withSession([
                '_sesi_mulai'    => time() - ($this->batasIdle + 10),
                '_sesi_terakhir' => time() - $this->batasIdle,   // tepat == batas
            ])
            ->get('/uji/terlindung-sesi')
            ->assertOk();
    }

    /* ---------------------------------------------------------------- */
    /* Halaman publik tidak terpengaruh                                 */
    /* ---------------------------------------------------------------- */

    public function test_rute_publik_tanpa_autentikasi_tidak_dikeluarkan(): void
    {
        /*
         * Middleware harus melewatkan pengguna yang belum masuk tanpa
         * mengalihkan -- halaman publik (login, lupa sandi) tidak boleh
         * terkena pengalihan sesi habis.
         */
        Route::middleware(['web'])
            ->get('/uji/publik', fn () => response('OK publik'));

        $this->withSession([
            '_sesi_mulai'    => time() - ($this->batasAbsolut + 9999),
            '_sesi_terakhir' => time() - ($this->batasIdle + 9999),
        ])
            ->get('/uji/publik')
            ->assertOk()
            ->assertSee('OK publik');
    }
}
