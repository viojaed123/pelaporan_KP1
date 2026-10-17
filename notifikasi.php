<?php
/**
 * notifikasi.php — daftar notifikasi milik pengguna yang sedang masuk.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + wajib_login() (semula session_start + cek $_SESSION manual)
 *   · layout terpusat — <style>/<head>/<body> lama dihapus
 *   · BUG KEAMANAN diperbaiki:
 *       - aksi "tandai dibaca" / "tandai semua dibaca" dulu POST tanpa CSRF
 *         -> kini memakai csrf_field('notifikasi_aksi') +
 *            csrf_cek_atau_matikan('notifikasi_aksi')
 *       - kolom `link` dulu dipakai langsung sebagai href (bisa javascript: /
 *         open redirect) -> kini disaring dengan bersihkan_tautan()
 *       - hanya notifikasi milik sesi_user_id() yang ditampilkan/diubah
 *         (WHERE user_id = ?)
 *   · paginasi + pola PRG (flash_set + redirect + exit) setelah operasi tulis
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();

/* ---------------------------------------------------------------
 * Proses aksi tulis (POST) — wajib bertoken CSRF, lalu PRG.
 * ------------------------------------------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('notifikasi_aksi');

    if (isset($_POST['baca_semua'])) {
        // Tandai semua notifikasi milik pengguna ini sebagai sudah dibaca.
        $stmt = $conn->prepare(
            'UPDATE notifikasi
             SET sudah_dibaca = 1
             WHERE user_id = ? AND sudah_dibaca = 0'
        );
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
            catat_audit($conn, 'notifikasi_dibaca_semua', 'notifikasi', null, 'Menandai semua notifikasi sudah dibaca');
            flash_set('sukses', 'Semua notifikasi ditandai sudah dibaca.');
        }
    } elseif (isset($_POST['baca_id'])) {
        // Tandai satu notifikasi — hanya bila memang milik pengguna ini.
        $baca_id = (int) $_POST['baca_id'];

        if ($baca_id > 0) {
            $stmt = $conn->prepare(
                'UPDATE notifikasi
                 SET sudah_dibaca = 1
                 WHERE id = ? AND user_id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('ii', $baca_id, $user_id);
                $stmt->execute();
                $stmt->close();
                catat_audit($conn, 'notifikasi_dibaca', 'notifikasi', $baca_id, 'Menandai satu notifikasi sudah dibaca');
                flash_set('sukses', 'Notifikasi ditandai sudah dibaca.');
            }
        }
    }

    header('Location: notifikasi.php');
    exit;
}

/* ---------------------------------------------------------------
 * Jumlah belum dibaca (dipakai juga oleh layout untuk lencana).
 * ------------------------------------------------------------- */

$jumlah_belum = jumlah_notifikasi($conn, $user_id);

/* ---------------------------------------------------------------
 * Paginasi
 * ------------------------------------------------------------- */

$per_halaman = 10;
$halaman     = max(1, (int) ($_GET['hal'] ?? 1));
$offset      = ($halaman - 1) * $per_halaman;

$stmtHitung = $conn->prepare('SELECT COUNT(*) FROM notifikasi WHERE user_id = ?');
$total_notifikasi = 0;
if ($stmtHitung) {
    $stmtHitung->bind_param('i', $user_id);
    $stmtHitung->execute();
    $stmtHitung->bind_result($total_notifikasi);
    $stmtHitung->fetch();
    $stmtHitung->close();
    $total_notifikasi = (int) $total_notifikasi;
}

$total_halaman = max(1, (int) ceil($total_notifikasi / $per_halaman));
if ($halaman > $total_halaman) {
    $halaman = $total_halaman;
    $offset  = ($halaman - 1) * $per_halaman;
}

/* Ambil notifikasi milik pengguna yang sedang login. */
$stmt = $conn->prepare(
    'SELECT id, laporan_id, pesan, link, sudah_dibaca, created_at
     FROM notifikasi
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC
     LIMIT ? OFFSET ?'
);

if ($stmt) {
    $stmt->bind_param('iii', $user_id, $per_halaman, $offset);
    $stmt->execute();
    $hasil = $stmt->get_result();
} else {
    $hasil = false;
}

/* Tautan paginasi tanpa kehilangan konteks. */
$tautan = static function (int $h): string {
    return 'notifikasi.php' . ($h > 1 ? '?hal=' . $h : '');
};

/* ---------------------------------------------------------------
 * Aksi topbar: tandai semua dibaca (hanya bila ada yang belum).
 * ------------------------------------------------------------- */

$aksi_topbar = $jumlah_belum > 0
    ? '<form method="POST" action="notifikasi.php" style="display:inline">'
        . csrf_field('notifikasi_aksi')
        . '<input type="hidden" name="baca_semua" value="1">'
        . '<button class="tombol tombol-utama tombol-kecil" type="submit">✓ Tandai semua dibaca</button>'
        . '</form>'
    : '';

mulai_halaman([
    'judul' => 'Notifikasi',
    'crumb' => 'Lainnya',
    'aksi'  => $aksi_topbar,
]);
?>

<?php if ($jumlah_belum > 0): ?>
    <div class="alert alert-info" role="status">
        <span class="ikon"><?= ikon_sp('lonceng') ?></span>
        <span>
            <strong>Anda memiliki <?= (int) $jumlah_belum ?> notifikasi belum dibaca</strong>
            Buka tiap notifikasi untuk melihat detailnya, atau tandai semuanya sebagai
            sudah dibaca melalui tombol di kanan atas.
        </span>
    </div>
<?php endif; ?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Daftar Notifikasi</h3>
            <span class="text-redup text-kecil">
                <?= (int) $total_notifikasi ?> notifikasi ·
                <?= (int) $jumlah_belum ?> belum dibaca
            </span>
        </div>
    </div>

    <div class="kartu-badan padat">
        <?php if (!$hasil || $total_notifikasi === 0): ?>
            <div class="tabel-kosong">
                <span class="ikon"><?= ikon_sp('lonceng') ?></span>
                Belum ada notifikasi.<br>
                Notifikasi baru akan muncul di halaman ini.<br>
                <a class="tombol tombol-garis tombol-kecil" style="margin-top:12px"
                   href="<?= e(halaman_default_peran(sesi_role())) ?>">Kembali ke Dashboard</a>
            </div>
        <?php else: ?>
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
                            <th>Notifikasi</th>
                            <th>Waktu</th>
                            <th>Keadaan</th>
                            <th class="text-kanan">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($item = $hasil->fetch_assoc()): ?>
                        <?php
                        // JANGAN percaya isi kolom `link` apa adanya: saring dulu.
                        $link_asli = (string) ($item['link'] ?? '');
                        $link_aman = bersihkan_tautan($link_asli);
                        $dibaca    = (int) $item['sudah_dibaca'] === 1;
                        $waktu_ts  = strtotime((string) $item['created_at']);
                        ?>
                        <tr>
                            <td>
                                <span class="potong-2"><?= e((string) $item['pesan']) ?></span>
                                <div class="text-redup text-kecil">#<?= (int) $item['id'] ?></div>
                            </td>
                            <td class="text-kecil text-redup">
                                <?= e(tanggal_id((string) $item['created_at'])) ?><br>
                                <?= e($waktu_ts !== false ? date('H:i', $waktu_ts) : '-') ?>
                            </td>
                            <td>
                                <span class="badge <?= $dibaca ? 'badge-hijau' : 'badge-biru' ?>">
                                    <?= $dibaca ? 'Sudah dibaca' : 'Baru' ?>
                                </span>
                            </td>
                            <td class="text-kanan">
                                <div class="aksi-baris">
                                    <?php if ($link_asli !== ''): ?>
                                        <a class="tombol tombol-kecil" href="<?= e($link_aman) ?>">Buka</a>
                                    <?php endif; ?>

                                    <?php if (!$dibaca): ?>
                                        <form method="POST" action="notifikasi.php">
                                            <?= csrf_field('notifikasi_aksi') ?>
                                            <input type="hidden" name="baca_id" value="<?= (int) $item['id'] ?>">
                                            <button class="tombol tombol-garis tombol-kecil" type="submit">
                                                Tandai dibaca
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_halaman > 1): ?>
        <div class="kartu-kaki">
            <nav class="paginasi" aria-label="Navigasi halaman" style="margin:0 auto">
                <?php if ($halaman > 1): ?>
                    <a href="<?= e($tautan($halaman - 1)) ?>" rel="prev">‹ Sebelumnya</a>
                <?php else: ?>
                    <span class="mati">‹ Sebelumnya</span>
                <?php endif; ?>

                <?php for ($h = 1; $h <= $total_halaman; $h++): ?>
                    <?php if ($h === $halaman): ?>
                        <span class="aktif" aria-current="page"><?= (int) $h ?></span>
                    <?php else: ?>
                        <a href="<?= e($tautan($h)) ?>"><?= (int) $h ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($halaman < $total_halaman): ?>
                    <a href="<?= e($tautan($halaman + 1)) ?>" rel="next">Berikutnya ›</a>
                <?php else: ?>
                    <span class="mati">Berikutnya ›</span>
                <?php endif; ?>
            </nav>
        </div>
    <?php endif; ?>
</section>

<?php
if ($stmt) {
    $stmt->close();
}
akhiri_halaman();
?>
