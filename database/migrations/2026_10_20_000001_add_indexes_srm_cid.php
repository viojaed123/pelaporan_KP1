<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('srm_kegiatan')) {
            Schema::table('srm_kegiatan', function (Blueprint $table) {
                try {
                    $table->index(['tahun_intervensi', 'kabupaten_kota', 'status', 'area_field'], 'idx_srm_filter');
                } catch (\Throwable $e) {
                }
            });
        }
        if (Schema::hasTable('cid_program')) {
            Schema::table('cid_program', function (Blueprint $table) {
                try {
                    $table->index(['tahun_intervensi', 'kabupaten_kota', 'pilar_program', 'desa'], 'idx_cid_filter');
                } catch (\Throwable $e) {
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('srm_kegiatan')) {
            Schema::table('srm_kegiatan', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_srm_filter');
                } catch (\Throwable $e) {
                }
            });
        }
        if (Schema::hasTable('cid_program')) {
            Schema::table('cid_program', function (Blueprint $table) {
                try {
                    $table->dropIndex('idx_cid_filter');
                } catch (\Throwable $e) {
                }
            });
        }
    }
};
