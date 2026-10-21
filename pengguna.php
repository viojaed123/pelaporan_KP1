<?php
/**
 * pengguna.php — Kelola pengguna & peran (khusus admin).
 *
 * Halaman ini adalah satu-satunya tempat peran/status akun boleh diubah.
 * Pendaftaran mandiri (register.php) SELALU menghasilkan peran 'karyawan';
 * promosi ke mentor/admin hanya lewat halaman ini. Ini menutup celah
 * eskalasi hak akses lewat pemilih peran di formulir publik.
 *
 * Fitur:
 *   · daftar pengguna dengan penyaring (peran, status) + pencarian + paginasi
 *   · ubah peran  : karyawan | mentor | admin
 *   · ubah status : aktif | menunggu | nonaktif
 *   · kolom "Data Terkait" (hanya-baca) sebagai peringatan sebelum menurunkan
 *     peran/menonaktifkan akun yang masih memiliki laporan/absensi/kasus
 *
 * Keamanan: hanya peran admin; aksi tulis memakai POST + token CSRF per-aksi
 * ('pengguna_peran' / 'pengguna_status') lalu pola PRG (flash_set + redirect).
 *
 * Penjaga (guard) yang diterapkan — mencegah admin mengunci dirinya sendiri:
 *   1. Tidak boleh mengubah peran/status akun sendiri.
 *   2. Tidak boleh menurunkan peran admin terakhir.
 *   3. Tidak boleh menonaktifkan/menangguhkan admin terakhir.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

/** Peran & status yang sah (whitelist — jangan pernah percaya input). */
const PERAN_SAH  = ['karyawan', 'mentor', 'admin'];
const STATUS_SAH = ['aktif', 'menunggu', 'nonaktif'];

$perHalaman = 20;
$saya       = (int) (sesi_user_id() ?? 0);

/* ================================================================
 * 1. Aksi tulis (POST + CSRF + PRG)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /** Aksi tulis yang dikenal (whitelist). */
    $aksi = (string) ($_POST['aksi'] ?? '');
    if (!in_array($aksi, ['pengguna_peran', 'pengguna_status'], true)) {
        // Aksi tak dikenal tidak punya token CSRF terdaftar; tolak dengan
        // pesan yang jelas alih-alih galat CSRF yang membingungkan.
        if (!headers_sent()) {
            http_response_code(403);
        }
        exit('Aksi tidak dikenal.');
    }

    csrf_cek_atau_matikan($aksi);

    $idUbah  = (int)    ($_POST['user_id'] ?? 0);
    $kembali = (string) ($_POST['kembali'] ?? 'pengguna.php');
    // Hanya izinkan kembali ke halaman ini sendiri (hindari open redirect).
    if (!str_starts_with($kembali, 'pengguna.php')) {
        $kembali = 'pengguna.php';
    }

    $gagal = static function (string $pesan) use ($kembali): never {
        flash_set('gagal', $pesan);
        header('Location: ' . $kembali);
        exit;
    };

    /* --- Ambil baris target + jumlah admin aktif (kunci penjaga) --- */
    $target = null;
    $stmt = $conn->prepare('SELECT id, nama, role, status FROM users WHERE id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $idUbah);
        $stmt->execute();
        $res = $stmt->get_result();
        $target = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
    if ($target === null) {
        $gagal('Pengguna tidak ditemukan.');
    }

    $adminAktif = 0;
    $res = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'aktif'");
    if ($res && ($b = $res->fetch_row())) {
        $adminAktif = (int) $b[0];
    }

    $peranLama  = (string) $target['role'];
    $statusLama = (string) $target['status'];
    $namaTarget = (string) $target['nama'];

    /* =================== Ubah peran =================== */
    if ($aksi === 'pengguna_peran') {
        $peranBaru = (string) ($_POST['role'] ?? '');

        if (!in_array($peranBaru, PERAN_SAH, true)) {
            $gagal('Peran tidak dikenal.');
        }
        if ($idUbah === $saya) {
            $gagal('Anda tidak dapat mengubah peran akun Anda sendiri.');
        }
        // Menurunkan admin terakhir akan mengunci sistem dari pengelolaan.
        if ($peranLama === 'admin' && $peranBaru !== 'admin'
            && $statusLama === 'aktif' && $adminAktif <= 1) {
            $gagal('Ini admin aktif terakhir — promosikan admin lain sebelum menurunkan peran ini.');
        }
        if ($peranBaru === $peranLama) {
            flash_set('info', 'Peran ' . $namaTarget . ' tidak berubah.');
            header('Location: ' . $kembali);
            exit;
        }

        $stmt = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
        if (!$stmt || !$stmt->bind_param('si', $peranBaru, $idUbah) || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            $gagal('Gagal menyimpan perubahan peran.');
        }
        $stmt->close();

        catat_audit(
            $conn, 'ubah_peran_pengguna', 'users', $idUbah,
            'Peran ' . $namaTarget . ': ' . $peranLama . ' → ' . $peranBaru,
            ['role' => $peranLama], ['role' => $peranBaru]
        );

        flash_set('sukses', 'Peran ' . $namaTarget . ' diubah menjadi ' . $peranBaru . '.');
        header('Location: ' . $kembali);
        exit;
    }

    /* =================== Ubah status =================== */
    if ($aksi === 'pengguna_status') {
        $statusBaru = (string) ($_POST['status'] ?? '');

        if (!in_array($statusBaru, STATUS_SAH, true)) {
            $gagal('Status tidak dikenal.');
        }
        if ($idUbah === $saya) {
            $gagal('Anda tidak dapat mengubah status akun Anda sendiri.');
        }
        // Menonaktifkan admin aktif terakhir sama berbahayanya dengan menurunkan perannya.
        if ($peranLama === 'admin' && $statusLama === 'aktif' && $statusBaru !== 'aktif'
            && $adminAktif <= 1) {
            $gagal('Ini admin aktif terakhir — statusnya tidak boleh dinonaktifkan.');
        }
        if ($statusBaru === $statusLama) {
            flash_set('info', 'Status ' . $namaTarget . ' tidak berubah.');
            header('Location: ' . $kembali);
            exit;
        }

        $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
        if (!$stmt || !$stmt->bind_param('si', $statusBaru, $idUbah) || !$stmt->execute()) {
            if ($stmt) {
                $stmt->close();
            }
            $gagal('Gagal menyimpan perubahan status.');
        }
        $stmt->close();

        catat_audit(
            $conn, 'ubah_status_pengguna', 'users', $idUbah,
            'Status ' . $namaTarget . ': ' . $statusLama . ' → ' . $statusBaru,
            ['status' => $statusLama], ['status' => $statusBaru]
        );

        flash_set('sukses', 'Status ' . $namaTarget . ' diubah menjadi ' . $statusBaru . '.');
        header('Location: ' . $kembali);
        exit;
    }

    $gagal('Aksi tidak dikenal.');
}

/* ================================================================
 * 2. Penyaring + paginasi
 * ================================================================ */
$fPeran  = trim((string) ($_GET['role']   ?? ''));
$fStatus = trim((string) ($_GET['status'] ?? ''));
$fCari   = trim((string) ($_GET['cari']   ?? ''));
$hal     = max(1, (int) (filter_input(INPUT_GET, 'hal', FILTER_VALIDATE_INT) ?: 1));

if (!in_array($fPeran, PERAN_SAH, true)) {
    $fPeran = '';
}
if (!in_array($fStatus, STATUS_SAH, true)) {
    $fStatus = '';
}

$where  = [];
$tipe   = '';
$params = [];

if ($fPeran !== '') {
    $where[]  = 'role = ?';
    $tipe    .= 's';
    $params[] = $fPeran;
}
if ($fStatus !== '') {
    $where[]  = 'status = ?';
    $tipe    .= 's';
    $params[] = $fStatus;
}
if ($fCari !== '') {
    $where[]  = '(nama LIKE ? OR email LIKE ? OR divisi LIKE ?)';
    $tipe    .= 'sss';
    $kunci    = '%' . $fCari . '%';
    $params[] = $kunci;
    $params[] = $kunci;
    $params[] = $kunci;
}
$sqlWhere = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

$total = 0;
$stmt = $conn->prepare('SELECT COUNT(*) FROM users' . $sqlWhere);
if ($stmt) {
    if ($tipe !== '') {
        $stmt->bind_param($tipe, ...$params);
    }
    $stmt->execute();
    $stmt->bind_result($total);
    $stmt->fetch();
    $stmt->close();
}
$total = (int) $total;
$totalHalaman = max(1, (int) ceil($total / $perHalaman));
$hal    = min($hal, $totalHalaman);
$offset = ($hal - 1) * $perHalaman;

$baris = [];
$stmt = $conn->prepare(
    'SELECT id, nama, email, role, status, divisi, telepon, created_at
     FROM users' . $sqlWhere . '
     ORDER BY (role = \'admin\') DESC, (status = \'aktif\') DESC, nama ASC
     LIMIT ? OFFSET ?'
);
if ($stmt) {
    $tipeAmbil   = $tipe . 'ii';
    $paramsAmbil = $params;
    $paramsAmbil[] = $perHalaman;
    $paramsAmbil[] = $offset;
    $stmt->bind_param($tipeAmbil, ...$paramsAmbil);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($b = $res->fetch_assoc()) {
        $baris[] = $b;
    }
    $stmt->close();
}

/* ================================================================
 * 3. Hitung data terkait (hanya-baca) untuk kolom peringatan
 * ================================================================ */
$dataTerkait = [];
$kolomTerkait = [
    'laporan_harian' => 'Laporan',
    'absensi'        => 'Absensi',
    'kasus'          => 'Kasus',
];
foreach ($baris as $b) {
    $uid = (int) $b['id'];
    $rincian = [];
    $jml = 0;
    foreach ($kolomTerkait as $tabel => $label) {
        $n = 0;
        $cek = $conn->prepare("SELECT COUNT(*) FROM $tabel WHERE user_id = ?");
        if ($cek) {
            $cek->bind_param('i', $uid);
            $cek->execute();
            $cek->bind_result($n);
            $cek->fetch();
            $cek->close();
        }
        $n = (int) $n;
        if ($n > 0) {
            $rincian[] = $n . ' ' . $label;
            $jml += $n;
        }
    }
    $dataTerkait[$uid] = ['jumlah' => $jml, 'rincian' => $rincian];
}

/* ================================================================
 * 4. Statistik ringkas
 * ================================================================ */
$stat = ['total' => 0, 'admin' => 0, 'mentor' => 0, 'karyawan' => 0,
         'aktif' => 0, 'menunggu' => 0, 'nonaktif' => 0, 'tanpa_verifikasi' => 0];
$res = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(role = 'admin')    AS admin,
        SUM(role = 'mentor')   AS mentor,
        SUM(role = 'karyawan') AS karyawan,
        SUM(status = 'aktif')    AS aktif,
        SUM(status = 'menunggu') AS menunggu,
        SUM(status = 'nonaktif') AS nonaktif,
        SUM(email_verified_at IS NULL) AS tanpa_verifikasi
     FROM users"
);
if ($res && ($b = $res->fetch_assoc())) {
    $stat = array_map('intval', $b);
}

/* ================================================================
 * 5. URL bantu (pertahankan penyaring)
 * ================================================================ */
$buatUrl = static function (array $ubah = []) use ($fPeran, $fStatus, $fCari, $hal): string {
    $q = array_merge([
        'role'   => $fPeran !== '' ? $fPeran : null,
        'status' => $fStatus !== '' ? $fStatus : null,
        'cari'   => $fCari !== '' ? $fCari : null,
        'hal'    => $hal > 1 ? $hal : null,
    ], $ubah);
    $q = array_filter($q, static fn ($v) => $v !== null && $v !== '');
    return 'pengguna.php' . ($q === [] ? '' : '?' . http_build_query($q));
};
$urlHalaman = static fn (int $h): string => $buatUrl(['hal' => $h]);
$adaSaringan = $fPeran !== '' || $fStatus !== '' || $fCari !== '';

/** Label + warna badge per peran. */
$badgePeran = static function (string $role): array {
    return match ($role) {
        'admin'  => ['Admin',  'badge-merah'],
        'mentor' => ['Mentor', 'badge-ungu'],
        default  => ['Karyawan', 'badge-biru'],
    };
};
/** Label + warna badge per status. */
$badgeStatus = static function (string $status): array {
    return match ($status) {
        'aktif'    => ['Aktif',    'badge-hijau'],
        'menunggu' => ['Menunggu', 'badge-kuning'],
        default    => ['Nonaktif', 'badge-navy'],
    };
};

mulai_halaman([
    'judul' => 'Kelola Pengguna',
    'crumb' => 'Administrasi',
    'aktif' => 'pengguna',
]);
?>

<!-- ============ Statistik ============ -->
<div class="grid grid-4">
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('grup') ?></span>
        <div class="nilai"><?= number_format((int) $stat['total'], 0, ',', '.') ?></div>
        <div class="label">Total pengguna</div>
    </div>
    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('perisai') ?></span>
        <div class="nilai"><?= (int) $stat['admin'] ?></div>
        <div class="label">Admin</div>
    </div>
    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('pengguna') ?></span>
        <div class="nilai"><?= (int) $stat['mentor'] ?></div>
        <div class="label">Mentor</div>
    </div>
    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('centang') ?></span>
        <div class="nilai"><?= (int) $stat['aktif'] ?></div>
        <div class="label">Akun aktif</div>
    </div>
</div>

<!-- ============ Catatan kebijakan ============ -->
<div class="alert alert-info" style="margin-top:18px">
    <span class="ikon"><?= ikon_sp('info') ?></span>
    <span>
        Pendaftaran mandiri selalu menghasilkan peran <strong>Karyawan</strong>.
        Promosi ke <strong>Mentor</strong> atau <strong>Admin</strong> hanya dapat dilakukan dari halaman ini.
    </span>
</div>

<!-- ============ Penyaring ============ -->
<section class="kartu" style="margin-top:18px">
    <div class="kartu-kepala">
        <h3><?= ikon_sp('cari') ?> Penyaring</h3>
        <span class="text-redup text-kecil" style="margin-left:auto">
            <?= number_format($total, 0, ',', '.') ?> pengguna ditemukan
        </span>
    </div>
    <div class="kartu-badan">
        <form method="GET" action="pengguna.php" class="filter-bar">
            <div class="grup-form">
                <label for="f_peran">Peran</label>
                <select id="f_peran" name="role">
                    <option value="">Semua peran</option>
                    <?php foreach (PERAN_SAH as $p): ?>
                        <option value="<?= e($p) ?>" <?= $fPeran === $p ? 'selected' : '' ?>>
                            <?= e($badgePeran($p)[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grup-form">
                <label for="f_status">Status</label>
                <select id="f_status" name="status">
                    <option value="">Semua status</option>
                    <?php foreach (STATUS_SAH as $s): ?>
                        <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>>
                            <?= e($badgeStatus($s)[0]) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grup-form" style="flex:1;min-width:200px">
                <label for="f_cari">Pencarian bebas</label>
                <input id="f_cari" type="search" name="cari" value="<?= e($fCari) ?>"
                       placeholder="Nama, email, atau divisi">
            </div>

            <div class="grup-form" style="justify-content:flex-end">
                <label>&nbsp;</label>
                <div class="d-flex gap-2">
                    <button class="tombol tombol-utama" type="submit">Terapkan</button>
                    <?php if ($adaSaringan): ?>
                        <a class="tombol tombol-garis" href="pengguna.php">Reset</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- ============ Tabel pengguna ============ -->
<section class="kartu">
    <div class="kartu-kepala">
        <h3><?= ikon_sp('pengguna') ?> Daftar Pengguna</h3>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th style="width:44px">#</th>
                        <th>Pengguna</th>
                        <th>Peran</th>
                        <th>Status</th>
                        <th>Data Terkait</th>
                        <th class="text-kanan">Ubah Peran</th>
                        <th class="text-kanan">Ubah Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($baris === []): ?>
                    <tr>
                        <td colspan="7" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('grup') ?></span>
                            Tidak ada pengguna yang cocok dengan penyaring.
                        </td>
                    </tr>
                <?php else: foreach ($baris as $b): ?>
                    <?php
                        $uid       = (int) $b['id'];
                        $diriSendiri = $uid === $saya;
                        [$lblPeran, $clsPeran]   = $badgePeran((string) $b['role']);
                        [$lblStatus, $clsStatus] = $badgeStatus((string) $b['status']);
                        $terkait = $dataTerkait[$uid] ?? ['jumlah' => 0, 'rincian' => []];
                    ?>
                    <tr>
                        <td class="text-redup text-kecil"><?= $uid ?></td>
                        <td class="text-kecil">
                            <strong><?= e((string) $b['nama']) ?></strong>
                            <?php if ($diriSendiri): ?>
                                <span class="badge badge-biru badge-tanpa-titik">Anda</span>
                            <?php endif; ?>
                            <br>
                            <span class="text-redup"><?= e((string) $b['email']) ?></span>
                            <?php if (!empty($b['divisi'])): ?>
                                <br><span class="text-redup text-kecil"><?= e((string) $b['divisi']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= e($clsPeran) ?> badge-tanpa-titik"><?= e($lblPeran) ?></span>
                        </td>
                        <td>
                            <span class="badge <?= e($clsStatus) ?> badge-tanpa-titik"><?= e($lblStatus) ?></span>
                        </td>
                        <td class="text-kecil">
                            <?php if ($terkait['jumlah'] === 0): ?>
                                <span class="text-redup">—</span>
                            <?php else: ?>
                                <?php foreach ($terkait['rincian'] as $r): ?>
                                    <span class="badge badge-navy badge-tanpa-titik"><?= e($r) ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>

                        <!-- Ubah peran -->
                        <td class="text-kanan">
                            <?php if ($diriSendiri): ?>
                                <span class="text-redup text-kecil">—</span>
                            <?php else: ?>
                                <form method="post" action="pengguna.php"
                                      style="display:inline-flex;gap:6px;align-items:center">
                                    <?= csrf_field('pengguna_peran') ?>
                                    <input type="hidden" name="aksi" value="pengguna_peran">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="kembali" value="<?= e($buatUrl()) ?>">
                                    <select name="role" aria-label="Peran baru untuk <?= e((string) $b['nama']) ?>">
                                        <?php foreach (PERAN_SAH as $p): ?>
                                            <option value="<?= e($p) ?>" <?= $b['role'] === $p ? 'selected' : '' ?>>
                                                <?= e($badgePeran($p)[0]) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="tombol tombol-garis tombol-kecil" type="submit">Simpan</button>
                                </form>
                            <?php endif; ?>
                        </td>

                        <!-- Ubah status -->
                        <td class="text-kanan">
                            <?php if ($diriSendiri): ?>
                                <span class="text-redup text-kecil">—</span>
                            <?php else: ?>
                                <form method="post" action="pengguna.php"
                                      style="display:inline-flex;gap:6px;align-items:center">
                                    <?= csrf_field('pengguna_status') ?>
                                    <input type="hidden" name="aksi" value="pengguna_status">
                                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                                    <input type="hidden" name="kembali" value="<?= e($buatUrl()) ?>">
                                    <select name="status" aria-label="Status baru untuk <?= e((string) $b['nama']) ?>">
                                        <?php foreach (STATUS_SAH as $s): ?>
                                            <option value="<?= e($s) ?>" <?= $b['status'] === $s ? 'selected' : '' ?>>
                                                <?= e($badgeStatus($s)[0]) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="tombol tombol-garis tombol-kecil" type="submit">Simpan</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($totalHalaman > 1): ?>
        <div class="kartu-kaki">
            <nav class="paginasi">
                <?php if ($hal > 1): ?>
                    <a class="tombol tombol-garis tombol-kecil" href="<?= e($urlHalaman($hal - 1)) ?>">‹ Sebelumnya</a>
                <?php endif; ?>
                <span class="text-redup text-kecil">Halaman <?= $hal ?> dari <?= $totalHalaman ?></span>
                <?php if ($hal < $totalHalaman): ?>
                    <a class="tombol tombol-garis tombol-kecil" href="<?= e($urlHalaman($hal + 1)) ?>">Berikutnya ›</a>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>
</section>

<?php akhiri_halaman(); ?>
