<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel untuk "dataset generik": data apa pun yang diimpor pengguna tanpa
 * skema tetap. Profil kolom disimpan di dataset.kolom_json, isi baris di
 * dataset_baris.data_json (JSON per baris).
 *
 * Rancangan JSON dipilih agar tidak perlu DDL (CREATE/ALTER TABLE) setiap kali
 * ada berkas baru, dan demi keamanan (tidak ada nama tabel/kolom dinamis yang
 * dibangun dari input pengguna).
 *
 * Skala sasaran: ratusan sampai ribuan baris per berkas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('dataset')) {
            Schema::create('dataset', function (Blueprint $table) {
                $table->increments('id');
                $table->string('nama', 150);
                $table->string('slug', 160)->unique();
                $table->string('deskripsi', 255)->nullable();
                $table->integer('user_id')->nullable()->index();
                $table->unsignedInteger('jumlah_baris')->default(0);
                $table->unsignedInteger('jumlah_kolom')->default(0);
                // Profil kolom: [{kunci,label,tipe,kosong}, ...]
                $table->longText('kolom_json')->nullable();
                $table->string('nama_berkas', 255)->nullable();
                $table->timestamp('dibuat_pada')->useCurrent();
            });
        }

        if (!Schema::hasTable('dataset_baris')) {
            Schema::create('dataset_baris', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('dataset_id')->index();
                $table->unsignedInteger('nomor')->default(0);
                // Isi baris: {"<kunci>": "<nilai>", ...}
                $table->longText('data_json')->nullable();
                $table->index(['dataset_id', 'nomor'], 'idx_dataset_baris_urut');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dataset_baris');
        Schema::dropIfExists('dataset');
    }
};
