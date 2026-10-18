<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Kasus;
use App\Models\KasusPenugasan;
use App\Models\KasusStatusLog;
use App\Models\Notifikasi;
use App\Models\User;
use App\Services\CocokPengguna;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Modul kasus (porting buat_kasus.php + includes/kasus.php ke Eloquent).
 *
 * Perubahan utama dari versi legacy: PIC dan anggota tim DIKETIK BEBAS
 * oleh pembuat (bukan dropdown). Nama yang bisa dicocokkan dengan
 * pengguna terdaftar otomatis memperoleh user_id (notifikasi & hak akses
 * tetap jalan); yang tidak cocok tetap tersimpan sebagai teks.
 *
 * Visibilitas (kasus_sql_visibilitas / kasus_izin_lihat_dasar):
 *   mentor/admin : semua kasus;
 *   karyawan     : hanya kasus yang melibatkannya (pembuat, PIC, anggota).
 */
class KasusController extends Controller
{
    public function __construct(private readonly CocokPengguna $cocok)
    {
    }

    /* =================================================================
     * DAFTAR
     * =============================================================== */

    public function index(Request $request): View
    {
        $user = $request->user();

        $q = Kasus::query()->orderByDesc('id');

        if (! $this->bolehLihatSemua($user)) {
            $this->saringLibatkan($q, (int) $user->id);
        }

        if ($request->filled('status')) {
            $q->where('status_kasus', (string) $request->query('status'));
        }
        if ($request->filled('urgensi')) {
            $q->where('tingkat_urgensi', (string) $request->query('urgensi'));
        }
        if ($request->filled('q')) {
            $cari = '%' . mb_strtolower((string) $request->query('q')) . '%';
            $q->where(function ($sub) use ($cari) {
                $sub->whereRaw('LOWER(judul) LIKE ?', [$cari])
                    ->orWhereRaw('LOWER(kode_kasus) LIKE ?', [$cari]);
            });
        }

        return view('kasus.index', [
            'menuAktif' => 'kasus',
            'daftar'    => $q->paginate(15)->withQueryString(),
            'opsiStatus'   => $this->opsiStatus(),
            'opsiUrgensi'  => $this->opsiUrgensi(),
        ]);
    }

    /* =================================================================
     * BUAT / SIMPAN
     * =============================================================== */

    public function buat(Request $request): View
    {
        return view('kasus.form', [
            'menuAktif'    => 'kasus',
            'kasus'        => null,
            'opsiStatus'   => $this->opsiStatus(),
            'opsiUrgensi'  => $this->opsiUrgensi(),
            'opsiKategori' => $this->opsiKategori(),
        ]);
    }

    public function simpan(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $this->validasi($request);
        $kasus = Kasus::create([
            'kode_kasus'              => $this->buatKode(),
            'judul'                   => $data['judul'],
            'deskripsi'               => $data['deskripsi'] ?? null,
            'nama_lokasi'             => $data['nama_lokasi'] ?? null,
            'garis_lintang'           => $data['garis_lintang'] ?? null,
            'garis_bujur'             => $data['garis_bujur'] ?? null,
            'tingkat_urgensi'         => $data['tingkat_urgensi'],
            'status_kasus'            => $data['status_kasus'],
            'progres'                 => $data['progres'],
            'kategori'                => $data['kategori'] ?? null,
            'sumber'                  => 'Manual',
            'target_selesai'          => $data['target_selesai'] ?? null,
            'dibuat_oleh'             => (int) $user->id,
            'penanggung_jawab_id'     => $this->cocok((string) ($data['penanggung_jawab'] ?? ''))['user_id'],
            'penanggung_jawab_teks'   => $this->cocok((string) ($data['penanggung_jawab'] ?? ''))['teks'] ?: null,
        ]);

        $this->sinkronTim($kasus, (string) ($data['anggota'] ?? ''), (int) $user->id);

        /* Linimasa awal + notifikasi pihak terkait. */
        $this->catatStatus($kasus, null, (string) $kasus->status_kasus, (int) $kasus->progres, 'Kasus dibuat', (int) $user->id);
        $this->beritahuPihak($kasus, 'Kasus baru: ' . $kasus->judul, (int) $user->id);

        return redirect()->route('kasus.index')
            ->with('sukses', 'Kasus ' . $kasus->kode_kasus . ' berhasil dibuat.');
    }

    /* =================================================================
     * DETAIL
     * =============================================================== */

    public function detail(Request $request, int $id): View|RedirectResponse
    {
        $kasus = Kasus::find($id);

        if ($kasus === null) {
            return redirect()->route('kasus.index')->with('galat', 'Kasus tidak ditemukan.');
        }

        $user = $request->user();
        if (! $this->bolehLihatSemua($user) && ! $this->libatkan($kasus, (int) $user->id)) {
            abort(403, 'Anda tidak berhak melihat kasus ini.');
        }

        return view('kasus.detail', [
            'menuAktif' => 'kasus',
            'kasus'     => $kasus,
            'anggota'   => $kasus->anggota()->with('user')->get(),
            'log'       => $kasus->statusLog()->with('user')->orderByDesc('id')->get(),
            'bolehKelola' => $this->bolehKelola($user, $kasus),
        ]);
    }

    /* =================================================================
     * EDIT / PERBARUI STATUS
     * =============================================================== */

    public function edit(Request $request, int $id): View|RedirectResponse
    {
        $kasus = Kasus::find($id);

        if ($kasus === null) {
            return redirect()->route('kasus.index')->with('galat', 'Kasus tidak ditemukan.');
        }

        if (! $this->bolehKelola($request->user(), $kasus)) {
            abort(403, 'Anda tidak berhak mengubah kasus ini.');
        }

        return view('kasus.form', [
            'menuAktif'    => 'kasus',
            'kasus'        => $kasus,
            'opsiStatus'   => $this->opsiStatus(),
            'opsiUrgensi'  => $this->opsiUrgensi(),
            'opsiKategori' => $this->opsiKategori(),
        ]);
    }

    public function perbarui(Request $request, int $id): RedirectResponse
    {
        $kasus = Kasus::find($id);

        if ($kasus === null) {
            return redirect()->route('kasus.index')->with('galat', 'Kasus tidak ditemukan.');
        }

        $user = $request->user();
        if (! $this->bolehKelola($user, $kasus)) {
            abort(403, 'Anda tidak berhak mengubah kasus ini.');
        }

        $data = $this->validasi($request);
        $statusLama  = (string) $kasus->status_kasus;
        $progresLama = (int) $kasus->progres;

        $pj = $this->cocok((string) ($data['penanggung_jawab'] ?? ''));

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
            $this->catatStatus($kasus, $statusLama, (string) $kasus->status_kasus, (int) $kasus->progres, null, (int) $user->id);
            $this->beritahuPihak($kasus, 'Status kasus ' . $kasus->kode_kasus . ' → ' . $kasus->status_kasus, (int) $user->id);
        }

        return redirect()->route('kasus.detail', $kasus->id)
            ->with('sukses', 'Kasus berhasil diperbarui.');
    }

    /* =================================================================
     * BAGIAN DALAM
     * =============================================================== */

    /** Aturan validasi bersama untuk buat & edit. */
    private function validasi(Request $request): array
    {
        return $request->validate([
            'judul'           => ['required', 'string', 'max:200'],
            'deskripsi'       => ['nullable', 'string'],
            'nama_lokasi'     => ['nullable', 'string', 'max:200'],
            'garis_lintang'   => ['nullable', 'numeric', 'between:-90,90'],
            'garis_bujur'     => ['nullable', 'numeric', 'between:-180,180'],
            'tingkat_urgensi' => ['required', 'string', 'max:20'],
            'status_kasus'    => ['required', 'string', 'max:20'],
            'progres'         => ['required', 'integer', 'between:0,100'],
            'kategori'        => ['nullable', 'string', 'max:60'],
            'target_selesai'  => ['nullable', 'date'],
            'penanggung_jawab' => ['nullable', 'string', 'max:150'],
            'anggota'         => ['nullable', 'string'],
        ]);
    }

    private function cocok(string $teks): array
    {
        return $this->cocok->cocok($teks);
    }

    /**
     * Sinkronkan anggota tim dari kotak teks multi-baris (kasus_sinkron_tim
     * versi teks bebas): hapus yang tidak lagi disebut, tambah yang baru.
     */
    private function sinkronTim(Kasus $kasus, string $kotak, int $oleh): void
    {
        $barisCocok = $this->cocok->cocokBanyak($kotak);

        $idTeks = [];
        foreach ($barisCocok as $b) {
            $idTeks[] = $b['teks'];
        }

        /* Tidak lagi disebut → hapus. */
        foreach ($kasus->anggota as $a) {
            $lama = (string) ($a->nama_teks ?? '');
            if (! in_array($lama, $idTeks, true)) {
                $a->delete();
            }
        }

        /* Baru disebut → tambah (nama_teks selalu tersimpan). */
        $ada = $kasus->anggota()->pluck('nama_teks')->all();
        foreach ($barisCocok as $b) {
            if (in_array($b['teks'], $ada, true)) {
                continue;
            }
            KasusPenugasan::create([
                'kasus_id'        => (int) $kasus->id,
                'user_id'         => $b['user_id'],
                'nama_teks'       => $b['teks'],
                'peran'           => 'Anggota Tim',
                'ditugaskan_oleh' => $oleh,
            ]);
        }
    }

    /** Catat satu entri linimasa perubahan status/progres. */
    private function catatStatus(
        Kasus $kasus,
        ?string $lama,
        string $baru,
        int $progres,
        ?string $catatan,
        int $userId,
    ): void {
        KasusStatusLog::create([
            'kasus_id'    => (int) $kasus->id,
            'user_id'     => $userId,
            'status_lama' => $lama,
            'status_baru' => $baru,
            'progres'     => max(0, min(100, $progres)),
            'catatan'     => $catatan,
        ]);
    }

    /** Notifikasi semua pihak terkait (pembuat, PIC, anggota). */
    private function beritahuPihak(Kasus $kasus, string $pesan, int $kecuali): void
    {
        $penerima = collect([(int) $kasus->dibuat_oleh, (int) $kasus->penanggung_jawab_id])
            ->merge($kasus->anggota()->pluck('user_id'))
            ->filter(fn ($id) => $id > 0 && (int) $id !== $kecuali)
            ->unique();

        foreach ($penerima as $uid) {
            Notifikasi::create([
                'user_id' => (int) $uid,
                'pesan'   => $pesan,
                'link'    => route('kasus.detail', $kasus->id),
            ]);
        }
    }

    /** Kode kasus unik: KAS-Ymd-XXXX (kasus_buat_kode). */
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

    private function bolehKelola(object $user, Kasus $kasus): bool
    {
        if ($this->bolehLihatSemua($user)) {
            return true;
        }

        return (int) $kasus->dibuat_oleh === (int) $user->id;
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

    /** @return list<string> Sama persis kasus_status_daftar() legacy. */
    private function opsiStatus(): array
    {
        return ['Terbuka', 'Dalam Proses', 'Selesai'];
    }

    /** @return list<string> Sama persis kasus_urgensi_daftar() legacy. */
    private function opsiUrgensi(): array
    {
        return ['Rendah', 'Sedang', 'Tinggi', 'Kritis'];
    }

    /** @return list<string> Sama persis kasus_kategori_daftar() legacy. */
    private function opsiKategori(): array
    {
        return [
            'Sosial & Kemasyarakatan',
            'Lingkungan',
            'Kesehatan',
            'Infrastruktur',
            'Ekonomi & Mata Pencaharian',
            'Keamanan',
            'Lainnya',
        ];
    }
}
