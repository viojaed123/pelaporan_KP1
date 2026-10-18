<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\LaporanHarian;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * API analitik untuk SPA: rekap laporan, rekap absensi, ringkasan
 * mingguan/bulanan, dan kalender aktivitas.
 *
 * Porting dari halaman legacy `rekap_laporan.php`, `rekap_absensi.php`,
 * `ringkasan.php`, dan `kalender.php`. Aturan peran dipertahankan:
 *   - rekap (laporan/absensi)  : mentor & admin;
 *   - ringkasan & kalender     : semua pengguna, dengan penskalaan
 *                                (karyawan melihat dirinya sendiri).
 *
 * Perhitungan dibuat portabel (tanpa fungsi khusus MySQL) agar cocok
 * di MySQL produksi maupun sqlite pengujian.
 */
class AnalitikController extends Controller
{
    /** Status laporan yang dikenali (gabungan nilai legacy & API). */
    private const STATUS_LAPORAN = ['draft', 'dikirim', 'diperiksa', 'perlu_revisi', 'diajukan'];

    /* =================================================================
     * REKAP LAPORAN (mentor/admin)
     * =============================================================== */

    public function rekapLaporan(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_akhir' => ['nullable', 'date'],
            'status'        => ['nullable', 'string', 'max:30'],
            'karyawan'      => ['nullable', 'integer'],
        ]);

        $dasar = fn () => $this->queryRekapLaporan($request);

        $ringkasan = ['total' => (clone $dasar())->count()];
        foreach (self::STATUS_LAPORAN as $status) {
            $ringkasan[$status] = (clone $dasar())->where('laporan_harian.status', $status)->count();
        }

        $halaman = $dasar()
            ->paginate(min(200, max(1, (int) $request->query('per_halaman', 50))))
            ->through(fn ($r) => [
                'id'       => (int) $r->id,
                'tanggal'  => (string) $r->tanggal,
                'kegiatan' => (string) $r->kegiatan,
                'status'   => (string) ($r->status ?? '-'),
                'karyawan' => (string) $r->karyawan,
                'divisi'   => $r->divisi !== null && $r->divisi !== '' ? (string) $r->divisi : null,
            ]);

        return response()->json([
            'sukses'          => true,
            'ringkasan'       => $ringkasan,
            'daftar_karyawan' => $this->daftarKaryawan(),
            'data'            => $halaman,
        ]);
    }

    /** Query dasar rekap laporan (dipakai berulang untuk hitungan & daftar). */
    private function queryRekapLaporan(Request $request)
    {
        $q = LaporanHarian::query()
            ->join('users', 'users.id', '=', 'laporan_harian.user_id')
            ->where('users.role', 'karyawan')
            ->select(
                'laporan_harian.id',
                'laporan_harian.tanggal',
                'laporan_harian.kegiatan',
                'laporan_harian.status',
                'users.nama as karyawan',
                'users.divisi',
            )
            ->orderByDesc('laporan_harian.tanggal')
            ->orderByDesc('laporan_harian.id');

        if ($request->filled('tanggal_mulai')) {
            $q->whereDate('laporan_harian.tanggal', '>=', (string) $request->query('tanggal_mulai'));
        }
        if ($request->filled('tanggal_akhir')) {
            $q->whereDate('laporan_harian.tanggal', '<=', (string) $request->query('tanggal_akhir'));
        }

        $status = (string) $request->query('status', '');
        if ($status !== '' && in_array($status, self::STATUS_LAPORAN, true)) {
            $q->where('laporan_harian.status', $status);
        }

        if ($request->filled('karyawan')) {
            $q->where('laporan_harian.user_id', (int) $request->query('karyawan'));
        }

        return $q;
    }

    /**
     * Daftar karyawan untuk pemilih (mentor/admin).
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,nama:string}>
     */
    private function daftarKaryawan()
    {
        return User::where('role', 'karyawan')
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn (User $u) => ['id' => (int) $u->id, 'nama' => (string) $u->nama])
            ->values();
    }

    /* =================================================================
     * REKAP ABSENSI (mentor/admin)
     * =============================================================== */

    public function rekapAbsensi(Request $request): JsonResponse
    {
        $request->validate([
            'awal'  => ['nullable', 'date'],
            'akhir' => ['nullable', 'date'],
        ]);

        $awal  = (string) ($request->query('awal') ?: now()->startOfMonth()->toDateString());
        $akhir = (string) ($request->query('akhir') ?: now()->toDateString());
        if ($awal > $akhir) {
            [$awal, $akhir] = [$akhir, $awal];
        }

        /* Daftar catatan absensi pada periode. */
        $halaman = Absensi::query()
            ->join('users', 'users.id', '=', 'absensi.user_id')
            ->whereDate('absensi.tanggal', '>=', $awal)
            ->whereDate('absensi.tanggal', '<=', $akhir)
            ->select(
                'absensi.id',
                'absensi.tanggal',
                'absensi.jam_masuk',
                'absensi.jam_pulang',
                'absensi.status',
                'absensi.keterangan',
                'absensi.kegiatan_hari_ini',
                'absensi.kondisi_kesehatan',
                'users.nama',
                'users.email',
            )
            ->orderBy('absensi.tanggal')
            ->orderBy('users.nama')
            ->paginate(min(300, max(1, (int) $request->query('per_halaman', 100))))
            ->through(fn ($r) => [
                'id'                => (int) $r->id,
                'tanggal'           => (string) $r->tanggal,
                'nama'              => (string) $r->nama,
                'email'             => (string) $r->email,
                'jam_masuk'         => $r->jam_masuk,
                'jam_pulang'        => $r->jam_pulang,
                'status'            => (string) ($r->status ?? '-'),
                'keterangan'        => $r->keterangan,
                'kegiatan_hari_ini' => $r->kegiatan_hari_ini,
                'kondisi_kesehatan' => $r->kondisi_kesehatan,
            ]);

        /* Ringkasan status pada periode. */
        $ringkasan = ['total' => 0, 'hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
        $hitung = Absensi::query()
            ->whereDate('tanggal', '>=', $awal)
            ->whereDate('tanggal', '<=', $akhir)
            ->selectRaw('status, COUNT(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        foreach ($hitung as $status => $jumlah) {
            $kunci = strtolower((string) $status);
            $ringkasan['total'] += (int) $jumlah;
            if (array_key_exists($kunci, $ringkasan)) {
                $ringkasan[$kunci] = (int) $jumlah;
            }
        }

        /* Rekap per peserta (termasuk yang belum pernah absen). */
        $perPeserta = User::query()
            ->where('role', 'karyawan')
            ->orderBy('nama')
            ->get(['id', 'nama', 'email'])
            ->map(function (User $u) use ($awal, $akhir) {
                $stat = Absensi::query()
                    ->where('user_id', $u->id)
                    ->whereDate('tanggal', '>=', $awal)
                    ->whereDate('tanggal', '<=', $akhir)
                    ->selectRaw('status, COUNT(*) as jumlah')
                    ->groupBy('status')
                    ->pluck('jumlah', 'status');

                $ambil = fn (string $s): int => (int) ($stat[$s] ?? 0);

                return [
                    'user_id'       => (int) $u->id,
                    'nama'          => (string) $u->nama,
                    'email'         => (string) $u->email,
                    'total_catatan' => (int) $stat->sum(),
                    'hadir'         => $ambil('hadir'),
                    'izin'          => $ambil('izin'),
                    'sakit'         => $ambil('sakit'),
                    'alpa'          => $ambil('alpa'),
                ];
            })
            ->values();

        return response()->json([
            'sukses'     => true,
            'periode'    => ['awal' => $awal, 'akhir' => $akhir],
            'ringkasan'  => $ringkasan,
            'per_peserta' => $perPeserta,
            'data'       => $halaman,
        ]);
    }

    /* =================================================================
     * RINGKASAN KEGIATAN (mingguan/bulanan)
     * =============================================================== */

    public function ringkasan(Request $request): JsonResponse
    {
        $request->validate([
            'periode' => ['nullable', 'in:mingguan,bulanan'],
            'tanggal' => ['nullable', 'date'],
            'user'    => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $periode = (string) $request->query('periode', 'mingguan') === 'bulanan' ? 'bulanan' : 'mingguan';

        $acuanStr = (string) ($request->query('tanggal') ?: now()->toDateString());
        $acuan = Carbon::parse($acuanStr);

        if ($periode === 'mingguan') {
            $mulai = $acuan->copy()->startOfWeek(Carbon::MONDAY);
            $selesai = $mulai->copy()->addDays(6);
            $mulaiLalu = $mulai->copy()->subDays(7);
            $selesaiLalu = $mulaiLalu->copy()->addDays(6);
            $label = 'Minggu ' . $mulai->translatedFormat('d M') . ' – ' . $selesai->translatedFormat('d M Y');
        } else {
            $mulai = $acuan->copy()->startOfMonth();
            $selesai = $acuan->copy()->endOfMonth();
            $mulaiLalu = $mulai->copy()->subMonthNoOverflow()->startOfMonth();
            $selesaiLalu = $mulai->copy()->subMonthNoOverflow()->endOfMonth();
            $label = $acuan->translatedFormat('F Y');
        }

        /* Sasaran: karyawan selalu dirinya; mentor/admin memilih. */
        $daftarKaryawan = [];
        if ($user->role === 'karyawan') {
            $sasaranId = (int) $user->id;
        } else {
            $daftarKaryawan = $this->daftarKaryawan();

            $minta = (int) $request->query('user', 0);
            $sasaranId = $minta > 0
                && $daftarKaryawan->contains(fn ($k) => $k['id'] === $minta)
                ? $minta
                : null; /* null = seluruh karyawan */
        }

        $stat = $this->statistikLaporan($mulai->toDateString(), $selesai->toDateString(), $sasaranId);
        $statLalu = $this->statistikLaporan($mulaiLalu->toDateString(), $selesaiLalu->toDateString(), $sasaranId);

        $namaSasaran = $sasaranId !== null
            ? (string) (User::whereKey($sasaranId)->value('nama') ?? $user->nama)
            : 'Seluruh Karyawan';

        return response()->json([
            'sukses'      => true,
            'periode'     => $periode,
            'label'       => $label,
            'mulai'       => $mulai->toDateString(),
            'selesai'     => $selesai->toDateString(),
            'sasaran'     => $namaSasaran,
            'sasaran_id'  => $sasaranId,
            'stat'        => $stat,
            'stat_sebelum'=> $statLalu,
            'selisih'     => $stat['jml'] - $statLalu['jml'],
            'daftar_karyawan' => $daftarKaryawan,
        ]);
    }

    /**
     * Statistik laporan pada rentang tanggal.
     *
     * @return array<string,int>
     */
    private function statistikLaporan(string $mulai, string $selesai, ?int $userId): array
    {
        $q = LaporanHarian::query()
            ->whereDate('tanggal', '>=', $mulai)
            ->whereDate('tanggal', '<=', $selesai);

        if ($userId !== null) {
            $q->where('user_id', $userId);
        }

        $baris = $q->get(['tanggal', 'jam_mulai', 'jam_selesai', 'status', 'kendala']);

        $menit = 0;
        $kendala = 0;
        $hitungStatus = ['revisi' => 0, 'diperiksa' => 0, 'dikirim' => 0, 'draft' => 0];

        foreach ($baris as $b) {
            $m = $this->selisihMenit((string) $b->jam_mulai, (string) $b->jam_selesai);
            if ($m > 0) {
                $menit += $m;
            }
            if (trim((string) $b->kendala) !== '') {
                $kendala++;
            }
            match ((string) $b->status) {
                'perlu_revisi' => $hitungStatus['revisi']++,
                'diperiksa'    => $hitungStatus['diperiksa']++,
                'dikirim', 'diajukan' => $hitungStatus['dikirim']++,
                'draft'        => $hitungStatus['draft']++,
                default        => null,
            };
        }

        $hariUnik = $baris
            ->map(fn ($b) => Carbon::parse((string) $b->tanggal)->toDateString())
            ->unique()
            ->count();

        return [
            'jml'       => $baris->count(),
            'hari'      => $hariUnik,
            'menit'     => $menit,
            'kendala'   => $kendala,
            'revisi'    => $hitungStatus['revisi'],
            'diperiksa' => $hitungStatus['diperiksa'],
            'dikirim'   => $hitungStatus['dikirim'],
            'draft'     => $hitungStatus['draft'],
        ];
    }

    /** Selisih menit antara dua jam 'H:i(:s)'; 0 bila tidak valid. */
    private function selisihMenit(string $mulai, string $selesai): int
    {
        if ($mulai === '' || $selesai === '') {
            return 0;
        }
        $a = strtotime('1970-01-01 ' . $mulai);
        $b = strtotime('1970-01-01 ' . $selesai);
        if ($a === false || $b === false) {
            return 0;
        }

        return max(0, (int) round(($b - $a) / 60));
    }

    /* =================================================================
     * KALENDER AKTIVITAS
     * =============================================================== */

    public function kalender(Request $request): JsonResponse
    {
        $request->validate([
            'bulan' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'user'  => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        $bulan = (string) ($request->query('bulan') ?: now()->format('Y-m'));

        $awal = Carbon::parse($bulan . '-01')->startOfMonth();
        $akhir = $awal->copy()->endOfMonth();

        $namaBulan = $awal->translatedFormat('F Y');

        /* Sasaran kalender. */
        $daftarKaryawan = [];
        if ($user->role === 'karyawan') {
            $sasaranId = (int) $user->id;
        } else {
            $daftarKaryawan = $this->daftarKaryawan();

            $minta = (int) $request->query('user', 0);
            $sasaranId = $minta > 0
                && $daftarKaryawan->contains(fn ($k) => $k['id'] === $minta)
                ? $minta
                : null;
        }

        $namaSasaran = $sasaranId !== null
            ? (string) (User::whereKey($sasaranId)->value('nama') ?? $user->nama)
            : 'Seluruh Karyawan';

        /* Data per hari: jumlah laporan (+ jumlah karyawan aktif bila agregat). */
        $q = LaporanHarian::query()
            ->whereDate('tanggal', '>=', $awal->toDateString())
            ->whereDate('tanggal', '<=', $akhir->toDateString());

        if ($sasaranId !== null) {
            $q->where('user_id', $sasaranId);
        }

        $perHari = [];
        foreach ($q->get(['tanggal', 'status', 'user_id']) as $b) {
            $tgl = Carbon::parse((string) $b->tanggal)->toDateString();
            if (! isset($perHari[$tgl])) {
                $perHari[$tgl] = ['jml' => 0, 'revisi' => 0, 'diperiksa' => 0, 'dikirim' => 0, 'org' => []];
            }
            $perHari[$tgl]['jml']++;
            $perHari[$tgl]['org'][(int) $b->user_id] = true;
            match ((string) $b->status) {
                'perlu_revisi' => $perHari[$tgl]['revisi']++,
                'diperiksa'    => $perHari[$tgl]['diperiksa']++,
                'dikirim', 'diajukan' => $perHari[$tgl]['dikirim']++,
                default        => null,
            };
        }

        /* Absensi per hari (hanya untuk sasaran satu pengguna). */
        $absensiHari = [];
        if ($sasaranId !== null) {
            $abs = Absensi::query()
                ->where('user_id', $sasaranId)
                ->whereDate('tanggal', '>=', $awal->toDateString())
                ->whereDate('tanggal', '<=', $akhir->toDateString())
                ->get(['tanggal', 'status', 'jam_masuk', 'jam_pulang']);

            foreach ($abs as $a) {
                $tgl = Carbon::parse((string) $a->tanggal)->toDateString();
                $absensiHari[$tgl] = [
                    'status'     => (string) ($a->status ?? '-'),
                    'jam_masuk'  => $a->jam_masuk,
                    'jam_pulang' => $a->jam_pulang,
                ];
            }
        }

        $hari = [];
        foreach ($perHari as $tgl => $d) {
            $hari[] = [
                'tanggal'   => $tgl,
                'jml'       => $d['jml'],
                'org'       => count($d['org']),
                'revisi'    => $d['revisi'],
                'diperiksa' => $d['diperiksa'],
                'dikirim'   => $d['dikirim'],
            ];
        }

        return response()->json([
            'sukses'          => true,
            'bulan'           => $bulan,
            'nama_bulan'      => $namaBulan,
            'awal'            => $awal->toDateString(),
            'akhir'           => $akhir->toDateString(),
            'sasaran'         => $namaSasaran,
            'sasaran_id'      => $sasaranId,
            'hari'            => $hari,
            'absensi'         => $absensiHari,
            'daftar_karyawan' => $daftarKaryawan,
        ]);
    }
}
