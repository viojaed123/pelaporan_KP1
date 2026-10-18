<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kasus;
use App\Services\CocokPengguna;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API kasus untuk klien mobile: daftar, detail, buat, perbarui.
 *
 * PIC & anggota diketik BEBAS (lihat KasusController versi web dan
 * CocokPengguna) -- API menerima teks yang sama dan mencocokkannya ke
 * pengguna terdaftar secara otomatis.
 *
 * Visibilitas (porting aturan legacy includes/kasus.php):
 *   - mentor/admin  : semua kasus;
 *   - karyawan       : hanya kasus yang melibatkannya (pembuat,
 *                      penanggung jawab, atau anggota tim).
 */
class KasusController extends Controller
{
    public function __construct(private readonly CocokPengguna $cocok)
    {
    }

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

    /** Buat kasus baru dari klien (teks bebas PIC & anggota). */
    public function simpan(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! in_array($user->role, ['mentor', 'admin'], true)) {
            return response()->json([
                'sukses' => false,
                'pesan'  => 'Hanya mentor/admin yang dapat membuat kasus.',
            ], 403);
        }

        $data = $this->validasi($request);

        $pj = $this->cocok->cocok((string) ($data['penanggung_jawab'] ?? ''));

        $kasus = Kasus::create([
            'kode_kasus'            => $this->buatKode(),
            'judul'                 => $data['judul'],
            'deskripsi'             => $data['deskripsi'] ?? null,
            'nama_lokasi'           => $data['nama_lokasi'] ?? null,
            'garis_lintang'         => $data['garis_lintang'] ?? null,
            'garis_bujur'           => $data['garis_bujur'] ?? null,
            'tingkat_urgensi'       => $data['tingkat_urgensi'],
            'status_kasus'          => $data['status_kasus'],
            'progres'               => $data['progres'],
            'kategori'              => $data['kategori'] ?? null,
            'sumber'                => 'Manual',
            'target_selesai'        => $data['target_selesai'] ?? null,
            'dibuat_oleh'           => (int) $user->id,
            'penanggung_jawab_id'   => $pj['user_id'],
            'penanggung_jawab_teks' => $pj['teks'] ?: null,
        ]);

        $this->sinkronTim($kasus, (string) ($data['anggota'] ?? ''), (int) $user->id);

        \App\Models\KasusStatusLog::create([
            'kasus_id'    => (int) $kasus->id,
            'user_id'     => (int) $user->id,
            'status_lama' => null,
            'status_baru' => (string) $kasus->status_kasus,
            'progres'     => (int) $kasus->progres,
            'catatan'     => 'Kasus dibuat',
        ]);

        return response()->json([
            'sukses' => true,
            'pesan'  => 'Kasus ' . $kasus->kode_kasus . ' berhasil dibuat.',
            'kasus'  => $this->ringkas($kasus, true),
        ], 201);
    }

    /** Perbarui kasus (hanya pembuat atau mentor/admin). */
    public function perbarui(Request $request, int $id): JsonResponse
    {
        $kasus = Kasus::find($id);

        if ($kasus === null) {
            return response()->json(['sukses' => false, 'pesan' => 'Kasus tidak ditemukan.'], 404);
        }

        $user = $request->user();

        if (! in_array($user->role, ['mentor', 'admin'], true)
            && (int) $kasus->dibuat_oleh !== (int) $user->id) {
            return response()->json(['sukses' => false, 'pesan' => 'Anda tidak berhak mengubah kasus ini.'], 403);
        }

        $data = $this->validasi($request);
        $statusLama  = (string) $kasus->status_kasus;
        $progresLama = (int) $kasus->progres;
        $pj = $this->cocok->cocok((string) ($data['penanggung_jawab'] ?? ''));

        $kasus->fill([
            'judul'                 => $data['judul'],
            'deskripsi'             => $data['deskripsi'] ?? null,
            'nama_lokasi'           => $data['nama_lokasi'] ?? null,
            'garis_lintang'         => $data['garis_lintang'] ?? null,
            'garis_bujur'           => $data['garis_bujur'] ?? null,
            'tingkat_urgensi'       => $data['tingkat_urgensi'],
            'status_kasus'          => $data['status_kasus'],
            'progres'               => $data['progres'],
            'kategori'              => $data['kategori'] ?? null,
            'target_selesai'        => $data['target_selesai'] ?? null,
            'penanggung_jawab_id'   => $pj['user_id'],
            'penanggung_jawab_teks' => $pj['teks'] ?: null,
        ])->save();

        $this->sinkronTim($kasus, (string) ($data['anggota'] ?? ''), (int) $user->id);

        if ($statusLama !== (string) $kasus->status_kasus || $progresLama !== (int) $kasus->progres) {
            \App\Models\KasusStatusLog::create([
                'kasus_id'    => (int) $kasus->id,
                'user_id'     => (int) $user->id,
                'status_lama' => $statusLama,
                'status_baru' => (string) $kasus->status_kasus,
                'progres'     => (int) $kasus->progres,
                'catatan'     => null,
            ]);
        }

        return response()->json([
            'sukses' => true,
            'pesan'  => 'Kasus berhasil diperbarui.',
            'kasus'  => $this->ringkas($kasus, true),
        ]);
    }

    /* ================================================================= */

    private function validasi(Request $request): array
    {
        return $request->validate([
            'judul'            => ['required', 'string', 'max:200'],
            'deskripsi'        => ['nullable', 'string'],
            'nama_lokasi'      => ['nullable', 'string', 'max:200'],
            'garis_lintang'    => ['nullable', 'numeric', 'between:-90,90'],
            'garis_bujur'      => ['nullable', 'numeric', 'between:-180,180'],
            'tingkat_urgensi'  => ['required', 'string', 'max:20'],
            'status_kasus'     => ['required', 'string', 'max:20'],
            'progres'          => ['required', 'integer', 'between:0,100'],
            'kategori'         => ['nullable', 'string', 'max:60'],
            'target_selesai'   => ['nullable', 'date'],
            'penanggung_jawab' => ['nullable', 'string', 'max:150'],
            'anggota'          => ['nullable', 'string'],
        ]);
    }

    /** Sinkron anggota tim dari kotak teks (hapus yang tak disebut). */
    private function sinkronTim(Kasus $kasus, string $kotak, int $oleh): void
    {
        $barisCocok = $this->cocok->cocokBanyak($kotak);

        $disebut = [];
        foreach ($barisCocok as $b) {
            $disebut[] = $b['teks'];
        }

        foreach ($kasus->anggota as $a) {
            if (! in_array((string) $a->nama_teks, $disebut, true)) {
                $a->delete();
            }
        }

        $ada = $kasus->anggota()->pluck('nama_teks')->all();
        foreach ($barisCocok as $b) {
            if (in_array($b['teks'], $ada, true)) {
                continue;
            }
            \App\Models\KasusPenugasan::create([
                'kasus_id'        => (int) $kasus->id,
                'user_id'         => $b['user_id'],
                'nama_teks'       => $b['teks'],
                'peran'           => 'Anggota Tim',
                'ditugaskan_oleh' => $oleh,
            ]);
        }
    }

    /** Kode kasus unik: KAS-Ymd-XXXX. */
    private function buatKode(): string
    {
        $awalan = 'KAS-' . now()->format('Ymd') . '-';

        for ($i = 0; $i < 6; $i++) {
            $kode = $awalan . strtoupper(bin2hex(random_bytes(2)));
            if (! Kasus::where('kode_kasus', $kode)->exists()) {
                return $kode;
            }
        }

        return $awalan . strtoupper(bin2hex(random_bytes(3)));
    }

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
            'id'           => (int) $k->id,
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
            $data['penanggung_jawab'] = $k->penanggungJawab?->nama
                ?? (string) ($k->penanggung_jawab_teks ?? '-');
            $data['penanggung_jawab_terdaftar'] = $k->penanggung_jawab_id !== null;
            $data['dibuat_oleh']    = $k->dibuatOleh?->nama;
            $data['kategori']       = $k->kategori;
            $data['target_selesai'] = $k->target_selesai?->format('Y-m-d');
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
