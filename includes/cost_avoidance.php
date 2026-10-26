<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('cost_avoidance_kategori_daftar')) {
    /** @return list<string> */
    function cost_avoidance_kategori_daftar(): array
    {
        return [
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
    }
}

if (!function_exists('cost_avoidance_status_daftar')) {
    /** @return list<string> */
    function cost_avoidance_status_daftar(): array
    {
        return ['draft', 'diajukan', 'disetujui', 'ditolak'];
    }
}

if (!function_exists('cost_avoidance_status_terbuka')) {
    /** @return list<string> */
    function cost_avoidance_status_terbuka(): array
    {
        return ['draft', 'ditolak'];
    }
}

if (!function_exists('cost_avoidance_label_status')) {
    function cost_avoidance_label_status(string $status): string
    {
        return match ($status) {
            'draft'      => 'Draft',
            'diajukan'   => 'Menunggu Verifikasi',
            'disetujui'  => 'Disetujui',
            'ditolak'    => 'Ditolak',
            default      => ucfirst($status),
        };
    }
}

if (!function_exists('cost_avoidance_kelas_status')) {
    function cost_avoidance_kelas_status(string $status): string
    {
        return match ($status) {
            'diajukan'  => 'ca-st-diajukan',
            'disetujui' => 'ca-st-disetujui',
            'ditolak'   => 'ca-st-ditolak',
            default     => 'ca-st-draft',
        };
    }
}

if (!function_exists('cost_avoidance_nama_bulan')) {
    function cost_avoidance_nama_bulan(int $bulan): string
    {
        return ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
                'Agustus', 'September', 'Oktober', 'November', 'Desember'][$bulan] ?? '';
    }
}

if (!function_exists('cost_avoidance_periode_label')) {
    function cost_avoidance_periode_label(?string $periode): string
    {
        $ts = $periode !== null && $periode !== '' ? strtotime($periode) : false;
        if ($ts === false) {
            return '-';
        }

        return cost_avoidance_nama_bulan((int) date('n', $ts)) . ' ' . date('Y', $ts);
    }
}

if (!function_exists('cost_avoidance_rupiah')) {
    function cost_avoidance_rupiah(mixed $nilai): string
    {
        return 'Rp ' . number_format(round((float) $nilai), 0, ',', '.');
    }
}

if (!function_exists('cost_avoidance_ringkas_rupiah')) {
    function cost_avoidance_ringkas_rupiah(mixed $nilai): string
    {
        $n = (float) $nilai;
        $tanda = $n < 0 ? '-' : '';
        $n = abs($n);

        if ($n >= 1_000_000_000_000) {
            return $tanda . number_format($n / 1_000_000_000_000, 2, ',', '.') . ' T';
        }
        if ($n >= 1_000_000_000) {
            return $tanda . number_format($n / 1_000_000_000, 2, ',', '.') . ' M';
        }
        if ($n >= 1_000_000) {
            return $tanda . number_format($n / 1_000_000, 1, ',', '.') . ' jt';
        }
        if ($n >= 1_000) {
            return $tanda . number_format($n / 1_000, 0, ',', '.') . ' rb';
        }

        return $tanda . number_format($n, 0, ',', '.');
    }
}

if (!function_exists('cost_avoidance_angka')) {
    function cost_avoidance_angka(mixed $nilai): ?float
    {
        if (is_int($nilai) || is_float($nilai)) {
            return (float) $nilai;
        }
        if (!is_string($nilai)) {
            return null;
        }

        $teks = trim($nilai);
        $teks = str_replace(['Rp', 'rp', ' '], '', $teks);
        if ($teks === '') {
            return null;
        }

        $punyaTitik = str_contains($teks, '.');
        $punyaKoma  = str_contains($teks, ',');

        if ($punyaTitik && $punyaKoma) {
            $teks = str_replace('.', '', $teks);
            $teks = str_replace(',', '.', $teks);
        } elseif ($punyaKoma) {
            $teks = str_replace(',', '.', $teks);
        } elseif ($punyaTitik && preg_match('/^\d{1,3}(\.\d{3})+$/', $teks) === 1) {
            $teks = str_replace('.', '', $teks);
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $teks) !== 1) {
            return null;
        }

        return (float) $teks;
    }
}

if (!function_exists('cost_avoidance_normal_periode')) {
    function cost_avoidance_normal_periode(mixed $nilai): ?string
    {
        $teks = trim((string) $nilai);
        if ($teks === '') {
            return null;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $teks, $m) === 1) {
            $bulan = (int) $m[2];
            if ($bulan < 1 || $bulan > 12) {
                return null;
            }

            return sprintf('%04d-%02d-01', (int) $m[1], $bulan);
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $teks, $m) === 1) {
            if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]);
        }

        $ts = strtotime($teks);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-01', $ts);
    }
}

if (!function_exists('cost_avoidance_teks_aman')) {
    function cost_avoidance_teks_aman(mixed $nilai, int $maks): string
    {
        $teks = trim((string) $nilai);
        if (mb_strlen($teks) > $maks) {
            $teks = mb_substr($teks, 0, $maks);
        }

        return $teks;
    }
}

if (!function_exists('cost_avoidance_validasi')) {
    /**
     * @return array{ok: bool, galat: array<string, string>, data: array<string, mixed>}
     */
    function cost_avoidance_validasi(array $in): array
    {
        $galat = [];

        $judul = cost_avoidance_teks_aman($in['judul'] ?? '', 200);
        if ($judul === '') {
            $galat['judul'] = 'Uraian wajib diisi.';
        }

        $kategori = (string) ($in['kategori'] ?? '');
        if (!in_array($kategori, cost_avoidance_kategori_daftar(), true)) {
            $galat['kategori'] = 'Pilih kategori yang tersedia.';
        }

        $periode = cost_avoidance_normal_periode($in['periode'] ?? '');
        if ($periode === null) {
            $galat['periode'] = 'Periode tidak valid.';
        }

        $estimasi = cost_avoidance_angka($in['estimasi'] ?? '');
        if ($estimasi === null) {
            $galat['estimasi'] = 'Nilai estimasi wajib berupa angka.';
        } elseif ($estimasi < 0) {
            $galat['estimasi'] = 'Nilai estimasi tidak boleh negatif.';
        }

        $realisasiMasuk = $in['realisasi'] ?? '';
        $realisasi = ($realisasiMasuk === '' || $realisasiMasuk === null)
            ? 0.0
            : cost_avoidance_angka($realisasiMasuk);
        if ($realisasi === null) {
            $galat['realisasi'] = 'Nilai realisasi wajib berupa angka.';
        } elseif ($realisasi < 0) {
            $galat['realisasi'] = 'Nilai realisasi tidak boleh negatif.';
        } elseif ($estimasi !== null && $realisasi > $estimasi) {
            $galat['realisasi'] = 'Realisasi tidak boleh melebihi estimasi.';
        }

        $deskripsi = cost_avoidance_teks_aman($in['deskripsi'] ?? '', 2000);
        $unit      = cost_avoidance_teks_aman($in['unit'] ?? '', 120);

        $nilai = ($estimasi !== null && $realisasi !== null) ? $estimasi - $realisasi : 0.0;

        return [
            'ok'    => $galat === [],
            'galat' => $galat,
            'data'  => [
                'judul'           => $judul,
                'kategori'        => $kategori,
                'deskripsi'       => $deskripsi,
                'unit'            => $unit,
                'periode'         => (string) $periode,
                'estimasi'        => (float) ($estimasi ?? 0),
                'realisasi'       => (float) ($realisasi ?? 0),
                'nilai_avoidance' => (float) $nilai,
            ],
        ];
    }
}

if (!function_exists('cost_avoidance_buat_kode')) {
    function cost_avoidance_buat_kode(mysqli $conn, ?int $tahun = null): string
    {
        $tahun = $tahun ?? (int) date('Y');
        $awalan = 'CA-' . $tahun . '-';
        $urut = 0;

        $stmt = $conn->prepare('SELECT kode FROM cost_avoidance WHERE kode LIKE ? ORDER BY kode DESC LIMIT 1');
        if ($stmt !== false) {
            $pola = $awalan . '%';
            $stmt->bind_param('s', $pola);
            $stmt->execute();
            $stmt->bind_result($kode);
            if ($stmt->fetch() && is_string($kode)) {
                $urut = (int) substr($kode, -4);
            }
            $stmt->close();
        }

        return $awalan . str_pad((string) ($urut + 1), 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('cost_avoidance_simpan')) {
    /**
     * @param  array<string, mixed>  $d
     * @return array{ok: bool, id: int, galat: string}
     */
    function cost_avoidance_simpan(mysqli $conn, array $d, int $uid, ?int $id = null): array
    {
        $judul     = (string) $d['judul'];
        $kategori  = (string) $d['kategori'];
        $deskripsi = (string) ($d['deskripsi'] ?? '');
        $unit      = (string) ($d['unit'] ?? '');
        $periode   = (string) $d['periode'];
        $estimasi  = number_format((float) $d['estimasi'], 2, '.', '');
        $realisasi = number_format((float) $d['realisasi'], 2, '.', '');
        $nilai     = number_format((float) $d['nilai_avoidance'], 2, '.', '');

        if ($id !== null && $id > 0) {
            $sql = 'UPDATE cost_avoidance SET
                        judul = ?, kategori = ?, deskripsi = ?, unit = ?, periode = ?,
                        estimasi = ?, realisasi = ?, nilai_avoidance = ?,
                        status = ?, catatan_verifikasi = NULL,
                        diverifikasi_oleh = NULL, diverifikasi_pada = NULL
                    WHERE id = ?';
            $stmt = $conn->prepare($sql);
            if ($stmt === false) {
                return ['ok' => false, 'id' => 0, 'galat' => 'Gagal menyiapkan penyimpanan.'];
            }

            $status = 'draft';
            $stmt->bind_param(
                'sssssssssi',
                $judul, $kategori, $deskripsi, $unit, $periode,
                $estimasi, $realisasi, $nilai, $status, $id
            );

            $ok = $stmt->execute();
            $stmt->close();

            return $ok
                ? ['ok' => true, 'id' => $id, 'galat' => '']
                : ['ok' => false, 'id' => 0, 'galat' => 'Gagal memperbarui data.'];
        }

        $kode   = cost_avoidance_buat_kode($conn, (int) substr($periode, 0, 4));
        $status = 'draft';

        $sql = 'INSERT INTO cost_avoidance
                    (kode, judul, kategori, deskripsi, unit, periode,
                     estimasi, realisasi, nilai_avoidance, status, dibuat_oleh)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return ['ok' => false, 'id' => 0, 'galat' => 'Gagal menyiapkan penyimpanan.'];
        }

        $stmt->bind_param(
            'ssssssssssi',
            $kode, $judul, $kategori, $deskripsi, $unit, $periode,
            $estimasi, $realisasi, $nilai, $status, $uid
        );

        $ok = $stmt->execute();
        $baru = (int) $conn->insert_id;
        $stmt->close();

        return $ok
            ? ['ok' => true, 'id' => $baru, 'galat' => '']
            : ['ok' => false, 'id' => 0, 'galat' => 'Gagal menyimpan data.'];
    }
}

if (!function_exists('cost_avoidance_satu')) {
    /** @return array<string, mixed>|null */
    function cost_avoidance_satu(mysqli $conn, int $id): ?array
    {
        $sql = 'SELECT c.*, u.nama AS nama_pembuat, v.nama AS nama_verifikator
                FROM cost_avoidance c
                LEFT JOIN users u ON u.id = c.dibuat_oleh
                LEFT JOIN users v ON v.id = c.diverifikasi_oleh
                WHERE c.id = ? LIMIT 1';
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            return null;
        }

        $stmt->bind_param('i', $id);
        $stmt->execute();
        $hasil = $stmt->get_result();
        $baris = $hasil !== false ? $hasil->fetch_assoc() : null;
        $stmt->close();

        return is_array($baris) ? $baris : null;
    }
}

if (!function_exists('cost_avoidance_ambil')) {
    /**
     * @param  array{status?: string, kategori?: string, bulan?: string, pemilik?: int}  $f
     * @return array{baris: list<array<string, mixed>>, total: int}
     */
    function cost_avoidance_ambil(mysqli $conn, array $f = [], int $limit = 25, int $offset = 0): array
    {
        $syarat = [];
        $tipe   = '';
        $nilai  = [];

        $status = (string) ($f['status'] ?? '');
        if (in_array($status, cost_avoidance_status_daftar(), true)) {
            $syarat[] = 'c.status = ?';
            $tipe .= 's';
            $nilai[] = $status;
        }

        $kategori = (string) ($f['kategori'] ?? '');
        if (in_array($kategori, cost_avoidance_kategori_daftar(), true)) {
            $syarat[] = 'c.kategori = ?';
            $tipe .= 's';
            $nilai[] = $kategori;
        }

        $bulan = (string) ($f['bulan'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $bulan) === 1) {
            $syarat[] = 'c.periode >= ? AND c.periode <= ?';
            $tipe .= 'ss';
            $nilai[] = $bulan . '-01';
            $nilai[] = date('Y-m-t', (int) strtotime($bulan . '-01'));
        }

        $pemilik = (int) ($f['pemilik'] ?? 0);
        if ($pemilik > 0) {
            $syarat[] = 'c.dibuat_oleh = ?';
            $tipe .= 'i';
            $nilai[] = $pemilik;
        }

        $where = $syarat === [] ? '' : ' WHERE ' . implode(' AND ', $syarat);

        $total = 0;
        $stmtHitung = $conn->prepare('SELECT COUNT(*) FROM cost_avoidance c' . $where);
        if ($stmtHitung !== false) {
            if ($nilai !== []) {
                $stmtHitung->bind_param($tipe, ...$nilai);
            }
            $stmtHitung->execute();
            $stmtHitung->bind_result($jumlah);
            if ($stmtHitung->fetch()) {
                $total = (int) $jumlah;
            }
            $stmtHitung->close();
        }

        $sql = 'SELECT c.*, u.nama AS nama_pembuat, v.nama AS nama_verifikator
                FROM cost_avoidance c
                LEFT JOIN users u ON u.id = c.dibuat_oleh
                LEFT JOIN users v ON v.id = c.diverifikasi_oleh'
            . $where
            . ' ORDER BY c.periode DESC, c.id DESC LIMIT ? OFFSET ?';

        $baris = [];
        $stmt = $conn->prepare($sql);
        if ($stmt !== false) {
            $tipeBaris = $tipe . 'ii';
            $nilaiBaris = array_merge($nilai, [$limit, $offset]);
            $stmt->bind_param($tipeBaris, ...$nilaiBaris);
            $stmt->execute();
            $hasil = $stmt->get_result();
            if ($hasil !== false) {
                while (($row = $hasil->fetch_assoc()) !== null) {
                    $baris[] = $row;
                }
            }
            $stmt->close();
        }

        return ['baris' => $baris, 'total' => $total];
    }
}

if (!function_exists('cost_avoidance_ringkas')) {
    /**
     * @param  array{bulan?: string, pemilik?: int}  $f
     * @return array<string, mixed>
     */
    function cost_avoidance_ringkas(mysqli $conn, array $f = []): array
    {
        $syarat = [];
        $tipe   = '';
        $nilai  = [];

        $bulan = (string) ($f['bulan'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $bulan) === 1) {
            $syarat[] = 'periode >= ? AND periode <= ?';
            $tipe .= 'ss';
            $nilai[] = $bulan . '-01';
            $nilai[] = date('Y-m-t', (int) strtotime($bulan . '-01'));
        }

        $pemilik = (int) ($f['pemilik'] ?? 0);
        if ($pemilik > 0) {
            $syarat[] = 'dibuat_oleh = ?';
            $tipe .= 'i';
            $nilai[] = $pemilik;
        }

        $where = $syarat === [] ? '' : ' WHERE ' . implode(' AND ', $syarat);

        $jumlah = ['draft' => 0, 'diajukan' => 0, 'disetujui' => 0, 'ditolak' => 0];
        $total = 0.0;

        $stmt = $conn->prepare('SELECT status, COUNT(*) AS jml, COALESCE(SUM(nilai_avoidance), 0) AS nilai
                                FROM cost_avoidance' . $where . ' GROUP BY status');
        if ($stmt !== false) {
            if ($nilai !== []) {
                $stmt->bind_param($tipe, ...$nilai);
            }
            $stmt->execute();
            $hasil = $stmt->get_result();
            if ($hasil !== false) {
                while (($row = $hasil->fetch_assoc()) !== null) {
                    $st = (string) $row['status'];
                    $jumlah[$st] = (int) $row['jml'];
                    if ($st === 'disetujui') {
                        $total = (float) $row['nilai'];
                    }
                }
            }
            $stmt->close();
        }

        $perKategori = [];
        $stmtKategori = $conn->prepare(
            'SELECT kategori, COALESCE(SUM(nilai_avoidance), 0) AS nilai, COUNT(*) AS jml
             FROM cost_avoidance' . ($where === '' ? ' WHERE' : $where . ' AND') . " status = 'disetujui'
             GROUP BY kategori ORDER BY nilai DESC"
        );
        if ($stmtKategori !== false) {
            if ($nilai !== []) {
                $stmtKategori->bind_param($tipe, ...$nilai);
            }
            $stmtKategori->execute();
            $hasil = $stmtKategori->get_result();
            if ($hasil !== false) {
                while (($row = $hasil->fetch_assoc()) !== null) {
                    $perKategori[] = [
                        'nama'  => (string) $row['kategori'],
                        'nilai' => (float) $row['nilai'],
                        'jumlah' => (int) $row['jml'],
                    ];
                }
            }
            $stmtKategori->close();
        }

        return [
            'total_disetujui' => $total,
            'jumlah'          => $jumlah,
            'per_kategori'    => $perKategori,
            'ada'             => array_sum($jumlah) > 0,
        ];
    }
}

if (!function_exists('cost_avoidance_peran_penuh')) {
    function cost_avoidance_peran_penuh(string $role): bool
    {
        return in_array($role, ['mentor', 'admin'], true);
    }
}

if (!function_exists('cost_avoidance_boleh_lihat')) {
    /** @param array<string, mixed> $row */
    function cost_avoidance_boleh_lihat(array $row, int $uid, string $role): bool
    {
        return cost_avoidance_peran_penuh($role) || (int) ($row['dibuat_oleh'] ?? 0) === $uid;
    }
}

if (!function_exists('cost_avoidance_boleh_ubah')) {
    /** @param array<string, mixed> $row */
    function cost_avoidance_boleh_ubah(array $row, int $uid, string $role): bool
    {
        $status = (string) ($row['status'] ?? '');
        if (!in_array($status, cost_avoidance_status_terbuka(), true)) {
            return false;
        }

        return (int) ($row['dibuat_oleh'] ?? 0) === $uid || $role === 'admin';
    }
}

if (!function_exists('cost_avoidance_boleh_verifikasi')) {
    /** @param array<string, mixed> $row */
    function cost_avoidance_boleh_verifikasi(array $row, int $uid, string $role): bool
    {
        if (!cost_avoidance_peran_penuh($role)) {
            return false;
        }
        if ((string) ($row['status'] ?? '') !== 'diajukan') {
            return false;
        }

        return $role === 'admin' || (int) ($row['dibuat_oleh'] ?? 0) !== $uid;
    }
}

if (!function_exists('cost_avoidance_ajukan')) {
    /** @return array{ok: bool, pesan: string} */
    function cost_avoidance_ajukan(mysqli $conn, int $id, int $uid, string $role): array
    {
        $row = cost_avoidance_satu($conn, $id);
        if ($row === null) {
            return ['ok' => false, 'pesan' => 'Data tidak ditemukan.'];
        }
        if (!cost_avoidance_boleh_ubah($row, $uid, $role)) {
            return ['ok' => false, 'pesan' => 'Data ini tidak dapat diajukan.'];
        }
        if ((float) $row['nilai_avoidance'] <= 0) {
            return ['ok' => false, 'pesan' => 'Nilai avoidance harus lebih besar dari nol.'];
        }

        $stmt = $conn->prepare("UPDATE cost_avoidance
                                SET status = 'diajukan', diajukan_pada = NOW(), catatan_verifikasi = NULL
                                WHERE id = ?");
        if ($stmt === false) {
            return ['ok' => false, 'pesan' => 'Gagal mengajukan data.'];
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok
            ? ['ok' => true, 'pesan' => 'Data diajukan untuk verifikasi.']
            : ['ok' => false, 'pesan' => 'Gagal mengajukan data.'];
    }
}

if (!function_exists('cost_avoidance_verifikasi')) {
    /** @return array{ok: bool, pesan: string} */
    function cost_avoidance_verifikasi(
        mysqli $conn,
        int $id,
        int $uid,
        string $role,
        string $keputusan,
        string $catatan = ''
    ): array {
        if (!in_array($keputusan, ['setujui', 'tolak'], true)) {
            return ['ok' => false, 'pesan' => 'Keputusan tidak dikenal.'];
        }

        $row = cost_avoidance_satu($conn, $id);
        if ($row === null) {
            return ['ok' => false, 'pesan' => 'Data tidak ditemukan.'];
        }
        if (!cost_avoidance_boleh_verifikasi($row, $uid, $role)) {
            return ['ok' => false, 'pesan' => 'Anda tidak berhak memverifikasi data ini.'];
        }

        $catatan = cost_avoidance_teks_aman($catatan, 255);
        if ($keputusan === 'tolak' && $catatan === '') {
            return ['ok' => false, 'pesan' => 'Catatan wajib diisi saat menolak.'];
        }

        $status = $keputusan === 'setujui' ? 'disetujui' : 'ditolak';
        $catatanSimpan = $catatan === '' ? null : $catatan;

        $stmt = $conn->prepare('UPDATE cost_avoidance
                                SET status = ?, catatan_verifikasi = ?,
                                    diverifikasi_oleh = ?, diverifikasi_pada = NOW()
                                WHERE id = ?');
        if ($stmt === false) {
            return ['ok' => false, 'pesan' => 'Gagal menyimpan verifikasi.'];
        }
        $stmt->bind_param('ssii', $status, $catatanSimpan, $uid, $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok
            ? ['ok' => true, 'pesan' => $keputusan === 'setujui' ? 'Data disetujui.' : 'Data ditolak.']
            : ['ok' => false, 'pesan' => 'Gagal menyimpan verifikasi.'];
    }
}

if (!function_exists('cost_avoidance_hapus')) {
    /** @param array<string, mixed> $row */
    function cost_avoidance_hapus(mysqli $conn, array $row): bool
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return false;
        }

        $stmt = $conn->prepare('DELETE FROM cost_avoidance WHERE id = ?');
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}
