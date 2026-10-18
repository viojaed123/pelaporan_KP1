<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit akun & peran (read-only) — pendamping checklist Go-Live §5.
 *
 * TIDAK mengubah apa pun. Membantu memverifikasi sebelum Go-Live:
 *   - sebaran peran (karyawan/mentor/admin) dan status akun;
 *   - akun uji yang harus dihapus (domain @uji.local);
 *   - nama duplikat pada karyawan (penting: PIC/anggota kasus diketik
 *     bebas dan dicocokkan ke nama pengguna terdaftar);
 *   - akun berstatus menunggu/nonaktif;
 *   - jumlah token API aktif (personal_access_tokens).
 *
 * Contoh:
 *   php artisan pengguna:audit
 */
class AuditPengguna extends Command
{
    protected $signature = 'pengguna:audit';

    protected $description = 'Laporkan sebaran peran, akun uji, nama duplikat, dan token API (read-only).';

    public function handle(): int
    {
        $this->info('Audit pengguna');
        $this->newLine();

        /* --- Sebaran peran ------------------------------------------- */
        $peran = User::query()
            ->select('role', DB::raw('count(*) as jml'))
            ->groupBy('role')
            ->orderByDesc('jml')
            ->pluck('jml', 'role');

        $this->line('Peran:');
        if ($peran->isEmpty()) {
            $this->line('  (belum ada pengguna)');
        }
        foreach ($peran as $nama => $jml) {
            $this->line(sprintf('  %-10s %d', (string) $nama, (int) $jml));
        }

        /* --- Sebaran status ----------------------------------------- */
        $status = User::query()
            ->select('status', DB::raw('count(*) as jml'))
            ->groupBy('status')
            ->orderByDesc('jml')
            ->pluck('jml', 'status');

        $this->newLine();
        $this->line('Status:');
        foreach ($status as $nama => $jml) {
            $this->line(sprintf('  %-10s %d', (string) $nama, (int) $jml));
        }

        /* --- Akun uji (harus dihapus sebelum Go-Live) ----------------- */
        $uji = User::where('email', 'like', '%@uji.local')
            ->orderBy('id')
            ->get(['id', 'nama', 'email', 'role', 'status']);

        $this->newLine();
        $this->line('Akun uji (@uji.local): ' . $uji->count() . ' — ' . ($uji->isEmpty() ? 'bersih' : 'HARUS DIHAPUS'));
        foreach ($uji as $u) {
            $this->line(sprintf('  #%d  %s  <%s>  %s/%s', $u->id, (string) $u->nama, (string) $u->email, (string) $u->role, (string) $u->status));
        }

        /* --- Nama duplikat pada karyawan ----------------------------- */
        $duplikat = User::where('role', 'karyawan')
            ->select('nama', DB::raw('count(*) as jml'))
            ->groupBy('nama')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('jml')
            ->get();

        $this->newLine();
        $this->line('Nama karyawan duplikat: ' . $duplikat->count());
        foreach ($duplikat as $d) {
            $this->line(sprintf('  %s  (%d akun)', (string) $d->nama, (int) $d->jml));
        }
        if ($duplikat->isNotEmpty()) {
            $this->warn('  Nama ganda menyulitkan pencocokan PIC/anggota kasus (teks bebas).');
        }

        /* --- Akun belum aktif ---------------------------------------- */
        $menunggu = User::where('status', '!=', 'aktif')
            ->orderBy('status')
            ->get(['id', 'nama', 'email', 'status']);

        $this->newLine();
        $this->line('Akun belum aktif (menunggu/nonaktif): ' . $menunggu->count());
        foreach ($menunggu as $u) {
            $this->line(sprintf('  #%d  %s  <%s>  %s', $u->id, (string) $u->nama, (string) $u->email, (string) $u->status));
        }

        /* --- Token API aktif ----------------------------------------- */
        $token = DB::table('personal_access_tokens')->count();
        $this->newLine();
        $this->line('Token API (personal_access_tokens): ' . $token);
        if ($token > 0) {
            $this->line('  Cabut bila perlu: DELETE FROM personal_access_tokens;');
        }

        $this->newLine();
        $this->info('Selesai. Tidak ada perubahan data.');

        return self::SUCCESS;
    }
}
