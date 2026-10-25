<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PengingatJadwal extends Command
{
    protected $signature = 'pengingat:jadwal
                            {--kering : Hitung saja, jangan kirim pengingat}
                            {--maks=100 : Maksimum pengingat yang diproses per jalan}';

    protected $description = 'Kirim pengingat agenda/jadwal yang jatuh tempo (aplikasi/surel/WhatsApp).';

    public function handle(): int
    {
        $kering = (bool) $this->option('kering');
        $maks = (int) $this->option('maks');
        if ($maks < 1) {
            $maks = 100;
        }
        if ($maks > 1000) {
            $maks = 1000;
        }

        $root = base_path();
        $bootstrap = $root . '/includes/bootstrap.php';
        $jadwal = $root . '/includes/jadwal.php';

        if (!is_file($bootstrap)) {
            $this->error('includes/bootstrap.php tidak ditemukan.');
            return self::FAILURE;
        }
        if (!is_file($jadwal)) {
            $this->error('includes/jadwal.php tidak ditemukan.');
            return self::FAILURE;
        }

        require_once $bootstrap;
        require_once $jadwal;

        if (!isset($conn) || !($conn instanceof \mysqli)) {
            $this->error('Koneksi database tidak tersedia.');
            return self::FAILURE;
        }

        if (!function_exists('jadwal_jalankan_pengingat')) {
            $this->error('Fungsi jadwal_jalankan_pengingat() tidak tersedia.');
            return self::FAILURE;
        }

        try {
            $hasil = jadwal_jalankan_pengingat($conn, $kering, $maks);
        } catch (\Throwable $t) {
            error_log('pengingat:jadwal - ' . $t->getMessage());
            $this->error('Gagal: ' . $t->getMessage());
            return self::FAILURE;
        }

        $this->info('Pengingat jadwal' . ($kering ? ' (mode kering)' : ''));
        $this->line('  jatuh tempo : ' . $hasil['total']);
        $this->line('  terkirim    : ' . $hasil['terkirim']);
        $this->line('  aplikasi    : ' . $hasil['aplikasi']);
        $this->line('  surel       : ' . $hasil['surel']);
        $this->line('  whatsapp    : ' . $hasil['whatsapp']);

        error_log('pengingat:jadwal total=' . $hasil['total']
            . ' terkirim=' . $hasil['terkirim']
            . ' aplikasi=' . $hasil['aplikasi']
            . ' surel=' . $hasil['surel']
            . ' whatsapp=' . $hasil['whatsapp']
            . ($kering ? ' (kering)' : ''));

        return self::SUCCESS;
    }
}
