<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CostAvoidance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CostAvoidanceController extends Controller
{
    private const KATEGORI = [
        'Negosiasi Pengadaan',
        'Substitusi Material',
        'Optimalisasi Operasi & Pemeliharaan',
        'Penghindaran Denda & Penalti',
        'Perpanjangan Umur Aset',
        'Pengurangan Downtime',
        'Pekerjaan In-house',
        'Penghindaran Sewa & Logistik',
        'Lainnya',
    ];

    private const STATUS = ['draft', 'diajukan', 'disetujui', 'ditolak'];

    public function daftar(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = CostAvoidance::query()
            ->with(['pembuat:id,nama', 'verifikator:id,nama'])
            ->orderByDesc('periode')
            ->orderByDesc('id');

        if (! $this->peranPenuh($user)) {
            $q->where('dibuat_oleh', (int) $user->id);
        }

        if ($request->filled('status')) {
            $q->where('status', (string) $request->query('status'));
        }

        if ($request->filled('kategori')) {
            $q->where('kategori', (string) $request->query('kategori'));
        }

        if ($request->filled('bulan') && preg_match('/^\d{4}-\d{2}$/', (string) $request->query('bulan')) === 1) {
            $bulan = (string) $request->query('bulan');
            $q->whereBetween('periode', [$bulan . '-01', date('Y-m-t', (int) strtotime($bulan . '-01'))]);
        }

        $halaman = $q->paginate(min(100, (int) $request->query('per_halaman', 20)))
            ->through(fn (CostAvoidance $row) => $this->ringkas($row, $user));

        return response()->json(['sukses' => true, 'data' => $halaman]);
    }

    public function ringkasan(Request $request): JsonResponse
    {
        $user = $request->user();
        $q = CostAvoidance::query();

        if (! $this->peranPenuh($user)) {
            $q->where('dibuat_oleh', (int) $user->id);
        }

        $perStatus = (clone $q)->selectRaw('status, COUNT(*) AS jumlah, COALESCE(SUM(nilai_avoidance), 0) AS nilai')
            ->groupBy('status')
            ->get();

        $jumlah = array_fill_keys(self::STATUS, 0);
        $total = 0.0;

        foreach ($perStatus as $baris) {
            $status = (string) $baris->status;
            if (array_key_exists($status, $jumlah)) {
                $jumlah[$status] = (int) $baris->jumlah;
            }
            if ($status === 'disetujui') {
                $total = (float) $baris->nilai;
            }
        }

        $perKategori = (clone $q)->where('status', 'disetujui')
            ->selectRaw('kategori, COUNT(*) AS jumlah, COALESCE(SUM(nilai_avoidance), 0) AS nilai')
            ->groupBy('kategori')
            ->orderByDesc('nilai')
            ->get()
            ->map(fn ($baris) => [
                'nama' => (string) $baris->kategori,
                'jumlah' => (int) $baris->jumlah,
                'nilai' => (float) $baris->nilai,
            ])
            ->all();

        return response()->json([
            'sukses' => true,
            'ringkasan' => [
                'total_disetujui' => $total,
                'jumlah' => $jumlah,
                'per_kategori' => $perKategori,
                'ada' => array_sum($jumlah) > 0,
            ],
        ]);
    }

    public function detail(Request $request, int $id): JsonResponse
    {
        $row = CostAvoidance::with(['pembuat:id,nama', 'verifikator:id,nama'])->find($id);

        if ($row === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Data tidak ditemukan.'], 404);
        }

        if (! $this->bolehLihat($request->user(), $row)) {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak berhak melihat data ini.'], 403);
        }

        return response()->json(['sukses' => true, 'data' => $this->ringkas($row, $request->user())]);
    }

    public function simpan(Request $request): JsonResponse
    {
        $data = $this->validasi($request);

        $row = CostAvoidance::create([
            'kode' => $this->buatKode(),
            'judul' => $data['judul'],
            'kategori' => $data['kategori'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'unit' => $data['unit'] ?? null,
            'periode' => $data['periode'],
            'estimasi' => $data['estimasi'],
            'realisasi' => $data['realisasi'],
            'nilai_avoidance' => $data['nilai_avoidance'],
            'status' => 'draft',
            'dibuat_oleh' => (int) $request->user()->id,
        ]);

        $row->load(['pembuat:id,nama', 'verifikator:id,nama']);

        return response()->json([
            'sukses' => true,
            'pesan' => 'Data penghematan dicatat.',
            'data' => $this->ringkas($row, $request->user()),
        ], 201);
    }

    public function perbarui(Request $request, int $id): JsonResponse
    {
        $row = CostAvoidance::find($id);

        if ($row === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Data tidak ditemukan.'], 404);
        }

        if (! $this->bolehUbah($request->user(), $row)) {
            return response()->json(['sukses' => false, 'pesan' => 'Data ini tidak dapat diubah.'], 403);
        }

        $data = $this->validasi($request);

        $row->fill([
            'judul' => $data['judul'],
            'kategori' => $data['kategori'],
            'deskripsi' => $data['deskripsi'] ?? null,
            'unit' => $data['unit'] ?? null,
            'periode' => $data['periode'],
            'estimasi' => $data['estimasi'],
            'realisasi' => $data['realisasi'],
            'nilai_avoidance' => $data['nilai_avoidance'],
            'status' => 'draft',
            'catatan_verifikasi' => null,
            'diverifikasi_oleh' => null,
            'diverifikasi_pada' => null,
        ]);
        $row->save();
        $row->load(['pembuat:id,nama', 'verifikator:id,nama']);

        return response()->json([
            'sukses' => true,
            'pesan' => 'Data diperbarui.',
            'data' => $this->ringkas($row, $request->user()),
        ]);
    }

    public function ajukan(Request $request, int $id): JsonResponse
    {
        $row = CostAvoidance::find($id);

        if ($row === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Data tidak ditemukan.'], 404);
        }

        if (! $this->bolehUbah($request->user(), $row)) {
            return response()->json(['sukses' => false, 'pesan' => 'Data ini tidak dapat diajukan.'], 403);
        }

        if ((float) $row->nilai_avoidance <= 0) {
            return response()->json(['sukses' => false, 'pesan' => 'Nilai avoidance harus lebih besar dari nol.'], 422);
        }

        $row->status = 'diajukan';
        $row->diajukan_pada = now();
        $row->catatan_verifikasi = null;
        $row->save();
        $row->load(['pembuat:id,nama', 'verifikator:id,nama']);

        return response()->json([
            'sukses' => true,
            'pesan' => 'Data diajukan untuk verifikasi.',
            'data' => $this->ringkas($row, $request->user()),
        ]);
    }

    public function verifikasi(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'keputusan' => ['required', Rule::in(['setujui', 'tolak'])],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        $row = CostAvoidance::find($id);

        if ($row === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Data tidak ditemukan.'], 404);
        }

        $user = $request->user();

        if (! $this->peranPenuh($user)) {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak berhak memverifikasi data ini.'], 403);
        }

        if ((string) $row->status !== 'diajukan') {
            return response()->json(['sukses' => false, 'pesan' => 'Data ini tidak menunggu verifikasi.'], 422);
        }

        if ($user->role !== 'admin' && (int) $row->dibuat_oleh === (int) $user->id) {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak dapat memverifikasi data sendiri.'], 403);
        }

        $keputusan = (string) $request->input('keputusan');
        $catatan = trim((string) $request->input('catatan', ''));

        if ($keputusan === 'tolak' && $catatan === '') {
            return response()->json(['sukses' => false, 'pesan' => 'Catatan wajib diisi saat menolak.'], 422);
        }

        $row->status = $keputusan === 'setujui' ? 'disetujui' : 'ditolak';
        $row->catatan_verifikasi = $catatan === '' ? null : $catatan;
        $row->diverifikasi_oleh = (int) $user->id;
        $row->diverifikasi_pada = now();
        $row->save();
        $row->load(['pembuat:id,nama', 'verifikator:id,nama']);

        return response()->json([
            'sukses' => true,
            'pesan' => $keputusan === 'setujui' ? 'Data disetujui.' : 'Data ditolak.',
            'data' => $this->ringkas($row, $request->user()),
        ]);
    }

    public function hapus(Request $request, int $id): JsonResponse
    {
        $row = CostAvoidance::find($id);

        if ($row === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Data tidak ditemukan.'], 404);
        }

        if (! $this->bolehUbah($request->user(), $row)) {
            return response()->json(['sukses' => false, 'pesan' => 'Data ini tidak dapat dihapus.'], 403);
        }

        $row->delete();

        return response()->json(['sukses' => true, 'pesan' => 'Data dihapus.']);
    }

    private function validasi(Request $request): array
    {
        $data = $request->validate([
            'judul' => ['required', 'string', 'max:200'],
            'kategori' => ['required', Rule::in(self::KATEGORI)],
            'periode' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
            'estimasi' => ['required', 'numeric', 'min:0'],
            'realisasi' => ['nullable', 'numeric', 'min:0'],
            'unit' => ['nullable', 'string', 'max:120'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
        ]);

        $estimasi = (float) $data['estimasi'];
        $realisasi = (float) ($data['realisasi'] ?? 0);

        if ($realisasi > $estimasi) {
            throw ValidationException::withMessages([
                'realisasi' => 'Realisasi tidak boleh melebihi estimasi.',
            ]);
        }

        $bulan = (int) substr((string) $data['periode'], 5, 2);
        if ($bulan < 1 || $bulan > 12) {
            throw ValidationException::withMessages([
                'periode' => 'Periode tidak valid.',
            ]);
        }

        $data['periode'] = substr((string) $data['periode'], 0, 7) . '-01';
        $data['estimasi'] = $estimasi;
        $data['realisasi'] = $realisasi;
        $data['nilai_avoidance'] = max(0, $estimasi - $realisasi);

        return $data;
    }

    private function ringkas(CostAvoidance $row, ?object $user = null): array
    {
        $bolehVerifikasi = $user !== null
            && $this->peranPenuh($user)
            && (string) $row->status === 'diajukan'
            && ((string) $user->role === 'admin' || (int) $row->dibuat_oleh !== (int) $user->id);

        return [
            'id' => (int) $row->id,
            'kode' => (string) $row->kode,
            'judul' => (string) $row->judul,
            'kategori' => (string) $row->kategori,
            'deskripsi' => $row->deskripsi,
            'unit' => $row->unit,
            'periode' => $row->periode?->format('Y-m'),
            'estimasi' => (float) $row->estimasi,
            'realisasi' => (float) $row->realisasi,
            'nilai_avoidance' => (float) $row->nilai_avoidance,
            'status' => (string) $row->status,
            'catatan_verifikasi' => $row->catatan_verifikasi,
            'pembuat' => $row->pembuat?->nama,
            'verifikator' => $row->verifikator?->nama,
            'diajukan_pada' => $row->diajukan_pada?->toDateTimeString(),
            'diverifikasi_pada' => $row->diverifikasi_pada?->toDateTimeString(),
            'boleh_ubah' => $user !== null && $this->bolehUbah($user, $row),
            'boleh_verifikasi' => $bolehVerifikasi,
        ];
    }

    private function peranPenuh(object $user): bool
    {
        return in_array($user->role, ['mentor', 'admin'], true);
    }

    private function bolehLihat(object $user, CostAvoidance $row): bool
    {
        return $this->peranPenuh($user) || (int) $row->dibuat_oleh === (int) $user->id;
    }

    private function bolehUbah(object $user, CostAvoidance $row): bool
    {
        if (! in_array((string) $row->status, ['draft', 'ditolak'], true)) {
            return false;
        }

        return (int) $row->dibuat_oleh === (int) $user->id || $user->role === 'admin';
    }

    private function buatKode(): string
    {
        $awalan = 'CA-' . now()->format('Y') . '-';
        $terakhir = CostAvoidance::where('kode', 'like', $awalan . '%')->orderByDesc('kode')->value('kode');
        $urut = is_string($terakhir) ? (int) substr($terakhir, -4) : 0;

        return $awalan . str_pad((string) ($urut + 1), 4, '0', STR_PAD_LEFT);
    }
}
