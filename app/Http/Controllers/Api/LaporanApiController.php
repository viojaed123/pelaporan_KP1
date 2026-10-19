<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Absensi;
use App\Models\Kasus;
use App\Models\LaporanHarian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint data mentah (JSON) untuk Power BI / external BI.
 *
 * Power BI terhubung lewat konektor "Web" (GET JSON) -- setiap endpoint
 * mengembalikan array datar yang langsung bisa dijadikan tabel. Autentikasi
 * memakai token statis (header `X-Api-Token` atau `Authorization: Bearer`)
 * yang di-set di .env sebagai POWERBI_API_TOKEN; token dapat diganti tanpa
 * menyentuh kode.
 *
 * Semua endpoint menerima filter opsional: dari, sampai (Y-m-d), user_id.
 */
class LaporanApiController extends Controller
{
    /** Token yang sah, sesuai .env POWERBI_API_TOKEN. */
    private function tokenSah(Request $request): bool
    {
        $token = (string) config('keamanan.powerbi_token', '');
        if ($token === '') {
            return false; // belum dikonfigurasi -> API nonaktif
        }
        $diberi = (string) $request->header('X-Api-Token', '');
        if ($diberi === '') {
            $auth = (string) $request->header('Authorization', '');
            if (str_starts_with($auth, 'Bearer ')) {
                $diberi = substr($auth, 7);
            }
        }
        if ($diberi === '') {
            $diberi = (string) $request->query('token', '');
        }
        return hash_equals($token, $diberi);
    }

    private function tolak(): JsonResponse
    {
        return response()->json(['ok' => false, 'pesan' => 'Token tidak sah atau belum dikonfigurasi.'], 401);
    }

    /** Filter tanggal bawaan semua endpoint. */
    private function rentang(Request $request)
    {
        $q = LaporanHarian::query();
        if ($request->filled('dari')) {
            $q->where('tanggal', '>=', $request->query('dari'));
        }
        if ($request->filled('sampai')) {
            $q->where('tanggal', '<=', $request->query('sampai'));
        }
        if ($request->filled('user_id')) {
            $q->where('user_id', (int) $request->query('user_id'));
        }
        return $q;
    }

    /** GET /api/v1/laporan — baris laporan harian lengkap. */
    public function laporan(Request $request): JsonResponse
    {
        if (!$this->tokenSah($request)) { return $this->tolak(); }
        $data = $this->rentang($request)
            ->with('user:id,nama,role')
            ->orderBy('tanggal')->orderBy('id')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'tanggal' => optional($l->tanggal)->toDateString(),
                'user_id' => $l->user_id,
                'pengguna' => $l->user->nama ?? null,
                'jam_mulai' => $l->jam_mulai,
                'jam_selesai' => $l->jam_selesai,
                'kegiatan' => $l->kegiatan,
                'lokasi_teks' => $l->lokasi_teks,
                'garis_lintang' => $l->garis_lintang,
                'garis_bujur' => $l->garis_bujur,
                'deskripsi' => $l->deskripsi,
                'hasil' => $l->hasil,
                'kendala' => $l->kendala,
                'rencana_berikutnya' => $l->rencana_berikutnya,
                'status' => $l->status,
            ]);
        return response()->json(['ok' => true, 'jumlah' => $data->count(), 'data' => $data->values()]);
    }

    /** GET /api/v1/absensi — baris absensi lengkap. */
    public function absensi(Request $request): JsonResponse
    {
        if (!$this->tokenSah($request)) { return $this->tolak(); }
        $q = Absensi::query();
        if ($request->filled('dari')) { $q->where('tanggal', '>=', $request->query('dari')); }
        if ($request->filled('sampai')) { $q->where('tanggal', '<=', $request->query('sampai')); }
        if ($request->filled('user_id')) { $q->where('user_id', (int) $request->query('user_id')); }
        $data = $q->with('user:id,nama,role')->orderBy('tanggal')->orderBy('id')->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'tanggal' => optional($a->tanggal)->toDateString(),
                'user_id' => $a->user_id,
                'pengguna' => $a->user->nama ?? null,
                'jam_masuk' => $a->jam_masuk,
                'jam_pulang' => $a->jam_pulang,
                'status' => $a->status,
                'keterangan' => $a->keterangan,
                'kegiatan_hari_ini' => $a->kegiatan_hari_ini,
                'lokasi_teks' => $a->lokasi_teks,
                'kondisi_kesehatan' => $a->kondisi_kesehatan,
            ]);
        return response()->json(['ok' => true, 'jumlah' => $data->count(), 'data' => $data->values()]);
    }

    /**
     * GET /api/v1/rekap/harian — agregasi laporan per tanggal.
     *
     * Kolom status memakai NILAI SAH laporan_harian
     * (draft/dikirim/diperiksa/perlu_revisi/diajukan). Versi lama
     * menghitung `disetujui`/`ditolak` yang tidak pernah ada di data
     * sehingga kolomnya selalu 0 -- diperbaiki 2026-10-19.
     */
    public function rekapHarian(Request $request): JsonResponse
    {
        if (!$this->tokenSah($request)) { return $this->tolak(); }
        $rows = $this->rentang($request)
            ->selectRaw(
                'tanggal, COUNT(*) as jumlah_laporan, '
                . 'SUM(status = "draft") as draft, '
                . 'SUM(status = "dikirim") as dikirim, '
                . 'SUM(status = "diperiksa") as diperiksa, '
                . 'SUM(status = "perlu_revisi") as perlu_revisi, '
                . 'SUM(status = "diajukan") as diajukan'
            )
            ->groupBy('tanggal')->orderBy('tanggal')->get()
            ->map(fn ($r) => [
                'tanggal' => optional($r->tanggal)->toDateString(),
                'jumlah_laporan' => (int) $r->jumlah_laporan,
                'draft' => (int) $r->draft,
                'dikirim' => (int) $r->dikirim,
                'diperiksa' => (int) $r->diperiksa,
                'perlu_revisi' => (int) $r->perlu_revisi,
                'diajukan' => (int) $r->diajukan,
            ]);
        return response()->json(['ok' => true, 'jumlah' => $rows->count(), 'data' => $rows->values()]);
    }

    /**
     * GET /api/v1/rekap/pengguna — agregasi laporan per pengguna.
     *
     * Menyertakan `divisi` agar Power BI bisa memotong per unit kerja
     * tanpa join tambahan. (Perbaikan status sama dengan rekap/harian.)
     */
    public function rekapPengguna(Request $request): JsonResponse
    {
        if (!$this->tokenSah($request)) { return $this->tolak(); }
        $rows = $this->rentang($request)
            ->join('users', 'users.id', '=', 'laporan_harian.user_id')
            ->selectRaw(
                'laporan_harian.user_id, users.nama as pengguna, users.divisi as divisi, '
                . 'COUNT(*) as jumlah_laporan, '
                . 'SUM(laporan_harian.status = "draft") as draft, '
                . 'SUM(laporan_harian.status = "dikirim") as dikirim, '
                . 'SUM(laporan_harian.status = "diperiksa") as diperiksa, '
                . 'SUM(laporan_harian.status = "perlu_revisi") as perlu_revisi, '
                . 'SUM(laporan_harian.status = "diajukan") as diajukan'
            )
            ->groupBy('laporan_harian.user_id', 'users.nama', 'users.divisi')
            ->orderBy('jumlah_laporan', 'desc')->get()
            ->map(fn ($r) => [
                'user_id' => (int) $r->user_id,
                'pengguna' => (string) $r->pengguna,
                'divisi' => $r->divisi !== null ? (string) $r->divisi : null,
                'jumlah_laporan' => (int) $r->jumlah_laporan,
                'draft' => (int) $r->draft,
                'dikirim' => (int) $r->dikirim,
                'diperiksa' => (int) $r->diperiksa,
                'perlu_revisi' => (int) $r->perlu_revisi,
                'diajukan' => (int) $r->diajukan,
            ]);
        return response()->json(['ok' => true, 'jumlah' => $rows->count(), 'data' => $rows->values()]);
    }

    /**
     * GET /api/v1/bi/kasus — baris kasus untuk Power BI.
     *
     * Nama PIC memakai COALESCE (pengguna terdaftar, atau teks ketik-
     * bebas yang belum cocok) -- konsisten dengan tampilan web/SPA/APK.
     * Filter: dari/sampai (tanggal pembuatan, Y-m-d), status, urgensi.
     */
    public function kasus(Request $request): JsonResponse
    {
        if (!$this->tokenSah($request)) { return $this->tolak(); }

        $q = Kasus::query()
            ->with(['penanggungJawab:id,nama', 'dibuatOleh:id,nama'])
            ->orderBy('id');

        if ($request->filled('dari')) {
            $q->whereDate('dibuat_pada', '>=', (string) $request->query('dari'));
        }
        if ($request->filled('sampai')) {
            $q->whereDate('dibuat_pada', '<=', (string) $request->query('sampai'));
        }
        if ($request->filled('status')) {
            $q->where('status_kasus', (string) $request->query('status'));
        }
        if ($request->filled('urgensi')) {
            $q->where('tingkat_urgensi', (string) $request->query('urgensi'));
        }

        $data = $q->get()
            ->map(fn (Kasus $k) => [
                'id' => (int) $k->id,
                'kode_kasus' => (string) $k->kode_kasus,
                'judul' => (string) $k->judul,
                'kategori' => $k->kategori,
                'nama_lokasi' => $k->nama_lokasi,
                'garis_lintang' => $k->garis_lintang,
                'garis_bujur' => $k->garis_bujur,
                'tingkat_urgensi' => (string) ($k->tingkat_urgensi ?? '-'),
                'status_kasus' => (string) ($k->status_kasus ?? '-'),
                'progres' => (int) ($k->progres ?? 0),
                'sumber' => $k->sumber,
                'penanggung_jawab' => $k->penanggungJawab?->nama
                    ?? ($k->penanggung_jawab_teks !== null ? (string) $k->penanggung_jawab_teks : null),
                'penanggung_jawab_terdaftar' => $k->penanggung_jawab_id !== null,
                'jumlah_anggota' => $k->anggota()->count(),
                'dibuat_oleh' => $k->dibuatOleh?->nama,
                'target_selesai' => optional($k->target_selesai)->toDateString(),
                'dibuat_pada' => optional($k->dibuat_pada)->toDateTimeString(),
                'diperbarui_pada' => optional($k->diperbarui_pada)->toDateTimeString(),
            ]);

        return response()->json(['ok' => true, 'jumlah' => $data->count(), 'data' => $data->values()]);
    }
}
