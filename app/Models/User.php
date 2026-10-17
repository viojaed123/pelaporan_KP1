<?php

declare(strict_types=1);

/* PENANDA_TULIS_TANGAN
 *
 * Berkas ini sengaja TIDAK dihasilkan scripts/buat_model.php, dan tidak akan
 * ditimpa bila generator dijalankan ulang. Alasannya: kelas User harus
 * mewarisi Authenticatable (bukan Model) agar kerangka autentikasi Laravel
 * -- hash kata sandi, sesi, guard, "ingat saya" -- bekerja sebagaimana
 * diharapkan.
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Model pengguna sistem pelaporan.
 *
 * PENTING -- dua ketidakteraturan warisan sistem lama yang harus diketahui:
 *
 *   1. Tabel ini TIDAK punya kolom updated_at, hanya created_at.
 *      Karena itu UPDATED_AT diset null: Laravel tidak akan mencoba
 *      menulis kolom yang tidak ada.
 *
 *   2. Sebelum migrasi 2026_10_18_000001, tabel ini TIDAK punya
 *      remember_token. Kolom itu ditambahkan agar fitur "ingat saya"
 *      bawaan Laravel berfungsi. Implementasi lama tetap hidup di tabel
 *      user_security (ingat_token_hash / ingat_kedaluwarsa / ingat_ip)
 *      dan keduanya tidak saling menimpa.
 *
 * Nilai enum role/status dikunci di level database:
 *   role   : admin | karyawan | mentor
 *   status : aktif | menunggu | nonaktif
 *
 * CATATAN: trait Illuminate\Notifications\Notifiable SENGAJA tidak dipakai.
 *   Trait itu menambah relasi notifications() yang menunjuk tabel
 *   "notifications" (skema bawaan Laravel). Tabel itu tidak ada di database
 *   ini -- sistem sudah memiliki tabel "notifikasi" sendiri dengan semantik
 *   berbeda (link, laporan_id, dsb). Memakai Notifiable akan membuat
 *   relasi yang error saat dipanggil. Bila kelak dibutuhkan, buat dulu
 *   migrasi tabel notifications.
 */
class User extends Authenticatable
{
    use HasFactory;

    /** Nama tabel eksplisit (bawaan Laravel akan mencari "users" -- cocok, tapi
     *  ditulis terang-terangan agar niatnya jelas saat membaca). */
    protected $table = 'users';

    /**
     * Kolom pembuat memakai nama bawaan (created_at), tetapi kolom pembaruan
     * TIDAK ADA di tabel ini. Tanpa konstanta ini Laravel akan error
     * "Unknown column 'updated_at'" pada setiap penyimpanan.
     */
    public const UPDATED_AT = null;

    /**
     * Atribut yang disembunyikan ketika model di-serialisasi (JSON/API).
     *
     * Kata sandi wajib disembunyikan: tanpa baris ini hash kata sandi akan
     * ikut terkirim bila model dipakai dalam response JSON.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Atribut yang selalu dikonversi ke tipe PHP tertentu.
     *
     * Role/status dibiarkan string agar cocok dengan enum database, tetapi
     * email_verified_at harus Carbon agar bisa dibandingkan tanggalnya.
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
        ];
    }

    /* =================================================================
     * RELASI
     *
     * Enam belas tabel memiliki kunci asing user_id yang menunjuk ke
     * users.id. Semua didefinisikan di sini agar modul yang diport tidak
     * perlu menulis query manual.
     * =============================================================== */

    /** Detail keamanan milik pengguna ini (2FA, "ingat saya" lama, kunci akun). */
    public function security(): BelongsTo
    {
        return $this->belongsTo(UserSecurity::class, 'id', 'user_id');
    }

    /** Absensi kehadiran pengguna. */
    public function absensi(): HasMany
    {
        return $this->hasMany(Absensi::class, 'user_id');
    }

    /** Laporan kegiatan harian yang dibuat pengguna. */
    public function laporanHarian(): HasMany
    {
        return $this->hasMany(LaporanHarian::class, 'user_id');
    }

    /** Jadwal tugas pengguna. */
    public function jadwal(): HasMany
    {
        return $this->hasMany(Jadwal::class, 'user_id');
    }

    /** Kasus yang menjadi tanggung jawab pengguna (sebagai penanggung jawab). */
    public function kasusDitugaskan(): HasMany
    {
        return $this->hasMany(Kasus::class, 'penanggung_jawab_id');
    }

    /** Penugasan kasus yang melibatkan pengguna. */
    public function kasusPenugasan(): HasMany
    {
        return $this->hasMany(KasusPenugasan::class, 'user_id');
    }

    /** Log perubahan status kasus yang dicatat oleh pengguna. */
    public function kasusStatusLog(): HasMany
    {
        return $this->hasMany(KasusStatusLog::class, 'user_id');
    }

    /** Riwayat tindak lanjut kasus yang dilakukan pengguna. */
    public function riwayatTindakLanjut(): HasMany
    {
        return $this->hasMany(RiwayatTindakLanjut::class, 'user_id');
    }

    /** Komentar pengguna pada laporan. */
    public function komentar(): HasMany
    {
        return $this->hasMany(KomentarLaporan::class, 'user_id');
    }

    /** Notifikasi yang ditujukan kepada pengguna. */
    public function notifikasi(): HasMany
    {
        return $this->hasMany(Notifikasi::class, 'user_id');
    }

    /** Pengingat yang dijadwalkan untuk pengguna. */
    public function pengingat(): HasMany
    {
        return $this->hasMany(Pengingat::class, 'user_id');
    }

    /** Sesi percakapan pengguna dengan layanan AI. */
    public function chatSessions(): HasMany
    {
        return $this->hasMany(ChatSession::class, 'user_id');
    }

    /** Riwayat kata sandi (mencegah pengulangan kata sandi). */
    public function passwordHistory(): HasMany
    {
        return $this->hasMany(PasswordHistory::class, 'user_id');
    }

    /** Upaya masuk yang tercatat, termasuk yang gagal. */
    public function loginAttempts(): HasMany
    {
        return $this->hasMany(LoginAttempt::class, 'user_id');
    }

    /** Token tambahan milik pengguna (API / sesi panjang). */
    public function userTokens(): HasMany
    {
        return $this->hasMany(UserToken::class, 'user_id');
    }

    /** Kode cadangan untuk pemulihan akun (2FA backup). */
    public function backupCodes(): HasMany
    {
        return $this->hasMany(BackupCode::class, 'user_id');
    }

    /** OTP yang pernah dikirim kepada pengguna. */
    public function otp(): HasMany
    {
        return $this->hasMany(UserOtp::class, 'user_id');
    }

    /** Pekerjaan AI yang dijalankan atas nama pengguna. */
    public function aiJobs(): HasMany
    {
        return $this->hasMany(AiJob::class, 'user_id');
    }

    /** Riwayat impor profil (migrasi data legacy). */
    public function imporProfil(): HasMany
    {
        return $this->hasMany(ImporProfil::class, 'user_id');
    }

    /** Riwayat impor data. */
    public function imporSejarah(): HasMany
    {
        return $this->hasMany(ImporSejarah::class, 'user_id');
    }

    /** Sesi aktif pengguna (pelacakan sesi login). */
    public function sesiAktif(): HasMany
    {
        return $this->hasMany(SesiAktif::class, 'user_id');
    }

    /* =================================================================
     * PENOLONG
     * =============================================================== */

    /**
     * Benar bila pengguna berstatus aktif.
     *
     * Dipakai penjaga akses: pengguna nonaktif/menunggu tidak boleh masuk
     * meski kata sandinya benar. Sistem lama memeriksa ini di login.php.
     */
    public function aktif(): bool
    {
        return $this->status === 'aktif';
    }

    /**
     * Benar bila pengguna adalah administrator.
     *
     * Sebanding dengan pola penjaga `wajib_role('mentor','admin')` pada
     * 40 halaman sistem lama.
     */
    public function admin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Benar bila pengguna memegang salah satu peran yang diminta.
     *
     * Dipakai sebagai pengganti pola `wajib_role()` imperatif lama.
     *
     *   if (! $user->punyaPeran(['mentor', 'admin'])) { abort(403); }
     */
    public function punyaPeran(array $peran): bool
    {
        return in_array($this->role, $peran, true);
    }

    /**
     * Nama rute halaman utama sesuai peran.
     *
     * Padanan halaman_default_peran() sistem lama
     * (dashboard.php / mentor_dashboard.php / admin_dashboard.php).
     * Dipakai setelah login berhasil untuk mengarahkan pengguna ke
     * halaman yang tepat, tanpa pengalihan perantara.
     *
     * @return string Nama rute (bukan URL) agar tetap sah bila URL berubah.
     */
    public function halamanDefault(): string
    {
        return match ($this->role) {
            'admin'  => 'admin.dashboard',
            'mentor' => 'mentor.dashboard',
            default  => 'dashboard',
        };
    }

    /**
     * Benar bila verifikasi dua faktor aktif untuk pengguna ini.
     *
     * Padanan user_2fa_aktif() sistem lama. Mengembalikan false bila baris
     * user_security belum ada -- artinya pengguna belum pernah mengaktifkan
     * 2FA, bukan error. Karena itu relasi `security` dipakai apa adanya
     * (null dianggap false), bukan dengan asumsi barisnya selalu ada.
     */
    public function duaFaktorAktif(): bool
    {
        return (bool) ($this->security?->dua_faktor_aktif ?? false);
    }

    /**
     * URL relatif foto profil bila berkasnya benar-benar ada, atau null.
     *
     * Padanan foto_profil_url() sistem lama. Hanya NAMA BERKAS yang diterima:
     * nilai yang memuat pemisah path atau '..' ditolak, sehingga isi kolom
     * foto_profil (yang berasal dari unggahan) tidak bisa dipakai untuk
     * membaca berkas di luar folder avatar (path traversal).
     */
    public function fotoProfilUrl(): ?string
    {
        $foto = trim((string) ($this->foto_profil ?? ''));

        if ($foto === '' || basename($foto) !== $foto || str_contains($foto, '..')) {
            return null;
        }

        $berkas = public_path('uploads/avatar/' . $foto);

        return is_file($berkas) ? 'uploads/avatar/' . rawurlencode($foto) : null;
    }
}
