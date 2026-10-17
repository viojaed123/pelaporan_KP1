<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `cid_program` (39 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class CidProgram extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'cid_program';

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
        'judul_program',
        'pilar_program',
        'desa',
        'desa_aset_operasi',
        'desa_rk_bor',
        'desa_konflik_sosial',
        'kode_desa',
        'kecamatan',
        'kode_kecamatan',
        'kabupaten_kota',
        'kode_kabupaten_kota',
        'kelompok_diberdayakan',
        'total_penerima_manfaat',
        'perempuan',
        'laki_laki',
        'ibu_hamil',
        'balita',
        'disabilitas',
        'potensi_peningkatan_ekonomi',
        'potensi_penghematan_operasional',
        'penanaman_pohon',
        'luas_penanaman_ha',
        'sampah_terkelola_kg',
        'peningkatan_kualitas_kesehatan',
        'penyerapan_lapangan_kerja',
        'peningkatan_kapasitas',
        'pembentukan_kelompok_baru',
        'kerjasama_stakeholder',
        'bulan',
        'bulan_angka',
        'program',
        'anggaran',
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
            'total_penerima_manfaat' => 'int',
            'perempuan' => 'int',
            'laki_laki' => 'int',
            'ibu_hamil' => 'int',
            'balita' => 'int',
            'disabilitas' => 'int',
            'potensi_peningkatan_ekonomi' => 'decimal:2',
            'potensi_penghematan_operasional' => 'decimal:2',
            'penanaman_pohon' => 'int',
            'luas_penanaman_ha' => 'decimal:2',
            'sampah_terkelola_kg' => 'decimal:2',
            'peningkatan_kualitas_kesehatan' => 'bool',
            'penyerapan_lapangan_kerja' => 'bool',
            'peningkatan_kapasitas' => 'bool',
            'pembentukan_kelompok_baru' => 'bool',
            'anggaran' => 'decimal:2',
            'wilayah_id' => 'int',
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
            'baris_sumber' => 'int',
        ];
    }
}
