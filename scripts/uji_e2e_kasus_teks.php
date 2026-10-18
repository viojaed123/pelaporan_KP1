<?php

/**
 * scripts/uji_e2e_kasus_teks.php
 *
 * Uji regresi E2E modul kasus dengan PIC & anggota tim TEKS BEBAS
 * (formulir legacy `buat_kasus.php` / `edit_kasus.php`).
 *
 * Bagian A  : uji murni fungsi `kasus_cocok_pengguna` / `kasus_tim_teks_cocok`
 *             (surel persis -> nama persis -> awalan unik; ambigu = teks).
 * Bagian B  : uji HTTP penuh lewat `http://localhost/pelaporan`
 *             (login -> GET form -> buat -> cek DB -> lihat detail/dashboard
 *              -> pra-isi edit -> ubah -> cek DB).
 * Bagian C  : pembersihan -- SELALU dijalankan, termasuk bila uji gagal.
 *
 * Skrip ini MENCIPTAKAN satu akun uji sementara (`uji_teks_kasus@uji.local`)
 * dan satu kasus uji berjudul "UJI TEKS BEBAS ...", lalu menghapusnya kembali
 * lengkap dengan notifikasi, log status, dan jejak auditnya. Karena itu ia
 * hanya boleh dijalankan eksplisit:
 *
 *     C:\xampp\php\php.exe scripts\uji_e2e_kasus_teks.php --yakin
 *
 * Prasyarat: Apache menyajikan aplikasi legacy di PHR_DASAR
 * (default http://localhost/pelaporan) dan ada minimal satu pengguna AKTIF
 * selain akun uji (dipakai sebagai contoh nama yang berhasil dicocokkan).
 *
 * Latar belakang: uji inilah yang menangkap dua bug nyata pada pengenalan
 * ketik-bebas -- (1) `kasus_perbarui()` mengikat `penanggung_jawab_teks`
 * sebagai integer sehingga tersimpan "0", dan (2) kolom
 * `kasus_penugasan.user_id` NOT NULL sehingga anggota non-pengguna ditolak
 * MySQL (diperbaiki lewat migrasi 2026_10_19_100000).
 */

declare(strict_types=1);

if (!in_array('--yakin', $argv, true)) {
    fwrite(STDERR, "Skrip ini menulis & menghapus data pada basis data.\n");
    fwrite(STDERR, "Jalankan eksplisit dengan:  php scripts\\uji_e2e_kasus_teks.php --yakin\n");
    exit(2);
}

$root = dirname(__DIR__);
$base = getenv('PHR_DASAR') ?: 'http://localhost/pelaporan';

$baris = [];
$gagal = 0;

function cek(string $label, bool $ok, string $info = ''): void
{
    global $baris, $gagal;
    if ($ok) {
        $baris[] = '  [ OK ]   ' . $label;
    } else {
        $gagal++;
        $baris[] = '  [GAGAL] ' . $label . ($info !== '' ? '  -- ' . $info : '');
    }
}

/* ---------------------------------------------------------------
 * 0. Koneksi DB (kredensial dibaca dari .env)
 * ------------------------------------------------------------- */
$env = (string) file_get_contents($root . '/.env');
$ek  = static function (string $k) use ($env): string {
    return preg_match('/^' . preg_quote($k, '/') . '=(.*)$/m', $env, $m) ? trim($m[1]) : '';
};

$pdo = new PDO(
    'mysql:host=' . $ek('DB_HOST') . ';port=' . $ek('DB_PORT')
        . ';dbname=' . $ek('DB_DATABASE') . ';charset=utf8mb4',
    $ek('DB_USERNAME'),
    $ek('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* ---------------------------------------------------------------
 * A. UJI MURNI PENCOCOKAN (tanpa DB)
 * ------------------------------------------------------------- */
require_once $root . '/includes/kasus.php';

$daftar = [
    ['id' => 11, 'nama' => 'Budi Santoso', 'email' => 'budi@contoh.com', 'role' => 'karyawan', 'divisi' => 'TI'],
    ['id' => 12, 'nama' => 'Sri Wahyuni', 'email' => 'sri@contoh.com', 'role' => 'karyawan', 'divisi' => 'TI'],
    ['id' => 13, 'nama' => 'Sri Lestari', 'email' => 'lestari@contoh.com', 'role' => 'karyawan', 'divisi' => 'TI'],
];

cek('A1 nama persis beda kapital', kasus_cocok_pengguna($daftar, 'budi santoso')['user_id'] === 11);
cek('A2 spasi ganda dirapikan', kasus_cocok_pengguna($daftar, "  Budi   Santoso  ")['user_id'] === 11);
cek('A3 surel persis', kasus_cocok_pengguna($daftar, 'budi@contoh.com')['user_id'] === 11);
cek('A4 awalan ambigu DITOLAK', kasus_cocok_pengguna($daftar, 'Sri')['user_id'] === null);
cek('A5 awalan unik diterima', kasus_cocok_pengguna($daftar, 'Sri W')['user_id'] === 12);
$asing = kasus_cocok_pengguna($daftar, 'Tidak Dikenal');
cek('A6 tak dikenal -> teks murni', $asing['user_id'] === null && $asing['teks'] === 'Tidak Dikenal');
$kosong = kasus_cocok_pengguna($daftar, '   ');
cek('A7 kosong -> teks kosong', $kosong['user_id'] === null && $kosong['teks'] === '');
$tim = kasus_tim_teks_cocok($daftar, "Budi\nbudi@contoh.com\nTidak Dikenal\n\n");
cek('A8 baris dipecah & duplikat user_id dibuang', count($tim) === 2, json_encode($tim));

/* ---------------------------------------------------------------
 * B. UJI HTTP
 * ------------------------------------------------------------- */
$email    = 'uji_teks_kasus@uji.local';
$sandi    = 'UjiTeksKasus123!';
$judulUji = 'UJI TEKS BEBAS ';
$idKasus  = 0;

/** Hapus satu kasus uji beserta seluruh jejaknya (anggota, log, notifikasi, audit). */
$hapusKasus = static function (PDO $pdo, int $id): void {
    foreach (['kasus_penugasan', 'kasus_status_log'] as $t) {
        $pdo->prepare("DELETE FROM {$t} WHERE kasus_id = ?")->execute([$id]);
    }
    $pdo->prepare('DELETE FROM notifikasi WHERE link LIKE ?')->execute(['%id=' . $id . '%']);
    $pdo->prepare("DELETE FROM audit_log WHERE baris_id = ? AND tabel_ref = 'kasus'")->execute([$id]);
    $pdo->prepare('DELETE FROM kasus WHERE id = ?')->execute([$id]);
};

/* Bersihkan sisa run sebelumnya (bila skrip pernah gagal di tengah). */
$sisanya = $pdo->query("SELECT id FROM kasus WHERE judul LIKE '" . $judulUji . "%'")->fetchAll(PDO::FETCH_COLUMN);
foreach ($sisanya as $idLama) {
    $hapusKasus($pdo, (int) $idLama);
}
$pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
$pdo->prepare(
    'INSERT INTO users (nama, email, password, role, status, divisi, created_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())'
)->execute(['Uji Teks Kasus', $email, password_hash($sandi, PASSWORD_DEFAULT), 'karyawan', 'aktif', 'TI']);
$idUji = (int) $pdo->lastInsertId();

/* Pengguna AKTIF nyata yang akan dicocokkan lewat ketikan (nama persis). */
$penggunaUji = $pdo->query(
    "SELECT id, nama FROM users WHERE status = 'aktif' AND id <> {$idUji} ORDER BY id LIMIT 1"
)->fetch();
$namaCocok = $penggunaUji !== false ? (string) $penggunaUji['nama'] : '';
$idCocok   = $penggunaUji !== false ? (int) $penggunaUji['id'] : 0;
if ($namaCocok === '') {
    fwrite(STDERR, "Tidak ada pengguna aktif selain akun uji; uji bagian B dilewati.\n");
}

$jar = tempnam(sys_get_temp_dir(), 'phrjar');
$ch  = curl_init();
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_COOKIEJAR      => $jar,
    CURLOPT_COOKIEFILE     => $jar,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) PHR-Uji/1.0',
]);

$meminta = static function (string $url, ?array $data = null) use ($ch): array {
    curl_setopt($ch, CURLOPT_URL, $url);
    if ($data === null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $badan = curl_exec($ch);
    if ($badan === false) {
        throw new RuntimeException(curl_error($ch));
    }

    return [
        'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body' => (string) $badan,
        'loc'  => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
    ];
};

$token = static function (string $html): string {
    if (!preg_match('/name="csrf_token"\s+value="([0-9a-f]{64})"/', $html, $m)) {
        throw new RuntimeException('token CSRF tidak ditemukan pada halaman');
    }

    return $m[1];
};

$anggotaAsing = 'Orang Asing Uji';
$picAsing     = 'Kepala Desa Uji (Tidak Terdaftar)';

try {
    /* --- masuk --- */
    $hal = $meminta($base . '/login.php');
    $res = $meminta($base . '/login.php', [
        'csrf_token' => $token($hal['body']),
        'email'      => $email,
        'password'   => $sandi,
    ]);
    cek('B1 login berhasil (302)', $res['code'] === 302, 'kode=' . $res['code'] . ' ' . $res['body']);

    /* --- GET buat_kasus: bentuk form --- */
    $g = $meminta($base . '/buat_kasus.php');
    cek('B2 GET buat_kasus 200', $g['code'] === 200, 'kode=' . $g['code'] . ' || ' . substr(preg_replace('/\s+/', ' ', $g['body']), 0, 400));
    cek('B3 pakai isian teks PIC', str_contains($g['body'], 'name="penanggung_jawab"'));
    cek('B4 tanpa dropdown PIC', !str_contains($g['body'], 'name="penanggung_jawab_id"'));
    cek('B5 pakai textarea anggota', str_contains($g['body'], 'name="anggota_teks"'));
    cek('B6 tanpa multi-select anggota', !str_contains($g['body'], 'name="anggota[]"'));

    if ($namaCocok !== '') {
        /* --- POST buat_kasus: ketikan bebas (1 cocok, 1 tidak) --- */
        $judul = $judulUji . date('His');
        $p = $meminta($base . '/buat_kasus.php', [
            'csrf_token'       => $token($g['body']),
            'judul'            => $judul,
            'deskripsi'        => 'Uji otomatis, kasus ini ikut terhapus setelah uji.',
            'kategori'         => '',
            'tingkat_urgensi'  => 'Rendah',
            'status_kasus'     => 'Terbuka',
            'progres'          => '0',
            'nama_lokasi'      => '',
            'garis_lintang'    => '',
            'garis_bujur'      => '',
            'target_selesai'   => '',
            'penanggung_jawab' => $picAsing,
            'anggota_teks'     => $namaCocok . "\n" . $anggotaAsing,
        ]);
        cek('B7 POST buat_kasus 302 ke detail', $p['code'] === 302, 'kode=' . $p['code'] . ' ' . substr($p['body'], 0, 300));
        if (preg_match('/id=(\d+)/', $p['loc'], $m)) {
            $idKasus = (int) $m[1];
        }
        cek('B8 mendapat id kasus', $idKasus > 0, $p['loc']);

        if ($idKasus > 0) {
            $k = $pdo->query('SELECT penanggung_jawab_id, penanggung_jawab_teks FROM kasus WHERE id = ' . $idKasus)->fetch();
            cek('B9 PIC yang tidak dikenal: id NULL', $k !== false && $k['penanggung_jawab_id'] === null, json_encode($k));
            cek('B10 PIC teks ketikan tersimpan', $k !== false && $k['penanggung_jawab_teks'] === $picAsing, json_encode($k));

            $rows = $pdo->query('SELECT user_id, nama_teks, peran FROM kasus_penugasan WHERE kasus_id = ' . $idKasus . ' ORDER BY id')->fetchAll();
            $adaCocok  = false;
            $adaAsing  = false;
            foreach ($rows as $r) {
                if ($r['nama_teks'] === $namaCocok) {
                    $adaCocok = $r['user_id'] !== null && (int) $r['user_id'] === $idCocok;
                }
                if ($r['nama_teks'] === $anggotaAsing) {
                    $adaAsing = $r['user_id'] === null;
                }
            }
            cek('B11 anggota terdaftar tertaut user_id', $adaCocok, json_encode($rows));
            cek('B12 anggota tak terdaftar tetap teks', $adaAsing, json_encode($rows));
            cek('B13 ada 2 baris anggota', count($rows) === 2, (string) count($rows));

            $notif = $idCocok > 0
                ? (int) $pdo->query('SELECT COUNT(*) c FROM notifikasi WHERE user_id = ' . $idCocok . " AND link LIKE '%id=" . $idKasus . "%'")->fetch()['c']
                : 0;
            cek('B14 anggota tercocok menerima notifikasi', $notif > 0, 'notif=' . $notif);

            /* --- detail & dashboard menampilkan nama ketikan --- */
            $d = $meminta($base . '/detail_kasus.php?id=' . $idKasus);
            cek('B15 GET detail 200', $d['code'] === 200, 'kode=' . $d['code']);
            cek('B16 detail menampilkan PIC ketikan', str_contains($d['body'], $picAsing));
            cek('B17 detail menampilkan anggota non-pengguna', str_contains($d['body'], $anggotaAsing));

            $h = $meminta($base . '/dashboard_kasus.php');
            cek('B18 dashboard menampilkan PIC ketikan', str_contains($h['body'], $picAsing));

            /* --- edit: pra-isi lalu simpan perubahan --- */
            $e = $meminta($base . '/edit_kasus.php?id=' . $idKasus);
            cek('B19 GET edit 200', $e['code'] === 200, 'kode=' . $e['code']);
            cek('B20 PIC terpra-isi ketikan', str_contains($e['body'], 'value="' . $picAsing . '"'));
            preg_match('/<textarea[^>]*name="anggota_teks"[^>]*>(.*?)<\/textarea>/s', $e['body'], $mmIsi);
            $isiTeks  = isset($mmIsi[1]) ? trim((string) html_entity_decode($mmIsi[1])) : '';
            $barisIsi = array_values(array_filter(array_map('trim', preg_split('/\R/', $isiTeks) ?: [])));
            sort($barisIsi);
            $harusIsi = [$anggotaAsing, $namaCocok];
            sort($harusIsi);
            cek('B21 anggota terpra-isi ketikan', $barisIsi === $harusIsi, json_encode($barisIsi));

            $u = $meminta($base . '/edit_kasus.php?id=' . $idKasus, [
                'csrf_token'       => $token($e['body']),
                'judul'            => $judul,
                'deskripsi'        => 'Uji otomatis, kasus ini ikut terhapus setelah uji.',
                'kategori'         => '',
                'tingkat_urgensi'  => 'Rendah',
                'status_kasus'     => 'Dalam Proses',
                'progres'          => '50',
                'nama_lokasi'      => '',
                'garis_lintang'    => '',
                'garis_bujur'      => '',
                'target_selesai'   => '',
                'catatan_status'   => 'uji otomatis',
                'penanggung_jawab' => $namaCocok,
                'anggota_teks'     => $anggotaAsing,
            ]);
            cek('B22 POST edit 302', $u['code'] === 302, 'kode=' . $u['code'] . ' ' . substr($u['body'], 0, 300));

            $k2 = $pdo->query('SELECT penanggung_jawab_id, penanggung_jawab_teks, status_kasus, progres FROM kasus WHERE id = ' . $idKasus)->fetch();
            cek('B23 PIC berubah jadi pengguna terdaftar', $k2 !== false && $idCocok > 0 && (int) $k2['penanggung_jawab_id'] === $idCocok, json_encode($k2));
            cek('B24 PIC teks ikut diperbarui', $k2 !== false && $k2['penanggung_jawab_teks'] === $namaCocok, json_encode($k2));
            cek('B25 status/progres tersimpan', $k2 !== false && $k2['status_kasus'] === 'Dalam Proses' && (int) $k2['progres'] === 50, json_encode($k2));

            $rows2 = $pdo->query('SELECT user_id, nama_teks FROM kasus_penugasan WHERE kasus_id = ' . $idKasus)->fetchAll();
            $cocokJadiPic = false;
            foreach ($rows2 as $r) {
                if ($r['nama_teks'] === $namaCocok) {
                    $cocokJadiPic = true;
                }
            }
            cek('B26 PIC lama dikeluarkan dari anggota', !$cocokJadiPic, json_encode($rows2));
            cek('B27 anggota tersisa 1 baris', count($rows2) === 1, (string) count($rows2));

            /* --- edit: PIC pengguna terdaftar terpra-isi namanya --- */
            $e2 = $meminta($base . '/edit_kasus.php?id=' . $idKasus);
            cek('B28 pra-isi edit setelah tersimpan', str_contains($e2['body'], 'value="' . $namaCocok . '"'));
        }
    }
} catch (Throwable $t) {
    $gagal++;
    $baris[] = '  [ERROR] ' . $t->getMessage();
}

curl_close($ch);
@unlink($jar);

/* ---------------------------------------------------------------
 * C. PEMBERSIHAN (dijalankan selalu)
 * ------------------------------------------------------------- */
try {
    if ($idKasus > 0) {
        $hapusKasus($pdo, $idKasus);
    }
    $pdo->prepare('DELETE FROM users WHERE email = ?')->execute([$email]);
} catch (Throwable $t) {
    $baris[] = '  [BERSIH] gagal: ' . $t->getMessage();
    $gagal++;
}

echo implode(PHP_EOL, $baris), PHP_EOL;
echo PHP_EOL, ($gagal === 0 ? 'SEMUA LULUS' : $gagal . ' GAGAL'), PHP_EOL;
exit($gagal === 0 ? 0 : 1);
