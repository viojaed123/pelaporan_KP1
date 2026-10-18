<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model untuk tabel `kasus` (18 kolom).
 *
 * Dihasilkan otomatis oleh scripts/buat_model.php dari metadata skema.
 * Bila menambah relasi atau accessor, tulis di bagian sendiri agar
 * regenerasi berikutnya tidak menghapusnya (lihat skrip generator).
 */
class Kasus extends Model
{
    use HasFactory;

    /** Nama tabel eksplisit: jamak bawaan Laravel tidak cocok (mis. kasus, absensi). */
    protected $table = 'kasus';

    public $timestamps = true;

    /** Kolom pembuat di tabel ini bukan created_at. */
    public const CREATED_AT = 'dibuat_pada';
    public const UPDATED_AT = 'diperbarui_pada';

    /**
     * Kolom yang boleh diisi lewat create()/update().
     *
     * Sengaja TIDAK memuat kunci utama maupun kolom timestamp:
     * keduanya dikelola Laravel sendiri, dan memasukkannya ke sini
     * membuka celah mass-assignment.
     */
    protected $fillable = [
        'kode_kasus',
        'judul',
        'deskripsi',
        'nama_lokasi',
        'garis_lintang',
        'garis_bujur',
        'tingkat_urgensi',
        'status_kasus',
        'progres',
        'dibuat_oleh',
        'penanggung_jawab_id',
        'kategori',
        'sumber',
        'target_selesai',
        'lampiran',
    ];

    /**
     * Pemetaan tipe database ke tipe PHP, supaya akses atribut
     * tidak perlu konversi manual di setiap halaman.
     */
    protected function casts(): array
    {
        return [
            'garis_lintang' => 'decimal:7',
            'garis_bujur' => 'decimal:7',
            'dibuat_oleh' => 'int',
            'penanggung_jawab_id' => 'int',
            'target_selesai' => 'date',
        ];
    }

    /* =================================================================
     * RELASI (ditulis tangan -- bagian generator tidak menyentuhnya)
     * =============================================================== */

    /** Pengguna yang membuat kasus. */
    public function dibuatOleh(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    /** Pengguna penanggung jawab. */
    public function penanggungJawab(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penanggung_jawab_id');
    }

    /** Anggota tim yang ditugaskan pada kasus ini. */
    public function anggota(): HasMany
    {
        return $this->hasMany(KasusPenugasan::class, 'kasus_id');
    }

    /** Riwayat perubahan status kasus. */
    public function statusLog(): HasMany
    {
        return $this->hasMany(KasusStatusLog::class, 'kasus_id');
    }
}
