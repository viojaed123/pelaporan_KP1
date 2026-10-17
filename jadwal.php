<?php
/**
 * jadwal.php — Agenda & pengingat kegiatan pribadi.
 *
 * Fitur:
 *   · Kalender bulanan berisi jadwal.
 *   · Daftar jadwal bulan terpilih + aksi ubah/hapus/tandai selesai.
 *   · Form tambah/ubah jadwal dengan pengingat (beberapa menit sebelum mulai).
 *
 * Aksi tulis memakai POST + token CSRF per-aksi ('jadwal') + pola PRG.
 * Pengiriman pengingat otomatis dijalankan oleh scripts/pengingat_jadwal.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid = (int) sesi_user_id();

/* ================================================================
 * Aksi tulis
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'simpan') {
        csrf_cek_atau_matikan('jadwal');

        $id = (int) ($_POST['id'] ?? 0);
        $id = $id > 0 ? $id : null;

        // Gabungkan tanggal + jam menjadi satu nilai waktu.
        $tanggal = trim((string) ($_POST['tanggal'] ?? ''));
        $jam = trim((string) ($_POST['jam'] ?? ''));
        $tanggalSelesai = trim((string) ($_POST['tanggal_selesai'] ?? ''));
        $jamSelesai = trim((string) ($_POST['jam_selesai'] ?? ''));

        $masukan = [
            'judul' => (string) ($_POST['judul'] ?? ''),
            'deskripsi' => (string) ($_POST['deskripsi'] ?? ''),
            'mulai' => ($tanggal !== '' && $jam !== '') ? ($tanggal . ' ' . $jam) : '',
            'selesai' => ($jamSelesai !== '')
                ? (($tanggalSelesai !== '' ? $tanggalSelesai : $tanggal) . ' ' . $jamSelesai)
                : '',
            'lokasi' => (string) ($_POST['lokasi'] ?? ''),
            'pengingat_menit' => (int) ($_POST['pengingat_menit'] ?? 30),
            'status' => (string) ($_POST['status'] ?? 'rencana'),
        ];

        $v = jadwal_validasi($masukan);
        if (!$v['ok']) {
            $_SESSION['jadwal_galat'] = $v['galat'];
            $_SESSION['jadwal_isian'] = $masukan;
            header('Location: jadwal.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        $simpan = jadwal_simpan($conn, $v['data'], $uid, $id);
        if ($simpan['ok']) {
            catat_audit($conn, $id ? 'jadwal_ubah' : 'jadwal_buat', 'jadwal', $simpan['id'],
                ($id ? 'Ubah' : 'Buat') . ' jadwal: ' . $v['data']['judul'], null, $v['data']);
            flash_set('sukses', $id ? 'Jadwal diperbarui.' : 'Jadwal ditambahkan.');
        } else {
            flash_set('gagal', $simpan['galat']);
        }

        header('Location: jadwal.php');
        exit;
    }

    if ($aksi === 'hapus') {
        csrf_cek_atau_matikan('jadwal');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && jadwal_hapus($conn, $id, $uid)) {
            catat_audit($conn, 'jadwal_hapus', 'jadwal', $id, 'Hapus jadwal', null, null);
            flash_set('sukses', 'Jadwal dihapus.');
        } else {
            flash_set('gagal', 'Jadwal tidak ditemukan.');
        }
        header('Location: jadwal.php');
        exit;
    }

    if ($aksi === 'status') {
        csrf_cek_atau_matikan('jadwal');
        $id = (int) ($_POST['id'] ?? 0);
        $status = (string) ($_POST['status_baru'] ?? '');
        if ($id > 0 && jadwal_ubah_status($conn, $id, $uid, $status)) {
            catat_audit($conn, 'jadwal_status', 'jadwal', $id, 'Status jadwal -> ' . $status, null, null);
            flash_set('sukses', 'Status jadwal diperbarui.');
        } else {
            flash_set('gagal', 'Gagal memperbarui status.');
        }
        header('Location: jadwal.php');
        exit;
    }
}

/* ================================================================
 * Data tampilan
 * ================================================================ */
$bulan = (string) ($_GET['bulan'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}
$tahun = (int) substr($bulan, 0, 4);
$bln = (int) substr($bulan, 5, 2);

$awal = sprintf('%04d-%02d-01', $tahun, $bln);
$akhir = date('Y-m-t', (int) strtotime($awal));
$sebulanLalu = date('Y-m', (int) strtotime($awal . ' -1 month'));
$sebulanDepan = date('Y-m', (int) strtotime($awal . ' +1 month'));

$statusSaring = (string) ($_GET['status'] ?? '');
$daftar = jadwal_ambil($conn, $uid, $awal, $akhir, ['status' => $statusSaring]);

$perHari = [];
foreach ($daftar as $j) {
    $tgl = substr((string) $j['mulai'], 0, 10);
    $perHari[$tgl][] = $j;
}

// Hitung jadwal mendatang (lintas bulan).
$mendatang = jadwal_mendatang($conn, $uid, 6);

$editId = (int) ($_GET['edit'] ?? 0);
$edit = $editId > 0 ? jadwal_satu($conn, $editId, $uid) : null;

// Isian form (dari sesi bila ada galat).
$galatForm = (array) ($_SESSION['jadwal_galat'] ?? []);
$isian = (array) ($_SESSION['jadwal_isian'] ?? []);
unset($_SESSION['jadwal_galat'], $_SESSION['jadwal_isian']);

if ($edit !== null) {
    $isian = [
        'judul' => (string) $edit['judul'],
        'deskripsi' => (string) ($edit['deskripsi'] ?? ''),
        'lokasi' => (string) ($edit['lokasi'] ?? ''),
        'pengingat_menit' => (int) $edit['pengingat_menit'],
        'status' => (string) $edit['status'],
    ];
    $mulaiEdit = (string) $edit['mulai'];
    $selesaiEdit = (string) ($edit['selesai'] ?? '');
} else {
    $mulaiEdit = (string) ($isian['mulai'] ?? '');
    $selesaiEdit = (string) ($isian['selesai'] ?? '');
}

$tanggalIsi = $mulaiEdit !== '' ? substr($mulaiEdit, 0, 10) : date('Y-m-d');
$jamIsi = $mulaiEdit !== '' ? substr($mulaiEdit, 11, 5) : '08:00';
$tanggalSelesaiIsi = $selesaiEdit !== '' ? substr($selesaiEdit, 0, 10) : '';
$jamSelesaiIsi = $selesaiEdit !== '' ? substr($selesaiEdit, 11, 5) : '';

// Kalender.
$offsetAwal = (int) date('N', (int) strtotime($awal)) - 1; // Senin = 0
$jumlahHari = (int) date('t', (int) strtotime($awal));
$tanggalIni = date('Y-m-d');
$namaHariKolom = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
$namaBulan = jadwal_bulan_id($bln) . ' ' . $tahun;

$aksiTopbar = '<form class="kalender-filter" method="get" action="jadwal.php">'
    . '<input type="month" name="bulan" value="' . e($bulan) . '" aria-label="Bulan">'
    . '<select name="status" aria-label="Status">'
    . '<option value="">Semua status</option>';
foreach (jadwal_status_tersedia() as $s) {
    $aksiTopbar .= '<option value="' . e($s) . '"' . ($statusSaring === $s ? ' selected' : '') . '>'
        . e(jadwal_label_status($s)) . '</option>';
}
$aksiTopbar .= '</select><button class="tombol tombol-kecil" type="submit">Tampilkan</button></form>';

mulai_halaman([
    'judul' => 'Jadwal & Pengingat',
    'crumb' => APP_SINGKAT,
    'aktif' => 'jadwal',
    'aksi'  => $aksiTopbar,
]);
?>

<style>
.jadwal-butir { display:flex; gap:10px; align-items:flex-start; padding:8px 10px; border-radius:8px;
    background:#fff; border:1px solid var(--abu-200); margin-bottom:6px; font-size:12.5px; }
.jadwal-butir .jam { font-weight:700; color:var(--phr-biru-tua); font-variant-numeric:tabular-nums; }
.jadwal-butir.jadwal-selesai { opacity:.75; }
.jadwal-butir.jadwal-batal { opacity:.65; text-decoration:line-through; }
.jadwal-titik { display:inline-block; width:7px; height:7px; border-radius:50%; background:var(--phr-biru); margin-right:4px; }
.jadwal-mendatang-item { display:flex; gap:10px; padding:9px 0; border-bottom:1px solid var(--abu-200); align-items:baseline; }
.jadwal-mendatang-item:last-child { border-bottom:none; }
.jadwal-kapan { min-width:130px; font-weight:600; color:var(--phr-biru-tua); font-size:13px; }
</style>

<div class="kartu">
    <div class="kartu-kepala">
        <h2><?= $edit !== null ? 'Ubah Jadwal' : 'Tambah Jadwal' ?></h2>
    </div>
    <div class="kartu-badan">
        <form method="post" action="jadwal.php">
            <?= csrf_field('jadwal') ?>
            <input type="hidden" name="aksi" value="simpan">
            <?php if ($edit !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px">
                <label style="grid-column:1/-1">Judul <span style="color:var(--phr-merah)">*</span>
                    <input type="text" name="judul" maxlength="200" required
                           value="<?= e((string) ($isian['judul'] ?? '')) ?>">
                    <?php if (isset($galatForm['judul'])): ?><small class="text-redup" style="color:var(--phr-merah)"><?= e($galatForm['judul']) ?></small><?php endif; ?>
                </label>
                <label>Tanggal <span style="color:var(--phr-merah)">*</span>
                    <input type="date" name="tanggal" required value="<?= e($tanggalIsi) ?>">
                    <?php if (isset($galatForm['mulai'])): ?><small class="text-redup" style="color:var(--phr-merah)"><?= e($galatForm['mulai']) ?></small><?php endif; ?>
                </label>
                <label>Jam mulai <span style="color:var(--phr-merah)">*</span>
                    <input type="time" name="jam" required value="<?= e($jamIsi) ?>">
                </label>
                <label>Tanggal selesai (opsional)
                    <input type="date" name="tanggal_selesai" value="<?= e($tanggalSelesaiIsi) ?>">
                </label>
                <label>Jam selesai (opsional)
                    <input type="time" name="jam_selesai" value="<?= e($jamSelesaiIsi) ?>">
                    <?php if (isset($galatForm['selesai'])): ?><small class="text-redup" style="color:var(--phr-merah)"><?= e($galatForm['selesai']) ?></small><?php endif; ?>
                </label>
                <label>Lokasi (opsional)
                    <input type="text" name="lokasi" maxlength="200" value="<?= e((string) ($isian['lokasi'] ?? '')) ?>">
                </label>
                <label>Pengingat
                    <select name="pengingat_menit">
                        <?php
                        $opsiMenit = [0 => 'Tepat saat mulai', 10 => '10 menit sebelum', 15 => '15 menit sebelum',
                            30 => '30 menit sebelum', 60 => '1 jam sebelum', 120 => '2 jam sebelum',
                            1440 => '1 hari sebelum'];
                        $menitTerpilih = (int) ($isian['pengingat_menit'] ?? 30);
                        foreach ($opsiMenit as $m => $label): ?>
                            <option value="<?= (int) $m ?>" <?= $menitTerpilih === $m ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        <?php $stTerpilih = (string) ($isian['status'] ?? 'rencana');
                        foreach (jadwal_status_tersedia() as $s): ?>
                            <option value="<?= e($s) ?>" <?= $stTerpilih === $s ? 'selected' : '' ?>><?= e(jadwal_label_status($s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="grid-column:1/-1">Deskripsi (opsional)
                    <textarea name="deskripsi" rows="3"><?= e((string) ($isian['deskripsi'] ?? '')) ?></textarea>
                </label>
            </div>

            <div style="margin-top:16px;display:flex;gap:10px">
                <button class="tombol tombol-utama" type="submit"><?= $edit !== null ? 'Simpan perubahan' : 'Tambah jadwal' ?></button>
                <?php if ($edit !== null): ?>
                    <a class="tombol tombol-garis" href="jadwal.php">Batal</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="kartu kalender-kartu">
    <div class="kalender-nav">
        <a class="tombol tombol-kecil tombol-garis" href="jadwal.php?bulan=<?= e($sebulanLalu) ?>">&lsaquo; Bulan lalu</a>
        <div class="kalender-judul">
            <strong><?= e($namaBulan) ?></strong>
            <span class="kalender-sasaran"><?= count($daftar) ?> jadwal</span>
        </div>
        <a class="tombol tombol-kecil tombol-garis" href="jadwal.php?bulan=<?= e($sebulanDepan) ?>">Bulan depan &rsaquo;</a>
    </div>
</div>

<div class="kartu">
    <div class="kalender-hari">
        <?php foreach ($namaHariKolom as $nh): ?>
            <div class="kalender-hari-sel"><?= e($nh) ?></div>
        <?php endforeach; ?>
    </div>
    <div class="kalender-grid">
        <?php for ($i = 0; $i < $offsetAwal; $i++): ?>
            <div class="kalender-sel kosong" aria-hidden="true"></div>
        <?php endfor; ?>

        <?php for ($t = 1; $t <= $jumlahHari; $t++): ?>
            <?php
            $tgl = sprintf('%04d-%02d-%02d', $tahun, $bln, $t);
            $dow = (int) date('N', (int) strtotime($tgl));
            $akhirPekan = $dow >= 6;
            $iniHari = ($tgl === $tanggalIni);
            $items = $perHari[$tgl] ?? [];

            $kelas = 'kalender-sel';
            if ($akhirPekan) { $kelas .= ' akhir-pekan'; }
            if ($iniHari) { $kelas .= ' sekarang'; }
            if ($items !== []) { $kelas .= ' ada-laporan'; }
            ?>
            <div class="<?= e($kelas) ?>">
                <div class="kalender-tanggal"><?= (int) $t ?></div>
                <?php foreach (array_slice($items, 0, 3) as $j): ?>
                    <div class="jadwal-butir <?= e(jadwal_kelas_status((string) $j['status'])) ?>"
                         title="<?= e((string) $j['judul']) ?>">
                        <span class="jam"><?= e(substr((string) $j['mulai'], 11, 5)) ?></span>
                        <span><?= e((string) $j['judul']) ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (count($items) > 3): ?>
                    <div class="text-redup" style="font-size:11.5px;padding-left:2px">+<?= count($items) - 3 ?> lagi</div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala"><h2>Daftar jadwal <?= e($namaBulan) ?></h2></div>
    <div class="kartu-badan padat">
        <?php if ($daftar === []): ?>
            <div class="tabel-kosong">Belum ada jadwal pada bulan ini.</div>
        <?php else: ?>
            <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Judul</th>
                        <th>Lokasi</th>
                        <th>Pengingat</th>
                        <th>Status</th>
                        <th class="text-kanan">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $j): ?>
                    <tr>
                        <td>
                            <?= e(date('d/m H:i', (int) strtotime((string) $j['mulai']))) ?>
                            <?php if (!empty($j['selesai'])): ?>
                                <span class="text-redup">&ndash; <?= e(date('d/m H:i', (int) strtotime((string) $j['selesai']))) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) $j['judul']) ?>
                            <?php if (!empty($j['deskripsi'])): ?>
                                <br><span class="text-redup" style="font-size:12px"><?= e(mb_substr((string) $j['deskripsi'], 0, 80)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e((string) ($j['lokasi'] ?? '') !== '' ? (string) $j['lokasi'] : '—') ?>
                            <?php
                            $uJadwal = '';
                            if (wilayah_koordinat_valid($j['garis_lintang'] ?? null, $j['garis_bujur'] ?? null)) {
                                $uJadwal = wilayah_titik_url((float) $j['garis_lintang'], (float) $j['garis_bujur']);
                            } elseif ((string) ($j['lokasi'] ?? '') !== '') {
                                $uJadwal = wilayah_peta_url((string) $j['lokasi'], ['Riau']);
                            }
                            ?>
                            <?php if ($uJadwal !== ''): ?>
                                <br><a class="tautan-lokasi" href="<?= e($uJadwal) ?>" target="_blank"
                                   rel="noopener"><?= ikon_sp('peta') ?> Cek Lokasi</a>
                            <?php endif; ?>
                        </td>
                        <td class="text-redup"><?= (int) $j['pengingat_menit'] ?> menit</td>
                        <td><span class="badge badge-navy badge-tanpa-titik"><?= e(jadwal_label_status((string) $j['status'])) ?></span></td>
                        <td class="text-kanan" style="white-space:nowrap">
                            <a class="tombol tombol-garis tombol-kecil" href="jadwal.php?edit=<?= (int) $j['id'] ?>&amp;bulan=<?= e($bulan) ?>">Ubah</a>
                            <?php if ((string) $j['status'] !== 'selesai'): ?>
                                <form method="post" action="jadwal.php" style="display:inline">
                                    <?= csrf_field('jadwal') ?>
                                    <input type="hidden" name="aksi" value="status">
                                    <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                    <input type="hidden" name="status_baru" value="selesai">
                                    <button class="tombol tombol-garis tombol-kecil" type="submit">Selesai</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="jadwal.php" style="display:inline"
                                  onsubmit="return confirm('Hapus jadwal ini?')">
                                <?= csrf_field('jadwal') ?>
                                <input type="hidden" name="aksi" value="hapus">
                                <input type="hidden" name="id" value="<?= (int) $j['id'] ?>">
                                <button class="tombol tombol-kecil" type="submit"
                                        style="background:#fee2e2;color:#991b1b;border-color:#fecaca">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($mendatang !== []): ?>
<div class="kartu">
    <div class="kartu-kepala"><h2>Jadwal mendatang</h2></div>
    <div class="kartu-badan">
        <?php foreach ($mendatang as $j): ?>
            <div class="jadwal-mendatang-item">
                <span class="jadwal-kapan"><?= e(date('d/m/Y H:i', (int) strtotime((string) $j['mulai']))) ?></span>
                <span>
                    <strong><?= e((string) $j['judul']) ?></strong>
                    <?php if (!empty($j['lokasi'])): ?>
                        <span class="text-redup"> &middot; <?= e((string) $j['lokasi']) ?></span>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
        <p class="text-redup" style="margin-top:12px;font-size:13px">
            Pengingat otomatis dikirim oleh <span class="text-mono">scripts/pengingat_jadwal.php</span>
            (dapat dijadwalkan lewat Task Scheduler setiap beberapa menit).
        </p>
    </div>
</div>
<?php endif; ?>

<?php
akhiri_halaman();
