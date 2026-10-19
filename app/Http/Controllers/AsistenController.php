<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Absensi;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Jadwal;
use App\Models\Kasus;
use App\Models\LaporanHarian;
use App\Models\Tugas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Asisten AI pelaporan harian.
 *
 * Dua lapis jawaban:
 *   1. SKILL ATLAS (registry): skill berbasis query Eloquent yang
 *      menjawab dengan data NYATA dari database -- laporan, absensi,
 *      kasus, tugas, agenda jadwal, rekap karyawan (mentor/admin),
 *      ringkasan gabungan, dan bantuan (daftar skill).
 *   2. LLM OPSIONAL: bila OPENAI_API_KEY terisi di .env, pertanyaan yang
 *      tidak tertangkap skill diteruskan ke model bahasa dengan konteks
 *      ringkas + riwayat percakapan sesi, sehingga jawaban tetap relevan
 *      dengan sistem PHR. Tanpa key, fallback ke jawaban panduan statis.
 *
 * Setiap percakapan dicatat di chat_sessions/chat_messages agar konteks
 * lintas pesan dapat dipakai pada iterasi berikutnya.
 */
class AsistenController extends Controller
{
    /**
     * Nama skill atlas, sesuai urutan deteksi (khusus → umum).
     * `bantuan` sengaja paling awal agar pengguna bisa bertanya
     * "skill apa saja yang kamu punya".
     */
    public const SKILLS = ['bantuan', 'laporan', 'absensi', 'kasus', 'tugas', 'agenda', 'rekap', 'ringkasan'];
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
     * Lapis 1: SKILL ATLAS (registry berbasis Eloquent)
     *
     * Setiap skill = deteksi kata kunci + jawaban dari query nyata.
     * Urutan deteksi: bantuan → laporan → absensi → kasus → tugas →
     * agenda → rekap → ringkasan (kata kunci spesifik menang dulu,
     * mis. "ringkas 5 laporan terakhir" tetap ke skill laporan).
     * -------------------------------------------------------------- */

    /** Kembalikan jawaban dari skill, atau null bila tidak ada yang cocok. */
    private function atlas(string $pesan, $riwayat): ?string
    {
        $q    = mb_strtolower($pesan);
        $uid  = (int) Auth::id();
        $role = (string) (Auth::user()->role ?? session('role') ?? 'karyawan');

        $skills = [
            'bantuan' => [
                'aktif'    => preg_match('/\b(bantuan|bisa apa|skill|cara pakai)\b/', $q) === 1,
                'jalankan' => fn () => $this->skillBantuan(),
            ],
            'laporan' => [
                'aktif'    => preg_match('/\b(laporan|report)\b/', $q) === 1,
                'jalankan' => fn () => $this->skillLaporan($q, $uid, $role),
            ],
            'absensi' => [
                'aktif'    => str_contains($q, 'absensi') || str_contains($q, 'kehadiran') || str_contains($q, 'hadir'),
                'jalankan' => fn () => $this->skillAbsensi($q, $uid, $role),
            ],
            'kasus' => [
                'aktif'    => str_contains($q, 'kasus'),
                'jalankan' => fn () => $this->skillKasus($q, $uid, $role),
            ],
            'tugas' => [
                'aktif'    => str_contains($q, 'tugas'),
                'jalankan' => fn () => $this->skillTugas($q, $uid, $role),
            ],
            'agenda' => [
                'aktif'    => str_contains($q, 'agenda')
                    || str_contains($q, 'jadwal'),
                'jalankan' => fn () => $this->skillAgenda($q, $uid, $role),
            ],
            'rekap' => [
                'aktif'    => str_contains($q, 'rekap')
                    || str_contains($q, 'kinerja')
                    || str_contains($q, 'performa'),
                'jalankan' => fn () => $this->skillRekap($q, $uid, $role),
            ],
            'ringkasan' => [
                'aktif'    => str_contains($q, 'ringkas') || str_contains($q, 'summary'),
                'jalankan' => fn () => $this->skillRingkasan($q, $uid, $role),
            ],
        ];

        foreach ($skills as $skill) {
            if ($skill['aktif']) {
                return $skill['jalankan']();
            }
        }

        return null;
    }

    /* ---- Skill: bantuan (daftar kemampuan) ---- */

    private function skillBantuan(): string
    {
        return 'Daftar skill bantuan -- saya menjawab dengan data NYATA dari sistem:'
            . "\n- *laporan*: \"berapa laporan bulan ini\", \"ringkas 5 laporan terakhir\""
            . "\n- *absensi*: \"absensi kemarin\", \"kehadiran 7 hari terakhir\""
            . "\n- *kasus*: \"berapa kasus terbuka\", \"kasus terakhir\", \"kasus urgensi kritis\""
            . "\n- *tugas*: \"tugas saya\", \"tugas terlambat\", \"tugas terakhir\""
            . "\n- *agenda*: \"jadwal hari ini\", \"agenda hari ini\""
            . "\n- *rekap karyawan*: \"rekap karyawan bulan ini\" (khusus mentor/admin)"
            . "\n- *ringkasan*: \"ringkas 7 hari terakhir\" (laporan + absensi + kasus + tugas)"
            . "\nPeriode yang saya pahami: \"bulan ini\", \"kemarin\", \"N hari terakhir\","
            . ' atau tanggal seperti "2026-10-19".';
    }

    /* ---- Skill: laporan ---- */

    private function skillLaporan(string $q, int $uid, string $role): string
    {
        [$dari, $sampai] = $this->periode($q);
        $rentang = fn ($QueryBuilder) => $QueryBuilder
            ->when($dari, fn ($x) => $x->where('tanggal', '>=', $dari))
            ->when($sampai, fn ($x) => $x->where('tanggal', '<=', $sampai));

        $scope = ($role === 'karyawan')
            ? fn ($x) => $x->where('user_id', $uid)
            : fn ($x) => $x;
        $total = $rentang(clone LaporanHarian::query());
        $total = $scope($total)->count();
        $perStatus = $rentang(clone LaporanHarian::query());
        $perStatus = $scope($perStatus)
            ->selectRaw('status, COUNT(*) as jml')->groupBy('status')->pluck('jml', 'status');
        $baris = 'Total laporan'
            . ($role === 'karyawan' ? ' Anda' : '')
            . ($dari ? " periode $dari s/d $sampai" : '') . ": **$total**.";
        foreach ($perStatus as $st => $jml) { $baris .= "\n- $st: $jml"; }
        if (str_contains($q, 'terakhir') || str_contains($q, 'terbaru')) {
            $terakhir = $rentang(clone LaporanHarian::query());
            $terakhir = $scope($terakhir)->with('user:id,nama')->latest('tanggal')->latest('id')->limit(5)->get();
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

    /* ---- Skill: absensi ---- */

    private function skillAbsensi(string $q, int $uid, string $role): string
    {
        [$dari, $sampai] = $this->periode($q);
        $rentang = fn ($QueryBuilder) => $QueryBuilder
            ->when($dari, fn ($x) => $x->where('tanggal', '>=', $dari))
            ->when($sampai, fn ($x) => $x->where('tanggal', '<=', $sampai));

        $scope = ($role === 'karyawan')
            ? fn ($x) => $x->where('user_id', $uid)
            : fn ($x) => $x;
        $total = $rentang(clone Absensi::query());
        $total = $scope($total)->count();
        $perStatus = $rentang(clone Absensi::query());
        $perStatus = $scope($perStatus)->selectRaw('status, COUNT(*) as jml')->groupBy('status')->pluck('jml', 'status');
        $baris = 'Total absensi'
            . ($role === 'karyawan' ? ' Anda' : '')
            . ($dari ? " periode $dari s/d $sampai" : '') . ": **$total**.";
        foreach ($perStatus as $st => $jml) { $baris .= "\n- $st: $jml"; }

        return $baris;
    }

    /* ---- Skill: kasus ---- */

    private function skillKasus(string $q, int $uid, string $role): string
    {
        [$dari, $sampai] = $this->periode($q);

        $dasar = Kasus::query();
        if ($role === 'karyawan') {
            $dasar->where(function ($sub) use ($uid) {
                $sub->where('dibuat_oleh', $uid)
                    ->orWhere('penanggung_jawab_id', $uid)
                    ->orWhereIn('id', function ($inner) use ($uid) {
                        $inner->select('kasus_id')->from('kasus_penugasan')->where('user_id', $uid);
                    });
            });
        }

        $semua = (clone $dasar)
            ->when($dari, fn ($x) => $x->whereDate('dibuat_pada', '>=', $dari))
            ->when($sampai, fn ($x) => $x->whereDate('dibuat_pada', '<=', $sampai))
            ->get(['kode_kasus', 'judul', 'status_kasus', 'tingkat_urgensi', 'progres']);

        $total = $semua->count();
        $baris = 'Total kasus'
            . ($role === 'karyawan' ? ' yang melibatkan Anda' : '')
            . ($dari ? " periode $dari s/d $sampai" : '') . ": **$total**.";

        foreach ($semua->groupBy(fn ($k) => (string) ($k->status_kasus ?? '-')) as $st => $g) {
            $baris .= "\n- $st: " . $g->count();
        }

        $rata = $total > 0 ? (int) round((float) $semua->avg(fn ($k) => (int) $k->progres)) : 0;
        $baris .= "\nRata-rata progres: **{$rata}%**.";

        if (str_contains($q, 'terbuka') || str_contains($q, 'selesai')) {
            $belum = $semua->whereIn('status_kasus', ['Terbuka', 'Dalam Proses'])->count();
            $baris .= "\nBelum selesai (Terbuka + Dalam Proses): **$belum**.";
        }

        if (str_contains($q, 'urgensi') || str_contains($q, 'kritis') || str_contains($q, 'prioritas')) {
            foreach ($semua->groupBy(fn ($k) => (string) ($k->tingkat_urgensi ?? '-')) as $u => $g) {
                $baris .= "\n- Urgensi $u: " . $g->count();
            }
        }

        if (str_contains($q, 'terakhir') || str_contains($q, 'terbaru') || str_contains($q, 'daftar')) {
            $terakhir = (clone $dasar)->orderByDesc('id')->limit(5)
                ->get(['kode_kasus', 'judul', 'status_kasus', 'tingkat_urgensi']);
            if ($terakhir->isNotEmpty()) {
                $baris .= "\n\n5 terakhir:";
                foreach ($terakhir as $k) {
                    $baris .= "\n- {$k->kode_kasus} — {$k->judul}"
                        . " ({$k->status_kasus}, urgensi {$k->tingkat_urgensi})";
                }
            }
        }

        return $baris;
    }

    /* ---- Skill: tugas ---- */

    private function skillTugas(string $q, int $uid, string $role): string
    {
        $dasar = Tugas::query();
        if ($role === 'karyawan') {
            $dasar->where('user_id', $uid);
        }

        $aktif     = (clone $dasar)->whereIn('status', ['baru', 'dikerjakan'])->count();
        $terlambat = (clone $dasar)->whereIn('status', ['baru', 'dikerjakan'])
            ->whereDate('tenggat', '<', now()->toDateString())->count();
        $selesai = (clone $dasar)->where('status', 'selesai')->count();

        $baris = 'Tugas aktif: **' . $aktif . '**'
            . ($role === 'karyawan' ? ' (milik Anda)' : '')
            . ', terlambat: **' . $terlambat . '**'
            . ', selesai: **' . $selesai . '**.';

        if (str_contains($q, 'terakhir') || str_contains($q, 'terbaru') || str_contains($q, 'daftar')) {
            $terakhir = (clone $dasar)->orderByDesc('id')->limit(5)
                ->get(['judul', 'status', 'tenggat']);
            if ($terakhir->isNotEmpty()) {
                $baris .= "\n\n5 terakhir:";
                foreach ($terakhir as $t) {
                    $baris .= "\n- {$t->judul} ({$t->status}"
                        . ($t->tenggat !== null ? ', tenggat ' . $t->tenggat : '') . ')';
                }
            }
        }

        return $baris;
    }

    /* ---- Skill: agenda (jadwal + absensi hari ini) ---- */

    private function skillAgenda(string $q, int $uid, string $role): string
    {
        $hari = now()->toDateString();

        $jadwal = Jadwal::query()
            ->when($role === 'karyawan', fn ($x) => $x->where('user_id', $uid))
            ->whereDate('mulai', $hari)
            ->orderBy('mulai')
            ->limit(6)
            ->get(['judul', 'lokasi', 'mulai', 'selesai']);

        $baris = 'Agenda ' . now()->translatedFormat('d M Y') . ':';
        if ($jadwal->isEmpty()) {
            $baris .= "\n- Tidak ada jadwal tercatat hari ini.";
        } else {
            foreach ($jadwal as $j) {
                $mulai = $j->mulai !== null ? date('H:i', strtotime((string) $j->mulai)) : '-';
                $baris .= "\n- $mulai — {$j->judul}"
                    . ($j->lokasi !== null && $j->lokasi !== '' ? " ({$j->lokasi})" : '');
            }
        }

        $absensi = Absensi::query()
            ->when($role === 'karyawan', fn ($x) => $x->where('user_id', $uid))
            ->whereDate('tanggal', $hari)
            ->get(['status', 'jam_masuk', 'jam_pulang']);
        if ($absensi->isNotEmpty()) {
            foreach ($absensi as $a) {
                $baris .= "\nAbsensi hari ini: " . ($a->status ?? '-')
                    . ' (masuk ' . ($a->jam_masuk ?? '-') . ', pulang ' . ($a->jam_pulang ?? '-') . ')';
            }
        }

        $laporanHari = LaporanHarian::query()
            ->when($role === 'karyawan', fn ($x) => $x->where('user_id', $uid))
            ->whereDate('tanggal', $hari)->count();
        $baris .= "\nLaporan hari ini: **{$laporanHari}**.";

        return $baris;
    }

    /* ---- Skill: rekap karyawan (mentor/admin) ---- */

    private function skillRekap(string $q, int $uid, string $role): string
    {
        if ($role === 'karyawan') {
            return 'Rekap karyawan hanya tersedia untuk mentor/admin. '
                . 'Untuk data Anda sendiri, coba: "berapa laporan bulan ini".';
        }

        [$dari, $sampai] = $this->periode($q);
        $awal  = $dari ?? now()->startOfMonth()->toDateString();
        $akhir = $sampai ?? now()->toDateString();

        $per = LaporanHarian::query()
            ->join('users', 'users.id', '=', 'laporan_harian.user_id')
            ->where('users.role', 'karyawan')
            ->whereDate('laporan_harian.tanggal', '>=', $awal)
            ->whereDate('laporan_harian.tanggal', '<=', $akhir)
            ->selectRaw(
                'laporan_harian.user_id, users.nama as nama, COUNT(*) as total, '
                . 'SUM(laporan_harian.status = "diperiksa") as diperiksa, '
                . 'SUM(laporan_harian.status = "perlu_revisi") as revisi'
            )
            ->groupBy('laporan_harian.user_id', 'users.nama')
            ->orderByDesc('total')
            ->get();

        if ($per->isEmpty()) {
            return "Belum ada laporan karyawan pada periode $awal s/d $akhir.";
        }

        $baris = "Rekap laporan karyawan $awal s/d $akhir:";
        foreach ($per as $r) {
            $baris .= "\n- {$r->nama}: **{$r->total}** laporan"
                . ' (diperiksa ' . (int) $r->diperiksa
                . ', perlu revisi ' . (int) $r->revisi . ')';
        }

        return $baris;
    }

    /* ---- Skill: ringkasan gabungan ---- */

    private function skillRingkasan(string $q, int $uid, string $role): string
    {
        [$dari, $sampai] = $this->periode($q);
        $dari   = $dari ?? now()->subDays(6)->toDateString();
        $sampai = $sampai ?? now()->toDateString();

        $scopeLaporan = ($role === 'karyawan')
            ? fn ($x) => $x->where('user_id', $uid)
            : fn ($x) => $x;

        $laporan = $scopeLaporan(LaporanHarian::query())
            ->whereDate('tanggal', '>=', $dari)->whereDate('tanggal', '<=', $sampai);
        $jmlLaporan = (clone $laporan)->count();
        $adaKendala = (clone $laporan)->whereNotNull('kendala')->where('kendala', '!=', '')->count();

        $absensi = ($role === 'karyawan'
            ? Absensi::where('user_id', $uid)
            : Absensi::query())
            ->whereDate('tanggal', '>=', $dari)->whereDate('tanggal', '<=', $sampai)
            ->selectRaw('status, COUNT(*) as jml')->groupBy('status')->pluck('jml', 'status');

        $kasusQ = Kasus::query();
        if ($role === 'karyawan') {
            $kasusQ->where(function ($sub) use ($uid) {
                $sub->where('dibuat_oleh', $uid)
                    ->orWhere('penanggung_jawab_id', $uid)
                    ->orWhereIn('id', function ($inner) use ($uid) {
                        $inner->select('kasus_id')->from('kasus_penugasan')->where('user_id', $uid);
                    });
            });
        }
        $kasusBelum = (clone $kasusQ)->whereIn('status_kasus', ['Terbuka', 'Dalam Proses'])->count();

        $tugasQ = Tugas::query();
        if ($role === 'karyawan') {
            $tugasQ->where('user_id', $uid);
        }
        $tugasAktif = (clone $tugasQ)->whereIn('status', ['baru', 'dikerjakan'])->count();

        $baris = 'Ringkasan ' . ($dari === $sampai ? $dari : "$dari s/d $sampai") . ':';
        $baris .= "\n- Laporan: **{$jmlLaporan}**"
            . ($adaKendala > 0 ? " ({$adaKendala} dengan kendala)" : '');
        foreach ($absensi as $st => $jml) {
            $baris .= "\n- Absensi $st: $jml";
        }
        $baris .= "\n- Kasus belum selesai: **{$kasusBelum}**";
        $baris .= "\n- Tugas aktif: **{$tugasAktif}**";

        return $baris;
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
     * Lapis 2: LLM opsional (konfigurasi .env: LLM_BASE_URL/LLM_API_KEY)
     *
     * Keselamatan permintaan:
     *   - Bila belum dikonfigurasi (base_url kosong) → langsung dilewati.
     *   - Bila provider menolak (401) atau tidak terjangkau → LLM
     *     DINONAKTIFKAN otomatis selama `llm_off_detik` (default 1 jam)
     *     lewat cache. Selama kunci mati itu, semua pertanyaan dijawab
     *     dari database tanpa satu pun HTTP keluar -- permintaan tidak
     *     pernah menggantung dan halaman tidak pernah "tutup".
     * -------------------------------------------------------------- */

    private function lewatLlm(string $pesan, $riwayat): ?string
    {
        $baseUrl = (string) config('keamanan.llm_base_url');
        $key = (string) config('keamanan.llm_api_key');

        if ($baseUrl === '' || $key === '') {
            return null;
        }
        if (cache()->get('asisten:llm_mati')) {
            return null;
        }

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
            $res = Http::withToken($key)
                ->connectTimeout(5)
                ->timeout((int) config('keamanan.llm_timeout', 12))
                ->post($baseUrl . '/chat/completions', [
                    'model' => config('keamanan.llm_model'),
                    'messages' => $messages,
                    'max_tokens' => 500,
                ]);

            if ($res->status() === 401 || $res->status() === 403) {
                // Key ditolak provider: kunci mati agar tidak dicoba lagi
                // berulang-ulang setiap pesan.
                cache()->put('asisten:llm_mati', true, (int) config('keamanan.llm_off_detik', 3600));
                return null;
            }
            if ($res->ok()) {
                $isi = trim((string) data_get($res->json(), 'choices.0.message.content', ''));
                return $isi !== '' ? $isi : null;
            }
        } catch (\Throwable $e) {
            // Timeout / koneksi gagal: kunci mati sebentar supaya halaman
            // tidak menunggu kegagalan yang sama pada pesan berikutnya.
            cache()->put('asisten:llm_mati', true, (int) config('keamanan.llm_off_detik', 3600));
            report($e);
        }
        return null;
    }

    /** Fallback statis bila skill & LLM keduanya tidak menjawab. */
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
        return 'Saya bisa menjawab seputar pelaporan dengan data nyata -- ketik "bantuan" untuk daftar skill, '
            . 'atau coba: "berapa laporan bulan ini", "kasus terbuka", "tugas saya", "jadwal hari ini".';
    }
}
