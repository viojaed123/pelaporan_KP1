<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Absensi;
use App\Models\LaporanHarian;
use App\Models\Notifikasi;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pengingat laporan harian (porting `scripts/pengingat_harian.php`).
 *
 * Mengirim notifikasi kepada karyawan yang BELUM mengisi laporan pada
 * tanggal acuan. Karyawan yang berstatus izin/sakit dilewati.
 *
 * Idempoten: dijalankan dua kali untuk tanggal yang sama tidak
 * menggandakan notifikasi (dicocokkan lewat teks tanggal Indonesia pada
 * pesan notifikasi -- sama seperti sistem lama).
 *
 * Contoh:
 *   php artisan pengingat:harian
 *   php artisan pengingat:harian --kering --tanggal=2026-10-15
 */
class PengingatHarian extends Command
{
    protected $signature = 'pengingat:harian
                            {--kering : Hitung saja, jangan kirim notifikasi}
                            {--tanggal= : Tanggal acuan (YYYY-MM-DD), bawaan hari ini}';

    protected $description = 'Kirim pengingat ke karyawan yang belum mengisi laporan harian.';

    public function handle(): int
    {
        $kering = (bool) $this->option('kering');
        $tanggal = (string) ($this->option('tanggal') ?: now()->toDateString());

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) !== 1) {
            $this->error('Format tanggal harus YYYY-MM-DD.');

            return self::FAILURE;
        }

        $tanggalId = $this->tanggalId($tanggal);

        $dicek = 0;
        $perlu = 0;
        $dikirim = 0;
        $dilewati = 0;

        $karyawan = User::where('role', 'karyawan')->orderBy('nama')->get(['id', 'nama', 'divisi']);

        foreach ($karyawan as $u) {
            $dicek++;

            $sudahLapor = LaporanHarian::where('user_id', $u->id)
                ->whereDate('tanggal', $tanggal)
                ->exists();

            $statusAbsen = (string) (Absensi::where('user_id', $u->id)
                ->whereDate('tanggal', $tanggal)
                ->value('status') ?? '');

            /* Izin/sakit: tidak wajib mengisi laporan hari itu. */
            if (in_array($statusAbsen, ['izin', 'sakit'], true)) {
                $dilewati++;
                $this->garis((string) $u->nama, $statusAbsen, '[-]');
                continue;
            }

            if ($sudahLapor) {
                $this->garis((string) $u->nama, '', '[ ]');
                continue;
            }

            $perlu++;

            $sudahDiingatkan = Notifikasi::where('user_id', $u->id)
                ->where('pesan', 'like', 'Pengingat:%')
                ->where('pesan', 'like', '%' . $tanggalId . '%')
                ->exists();

            if ($sudahDiingatkan) {
                $this->garis((string) $u->nama, '', '[v]');
                continue;
            }

            if (! $kering) {
                Notifikasi::create([
                    'user_id' => (int) $u->id,
                    'pesan'   => 'Pengingat: laporan kegiatan Tanggal ' . $tanggalId
                        . ' belum diisi. Silakan isi laporan harian Anda.',
                    'link'    => '/app/laporan/buat',
                ]);
                $dikirim++;
            }

            $this->garis((string) $u->nama, '', '[!]');
        }

        $this->newLine();
        $this->info('Pengingat laporan ' . $tanggal . ($kering ? '  (mode kering)' : ''));
        $this->line('  karyawan diperiksa : ' . $dicek);
        $this->line('  perlu diingatkan   : ' . $perlu);
        $this->line('  dikirim            : ' . $dikirim);
        $this->line('  dilewati           : ' . $dilewati);

        return self::SUCCESS;
    }

    private function garis(string $nama, string $absensi, string $tanda): void
    {
        $this->line('    ' . $tanda . ' ' . $nama . ' — ' . ($absensi !== '' ? $absensi : '-'));
    }

    /** "2026-10-15" -> "15 Oktober 2026" (meniru tanggal_id() sistem lama). */
    private function tanggalId(string $tanggal): string
    {
        return Carbon::parse($tanggal)
            ->locale((string) config('app.locale'))
            ->translatedFormat('j F Y');
    }
}
