<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API absensi untuk klien mobile: riwayat, check-in, check-out.
 *
 * Satu baris absensi per pengguna per hari. Check-in mengisi `jam_masuk`,
 * check-out mengisi `jam_pulang`. Keduanya menolak pengulangan agar data
 * kehadiran tetap jujur.
 */
class AbsensiController extends Controller
{
    public function riwayat(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = Absensi::with('user:id,nama')
            ->where('absensi.user_id', $user->id)
            ->orderByDesc('tanggal')
            ->orderByDesc('id');

        if ($request->filled('bulan')) {
            $q->whereDate('tanggal', '>=', $request->query('bulan') . '-01')
              ->whereDate('tanggal', '<=', $request->query('bulan') . '-31');
        }

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (Absensi $a) => $this->ringkas($a));

        return response()->json(['sukses' => true, 'data' => $halaman]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        $user = $request->user();
        $hari = now()->toDateString();

        /* whereDate (bukan where): kolom tanggal punya cast 'date' sehingga
         * nilainya tersimpan 'Y-m-d H:i:s' -- perbandingan setara tanggal
         * dibutuhkan agar cocok di MySQL maupun sqlite uji. */
        if (Absensi::where('user_id', $user->id)->whereDate('tanggal', $hari)->exists()) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Anda sudah melakukan check-in hari ini.',
            ], 422);
        }

        $data = $request->validate([
            'lokasi_teks'   => ['nullable', 'string', 'max:255'],
            'garis_lintang' => ['nullable', 'numeric', 'between:-90,90'],
            'garis_bujur'   => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $absensi = Absensi::create(array_merge([
            'user_id'  => $user->id,
            'tanggal'  => $hari,
            'jam_masuk' => now()->format('H:i:s'),
            'status'   => 'hadir',
        ], $data));

        return response()->json([
            'sukses'  => true,
            'pesan'   => 'Check-in berhasil. Selamat bekerja!',
            'absensi' => $this->ringkas($absensi),
        ], 201);
    }

    public function checkOut(Request $request): JsonResponse
    {
        $user = $request->user();
        $hari = now()->toDateString();

        $absensi = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', $hari)
            ->first();

        if ($absensi === null) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Anda belum check-in hari ini.',
            ], 422);
        }

        if ($absensi->jam_pulang !== null && $absensi->jam_pulang !== '') {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Anda sudah melakukan check-out hari ini.',
            ], 422);
        }

        $absensi->jam_pulang = now()->format('H:i:s');
        $absensi->save();

        return response()->json([
            'sukses'  => true,
            'pesan'   => 'Check-out berhasil. Sampai jumpa besok!',
            'absensi' => $this->ringkas($absensi),
        ]);
    }

    private function ringkas(Absensi $a): array
    {
        return [
            'id'          => (int) $a->id,
            'tanggal'     => (string) $a->tanggal,
            'jam_masuk'   => $a->jam_masuk,
            'jam_pulang'  => $a->jam_pulang,
            'status'      => (string) ($a->status ?? '-'),
            'lokasi_teks' => $a->lokasi_teks,
        ];
    }
}
