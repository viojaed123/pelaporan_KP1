<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LaporanHarian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API laporan harian untuk klien mobile.
 *
 * Karyawan SELALU melihat laporannya sendiri; mentor/admin dapat
 * melihat laporan semua orang (dengan filter `user_id` opsional).
 */
class LaporanController extends Controller
{
    /** Riwayat laporan milik pengguna (atau semua, untuk mentor/admin). */
    public function riwayat(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = LaporanHarian::with('user:id,nama')
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if (! $this->bolehLihatSemua($user)) {
            $q->where('laporan_harian.user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $q->where('laporan_harian.user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('bulan')) {
            /* Format: YYYY-MM */
            $q->whereDate('tanggal', '>=', $request->query('bulan') . '-01')
              ->whereDate('tanggal', '<=', $request->query('bulan') . '-31');
        }

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (LaporanHarian $l) => $this->ringkas($l));

        return response()->json(['sukses' => true, 'data' => $halaman]);
    }

    /** Simpan laporan baru (dari aplikasi mobile). */
    public function simpan(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'tanggal'         => ['required', 'date'],
            'jam_mulai'       => ['nullable', 'date_format:H:i'],
            'jam_selesai'     => ['nullable', 'date_format:H:i', 'after:jam_mulai'],
            'kegiatan'        => ['required', 'string', 'max:255'],
            'lokasi_teks'     => ['nullable', 'string', 'max:255'],
            'garis_lintang'   => ['nullable', 'numeric', 'between:-90,90'],
            'garis_bujur'     => ['nullable', 'numeric', 'between:-180,180'],
            'wilayah_id'      => ['nullable', 'integer', 'exists:wilayah,id'],
            'deskripsi'       => ['nullable', 'string'],
            'hasil'           => ['nullable', 'string'],
            'kendala'         => ['nullable', 'string'],
            'rencana_berikutnya' => ['nullable', 'string'],
        ]);

        $data['user_id'] = $user->id;
        $data['status']  = 'diajukan';

        $laporan = LaporanHarian::create($data);

        return response()->json([
            'sukses'  => true,
            'pesan'   => 'Laporan berhasil disimpan.',
            'laporan' => $this->ringkas($laporan),
        ], 201);
    }

    /* ================================================================= */

    private function bolehLihatSemua(object $user): bool
    {
        return in_array($user->role, ['mentor', 'admin'], true);
    }

    private function ringkas(LaporanHarian $l): array
    {
        return [
            'id'           => (int) $l->id,
            'tanggal'      => (string) $l->tanggal,
            'kegiatan'     => (string) $l->kegiatan,
            'jam_mulai'    => $l->jam_mulai,
            'jam_selesai'  => $l->jam_selesai,
            'lokasi_teks'  => $l->lokasi_teks,
            'deskripsi'    => $l->deskripsi,
            'hasil'        => $l->hasil,
            'kendala'      => $l->kendala,
            'status'       => (string) ($l->status ?? '-'),
            'pengguna'     => $l->user?->nama,
        ];
    }
}
