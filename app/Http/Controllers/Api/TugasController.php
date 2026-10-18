<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API tugas untuk klien mobile: daftar penugasan & pembaruan status.
 *
 * Aturan yang sama dengan web:
 *   - mentor/admin membuat tugas (via web);
 *   - penerima hanya mengubah status tugas MILIKNYA;
 *   - status: baru → dikerjakan → selesai.
 */
class TugasController extends Controller
{
    public function daftar(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = Tugas::with('pemberiTugas:id,nama')
            ->orderByDesc('id');

        if (! in_array($user->role, ['mentor', 'admin'], true)) {
            $q->where('tugas.user_id', $user->id);
        } elseif ($request->filled('user_id')) {
            $q->where('tugas.user_id', (int) $request->query('user_id'));
        }

        if ($request->filled('status')) {
            $q->where('tugas.status', (string) $request->query('status'));
        }

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (Tugas $t) => $this->ringkas($t));

        return response()->json(['sukses' => true, 'data' => $halaman]);
    }

    public function perbaruiStatus(Request $request, int $id): JsonResponse
    {
        $request->validate(['status' => 'required|in:baru,dikerjakan,selesai']);

        $tugas = Tugas::find($id);

        if ($tugas === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Tugas tidak ditemukan.'], 404);
        }

        $user = $request->user();

        /* Hanya penerima (atau admin) yang boleh mengubah status. */
        if ((int) $tugas->user_id !== (int) $user->id && $user->role !== 'admin') {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak berhak mengubah tugas ini.'], 403);
        }

        $tugas->status = (string) $request->input('status');
        $tugas->diselesaikan_pada = $tugas->status === 'selesai' ? now() : null;
        $tugas->save();

        return response()->json([
            'sukses' => true,
            'pesan'  => 'Status tugas diperbarui.',
            'tugas'  => $this->ringkas($tugas),
        ]);
    }

    private function ringkas(Tugas $t): array
    {
        return [
            'id'          => (int) $t->id,
            'judul'       => (string) $t->judul,
            'deskripsi'   => $t->deskripsi,
            'tenggat'     => $t->tenggat,
            'status'      => (string) $t->status,
            'pemberi'     => $t->pemberiTugas?->nama,
            'diselesaikan_pada' => $t->diselesaikan_pada,
        ];
    }
}
