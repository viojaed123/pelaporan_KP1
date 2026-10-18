<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Model untuk tabel `tugas` — penugasan pekerjaan mentor/admin → karyawan.
 *
 * Tabel baru (additive) hasil migrasi fitur pembagian tugas.
 */
class Tugas extends Model
{
    use HasFactory;

    protected $table = 'tugas';

    protected $fillable = [
        'user_id',
        'ditugaskan_oleh',
        'judul',
        'deskripsi',
        'tenggat',
        'status',
        'diselesaikan_pada',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'ditugaskan_oleh' => 'int',
            'tenggat' => 'date',
            'diselesaikan_pada' => 'datetime',
        ];
    }

    public function penerima()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pemberiTugas()
    {
        return $this->belongsTo(User::class, 'ditugaskan_oleh');
    }

    /** Tenggat sudah lewat dan tugas belum selesai. */
    public function scopeTerlambat($q)
    {
        return $q->where('tenggat', '<', now()->toDateString())
            ->whereNotIn('status', ['selesai', 'dibatalkan']);
    }
}
