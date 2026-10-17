<?php
/**
 * riwayat_audit.php — Penampil jejak audit (khusus admin).
 *
 * Menampilkan seluruh catatan audit_log dengan:
 *   · penyaring: pengguna, jenis aksi, tabel, rentang tanggal, pencarian bebas
 *   · paginasi
 *   · rincian data_lama vs data_baru per baris (modal)
 *
 * Halaman ini HANYA-BACA. Tidak ada operasi tulis, jadi tidak ada CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

$perHalaman = 25;

/* ----------------------------------------------------------------
 * Kumpulkan penyaring dari query string
 * ------------------------------------------------------------- */
$fUser   = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT) ?: 0;
$fAksi   = trim((string) ($_GET['aksi'] ?? ''));
$fTabel  = trim((string) ($_GET['tabel'] ?? ''));
$fAwal   = trim((string) ($_GET['tanggal_awal'] ?? ''));
$fAkhir  = trim((string) ($_GET['tanggal_akhir'] ?? ''));
$fCari   = trim((string) ($_GET['cari'] ?? ''));
$hal     = max(1, (int) (filter_input(INPUT_GET, 'hal', FILTER_VALIDATE_INT) ?: 1));

$validTanggal = static function (string $t): bool {
    return $t !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) === 1;
};

$where  = [];
$tipe   = '';
$params = [];

if ($fUser > 0) {
    $where[] = 'a.user_id = ?';
    $tipe   .= 'i';
    $params[] = $fUser;
}
if ($fAksi !== '') {
    $where[] = 'a.aksi = ?';
    $tipe   .= 's';
    $params[] = $fAksi;
}
if ($fTabel !== '') {
    $where[] = 'a.tabel_ref = ?';
    $tipe   .= 's';
    $params[] = $fTabel;
}
if ($validTanggal($fAwal)) {
    $where[] = 'a.dibuat_pada >= ?';
    $tipe   .= 's';
    $params[] = $fAwal . ' 00:00:00';
}
if ($validTanggal($fAkhir)) {
    $where[] = 'a.dibuat_pada <= ?';
    $tipe   .= 's';
    $params[] = $fAkhir . ' 23:59:59';
}
if ($fCari !== '') {
    $where[] = '(a.ringkasan LIKE ? OR a.aksi LIKE ? OR u.nama LIKE ?)';
    $tipe   .= 'sss';
    $kunci   = '%' . $fCari . '%';
    $params[] = $kunci;
    $params[] = $kunci;
    $params[] = $kunci;
}

$sqlWhere = $where === [] ? '' : (' WHERE ' . implode(' AND ', $where));

/* ----------------------------------------------------------------
 * Ekspor CSV (sama filter, tanpa paginasi)
 * ------------------------------------------------------------- */
if ((string) ($_GET['ekspor'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM agar Excel membaca UTF-8 dengan benar
    fputcsv($out, [
        'ID', 'Waktu', 'Pengguna', 'Email', 'Aksi', 'Tabel',
        'ID Baris', 'Ringkasan', 'Data Lama', 'Data Baru', 'IP',
    ]);

    $sqlEkspor = 'SELECT a.id, a.user_id, a.aksi, a.tabel_ref, a.baris_id, a.ringkasan,
                        a.data_lama, a.data_baru, a.ip_address, a.dibuat_pada,
                        u.nama AS nama_user, u.email AS email_user
                 FROM audit_log a
                 LEFT JOIN users u ON u.id = a.user_id'
                . $sqlWhere
                . ' ORDER BY a.id DESC LIMIT 50000';

    $stmtEk = $conn->prepare($sqlEkspor);
    if ($stmtEk) {
        if ($tipe !== '') {
            $stmtEk->bind_param($tipe, ...$params);
        }
        $stmtEk->execute();
        $resEk = $stmtEk->get_result();
        while ($b = $resEk ? $resEk->fetch_assoc() : null) {
            fputcsv($out, [
                (int) $b['id'],
                (string) $b['dibuat_pada'],
                (string) ($b['nama_user'] ?? ''),
                (string) ($b['email_user'] ?? ''),
                (string) $b['aksi'],
                (string) ($b['tabel_ref'] ?? ''),
                (int) ($b['baris_id'] ?? 0),
                (string) ($b['ringkasan'] ?? ''),
                (string) ($b['data_lama'] ?? ''),
                (string) ($b['data_baru'] ?? ''),
                (string) ($b['ip_address'] ?? ''),
            ]);
        }
        $stmtEk->close();
    }
    fclose($out);
    exit;
}

/* ----------------------------------------------------------------
 * Hitung total & ambil baris
 * ------------------------------------------------------------- */
$total = 0;
$sqlHitung = 'SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON u.id = a.user_id' . $sqlWhere;
$stmt = $conn->prepare($sqlHitung);
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
$hal = min($hal, $totalHalaman);
$offset = ($hal - 1) * $perHalaman;

$baris = [];
$sqlAmbil = 'SELECT a.id, a.user_id, a.aksi, a.tabel_ref, a.baris_id, a.ringkasan,
                    a.data_lama, a.data_baru, a.ip_address, a.user_agent, a.dibuat_pada,
                    u.nama AS nama_user, u.email AS email_user
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id'
          . $sqlWhere
          . ' ORDER BY a.id DESC LIMIT ? OFFSET ?';

$stmt = $conn->prepare($sqlAmbil);
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

/* ----------------------------------------------------------------
 * Daftar pilihan penyaring
 * ------------------------------------------------------------- */
$daftarAksi = [];
$res = $conn->query('SELECT DISTINCT aksi FROM audit_log ORDER BY aksi');
if ($res) {
    while ($b = $res->fetch_row()) {
        $daftarAksi[] = (string) $b[0];
    }
}

$daftarTabel = [];
$res = $conn->query("SELECT DISTINCT tabel_ref FROM audit_log WHERE tabel_ref IS NOT NULL AND tabel_ref <> '' ORDER BY tabel_ref");
if ($res) {
    while ($b = $res->fetch_row()) {
        $daftarTabel[] = (string) $b[0];
    }
}

$daftarPengguna = [];
$res = $conn->query('SELECT id, nama, role FROM users ORDER BY nama');
if ($res) {
    while ($b = $res->fetch_assoc()) {
        $daftarPengguna[] = $b;
    }
}

/* ----------------------------------------------------------------
 * Statistik ringkas (7 hari terakhir)
 * ------------------------------------------------------------- */
$statistik = ['total' => 0, 'hari_ini' => 0, 'gagal_login' => 0, 'pengguna_aktif' => 0];
$res = $conn->query(
    "SELECT
        (SELECT COUNT(*) FROM audit_log) AS total,
        (SELECT COUNT(*) FROM audit_log WHERE DATE(dibuat_pada) = CURDATE()) AS hari_ini,
        (SELECT COUNT(*) FROM audit_log WHERE aksi IN ('login_gagal','login_terkunci')
             AND dibuat_pada >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS gagal_login,
        (SELECT COUNT(DISTINCT user_id) FROM audit_log
             WHERE dibuat_pada >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS pengguna_aktif"
);
if ($res && ($b = $res->fetch_assoc())) {
    $statistik = array_map('intval', $b);
}

/* Label ramah untuk aksi umum. */
$labelAksi = static function (string $aksi): string {
    $peta = [
        'login'                     => 'Masuk',
        'logout'                    => 'Keluar',
        'login_gagal'               => 'Gagal masuk',
        'login_terkunci'            => 'Akun terkunci',
        'login_2fa_diminta'         => 'Kode 2FA diminta',
        'login_2fa_berhasil'        => '2FA berhasil',
        'login_2fa_gagal'           => '2FA gagal',
        'register'                  => 'Pendaftaran',
        'buat_laporan'              => 'Buat laporan',
        'kirim_laporan'             => 'Kirim laporan',
        'periksa_laporan'           => 'Periksa laporan',
        'komentar_laporan'          => 'Komentar laporan',
        'ajukan_revisi'             => 'Ajukan revisi',
        'hapus_laporan'             => 'Hapus laporan',
        'absen_masuk'               => 'Absen masuk',
        'absen_keluar'              => 'Absen keluar',
        'ganti_sandi'               => 'Ganti sandi',
        'aktifkan_2fa'              => '2FA diaktifkan',
        'matikan_2fa'               => '2FA dimatikan',
        'cabut_sesi'                => 'Sesi dicabut',
        'reset_sandi_pengguna'      => 'Reset sandi pengguna',
        'verifikasi_email'          => 'Verifikasi surel',
        'reset_sandi'               => 'Reset sandi',
        'ubah_status_pengguna'      => 'Ubah status pengguna',
        'verifikasi_pengguna'       => 'Verifikasi pengguna',
        'backup_buat'               => 'Cadangan dibuat',
        'backup_pulihkan'           => 'Cadangan dipulihkan',
        'backup_hapus'              => 'Cadangan dihapus',
    ];
    return $peta[$aksi] ?? ucfirst(str_replace('_', ' ', $aksi));
};

/* Bangun URL halaman dengan mempertahankan penyaring. */
$urlHalaman = static function (int $halaman) use ($fUser, $fAksi, $fTabel, $fAwal, $fAkhir, $fCari): string {
    $q = [
        'user_id'       => $fUser ?: null,
        'aksi'          => $fAksi !== '' ? $fAksi : null,
        'tabel'         => $fTabel !== '' ? $fTabel : null,
        'tanggal_awal'  => $fAwal !== '' ? $fAwal : null,
        'tanggal_akhir' => $fAkhir !== '' ? $fAkhir : null,
        'cari'          => $fCari !== '' ? $fCari : null,
        'hal'           => $halaman,
    ];
    return 'riwayat_audit.php?' . http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
};

/* URL ekspor CSV dengan penyaring yang sama. */
$urlEkspor = static function () use ($fUser, $fAksi, $fTabel, $fAwal, $fAkhir, $fCari): string {
    $q = [
        'ekspor'        => 'csv',
        'user_id'       => $fUser ?: null,
        'aksi'          => $fAksi !== '' ? $fAksi : null,
        'tabel'         => $fTabel !== '' ? $fTabel : null,
        'tanggal_awal'  => $fAwal !== '' ? $fAwal : null,
        'tanggal_akhir' => $fAkhir !== '' ? $fAkhir : null,
        'cari'          => $fCari !== '' ? $fCari : null,
    ];
    return 'riwayat_audit.php?' . http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
};

$adaSaringan = $fUser > 0 || $fAksi !== '' || $fTabel !== '' || $fAwal !== '' || $fAkhir !== '' || $fCari !== '';

mulai_halaman([
    'judul' => 'Jejak Audit',
    'crumb' => 'Administrasi',
    'aktif' => 'audit',
]);
?>

<!-- ============ Statistik ============ -->
<div class="grid grid-4">
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('arsip') ?></span>
        <div class="nilai"><?= number_format((int) $statistik['total'], 0, ',', '.') ?></div>
        <div class="label">Total catatan</div>
    </div>
    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('kalender') ?></span>
        <div class="nilai"><?= number_format((int) $statistik['hari_ini'], 0, ',', '.') ?></div>
        <div class="label">Aktivitas hari ini</div>
    </div>
    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('grup') ?></span>
        <div class="nilai"><?= (int) $statistik['pengguna_aktif'] ?></div>
        <div class="label">Pengguna aktif (7 hari)</div>
    </div>
    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('peringatan') ?></span>
        <div class="nilai"><?= (int) $statistik['gagal_login'] ?></div>
        <div class="label">Gagal masuk (7 hari)</div>
    </div>
</div>

<!-- ============ Penyaring ============ -->
<section class="kartu">
    <div class="kartu-kepala">
        <h3><?= ikon_sp('cari') ?> Penyaring</h3>
        <span class="text-redup text-kecil" style="margin-left:auto">
            <?= number_format($total, 0, ',', '.') ?> catatan ditemukan
        </span>
    </div>
    <div class="kartu-badan">
        <form method="GET" action="riwayat_audit.php" class="filter-bar">
            <div class="grup-form">
                <label for="f_user">Pengguna</label>
                <select id="f_user" name="user_id">
                    <option value="">Semua pengguna</option>
                    <?php foreach ($daftarPengguna as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $fUser === (int) $p['id'] ? 'selected' : '' ?>>
                            <?= e((string) $p['nama']) ?> (<?= e(ucfirst((string) $p['role'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grup-form">
                <label for="f_aksi">Jenis aksi</label>
                <select id="f_aksi" name="aksi">
                    <option value="">Semua jenis</option>
                    <?php foreach ($daftarAksi as $a): ?>
                        <option value="<?= e($a) ?>" <?= $fAksi === $a ? 'selected' : '' ?>>
                            <?= e($labelAksi($a)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grup-form">
                <label for="f_tabel">Tabel</label>
                <select id="f_tabel" name="tabel">
                    <option value="">Semua tabel</option>
                    <?php foreach ($daftarTabel as $t): ?>
                        <option value="<?= e($t) ?>" <?= $fTabel === $t ? 'selected' : '' ?>>
                            <?= e($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="grup-form">
                <label for="f_awal">Dari tanggal</label>
                <input id="f_awal" type="date" name="tanggal_awal" value="<?= e($fAwal) ?>">
            </div>

            <div class="grup-form">
                <label for="f_akhir">Sampai tanggal</label>
                <input id="f_akhir" type="date" name="tanggal_akhir" value="<?= e($fAkhir) ?>">
            </div>

            <div class="grup-form" style="flex:1;min-width:200px">
                <label for="f_cari">Pencarian bebas</label>
                <input id="f_cari" type="search" name="cari" value="<?= e($fCari) ?>"
                       placeholder="Ringkasan, aksi, atau nama pengguna">
            </div>

            <div class="grup-form" style="justify-content:flex-end">
                <label>&nbsp;</label>
                <div class="d-flex gap-2">
                    <button class="tombol tombol-utama" type="submit">Terapkan</button>
                    <?php if ($adaSaringan): ?>
                        <a class="tombol tombol-garis" href="riwayat_audit.php">Reset</a>
                    <?php endif; ?>
                    <a class="tombol tombol-garis" href="<?= e($urlEkspor()) ?>">Ekspor CSV</a>
                </div>
            </div>
        </form>
    </div>
</section>

<!-- ============ Tabel ============ -->
<section class="kartu">
    <div class="kartu-kepala">
        <h3><?= ikon_sp('gulir') ?> Catatan Audit</h3>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th style="width:44px">#</th>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Aksi</th>
                        <th>Objek</th>
                        <th>Ringkasan</th>
                        <th>IP</th>
                        <th class="text-kanan">Rincian</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($baris === []): ?>
                    <tr>
                        <td colspan="8" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('kotak_masuk') ?></span>
                            Tidak ada catatan audit yang cocok dengan penyaring.
                        </td>
                    </tr>
                <?php else: foreach ($baris as $b): ?>
                    <?php
                        $adaRincian = ($b['data_lama'] !== null && $b['data_lama'] !== '')
                                   || ($b['data_baru'] !== null && $b['data_baru'] !== '');
                        $bukaId = 'audit-' . (int) $b['id'];
                    ?>
                    <tr>
                        <td class="text-redup text-kecil"><?= (int) $b['id'] ?></td>
                        <td class="text-kecil" style="white-space:nowrap">
                            <?= e(tanggal_id((string) $b['dibuat_pada'], 'd M Y')) ?><br>
                            <span class="text-redup text-mono"><?= e(date('H:i:s', strtotime((string) $b['dibuat_pada']))) ?></span>
                        </td>
                        <td class="text-kecil">
                            <?php if ($b['nama_user'] !== null): ?>
                                <?= e((string) $b['nama_user']) ?><br>
                                <span class="text-redup"><?= e((string) $b['email_user']) ?></span>
                            <?php else: ?>
                                <span class="text-redup">Sistem / tidak dikenal</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-biru badge-tanpa-titik"><?= e($labelAksi((string) $b['aksi'])) ?></span>
                        </td>
                        <td class="text-kecil text-redup">
                            <?php if ($b['tabel_ref'] !== null && $b['tabel_ref'] !== ''): ?>
                                <span class="text-mono"><?= e((string) $b['tabel_ref']) ?></span>
                                <?php if ($b['baris_id'] !== null): ?>
                                    <span class="text-mono">#<?= (int) $b['baris_id'] ?></span>
                                <?php endif; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="text-kecil"><?= e(potong((string) ($b['ringkasan'] ?? ''), 80)) ?></td>
                        <td class="text-mono text-kecil"><?= e((string) ($b['ip_address'] ?? '-')) ?></td>
                        <td class="text-kanan">
                            <?php if ($adaRincian): ?>
                                <button class="tombol tombol-garis tombol-kecil" type="button"
                                        onclick="window.bukaModal('<?= e($bukaId) ?>')">Lihat</button>
                            <?php else: ?>
                                <span class="text-redup text-kecil">—</span>
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

<!-- ============ Modal rincian ============ -->
<?php foreach ($baris as $b): ?>
    <?php
        $adaRincian = ($b['data_lama'] !== null && $b['data_lama'] !== '')
                   || ($b['data_baru'] !== null && $b['data_baru'] !== '');
        if (!$adaRincian) {
            continue;
        }
        $bukaId = 'audit-' . (int) $b['id'];

        $rapikan = static function (?string $json): string {
            if ($json === null || trim($json) === '') {
                return '—';
            }
            $data = json_decode($json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $json;
            }
            $out = [];
            foreach ($data as $k => $v) {
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $out[] = $k . ': ' . (string) $v;
            }
            return implode("\n", $out);
        };

        $teksLama = $rapikan($b['data_lama'] !== null ? (string) $b['data_lama'] : null);
        $teksBaru = $rapikan($b['data_baru'] !== null ? (string) $b['data_baru'] : null);
    ?>
    <div class="modal-latar" id="<?= e($bukaId) ?>" data-modal>
        <div class="modal modal-lebar">
            <div class="modal-kepala">
                <h3>Rincian Perubahan #<?= (int) $b['id'] ?></h3>
                <button class="modal-tutup" type="button" onclick="window.tutupModal('<?= e($bukaId) ?>')" aria-label="Tutup">✕</button>
            </div>
            <div class="modal-badan">
                <div class="rincian mb-3">
                    <div class="baris"><span class="kunci">Aksi</span><span class="nilai"><?= e($labelAksi((string) $b['aksi'])) ?></span></div>
                    <div class="baris"><span class="kunci">Waktu</span><span class="nilai"><?= e(tanggal_id((string) $b['dibuat_pada'], 'd M Y H:i:s')) ?></span></div>
                    <div class="baris"><span class="kunci">Alamat IP</span><span class="nilai text-mono"><?= e((string) ($b['ip_address'] ?? '-')) ?></span></div>
                    <div class="baris"><span class="kunci">Peramban</span><span class="nilai text-kecil"><?= e(potong((string) ($b['user_agent'] ?? '-'), 120)) ?></span></div>
                </div>

                <div class="grid grid-2">
                    <div>
                        <label class="label-form">Data lama</label>
                        <pre class="teks-konten" style="white-space:pre-wrap;margin:6px 0 0;max-height:320px;overflow:auto"><?= e($teksLama) ?></pre>
                    </div>
                    <div>
                        <label class="label-form">Data baru</label>
                        <pre class="teks-konten" style="white-space:pre-wrap;margin:6px 0 0;max-height:320px;overflow:auto"><?= e($teksBaru) ?></pre>
                    </div>
                </div>
            </div>
            <div class="modal-kaki">
                <button class="tombol tombol-garis" type="button" onclick="window.tutupModal('<?= e($bukaId) ?>')">Tutup</button>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php akhiri_halaman(); ?>
