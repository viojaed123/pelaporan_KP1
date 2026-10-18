<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel penugasan tugas karyawan.
 *
 * Mentor/admin menugaskan pekerjaan kepada karyawan; tugas tampil di
 * dashboard karyawan beserta notifikasi tenggat. Tabel ini BARU (additive),
 * sehingga aman dijalankan dengan `php artisan migrate` pada basis data
 * produksi yang sudah berisi 44 tabel tanpa menyentuh data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tugas', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->comment('Karyawan yang ditugaskan');
            $table->unsignedInteger('ditugaskan_oleh')->comment('Mentor/admin yang menugaskan');
            $table->string('judul', 200);
            $table->text('deskripsi')->nullable();
            $table->date('tenggat')->nullable();
            $table->enum('status', ['baru', 'dikerjakan', 'selesai', 'dibatalkan'])->default('baru');
            $table->timestamp('diselesaikan_pada')->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_tugas_user');
            $table->index('status', 'idx_tugas_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tugas');
    }
};
