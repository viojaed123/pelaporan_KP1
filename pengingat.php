<?php
/**
 * pengingat.php — Pengingat laporan harian (khusus admin).
 *
 * Menampilkan karyawan yang BELUM mengisi laporan pada tanggal terpilih
 * (bawaan: hari ini, Asia/Jakarta) dan menyediakan tombol untuk mengirim
 * notifikasi pengingat seketika.
 *
 * Logika pengingat tidak ditulis ulang di sini: berkas ini menyertakan
 * scripts/pengingat_harian.php (implementasi tunggal) dan memanggil
 * pengingat_harian_jalankan(). Bagian CLI pada berkas itu hanya berjalan
 * bila dijalankan langsung, sehingga aman untuk di-include dari sini.
 *
 * Keamanan: hanya peran admin; aksi tulis memakai POST + token CSRF
 * per-aksi ('pengingat_kirim') lalu pola PRG (flash_set + redirect + exit).
 *
 * Penjadwalan otomatis (opsional, jalankan di Task Scheduler):
 *   php C:\xampp\htdocs\pelaporan\scripts\pengingat_harian.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/scripts/pengingat_harian.php';

wajib_login();
wajib_role(['admin'], null, $conn);

$kesalahan = '';

/* ================================================================
 * Aksi tulis (POST) — kirim pengingat seketika, lalu pola PRG.
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'pengingat_kirim') {
    csrf_cek_atau_matikan('pengingat_kirim');

    $tanggal = (string) ($_POST['tanggal'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    try {
        $hasil = pengingat_harian_jalankan($conn, $tanggal, false);

        catat_audit($conn, 'pengingat_kirim', 'notifikasi', null,
            'Pengingat ' . $tanggal . ': ' . $hasil['dikirim'] . ' notifikasi dikirim', null, null);

        if ($hasil['dikirim'] > 0) {
            flash_set('sukses', $hasil['dikirim'] . ' pengingat dikirim untuk ' . tanggal_id($tanggal) . '.');
        } elseif ($hasil['perlu'] === 0) {
            flash_set('info', 'Semua karyawan sudah mengisi laporan pada ' . tanggal_id($tanggal) . '.');
        } else {
            flash_set('info', 'Tidak ada pengiriman baru (semua sudah pernah diingatkan).');
        }
    } catch (Throwable $t) {
        error_log('pengingat: ' . $t->getMessage());
        $kesalahan = 'Gagal mengirim pengingat.';
    }

    header('Location: pengingat.php?tanggal=' . urlencode($tanggal));
    exit;
}

/* ================================================================
 * Data tampilan
 * ================================================================ */
$tanggal = (string) ($_GET['tanggal'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$hasil = pengingat_harian_jalankan($conn, $tanggal, true);

$perluDiingatkan = [];
foreach ($hasil['daftar'] as $d) {
    if ($d['tindakan'] === 'perlu_diingatkan') {
        $perluDiingatkan[] = $d;
    }
}

$aksiTopbar = '<span class="badge badge-navy badge-tanpa-titik">'
    . tanggal_id($tanggal) . '</span>';

mulai_halaman([
    'judul' => 'Pengingat Harian',
    'crumb' => APP_SINGKAT,
    'aktif' => 'pengingat',
    'aksi'  => $aksiTopbar,
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Ringkasan <?= e(tanggal_id($tanggal)) ?></h2>
    </div>
    <div class="kartu-badan">
        <?php if ($kesalahan !== ''): ?>
            <div class="alert alert-gagal"><span class="ikon">✕</span><span><?= e($kesalahan) ?></span></div>
        <?php endif; ?>

        <div class="kalender-ringkas" style="padding:0">
            <div class="ringkas-butir">
                <span class="angka"><?= (int) $hasil['dicek'] ?></span>
                <span class="ket">Karyawan diperiksa</span>
            </div>
            <div class="ringkas-butir <?= $perluDiingatkan !== [] ? 'ringkas-revisi' : 'ringkas-hadir' ?>">
                <span class="angka"><?= (int) $hasil['perlu'] ?></span>
                <span class="ket">Belum mengisi laporan</span>
            </div>
            <div class="ringkas-butir">
                <span class="angka"><?= (int) $hasil['dilewati'] ?></span>
                <span class="ket">Dilewati</span>
            </div>
        </div>

        <form method="post" action="pengingat.php" style="margin-top:18px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
            <?= csrf_field('pengingat_kirim') ?>
            <input type="hidden" name="aksi" value="pengingat_kirim">
            <label>Tanggal
                <input type="date" name="tanggal" value="<?= e($tanggal) ?>" required>
            </label>
            <button class="tombol tombol-utama" type="submit">Kirim pengingat sekarang</button>
        </form>
        <p class="text-redup" style="margin-top:10px;font-size:13px">
            Pengiriman otomatis setiap hari dapat dijalankan lewat Task Scheduler:
            <span class="text-mono">php C:\xampp\htdocs\pelaporan\scripts\pengingat_harian.php</span>
        </p>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Daftar karyawan</h2>
    </div>
    <div class="kartu-badan padat">
        <?php if ($hasil['daftar'] === []): ?>
            <div class="tabel-kosong">Belum ada akun karyawan.</div>
        <?php else: ?>
            <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Divisi</th>
                        <th class="text-kanan">Laporan</th>
                        <th class="text-kanan">Absensi</th>
                        <th class="text-kanan">Pengingat</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($hasil['daftar'] as $d): ?>
                    <tr>
                        <td><?= e((string) $d['nama']) ?></td>
                        <td><?= e($d['divisi'] !== '' ? $d['divisi'] : '—') ?></td>
                        <td class="text-kanan">
                            <?php if ($d['sudah_lapor']): ?>
                                <span class="badge badge-hijau badge-tanpa-titik">sudah</span>
                            <?php else: ?>
                                <span class="badge badge-merah badge-tanpa-titik">belum</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-kanan">
                            <?php if ($d['absensi'] !== ''): ?>
                                <span class="badge badge-navy badge-tanpa-titik"><?= e($d['absensi']) ?></span>
                            <?php else: ?>
                                <span class="text-redup">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-kanan">
                            <?php switch ($d['tindakan']):
                                case 'sudah_lapor': ?>
                                    <span class="text-redup" style="font-size:13px">tidak perlu</span>
                                    <?php break;
                                case 'izin_sakit': ?>
                                    <span class="badge badge-kuning badge-tanpa-titik">izin/sakit</span>
                                    <?php break;
                                case 'sudah_diingatkan': ?>
                                    <span class="badge badge-biru badge-tanpa-titik">sudah dikirim</span>
                                    <?php break;
                                default: ?>
                                    <span class="badge badge-merah badge-tanpa-titik">perlu dikirim</span>
                            <?php endswitch; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
akhiri_halaman();
