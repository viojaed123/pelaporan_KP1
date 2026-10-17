<?php
/**
 * admin_dashboard.php - ruang kerja administrator.
 *
 * Ringkasan:
 *   - bootstrap + penjaga peran admin
 *   - layout terpusat (memakai kelas app.css)
 *   - sambutan, peringatan akun menunggu verifikasi
 *   - ringkasan data (8 kartu statistik)
 *   - pintasan administrasi
 *   - aktivitas terbaru dari tabel audit_log
 *
 * Catatan: halaman ini sengaja ditulis tanpa emoji dan memakai ikon garis
 * (ikon_sp()) agar tampilan tetap bersih dan konsisten.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

$user_id = (int) sesi_user_id();

$admin = user_dari_db($conn, $user_id);
if ($admin === null) {
    $admin = ['nama' => sesi_nama(), 'role' => sesi_role()];
}

/* ---------------------------------------------------------------
 * 1. Ringkasan pengguna
 * ------------------------------------------------------------- */

$jumlahPengguna = ['total' => 0, 'menunggu' => 0, 'karyawan' => 0, 'mentor' => 0, 'admin' => 0];
$res = $conn->query(
    "SELECT role, status, COUNT(*) AS jumlah FROM users GROUP BY role, status"
);
while ($res && ($r = $res->fetch_assoc())) {
    $jumlahPengguna['total'] += (int) $r['jumlah'];
    if (($r['status'] ?? '') === 'menunggu') {
        $jumlahPengguna['menunggu'] += (int) $r['jumlah'];
    }
    $role = (string) ($r['role'] ?? '');
    if (isset($jumlahPengguna[$role])) {
        $jumlahPengguna[$role] += (int) $r['jumlah'];
    }
}

/* ---------------------------------------------------------------
 * 2. Ringkasan laporan
 * ------------------------------------------------------------- */

$statLaporan = ['draft' => 0, 'dikirim' => 0, 'diperiksa' => 0, 'perlu_revisi' => 0];
$res = $conn->query('SELECT status, COUNT(*) AS jumlah FROM laporan_harian GROUP BY status');
while ($res && ($r = $res->fetch_assoc())) {
    $k = (string) ($r['status'] ?? '');
    if (array_key_exists($k, $statLaporan)) {
        $statLaporan[$k] = (int) $r['jumlah'];
    }
}
$totalLaporan = array_sum($statLaporan);

/* ---------------------------------------------------------------
 * 3. Ringkasan data sosial
 * ------------------------------------------------------------- */

$jumlahSrm = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM srm_kegiatan');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahSrm = (int) $r['jumlah'];
}

$jumlahCid = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM cid_program');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahCid = (int) $r['jumlah'];
}

$jumlahWilayah = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM wilayah WHERE garis_lintang IS NOT NULL AND garis_bujur IS NOT NULL');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahWilayah = (int) $r['jumlah'];
}

$jumlahNotifikasi = jumlah_notifikasi($conn, $user_id);

/* ---------------------------------------------------------------
 * 4. Akun menunggu verifikasi (peringatan)
 * ------------------------------------------------------------- */

$akunMenunggu = [];
$stmt = $conn->prepare(
    "SELECT id, nama, email, role, created_at FROM users
      WHERE status = 'menunggu' ORDER BY created_at DESC LIMIT 6"
);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $akunMenunggu[] = $r;
    }
    $stmt->close();
}

/* ---------------------------------------------------------------
 * 5. Aktivitas terbaru (audit_log)
 * ------------------------------------------------------------- */

$aktivitas = [];
$stmt = $conn->prepare(
    'SELECT a.id, a.aksi, a.tabel_ref, a.baris_id, a.ringkasan, a.dibuat_pada,
            u.nama AS nama_pengguna, u.role AS role_pengguna
       FROM audit_log a
       LEFT JOIN users u ON u.id = a.user_id
      ORDER BY a.id DESC LIMIT 8'
);
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $aktivitas[] = $r;
    }
    $stmt->close();
}

/* Label ramah untuk jenis aksi audit. */
$labelAksiAudit = static function (string $aksi): string {
    return match (strtolower(trim($aksi))) {
        'login'            => 'Masuk',
        'logout'           => 'Keluar',
        'login_gagal'      => 'Gagal masuk',
        'login_terkunci'   => 'Akun terkunci',
        'buat'             => 'Membuat data',
        'ubah', 'update'   => 'Memperbarui data',
        'hapus'            => 'Menghapus data',
        'impor_unggah'     => 'Unggah berkas impor',
        'impor_simpan'     => 'Simpan hasil impor',
        'impor_srm_cid'    => 'Impor SRM & CID',
        'kirim'            => 'Mengirim laporan',
        'periksa'          => 'Memeriksa laporan',
        'backup'           => 'Cadangan data',
        'arsip'            => 'Pengarsipan',
        default            => $aksi !== '' ? ucwords(str_replace('_', ' ', $aksi)) : 'Aktivitas',
    };
};

$jumlahAktivitasHariIni = 0;
$res = $conn->query('SELECT COUNT(*) AS jumlah FROM audit_log WHERE DATE(dibuat_pada) = CURDATE()');
if ($res && ($r = $res->fetch_assoc())) {
    $jumlahAktivitasHariIni = (int) $r['jumlah'];
}

mulai_halaman([
    'judul' => 'Dashboard Admin',
    'crumb' => 'Administrator',
    'aktif' => 'dashboard',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="riwayat_audit.php">Riwayat Perubahan</a>',
]);
?>

<!-- ================= SAMBUTAN ================= -->
<section class="kartu dasbor-sambutan">
    <div class="kartu-tubuh">
        <span class="badge badge-tanpa-titik" style="background:rgba(255,255,255,.16);color:#cfe6f7">
            PORTAL ADMINISTRATOR <?= e(APP_ORGANISASI) ?>
        </span>
        <h2>Selamat datang, <?= e((string) $admin['nama']) ?></h2>
        <p>
            Kelola pengguna, pantau laporan kegiatan, tinjau data SRM &amp; CID,
            serta awasi seluruh aktivitas sistem dari satu halaman.
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <a class="tombol" style="background:#fff;border-color:#fff;color:var(--navy-600)" href="mentor_dashboard.php">Periksa Laporan</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="impor_laporan.php">Impor Data</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="peta.php">Peta Lokasi</a>
        </div>
    </div>
</section>

<?php if ($jumlahPengguna['menunggu'] > 0): ?>
<!-- ================= PERINGATAN VERIFIKASI ================= -->
<section class="kartu" style="margin-top:20px;border-color:rgba(183,121,31,.45);background:var(--kuning-50)">
    <div class="kartu-tubuh">
        <div class="d-flex items-center gap-3 flex-wrap">
            <span class="ikon" style="width:44px;height:44px;border-radius:12px;display:grid;place-items:center;background:#fff;color:var(--kuning-600)">
                <?= ikon_sp('lonceng') ?>
            </span>
            <div class="flex-1">
                <h3 style="margin:0;font-size:15px;color:var(--kuning-600)">
                    <?= (int) $jumlahPengguna['menunggu'] ?> akun menunggu verifikasi
                </h3>
                <p class="text-kecil" style="margin:4px 0 0;color:#92400e">
                    Tinjau dan aktifkan akun berikut agar dapat menggunakan aplikasi.
                </p>
            </div>
            <a class="tombol tombol-kecil" href="mentor_dashboard.php">Tindak lanjut</a>
        </div>

        <?php if ($akunMenunggu !== []): ?>
        <div class="bungkus-tabel" style="margin-top:14px">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th>Nama</th>
                        <th>Email</th>
                        <th>Peran</th>
                        <th>Terdaftar</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($akunMenunggu as $akun): ?>
                    <tr>
                        <td><?= e((string) $akun['nama']) ?></td>
                        <td class="text-kecil text-redup"><?= e((string) $akun['email']) ?></td>
                        <td><span class="badge badge-navy badge-tanpa-titik"><?= e(ucfirst((string) $akun['role'])) ?></span></td>
                        <td class="text-kecil text-redup"><?= e(tanggal_id($akun['created_at'] !== null ? (string) $akun['created_at'] : null, 'd M Y H:i')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- ================= STATISTIK ================= -->
<h2 class="bagian-judul">Ringkasan Data
    <span class="bagian-ket"><?= (int) $jumlahAktivitasHariIni ?> aktivitas tercatat hari ini</span>
</h2>

<section class="grid grid-4">
    <div class="stat navy">
        <span class="ikon"><?= ikon_sp('pengguna') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahPengguna['total'] ?></div>
            <div class="keterangan">Total Pengguna</div>
            <div class="tambahan"><?= (int) $jumlahPengguna['karyawan'] ?> karyawan &middot; <?= (int) $jumlahPengguna['mentor'] ?> mentor</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahPengguna['menunggu'] ?></div>
            <div class="keterangan">Menunggu Verifikasi</div>
            <div class="tambahan">Akun belum aktif</div>
        </div>
    </div>

    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $totalLaporan ?></div>
            <div class="keterangan">Total Laporan</div>
            <div class="tambahan"><?= (int) $statLaporan['draft'] ?> draf belum terkirim</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['dikirim'] ?></div>
            <div class="keterangan">Menunggu Pemeriksaan</div>
            <div class="tambahan">Perlu ditinjau segera</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('centang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['diperiksa'] ?></div>
            <div class="keterangan">Sudah Diperiksa</div>
            <div class="tambahan">Laporan selesai ditinjau</div>
        </div>
    </div>

    <div class="stat merah">
        <span class="ikon"><?= ikon_sp('silang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $statLaporan['perlu_revisi'] ?></div>
            <div class="keterangan">Perlu Revisi</div>
            <div class="tambahan">Menunggu perbaikan karyawan</div>
        </div>
    </div>

    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('kegiatan') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahSrm ?></div>
            <div class="keterangan">Kegiatan SRM</div>
            <div class="tambahan">Seluruh catatan hubungan masyarakat</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('koin') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlahCid ?></div>
            <div class="keterangan">Program CID</div>
            <div class="tambahan"><?= (int) $jumlahWilayah ?> wilayah berkoordinat</div>
        </div>
    </div>
</section>

<!-- ================= PINTASAN ADMINISTRASI ================= -->
<h2 class="bagian-judul">Pintasan Administrasi</h2>

<section class="aksi-cepat">
    <a href="mentor_dashboard.php">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <span>Periksa Laporan</span>
    </a>
    <a href="rekap_laporan.php">
        <span class="ikon"><?= ikon_sp('grafik') ?></span>
        <span>Rekap Laporan</span>
    </a>
    <a href="mentor_absensi.php">
        <span class="ikon"><?= ikon_sp('kalender') ?></span>
        <span>Absensi Karyawan</span>
    </a>
    <a href="impor_laporan.php">
        <span class="ikon"><?= ikon_sp('unggah') ?></span>
        <span>Impor Data</span>
    </a>
    <a href="impor_srm_cid.php">
        <span class="ikon"><?= ikon_sp('folder') ?></span>
        <span>Impor SRM &amp; CID</span>
    </a>
    <a href="peta.php">
        <span class="ikon"><?= ikon_sp('peta') ?></span>
        <span>Peta Lokasi</span>
    </a>
    <a href="riwayat_audit.php">
        <span class="ikon"><?= ikon_sp('perisai') ?></span>
        <span>Riwayat Perubahan</span>
    </a>
    <a href="backup.php">
        <span class="ikon"><?= ikon_sp('database') ?></span>
        <span>Cadangan Data</span>
    </a>
    <a href="notifikasi.php">
        <span class="ikon"><?= ikon_sp('lonceng') ?></span>
        <span>Notifikasi<?= $jumlahNotifikasi > 0 ? ' (' . (int) $jumlahNotifikasi . ')' : '' ?></span>
    </a>
    <a href="status.php">
        <span class="ikon"><?= ikon_sp('alat') ?></span>
        <span>Status Sistem</span>
    </a>
</section>

<!-- ================= AKTIVITAS TERBARU ================= -->
<section class="kartu" style="margin-top:22px">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Aktivitas Terbaru</h3>
            <span class="text-redup text-kecil">Delapan catatan aktivitas terakhir dari log sistem.</span>
        </div>
        <a class="tombol tombol-kecil" href="riwayat_audit.php">Lihat semua</a>
    </div>
    <div class="kartu-tubuh padat">
        <?php if ($aktivitas === []): ?>
            <div class="empty">
                <div class="empty-ikon"><?= ikon_sp('dokumen') ?></div>
                <div class="empty-judul">Belum ada aktivitas</div>
                <p class="empty-teks">Catatan aktivitas akan muncul di sini.</p>
            </div>
        <?php else: ?>
            <div class="bungkus-tabel">
                <table class="tabel tabel-padat">
                    <thead>
                        <tr>
                            <th>Waktu</th>
                            <th>Pengguna</th>
                            <th>Aksi</th>
                            <th>Ringkasan</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($aktivitas as $a): ?>
                        <tr>
                            <td class="text-kecil text-redup"><?= e(tanggal_id($a['dibuat_pada'] !== null ? (string) $a['dibuat_pada'] : null, 'd M Y H:i')) ?></td>
                            <td>
                                <?= e((string) ($a['nama_pengguna'] ?? 'Sistem')) ?>
                                <?php if (!empty($a['role_pengguna'])): ?>
                                    <span class="badge badge-navy badge-tanpa-titik" style="margin-left:6px"><?= e(ucfirst((string) $a['role_pengguna'])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-biru badge-tanpa-titik"><?= e($labelAksiAudit((string) $a['aksi'])) ?></span></td>
                            <td class="text-kecil"><?= e(potong((string) ($a['ringkasan'] ?? '-'), 80)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php akhiri_halaman(); ?>
