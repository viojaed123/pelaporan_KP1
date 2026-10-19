<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Kasus;
use App\Models\LaporanHarian;
use App\Models\Tugas;
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

    /** Status kasus yang sah (pilihan legacy includes/kasus.php). */
    private const STATUS_KASUS = ['Terbuka', 'Dalam Proses', 'Selesai'];

    /** Tugas yang masih aktif (konsisten RingkasanController). */
    private const TUGAS_AKTIF = ['baru', 'dikerjakan'];

    /* =================================================================
     * DASBOR (semua peran -- penskalaan otomatis di dalam)
     *
     * Satu panggilan untuk kartu + grafik halaman beranda SPA:
     *   - karyawan   : datanya sendiri (laporan, absensi, kasus
     *                  yang melibatkannya, tugasnya);
     *   - mentor/admin: seluruh data (kartu global, tren, perlu-revisi).
     * =============================================================== */

    public function dasbor(Request $request): JsonResponse
    {
        $user     = $request->user();
        $peran    = (string) $user->role;
        $karyawan = $peran === 'karyawan';
        $uid      = (int) $user->id;

        $hariIni   = now()->toDateString();
        $awalBulan = now()->startOfMonth()->toDateString();
        $mulaiTren = now()->subDays(29)->toDateString();

        /* --- Laporan: tren 30 hari + status bulan berjalan --- */
        $tren = [];
        for ($i = 29; $i >= 0; $i--) {
            $tren[now()->subDays($i)->toDateString()] = array_fill_keys(self::STATUS_LAPORAN, 0) + ['total' => 0];
        }

        $bulanStatus = array_fill_keys(self::STATUS_LAPORAN, 0);
        $laporanTren = LaporanHarian::query()
            ->when($karyawan, fn ($q) => $q->where('user_id', $uid))
            ->whereDate('tanggal', '>=', $mulaiTren)
            ->get(['tanggal', 'status']);

        foreach ($laporanTren as $l) {
            $tgl = Carbon::parse((string) $l->tanggal)->toDateString();
            $st  = (string) $l->status;
            if (isset($tren[$tgl])) {
                $tren[$tgl]['total']++;
                if (isset($tren[$tgl][$st])) {
                    $tren[$tgl][$st]++;
                }
            }
            if ($tgl >= $awalBulan && isset($bulanStatus[$st])) {
                $bulanStatus[$st]++;
            }
        }

        /* --- Absensi: hari ini + bulan berjalan --- */
        $absensiHari = $this->hitungStatusAbsensi(
            Absensi::query()
                ->when($karyawan, fn ($q) => $q->where('user_id', $uid))
                ->whereDate('tanggal', $hariIni)
        );
        $absensiBulan = $this->hitungStatusAbsensi(
            Absensi::query()
                ->when($karyawan, fn ($q) => $q->where('user_id', $uid))
                ->whereDate('tanggal', '>=', $awalBulan)
        );
        $persenHadir = $absensiBulan['total'] > 0
            ? (int) round($absensiBulan['hadir'] / $absensiBulan['total'] * 100)
            : 0;

        /* --- Kasus (karyawan: yang melibatkannya) --- */
        $kasusQ = Kasus::query();
        if ($karyawan) {
            $kasusQ->where(function ($sub) use ($uid) {
                $sub->where('dibuat_oleh', $uid)
                    ->orWhere('penanggung_jawab_id', $uid)
                    ->orWhereIn('id', function ($inner) use ($uid) {
                        $inner->select('kasus_id')->from('kasus_penugasan')->where('user_id', $uid);
                    });
            });
        }
        $kasusRows = $kasusQ->get(['status_kasus', 'tingkat_urgensi', 'progres']);

        $kasusStatus = array_fill_keys(self::STATUS_KASUS, 0);
        $kasusUrgensi = [];
        $progresJumlah = 0;
        foreach ($kasusRows as $k) {
            $st = (string) $k->status_kasus;
            $kasusStatus[$st] = ($kasusStatus[$st] ?? 0) + 1;
            $u = (string) ($k->tingkat_urgensi ?? 'Lainnya');
            $kasusUrgensi[$u] = ($kasusUrgensi[$u] ?? 0) + 1;
            $progresJumlah += (int) $k->progres;
        }
        $kasusTotal = $kasusRows->count();
        $kasusProgresRata = $kasusTotal > 0 ? (int) round($progresJumlah / $kasusTotal) : 0;

        /* --- Tugas aktif --- */
        $tugasAktif = Tugas::query()
            ->when($karyawan, fn ($q) => $q->where('user_id', $uid))
            ->whereIn('status', self::TUGAS_AKTIF)
            ->count();
        $tugasTerlambat = Tugas::query()
            ->when($karyawan, fn ($q) => $q->where('user_id', $uid))
            ->whereIn('status', self::TUGAS_AKTIF)
            ->whereDate('tenggat', '<', $hariIni)
            ->count();

        /* --- Kartu --- */
        $kartu = [
            'laporan_bulan_ini'  => (int) array_sum($bulanStatus),
            'tugas_aktif'        => $tugasAktif,
            'tugas_terlambat'    => $tugasTerlambat,
            'kasus_total'        => $kasusTotal,
            'kasus_terbuka'      => ($kasusStatus['Terbuka'] ?? 0) + ($kasusStatus['Dalam Proses'] ?? 0),
            'kasus_progres_rata' => $kasusProgresRata,
            'absensi_hari_ini'   => $absensiHari,
            'persen_hadir_bulan' => $persenHadir,
        ];

        /* --- Tambahan mentor/admin (global) --- */
        $perluRevisi = collect();
        if (! $karyawan) {
            $kartu['perlu_revisi_bulan_ini'] = LaporanHarian::whereDate('tanggal', '>=', $awalBulan)
                ->where('status', 'perlu_revisi')->count();
            $kartu['karyawan_total']        = User::where('role', 'karyawan')->count();
            $kartu['karyawan_hadir_hari']   = $absensiHari['hadir'];

            $perluRevisi = LaporanHarian::query()
                ->join('users', 'users.id', '=', 'laporan_harian.user_id')
                ->where('laporan_harian.status', 'perlu_revisi')
                ->orderByDesc('laporan_harian.tanggal')->orderByDesc('laporan_harian.id')
                ->limit(8)
                ->get(['laporan_harian.tanggal', 'laporan_harian.kegiatan', 'users.nama'])
                ->map(fn ($r) => [
                    'tanggal'  => optional($r->tanggal)->toDateString(),
                    'kegiatan' => (string) $r->kegiatan,
                    'karyawan' => (string) $r->nama,
                ])
                ->values();
        }

        return response()->json([
            'sukses'    => true,
            'peran'     => $peran,
            'hari_ini'  => $hariIni,
            'kartu'     => $kartu,
            'tren'      => collect($tren)
                ->map(fn ($v, $tgl) => ['tanggal' => $tgl] + $v)
                ->values(),
            'donut_status' => collect($bulanStatus)
                ->map(fn ($jml, $st) => ['status' => $st, 'jumlah' => $jml])
                ->values(),
            'kasus'     => [
                'total'        => $kasusTotal,
                'per_status'   => $kasusStatus,
                'per_urgensi'  => $kasusUrgensi,
                'progres_rata' => $kasusProgresRata,
            ],
            'kehadiran_bulan' => $absensiBulan,
            'perlu_revisi'    => $perluRevisi,
        ]);
    }

    /** Hitung jumlah absensi per status dari query yang sudah difilter. */
    private function hitungStatusAbsensi($q): array
    {
        $hasil = ['total' => 0, 'hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
        foreach ($q->selectRaw('status, COUNT(*) as jumlah')->groupBy('status')->get() as $row) {
            $kunci = strtolower((string) $row->status);
            $hasil['total'] += (int) $row->jumlah;
            if (array_key_exists($kunci, $hasil)) {
                $hasil[$kunci] = (int) $row->jumlah;
            }
        }

        return $hasil;
    }

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
                'tanggal'  => optional($r->tanggal)->toDateString(),
                'kegiatan' => (string) $r->kegiatan,
                'status'   => (string) ($r->status ?? '-'),
                'karyawan' => (string) $r->karyawan,
                'divisi'   => $r->divisi !== null && $r->divisi !== '' ? (string) $r->divisi : null,
            ]);

        /* Agregat per tanggal (bahan grafik tren di SPA). */
        $agregatHarian = [];
        foreach ((clone $dasar())->select(['laporan_harian.tanggal', 'laporan_harian.status'])->get() as $r) {
            $tgl = optional($r->tanggal)->toDateString();
            if (! isset($agregatHarian[$tgl])) {
                $agregatHarian[$tgl] = ['tanggal' => $tgl, 'total' => 0] + array_fill_keys(self::STATUS_LAPORAN, 0);
            }
            $agregatHarian[$tgl]['total']++;
            $st = (string) $r->status;
            if (isset($agregatHarian[$tgl][$st])) {
                $agregatHarian[$tgl][$st]++;
            }
        }
        ksort($agregatHarian);

        /* Agregat per karyawan (performa & tingkat revisi).
           PENTING: query dasar sudah punya select(), maka kolom harus
           diganti lewat select() -- get($columns) diabaikan Eloquent
           bila select sudah terisi. */
        $perPengguna = [];
        foreach ((clone $dasar())->select([
            'laporan_harian.user_id', 'laporan_harian.status', 'users.nama as karyawan', 'users.divisi',
        ])->get() as $r) {
            $id = (int) $r->user_id;
            if (! isset($perPengguna[$id])) {
                $perPengguna[$id] = [
                    'user_id'    => $id,
                    'karyawan'   => (string) $r->karyawan,
                    'divisi'     => $r->divisi !== null && $r->divisi !== '' ? (string) $r->divisi : null,
                    'total'      => 0,
                    'diperiksa'  => 0,
                    'perlu_revisi' => 0,
                ];
            }
            $perPengguna[$id]['total']++;
            $st = (string) $r->status;
            if (isset($perPengguna[$id][$st])) {
                $perPengguna[$id][$st]++;
            }
        }
        foreach ($perPengguna as &$p) {
            $p['persen_revisi'] = $p['total'] > 0
                ? (int) round($p['perlu_revisi'] / $p['total'] * 100)
                : 0;
        }
        unset($p);
        uasort($perPengguna, fn ($a, $b) => $b['total'] <=> $a['total']);

        return response()->json([
            'sukses'          => true,
            'ringkasan'       => $ringkasan,
            'agregat_harian'  => array_values($agregatHarian),
            'per_pengguna'    => array_values($perPengguna),
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
                $total = (int) $stat->sum();

                return [
                    'user_id'       => (int) $u->id,
                    'nama'          => (string) $u->nama,
                    'email'         => (string) $u->email,
                    'total_catatan' => $total,
                    'hadir'         => $ambil('hadir'),
                    'izin'          => $ambil('izin'),
                    'sakit'         => $ambil('sakit'),
                    'alpa'          => $ambil('alpa'),
                    'persen_hadir'  => $total > 0 ? (int) round($ambil('hadir') / $total * 100) : 0,
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
     * REKAP KASUS (mentor/admin)
     * =============================================================== */

    public function rekapKasus(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal_mulai' => ['nullable', 'date'],
            'tanggal_akhir' => ['nullable', 'date'],
            'status'        => ['nullable', 'string', 'max:30'],
            'urgensi'       => ['nullable', 'string', 'max:30'],
            'kategori'      => ['nullable', 'string', 'max:60'],
        ]);

        $dasar = fn () => $this->queryRekapKasus($request);
        $hariIni = now()->toDateString();

        /* --- Agregat (kasus umumnya sedikit; scan penuh aman) --- */
        $ringkasan = [
            'total' => 0, 'terbuka' => 0, 'dalam_proses' => 0, 'selesai' => 0,
            'terlambat' => 0, 'tanpa_pic' => 0, 'progres_rata' => 0,
        ];
        $perUrgensi  = [];
        $perKategori = [];
        $perPj       = [];
        $progresJumlah = 0;

        $baris = $dasar()->with('penanggungJawab:id,nama')->get([
            'kasus.id', 'kasus.tingkat_urgensi', 'kasus.status_kasus', 'kasus.progres',
            'kasus.kategori', 'kasus.target_selesai', 'kasus.penanggung_jawab_id',
            'kasus.penanggung_jawab_teks',
        ]);

        foreach ($baris as $k) {
            $ringkasan['total']++;

            $st = (string) ($k->status_kasus ?? '-');
            match ($st) {
                'Terbuka'      => $ringkasan['terbuka']++,
                'Dalam Proses' => $ringkasan['dalam_proses']++,
                'Selesai'      => $ringkasan['selesai']++,
                default        => null,
            };

            $progres = (int) ($k->progres ?? 0);
            $progresJumlah += $progres;

            if ($st !== 'Selesai'
                && $k->target_selesai !== null
                && $k->target_selesai->toDateString() < $hariIni) {
                $ringkasan['terlambat']++;
            }

            /* Tanpa PIC = tidak ada nama sama sekali (baik id pengguna
               maupun teks ketik-bebas masih kosong). */
            if ($k->penanggung_jawab_id === null
                && ($k->penanggung_jawab_teks === null || $k->penanggung_jawab_teks === '')) {
                $ringkasan['tanpa_pic']++;
            }

            $u = (string) ($k->tingkat_urgensi ?? '-');
            $perUrgensi[$u] = ($perUrgensi[$u] ?? 0) + 1;

            $kat = $k->kategori !== null && $k->kategori !== '' ? (string) $k->kategori : 'Tanpa Kategori';
            $perKategori[$kat] = ($perKategori[$kat] ?? 0) + 1;

            $namaPj = $k->penanggungJawab?->nama
                ?? ($k->penanggung_jawab_teks !== null && $k->penanggung_jawab_teks !== ''
                    ? (string) $k->penanggung_jawab_teks
                    : 'Belum Ditentukan');
            if (! isset($perPj[$namaPj])) {
                $perPj[$namaPj] = [
                    'nama'       => $namaPj,
                    'terdaftar'  => $k->penanggung_jawab_id !== null,
                    'jumlah'     => 0,
                    'progres'    => 0,
                ];
            }
            $perPj[$namaPj]['jumlah']++;
            $perPj[$namaPj]['progres'] += $progres;
        }

        $ringkasan['progres_rata'] = $ringkasan['total'] > 0
            ? (int) round($progresJumlah / $ringkasan['total'])
            : 0;

        foreach ($perPj as &$p) {
            $p['progres_rata'] = $p['jumlah'] > 0 ? (int) round($p['progres'] / $p['jumlah']) : 0;
            unset($p['progres']);
        }
        unset($p);
        uasort($perPj, fn ($a, $b) => $b['jumlah'] <=> $a['jumlah']);

        $urutanUrgensi = ['Kritis', 'Tinggi', 'Sedang', 'Rendah'];
        uksort($perUrgensi, fn ($a, $b) =>
            array_search($a, $urutanUrgensi, true) <=> array_search($b, $urutanUrgensi, true));

        /* --- Daftar berhalaman --- */
        $halaman = $dasar()
            ->with(['penanggungJawab:id,nama', 'dibuatOleh:id,nama'])
            ->orderByDesc('kasus.id')
            ->paginate(min(100, max(1, (int) $request->query('per_halaman', 50))))
            ->through(fn (Kasus $k) => [
                'id'           => (int) $k->id,
                'kode'         => (string) $k->kode_kasus,
                'judul'        => (string) $k->judul,
                'kategori'     => $k->kategori,
                'urgensi'      => (string) ($k->tingkat_urgensi ?? '-'),
                'status'       => (string) ($k->status_kasus ?? '-'),
                'progres'      => (int) ($k->progres ?? 0),
                'lokasi'       => $k->nama_lokasi,
                'penanggung_jawab' => $k->penanggungJawab?->nama
                    ?? ($k->penanggung_jawab_teks !== null ? (string) $k->penanggung_jawab_teks : null),
                'target_selesai' => $k->target_selesai?->toDateString(),
                'jumlah_anggota' => $k->anggota()->count(),
                'dibuat_pada'  => optional($k->dibuat_pada)->toDateString(),
            ]);

        return response()->json([
            'sukses'      => true,
            'ringkasan'   => $ringkasan,
            'per_urgensi' => collect($perUrgensi)
                ->map(fn ($jml, $u) => ['urgensi' => $u, 'jumlah' => $jml])
                ->values(),
            'per_kategori' => collect($perKategori)
                ->map(fn ($jml, $kat) => ['kategori' => $kat, 'jumlah' => $jml])
                ->values(),
            'per_penanggung_jawab' => array_values($perPj),
            'data'        => $halaman,
        ]);
    }

    /** Query dasar rekap kasus (filter identik untuk agregat & daftar). */
    private function queryRekapKasus(Request $request)
    {
        $q = Kasus::query();

        if ($request->filled('tanggal_mulai')) {
            $q->whereDate('kasus.dibuat_pada', '>=', (string) $request->query('tanggal_mulai'));
        }
        if ($request->filled('tanggal_akhir')) {
            $q->whereDate('kasus.dibuat_pada', '<=', (string) $request->query('tanggal_akhir'));
        }
        if ($request->filled('status')) {
            $q->where('kasus.status_kasus', (string) $request->query('status'));
        }
        if ($request->filled('urgensi')) {
            $q->where('kasus.tingkat_urgensi', (string) $request->query('urgensi'));
        }
        if ($request->filled('kategori')) {
            $q->where('kasus.kategori', (string) $request->query('kategori'));
        }

        return $q;
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
