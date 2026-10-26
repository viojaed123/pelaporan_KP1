<?php
/**
 * includes/layout.php
 * Kerangka halaman terpusat: sidebar, topbar, flash message, footer.
 *
 * Pakai:
 *   mulai_halaman(['judul' => 'Dashboard']);
 *   ... konten ...
 *   akhiri_halaman();
 *
 * Sebelumnya SETIAP file punya <style> dan menu sidebar sendiri-sendiri.
 *
 * Catatan: seluruh berkas ini sengaja ditulis dengan karakter ASCII saja.
 * Ikon memakai SVG garis (melalui ikon_sp()) agar tampil konsisten dan tidak
 * rusak walau berkas dibuka/disimpan dengan pengodean berbeda.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/* ---------------------------------------------------------------
 * Lambang (logo) PHR - dipakai di sidebar, halaman masuk, dsb.
 * Mengutamakan SVG; jatuh ke PNG; bila tak ada, memakai teks "PH".
 * Ganti berkas assets/img/logo-phr.svg dengan lambang resmi bila ada.
 * ------------------------------------------------------------- */
function logo_phr(bool $dengan_wadah = true): string
{
    $svg = dirname(__DIR__) . '/assets/img/logo-phr.svg';
    $png = dirname(__DIR__) . '/assets/img/logo-phr.png';
    $src = is_file($svg) ? 'assets/img/logo-phr.svg' : (is_file($png) ? 'assets/img/logo-phr.png' : '');

    if ($src === '') {
        return $dengan_wadah ? '<span class="logo">PH</span>' : 'PH';
    }

    $gambar = '<img src="' . e($src) . '" alt="PHR" width="64" height="64">';

    return $dengan_wadah ? '<span class="logo logo-gambar">' . $gambar . '</span>' : $gambar;
}

/* ---------------------------------------------------------------
 * Definisi menu per peran
 * ------------------------------------------------------------- */

function menu_tersedia(): array
{
    return [
        'karyawan' => [
            ['grup' => 'Utama'],
            ['key' => 'dashboard',   'ikon' => 'grafik', 'label' => 'Dashboard',   'url' => 'dashboard.php'],
            ['key' => 'buat',        'ikon' => 'tulis',  'label' => 'Buat Laporan', 'url' => 'buat_laporan.php'],
            ['key' => 'riwayat',     'ikon' => 'jam', 'label' => 'Riwayat Laporan', 'url' => 'riwayat.php'],
            ['key' => 'absensi',     'ikon' => 'kalender', 'label' => 'Absensi',      'url' => 'absensi.php'],
            ['key' => 'impor',       'ikon' => 'unggah', 'label' => 'Unggah Data',  'url' => 'impor_laporan.php'],

            ['key' => 'ringkasan',   'ikon' => 'kegiatan', 'label' => 'Ringkasan',    'url' => 'ringkasan.php'],
            ['key' => 'kalender',    'ikon' => 'kalender', 'label' => 'Kalender Aktivitas', 'url' => 'kalender.php'],
            ['key' => 'jadwal',      'ikon' => 'dokumen', 'label' => 'Jadwal & Pengingat', 'url' => 'jadwal.php'],
            ['key' => 'cost-avoidance', 'ikon' => 'buku-besar', 'label' => 'Cost Avoidance', 'url' => 'cost_avoidance.php'],

            ['grup' => 'Data Sosial'],
            ['key' => 'srm',         'ikon' => 'pengguna', 'label' => 'SRM', 'url' => 'srm.php'],
            ['key' => 'cid',         'ikon' => 'koin', 'label' => 'CID', 'url' => 'cid.php'],
            ['key' => 'peta',        'ikon' => 'peta', 'label' => 'Peta Lokasi', 'url' => 'peta.php'],

            ['grup' => 'Lainnya'],
            ['key' => 'kasus',       'ikon' => 'folder', 'label' => 'Pelacakan Kasus', 'url' => 'dashboard_kasus.php'],
            ['key' => 'notifikasi',  'ikon' => 'lonceng', 'label' => 'Notifikasi',   'url' => 'notifikasi.php', 'badge' => 'notifikasi'],
            ['key' => 'asisten',     'ikon' => 'robot', 'label' => 'Asisten AI',    'url' => 'asisten.php'],
            ['key' => 'profil',      'ikon' => 'pengguna', 'label' => 'Akun & Keamanan', 'url' => 'profil.php'],
        ],
        'mentor' => [
            ['grup' => 'Utama'],
            ['key' => 'dashboard',   'ikon' => 'grafik', 'label' => 'Dashboard',    'url' => 'mentor_dashboard.php'],
            ['key' => 'rekap',       'ikon' => 'dokumen', 'label' => 'Rekap Laporan', 'url' => 'rekap_laporan.php'],
            ['key' => 'rekap_absensi', 'ikon' => 'grafik', 'label' => 'Rekap Absensi', 'url' => 'rekap_absensi.php'],
            ['key' => 'absensi_mentor', 'ikon' => 'kalender', 'label' => 'Absensi Karyawan', 'url' => 'mentor_absensi.php'],
            ['key' => 'jadwal',      'ikon' => 'dokumen', 'label' => 'Jadwal & Pengingat', 'url' => 'jadwal.php'],

            ['grup' => 'Analitik'],
            ['key' => 'ringkasan',   'ikon' => 'kegiatan', 'label' => 'Ringkasan',    'url' => 'ringkasan.php'],
            ['key' => 'kalender',    'ikon' => 'kalender', 'label' => 'Kalender Aktivitas', 'url' => 'kalender.php'],
            ['key' => 'distribusi',  'ikon' => 'unggah', 'label' => 'Pengiriman Laporan', 'url' => 'distribusi_laporan.php'],
            ['key' => 'impor',       'ikon' => 'unggah',  'label' => 'Impor Data',   'url' => 'impor_laporan.php'],
            ['key' => 'dataset',     'ikon' => 'grafik', 'label' => 'Dataset',      'url' => 'analisis_dataset.php'],
            ['key' => 'cost-avoidance', 'ikon' => 'buku-besar', 'label' => 'Cost Avoidance', 'url' => 'cost_avoidance.php'],

            ['grup' => 'Data Sosial'],
            ['key' => 'srm',         'ikon' => 'pengguna', 'label' => 'SRM', 'url' => 'srm.php'],
            ['key' => 'cid',         'ikon' => 'koin', 'label' => 'CID', 'url' => 'cid.php'],
            ['key' => 'peta',        'ikon' => 'peta', 'label' => 'Peta Lokasi', 'url' => 'peta.php'],

            ['grup' => 'Lainnya'],
            ['key' => 'kasus',       'ikon' => 'folder', 'label' => 'Pelacakan Kasus', 'url' => 'dashboard_kasus.php'],
            ['key' => 'notifikasi',  'ikon' => 'lonceng', 'label' => 'Notifikasi',   'url' => 'notifikasi.php', 'badge' => 'notifikasi'],
            ['key' => 'asisten',     'ikon' => 'robot', 'label' => 'Asisten AI',    'url' => 'asisten.php'],
            ['key' => 'profil',      'ikon' => 'pengguna', 'label' => 'Akun & Keamanan', 'url' => 'profil.php'],
        ],
        'admin' => [
            ['grup' => 'Utama'],
            ['key' => 'dashboard',   'ikon' => 'grafik', 'label' => 'Dashboard Admin', 'url' => 'admin_dashboard.php'],
            ['key' => 'periksa',     'ikon' => 'dokumen', 'label' => 'Periksa Laporan', 'url' => 'mentor_dashboard.php'],
            ['key' => 'rekap',       'ikon' => 'dokumen', 'label' => 'Rekap Laporan', 'url' => 'rekap_laporan.php'],
            ['key' => 'rekap_absensi', 'ikon' => 'grafik', 'label' => 'Rekap Absensi', 'url' => 'rekap_absensi.php'],
            ['key' => 'absensi_mentor', 'ikon' => 'kalender', 'label' => 'Absensi Karyawan', 'url' => 'mentor_absensi.php'],
            ['key' => 'jadwal',      'ikon' => 'dokumen', 'label' => 'Jadwal & Pengingat', 'url' => 'jadwal.php'],

            ['grup' => 'Analitik'],
            ['key' => 'ringkasan',   'ikon' => 'kegiatan', 'label' => 'Ringkasan',    'url' => 'ringkasan.php'],
            ['key' => 'kalender',    'ikon' => 'kalender', 'label' => 'Kalender Aktivitas', 'url' => 'kalender.php'],
            ['key' => 'distribusi',  'ikon' => 'unggah', 'label' => 'Pengiriman Laporan', 'url' => 'distribusi_laporan.php'],
            ['key' => 'impor',       'ikon' => 'unggah',  'label' => 'Impor Data',   'url' => 'impor_laporan.php'],
            ['key' => 'dataset',     'ikon' => 'grafik', 'label' => 'Dataset',      'url' => 'analisis_dataset.php'],
            ['key' => 'cost-avoidance', 'ikon' => 'buku-besar', 'label' => 'Cost Avoidance', 'url' => 'cost_avoidance.php'],

            ['grup' => 'Data Sosial'],
            ['key' => 'srm',         'ikon' => 'pengguna', 'label' => 'SRM', 'url' => 'srm.php'],
            ['key' => 'cid',         'ikon' => 'koin', 'label' => 'CID', 'url' => 'cid.php'],
            ['key' => 'peta',        'ikon' => 'peta', 'label' => 'Peta Lokasi', 'url' => 'peta.php'],

            ['grup' => 'Administrasi'],
            ['key' => 'pengguna',    'ikon' => 'grup', 'label' => 'Kelola Pengguna', 'url' => 'pengguna.php'],
            ['key' => 'kasus',       'ikon' => 'folder', 'label' => 'Pelacakan Kasus', 'url' => 'dashboard_kasus.php'],
            ['key' => 'audit',       'ikon' => 'perisai', 'label' => 'Riwayat Perubahan', 'url' => 'riwayat_audit.php'],
            ['key' => 'backup',      'ikon' => 'database', 'label' => 'Cadangan Data', 'url' => 'backup.php'],
            ['key' => 'impor_srmcid', 'ikon' => 'folder', 'label' => 'Impor SRM & CID', 'url' => 'impor_srm_cid.php'],
            ['key' => 'powerbi',     'ikon' => 'grafik', 'label' => 'Ekspor Dataset', 'url' => 'powerbi_ekspor.php'],
            ['key' => 'status',      'ikon' => 'alat', 'label' => 'Status Sistem', 'url' => 'status.php'],
            ['key' => 'pengingat',   'ikon' => 'jam',   'label' => 'Pengingat Harian', 'url' => 'pengingat.php'],
            ['key' => 'notifikasi',  'ikon' => 'lonceng', 'label' => 'Notifikasi',   'url' => 'notifikasi.php', 'badge' => 'notifikasi'],
            ['key' => 'asisten',     'ikon' => 'robot', 'label' => 'Asisten AI',    'url' => 'asisten.php'],
            ['key' => 'profil',      'ikon' => 'pengguna', 'label' => 'Akun & Keamanan', 'url' => 'profil.php'],
        ],
    ];
}

/** Halaman default untuk peran (dipakai pengalihan setelah masuk). */
function halaman_default_peran(string $role): string
{
    return match ($role) {
        'mentor' => 'mentor_dashboard.php',
        'admin'  => 'admin_dashboard.php',
        default  => 'dashboard.php',
    };
}

/** Deteksi kunci menu aktif dari skrip yang sedang berjalan. */
function menu_aktif_otomatis(): string
{
    $skrip = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));

    return match ($skrip) {
        'index.php'                             => 'dashboard',
        'dashboard.php'                         => 'dashboard',
        'admin_dashboard.php'                   => 'dashboard',
        'mentor_dashboard.php'                  => (sesi_role() === 'admin' ? 'periksa' : 'dashboard'),
        'buat_laporan.php'                      => 'buat',
        'riwayat.php', 'detail_laporan.php',
        'edit_revisi.php'                       => 'riwayat',
        'absensi.php'                           => 'absensi',
        'mentor_absensi.php', 'rekap_absensi.php' => in_array($skrip, ['rekap_absensi.php'], true)
                                                    ? 'rekap_absensi' : 'absensi_mentor',
        'rekap_laporan.php'                     => 'rekap',
        'mentor_detail.php', 'mentor_proses.php'=> 'rekap',
        'dashboard_kasus.php', 'detail_kasus.php',
        'buat_kasus.php', 'edit_kasus.php', 'hapus_kasus.php',
        'edit_riwayat.php', 'hapus_riwayat.php' => 'kasus',
        'notifikasi.php'                        => 'notifikasi',
        'ringkasan.php'                         => 'ringkasan',
        'kalender.php'                          => 'kalender',
        'jadwal.php'                            => 'jadwal',
        'cost_avoidance.php'                    => 'cost-avoidance',
        'srm.php'                               => 'srm',
        'cid.php'                               => 'cid',
        'peta.php'                              => 'peta',
        'impor_srm_cid.php'                     => 'impor_srmcid',
        'impor_laporan.php'                     => 'impor',
        'impor_dataset.php', 'analisis_dataset.php' => 'dataset',
        'powerbi_ekspor.php'                    => 'powerbi',
        'distribusi_laporan.php'                => 'distribusi',
        'pengingat.php'                         => 'pengingat',
        'asisten.php'                           => 'asisten',
        'profil.php', 'dua_faktor.php'          => 'profil',
        'riwayat_audit.php'                     => 'audit',
        'pengguna.php'                          => 'pengguna',
        'backup.php'                            => 'backup',
        'status.php'                            => 'status',
        default                                 => 'dashboard',
    };
}

/** Jumlah notifikasi belum dibaca (0 bila tabel kosong/gagal). */
function jumlah_notifikasi(mysqli $conn, int $userId): int
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM notifikasi WHERE user_id = ? AND sudah_dibaca = 0'
        );
        if (!$stmt) {
            return $cache = 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($n);
        $stmt->fetch();
        $stmt->close();
        return $cache = (int) $n;
    } catch (Throwable $t) {
        return $cache = 0;
    }
}

/** Ikon flash berdasarkan jenis pesan. */
function ikon_flash(string $jenis): string
{
    return match ($jenis) {
        'sukses' => '&#10004;',
        'gagal'  => '&#10006;',
        default  => '&#8505;',
    };
}

/* ---------------------------------------------------------------
 * Mulai halaman
 * ------------------------------------------------------------- */

function mulai_halaman(array $opsi = []): void
{
    global $conn;

    $opsi += [
        'judul'   => APP_SINGKAT,
        'crumb'   => '',
        'menu'    => null,          // null = otomatis
        'aksi'    => '',            // HTML tombol di ujung kanan topbar
        'ringkas' => false,         // true = tanpa sidebar (halaman lebar)
        'aktif'   => null,          // menimpa deteksi otomatis
        'deskripsi' => '',
        'head'    => '',            // markup tambahan di dalam <head>
    ];

    $judul   = (string) $opsi['judul'];
    $aktif   = $opsi['aktif'] ?? menu_aktif_otomatis();
    $role    = sesi_role();
    $nama    = sesi_nama();
    $menus   = menu_tersedia();
    $menu    = $menus[$role] ?? $menus['karyawan'];

    // Kelompokkan menu agar sidebar dapat dilipat per grup.
    $grupMenu      = [];
    $namaGrupAktif = '';
    foreach ($menu as $item) {
        if (isset($item['grup'])) {
            $namaGrupAktif = (string) $item['grup'];
            if (!isset($grupMenu[$namaGrupAktif])) {
                $grupMenu[$namaGrupAktif] = [];
            }
            continue;
        }
        if (!isset($grupMenu[$namaGrupAktif])) {
            $grupMenu[$namaGrupAktif] = [];
        }
        $grupMenu[$namaGrupAktif][] = $item;
    }

    $unread = 0;
    $uid = sesi_user_id();
    if ($uid !== null && isset($conn) && $conn instanceof mysqli) {
        $unread = jumlah_notifikasi($conn, $uid);
    }

    $flash = flash_ambil();

    // Judul untuk tab & topbar
    $judulPenuh = $judul . ' - ' . APP_SINGKAT;

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token('global')) ?>">
    <title><?= e($judulPenuh) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
    <!-- Tema gelap dimuat di <head> supaya preferensi tersimpan langsung
         diterapkan sebelum halaman digambar (tanpa kedip), dan tetap
         bekerja walau isi halaman berhenti di tengah jalan. -->
    <script src="assets/js/toggle.js"></script>
    <?= $opsi['head'] ?>
</head>
<body>
<a class="lewati-ke-konten" href="#konten-utama">Lewati ke konten utama</a>
<?php if ($opsi['ringkas']): ?>
    <?php if (is_array($flash)): ?>
        <div style="max-width:960px;margin:16px auto 0;padding:0 16px">
            <div class="alert alert-<?= $flash['jenis'] === 'sukses' ? 'sukses'
                : ($flash['jenis'] === 'gagal' ? 'gagal' : 'info') ?>">
                <span class="ikon"><?= ikon_flash((string) $flash['jenis']) ?></span>
                <span><?= $flash['pesan'] ?></span>
            </div>
        </div>
    <?php endif; ?>
    <div class="isi" style="max-width:960px">
<?php else: ?>
<div class="app">
    <!-- ================= SIDEBAR ================= -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <?= logo_phr() ?>
            <span class="brand-teks">
                <span class="nama"><?= e(APP_SINGKAT) ?></span><br>
                <span class="sub"><?= e(APP_ORGANISASI) ?></span>
            </span>
            <button class="tombol-mini" type="button" id="tombolMini"
                    aria-pressed="false" aria-label="Perkecil atau perbesar menu"
                    title="Perkecil / perbesar menu">&#9776;</button>
        </div>

        <nav class="sidebar-nav" aria-label="Navigasi utama">
            <div class="pencarian-menu">
                <input type="search" id="cariMenu" placeholder="Cari menu..."
                       aria-label="Cari menu" autocomplete="off">
            </div>

            <?php foreach ($grupMenu as $namaGrup => $itemsGrup): ?>
                <div class="nav-grup" data-grup="<?= e((string) $namaGrup) ?>">
                    <?php if ((string) $namaGrup !== ''): ?>
                        <button class="nav-judul" type="button" aria-expanded="true">
                            <?= e((string) $namaGrup) ?>
                        </button>
                    <?php endif; ?>

                    <?php foreach ($itemsGrup as $item): ?>
                        <?php
                        $aktifItem = ($aktif === $item['key']);
                        $badge = '';
                        if (($item['badge'] ?? '') === 'notifikasi' && $unread > 0) {
                            $badge = '<span class="nav-badge">' . ($unread > 99 ? '99+' : $unread) . '</span>';
                        }
                        ?>
                        <a class="nav-item<?= $aktifItem ? ' aktif' : '' ?>"
                           href="<?= e($item['url']) ?>"
                           title="<?= e($item['label']) ?>"
                           <?= $aktifItem ? 'aria-current="page"' : '' ?>>
                            <span class="ikon"><?= ikon_sp((string) $item['ikon']) ?></span>
                            <span class="label"><?= e($item['label']) ?></span>
                            <?= $badge ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <?= e(APP_VERSI) ?> &middot; <?= e(APP_ORGANISASI) ?>
        </div>
    </aside>

    <!-- ================= AREA UTAMA ================= -->
    <div class="area-utama">
        <header class="topbar">
            <button class="tombol-burger" type="button" id="tombolBurger"
                    aria-label="Buka menu">&#9776;</button>

            <h1 class="judul-halaman">
                <?php if ($opsi['crumb'] !== ''): ?>
                    <span class="crumb"><?= e($opsi['crumb']) ?> &middot; </span>
                <?php endif; ?>
                <?= e($judul) ?>
            </h1>

            <div class="topbar-kanan">
                <?= $opsi['aksi'] ?>

                <button type="button" id="tombolTema" class="tombol-lonceng" aria-label="Ganti tema" title="Ganti tema terang/gelap" style="border:0;background:transparent;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:6px;">🌙</button>

                <a class="tombol-lonceng" href="notifikasi.php"
                   title="Notifikasi<?= $unread > 0 ? ' (' . $unread . ' baru)' : '' ?>">
                    <?= ikon_sp('lonceng') ?>
                    <?php if ($unread > 0): ?>
                        <span class="titik"><?= $unread > 99 ? '99+' : $unread ?></span>
                    <?php endif; ?>
                </a>

                <div class="dropdown">
                    <button class="dropdown-tombol" type="button" data-dropdown="menu-user" aria-label="Menu pengguna">
                        <?= avatar_tampil($nama, $role, sesi_foto(), 'avatar avatar-kecil') ?>
                        <span class="sembunyi-mobile"><?= e(potong($nama, 18)) ?></span>
                        <span aria-hidden="true">&#9662;</span>
                    </button>
                    <div class="dropdown-menu" id="menu-user">
                        <div class="label-menu">
                            <?= e($nama) ?><br>
                            <span style="text-transform:none;letter-spacing:0;font-weight:500">
                                <?= e(ucfirst($role)) ?>
                            </span>
                        </div>
                        <div class="pisah"></div>
                        <a href="profil.php"><?= ikon_sp('pengguna') ?> Akun &amp; Keamanan</a>
                        <a href="asisten.php"><?= ikon_sp('robot') ?> Asisten AI</a>
                        <?php if ($role === 'karyawan'): ?>
                            <a href="riwayat.php"><?= ikon_sp('jam') ?> Riwayat Laporan</a>
                        <?php endif; ?>
                        <div class="pisah"></div>
                        <a href="logout.php" class="bahaya"><?= ikon_sp('keluar') ?> Keluar</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="isi" id="konten-utama">
            <?php if (is_array($flash)): ?>
                <div class="alert alert-<?= $flash['jenis'] === 'sukses' ? 'sukses'
                    : ($flash['jenis'] === 'gagal' ? 'gagal' : 'info') ?>">
                    <span class="ikon"><?= ikon_flash((string) $flash['jenis']) ?></span>
                    <span><?= $flash['pesan'] ?></span>
                </div>
            <?php endif; ?>
<?php endif; ?>
    <?php
}

/* ---------------------------------------------------------------
 * Akhiri halaman
 * ------------------------------------------------------------- */

function akhiri_halaman(array $opsi = []): void
{
    $opsi += ['ringkas' => false, 'chat' => true];

    if (!$opsi['ringkas']) {
        echo '        </main>';
        echo "\n    </div>\n</div>\n";
    } else {
        echo '        </div>';
        echo "\n";
    }

    if ($opsi['chat'] && defined('AI_AKTIF') && AI_AKTIF && sudah_masuk()) {
        echo render_panel_chat();
    }
    ?>
<script src="assets/js/app.js" defer></script>
<?php if (!$opsi['ringkas']): ?>
<script>
    // Buka/tutup sidebar pada layar kecil
    (function () {
        var b = document.getElementById('tombolBurger');
        var s = document.getElementById('sidebar');
        if (b && s) {
            b.addEventListener('click', function () { s.classList.toggle('tampil'); });
            document.addEventListener('click', function (e) {
                if (!s.contains(e.target) && !b.contains(e.target)) s.classList.remove('tampil');
            });
        }
    })();
</script>
<?php endif; ?>
</body>
</html>
    <?php
}

/* ---------------------------------------------------------------
 * Panel chatbot (disuntikkan ke semua halaman terautentikasi)
 * ------------------------------------------------------------- */

function render_panel_chat(): string
{
    $saran = [
        'Ringkas laporan minggu ini',
        'Laporan saya belum lengkap?',
        'Buat narasi laporan bulanan',
        'Statistik kegiatan saya',
    ];

    ob_start();
    ?>
<button class="tombol-chat" type="button" id="tombolChat"
        aria-label="Buka asisten AI" aria-expanded="false"><?= ikon_sp('robot') ?></button>

<section class="panel-chat" id="panelChat" aria-label="Asisten AI" hidden>
    <div class="kepala">
        <span class="avatar avatar-kecil" style="background:rgba(255,255,255,.2)">AI</span>
        <span>
            <span class="judul">Asisten Pelaporan</span><br>
            <span class="status">Terhubung ke Gemini 3.8 &middot; Google Search</span>
        </span>
        <button class="tutup" type="button" id="tutupChat" aria-label="Tutup chat">&times;</button>
    </div>

    <div class="badan" id="badanChat">
        <div class="pesan-chat bot">Halo <strong><?= e(sesi_nama()) ?></strong>!<br>
Saya asisten untuk laporan kegiatan harian. Saya bisa:
&bull; mencari &amp; mengubah catatan kegiatan
&bull; memeriksa kelengkapan laporan
&bull; membuat narasi dan statistik
&bull; merangkum mingguan / bulanan

Apa yang ingin Anda kerjakan?</div>
    </div>

    <div class="saran-chat" id="saranChat">
        <?php foreach ($saran as $s): ?>
            <button type="button" data-saran="<?= e($s) ?>"><?= e($s) ?></button>
        <?php endforeach; ?>
    </div>

    <div class="kaki">
        <textarea id="inputChat" rows="1" placeholder="Tulis pesan..."
                  aria-label="Pesan untuk asisten"></textarea>
        <button class="kirim" type="button" id="kirimChat" aria-label="Kirim"><svg class="sp-ikon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
    </div>
</section>
    <?php
    return (string) ob_get_clean();
}
