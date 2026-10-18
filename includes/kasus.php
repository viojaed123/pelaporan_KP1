<?php
/**
 * includes/kasus.php
 * Pustaka bersama modul Pelacakan Kasus (multi-pengguna).
 *
 * Isi:
 *   - konstanta pilihan (status, urgensi, kategori, sumber)
 *   - aturan visibilitas & izin (siapa boleh melihat/mengelola/menghapus)
 *   - pembantu pengambilan & penulisan (kode kasus, linimasa status,
 *     penugasan tim, notifikasi pihak terkait)
 *
 * Catatan kepemilikan: tabel `kasus` kini punya kolom `dibuat_oleh`
 * (pembuat) dan `penanggung_jawab_id` (PIC pengguna), ditambah tabel
 * `kasus_penugasan` untuk anggota tim. Karyawan hanya melihat kasus yang
 * ia buat / ditugaskan padanya / ia menjadi anggota timnya; mentor & admin
 * melihat seluruh kasus.
 */

declare(strict_types=1);

if (!defined('APP_NAMA')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

/* ===============================================================
 * 1. PILIHAN (nilai sah)
 * ============================================================= */

if (!function_exists('kasus_status_daftar')) {
    /** @return list<string> */
    function kasus_status_daftar(): array
    {
        return ['Terbuka', 'Dalam Proses', 'Selesai'];
    }
}

if (!function_exists('kasus_urgensi_daftar')) {
    /** @return list<string> */
    function kasus_urgensi_daftar(): array
    {
        return ['Rendah', 'Sedang', 'Tinggi', 'Kritis'];
    }
}

if (!function_exists('kasus_kategori_daftar')) {
    /** @return list<string> */
    function kasus_kategori_daftar(): array
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

if (!function_exists('kasus_label_sumber')) {
    function kasus_label_sumber(string $sumber): string
    {
        return match (strtolower(trim($sumber))) {
            'srm'   => 'SRM',
            'cid'   => 'CID',
            default => 'Manual',
        };
    }
}

if (!function_exists('kelas_status_kasus')) {
    /** Kelas badge status kasus (lihat app.css). */
    function kelas_status_kasus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'terbuka'      => 'kasus-terbuka',
            'dalam proses' => 'kasus-dalam-proses',
            'selesai'      => 'kasus-selesai',
            default        => 'badge-navy',
        };
    }
}

/* ===============================================================
 * 2. VISIBILITAS & IZIN
 * ============================================================= */

if (!function_exists('kasus_peran_penuh')) {
    /** Mentor & admin melihat/mengelola seluruh kasus. */
    function kasus_peran_penuh(?string $role = null): bool
    {
        $role = $role ?? sesi_role();
        return in_array($role, ['mentor', 'admin'], true);
    }
}

if (!function_exists('kasus_sql_visibilitas')) {
    /**
     * Klausa SQL (tanpa parameter) yang membatasi baris kasus sesuai peran
     * pengguna saat ini. Mengembalikan '' bila pengguna melihat semua kasus.
     *
     * @param string $alias alias tabel kasus pada kueri
     */
    function kasus_sql_visibilitas(string $alias = 'k'): string
    {
        if (kasus_peran_penuh()) {
            return '';
        }
        $uid = (int) sesi_user_id();
        if ($uid < 1) {
            // Tanpa identitas: jangan tampilkan apa pun.
            return '0 = 1';
        }
        $p = $alias !== '' ? $alias . '.' : '';
        return "({$p}dibuat_oleh = {$uid}"
            . " OR {$p}penanggung_jawab_id = {$uid}"
            . " OR EXISTS (SELECT 1 FROM kasus_penugasan kx"
            . " WHERE kx.kasus_id = {$p}id AND kx.user_id = {$uid}))";
    }
}

if (!function_exists('kasus_ambil')) {
    /** Ambil satu baris kasus berdasarkan id (atau null). */
    function kasus_ambil(mysqli $conn, int $id): ?array
    {
        if ($id < 1) {
            return null;
        }
        $stmt = $conn->prepare('SELECT * FROM kasus WHERE id = ? LIMIT 1');
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('kasus_pengguna_terkait')) {
    /** Apakah pengguna terdaftar sebagai anggota tim kasus. */
    function kasus_pengguna_terkait(mysqli $conn, int $kasusId, int $userId): bool
    {
        if ($kasusId < 1 || $userId < 1) {
            return false;
        }
        $stmt = $conn->prepare('SELECT 1 FROM kasus_penugasan WHERE kasus_id = ? AND user_id = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ii', $kasusId, $userId);
        $stmt->execute();
        $ada = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();
        return $ada;
    }
}

if (!function_exists('kasus_izin_lihat_dasar')) {
    /**
     * Bagian izin yang tidak menyentuh basis data: mentor/admin, pembuat,
     * atau PIC. Pemeriksaan keanggotaan tim dilakukan terpisah.
     */
    function kasus_izin_lihat_dasar(array $kasus, ?int $userId = null, ?string $role = null): bool
    {
        $role   = $role ?? sesi_role();
        $userId = $userId ?? (int) sesi_user_id();
        if (kasus_peran_penuh($role)) {
            return true;
        }
        if ((int) ($kasus['dibuat_oleh'] ?? 0) === $userId) {
            return true;
        }
        if ((int) ($kasus['penanggung_jawab_id'] ?? 0) === $userId) {
            return true;
        }
        return false;
    }
}

if (!function_exists('kasus_izin_lihat')) {
    /** Izin melihat detail kasus (termasuk anggota tim). */
    function kasus_izin_lihat(mysqli $conn, array $kasus, ?int $userId = null, ?string $role = null): bool
    {
        if (kasus_izin_lihat_dasar($kasus, $userId, $role)) {
            return true;
        }
        $userId = $userId ?? (int) sesi_user_id();
        return kasus_pengguna_terkait($conn, (int) ($kasus['id'] ?? 0), $userId);
    }
}

if (!function_exists('kasus_izin_kelola')) {
    /** Izin mengubah kasus, status, dan riwayatnya. */
    function kasus_izin_kelola(mysqli $conn, array $kasus, ?int $userId = null, ?string $role = null): bool
    {
        // Sama dengan izin melihat: pembuat, PIC, anggota tim, mentor/admin.
        return kasus_izin_lihat($conn, $kasus, $userId, $role);
    }
}

if (!function_exists('kasus_izin_hapus')) {
    /** Izin menghapus kasus: pembuat sendiri, atau mentor/admin. */
    function kasus_izin_hapus(mysqli $conn, array $kasus, ?int $userId = null, ?string $role = null): bool
    {
        $role   = $role ?? sesi_role();
        $userId = $userId ?? (int) sesi_user_id();
        if (kasus_peran_penuh($role)) {
            return true;
        }
        return (int) ($kasus['dibuat_oleh'] ?? 0) === $userId;
    }
}

/* ===============================================================
 * 3. PEMBANTU PENULISAN
 * ============================================================= */

if (!function_exists('kasus_buat_kode')) {
    /** Buat kode kasus unik, mis. KAS-20261017-A1B2. */
    function kasus_buat_kode(mysqli $conn): string
    {
        $awalan = 'KAS-' . date('Ymd') . '-';
        for ($i = 0; $i < 6; $i++) {
            $kode = $awalan . strtoupper(bin2hex(random_bytes(2)));
            $stmt = $conn->prepare('SELECT 1 FROM kasus WHERE kode_kasus = ? LIMIT 1');
            if (!$stmt) {
                break;
            }
            $stmt->bind_param('s', $kode);
            $stmt->execute();
            $ada = (bool) $stmt->get_result()->fetch_row();
            $stmt->close();
            if (!$ada) {
                return $kode;
            }
        }
        return $awalan . strtoupper(bin2hex(random_bytes(3)));
    }
}

if (!function_exists('kasus_simpan')) {
    /**
     * Simpan kasus baru. Mengembalikan id baru, atau 0 bila gagal.
     *
     * Kunci yang diharapkan di $d: kode_kasus, judul, tingkat_urgensi,
     * status_kasus, progres, dibuat_oleh, dan opsional: deskripsi,
     * nama_lokasi, garis_lintang, garis_bujur, kategori, sumber,
     * target_selesai, penanggung_jawab_id, penanggung_jawab_teks
     * (nama PIC sebagaimana diketik -- bila PIC bukan pengguna sistem).
     *
     * Seluruh pemetaan kolom <-> tipe pembindan berada di sini (teruji),
     * agar halaman formulir tidak menulis SQL inline.
     */
    function kasus_simpan(mysqli $conn, array $d): int
    {
        $sql = 'INSERT INTO kasus
                    (kode_kasus, judul, deskripsi, nama_lokasi, garis_lintang, garis_bujur,
                     tingkat_urgensi, status_kasus, progres, kategori, sumber,
                     target_selesai, dibuat_oleh, penanggung_jawab_id, penanggung_jawab_teks)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return 0;
        }

        $kode  = (string) $d['kode_kasus'];
        $judul = (string) $d['judul'];
        $deskripsi  = isset($d['deskripsi'])    ? (string) $d['deskripsi']    : null;
        $lokasi     = isset($d['nama_lokasi'])  ? (string) $d['nama_lokasi']  : null;
        $lat        = $d['garis_lintang']       ?? null;
        $lng        = $d['garis_bujur']         ?? null;
        $urgensi    = (string) $d['tingkat_urgensi'];
        $status     = (string) $d['status_kasus'];
        $progres    = max(0, min(100, (int) $d['progres']));
        $kategori   = isset($d['kategori'])     ? (string) $d['kategori']     : null;
        $sumber     = (string) ($d['sumber'] ?? 'Manual');
        $target     = isset($d['target_selesai']) ? (string) $d['target_selesai'] : null;
        $pembuat    = (int) $d['dibuat_oleh'];
        $pj         = isset($d['penanggung_jawab_id']) ? (int) $d['penanggung_jawab_id'] : null;
        $pjTeks     = isset($d['penanggung_jawab_teks']) && trim((string) $d['penanggung_jawab_teks']) !== ''
            ? trim((string) $d['penanggung_jawab_teks'])
            : null;

        $stmt->bind_param(
            'ssssddssisssiis',
            $kode, $judul, $deskripsi, $lokasi, $lat, $lng,
            $urgensi, $status, $progres, $kategori, $sumber,
            $target, $pembuat, $pj, $pjTeks
        );

        if (!$stmt->execute()) {
            $stmt->close();
            return 0;
        }
        $idBaru = (int) $conn->insert_id;
        $stmt->close();
        return $idBaru;
    }
}

if (!function_exists('kasus_perbarui')) {
    /**
     * Perbarui kasus sedia ada. true bila berhasil.
     * Kunci wajib: id, judul, tingkat_urgensi, status_kasus, progres.
     * Kunci opsional: penanggung_jawab_id & penanggung_jawab_teks.
     */
    function kasus_perbarui(mysqli $conn, array $d): bool
    {
        $sql = 'UPDATE kasus SET
                    judul = ?, deskripsi = ?, nama_lokasi = ?,
                    garis_lintang = ?, garis_bujur = ?,
                    tingkat_urgensi = ?, status_kasus = ?, progres = ?,
                    kategori = ?, target_selesai = ?, penanggung_jawab_id = ?,
                    penanggung_jawab_teks = ?
                WHERE id = ?';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $judul = (string) $d['judul'];
        $deskripsi  = isset($d['deskripsi'])   ? (string) $d['deskripsi']   : null;
        $lokasi     = isset($d['nama_lokasi']) ? (string) $d['nama_lokasi'] : null;
        $lat        = $d['garis_lintang']      ?? null;
        $lng        = $d['garis_bujur']        ?? null;
        $urgensi    = (string) $d['tingkat_urgensi'];
        $status     = (string) $d['status_kasus'];
        $progres    = max(0, min(100, (int) $d['progres']));
        $kategori   = isset($d['kategori'])    ? (string) $d['kategori']    : null;
        $target     = isset($d['target_selesai']) ? (string) $d['target_selesai'] : null;
        $pj         = isset($d['penanggung_jawab_id']) ? (int) $d['penanggung_jawab_id'] : null;
        $pjTeks     = isset($d['penanggung_jawab_teks']) && trim((string) $d['penanggung_jawab_teks']) !== ''
            ? trim((string) $d['penanggung_jawab_teks'])
            : null;
        $id         = (int) $d['id'];

        $stmt->bind_param(
            'sssddssissisi',
            $judul, $deskripsi, $lokasi, $lat, $lng,
            $urgensi, $status, $progres, $kategori, $target, $pj, $pjTeks, $id
        );

        $berhasil = (bool) $stmt->execute();
        $stmt->close();
        return $berhasil;
    }
}

if (!function_exists('kasus_catat_status')) {
    /**
     * Catat satu entri linimasa perubahan status/progres kasus.
     */
    function kasus_catat_status(
        mysqli $conn,
        int $kasusId,
        ?string $statusLama,
        string $statusBaru,
        int $progres,
        ?string $catatan = null,
        ?int $userId = null
    ): void {
        $userId  = $userId ?? (int) sesi_user_id();
        $progres = max(0, min(100, $progres));
        $catatan = $catatan !== null ? mb_substr(trim($catatan), 0, 255) : null;
        if ($catatan === '') {
            $catatan = null;
        }

        try {
            $stmt = $conn->prepare(
                'INSERT INTO kasus_status_log
                    (kasus_id, user_id, status_lama, status_baru, progres, catatan)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            if (!$stmt) {
                return;
            }
            $stmt->bind_param('iissis', $kasusId, $userId, $statusLama, $statusBaru, $progres, $catatan);
            $stmt->execute();
            $stmt->close();
        } catch (\Throwable $t) {
            // Linimasa opsional; jangan gagalkan aksi utama.
        }
    }
}

if (!function_exists('kasus_log_daftar')) {
    /** Linimasa status sebuah kasus (terbaru dahulu). */
    function kasus_log_daftar(mysqli $conn, int $kasusId, int $batas = 100): array
    {
        $out = [];
        $batas = max(1, min(500, $batas));
        $sql = 'SELECT l.*, u.nama AS nama_pengguna
                  FROM kasus_status_log l
                  LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.kasus_id = ?
                 ORDER BY l.dibuat_pada DESC, l.id DESC
                 LIMIT ' . $batas;
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $kasusId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('kasus_penugasan_daftar')) {
    /** Anggota tim yang ditugaskan ke sebuah kasus. */
    function kasus_penugasan_daftar(mysqli $conn, int $kasusId): array
    {
        $out = [];
        $sql = 'SELECT kp.id, kp.user_id, kp.peran, kp.nama_teks,
                       COALESCE(u.nama, kp.nama_teks) AS nama,
                       u.role, u.divisi
                  FROM kasus_penugasan kp
                  LEFT JOIN users u ON u.id = kp.user_id
                 WHERE kp.kasus_id = ?
                 ORDER BY COALESCE(u.nama, kp.nama_teks)';
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        $stmt->bind_param('i', $kasusId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('kasus_daftar_user')) {
    /** Daftar pengguna AKTIF: bahan pencocokan ketikan & (dulu) pilihan dropdown. */
    function kasus_daftar_user(mysqli $conn): array
    {
        $out = [];
        $res = $conn->query("SELECT id, nama, email, role, divisi FROM users WHERE status = 'aktif' ORDER BY nama");
        while ($res && ($r = $res->fetch_assoc())) {
            $out[] = $r;
        }
        if ($res) {
            $res->free();
        }
        return $out;
    }
}

if (!function_exists('kasus_cocok_pengguna')) {
    /**
     * Cocokkan satu nama/surel ketikan dengan pengguna terdaftar.
     *
     * Aturan dibuat IDENTIK dengan App\Services\CocokPengguna (sisi Laravel
     * & React) sehingga form legacy ini menghasilkan data yang sama persis
     * dengan form aplikasi:
     *   1. surel persis (abaikan huruf besar/kecil);
     *   2. nama persis (abaikan huruf besar/kecil);
     *   3. nama berawalan ketikan -- hanya bila cocok TEPAT SATU pengguna.
     *
     * Ketikan yang tidak cocok ATAU ambigu tidak dicocokkan (user_id null),
     * tetap dikembalikan sebagai teks murni, supaya orang yang salah tidak
     * pernah ditugaskan.
     *
     * @param array<int, array<string, mixed>> $daftarUser hasil kasus_daftar_user()
     * @return array{teks: string, user_id: int|null}
     */
    function kasus_cocok_pengguna(array $daftarUser, string $ketikan): array
    {
        $teks = trim((string) preg_replace('/\s+/u', ' ', $ketikan));

        if ($teks === '') {
            return ['teks' => '', 'user_id' => null];
        }

        $rujuk = [];
        foreach ($daftarUser as $u) {
            $id = (int) ($u['id'] ?? 0);
            if ($id < 1) {
                continue;
            }
            $rujuk[$id] = [
                'nama'  => mb_strtolower(trim((string) ($u['nama'] ?? ''))),
                'surel' => mb_strtolower(trim((string) ($u['email'] ?? ''))),
            ];
        }

        /* 1) Surel persis. */
        if (filter_var($teks, FILTER_VALIDATE_EMAIL)) {
            $kunci = mb_strtolower($teks);
            foreach ($rujuk as $id => $r) {
                if ($r['surel'] !== '' && $r['surel'] === $kunci) {
                    return ['teks' => $teks, 'user_id' => $id];
                }
            }
        }

        $rendah = mb_strtolower($teks);

        /* 2) Nama persis. */
        foreach ($rujuk as $id => $r) {
            if ($r['nama'] !== '' && $r['nama'] === $rendah) {
                return ['teks' => $teks, 'user_id' => $id];
            }
        }

        /* 3) Nama berawalan -- hanya bila unik. */
        $cocok = [];
        foreach ($rujuk as $id => $r) {
            if ($r['nama'] !== '' && str_starts_with($r['nama'], $rendah)) {
                $cocok[] = $id;
            }
        }
        if (count($cocok) === 1) {
            return ['teks' => $teks, 'user_id' => $cocok[0]];
        }

        return ['teks' => $teks, 'user_id' => null];
    }
}

if (!function_exists('kasus_tim_teks_cocok')) {
    /**
     * Pecah kotak teks multi-baris anggota (satu nama per baris; koma juga
     * diterima sebagai pemisah) menjadi daftar tercocok.
     *
     * @param array<int, array<string, mixed>> $daftarUser hasil kasus_daftar_user()
     * @return list<array{teks: string, user_id: int|null}>
     */
    function kasus_tim_teks_cocok(array $daftarUser, string $kotak): array
    {
        $baris = preg_split('/[\r\n,]+/', $kotak) ?: [];
        $hasil = [];

        foreach ($baris as $b) {
            $c = kasus_cocok_pengguna($daftarUser, $b);
            if ($c['teks'] === '') {
                continue;
            }

            /* Hindari pengulangan user_id yang sama dalam satu kasus. */
            if ($c['user_id'] !== null) {
                $sudah = false;
                foreach ($hasil as $h) {
                    if ($h['user_id'] === $c['user_id']) {
                        $sudah = true;
                        break;
                    }
                }
                if ($sudah) {
                    continue;
                }
            }

            $hasil[] = $c;
        }

        return $hasil;
    }
}

if (!function_exists('kasus_sinkron_tim_teks')) {
    /**
     * Ganti daftar anggota tim kasus dari ketikan bebas: setiap baris selalu
     * tersimpan sebagai `nama_teks`, dan `user_id` hanya diisi bila nama itu
     * cocok dengan pengguna terdaftar (agar notifikasi & izin tetap bekerja).
     *
     * @param list<array{teks: string, user_id: int|null}> $daftar
     */
    function kasus_sinkron_tim_teks(mysqli $conn, int $kasusId, array $daftar, ?int $oleh = null): void
    {
        $oleh = $oleh ?? (int) sesi_user_id();

        try {
            $del = $conn->prepare('DELETE FROM kasus_penugasan WHERE kasus_id = ?');
            if ($del) {
                $kid = $kasusId;
                $del->bind_param('i', $kid);
                $del->execute();
                $del->close();
            }

            $ins = $conn->prepare(
                'INSERT INTO kasus_penugasan (kasus_id, user_id, nama_teks, peran, ditugaskan_oleh)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$ins) {
                return;
            }

            foreach ($daftar as $baris) {
                $teks = (string) ($baris['teks'] ?? '');
                if ($teks === '') {
                    continue;
                }
                $uid  = isset($baris['user_id']) && (int) $baris['user_id'] > 0 ? (int) $baris['user_id'] : null;
                $peran = 'Anggota Tim';
                $ins->bind_param('iissi', $kasusId, $uid, $teks, $peran, $oleh);
                $ins->execute();
            }
            $ins->close();
        } catch (\Throwable $t) {
            // Penugasan tim bersifat opsional.
        }
    }
}

if (!function_exists('kasus_sinkron_tim')) {
    /**
     * Ganti daftar anggota tim kasus dengan daftar id pengguna yang diberikan.
     * Tidak menyertakan PIC utama (yang disimpan di kolom penanggung_jawab_id).
     *
     * @param list<int> $idUser
     */
    function kasus_sinkron_tim(mysqli $conn, int $kasusId, array $idUser, ?int $oleh = null): void
    {
        $oleh = $oleh ?? (int) sesi_user_id();
        $bersih = [];
        foreach ($idUser as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $bersih[$id] = true;
            }
        }

        try {
            // Hapus yang tidak lagi ada di daftar.
            $ada = kasus_penugasan_daftar($conn, $kasusId);
            foreach ($ada as $a) {
                if (!isset($bersih[(int) $a['user_id']])) {
                    $del = $conn->prepare('DELETE FROM kasus_penugasan WHERE id = ?');
                    if ($del) {
                        $rid = (int) $a['id'];
                        $del->bind_param('i', $rid);
                        $del->execute();
                        $del->close();
                    }
                }
            }
            // Tambah yang baru.
            foreach (array_keys($bersih) as $uid) {
                $adaSudah = false;
                foreach ($ada as $a) {
                    if ((int) $a['user_id'] === $uid) {
                        $adaSudah = true;
                        break;
                    }
                }
                if ($adaSudah) {
                    continue;
                }
                $ins = $conn->prepare(
                    'INSERT IGNORE INTO kasus_penugasan (kasus_id, user_id, peran, ditugaskan_oleh) VALUES (?, ?, ?, ?)'
                );
                if ($ins) {
                    $peran = 'Anggota Tim';
                    $ins->bind_param('iisi', $kasusId, $uid, $peran, $oleh);
                    $ins->execute();
                    $ins->close();
                }
            }
        } catch (\Throwable $t) {
            // Penugasan opsional.
        }
    }
}

if (!function_exists('kasus_notif_pihak')) {
    /**
     * Kirim notifikasi ke pihak terkait kasus (pembuat, PIC, anggota tim),
     * kecuali pengguna yang sedang bertindak.
     *
     * @param array $kasus baris kasus (butuh kolom id, judul, dibuat_oleh, penanggung_jawab_id)
     */
    function kasus_notif_pihak(mysqli $conn, array $kasus, string $pesan, ?int $kecuali = null): void
    {
        if (!function_exists('buat_notifikasi')) {
            return;
        }
        $kecuali = $kecuali ?? (int) sesi_user_id();
        $kasusId = (int) ($kasus['id'] ?? 0);
        if ($kasusId < 1) {
            return;
        }
        $link = 'detail_kasus.php?id=' . $kasusId;

        $penerima = [];
        foreach ([$kasus['dibuat_oleh'] ?? 0, $kasus['penanggung_jawab_id'] ?? 0] as $uid) {
            $uid = (int) $uid;
            if ($uid > 0 && $uid !== $kecuali) {
                $penerima[$uid] = true;
            }
        }
        foreach (kasus_penugasan_daftar($conn, $kasusId) as $a) {
            $uid = (int) ($a['user_id'] ?? 0);
            if ($uid > 0 && $uid !== $kecuali) {
                $penerima[$uid] = true;
            }
        }

        foreach (array_keys($penerima) as $uid) {
            buat_notifikasi($conn, $uid, $pesan, null, $link);
        }
    }
}
