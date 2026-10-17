<?php
/**
 * scripts/pengingat_harian.php
 * Logika tunggal pengingat laporan harian + CLI opsional.
 *
 * Dipakai dua cara:
 *   1) di-include oleh pengingat.php (halaman admin) lalu memanggil
 *      pengingat_harian_jalankan($conn, $tanggal, $kering);
 *   2) dijalankan langsung dari baris perintah (Task Scheduler):
 *        php scripts\pengingat_harian.php [--kering] [--tanggal=YYYY-MM-DD]
 *
 * Idempoten: dijalankan dua kali untuk tanggal yang sama tidak
 * menggandakan notifikasi (dicocokkan lewat teks tanggal Indonesia pada
 * pesan notifikasi).
 *
 * Aman di-include: blok CLI hanya aktif bila berkas ini adalah skrip
 * utama (PHP_SAPI === 'cli').
 */

declare(strict_types=1);

if (!function_exists('pengingat_harian_jalankan')) {
    /**
     * Hitung dan (opsional) kirim pengingat untuk satu tanggal.
     *
     * @param mysqli $conn    koneksi database
     * @param string $tanggal tanggal laporan (YYYY-MM-DD)
     * @param bool   $kering  true = jangan kirim, hanya hitung (dry-run)
     * @return array{dicek:int, perlu:int, dikirim:int, dilewati:int, daftar:list<array{id:int,nama:string,divisi:string,sudah_lapor:bool,absensi:string,tindakan:string}>}
     */
    function pengingat_harian_jalankan(mysqli $conn, string $tanggal, bool $kering = false): array
    {
        $hasil = [
            'dicek'    => 0,
            'perlu'    => 0,
            'dikirim'  => 0,
            'dilewati' => 0,
            'daftar'   => [],
        ];

        $tanggal   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) === 1 ? $tanggal : date('Y-m-d');
        $tanggalId = function_exists('tanggal_id') ? tanggal_id($tanggal) : $tanggal;

        /* Semua akun karyawan (yang wajib mengisi laporan harian). */
        $res = $conn->query("SELECT id, nama, COALESCE(divisi, '') AS divisi
                             FROM users WHERE role = 'karyawan' ORDER BY nama");
        if (!$res) {
            error_log('pengingat: kueri users gagal - ' . $conn->error);
            return $hasil;
        }

        /* Siapkan seluruh prepared statement sekali. */
        $stmtLapor = $conn->prepare('SELECT 1 FROM laporan_harian WHERE user_id = ? AND tanggal = ? LIMIT 1');
        $stmtAbsen = $conn->prepare('SELECT status FROM absensi WHERE user_id = ? AND tanggal = ? LIMIT 1');
        $stmtIngat = $conn->prepare("SELECT 1 FROM notifikasi
                                     WHERE user_id = ? AND pesan LIKE 'Pengingat:%' AND pesan LIKE ?
                                     LIMIT 1");

        while ($u = $res->fetch_assoc()) {
            $uid    = (int) $u['id'];
            $nama   = (string) $u['nama'];
            $divisi = (string) $u['divisi'];

            $hasil['dicek']++;

            /* Sudah mengisi laporan? (status apa pun dianggap sudah) */
            $sudahLapor = false;
            if ($stmtLapor !== false) {
                $stmtLapor->bind_param('is', $uid, $tanggal);
                $stmtLapor->execute();
                $sudahLapor = (bool) $stmtLapor->get_result()->fetch_row();
                $stmtLapor->free_result();
            }

            /* Status absensi (izin/sakit => dikecualikan). */
            $absensi = '';
            if ($stmtAbsen !== false) {
                $stmtAbsen->bind_param('is', $uid, $tanggal);
                $stmtAbsen->execute();
                $barisAbsen = $stmtAbsen->get_result()->fetch_row();
                if ($barisAbsen) {
                    $absensi = (string) $barisAbsen[0];
                }
                $stmtAbsen->free_result();
            }

            if ($absensi === 'izin' || $absensi === 'sakit') {
                $tindakan = 'izin_sakit';
                $hasil['dilewati']++;
            } elseif ($sudahLapor) {
                $tindakan = 'sudah_lapor';
            } else {
                $hasil['perlu']++;

                /* Sudah pernah diingatkan untuk tanggal ini? */
                $sudahDiingatkan = false;
                if ($stmtIngat !== false) {
                    $cari = '%' . $tanggalId . '%';
                    $stmtIngat->bind_param('is', $uid, $cari);
                    $stmtIngat->execute();
                    $sudahDiingatkan = (bool) $stmtIngat->get_result()->fetch_row();
                    $stmtIngat->free_result();
                }

                if ($sudahDiingatkan) {
                    $tindakan = 'sudah_diingatkan';
                } else {
                    $tindakan = 'perlu_diingatkan';
                    if (!$kering && function_exists('buat_notifikasi')) {
                        $pesan = 'Pengingat: laporan kegiatan Tanggal ' . $tanggalId
                               . ' belum diisi. Silakan isi laporan harian Anda.';
                        if (buat_notifikasi($conn, $uid, $pesan, null, 'buat_laporan.php')) {
                            $hasil['dikirim']++;
                        }
                    }
                }
            }

            $hasil['daftar'][] = [
                'id'          => $uid,
                'nama'        => $nama,
                'divisi'      => $divisi,
                'sudah_lapor' => $sudahLapor,
                'absensi'     => $absensi,
                'tindakan'    => $tindakan,
            ];
        }

        if ($stmtLapor !== false) { $stmtLapor->close(); }
        if ($stmtAbsen !== false) { $stmtAbsen->close(); }
        if ($stmtIngat !== false) { $stmtIngat->close(); }
        $res->free();

        return $hasil;
    }
}

/* ====================================================================
 * Blok CLI — hanya jalan bila berkas ini adalah skrip utama.
 * ==================================================================== */
if (PHP_SAPI === 'cli'
    && isset($argv[0])
    && realpath($argv[0]) === realpath(__FILE__)
) {
    require_once __DIR__ . '/../includes/bootstrap.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        fwrite(STDERR, "pengingat: koneksi database tidak tersedia.\n");
        exit(1);
    }

    $kering  = in_array('--kering', $argv, true);
    $tanggal = date('Y-m-d');
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--tanggal=')) {
            $nilai = substr($arg, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $nilai) === 1) {
                $tanggal = $nilai;
            }
        }
    }

    try {
        $hasil = pengingat_harian_jalankan($conn, $tanggal, $kering);
    } catch (Throwable $t) {
        error_log('pengingat: ' . $t->getMessage());
        fwrite(STDERR, 'pengingat: gagal - ' . $t->getMessage() . "\n");
        exit(1);
    }

    $tanda = static function (string $tindakan): string {
        return match ($tindakan) {
            'perlu_diingatkan' => '[!]',
            'sudah_diingatkan' => '[v]',
            'izin_sakit'       => '[-]',
            default            => '[ ]',
        };
    };

    fwrite(STDOUT, 'Pengingat laporan ' . $tanggal . ($kering ? '  (mode kering)' : '') . PHP_EOL);
    fwrite(STDOUT, '  karyawan diperiksa : ' . $hasil['dicek'] . "\n");
    fwrite(STDOUT, '  perlu diingatkan  : ' . $hasil['perlu'] . "\n");
    fwrite(STDOUT, '  dikirim           : ' . $hasil['dikirim'] . "\n");
    fwrite(STDOUT, '  dilewati          : ' . $hasil['dilewati'] . "\n");
    foreach ($hasil['daftar'] as $d) {
        fwrite(STDOUT, '    ' . $tanda($d['tindakan']) . ' ' . $d['nama']
            . ' — ' . ($d['absensi'] !== '' ? $d['absensi'] : '-') . "\n");
    }

    error_log('pengingat: tanggal=' . $tanggal
        . ' dicek=' . $hasil['dicek']
        . ' perlu=' . $hasil['perlu']
        . ' dikirim=' . $hasil['dikirim']
        . ' dilewati=' . $hasil['dilewati']
        . ($kering ? ' (kering)' : ''));

    exit(0);
}
