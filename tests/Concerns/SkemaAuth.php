<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membuat skema tabel otentikasi di basis data uji (sqlite :memory:).
 *
 * MENGAPA INI PERLU:
 *   Tabel-tabel ini adalah WARISAN sistem lama; tidak ada migrasi Laravel
 *   untuknya dan memang tidak boleh ada -- basis data produksi sudah berisi
 *   data dan tidak boleh disentuh (`migrate:fresh` akan menghapusnya).
 *   Karena itu uji yang membutuhkan tabel ini membuatnya SENDIRI di memori,
 *   meniru definisi sebenarnya namun sesederhana mungkin.
 *
 * Kolomnya sengaja dicocokkan dengan nama ASLI (dibuat_pada, bukan
 * created_at) supaya model yang memakai konstanta timestamp kustom
 * (CREATED_AT/UPDATED_AT) benar-benar teruji -- bukan teruji dengan skema
 * yang berbeda dari kenyataan.
 */
trait SkemaAuth
{
    protected function siapkanSkemaAuth(): void
    {
        /*
         * users -- satu-satunya tabel di sini yang memakai created_at
         * (bukan dibuat_pada); lihat konstanta CREATED_AT pada model User.
         */
        Schema::create('users', function (Blueprint $t): void {
            $t->id();
            $t->string('nama');
            $t->string('email')->unique();
            $t->string('password');
            $t->string('role')->default('karyawan');
            $t->string('status')->default('aktif');
            $t->timestamp('email_verified_at')->nullable();
            $t->string('divisi')->nullable();
            $t->string('telepon')->nullable();
            $t->string('foto_profil')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->string('remember_token')->nullable();
        });

        /* Kunci utamanya varchar, bukan id auto-increment. */
        Schema::create('login_locks', function (Blueprint $t): void {
            $t->string('kunci')->primary();
            $t->integer('gagal')->default(0);
            $t->timestamp('terkunci_sampai')->nullable();
            $t->timestamp('percobaan_pertama')->nullable();
            $t->timestamp('diperbarui')->nullable();
        });

        Schema::create('login_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('email')->nullable();
            $t->integer('user_id')->nullable();
            $t->string('ip_address');
            $t->string('user_agent')->nullable();
            $t->boolean('berhasil')->default(false);
            $t->string('alasan')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('user_security', function (Blueprint $t): void {
            $t->integer('user_id')->primary();
            $t->boolean('dua_faktor_aktif')->default(false);
            $t->timestamp('password_diubah_pada')->nullable();
            $t->string('ingat_token_hash')->nullable();
            $t->timestamp('ingat_kedaluwarsa')->nullable();
            $t->string('ingat_ip')->nullable();
            $t->integer('gagal_login_berturut')->default(0);
            $t->timestamp('terakhir_kunci')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('diperbarui_pada')->nullable();
        });

        Schema::create('user_otp', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('jenis')->default('login');
            $t->string('kode_hash');
            $t->string('tujuan');
            $t->integer('percobaan')->default(0);
            $t->boolean('dipakai')->default(false);
            $t->timestamp('kedaluwarsa');
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('backup_codes', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('kode_hash');
            $t->boolean('dipakai')->default(false);
            $t->timestamp('dipakai_pada')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('password_history', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('password_hash');
            $t->timestamp('dibuat_pada')->nullable();
        });

        Schema::create('sesi_aktif', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id');
            $t->string('sesi_hash')->unique();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->boolean('dicabut')->default(false);
            $t->timestamp('dibuat_pada')->nullable();
            $t->timestamp('terakhir_aktif')->nullable();
        });

        Schema::create('audit_log', function (Blueprint $t): void {
            $t->id();
            $t->integer('user_id')->nullable();
            $t->string('aksi');
            $t->string('tabel_ref')->nullable();
            $t->integer('baris_id')->nullable();
            $t->string('ringkasan')->nullable();
            $t->longText('data_lama')->nullable();
            $t->longText('data_baru')->nullable();
            $t->string('ip_address')->nullable();
            $t->string('user_agent')->nullable();
            $t->timestamp('dibuat_pada')->nullable();
        });
    }
}
