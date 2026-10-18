<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\LaporanHarian;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Asisten AI pelaporan harian.
 *
 * Dua lapis jawaban:
 *   1. ATLAS (rule + query Eloquent): pertanyaan yang mengandung angka,
 *      periode, atau nama dijawab dengan data NYATA dari database
 *      (jumlah laporan, absensi hari ini, kasus terbuka, dst.).
 *   2. LLM OPSIONAL: bila OPENAI_API_KEY terisi di .env, pertanyaan yang
 *      tidak tertangkap atlas diteruskan ke model bahasa dengan konteks
 *      ringkas + riwayat percakapan sesi, sehingga jawaban tetap relevan
 *      dengan sistem PHR. Tanpa key, fallback ke jawaban panduan statis.
 *
 * Setiap percakapan dicatat di chat_sessions/chat_messages agar konteks
 * lintas pesan dapat dipakai pada iterasi berikutnya.
 */
class AsistenController extends Controller
{
    public function index(Request $request)
    {
        return view('asisten.index', ['menuAktif' => 'asisten']);
    }

    public function kirim(Request $request): JsonResponse
    {
        $request->validate(['pesan' => 'required|string|max:2000']);
        $pesan = trim((string) $request->input('pesan'));
        $uid = (int) Auth::id();

        // Sesi percakapan per pengguna (dibuat bila belum ada).
        $sesi = ChatSession::firstOrCreate(
            ['user_id' => $uid],
            ['judul' => mb_substr($pesan, 0, 60)]
        );
        ChatMessage::create(['session_id' => $sesi->id, 'peran' => 'user', 'isi' => $pesan]);

        $riwayat = ChatMessage::where('session_id', $sesi->id)
            ->orderByDesc('id')->limit(6)->get()->reverse()->values();

        $jawaban = $this->atlas($pesan, $riwayat);
        if ($jawaban === null) {
            $jawaban = $this->lewatLlm($pesan, $riwayat) ?? $this->panduan($pesan);
        }

        ChatMessage::create(['session_id' => $sesi->id, 'peran' => 'assistant', 'isi' => $jawaban]);

        return response()->json(['sukses' => true, 'jawaban' => $jawaban]);
    }

    /* ----------------------------------------------------------------
     * Lapis 1: jawaban berbasis data nyata (Eloquent)
     * -------------------------------------------------------------- */

    /** Kembalikan jawaban dari query, atau null bila tidak tertangkap. */
    private function atlas(string $pesan, $riwayat): ?string
    {
        $q = mb_strtolower($pesan);
        $uid = (int) Auth::id();
        $role = Auth::user()->role ?? session('role') ?? 'karyawan';

        // Periode: "bulan ini", "kemarin", "7 hari terakhir", atau Y-m-d bebas.
        [$dari, $sampai] = $this->periode($q);
        $rentang = fn ($QueryBuilder) => $QueryBuilder
            ->when($dari, fn ($x) => $x->where('tanggal', '>=', $dari))
            ->when($sampai, fn ($x) => $x->where('tanggal', '<=', $sampai));

        // Jumlah / daftar laporan.
        if (preg_match('/\b(laporan|report)\b/', $q)) {
            $scope = ($role === 'karyawan')
                ? fn ($x) => $x->where('user_id', $uid)
                : ($role === 'mentor' ? fn ($x) => $x : fn ($x) => $x);
            $total = $rentang(clone LaporanHarian::query());
            $total = $scope($total)->count();
            $perStatus = $rentang(clone LaporanHarian::query());
            $perStatus = $scope($perStatus)
                ->selectRaw('status, COUNT(*) as jml')->groupBy('status')->pluck('jml', 'status');
            $baris = "Total laporan" . ($dari ? " periode $dari s/d $sampai" : '') . ": **$total**.";
            foreach ($perStatus as $st => $jml) { $baris .= "\n- $st: $jml"; }
            if (str_contains($q, 'terakhir') || str_contains($q, 'terbaru')) {
                $terakhir = $rentang(clone LaporanHarian::query());
                $terakhir = $scope($terakhir)->with('user:id,name')->latest('tanggal')->latest('id')->limit(5)->get();
                if ($terakhir->isNotEmpty()) {
                    $baris .= "\n\n5 terakhir:";
                    foreach ($terakhir as $l) {
                        $baris .= "\n- " . optional($l->tanggal)->toDateString() . " — " . $l->kegiatan
                            . " (" . ($l->user->nama ?? '-') . ", " . $l->status . ")";
                    }
                }
            }
            return $baris;
        }

        // Absensi.
        if (str_contains($q, 'absensi') || str_contains($q, 'kehadiran') || str_contains($q, 'hadir')) {
            $scope = ($role === 'karyawan')
                ? fn ($x) => $x->where('user_id', $uid)
                : fn ($x) => $x;
            $total = $rentang(clone Absensi::query());
            $total = $scope($total)->count();
            $perStatus = $rentang(clone Absensi::query());
            $perStatus = $scope($perStatus)->selectRaw('status, COUNT(*) as jml')->groupBy('status')->pluck('jml', 'status');
            $baris = "Total absensi" . ($dari ? " periode $dari s/d $sampai" : '') . ": **$total**.";
            foreach ($perStatus as $st => $jml) { $baris .= "\n- $st: $jml"; }
            return $baris;
        }

        return null;
    }

    /** Deteksi periode sederhana dari teks pertanyaan. */
    private function periode(string $q): array
    {
        $hari = date('Y-m-d');
        if (str_contains($q, 'bulan ini')) {
            return [date('Y-m-01'), $hari];
        }
        if (str_contains($q, 'kemarin')) {
            return [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))];
        }
        if (preg_match('/(\d+)\s*hari\s*terakhir/', $q, $m)) {
            return [date('Y-m-d', strtotime('-'.(int) $m[1].' day')), $hari];
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2})/', $q, $m)) {
            return [$m[1], $m[1]];
        }
        if (preg_match('/(\d{4})-(\d{2})/', $q, $m)) {
            return [$m[1].'-'.$m[2].'-01', date('Y-m-t', strtotime($m[1].'-'.$m[2].'-01'))];
        }
        return [null, null];
    }

    /* ----------------------------------------------------------------
     * Lapis 2: LLM opsional (OPENAI_API_KEY)
     * -------------------------------------------------------------- */

    private function lewatLlm(string $pesan, $riwayat): ?string
    {
        $key = (string) env('OPENAI_API_KEY', '');
        if ($key === '') { return null; }

        $system = 'Anda adalah Asisten AI Sistem Pelaporan Kegiatan Harian (PHR) '
            . config('keamanan.organisasi') . '. '
            . 'Jawab ringkas dalam Bahasa Indonesia. '
            . 'Modul: laporan harian, absensi, rekap, kasus, kalender, notifikasi, profil. '
            . 'Peran pengguna: ' . (Auth::user()->role ?? '-') . '.';

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($riwayat as $m) {
            $messages[] = ['role' => $m->peran === 'user' ? 'user' : 'assistant', 'content' => $m->isi];
        }
        $messages[] = ['role' => 'user', 'content' => $pesan];

        try {
            $res = Http::withToken($key)->timeout(20)->post('https://api.openai.com/v1/chat/completions', [
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'messages' => $messages,
                'max_tokens' => 500,
            ]);
            if ($res->ok()) {
                return (string) data_get($res->json(), 'choices.0.message.content', '');
            }
        } catch (\Throwable $e) {
            report($e);
        }
        return null;
    }

    /** Fallback statis bila atlas & LLM keduanya tidak menjawab. */
    private function panduan(string $pesan): string
    {
        $q = mb_strtolower($pesan);
        if (str_contains($q, 'profil') || str_contains($q, 'sandi') || str_contains($q, 'password')) {
            return 'Profil & Keamanan: ganti kata sandi sesuai kebijakan (min 8 karakter, tidak umum, beda dari riwayat).';
        }
        if (str_contains($q, 'kasus')) {
            return 'Pelacakan Kasus: catat, tindak lanjut, status. Minta "daftar kasus terbuka" untuk ringkasan.';
        }
        if (str_contains($q, 'impor')) {
            return 'Impor Excel: menu Impor Excel → unggah → pemetaan kolom (bisa simpan profil) → pratinjau → simpan.';
        }
        return 'Saya bisa menjawab seputar pelaporan dengan data nyata -- coba: '
            . '"berapa laporan bulan ini", "absensi kemarin", atau "ringkas 5 laporan terakhir".';
    }
}
