<?php
/**
 * cid.php — Tampilan program Community Involvement/Development (CID / TJSL).
 *
 * Membaca tabel `cid_program` (hasil impor impor_srm_cid.php) dengan
 * ringkasan, penyaring, dan tabel berhalaman. Hanya-baca.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

/* ---------------- Hapus semua data CID ---------------- */
$aksi = (string) ($_POST['aksi'] ?? $_GET['aksi'] ?? '');
if ($aksi === 'hapus_semua_cid') {
    csrf_cek_atau_matikan('cid');
    if (!boleh_akses('cid.hapus')) {
        flash_set('gagal', 'Anda tidak berhak menghapus data CID.');
        header('Location: cid.php');
        exit;
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->query('TRUNCATE TABLE cid_program');
        catat_audit($conn, 'hapus_cid_semua', null, null, 'Hapus semua data CID', null, null);
    }
    flash_set('sukses', 'Semua data CID berhasil dihapus.');
    header('Location: cid.php');
    exit;
}

/* ---------------- Penyaring (GET) ---------------- */
$fTahun = (string) ($_GET['tahun'] ?? '');
$fPilar = (string) ($_GET['pilar'] ?? '');
$fKab   = (string) ($_GET['kabupaten'] ?? '');
$fCari  = trim((string) ($_GET['q'] ?? ''));
$halaman = max(1, (int) ($_GET['hal'] ?? 1));
$perHal  = 25;

$where = [];
$tipe  = '';
$param = [];

if ($fTahun !== '' && ctype_digit($fTahun)) {
    $where[] = 'tahun_intervensi = ?';
    $tipe .= 'i';
    $param[] = (int) $fTahun;
}
if ($fPilar !== '') {
    $where[] = 'pilar_program = ?';
    $tipe .= 's';
    $param[] = $fPilar;
}
if ($fKab !== '') {
    $where[] = 'kabupaten_kota = ?';
    $tipe .= 's';
    $param[] = $fKab;
}
if ($fCari !== '') {
    $where[] = '(judul_program LIKE ? OR desa LIKE ? OR kecamatan LIKE ? OR kelompok_diberdayakan LIKE ? OR program LIKE ?)';
    $tipe .= 'sssss';
    $like = '%' . $fCari . '%';
    array_push($param, $like, $like, $like, $like, $like);
}

$klausa = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

/* ---------------- Hitung total ---------------- */
$total = 0;
$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM cid_program ' . $klausa);
if ($stmt) {
    if ($tipe !== '') {
        $stmt->bind_param($tipe, ...$param);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    $total = (int) ($res ? $res->fetch_assoc()['c'] : 0);
    $stmt->close();
}

$totalHal = max(1, (int) ceil($total / $perHal));
$halaman  = min($halaman, $totalHal);
$offset   = ($halaman - 1) * $perHal;

/* ---------------- Ambil baris ---------------- */
$baris = [];
$sql = 'SELECT id, tahun_intervensi, judul_program, pilar_program, desa, kecamatan, kabupaten_kota,
               kelompok_diberdayakan, total_penerima_manfaat, perempuan, laki_laki,
               potensi_peningkatan_ekonomi, potensi_penghematan_operasional, penanaman_pohon,
               luas_penanaman_ha, sampah_terkelola_kg, bulan, bulan_angka, program, anggaran
        FROM cid_program ' . $klausa . '
        ORDER BY tahun_intervensi DESC, bulan_angka IS NULL, bulan_angka, id ASC
        LIMIT ? OFFSET ?';
$stmt = $conn->prepare($sql);
if ($stmt) {
    $tipe2  = $tipe . 'ii';
    $param2 = $param;
    $param2[] = $perHal;
    $param2[] = $offset;
    $stmt->bind_param($tipe2, ...$param2);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $baris[] = $r;
    }
    $stmt->close();
}

/* ---------------- Ringkasan keseluruhan ---------------- */
$ringkas = ['total' => 0, 'anggaran' => 0.0, 'penerima' => 0, 'desa' => 0, 'pilar' => 0, 'kecamatan' => 0];
$res = $conn->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(anggaran), 0) AS anggaran,
            COALESCE(SUM(total_penerima_manfaat), 0) AS penerima,
            COUNT(DISTINCT desa) AS desa,
            COUNT(DISTINCT pilar_program) AS pilar,
            COUNT(DISTINCT kecamatan) AS kecamatan
       FROM cid_program'
);
if ($res && ($r = $res->fetch_assoc())) {
    $ringkas = [
        'total'     => (int) $r['total'],
        'anggaran'  => (float) $r['anggaran'],
        'penerima'  => (int) $r['penerima'],
        'desa'      => (int) $r['desa'],
        'pilar'     => (int) $r['pilar'],
        'kecamatan' => (int) $r['kecamatan'],
    ];
}

/* ---------------- Daftar penyaring ---------------- */
$daftarTahun = [];
$res = $conn->query('SELECT DISTINCT tahun_intervensi AS v FROM cid_program WHERE tahun_intervensi IS NOT NULL ORDER BY v DESC');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarTahun[] = (int) $r['v'];
}
$daftarPilar = [];
$res = $conn->query('SELECT DISTINCT pilar_program AS v FROM cid_program WHERE pilar_program IS NOT NULL ORDER BY v');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarPilar[] = (string) $r['v'];
}
$daftarKab = [];
$res = $conn->query('SELECT DISTINCT kabupaten_kota AS v FROM cid_program WHERE kabupaten_kota IS NOT NULL ORDER BY v');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarKab[] = (string) $r['v'];
}
// Jaring pengaman: buang varian beda besar-kecil/spasi agar tidak dobel.
$daftarKab = wilayah_unik($daftarKab);

$namaBulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

/** Bangun URL halaman dengan mempertahankan penyaring. */
function cid_tautan(array $ubah = []): string
{
    $q = array_merge($_GET, $ubah);
    return 'cid.php?' . http_build_query($q);
}

mulai_halaman([
    'judul' => 'CID',
    'crumb' => 'Data Sosial',
    'aktif' => 'cid',
]);
?>

<style>
.cid-ringkas { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:12px; }
.cid-kotak { background:#fff; border:1px solid var(--abu-200); border-left:4px solid var(--phr-hijau); border-radius:10px; padding:12px 14px; }
.cid-kotak .angka { font-size:21px; font-weight:800; color:var(--phr-biru-tua); display:block; line-height:1.15; }
.cid-kotak .ket { font-size:12px; color:#64748b; }
.cid-kotak.biru { border-left-color:var(--phr-biru); }
.cid-penyaring { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.cid-penyaring label { font-size:12.5px; font-weight:600; color:#475569; }
.cid-penyaring select, .cid-penyaring input { min-width:150px; }
.aksi-baris { display:flex; gap:8px; justify-content:center; align-items:center; margin-top:16px; flex-wrap:wrap; }
.tautan-lokasi { display:inline-block; margin-top:5px; font-size:12px; font-weight:600; color:var(--phr-biru); text-decoration:none; }
.tautan-lokasi:hover { text-decoration:underline; }
</style>

<div class="kartu">
    <div class="kartu-kepala" style="display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:flex-start">
        <div>
            <h2>Community Involvement / Development (CID)</h2>
            <p class="text-redup" style="margin:6px 0 0;font-size:13px">
                Program pemberdayaan masyarakat / TJSL. Data berasal dari impor Excel dan
                tersedia untuk Power BI.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
            <a href="analisis_sosial.php?tab=cid" class="tombol tombol-garis tombol-kecil">Analisis Sosial</a>
            <?php if (boleh_akses('cid.hapus')): ?>
            <form method="post" action="cid.php" onsubmit="return confirm('Hapus SEMUA data CID? Tindakan ini tidak bisa dibatalkan.');" style="display:inline">
                <?= csrf_field('cid') ?>
                <input type="hidden" name="aksi" value="hapus_semua_cid">
                <button type="submit" class="tombol tombol-bahaya tombol-kecil">Hapus Semua Data CID</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="kartu-badan">
        <div class="cid-ringkas">
            <div class="cid-kotak"><span class="angka"><?= number_format($ringkas['total'], 0, ',', '.') ?></span><span class="ket">Total program</span></div>
            <div class="cid-kotak biru"><span class="angka"><?= e(srm_cid_rupiah($ringkas['anggaran'])) ?></span><span class="ket">Total anggaran</span></div>
            <div class="cid-kotak"><span class="angka"><?= number_format($ringkas['penerima'], 0, ',', '.') ?></span><span class="ket">Penerima manfaat</span></div>
            <div class="cid-kotak"><span class="angka"><?= number_format($ringkas['desa'], 0, ',', '.') ?></span><span class="ket">Desa</span></div>
            <div class="cid-kotak"><span class="angka"><?= number_format($ringkas['kecamatan'], 0, ',', '.') ?></span><span class="ket">Kecamatan</span></div>
            <div class="cid-kotak"><span class="angka"><?= number_format($ringkas['pilar'], 0, ',', '.') ?></span><span class="ket">Pilar program</span></div>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-badan">
        <form class="cid-penyaring" method="get" action="cid.php">
            <label>Tahun
                <select name="tahun">
                    <option value="">Semua</option>
                    <?php foreach ($daftarTahun as $t): ?>
                        <option value="<?= (int) $t ?>" <?= $fTahun === (string) $t ? 'selected' : '' ?>><?= (int) $t ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Pilar Program
                <select name="pilar">
                    <option value="">Semua</option>
                    <?php foreach ($daftarPilar as $p): ?>
                        <option value="<?= e($p) ?>" <?= $fPilar === $p ? 'selected' : '' ?>><?= e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Kabupaten / Kota
                <select name="kabupaten">
                    <option value="">Semua</option>
                    <?php foreach ($daftarKab as $k): ?>
                        <option value="<?= e($k) ?>" <?= $fKab === $k ? 'selected' : '' ?>><?= e($k) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="flex:1;min-width:200px">Cari
                <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="judul, desa, kelompok...">
            </label>
            <button class="tombol tombol-utama" type="submit">Terapkan</button>
            <a class="tombol tombol-garis" href="cid.php">Reset</a>
        </form>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Daftar Program
            <span class="text-redup" style="font-weight:500;font-size:13px">
                (<?= number_format($total, 0, ',', '.') ?> baris)
            </span>
        </h2>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
        <table class="tabel">
            <thead>
                <tr>
                    <th style="width:56px">Tahun</th>
                    <th>Judul Program</th>
                    <th style="width:130px">Pilar</th>
                    <th style="width:180px">Desa / Kecamatan</th>
                    <th style="width:110px" class="text-kanan">Penerima</th>
                    <th style="width:130px" class="text-kanan">Anggaran</th>
                    <th style="width:150px" class="text-kanan">Cost Avoidance</th>
                    <th style="width:90px">Bulan</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($baris === []): ?>
                <tr><td colspan="8" class="text-redup" style="text-align:center;padding:22px">
                    Tidak ada data yang cocok.
                </td></tr>
            <?php else: ?>
                <?php foreach ($baris as $b): ?>
                    <tr>
                        <td class="text-redup"><?= e((string) ($b['tahun_intervensi'] ?? '-')) ?></td>
                        <td>
                            <div><strong><?= e(potong((string) ($b['judul_program'] ?? '-'), 120)) ?></strong></div>
                            <?php if (!empty($b['kelompok_diberdayakan'])): ?>
                                <div class="text-redup" style="font-size:12px">
                                    Kelompok: <?= e((string) $b['kelompok_diberdayakan']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-redup"><?= e((string) ($b['pilar_program'] ?? '-')) ?></td>
                        <td class="text-redup">
                            <?= e((string) ($b['desa'] ?? '-')) ?>
                            <div style="font-size:12px"><?= e((string) ($b['kecamatan'] ?? '-')) ?>,
                                <?= e((string) ($b['kabupaten_kota'] ?? '-')) ?>
                            </div>
                            <?php
                            $uPeta = wilayah_peta_url(
                                $b['desa'] !== null ? (string) $b['desa'] : null,
                                [(string) ($b['kecamatan'] ?? ''), (string) ($b['kabupaten_kota'] ?? ''), 'Riau']
                            );
                            ?>
                            <?php if ($uPeta !== ''): ?>
                                <a class="tautan-lokasi" href="<?= e($uPeta) ?>" target="_blank" rel="noopener"
                                   title="Buka lokasi di Google Maps"><?= ikon_sp('peta') ?> Cek Lokasi</a>
                            <?php endif; ?>
                        </td>
                        <td class="text-kanan"><?= e(srm_cid_angka_id($b['total_penerima_manfaat'])) ?></td>
                        <td class="text-kanan"><?= e(srm_cid_rupiah($b['anggaran'])) ?></td>
                        <td class="text-kanan"><?= (isset($b['cost_avoidance']) && $b['cost_avoidance'] !== null && $b['cost_avoidance'] !== '') ? e(srm_cid_rupiah($b['cost_avoidance'])) : '-' ?></td>
                        <td class="text-redup">
                            <?= e($namaBulan[(int) ($b['bulan_angka'] ?? 0)] ?? (string) ($b['bulan'] ?? '-')) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalHal > 1): ?>
        <div class="aksi-baris">
            <?php if ($halaman > 1): ?>
                <a class="tombol tombol-garis tombol-kecil" href="<?= e(cid_tautan(['hal' => $halaman - 1])) ?>">&larr; Sebelumnya</a>
            <?php endif; ?>
            <span class="text-redup" style="font-size:13px">Halaman <?= (int) $halaman ?> dari <?= (int) $totalHal ?></span>
            <?php if ($halaman < $totalHal): ?>
                <a class="tombol tombol-garis tombol-kecil" href="<?= e(cid_tautan(['hal' => $halaman + 1])) ?>">Berikutnya &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
akhiri_halaman();
