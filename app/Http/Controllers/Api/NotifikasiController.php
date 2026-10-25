<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notifikasi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API notifikasi untuk klien mobile: daftar & penandaan dibaca.
 *
 * Notifikasi SELALU milik pengguna sendiri (tidak ada lintas akun).
 */
class NotifikasiController extends Controller
{
    public function daftar(Request $request): JsonResponse
    {
        $q = Notifikasi::where('user_id', $request->user()->id)
            ->orderByDesc('id');

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (Notifikasi $n) => $this->ringkas($n));

        return response()->json([
            'sukses' => true,
            'data'   => $halaman,
            'belum_dibaca' => Notifikasi::where('user_id', $request->user()->id)
                ->where('sudah_dibaca', false)
                ->count(),
        ]);
    }

    /**
     * Jumlah notifikasi belum dibaca (ringan, dipakai lencana topbar SPA).
     * Sengaja tidak mengirim daftar agar hemat kuota saat dipanggil berkala.
     */
    public function jumlah(Request $request): JsonResponse
    {
        $belum = Notifikasi::where('user_id', $request->user()->id)
            ->where('sudah_dibaca', false)
            ->count();

        return response()->json(['sukses' => true, 'jumlah' => (int) $belum]);
    }

    public function tandaiDibaca(Request $request, int $id): JsonResponse
    {
        $n = Notifikasi::where('user_id', $request->user()->id)->find($id);

        if ($n === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Notifikasi tidak ditemukan.'], 404);
        }

        $n->sudah_dibaca = true;
        $n->save();

        return response()->json(['sukses' => true]);
    }

    public function tandaiSemuaDibaca(Request $request): JsonResponse
    {
        Notifikasi::where('user_id', $request->user()->id)
            ->where('sudah_dibaca', false)
            ->update(['sudah_dibaca' => true]);

        return response()->json(['sukses' => true, 'pesan' => 'Semua notifikasi ditandai sudah dibaca.']);
    }

    private function ringkas(Notifikasi $n): array
    {
        return [
            'id'           => (int) $n->id,
            'pesan'        => (string) $n->pesan,
            'link'         => $n->link,
            'sudah_dibaca' => (bool) $n->sudah_dibaca,
            'pada'         => (string) ($n->dibuat_pada ?? $n->created_at ?? '-'),
        ];
    }
}
