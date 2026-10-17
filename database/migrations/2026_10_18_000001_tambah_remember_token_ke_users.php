<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menambahkan kolom remember_token ke tabel users.
 *
 * Latar belakang:
 *   Tabel users hasil sistem lama TIDAK memiliki remember_token, padahal
 *   Authenticatable::getAuthRememberToken() memakainya untuk fitur
 *   "ingat saya" bawaan Laravel. Tanpa kolom ini, login() dengan
 *   remember=true akan gagal dengan SQL error (Unknown column).
 *
 *   Sistem lama menyimpan "ingat saya" di tabel terpisah (user_security:
 *   ingat_token_hash, ingat_kedaluwarsa, ingat_ip). Kolom remember_token
 *   yang ditambahkan di sini adalah standar Laravel dan BERCOBAKOSS dengan
 *   mekanisme lama -- keduanya dapat hidup berdampingan selama migrasi.
 *
 * Sifat perubahan: PENAMBAHAN kolom nullable.
 *   Tidak memodifikasi baris mana pun, tidak mengubah tipe kolom yang ada,
 *   dan aman dijalankan pada database berisi data produksi (36 tabel).
 *
 * PENTING: jangan sekali-kali memakai migrate:fresh pada database ini.
 */
return new class extends Migration
{
    /**
     * Jalankan migrasi.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /* Penjaga: bila kolom sudah ada (mis. sudah pernah dijalankan
             * atau ditambahkan manual), lewati agar migrasi tetap idempoten. */
            if (Schema::hasColumn('users', 'remember_token')) {
                return;
            }

            /* $table->rememberToken() membuat: varchar(100) nullable, terindeks. */
            $table->rememberToken();
        });
    }

    /**
     * Batalkan migrasi.
     *
     * Dapat dijalankan dengan aman: hanya menghapus kolom yang DITAMBAHKAN
     * oleh up(). Kolom lain pada tabel users tidak tersentuh.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'remember_token')) {
                $table->dropColumn('remember_token');
            }
        });
    }
};
