<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kasus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API kasus untuk klien mobile: daftar & detail.
 *
 * Visibilitas (porting aturan legacy includes/kasus.php):
 *   - mentor/admin  : semua kasus;
 *   - karyawan       : hanya kasus yang melibatkannya (pembuat,
 *                      penanggung jawab, atau anggota tim).
 */
class KasusController extends Controller
{
    public function daftar(Request $request): JsonResponse
    {
        $q = Kasus::query()->orderByDesc('id');

        if (! $this->bolehLihatSemua($request->user())) {
            $this->saringLibatkan($q, (int) $request->user()->id);
        }

        if ($request->filled('status')) {
            $q->where('status_kasus', (string) $request->query('status'));
        }

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (Kasus $k) => $this->ringkas($k));

        return response()->json(['sukses' => true, 'data' => $halaman]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $kasus = Kasus::with([
            'penanggungJawab:id,nama',
            'dibuatOleh:id,nama',
            'anggota.user:id,nama',
            'statusLog',
        ])->find($id);

        if ($kasus === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Kasus tidak ditemukan.'], 404);
        }

        $user = $request->user();

        if (! $this->bolehLihatSemua($user) && ! $this->libatkan($kasus, (int) $user->id)) {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak berhak melihat kasus ini.'], 403);
        }

        return response()->json([
            'sukses' => true,
            'kasus'  => $this->ringkas($kasus, true),
        ]);
    }

    /* ================================================================= */

    private function bolehLihatSemua(object $user): bool
    {
        return in_array($user->role, ['mentor', 'admin'], true);
    }

    /** Batasi query ke kasus yang melibatkan pengguna tersebut. */
    private function saringLibatkan($q, int $userId): void
    {
        $q->where(function ($sub) use ($userId) {
            $sub->where('dibuat_oleh', $userId)
                ->orWhere('penanggung_jawab_id', $userId)
                ->orWhereIn('id', function ($inner) use ($userId) {
                    $inner->select('kasus_id')
                        ->from('kasus_penugasan')
                        ->where('user_id', $userId);
                });
        });
    }

    private function libatkan(Kasus $k, int $userId): bool
    {
        return (int) $k->dibuat_oleh === $userId
            || (int) $k->penanggung_jawab_id === $userId
            || $k->anggota->contains(fn ($a) => (int) $a->user_id === $userId);
    }

    private function ringkas(Kasus $k, bool $lengkap = false): array
    {
        $data = [
            'kode'         => (string) $k->kode_kasus,
            'judul'        => (string) $k->judul,
            'urgensi'      => (string) ($k->tingkat_urgensi ?? '-'),
            'status'       => (string) ($k->status_kasus ?? '-'),
            'progres'      => (int) ($k->progres ?? 0),
            'lokasi'       => $k->nama_lokasi,
            'kategori'     => $k->kategori,
        ];

        if ($lengkap) {
            $data['deskripsi']      = $k->deskripsi;
            $data['penanggung_jawab'] = $k->penanggungJawab?->nama;
            $data['dibuat_oleh']    = $k->dibuatOleh?->nama;
            $data['anggota']        = $k->anggota->map(fn ($a) => [
                'user_id' => $a->user_id !== null ? (int) $a->user_id : null,
                'nama'    => $a->user?->nama ?? (string) ($a->nama_teks ?? '-'),
                'peran'   => (string) ($a->peran ?? '-'),
            ])->values();
            $data['log_status'] = $k->statusLog->map(fn ($l) => [
                'status_lama' => $l->status_lama,
                'status_baru' => $l->status_baru,
                'progres'     => $l->progres,
                'catatan'     => $l->catatan,
                'oleh'        => $l->user?->nama,
                'pada'        => (string) $l->dibuat_pada,
            ])->values();
        }

        return $data;
    }
}
