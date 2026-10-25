<?php
/**
 * impor_dataset.php — Impor "dataset generik" (kolom apa pun dari berkas).
 *
 * Berbeda dari impor_laporan.php (jenis tetap + pemetaan ke tabel tetap), di
 * sini header berkas LANGSUNG menjadi kolom dataset. Tujuannya: data apa pun
 * yang diimpor bisa langsung divisualkan di analisis_dataset.php tanpa perlu
 * menambah jenis impor + tabel + query grafik baru.
 *
 * Alur (POST + CSRF + pola PRG):
 *   1. Unggah   — pilih berkas, berkas disimpan di backups/impor/.
 *   2. Pratinjau— tinjau profil kolom (kunci/tipe/kosong) lalu simpan.
 *
 * Peran: admin & mentor boleh mengimpor; karyawan tidak (lihat
 * impor_dataset_jenis_untuk_role()).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/impor_dataset.php';

wajib_login();

$uid  = (int) sesi_user_id();
$role = (string) sesi_role();

/**
 * Bersihkan sesi impor dataset + hapus berkas kerja.
 * (impor_reset() milik impor_laporan.php tidak dimuat di sini.)
 */
function dataset_impor_reset(?string $berkas = null): void
{
    if ($berkas !== null && is_file($berkas)) {
        @unlink($berkas);
    }
    unset($_SESSION['dataset_impor']);
}

/* Peran yang boleh memakai fitur ini (admin & mentor). */
$bolehImpor = in_array($role, ['admin', 'mentor'], true);

if (!$bolehImpor) {
    flash_set('gagal', 'Anda tidak berhak mengimpor dataset.');
    header('Location: dashboard.php');
    exit;
}

$aksi = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['aksi'] ?? '') : '');
$galat = '';
$sukses = '';

$sesiKunci = 'dataset_impor';
$sesi = (array) ($_SESSION[$sesiKunci] ?? []);

/* ================================================================
 * Aksi tulis (POST)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- Langkah 1: unggah berkas ---------- */
    if ($aksi === 'unggah') {
        csrf_cek_atau_matikan('dataset_impor');

        $hasil = impor_simpan_tmp($_FILES['berkas'] ?? []);
        if (!$hasil['ok']) {
            flash_set('gagal', $hasil['galat']);
            header('Location: impor_dataset.php');
            exit;
        }

        $_SESSION[$sesiKunci] = [
            'token'      => $hasil['token'],
            'ekstensi'   => $hasil['ekstensi'],
            'nama_asli'  => $hasil['nama_asli'],
            'nama'       => (string) ($_POST['nama'] ?? ''),
        ];

        catat_audit($conn, 'dataset_unggah', 'dataset', null,
            'Unggah berkas dataset: ' . $hasil['nama_asli'], null, null);

        header('Location: impor_dataset.php?langkah=pratinjau');
        exit;
    }

    /* ---------- Langkah 2: simpan dataset ---------- */
    if ($aksi === 'simpan') {
        csrf_cek_atau_matikan('dataset_impor');

        if (empty($sesi['token'])) {
            flash_set('gagal', 'Sesi impor tidak ditemukan. Silakan unggah ulang.');
            header('Location: impor_dataset.php');
            exit;
        }

        $berkas = impor_berkas_dari_token((string) $sesi['token'], (string) $sesi['ekstensi']);
        if ($berkas === null) {
            dataset_impor_reset();
            flash_set('gagal', 'Berkas kerja sudah tidak tersedia. Silakan unggah ulang.');
            header('Location: impor_dataset.php');
            exit;
        }

        $baca = impor_baca($berkas);
        if (!$baca['ok']) {
            dataset_impor_reset($berkas);
            flash_set('gagal', $baca['galat']);
            header('Location: impor_dataset.php');
            exit;
        }

        $nama = trim((string) ($_POST['nama'] ?? ($sesi['nama'] ?? '')));
        if ($nama === '') {
            $nama = pathinfo((string) $sesi['nama_asli'], PATHINFO_FILENAME);
        }

        $simpan = dataset_simpan(
            $conn,
            $nama,
            (array) $baca['header'],
            (array) $baca['baris'],
            $uid,
            (string) $sesi['nama_asli'],
            (string) ($_POST['deskripsi'] ?? '')
        );

        dataset_impor_reset($berkas);

        if (!$simpan['ok']) {
            flash_set('gagal', $simpan['galat']);
            header('Location: impor_dataset.php');
            exit;
        }

        catat_audit($conn, 'dataset_simpan', 'dataset', (int) $simpan['id'],
            'Simpan dataset "' . $nama . '": baris=' . (int) $simpan['jumlah_baris']
            . ' kolom=' . (int) $simpan['jumlah_kolom'], null, null);

        flash_set('sukses', sprintf(
            'Dataset "%s" tersimpan: %d baris, %d kolom. Grafiknya bisa dilihat di Analisis Dataset.',
            $nama,
            (int) $simpan['jumlah_baris'],
            (int) $simpan['jumlah_kolom']
        ));

        header('Location: analisis_dataset.php?id=' . (int) $simpan['id']);
        exit;
    }

    /* ---------- Hapus dataset ---------- */
    if ($aksi === 'hapus') {
        csrf_cek_atau_matikan('dataset_hapus');

        $id = (int) ($_POST['id'] ?? 0);
        $ds = dataset_ambil($conn, $id);

        if ($ds === null) {
            flash_set('gagal', 'Dataset tidak ditemukan.');
            header('Location: impor_dataset.php');
            exit;
        }

        $pemilik = (int) ($ds['user_id'] ?? 0);
        if ($role !== 'admin' && $pemilik !== $uid) {
            flash_set('gagal', 'Anda hanya boleh menghapus dataset milik sendiri.');
            header('Location: impor_dataset.php');
            exit;
        }

        if (dataset_hapus($conn, $id)) {
            catat_audit($conn, 'dataset_hapus', 'dataset', $id,
                'Hapus dataset "' . (string) $ds['nama'] . '"', null, null);
            flash_set('sukses', 'Dataset "' . (string) $ds['nama'] . '" dihapus.');
        } else {
            flash_set('gagal', 'Gagal menghapus dataset.');
        }

        header('Location: impor_dataset.php');
        exit;
    }
}

/* ================================================================
 * Baca (GET)
 * ================================================================ */
$langkah = (string) ($_GET['langkah'] ?? 'unggah');
$sesi = (array) ($_SESSION[$sesiKunci] ?? []);

/* Batalkan sesi impor berjalan. */
if ($langkah === 'batal') {
    $b = null;
    if (!empty($sesi['token'])) {
        $b = impor_berkas_dari_token((string) $sesi['token'], (string) $sesi['ekstensi']);
    }
    dataset_impor_reset($b);
    header('Location: impor_dataset.php');
    exit;
}

$baca = null;
$berkas = null;

if ($langkah === 'pratinjau' && !empty($sesi['token'])) {
    $berkas = impor_berkas_dari_token((string) $sesi['token'], (string) $sesi['ekstensi']);
    if ($berkas !== null) {
        $baca = impor_baca($berkas);
    }
    if ($baca === null || !$baca['ok']) {
        dataset_impor_reset();
        flash_set('gagal', $baca['galat'] ?? 'Berkas kerja sudah tidak tersedia. Silakan unggah ulang.');
        header('Location: impor_dataset.php');
        exit;
    }
}

$profil = ($baca !== null && $baca['ok'])
    ? dataset_profil_kolom((array) $baca['header'], (array) $baca['baris'])
    : [];

$daftar = dataset_daftar($conn);

mulai_halaman([
    'judul' => 'Impor Dataset',
    'crumb' => APP_SINGKAT,
    'aktif' => 'dataset',
]);
?>

<style>
.langkah-baris { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:6px; }
.langkah-butir { display:flex; gap:9px; align-items:center; padding:9px 14px; border-radius:10px;
    background:#fff; border:1px solid var(--abu-200); font-size:13px; font-weight:600; color:#64748b; }
.langkah-butir.aktif { border-color:var(--phr-biru); color:var(--phr-biru-tua); background:var(--biru-50); }
.langkah-butir.selesai { border-color:#BBF7D0; color:#166534; background:#F0FDF4; }
.langkah-butir .nomor { width:22px; height:22px; border-radius:50%; background:var(--abu-200); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; }
.langkah-butir.aktif .nomor { background:var(--phr-biru); }
.langkah-butir.selesai .nomor { background:var(--phr-hijau); }
.pratinjau-bungkus { max-height:420px; overflow:auto; border:1px solid var(--abu-200); border-radius:10px; }
.pratinjau-bungkus table { font-size:12.5px; }
.badge-tipe { display:inline-block; padding:2px 7px; border-radius:6px; font-size:11px; font-weight:600; }
.badge-tipe.teks { background:#EFF6FF; color:#1D4ED8; }
.badge-tipe.angka { background:#F0FDF4; color:#166534; }
.badge-tipe.tanggal { background:#FEF3C7; color:#92400E; }
</style>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Impor Dataset (Kolom Bebas)</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Unggah berkas Excel atau CSV dengan kolom apa pun. Setiap kolom otomatis
            dikenali tipenya (teks/angka/tanggal) dan langsung dibuatkan grafiknya.
            Tidak perlu menambah jenis impor baru.
        </p>
    </div>
    <div class="kartu-badan">
        <div class="langkah-baris">
            <div class="langkah-butir <?= $berkas === null ? 'aktif' : 'selesai' ?>"><span class="nomor">1</span> Unggah berkas</div>
            <div class="langkah-butir <?= $berkas !== null ? 'aktif' : '' ?>"><span class="nomor">2</span> Pratinjau &amp; simpan</div>
        </div>

        <?php if ($berkas === null): ?>
        <!-- ===================== LANGKAH 1 ===================== -->
        <form method="post" action="impor_dataset.php" enctype="multipart/form-data" style="margin-top:14px">
            <?= csrf_field('dataset_impor') ?>
            <input type="hidden" name="aksi" value="unggah">
            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                <label>Nama dataset
                    <input type="text" name="nama" maxlength="150"
                           placeholder="mis. Data Anggaran 2026" value="<?= e((string) ($sesi['nama'] ?? '')) ?>">
                    <span class="text-redup" style="font-size:12px">Kosongkan untuk memakai nama berkas.</span>
                </label>
                <label>Berkas
                    <input type="file" name="berkas" accept=".xlsx,.xls,.csv,.txt" required>
                </label>
            </div>
            <div style="margin-top:16px">
                <button class="tombol tombol-utama" type="submit">Unggah &amp; lanjut</button>
            </div>
        </form>

        <?php else: ?>
        <!-- ===================== LANGKAH 2 ===================== -->
        <form method="post" action="impor_dataset.php" style="margin-top:14px">
            <?= csrf_field('dataset_impor') ?>
            <input type="hidden" name="aksi" value="simpan">

            <div style="display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))">
                <label>Nama dataset
                    <input type="text" name="nama" maxlength="150" required
                           value="<?= e((string) ($sesi['nama'] ?? pathinfo((string) ($sesi['nama_asli'] ?? ''), PATHINFO_FILENAME))) ?>">
                </label>
                <label>Deskripsi (opsional)
                    <input type="text" name="deskripsi" maxlength="255" placeholder="Keterangan singkat">
                </label>
            </div>

            <hr style="margin:20px 0;border:none;border-top:1px solid var(--abu-200)">
            <h3 style="margin:0 0 10px;font-size:15px">Pratinjau struktur</h3>

            <div class="kalender-ringkas" style="padding:0">
                <div class="ringkas-butir"><span class="angka"><?= count((array) $baca['baris']) ?></span><span class="ket">Baris data</span></div>
                <div class="ringkas-butir"><span class="angka"><?= count($profil) ?></span><span class="ket">Kolom</span></div>
                <div class="ringkas-butir"><span class="angka"><?= e((string) count(array_filter($profil, static fn ($k) => $k['tipe'] === 'angka'))) ?></span><span class="ket">Kolom angka</span></div>
                <div class="ringkas-butir"><span class="angka"><?= e((string) count(array_filter($profil, static fn ($k) => $k['tipe'] === 'tanggal'))) ?></span><span class="ket">Kolom tanggal</span></div>
            </div>

            <div class="pratinjau-bungkus" style="margin-top:14px">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th style="width:46px">#</th>
                            <th>Kolom asli</th>
                            <th>Kunci aman</th>
                            <th style="width:110px">Tipe</th>
                            <th style="width:110px">Kosong</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($profil as $i => $k): ?>
                        <tr>
                            <td class="text-redup"><?= $i + 1 ?></td>
                            <td><?= e((string) $k['label']) ?></td>
                            <td><code><?= e((string) $k['kunci']) ?></code></td>
                            <td><span class="badge-tipe <?= e((string) $k['tipe']) ?>"><?= e((string) $k['tipe']) ?></span></td>
                            <td class="text-redup"><?= (int) $k['kosong'] ?> dari <?= count((array) $baca['baris']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3 style="margin:20px 0 10px;font-size:15px">Contoh baris (maks 50)</h3>
            <div class="pratinjau-bungkus">
                <table class="tabel">
                    <thead>
                        <tr>
                            <th style="width:46px">#</th>
                            <?php foreach ($profil as $k): ?>
                                <th><?= e((string) $k['label']) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice((array) $baca['baris'], 0, 50) as $i => $r): ?>
                        <tr>
                            <td class="text-redup"><?= $i + 1 ?></td>
                            <?php foreach ($profil as $j => $k): ?>
                                <td><?= e(trim((string) ($r[$j] ?? '')) !== '' ? (string) $r[$j] : '—') ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
                <button class="tombol tombol-utama" type="submit">Simpan dataset &amp; lihat grafik</button>
                <a class="tombol tombol-garis" href="impor_dataset.php?langkah=batal">Batal</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($berkas === null): ?>
<div class="kartu" style="margin-top:18px">
    <div class="kartu-kepala">
        <h2>Dataset Tersimpan (<?= count($daftar) ?>)</h2>
    </div>
    <div class="kartu-badan padat">
        <?php if ($daftar === []): ?>
            <p class="text-redup" style="padding:18px 4px">Belum ada dataset. Unggah berkas pertama Anda di atas.</p>
        <?php else: ?>
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nama</th>
                        <th>Pemilik</th>
                        <th style="width:90px">Baris</th>
                        <th style="width:90px">Kolom</th>
                        <th style="width:160px">Dibuat</th>
                        <th style="width:180px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $i => $d): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="analisis_dataset.php?id=<?= (int) $d['id'] ?>"><?= e((string) $d['nama']) ?></a>
                            <?php if (!empty($d['nama_berkas'])): ?>
                                <div class="text-redup" style="font-size:12px"><?= e((string) $d['nama_berkas']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-redup"><?= e((string) ($d['pemilik'] ?? '—')) ?></td>
                        <td><?= number_format((int) $d['jumlah_baris'], 0, ',', '.') ?></td>
                        <td><?= (int) $d['jumlah_kolom'] ?></td>
                        <td class="text-redup"><?= e((string) $d['dibuat_pada']) ?></td>
                        <td>
                            <a class="tombol tombol-garis tombol-kecil" href="analisis_dataset.php?id=<?= (int) $d['id'] ?>">Grafik</a>
                            <?php
                            $pemilikId = (int) ($d['user_id'] ?? 0);
                            $bolehHapus = ($role === 'admin' || $pemilikId === $uid);
                            ?>
                            <?php if ($bolehHapus): ?>
                                <form method="post" action="impor_dataset.php" style="display:inline"
                                      onsubmit="return confirm('Hapus dataset ini beserta seluruh barisnya?')">
                                    <?= csrf_field('dataset_hapus') ?>
                                    <input type="hidden" name="aksi" value="hapus">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button class="tombol tombol-garis tombol-kecil" type="submit">Hapus</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
akhiri_halaman();
