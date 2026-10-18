<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `kasus_penugasan.user_id` harus boleh NULL (anggota ketikan yang tidak
 * cocok dengan pengguna terdaftar).
 *
 * Konteks: migrasi 2026_10_19_090000 menambah `kasus_penugasan.nama_teks`
 * dengan keterangan "user_id diisi bila cocok", tetapi kolom `user_id`
 * sendiri dibiarkan NOT NULL. Akibatnya INSERT anggota yang TIDAK terdaftar
 * (user_id = NULL) ditolak MySQL dengan pelanggaran integritas sehingga
 * nama tersebut hilang sama sekali -- baik lewat formulir legacy
 * `kasus_sinkron_tim_teks()` maupun lewat API `POST/PUT /api/v1/kasus`
 * (SPA React & APK Android). Skema uji PHPUnit memang sudah nullable,
 * sehingga bug ini tidak tertangkap uji otomatis dan baru terbukti pada
 * uji E2E terhadap basis data produksi.
 *
 * Perubahan bersifat PELEBARAN (menambah yang NULL diizinkan) dan tidak
 * menyentuh baris mana pun: aman dijalankan pada produksi 44 tabel,
 * tanpa `migrate:fresh`. Skema uji Laravel tidak menjalankan migration
 * (memakai SkemaAuth), jadi berkas ini murni untuk basis data MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kasus_penugasan', function (Blueprint $table): void {
            // int(11) signed -- hanya nullability yang diubah, tipe & tanda
            // dipertahankan persis seperti definisi kolom yang sudah ada.
            $table->integer('user_id')->nullable()->change();
        });
    }

    /**
     * Tidak dibalik: mengembalikan NOT NULL akan menggagalkan migrasi bila
     * sudah ada anggota berupa teks murni (user_id NULL) di produksi.
     */
    public function down(): void
    {
        // Tidak dapat dibatalkan secara otomatis.
    }
};
