<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\CostAvoidance;
use App\Models\LaporanHarian;
use App\Models\Tugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ringkasan dashboard untuk klien mobile (kartu beranda).
 *
 * Angka dihitung dari data pengguna SENDIRI sehingga setiap peran
 * melihat ringkasan relevan baginya.
 */
class RingkasanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user     = $request->user();
        $bulanIni = now()->startOfMonth()->toDateString();
        $hariIni  = now()->toDateString();

        $laporanBulan = LaporanHarian::where('user_id', $user->id)
            ->whereDate('tanggal', '>=', $bulanIni)
            ->count();

        $absensiHari = Absensi::where('user_id', $user->id)
            ->whereDate('tanggal', $hariIni)
            ->first();

        $tugasAktif = Tugas::where('user_id', $user->id)
            ->whereIn('status', ['baru', 'dikerjakan'])
            ->count();

        $tugasTerlambat = Tugas::where('user_id', $user->id)
            ->whereIn('status', ['baru', 'dikerjakan'])
            ->whereDate('tenggat', '<', $hariIni)
            ->count();

        $costAvoidance = ['total_disetujui' => 0.0, 'diajukan' => 0, 'disetujui' => 0, 'cakupan' => 'sendiri'];
        $peranPenuh = in_array($user->role, ['mentor', 'admin'], true);

        try {
            $q = CostAvoidance::query();
            if (! $peranPenuh) {
                $q->where('dibuat_oleh', $user->id);
            }

            $costAvoidance = [
                'total_disetujui' => (float) (clone $q)
                    ->where('status', 'disetujui')
                    ->sum('nilai_avoidance'),
                'diajukan' => (clone $q)
                    ->where('status', 'diajukan')
                    ->count(),
                'disetujui' => (clone $q)
                    ->where('status', 'disetujui')
                    ->count(),
                'cakupan' => $peranPenuh ? 'semua' : 'sendiri',
            ];
        } catch (\Throwable $e) {
            $costAvoidance = ['total_disetujui' => 0.0, 'diajukan' => 0, 'disetujui' => 0, 'cakupan' => 'sendiri'];
        }

        return response()->json([
            'sukses' => true,
            'ringkasan' => [
                'laporan_bulan_ini'   => $laporanBulan,
                'absensi_hari_ini'    => $absensiHari === null
                    ? ['ada' => false]
                    : [
                        'ada'        => true,
                        'jam_masuk'  => $absensiHari->jam_masuk,
                        'jam_pulang' => $absensiHari->jam_pulang,
                        'status'     => (string) ($absensiHari->status ?? '-'),
                    ],
                'tugas_aktif'         => $tugasAktif,
                'tugas_terlambat'     => $tugasTerlambat,
                'cost_avoidance'      => $costAvoidance,
            ],
        ]);
    }
}
