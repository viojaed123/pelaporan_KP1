<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `srm_kegiatan` (26 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class SrmKegiatan extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'srm_kegiatan';

    public $timestamps = true;

    /** Kolom pembuat di tabel ini bukan created_at. */
    public const CREATED_AT = 'dibuat_pada';
    /** Tabel ini hanya mencatat waktu pembuatan, tanpa pembaruan. */
    public const UPDATED_AT = null;

    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'tahun_intervensi',
        'area_field',
        'tanggal',
        'waktu',
        'pic',
        'kategori_kegiatan',
        'klasifikasi_issue',
        'judul_issue',
        'detail_issue',
        'kelurahan',
        'kecamatan',
        'kabupaten_kota',
        'jenis_stakeholder',
        'stakeholder_hadir',
        'action_plan',
        'tim_phr',
        'value_creation',
        'status',
        'persen_status',
        'dokumentasi',
        'wilayah_id',
        'garis_lintang',
        'garis_bujur',
        'baris_sumber',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'tahun_intervensi' => 'int',
            'tanggal' => 'date',
            'persen_status' => 'decimal:2',
            'wilayah_id' => 'int',
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
            'baris_sumber' => 'int',
        ];
    }
}
