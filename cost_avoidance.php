<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid   = (int) sesi_user_id();
$role  = (string) sesi_role();
$penuh = cost_avoidance_peran_penuh($role);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);

    if ($aksi === 'simpan') {
        csrf_cek_atau_matikan('cost_avoidance');

        $masukan = [
            'judul'     => (string) ($_POST['judul'] ?? ''),
            'kategori'  => (string) ($_POST['kategori'] ?? ''),
            'periode'   => (string) ($_POST['periode'] ?? ''),
            'estimasi'  => (string) ($_POST['estimasi'] ?? ''),
            'realisasi' => (string) ($_POST['realisasi'] ?? ''),
            'unit'      => (string) ($_POST['unit'] ?? ''),
            'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
        ];

        $v = cost_avoidance_validasi($masukan);
        if (!$v['ok']) {
            $_SESSION['ca_galat'] = $v['galat'];
            $_SESSION['ca_isian'] = $masukan;
            header('Location: cost_avoidance.php' . ($id > 0 ? '?edit=' . $id : '?baru=1'));
            exit;
        }

        if ($id > 0) {
            $lama = cost_avoidance_satu($conn, $id);
            if ($lama === null || !cost_avoidance_boleh_ubah($lama, $uid, $role)) {
                flash_set('gagal', 'Data ini tidak dapat diubah.');
                header('Location: cost_avoidance.php');
                exit;
            }
        }

        $simpan = cost_avoidance_simpan($conn, $v['data'], $uid, $id > 0 ? $id : null);
        if ($simpan['ok']) {
            catat_audit(
                $conn,
                $id > 0 ? 'cost_avoidance_ubah' : 'cost_avoidance_buat',
                'cost_avoidance',
                $simpan['id'],
                ($id > 0 ? 'Ubah' : 'Buat') . ' cost avoidance: ' . $v['data']['judul'],
                null,
                $v['data']
            );
            flash_set('sukses', $id > 0 ? 'Data diperbarui.' : 'Data penghematan dicatat.');
        } else {
            flash_set('gagal', $simpan['galat']);
        }

        header('Location: cost_avoidance.php');
        exit;
    }

    if ($aksi === 'ajukan') {
        csrf_cek_atau_matikan('cost_avoidance');
        $hasil = cost_avoidance_ajukan($conn, $id, $uid, $role);
        if ($hasil['ok']) {
            catat_audit($conn, 'cost_avoidance_ajukan', 'cost_avoidance', $id, 'Ajukan verifikasi', null, null);
        }
        flash_set($hasil['ok'] ? 'sukses' : 'gagal', $hasil['pesan']);
        header('Location: cost_avoidance.php');
        exit;
    }

    if ($aksi === 'verifikasi') {
        csrf_cek_atau_matikan('cost_avoidance');
        $keputusan = (string) ($_POST['keputusan'] ?? '');
        $catatan   = (string) ($_POST['catatan'] ?? '');
        $hasil = cost_avoidance_verifikasi($conn, $id, $uid, $role, $keputusan, $catatan);
        if ($hasil['ok']) {
            catat_audit(
                $conn,
                'cost_avoidance_verifikasi',
                'cost_avoidance',
                $id,
                $keputusan === 'setujui' ? 'Setujui' : 'Tolak',
                null,
                ['keputusan' => $keputusan, 'catatan' => $catatan]
            );
        }
        flash_set($hasil['ok'] ? 'sukses' : 'gagal', $hasil['pesan']);
        header('Location: cost_avoidance.php');
        exit;
    }

    if ($aksi === 'hapus') {
        csrf_cek_atau_matikan('cost_avoidance');
        $row = cost_avoidance_satu($conn, $id);
        if ($row !== null && cost_avoidance_boleh_ubah($row, $uid, $role) && cost_avoidance_hapus($conn, $row)) {
            catat_audit($conn, 'cost_avoidance_hapus', 'cost_avoidance', $id, 'Hapus data', $row, null);
            flash_set('sukses', 'Data dihapus.');
        } else {
            flash_set('gagal', 'Data tidak dapat dihapus.');
        }
        header('Location: cost_avoidance.php');
        exit;
    }
}

$statusSaring = (string) ($_GET['status'] ?? '');
if (!in_array($statusSaring, cost_avoidance_status_daftar(), true)) {
    $statusSaring = '';
}

$kategoriSaring = (string) ($_GET['kategori'] ?? '');
if (!in_array($kategoriSaring, cost_avoidance_kategori_daftar(), true)) {
    $kategoriSaring = '';
}

$bulanSaring = (string) ($_GET['bulan'] ?? '');
if (preg_match('/^\d{4}-\d{2}$/', $bulanSaring) !== 1) {
    $bulanSaring = '';
}

$hal    = max(1, (int) ($_GET['hal'] ?? 1));
$perHal = 25;

$filter = ['status' => $statusSaring, 'kategori' => $kategoriSaring, 'bulan' => $bulanSaring];
if (!$penuh) {
    $filter['pemilik'] = $uid;
}

$hasil   = cost_avoidance_ambil($conn, $filter, $perHal, ($hal - 1) * $perHal);
$baris   = $hasil['baris'];
$total   = $hasil['total'];
$ringkas = cost_avoidance_ringkas($conn, $penuh ? [] : ['pemilik' => $uid]);

$editId = (int) ($_GET['edit'] ?? 0);
$edit   = null;
if ($editId > 0) {
    $kandidat = cost_avoidance_satu($conn, $editId);
    if ($kandidat !== null
        && cost_avoidance_boleh_lihat($kandidat, $uid, $role)
        && cost_avoidance_boleh_ubah($kandidat, $uid, $role)
    ) {
        $edit = $kandidat;
    }
}

$galatForm = (array) ($_SESSION['ca_galat'] ?? []);
$isianSesi = (array) ($_SESSION['ca_isian'] ?? []);
unset($_SESSION['ca_galat'], $_SESSION['ca_isian']);

$tampilForm = $edit !== null || isset($_GET['baru']) || $galatForm !== [];

$isian = [
    'judul'     => '',
    'kategori'  => cost_avoidance_kategori_daftar()[0],
    'periode'   => date('Y-m'),
    'estimasi'  => '',
    'realisasi' => '',
    'unit'      => '',
    'deskripsi' => '',
];

if ($edit !== null) {
    $isian = [
        'judul'     => (string) $edit['judul'],
        'kategori'  => (string) $edit['kategori'],
        'periode'   => substr((string) $edit['periode'], 0, 7),
        'estimasi'  => (string) $edit['estimasi'],
        'realisasi' => (string) $edit['realisasi'],
        'unit'      => (string) ($edit['unit'] ?? ''),
        'deskripsi' => (string) ($edit['deskripsi'] ?? ''),
    ];
}

if ($isianSesi !== []) {
    $isian = array_merge($isian, $isianSesi);
}

$jumlah = $ringkas['jumlah'];
$maksKategori = 0.0;
foreach ($ringkas['per_kategori'] as $k) {
    $maksKategori = max($maksKategori, (float) $k['nilai']);
}

$totalHal = max(1, (int) ceil($total / $perHal));
$tautanHal = static function (int $n) use ($statusSaring, $kategoriSaring, $bulanSaring): string {
    $q = ['hal' => $n];
    if ($statusSaring !== '') {
        $q['status'] = $statusSaring;
    }
    if ($kategoriSaring !== '') {
        $q['kategori'] = $kategoriSaring;
    }
    if ($bulanSaring !== '') {
        $q['bulan'] = $bulanSaring;
    }

    return 'cost_avoidance.php?' . http_build_query($q);
};

mulai_halaman([
    'judul'     => 'Cost Avoidance',
    'crumb'     => 'Cost Avoidance',
    'aktif'     => 'cost-avoidance',
    'deskripsi' => 'Nilai penghematan yang berhasil dihindari.',
]);
?>

<div class="ca-halaman">
    <header class="ca-kepala">
        <div>
            <p class="ca-eyebrow">Cost Avoidance</p>
            <h2 class="ca-judul">Nilai yang berhasil dihindari</h2>
            <p class="ca-ket">Catat estimasi biaya, realisasi yang keluar, lalu ajukan untuk diverifikasi.</p>
        </div>
        <div class="ca-kepala-aksi">
            <a class="ca-tombol ca-tombol-utama" href="cost_avoidance.php?baru=1">Catat penghematan</a>
        </div>
    </header>

    <section class="ca-hero">
        <p class="ca-eyebrow">Total disetujui</p>
        <p class="ca-angka"><?= e(cost_avoidance_rupiah($ringkas['total_disetujui'])) ?></p>
        <p class="ca-konteks">
            <span><?= (int) $jumlah['disetujui'] ?> disetujui</span>
            <span><?= (int) $jumlah['diajukan'] ?> menunggu verifikasi</span>
            <span><?= (int) $jumlah['draft'] ?> draft</span>
            <?php if ((int) $jumlah['ditolak'] > 0): ?>
                <span><?= (int) $jumlah['ditolak'] ?> ditolak</span>
            <?php endif; ?>
        </p>
    </section>

    <div class="ca-panel">
        <div class="ca-panel-kepala">
            <h3>Rincian per kategori</h3>
            <span class="ca-meta">Hanya yang disetujui</span>
        </div>

        <?php if ($ringkas['per_kategori'] === []): ?>
            <p class="ca-kosong">Belum ada nilai yang disetujui.</p>
        <?php else: ?>
            <ul class="ca-rincian">
                <?php foreach ($ringkas['per_kategori'] as $k): ?>
                    <?php $lebar = $maksKategori > 0 ? (int) round(((float) $k['nilai'] / $maksKategori) * 100) : 0; ?>
                    <li class="ca-rincian-baris">
                        <span class="ca-rincian-nama"><?= e((string) $k['nama']) ?></span>
                        <span class="ca-rincian-nilai"><?= e(cost_avoidance_rupiah($k['nilai'])) ?></span>
                        <span class="ca-bar"><span class="ca-bar-isi" style="width: <?= $lebar ?>%"></span></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <?php if ($tampilForm): ?>
        <div class="ca-panel">
            <div class="ca-panel-kepala">
                <h3><?= $edit !== null ? 'Ubah data' : 'Data baru' ?></h3>
                <span class="ca-meta">Nilai avoidance dihitung otomatis</span>
            </div>

            <form class="ca-form" method="post" action="cost_avoidance.php">
                <?= csrf_field('cost_avoidance') ?>
                <input type="hidden" name="aksi" value="simpan">
                <?php if ($edit !== null): ?>
                    <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
                <?php endif; ?>

                <label class="ca-bidang ca-bidang-lebar">
                    <span>Uraian</span>
                    <input type="text" name="judul" maxlength="200" required
                           value="<?= e((string) $isian['judul']) ?>">
                    <?php if (isset($galatForm['judul'])): ?>
                        <small class="ca-galat"><?= e($galatForm['judul']) ?></small>
                    <?php endif; ?>
                </label>

                <label class="ca-bidang">
                    <span>Kategori</span>
                    <select name="kategori" required>
                        <?php foreach (cost_avoidance_kategori_daftar() as $k): ?>
                            <option value="<?= e($k) ?>" <?= $isian['kategori'] === $k ? 'selected' : '' ?>>
                                <?= e($k) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($galatForm['kategori'])): ?>
                        <small class="ca-galat"><?= e($galatForm['kategori']) ?></small>
                    <?php endif; ?>
                </label>

                <label class="ca-bidang">
                    <span>Periode</span>
                    <input type="month" name="periode" required value="<?= e((string) $isian['periode']) ?>">
                    <?php if (isset($galatForm['periode'])): ?>
                        <small class="ca-galat"><?= e($galatForm['periode']) ?></small>
                    <?php endif; ?>
                </label>

                <label class="ca-bidang">
                    <span>Estimasi biaya</span>
                    <input type="text" name="estimasi" inputmode="numeric" required
                           placeholder="1500000" value="<?= e((string) $isian['estimasi']) ?>">
                    <?php if (isset($galatForm['estimasi'])): ?>
                        <small class="ca-galat"><?= e($galatForm['estimasi']) ?></small>
                    <?php endif; ?>
                </label>

                <label class="ca-bidang">
                    <span>Realisasi biaya</span>
                    <input type="text" name="realisasi" inputmode="numeric"
                           placeholder="0" value="<?= e((string) $isian['realisasi']) ?>">
                    <?php if (isset($galatForm['realisasi'])): ?>
                        <small class="ca-galat"><?= e($galatForm['realisasi']) ?></small>
                    <?php endif; ?>
                </label>

                <label class="ca-bidang">
                    <span>Unit</span>
                    <input type="text" name="unit" maxlength="120"
                           placeholder="Divisi atau lokasi" value="<?= e((string) $isian['unit']) ?>">
                </label>

                <label class="ca-bidang ca-bidang-lebar">
                    <span>Keterangan</span>
                    <textarea name="deskripsi" rows="3"><?= e((string) $isian['deskripsi']) ?></textarea>
                </label>

                <div class="ca-form-aksi">
                    <button class="ca-tombol ca-tombol-utama" type="submit">
                        <?= $edit !== null ? 'Simpan perubahan' : 'Simpan sebagai draft' ?>
                    </button>
                    <a class="ca-tombol" href="cost_avoidance.php">Batal</a>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="ca-panel">
        <div class="ca-panel-kepala">
            <h3>Daftar pengajuan</h3>
            <span class="ca-meta"><?= (int) $total ?> entri</span>
        </div>

        <form class="ca-filter" method="get" action="cost_avoidance.php">
            <label>
                <span>Periode</span>
                <input type="month" name="bulan" value="<?= e($bulanSaring) ?>">
            </label>
            <label>
                <span>Status</span>
                <select name="status">
                    <option value="">Semua status</option>
                    <?php foreach (cost_avoidance_status_daftar() as $s): ?>
                        <option value="<?= e($s) ?>" <?= $statusSaring === $s ? 'selected' : '' ?>>
                            <?= e(cost_avoidance_label_status($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Kategori</span>
                <select name="kategori">
                    <option value="">Semua kategori</option>
                    <?php foreach (cost_avoidance_kategori_daftar() as $k): ?>
                        <option value="<?= e($k) ?>" <?= $kategoriSaring === $k ? 'selected' : '' ?>>
                            <?= e($k) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="ca-tombol" type="submit">Terapkan</button>
            <a class="ca-tautan" href="cost_avoidance.php">Reset</a>
        </form>

        <?php if ($baris === []): ?>
            <p class="ca-kosong">Belum ada data pada filter ini.</p>
        <?php else: ?>
            <table class="ca-tabel">
                <thead>
                    <tr>
                        <th>Kode</th>
                        <th>Uraian</th>
                        <th>Periode</th>
                        <th class="ca-kanan">Nilai</th>
                        <th>Status</th>
                        <th class="ca-kanan">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($baris as $r): ?>
                        <?php
                        $bolehUbah = cost_avoidance_boleh_ubah($r, $uid, $role);
                        $bolehVerif = cost_avoidance_boleh_verifikasi($r, $uid, $role);
                        $status = (string) $r['status'];
                        ?>
                        <tr>
                            <td data-label="Kode">
                                <span class="ca-kode"><?= e((string) $r['kode']) ?></span>
                            </td>
                            <td data-label="Uraian">
                                <span class="ca-uraian"><?= e((string) $r['judul']) ?></span>
                                <span class="ca-meta">
                                    <?= e((string) $r['kategori']) ?>
                                    <?php if ((string) ($r['unit'] ?? '') !== ''): ?>
                                        &middot; <?= e((string) $r['unit']) ?>
                                    <?php endif; ?>
                                </span>
                                <?php if ((string) ($r['deskripsi'] ?? '') !== ''): ?>
                                    <span class="ca-desk"><?= e(mb_substr((string) $r['deskripsi'], 0, 140)) ?></span>
                                <?php endif; ?>
                                <?php if ((string) ($r['catatan_verifikasi'] ?? '') !== ''): ?>
                                    <span class="ca-catatan">Catatan: <?= e((string) $r['catatan_verifikasi']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Periode"><?= e(cost_avoidance_periode_label((string) $r['periode'])) ?></td>
                            <td data-label="Nilai" class="ca-nominal">
                                <strong><?= e(cost_avoidance_rupiah($r['nilai_avoidance'])) ?></strong>
                                <span class="ca-meta">
                                    <?= e(cost_avoidance_rupiah($r['estimasi'])) ?>
                                    &rarr;
                                    <?= e(cost_avoidance_rupiah($r['realisasi'])) ?>
                                </span>
                            </td>
                            <td data-label="Status">
                                <?php if ($status === 'disetujui'): ?>
                                    <span class="ca-cap">Disetujui</span>
                                <?php else: ?>
                                    <span class="ca-lencana <?= e(cost_avoidance_kelas_status($status)) ?>">
                                        <?= e(cost_avoidance_label_status($status)) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ((string) ($r['nama_verifikator'] ?? '') !== ''): ?>
                                    <span class="ca-meta">
                                        <?= e((string) $r['nama_verifikator']) ?>
                                        <?php if ((string) ($r['diverifikasi_pada'] ?? '') !== ''): ?>
                                            &middot; <?= e(tanggal_id((string) $r['diverifikasi_pada'], 'd/m/Y')) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" class="ca-kanan">
                                <div class="ca-aksi">
                                    <?php if ($bolehUbah): ?>
                                        <a class="ca-tombol ca-tombol-kecil" href="cost_avoidance.php?edit=<?= (int) $r['id'] ?>">Ubah</a>

                                        <form method="post" action="cost_avoidance.php" onsubmit="return confirm('Ajukan data ini untuk verifikasi?');">
                                            <?= csrf_field('cost_avoidance') ?>
                                            <input type="hidden" name="aksi" value="ajukan">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button class="ca-tombol ca-tombol-kecil ca-tombol-utama" type="submit">Ajukan</button>
                                        </form>

                                        <form method="post" action="cost_avoidance.php" onsubmit="return confirm('Hapus data ini?');">
                                            <?= csrf_field('cost_avoidance') ?>
                                            <input type="hidden" name="aksi" value="hapus">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button class="ca-tombol ca-tombol-kecil ca-tombol-bahaya" type="submit">Hapus</button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($bolehVerif): ?>
                                        <form method="post" action="cost_avoidance.php" onsubmit="return confirm('Setujui data ini?');">
                                            <?= csrf_field('cost_avoidance') ?>
                                            <input type="hidden" name="aksi" value="verifikasi">
                                            <input type="hidden" name="keputusan" value="setujui">
                                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                            <button class="ca-tombol ca-tombol-kecil ca-tombol-utama" type="submit">Setujui</button>
                                        </form>

                                        <details class="ca-tolak">
                                            <summary class="ca-tombol ca-tombol-kecil">Tolak</summary>
                                            <form method="post" action="cost_avoidance.php">
                                                <?= csrf_field('cost_avoidance') ?>
                                                <input type="hidden" name="aksi" value="verifikasi">
                                                <input type="hidden" name="keputusan" value="tolak">
                                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                <input type="text" name="catatan" maxlength="255"
                                                       placeholder="Alasan penolakan" required>
                                                <button class="ca-tombol ca-tombol-kecil ca-tombol-bahaya" type="submit">Kirim</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>

                                    <?php if (!$bolehUbah && !$bolehVerif): ?>
                                        <span class="ca-meta">&mdash;</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalHal > 1): ?>
                <nav class="ca-paginasi" aria-label="Halaman">
                    <span class="ca-meta">
                        Halaman <?= (int) $hal ?> dari <?= (int) $totalHal ?>
                    </span>
                    <span class="ca-paginasi-aksi">
                        <?php if ($hal > 1): ?>
                            <a class="ca-tombol ca-tombol-kecil" href="<?= e($tautanHal($hal - 1)) ?>">Sebelumnya</a>
                        <?php endif; ?>
                        <?php if ($hal < $totalHal): ?>
                            <a class="ca-tombol ca-tombol-kecil" href="<?= e($tautanHal($hal + 1)) ?>">Berikutnya</a>
                        <?php endif; ?>
                    </span>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php akhiri_halaman(); ?>
