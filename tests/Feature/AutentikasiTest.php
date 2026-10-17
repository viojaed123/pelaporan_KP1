<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginLock;
use App\Models\User;
use App\Services\Otp;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SkemaAuth;
use Tests\TestCase;

/**
 * Uji alur otentikasi menyeluruh (porting login.php, dua_faktor.php,
 * logout.php, dan login_tuntaskan()).
 *
 * Uji ini memakai basis data uji di memori (sqlite) dengan skema warisan
 * yang dibuat ulang di tests/Concerns/SkemaAuth.php -- sehingga tidak pernah
 * menyentuh basis data produksi.
 */
class AutentikasiTest extends TestCase
{
    use SkemaAuth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanSkemaAuth();
    }

    /*
    |----------------------------------------------------------------------
    | Penolong
    |----------------------------------------------------------------------
    */

    /**
     * Buat pengguna. Properti diset LANGSUNG karena model User sengaja tidak
     * punya $fillable (semua mass-assignment diblokir).
     *
     * @param  array<string, mixed>  $atribut
     */
    private function buatPengguna(array $atribut = []): User
    {
        $user = new User();
        $user->nama     = $atribut['nama'] ?? 'Pengguna Uji';
        $user->email    = $atribut['email'] ?? 'uji@contoh.com';
        $user->password = $atribut['password'] ?? 'SandiRahasia123';
        $user->role     = $atribut['role'] ?? 'karyawan';
        $user->status   = $atribut['status'] ?? 'aktif';
        $user->save();

        return $user;
    }

    /**
     * Aktifkan 2FA untuk pengguna.
     *
     * Memakai query builder karena user_id adalah kunci utama user_security
     * dan (seperti semua PK) sengaja tidak ada di $fillable.
     */
    private function aktifkanDuaFaktor(User $user): void
    {
        DB::table('user_security')->insert([
            'user_id'          => $user->id,
            'dua_faktor_aktif' => true,
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Halaman & masuk
    |----------------------------------------------------------------------
    */

    public function test_halaman_login_dapat_ditampilkan(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Selamat Datang')
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);
    }

    public function test_login_berhasil_mengarahkan_ke_beranda_peran(): void
    {
        $user = $this->buatPengguna();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_pengguna_mentor_diarahkan_ke_beranda_mentor(): void
    {
        $user = $this->buatPengguna(['email' => 'mentor@contoh.com', 'role' => 'mentor']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ])->assertRedirect(route('mentor.dashboard'));
    }

    public function test_sandi_salah_ditolak_tanpa_membocorkan_akun(): void
    {
        $user = $this->buatPengguna();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiSalahSekali',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_pesan_gagal_sama_untuk_surel_terdaftar_dan_tidak(): void
    {
        $this->buatPengguna(['email' => 'ada@contoh.com']);

        /* Percobaan pertama: 1 kegagalan -- keduanya masih memakai pesan umum
           yang sama (belum ada penghitung sisa). */
        $this->post('/login', ['email' => 'ada@contoh.com', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai.']);

        $this->post('/login', ['email' => 'tidak-ada@contoh.com', 'password' => 'x'])
            ->assertSessionHasErrors(['email' => 'Email atau kata sandi tidak sesuai.']);
    }

    public function test_format_surel_tidak_valid_ditolak_validasi(): void
    {
        $this->post('/login', ['email' => 'bukan-surel', 'password' => 'x'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_akun_belum_diverifikasi_ditolak(): void
    {
        $user = $this->buatPengguna(['status' => 'menunggu']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_akun_nonaktif_ditolak(): void
    {
        $user = $this->buatPengguna(['status' => 'nonaktif']);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    /*
    |----------------------------------------------------------------------
    | Penguncian (lockout)
    |----------------------------------------------------------------------
    */

    public function test_penguncian_setelah_lima_kegagalan_beruntun(): void
    {
        $user = $this->buatPengguna();

        /*
         * Empat kegagalan pertama: pesan pada akhirnya menyebut sisa
         * percobaan, tetapi akun BELUM terkunci.
         */
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $this->assertDatabaseCount('login_locks', 2); // ip:... dan email:...

        /* Kegagalan kelima: mencapai ambang, pesan berubah menjadi terkunci. */
        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertSessionHasErrors(['email' => 'Terlalu banyak percobaan gagal. Akun sementara dikunci.']);

        /* Kegagalan keenam: sudah terkunci -- diperiksa SEBELUM sandi diuji.
           Pesannya memuat durasi yang bervariasi, jadi dicocokkan sebagian. */
        $this->post('/login', ['email' => $user->email, 'password' => 'salah'])
            ->assertSessionHasErrors('email');

        $pesanTerkunci = (string) session('errors')?->first('email');

        $this->assertStringContainsString('Coba lagi dalam', $pesanTerkunci);

        /* Kredensial yang BENAR pun ditolak selama terkunci. */
        $this->post('/login', ['email' => $user->email, 'password' => 'SandiRahasia123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_penguncian_menyimpan_kunci_untuk_ip_dan_surel(): void
    {
        $user = $this->buatPengguna();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'salah']);
        }

        $this->assertDatabaseHas('login_locks', ['kunci' => LoginLock::kunciEmail($user->email)]);
        $this->assertDatabaseHas('login_locks', ['kunci' => LoginLock::kunciIp('127.0.0.1')]);
    }

    /*
    |----------------------------------------------------------------------
    | Verifikasi dua faktor
    |----------------------------------------------------------------------
    */

    public function test_2fa_mengarahkan_ke_halaman_verifikasi_tanpa_login(): void
    {
        $user = $this->buatPengguna();
        $this->aktifkanDuaFaktor($user);

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ])->assertRedirect(route('dua.faktor'));

        /*
         * PENTING: pengguna BELUM benar-benar masuk pada tahap ini. Bila
         * assertGuest() di sini gagal, berarti 2FA hanya jadi hiasan.
         */
        $this->assertGuest();
    }

    public function test_2fa_kode_benar_menuntaskan_login(): void
    {
        $user = $this->buatPengguna();
        $this->aktifkanDuaFaktor($user);

        $kode = app(Otp::class)->buat((int) $user->id, (string) $user->email);

        $this->withSession(['tfa_uid' => $user->id, 'tfa_email' => $user->email])
            ->post(route('dua.faktor.verifikasi'), ['kode' => $kode])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_2fa_kode_salah_ditolak(): void
    {
        $user = $this->buatPengguna();
        $this->aktifkanDuaFaktor($user);

        app(Otp::class)->buat((int) $user->id, (string) $user->email);

        $this->withSession(['tfa_uid' => $user->id, 'tfa_email' => $user->email])
            ->post(route('dua.faktor.verifikasi'), ['kode' => '000000'])
            ->assertSessionHasErrors('kode');

        $this->assertGuest();
    }

    public function test_2fa_tanpa_sesi_sementara_dialihkan_ke_login(): void
    {
        $this->get(route('dua.faktor'))->assertRedirect(route('login'));
    }

    /*
    |----------------------------------------------------------------------
    | Keluar
    |----------------------------------------------------------------------
    */

    public function test_keluar_menghapus_otentikasi(): void
    {
        $user = $this->buatPengguna();

        $this->actingAs($user)
            ->post('/keluar')
            ->assertRedirect(route('login', ['alasan' => 'keluar']));

        $this->assertGuest();
    }

    /*
    |----------------------------------------------------------------------
    | Penguatan kata sandi
    |----------------------------------------------------------------------
    */

    public function test_hash_sandi_lama_ditingkatkan_saat_login(): void
    {
        /*
         * Sisipkan pengguna dengan hash BERBIAYA BERBEDA dari konfigurasi uji
         * (uji memakai biaya 4). Nilai disisipkan lewat query builder agar
         * cast 'hashed' pada model TIDAK ikut meng-hash ulang.
         */
        $hashLama = password_hash('SandiRahasia123', PASSWORD_BCRYPT, ['cost' => 10]);

        DB::table('users')->insert([
            'nama'       => 'Pengguna Lama',
            'email'      => 'lama@contoh.com',
            'password'   => $hashLama,
            'role'       => 'karyawan',
            'status'     => 'aktif',
            'created_at' => now(),
        ]);

        $this->post('/login', [
            'email'    => 'lama@contoh.com',
            'password' => 'SandiRahasia123',
        ])->assertRedirect(route('dashboard'));

        $hashBaru = (string) DB::table('users')->where('email', 'lama@contoh.com')->value('password');

        $this->assertNotSame($hashLama, $hashBaru, 'Hash lama seharusnya ditingkatkan saat login.');
        $this->assertFalse(Hash::needsRehash($hashBaru), 'Hash baru seharusnya sudah sesuai konfigurasi.');
    }

    public function test_audit_tercatat_saat_login_berhasil(): void
    {
        $user = $this->buatPengguna();

        $this->post('/login', [
            'email'    => $user->email,
            'password' => 'SandiRahasia123',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'user_id' => $user->id,
            'aksi'    => 'login',
        ]);
    }

    /*
    |----------------------------------------------------------------------
    | Penjaga halaman terlindung
    |----------------------------------------------------------------------
    */

    public function test_tamu_di_beranda_dialihkan_ke_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }
}
