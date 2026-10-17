<?php
/**
 * backup.php — Pusat Pencadangan & Pemulihan Basis Data (khusus admin).
 *
 * Kemampuan:
 *   · Membuat cadangan (mysqldump) ke backups/db/*.sql
 *   · Mengunduh berkas cadangan
 *   · Menghapus berkas cadangan (beserta baris riwayatnya)
 *   · Memulihkan (restore) dari berkas cadangan — dengan cadangan pengaman
 *     otomatis sebelum penimpaan
 *
 * Keamanan:
 *   · hanya peran admin
 *   · semua aksi tulis memakai token CSRF + metode POST + konfirmasi
 *   · nama berkas selalu diverifikasi terhadap baris riwayat di basis data
 *     (mencegah path traversal)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

/* ================================================================
 * Utilitas
 * ============================================================= */

/**
 * Jalankan sebuah proses tanpa melewati shell.
 *
 * @param  string[]    $argv   argumen (argv[0] = biner)
 * @param  string|null $stdin  isi yang dikirim ke stdin proses
 * @return array{ok: bool, kode: int, keluaran: string, galat: string}
 */
function backup_jalankan(array $argv, ?string $stdin = null): array
{
    $hasil = ['ok' => false, 'kode' => -1, 'keluaran' => '', 'galat' => ''];

    $biner = $argv[0] ?? '';
    if ($biner === '' || !is_file($biner)) {
        $hasil['galat'] = 'Biner tidak ditemukan: ' . $biner;
        return $hasil;
    }

    $pipa = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $proses = @proc_open($argv, $pipa, $pipaBuka);
    if (!is_resource($proses)) {
        $hasil['galat'] = 'Tidak dapat menjalankan proses.';
        return $hasil;
    }

    if ($stdin !== null) {
        fwrite($pipaBuka[0], $stdin);
    }
    fclose($pipaBuka[0]);

    $hasil['keluaran'] = (string) stream_get_contents($pipaBuka[1]);
    $hasil['galat']    = (string) stream_get_contents($pipaBuka[2]);
    fclose($pipaBuka[1]);
    fclose($pipaBuka[2]);

    $hasil['kode'] = (int) proc_close($proses);
    $hasil['ok']   = $hasil['kode'] === 0;

    return $hasil;
}

/** Argumen koneksi bersama untuk mysqldump / mysql. */
function backup_argumen_koneksi(): array
{
    $arg = ['-h', DB_HOST, '-u', DB_USER];
    if (DB_PASS !== '') {
        $arg[] = '--password=' . DB_PASS;
    }
    return $arg;
}

/**
 * Verifikasi bahwa nama berkas benar-benar terdaftar pada riwayat cadangan
 * dan menunjuk ke berkas nyata di dalam BACKUP_DIR.
 *
 * @return array|null baris riwayat
 */
function backup_ambil_baris(mysqli $conn, int $id): ?array
{
    $stmt = $conn->prepare('SELECT * FROM backup_riwayat WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $baris = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($baris === null) {
        return null;
    }

    // Cegah path traversal: paksa hanya nama dasar.
    $namaAman = basename((string) $baris['nama_file']);
    $jalur    = rtrim(BACKUP_DIR, '/\\') . DIRECTORY_SEPARATOR . $namaAman;

    if (!is_file($jalur)) {
        return null;
    }

    $baris['_jalur'] = $jalur;
    $baris['_nama']  = $namaAman;

    // Cocokkan checksum bila ada.
    $baris['_checksum_cocok'] = true;
    if (!empty($baris['checksum'])) {
        $baris['_checksum_cocok'] = hash_file('sha256', $jalur) === (string) $baris['checksum'];
    }

    return $baris;
}

/** Buat cadangan ke berkas, kembalikan nama berkas atau null. */
function backup_buat_berkas(mysqli $conn, string $tipe, ?int $oleh): ?string
{
    if (!is_dir(BACKUP_DIR)) {
        @mkdir(BACKUP_DIR, 0770, true);
    }
    if (!is_dir(BACKUP_DIR) || !is_writable(BACKUP_DIR)) {
        return null;
    }

    $stempel = date('Ymd_His');
    $nama    = 'pelaporan_' . $stempel . '_' . strtolower($tipe) . '.sql';
    $jalur   = rtrim(BACKUP_DIR, '/\\') . DIRECTORY_SEPARATOR . $nama;

    $argv = array_merge(
        [MYSQLDUMP_BIN],
        backup_argumen_koneksi(),
        [
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--default-character-set=utf8mb4',
            '--result-file=' . $jalur,
            DB_NAME,
        ]
    );

    $hasil = backup_jalankan($argv);

    if (!$hasil['ok'] || !is_file($jalur) || filesize($jalur) === 0) {
        if (is_file($jalur)) {
            @unlink($jalur);
        }
        error_log('backup: gagal mysqldump - ' . $hasil['galat']);
        return null;
    }

    /* ---------------------------------------------------------------
     * Netralkan klausa DEFINER pada berkas cadangan.
     *
     * mysqldump menuliskan, mis. `DEFINER=`root`@`localhost`` pada
     * setiap VIEW / TRIGGER / ROUTINE. Saat pemulihan dijalankan oleh
     * akun aplikasi (`pelaporan_app`) yang TIDAK punya SUPER, MySQL
     * menolak dengan:
     *     ERROR 1227 Access denied; you need ... SUPER privilege(s)
     * Akibatnya `mysql` berhenti di tengah berkas dan VIEW pertama
     * terhapus tanpa dibuat ulang.
     *
     * Dengan menghapus klausa DEFINER, objek dibuat memakai definer =
     * pemanggil (akun aplikasi). `SQL SECURITY DEFINER` tetap
     * dipertahankan sehingga `bi_reader` tetap dapat membaca VIEW
     * (definer = pelaporan_app yang punya hak penuh atas basis data).
     * ------------------------------------------------------------- */
    $isi = @file_get_contents($jalur);
    if ($isi === false) {
        @unlink($jalur);
        error_log('backup: gagal membaca berkas cadangan untuk normalisasi DEFINER.');
        return null;
    }
    $isiBersih = preg_replace('/DEFINER=`[^`]*`@`[^`]*`/', '', $isi);
    if ($isiBersih === null) {
        @unlink($jalur);
        error_log('backup: gagal menormalkan DEFINER pada berkas cadangan.');
        return null;
    }
    if ($isiBersih !== $isi && @file_put_contents($jalur, $isiBersih, LOCK_EX) === false) {
        @unlink($jalur);
        error_log('backup: gagal menulis ulang berkas cadangan tanpa DEFINER.');
        return null;
    }
    unset($isi, $isiBersih);

    $ukuran   = (int) filesize($jalur);
    $checksum = (string) hash_file('sha256', $jalur);

    $stmt = $conn->prepare(
        'INSERT INTO backup_riwayat (nama_file, ukuran_bytes, checksum, tipe, dibuat_oleh)
         VALUES (?, ?, ?, ?, ?)'
    );
    if ($stmt) {
        $stmt->bind_param('sissi', $nama, $ukuran, $checksum, $tipe, $oleh);
        $stmt->execute();
        $stmt->close();
    }

    return $nama;
}

/**
 * Selaraskan tabel backup_riwayat dengan berkas yang benar-benar ada di
 * BACKUP_DIR.
 *
 * Berguna setelah pemulihan: tabel backup_riwayat ikut kembali ke keadaan
 * pada saat dump, sehingga baris cadangan yang dibuat SETELAH dump (termasuk
 * cadangan pengaman otomatis) hilang dari tabel padahal berkasnya ada.
 * Fungsi ini mendaftarkan ulang berkas yang belum tercatat.
 *
 * @return int jumlah baris yang ditambahkan
 */
function backup_sinkron_riwayat(mysqli $conn): int
{
    if (!is_dir(BACKUP_DIR)) {
        return 0;
    }

    $tercatat = [];
    $res = $conn->query('SELECT id, nama_file FROM backup_riwayat');
    if ($res) {
        while ($b = $res->fetch_row()) {
            $tercatat[(string) $b[1]] = true;
        }
    }

    $tambah = 0;
    $daftar = @scandir(BACKUP_DIR);
    if ($daftar === false) {
        return 0;
    }

    foreach ($daftar as $nama) {
        if ($nama === '.' || $nama === '..' || isset($tercatat[$nama])) {
            continue;
        }
        $jalur = BACKUP_DIR . DIRECTORY_SEPARATOR . $nama;
        if (!is_file($jalur) || strtolower((string) pathinfo($nama, PATHINFO_EXTENSION)) !== 'sql') {
            continue;
        }

        $ukuran = (int) filesize($jalur);
        $cksum  = (string) hash_file('sha256', $jalur);
        $waktu  = date('Y-m-d H:i:s', (int) filemtime($jalur));
        $tipe   = str_contains($nama, '_otomatis') ? 'otomatis' : 'manual';

        $stmt = $conn->prepare(
            'INSERT INTO backup_riwayat (nama_file, ukuran_bytes, checksum, tipe, dibuat_oleh, dibuat_pada)
             VALUES (?, ?, ?, ?, NULL, ?)'
        );
        if ($stmt) {
            $stmt->bind_param('sisss', $nama, $ukuran, $cksum, $tipe, $waktu);
            $stmt->execute();
            $stmt->close();
            $tambah++;
            $tercatat[$nama] = true;
        }
    }

    return $tambah;
}

/** Hapus cadangan lama sehingga hanya tersisa BACKUP_SIMPAN_MAKS terbaru. */
function backup_pangkas(mysqli $conn): void
{
    $batas = max(1, (int) BACKUP_SIMPAN_MAKS);
    $res = $conn->query(
        'SELECT id, nama_file FROM backup_riwayat ORDER BY id DESC LIMIT 100 OFFSET ' . $batas
    );
    if (!$res) {
        return;
    }
    while ($b = $res->fetch_assoc()) {
        $jalur = rtrim(BACKUP_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string) $b['nama_file']);
        if (is_file($jalur)) {
            @unlink($jalur);
        }
        $id = (int) $b['id'];
        $conn->query('DELETE FROM backup_riwayat WHERE id = ' . $id);
    }
}

/* ================================================================
 * Penanganan aksi
 * ============================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    csrf_cek_atau_matikan('backup_' . $aksi);

    switch ($aksi) {

        /* ---------- Buat cadangan baru ---------- */
        case 'backup_buat': {
            $nama = backup_buat_berkas($conn, 'manual', (int) sesi_user_id());

            if ($nama === null) {
                catat_audit($conn, 'backup_gagal', 'backup_riwayat', null, 'Pembuatan cadangan gagal', null, null);
                flash_set('gagal', 'Gagal membuat cadangan. Periksa konfigurasi biner mysqldump dan izin folder backups/db.');
            } else {
                backup_pangkas($conn);
                catat_audit($conn, 'backup_buat', 'backup_riwayat', null, 'Cadangan dibuat: ' . $nama, null, ['nama_file' => $nama]);
                flash_set('sukses', 'Cadangan berhasil dibuat: ' . $nama);
            }

            header('Location: backup.php');
            exit;
        }

        /* ---------- Hapus cadangan ---------- */
        case 'backup_hapus': {
            $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $baris = $id > 0 ? backup_ambil_baris($conn, $id) : null;

            if ($baris === null) {
                flash_set('gagal', 'Berkas cadangan tidak ditemukan.');
                header('Location: backup.php');
                exit;
            }

            @unlink((string) $baris['_jalur']);

            $stmt = $conn->prepare('DELETE FROM backup_riwayat WHERE id = ?');
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
            }

            catat_audit($conn, 'backup_hapus', 'backup_riwayat', $id, 'Cadangan dihapus: ' . $baris['_nama'], $baris, null);

            flash_set('sukses', 'Cadangan ' . $baris['_nama'] . ' telah dihapus.');
            header('Location: backup.php');
            exit;
        }

        /* ---------- Pulihkan dari cadangan ---------- */
        case 'backup_pulihkan': {
            $id    = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
            $baris = $id > 0 ? backup_ambil_baris($conn, $id) : null;

            if ($baris === null) {
                flash_set('gagal', 'Berkas cadangan tidak valid atau tidak ditemukan.');
                header('Location: backup.php');
                exit;
            }

            if (!empty($_POST['konfirmasi_pulihkan']) && $_POST['konfirmasi_pulihkan'] === 'PULIHKAN') {
                // 1. Cadangan pengaman sebelum menimpa.
                $pengaman = backup_buat_berkas($conn, 'manual', (int) sesi_user_id());
                if ($pengaman === null) {
                    flash_set('gagal', 'Dibatalkan: cadangan pengaman gagal dibuat. Pemulihan tidak dijalankan.');
                    header('Location: backup.php');
                    exit;
                }

                // 2. Jalankan mysql < berkas.
                $isi = (string) file_get_contents((string) $baris['_jalur']);

                $argv = array_merge(
                    [MYSQL_BIN],
                    backup_argumen_koneksi(),
                    ['--default-character-set=utf8mb4', DB_NAME]
                );

                $hasil = backup_jalankan($argv, $isi);

                if ($hasil['ok']) {
                    catat_audit(
                        $conn, 'backup_pulihkan', 'backup_riwayat', $id,
                        'Basis data dipulihkan dari ' . $baris['_nama'] . ' (pengaman: ' . $pengaman . ')',
                        null,
                        ['dipulihkan_dari' => $baris['_nama'], 'cadangan_pengaman' => $pengaman]
                    );

                    // Tabel backup_riwayat ikut terputar balik; daftarkan ulang
                    // berkas cadangan (termasuk cadangan pengaman) dari disk.
                    backup_sinkron_riwayat($conn);

                    flash_set('sukses', 'Basis data berhasil dipulihkan dari ' . $baris['_nama'] . '. Cadangan pengaman: ' . $pengaman . '.');
                } else {
                    catat_audit(
                        $conn, 'backup_pulihkan_gagal', 'backup_riwayat', $id,
                        'Pemulihan gagal dari ' . $baris['_nama'], null, null
                    );
                    // Pemulihan yang gagal di tengah tetap dapat memutar
                    // balik tabel backup_riwayat; selaraskan dengan disk.
                    backup_sinkron_riwayat($conn);
                    flash_set('gagal', 'Pemulihan gagal: ' . potong($hasil['galat'] !== '' ? $hasil['galat'] : 'kesalahan tidak diketahui', 200));
                }
            } else {
                flash_set('gagal', 'Pemulihan dibatalkan: kata konfirmasi tidak sesuai.');
            }

            header('Location: backup.php');
            exit;
        }

        default:
            flash_set('gagal', 'Tindakan tidak dikenal.');
            header('Location: backup.php');
            exit;
    }
}

/* ================================================================
 * Unduh berkas (GET, hanya admin)
 * ============================================================= */

if (($_GET['aksi'] ?? '') === 'unduh') {
    $id    = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
    $baris = $id > 0 ? backup_ambil_baris($conn, $id) : null;

    if ($baris === null) {
        http_response_code(404);
        exit('Berkas cadangan tidak ditemukan.');
    }

    catat_audit($conn, 'backup_unduh', 'backup_riwayat', $id, 'Cadangan diunduh: ' . $baris['_nama'], null, null);

    // Bersihkan buffer agar berkas tidak rusak.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $baris['_nama'] . '"');
    header('Content-Length: ' . (string) filesize((string) $baris['_jalur']));
    header('X-Content-Type-Options: nosniff');
    readfile((string) $baris['_jalur']);
    exit;
}

/* ================================================================
 * Data untuk tampilan
 * ============================================================= */

// Pastikan katalog cadangan selaras dengan berkas di disk (misalnya sesudah
// pemulihan yang mengembalikan tabel ke keadaan lama).
backup_sinkron_riwayat($conn);

$cadangan = [];
$res = $conn->query(
    'SELECT b.*, u.nama AS nama_pembuat
     FROM backup_riwayat b
     LEFT JOIN users u ON u.id = b.dibuat_oleh
     ORDER BY b.id DESC
     LIMIT 50'
);
if ($res) {
    while ($b = $res->fetch_assoc()) {
        $jalur = rtrim(BACKUP_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string) $b['nama_file']);
        $b['_ada']    = is_file($jalur);
        $b['_ukuran'] = $b['_ada'] ? (int) filesize($jalur) : 0;
        $cadangan[]   = $b;
    }
}

$totalUkuran = 0;
foreach ($cadangan as $c) {
    if ($c['_ada']) {
        $totalUkuran += (int) $c['_ukuran'];
    }
}

/** Format ukuran byte agar mudah dibaca. */
function ukuran_mudah(int $byte): string
{
    if ($byte >= 1048576) {
        return number_format($byte / 1048576, 2, ',', '.') . ' MB';
    }
    if ($byte >= 1024) {
        return number_format($byte / 1024, 1, ',', '.') . ' KB';
    }
    return $byte . ' B';
}

$binerSiap = is_file(MYSQLDUMP_BIN) && is_file(MYSQL_BIN);
$folderSiap = is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR);

mulai_halaman([
    'judul' => 'Cadangan & Pemulihan',
    'crumb' => 'Administrasi',
    'aktif' => 'backup',
    'aksi'  => '<form method="POST" action="backup.php">'
             . csrf_field('backup_backup_buat')
             . '<input type="hidden" name="aksi" value="backup_buat">'
             . '<button class="tombol tombol-utama" type="submit">＋ Buat Cadangan</button>'
             . '</form>',
]);
?>

<!-- ============ Status sistem ============ -->
<div class="grid grid-3">
    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('floppy') ?></span>
        <div class="nilai"><?= count($cadangan) ?></div>
        <div class="label">Berkas cadangan</div>
    </div>
    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('kotak') ?></span>
        <div class="nilai"><?= e(ukuran_mudah($totalUkuran)) ?></div>
        <div class="label">Total ukuran</div>
    </div>
    <div class="stat <?= $binerSiap && $folderSiap ? 'hijau' : 'merah' ?>">
        <span class="ikon"><?= $binerSiap && $folderSiap ? ikon_sp('centang') : ikon_sp('peringatan') ?></span>
        <div class="nilai"><?= $binerSiap && $folderSiap ? 'Siap' : 'Periksa' ?></div>
        <div class="label">Status pencadangan</div>
    </div>
</div>

<?php if (!$binerSiap): ?>
    <div class="alert alert-peringatan">
        <span class="ikon">!</span>
        <span>
            <strong>Biner MySQL tidak ditemukan</strong>
            Periksa konstanta <span class="text-mono">MYSQLDUMP_BIN</span> dan
            <span class="text-mono">MYSQL_BIN</span> pada <span class="text-mono">includes/config.php</span>.
        </span>
    </div>
<?php elseif (!$folderSiap): ?>
    <div class="alert alert-peringatan">
        <span class="ikon">!</span>
        <span>
            <strong>Folder cadangan tidak dapat ditulis</strong>
            Pastikan folder <span class="text-mono">backups/db</span> ada dan dapat ditulis oleh server web.
        </span>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <span class="ikon">i</span>
    <span>
        Cadangan dibuat dengan <span class="text-mono">mysqldump</span> dan disimpan di
        <span class="text-mono">backups/db/</span> (tidak dapat diakses langsung dari web).
        Sebelum pemulihan, sistem otomatis membuat cadangan pengaman terlebih dahulu.
    </span>
</div>

<!-- ============ Daftar cadangan ============ -->
<section class="kartu">
    <div class="kartu-kepala">
        <h3><?= ikon_sp('arsip') ?> Daftar Cadangan</h3>
        <span class="text-redup text-kecil" style="margin-left:auto">
            Menyimpan maksimal <?= (int) BACKUP_SIMPAN_MAKS ?> berkas terbaru
        </span>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th>Nama berkas</th>
                        <th>Ukuran</th>
                        <th>Jenis</th>
                        <th>Dibuat oleh</th>
                        <th>Waktu</th>
                        <th>Integritas</th>
                        <th class="text-kanan">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($cadangan === []): ?>
                    <tr>
                        <td colspan="7" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('kotak_masuk') ?></span>
                            Belum ada berkas cadangan. Klik <strong>Buat Cadangan</strong> untuk memulai.
                        </td>
                    </tr>
                <?php else: foreach ($cadangan as $c): ?>
                    <tr>
                        <td>
                            <span class="text-mono text-kecil"><?= e((string) $c['nama_file']) ?></span>
                            <?php if (!$c['_ada']): ?>
                                <span class="badge badge-merah badge-tanpa-titik" style="margin-left:6px">Hilang</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-kecil"><?= $c['_ada'] ? e(ukuran_mudah((int) $c['_ukuran'])) : '—' ?></td>
                        <td>
                            <span class="badge <?= $c['tipe'] === 'otomatis' ? 'badge-biru' : 'badge-navy' ?> badge-tanpa-titik">
                                <?= e(ucfirst((string) $c['tipe'])) ?>
                            </span>
                        </td>
                        <td class="text-kecil"><?= e((string) ($c['nama_pembuat'] ?? 'Sistem')) ?></td>
                        <td class="text-kecil text-redup" style="white-space:nowrap">
                            <?= e(tanggal_id((string) $c['dibuat_pada'], 'd M Y H:i')) ?>
                        </td>
                        <td class="text-kecil">
                            <?php if (!$c['_ada']): ?>
                                <span class="text-redup">—</span>
                            <?php else: ?>
                                <?php
                                    $jalur = rtrim(BACKUP_DIR, '/\\') . DIRECTORY_SEPARATOR . basename((string) $c['nama_file']);
                                    $cocok = empty($c['checksum']) || hash_file('sha256', $jalur) === (string) $c['checksum'];
                                ?>
                                <span class="badge <?= $cocok ? 'badge-hijau' : 'badge-merah' ?> badge-tanpa-titik">
                                    <?= $cocok ? 'Utuh' : 'Berubah' ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-kanan">
                            <div class="aksi-baris" style="justify-content:flex-end">
                                <?php if ($c['_ada']): ?>
                                    <a class="tombol tombol-garis tombol-kecil"
                                       href="backup.php?aksi=unduh&amp;id=<?= (int) $c['id'] ?>">Unduh</a>

                                    <button class="tombol tombol-kecil" type="button"
                                            onclick="window.bukaModal('pulihkan-<?= (int) $c['id'] ?>')">Pulihkan</button>

                                    <form method="POST" action="backup.php" style="display:inline"
                                          data-konfirmasi="Hapus berkas cadangan ini secara permanen?">
                                        <?= csrf_field('backup_backup_hapus') ?>
                                        <input type="hidden" name="aksi" value="backup_hapus">
                                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                        <button class="tombol tombol-bahaya tombol-kecil" type="submit">Hapus</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-redup text-kecil">Berkas tidak ada</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ============ Modal pemulihan ============ -->
<?php foreach ($cadangan as $c): ?>
    <?php if (!$c['_ada']) { continue; } ?>
    <div class="modal-latar" id="pulihkan-<?= (int) $c['id'] ?>" data-modal>
        <div class="modal">
            <form method="POST" action="backup.php">
                <?= csrf_field('backup_backup_pulihkan') ?>
                <input type="hidden" name="aksi" value="backup_pulihkan">
                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">

                <div class="modal-kepala">
                    <h3>Pulihkan Basis Data</h3>
                    <button class="modal-tutup" type="button" onclick="window.tutupModal('pulihkan-<?= (int) $c['id'] ?>')" aria-label="Tutup">✕</button>
                </div>
                <div class="modal-badan">
                    <div class="alert alert-peringatan">
                        <span class="ikon">!</span>
                        <span>
                            <strong>Tindakan berisiko</strong>
                            Seluruh data saat ini — termasuk riwayat perubahan — akan
                            <strong>ditimpa</strong> oleh isi berkas cadangan.
                        </span>
                    </div>

                    <div class="rincian mb-3">
                        <div class="baris"><span class="kunci">Berkas</span><span class="nilai text-mono text-kecil"><?= e((string) $c['nama_file']) ?></span></div>
                        <div class="baris"><span class="kunci">Waktu dibuat</span><span class="nilai"><?= e(tanggal_id((string) $c['dibuat_pada'], 'd M Y H:i')) ?></span></div>
                        <div class="baris"><span class="kunci">Ukuran</span><span class="nilai"><?= e(ukuran_mudah((int) $c['_ukuran'])) ?></span></div>
                    </div>

                    <p class="text-redup text-kecil">
                        Sistem akan membuat cadangan pengaman otomatis sebelum memulihkan.
                        Ketik <strong>PULIHKAN</strong> untuk mengonfirmasi.
                    </p>

                    <div class="grup-form">
                        <label for="konfirmasi-<?= (int) $c['id'] ?>">Kata konfirmasi</label>
                        <input id="konfirmasi-<?= (int) $c['id'] ?>" name="konfirmasi_pulihkan" type="text"
                               placeholder="PULIHKAN" autocomplete="off" required>
                    </div>
                </div>
                <div class="modal-kaki">
                    <button class="tombol tombol-garis" type="button"
                            onclick="window.tutupModal('pulihkan-<?= (int) $c['id'] ?>')">Batal</button>
                    <button class="tombol tombol-bahaya" type="submit">Pulihkan Sekarang</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php akhiri_halaman(); ?>
