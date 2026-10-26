<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CostAvoidance extends Model
{
    protected $table = 'cost_avoidance';

    public const CREATED_AT = 'dibuat_pada';
    public const UPDATED_AT = 'diperbarui_pada';

    protected $fillable = [
        'kode',
        'judul',
        'kategori',
        'deskripsi',
        'unit',
        'periode',
        'estimasi',
        'realisasi',
        'nilai_avoidance',
        'status',
        'catatan_verifikasi',
        'dibuat_oleh',
        'diverifikasi_oleh',
        'diajukan_pada',
        'diverifikasi_pada',
    ];

    protected function casts(): array
    {
        return [
            'periode' => 'date',
            'estimasi' => 'decimal:2',
            'realisasi' => 'decimal:2',
            'nilai_avoidance' => 'decimal:2',
            'dibuat_oleh' => 'int',
            'diverifikasi_oleh' => 'int',
            'diajukan_pada' => 'datetime',
            'diverifikasi_pada' => 'datetime',
        ];
    }

    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function verifikator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }
}
