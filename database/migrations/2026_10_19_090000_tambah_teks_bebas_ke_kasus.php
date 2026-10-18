<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIC & anggota kasus berupa TEKS BEBAS (bukan dropdown pilihan).
 *
 * Keputusan desain: pembuat kasus MENGETIK nama PIC dan anggota tim
 * secara bebas (satu nama per baris untuk anggota), sehingga fleksibel
 * -- tidak terbatas pada daftar pengguna terdaftar. Untuk menjaga
 * notifikasi & hak akses tetap berfungsi, setiap nama yang COCOK dengan
 * pengguna terdaftar diisi `user_id`-nya secara otomatis (pencocokan
 * case-insensitive terhadap nama/surel); yang tidak cocok tetap tersimpan
 * sebagai teks murni.
 *
 *   kasus.penanggung_jawab_teks  : nama PIC sebagaimana diketik.
 *   kasus_penugasan.nama_teks    : nama anggota sebagaimana diketik.
 *
 * Kedua kolom ADDITIVE dan nullable; kolom `user_id` lama tidak dihapus
 * sehingga data legacy tetap tampil kompatibel. Aman dijalankan pada
 * basis data produksi 44 tabel tanpa menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kasus', function (Blueprint $table): void {
            $table->string('penanggung_jawab_teks', 150)->nullable()
                ->after('penanggung_jawab_id')
                ->comment('Nama PIC sebagaimana diketik; user_id diisi bila cocok');
        });

        Schema::table('kasus_penugasan', function (Blueprint $table): void {
            $table->string('nama_teks', 150)->nullable()
                ->after('user_id')
                ->comment('Nama anggota sebagaimana diketik; user_id diisi bila cocok');
        });
    }

    public function down(): void
    {
        Schema::table('kasus', function (Blueprint $table): void {
            $table->dropColumn('penanggung_jawab_teks');
        });

        Schema::table('kasus_penugasan', function (Blueprint $table): void {
            $table->dropColumn('nama_teks');
        });
    }
};
