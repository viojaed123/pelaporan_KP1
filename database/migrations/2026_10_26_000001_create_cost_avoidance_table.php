<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_avoidance', function (Blueprint $table) {
            $table->increments('id');
            $table->string('kode', 20)->unique();
            $table->string('judul', 200);
            $table->string('kategori', 80);
            $table->text('deskripsi')->nullable();
            $table->string('unit', 120)->nullable();
            $table->date('periode');
            $table->decimal('estimasi', 15, 2)->default(0);
            $table->decimal('realisasi', 15, 2)->default(0);
            $table->decimal('nilai_avoidance', 15, 2)->default(0);
            $table->enum('status', ['draft', 'diajukan', 'disetujui', 'ditolak'])->default('draft');
            $table->string('catatan_verifikasi', 255)->nullable();
            $table->unsignedInteger('dibuat_oleh');
            $table->unsignedInteger('diverifikasi_oleh')->nullable();
            $table->dateTime('diajukan_pada')->nullable();
            $table->dateTime('diverifikasi_pada')->nullable();
            $table->timestamp('dibuat_pada')->useCurrent();
            $table->timestamp('diperbarui_pada')->useCurrent()->useCurrentOnUpdate();
            $table->index('status');
            $table->index('periode');
            $table->index('dibuat_oleh');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_avoidance');
    }
};
