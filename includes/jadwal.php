<?php
/**
 * includes/jadwal.php
 * Agenda & pengingat kegiatan per pengguna (tabel `jadwal`).
 *
 * Dipakai oleh:
 *   · jadwal.php            — halaman kelola agenda
 *   · scripts/pengingat_jadwal.php — pengirim pengingat terjadwal
 *   · ai-service (alat AI)  — lewat tabel yang sama
 *
 * Fungsi murni (jadwal_validasi, jadwal_normal_mulai) tidak menyentuh basis
 * data supaya mudah diuji.
 *
 * Seluruh berkas ini ditulis dengan karakter ASCII saja.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

if (!function_exists('jadwal_status_tersedia')) {
    /** @return list<string> */
    function jadwal_status_tersedia(): array
    {
        return ['rencana', 'selesai', 'batal'];
    }
}

if (!function_exists('jadwal_label_status')) {
    function jadwal_label_status(string $status): string
    {
        return match ($status) {
            'rencana' => 'Direncanakan',
            'selesai' => 'Selesai',
            'batal'   => 'Dibatalkan',
            default   => ucfirst($status),
        };
    }
}

if (!function_exists('jadwal_kelas_status')) {
    function jadwal_kelas_status(string $status): string
    {
        return match ($status) {
            'selesai' => 'jadwal-selesai',
            'batal'   => 'jadwal-batal',
            default   => 'jadwal-rencana',
        };
    }
}

if (!function_exists('jadwal_hari_id')) {
    function jadwal_hari_id(int $n): string
    {
        return ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][$n] ?? '';
    }
}

if (!function_exists('jadwal_bulan_id')) {
    function jadwal_bulan_id(int $n): string
    {
        return ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
                'Agustus', 'September', 'Oktober', 'November', 'Desember'][$n] ?? '';
    }
}

if (!function_exists('jadwal_normal_mulai')) {
    /** Normalisasi masukan waktu menjadi Y-m-d H:i:s, atau null bila gagal. */
    function jadwal_normal_mulai(mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }
        if ($nilai instanceof \DateTimeInterface) {
            return $nilai->format('Y-m-d H:i:s');
        }
        $teks = trim((string) $nilai);
        if ($teks === '') {
            return null;
        }
        // Terima "d/m/Y H:i" gaya Indonesia.
        if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})(?:[ T](\d{1,2})[:.](\d{2}))?$#', $teks, $m)) {
            $teks = sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $m[3], (int) $m[2], (int) $m[1],
                (int) ($m[4] ?? 0), (int) ($m[5] ?? 0));
        }
        // Terima "Y-m-d" saja -> 08:00.
        if (preg_match('#^\d{4}-\d{1,2}-\d{1,2}$#', $teks)) {
            $teks .= ' 08:00:00';
        }
        // Terima "Y-m-d H:i" -> tambah detik.
        if (preg_match('#^\d{4}-\d{1,2}-\d{1,2}[ T]\d{1,2}:\d{2}$#', $teks)) {
            $teks .= ':00';
        }

        $ts = strtotime($teks);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }
}

if (!function_exists('jadwal_validasi')) {
    /**
     * Validasi & normalisasi masukan jadwal (murni, tanpa basis data).
     *
     * @return array{ok: bool, galat: array<string,string>, data: array<string,mixed>}
     */
    function jadwal_validasi(array $in): array
    {
        $galat = [];
        $judul = trim((string) ($in['judul'] ?? ''));
        if ($judul === '') {
            $galat['judul'] = 'Judul jadwal wajib diisi.';
        } elseif (mb_strlen($judul) > 200) {
            $galat['judul'] = 'Judul maksimal 200 karakter.';
        }

        $mulai = jadwal_normal_mulai($in['mulai'] ?? null);
        if ($mulai === null) {
            $galat['mulai'] = 'Waktu mulai tidak valid.';
        }

        $selesai = null;
        $selesaiMentah = trim((string) ($in['selesai'] ?? ''));
        if ($selesaiMentah !== '') {
            $selesai = jadwal_normal_mulai($selesaiMentah);
            if ($selesai === null) {
                $galat['selesai'] = 'Waktu selesai tidak valid.';
            } elseif ($mulai !== null && $selesai < $mulai) {
                $galat['selesai'] = 'Waktu selesai tidak boleh sebelum waktu mulai.';
            }
        }

        $menit = (int) ($in['pengingat_menit'] ?? 30);
        if ($menit < 0 || $menit > 10080) {
            $galat['pengingat_menit'] = 'Pengingat antara 0 dan 10080 menit (7 hari).';
        }

        $status = (string) ($in['status'] ?? 'rencana');
        if (!in_array($status, jadwal_status_tersedia(), true)) {
            $status = 'rencana';
        }

        $lokasi = trim((string) ($in['lokasi'] ?? ''));
        if (mb_strlen($lokasi) > 200) {
            $galat['lokasi'] = 'Lokasi maksimal 200 karakter.';
        }
        $deskripsi = trim((string) ($in['deskripsi'] ?? ''));

        return [
            'ok' => $galat === [],
            'galat' => $galat,
            'data' => [
                'judul' => $judul,
                'deskripsi' => $deskripsi,
                'mulai' => $mulai,
                'selesai' => $selesai,
                'lokasi' => $lokasi,
                'pengingat_menit' => $menit,
                'status' => $status,
            ],
        ];
    }
}

/* ---------------------------------------------------------------
 * Akses basis data
 * ------------------------------------------------------------- */

if (!function_exists('jadwal_satu')) {
    /** Ambil satu jadwal (opsional dibatasi pemiliknya). */
    function jadwal_satu(mysqli $conn, int $id, ?int $userId = null): ?array
    {
        $sql = 'SELECT * FROM jadwal WHERE id = ?';
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
        }
        $sql .= ' LIMIT 1';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($userId !== null) {
            $stmt->bind_param('ii', $id, $userId);
        } else {
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('jadwal_ambil')) {
    /**
     * Ambil daftar jadwal pada rentang tanggal.
     *
     * @param array{status?:string,kata_kunci?:string} $filter
     * @return list<array<string,mixed>>
     */
    function jadwal_ambil(mysqli $conn, int $userId, string $dari, string $sampai, array $filter = []): array
    {
        $sql = 'SELECT * FROM jadwal WHERE user_id = ? AND DATE(mulai) BETWEEN ? AND ?';
        $tipe = 'iss';
        $params = [$userId, $dari, $sampai];

        $status = (string) ($filter['status'] ?? '');
        if ($status !== '' && in_array($status, jadwal_status_tersedia(), true)) {
            $sql .= ' AND status = ?';
            $tipe .= 's';
            $params[] = $status;
        }
        $kata = trim((string) ($filter['kata_kunci'] ?? ''));
        if ($kata !== '') {
            $sql .= ' AND (judul LIKE ? OR deskripsi LIKE ? OR lokasi LIKE ?)';
            $like = '%' . $kata . '%';
            $tipe .= 'sss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        $sql .= ' ORDER BY mulai ASC, id ASC';

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($tipe, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasil = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $hasil[] = $row;
        }
        $stmt->close();
        return $hasil;
    }
}

if (!function_exists('jadwal_mendatang')) {
    /** Jadwal terdekat yang belum selesai/dibatalkan. */
    function jadwal_mendatang(mysqli $conn, int $userId, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $stmt = $conn->prepare(
            "SELECT * FROM jadwal
              WHERE user_id = ? AND status = 'rencana' AND mulai >= NOW()
              ORDER BY mulai ASC LIMIT ?"
        );
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $hasil = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $hasil[] = $row;
        }
        $stmt->close();
        return $hasil;
    }
}

if (!function_exists('jadwal_simpan')) {
    /**
     * Simpan (buat/ubah) jadwal. Bila $id null -> buat baru untuk $userId.
     *
     * @param array<string,mixed> $data hasil jadwal_validasi()['data']
     * @return array{ok: bool, galat: string, id: int}
     */
    function jadwal_simpan(mysqli $conn, array $data, int $userId, ?int $id = null): array
    {
        /* Resolusi koordinat dari teks lokasi (bila pustaka wilayah tersedia). */
        $latDb = '';
        $lngDb = '';
        $widDb = '';
        $lokasiTeks = trim((string) ($data['lokasi'] ?? ''));
        if ($lokasiTeks !== '' && function_exists('wilayah_cari_teks')) {
            $w = wilayah_cari_teks($conn, $lokasiTeks);
            if ($w !== null && wilayah_koordinat_valid($w['garis_lintang'] ?? null, $w['garis_bujur'] ?? null)) {
                $latDb = number_format((float) $w['garis_lintang'], 7, '.', '');
                $lngDb = number_format((float) $w['garis_bujur'], 7, '.', '');
                $widDb = (string) (int) $w['id'];
            }
        }

        if ($id === null) {
            $stmt = $conn->prepare(
                'INSERT INTO jadwal
                    (user_id, judul, deskripsi, mulai, selesai, lokasi, pengingat_menit, status,
                     garis_lintang, garis_bujur, wilayah_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))'
            );
            if (!$stmt) {
                return ['ok' => false, 'galat' => 'Gagal menyiapkan kueri simpan.', 'id' => 0];
            }
            $stmt->bind_param(
                'isssssissss',
                $userId, $data['judul'], $data['deskripsi'], $data['mulai'],
                $data['selesai'], $data['lokasi'], $data['pengingat_menit'], $data['status'],
                $latDb, $lngDb, $widDb
            );
            $ok = $stmt->execute();
            $baru = $ok ? (int) $conn->insert_id : 0;
            $stmt->close();
            return ['ok' => (bool) $ok, 'galat' => $ok ? '' : 'Gagal menyimpan jadwal.', 'id' => $baru];
        }

        $stmt = $conn->prepare(
            'UPDATE jadwal SET judul = ?, deskripsi = ?, mulai = ?, selesai = ?, lokasi = ?,
                pengingat_menit = ?, status = ?, pengingat_terkirim = 0,
                garis_lintang = NULLIF(?, \'\'), garis_bujur = NULLIF(?, \'\'), wilayah_id = NULLIF(?, \'\')
              WHERE id = ? AND user_id = ?'
        );
        if (!$stmt) {
            return ['ok' => false, 'galat' => 'Gagal menyiapkan kueri ubah.', 'id' => 0];
        }
        $stmt->bind_param(
            'sssssissssii',
            $data['judul'], $data['deskripsi'], $data['mulai'], $data['selesai'],
            $data['lokasi'], $data['pengingat_menit'], $data['status'],
            $latDb, $lngDb, $widDb, $id, $userId
        );
        $ok = $stmt->execute();
        $terpengaruh = $stmt->affected_rows;
        $stmt->close();
        if (!$ok) {
            return ['ok' => false, 'galat' => 'Gagal mengubah jadwal.', 'id' => 0];
        }
        return ['ok' => true, 'galat' => '', 'id' => $terpengaruh > 0 ? $id : $id];
    }
}

if (!function_exists('jadwal_hapus')) {
    function jadwal_hapus(mysqli $conn, int $id, int $userId): bool
    {
        $stmt = $conn->prepare('DELETE FROM jadwal WHERE id = ? AND user_id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $id, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

if (!function_exists('jadwal_ubah_status')) {
    function jadwal_ubah_status(mysqli $conn, int $id, int $userId, string $status): bool
    {
        if (!in_array($status, jadwal_status_tersedia(), true)) {
            return false;
        }
        $stmt = $conn->prepare('UPDATE jadwal SET status = ? WHERE id = ? AND user_id = ?');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('sii', $status, $id, $userId);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool) $ok;
    }
}

/* ---------------------------------------------------------------
 * Pengingat
 * ------------------------------------------------------------- */

if (!function_exists('jadwal_pengingat_jatuh_tempo')) {
    /**
     * Jadwal yang pengingatnya sudah waktunya dikirim (status rencana).
     * Pengingat jatuh tempo = mulai - pengingat_menit menit.
     *
     * @return list<array<string,mixed>>
     */
    function jadwal_pengingat_jatuh_tempo(mysqli $conn, int $maks = 100): array
    {
        $maks = max(1, min(1000, $maks));
        $sql = "SELECT j.*, u.nama AS nama_user, u.email AS email_user, u.telepon AS telepon_user
                  FROM jadwal j
                  JOIN users u ON u.id = j.user_id
                 WHERE j.status = 'rencana'
                   AND j.pengingat_terkirim = 0
                   AND DATE_SUB(j.mulai, INTERVAL j.pengingat_menit MINUTE) <= NOW()
                   AND j.mulai >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 ORDER BY j.mulai ASC
                 LIMIT " . $maks;
        $res = $conn->query($sql);
        $hasil = [];
        while ($res && ($row = $res->fetch_assoc())) {
            $hasil[] = $row;
        }
        return $hasil;
    }
}

if (!function_exists('jadwal_tandai_pengingat')) {
    function jadwal_tandai_pengingat(mysqli $conn, array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn ($v) => $v > 0));
        if ($ids === []) {
            return;
        }
        $in = implode(',', $ids);
        $conn->query('UPDATE jadwal SET pengingat_terkirim = 1 WHERE id IN (' . $in . ')');
    }
}

if (!function_exists('jadwal_kirim_pengingat')) {
    /**
     * Kirim pengingat untuk satu jadwal: notifikasi aplikasi + surel + WhatsApp
     * (bila tersedia). Mengembalikan status per kanal.
     *
     * @return array{aplikasi: bool, surel: bool, whatsapp: bool}
     */
    function jadwal_kirim_pengingat(mysqli $conn, array $jadwal): array
    {
        $hasil = ['aplikasi' => false, 'surel' => false, 'whatsapp' => false];

        $userId = (int) $jadwal['user_id'];
        $judul = (string) $jadwal['judul'];
        $mulai = (string) $jadwal['mulai'];
        $lokasi = (string) ($jadwal['lokasi'] ?? '');
        $deskripsi = (string) ($jadwal['deskripsi'] ?? '');
        $nama = (string) ($jadwal['nama_user'] ?? '');
        $email = (string) ($jadwal['email_user'] ?? '');
        $telepon = (string) ($jadwal['telepon_user'] ?? '');

        $kapan = date('d/m/Y H:i', strtotime($mulai));
        $ringkas = 'Pengingat: "' . $judul . '" pada ' . $kapan . '.';

        if (function_exists('buat_notifikasi')) {
            $hasil['aplikasi'] = buat_notifikasi($conn, $userId, mb_substr($ringkas, 0, 500), null, 'jadwal.php');
        }

        $badan = '<p style="margin:0 0 14px;color:#334155;font-size:14px;">Halo ' . e($nama)
            . ', Anda memiliki jadwal yang akan datang.</p>'
            . '<div style="background:#F0F7FF;border:1px solid #BAE0FF;border-radius:8px;padding:12px 14px;'
            . 'color:#0B3D91;font-size:14px;"><strong>' . e($judul) . '</strong><br>'
            . 'Waktu: ' . e($kapan)
            . ($lokasi !== '' ? '<br>Lokasi: ' . e($lokasi) : '')
            . ($deskripsi !== '' ? '<br><br>' . nl2br(e($deskripsi)) : '')
            . '</div>';

        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && function_exists('kirim_email')
            && function_exists('template_surat')) {
            $html = template_surat('Pengingat Jadwal', $badan, 'Pesan otomatis dari ' . e(APP_NAMA) . '.');
            $teks = $ringkas . ($lokasi !== '' ? "\nLokasi: " . $lokasi : '');
            $kirim = kirim_email($email, 'Pengingat Jadwal - ' . APP_NAMA, $html, $teks);
            $hasil['surel'] = (bool) ($kirim['ok'] ?? false);
        }

        if ($telepon !== '' && function_exists('wa_aktif') && function_exists('kirim_whatsapp') && wa_aktif()) {
            $wa = kirim_whatsapp($telepon, "*Pengingat Jadwal*\n\n" . $ringkas
                . ($lokasi !== '' ? "\nLokasi: " . $lokasi : ''));
            $hasil['whatsapp'] = (bool) ($wa['ok'] ?? false);
        }

        return $hasil;
    }
}

if (!function_exists('jadwal_jalankan_pengingat')) {
    /**
     * Proses semua pengingat yang jatuh tempo.
     *
     * @return array{total:int, terkirim:int, aplikasi:int, surel:int, whatsapp:int, rincian:list<array<string,mixed>>}
     */
    function jadwal_jalankan_pengingat(mysqli $conn, bool $kering = false, int $maks = 100): array
    {
        $daftar = jadwal_pengingat_jatuh_tempo($conn, $maks);
        $hasil = ['total' => count($daftar), 'terkirim' => 0, 'aplikasi' => 0, 'surel' => 0, 'whatsapp' => 0, 'rincian' => []];

        foreach ($daftar as $jadwal) {
            if ($kering) {
                $hasil['rincian'][] = ['id' => (int) $jadwal['id'], 'judul' => $jadwal['judul'], 'uji' => true];
                continue;
            }
            $kanal = jadwal_kirim_pengingat($conn, $jadwal);
            jadwal_tandai_pengingat($conn, [(int) $jadwal['id']]);
            $hasil['terkirim']++;
            $hasil['aplikasi'] += $kanal['aplikasi'] ? 1 : 0;
            $hasil['surel'] += $kanal['surel'] ? 1 : 0;
            $hasil['whatsapp'] += $kanal['whatsapp'] ? 1 : 0;
            $hasil['rincian'][] = ['id' => (int) $jadwal['id'], 'judul' => $jadwal['judul'], 'kanal' => $kanal];
        }

        return $hasil;
    }
}
