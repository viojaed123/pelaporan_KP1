<?php
/**
 * srm.php — Tampilan data Social Relations Management (SRM).
 *
 * Membaca tabel `srm_kegiatan` (hasil impor impor_srm_cid.php) dengan
 * ringkasan, penyaring, dan tabel berhalaman. Hanya-baca.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

/* ---------------- Hapus semua data SRM ---------------- */
$aksi = (string) ($_POST['aksi'] ?? $_GET['aksi'] ?? '');
if ($aksi === 'hapus_semua_srm') {
    csrf_cek_atau_matikan('srm');
    if (!boleh_akses('srm.hapus')) {
        flash_set('gagal', 'Anda tidak berhak menghapus data SRM.');
        header('Location: srm.php');
        exit;
    }
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->query('TRUNCATE TABLE srm_kegiatan');
        catat_audit($conn, 'hapus_srm_semua', null, null, 'Hapus semua data SRM', null, null);
    }
    flash_set('sukses', 'Semua data SRM berhasil dihapus.');
    header('Location: srm.php');
    exit;
}

/* ---------------- Penyaring (GET) ---------------- */
$fTahun  = (string) ($_GET['tahun'] ?? '');
$fArea   = (string) ($_GET['area'] ?? '');
$fStatus = (string) ($_GET['status'] ?? '');
$fKab    = (string) ($_GET['kabupaten'] ?? '');
$fCari   = trim((string) ($_GET['q'] ?? ''));
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
if ($fArea !== '') {
    $where[] = 'area_field = ?';
    $tipe .= 's';
    $param[] = $fArea;
}
if ($fStatus !== '') {
    $where[] = 'status = ?';
    $tipe .= 's';
    $param[] = $fStatus;
}
if ($fKab !== '') {
    $where[] = 'kabupaten_kota = ?';
    $tipe .= 's';
    $param[] = $fKab;
}
if ($fCari !== '') {
    $where[] = '(judul_issue LIKE ? OR detail_issue LIKE ? OR stakeholder_hadir LIKE ? OR pic LIKE ? OR kelurahan LIKE ?)';
    $tipe .= 'sssss';
    $like = '%' . $fCari . '%';
    array_push($param, $like, $like, $like, $like, $like);
}

$klausa = $where === [] ? '' : ('WHERE ' . implode(' AND ', $where));

/* ---------------- Hitung total (untuk halaman) ---------------- */
$total = 0;
$sqlHit = 'SELECT COUNT(*) AS c FROM srm_kegiatan ' . $klausa;
$stmt = $conn->prepare($sqlHit);
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
$sql = 'SELECT id, tahun_intervensi, area_field, tanggal, waktu, pic, kategori_kegiatan,
               klasifikasi_issue, judul_issue, detail_issue, kelurahan, kecamatan, kabupaten_kota,
               jenis_stakeholder, stakeholder_hadir, action_plan, tim_phr, value_creation,
               status, persen_status, dokumentasi
        FROM srm_kegiatan ' . $klausa . '
        ORDER BY tanggal IS NULL, tanggal DESC, id DESC
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
$ringkas = ['total' => 0, 'high' => 0, 'med' => 0, 'low' => 0, 'area' => 0, 'kab' => 0];
$res = $conn->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'High') AS high,
            SUM(status = 'Med')  AS med,
            SUM(status = 'Low')  AS low,
            COUNT(DISTINCT area_field) AS area,
            COUNT(DISTINCT kabupaten_kota) AS kab
       FROM srm_kegiatan"
);
if ($res && ($r = $res->fetch_assoc())) {
    $ringkas = [
        'total' => (int) $r['total'],
        'high'  => (int) $r['high'],
        'med'   => (int) $r['med'],
        'low'   => (int) $r['low'],
        'area'  => (int) $r['area'],
        'kab'   => (int) $r['kab'],
    ];
}

/* ---------------- Daftar penyaring ---------------- */
$daftarTahun = [];
$res = $conn->query('SELECT DISTINCT tahun_intervensi AS v FROM srm_kegiatan WHERE tahun_intervensi IS NOT NULL ORDER BY v DESC');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarTahun[] = (int) $r['v'];
}
$daftarArea = [];
$res = $conn->query('SELECT DISTINCT area_field AS v FROM srm_kegiatan WHERE area_field IS NOT NULL ORDER BY v');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarArea[] = (string) $r['v'];
}
$daftarStatus = [];
$res = $conn->query('SELECT DISTINCT status AS v FROM srm_kegiatan WHERE status IS NOT NULL ORDER BY v');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarStatus[] = (string) $r['v'];
}
$daftarKab = [];
$res = $conn->query('SELECT DISTINCT kabupaten_kota AS v FROM srm_kegiatan WHERE kabupaten_kota IS NOT NULL ORDER BY v');
while ($res && ($r = $res->fetch_assoc())) {
    $daftarKab[] = (string) $r['v'];
}
// Jaring pengaman: buang varian beda besar-kecil/spasi agar tidak dobel.
$daftarKab = wilayah_unik($daftarKab);

/** Bangun URL halaman dengan mempertahankan penyaring. */
function srm_tautan(array $ubah = []): string
{
    $q = array_merge($_GET, $ubah);
    return 'srm.php?' . http_build_query($q);
}

mulai_halaman([
    'judul' => 'SRM',
    'crumb' => 'Data Sosial',
    'aktif' => 'srm',
]);
?>

<style>
.srm-ringkas { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; }
.srm-kotak { background:#fff; border:1px solid var(--abu-200); border-left:4px solid var(--phr-biru); border-radius:10px; padding:12px 14px; }
.srm-kotak .angka { font-size:22px; font-weight:800; color:var(--phr-biru-tua); display:block; line-height:1.15; }
.srm-kotak .ket { font-size:12px; color:#64748b; }
.srm-kotak.merah { border-left-color:var(--phr-merah); }
.srm-kotak.merah .angka { color:var(--phr-merah); }
.srm-kotak.hijau { border-left-color:var(--phr-hijau); }
.srm-kotak.hijau .angka { color:var(--phr-hijau); }
.srm-kotak.kuning { border-left-color:#D97706; }
.srm-kotak.kuning .angka { color:#B45309; }
.srm-detail { max-width:420px; }
.srm-detail summary { cursor:pointer; color:var(--phr-biru); font-weight:600; font-size:12.5px; }
.srm-penyaring { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.srm-penyaring label { font-size:12.5px; font-weight:600; color:#475569; }
.srm-penyaring select, .srm-penyaring input { min-width:150px; }
.aksi-baris { display:flex; gap:8px; justify-content:center; align-items:center; margin-top:16px; flex-wrap:wrap; }
.tautan-lokasi { display:inline-block; margin-top:5px; font-size:12px; font-weight:600; color:var(--phr-biru); text-decoration:none; }
.tautan-lokasi:hover { text-decoration:underline; }
</style>

<div class="kartu">
    <div class="kartu-kepala" style="display:flex;flex-wrap:wrap;gap:10px;justify-content:space-between;align-items:flex-start">
        <div>
            <h2>Social Relations Management (SRM)</h2>
            <p class="text-redup" style="margin:6px 0 0;font-size:13px">
                Catatan kegiatan hubungan sosial &amp; penanganan isu. Data berasal dari impor Excel
                dan tersedia untuk Power BI.
            </p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end">
            <a href="analisis_sosial.php?tab=srm" class="tombol tombol-garis tombol-kecil">Analisis Sosial</a>
            <?php if (boleh_akses('srm.hapus')): ?>
            <form method="post" action="srm.php" onsubmit="return confirm('Hapus SEMUA data SRM? Tindakan ini tidak bisa dibatalkan.');" style="display:inline">
                <?= csrf_field('srm') ?>
                <input type="hidden" name="aksi" value="hapus_semua_srm">
                <button type="submit" class="tombol tombol-bahaya tombol-kecil">Hapus Semua Data SRM</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="kartu-badan">
        <div class="srm-ringkas">
            <div class="srm-kotak"><span class="angka"><?= number_format($ringkas['total'], 0, ',', '.') ?></span><span class="ket">Total kegiatan</span></div>
            <div class="srm-kotak merah"><span class="angka"><?= number_format($ringkas['high'], 0, ',', '.') ?></span><span class="ket">Status High</span></div>
            <div class="srm-kotak kuning"><span class="angka"><?= number_format($ringkas['med'], 0, ',', '.') ?></span><span class="ket">Status Med</span></div>
            <div class="srm-kotak hijau"><span class="angka"><?= number_format($ringkas['low'], 0, ',', '.') ?></span><span class="ket">Status Low</span></div>
            <div class="srm-kotak"><span class="angka"><?= number_format($ringkas['area'], 0, ',', '.') ?></span><span class="ket">Area / Field</span></div>
            <div class="srm-kotak"><span class="angka"><?= number_format($ringkas['kab'], 0, ',', '.') ?></span><span class="ket">Kabupaten / Kota</span></div>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-badan">
        <form class="srm-penyaring" method="get" action="srm.php">
            <label>Tahun
                <select name="tahun">
                    <option value="">Semua</option>
                    <?php foreach ($daftarTahun as $t): ?>
                        <option value="<?= (int) $t ?>" <?= $fTahun === (string) $t ? 'selected' : '' ?>><?= (int) $t ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Area / Field
                <select name="area">
                    <option value="">Semua</option>
                    <?php foreach ($daftarArea as $a): ?>
                        <option value="<?= e($a) ?>" <?= $fArea === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Status
                <select name="status">
                    <option value="">Semua</option>
                    <?php foreach ($daftarStatus as $s): ?>
                        <option value="<?= e($s) ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= e($s) ?></option>
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
                <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="judul, stakeholder, PIC...">
            </label>
            <button class="tombol tombol-utama" type="submit">Terapkan</button>
            <a class="tombol tombol-garis" href="srm.php">Reset</a>
        </form>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2 style="font-size:15px">Daftar Kegiatan
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
                    <th style="width:96px">Tanggal</th>
                    <th style="width:110px">Area</th>
                    <th>Judul Issue</th>
                    <th style="width:150px">Kategori</th>
                    <th style="width:130px">Stakeholder</th>
                    <th style="width:120px">PIC</th>
                    <th style="width:150px" class="text-kanan">Cost Avoidance</th>
                    <th style="width:70px">Status</th>
                    <th style="width:70px" class="text-kanan">%</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($baris === []): ?>
                <tr><td colspan="9" class="text-redup" style="text-align:center;padding:22px">
                    Tidak ada data yang cocok.
                </td></tr>
            <?php else: ?>
                <?php foreach ($baris as $b): ?>
                    <tr>
                        <td class="text-redup"><?= e(tanggal_id($b['tanggal'] !== null ? (string) $b['tanggal'] : null)) ?></td>
                        <td><?= e((string) ($b['area_field'] ?? '-')) ?></td>
                        <td>
                            <div><strong><?= e(potong((string) ($b['judul_issue'] ?? '-'), 120)) ?></strong></div>
                            <div class="text-redup" style="font-size:12px">
                                <?= e((string) ($b['kelurahan'] ?? '-')) ?>,
                                <?= e((string) ($b['kecamatan'] ?? '-')) ?>,
                                <?= e((string) ($b['kabupaten_kota'] ?? '-')) ?>
                            </div>
                            <?php
                            $uPeta = wilayah_peta_url(
                                $b['kelurahan'] !== null ? (string) $b['kelurahan'] : null,
                                [(string) ($b['kecamatan'] ?? ''), (string) ($b['kabupaten_kota'] ?? ''), 'Riau']
                            );
                            ?>
                            <?php if ($uPeta !== ''): ?>
                                <a class="tautan-lokasi" href="<?= e($uPeta) ?>" target="_blank" rel="noopener"
                                   title="Buka lokasi di Google Maps"><?= ikon_sp('peta') ?> Cek Lokasi</a>
                            <?php endif; ?>
                            <?php if (!empty($b['detail_issue']) || !empty($b['action_plan'])): ?>
                                <details class="srm-detail" style="margin-top:4px">
                                    <summary>Rincian</summary>
                                    <?php if (!empty($b['detail_issue'])): ?>
                                        <p style="margin:6px 0 0;font-size:12.5px"><strong>Detail:</strong> <?= nl2br(e((string) $b['detail_issue'])) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['action_plan'])): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Action Plan:</strong> <?= nl2br(e((string) $b['action_plan'])) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['stakeholder_hadir'])): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Hadir:</strong> <?= e((string) $b['stakeholder_hadir']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['value_creation'])): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Value Creation:</strong> <?= e((string) $b['value_creation']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['tim_phr'])): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Tim PHR:</strong> <?= e((string) $b['tim_phr']) ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($b['kategori_kegiatan'])): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Kategori:</strong> <?= e((string) $b['kategori_kegiatan']) ?></p>
                                    <?php endif; ?>
                                    <?php if (isset($b['cost_avoidance']) && $b['cost_avoidance'] !== null && $b['cost_avoidance'] !== ''): ?>
                                        <p style="margin:4px 0 0;font-size:12.5px"><strong>Cost Avoidance:</strong> <?= e(srm_cid_rupiah($b['cost_avoidance'])) ?></p>
                                    <?php endif; ?>
                                </details>
                            <?php endif; ?>
                        </td>
                        <td class="text-redup"><?= e((string) ($b['kategori_kegiatan'] ?? '-')) ?></td>
                        <td class="text-redup"><?= e((string) ($b['jenis_stakeholder'] ?? '-')) ?></td>
                        <td class="text-redup"><?= e((string) ($b['pic'] ?? '-')) ?></td>
                        <td class="text-kanan text-redup"><?= (isset($b['cost_avoidance']) && $b['cost_avoidance'] !== null && $b['cost_avoidance'] !== '') ? e(srm_cid_rupiah($b['cost_avoidance'])) : '-' ?></td>
                        <td>
                            <?php if (!empty($b['status'])): ?>
                                <span class="badge <?= srm_kelas_status((string) $b['status']) ?> badge-tanpa-titik"><?= e((string) $b['status']) ?></span>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="text-kanan text-redup"><?= e(srm_persen($b['persen_status'] !== null ? (string) $b['persen_status'] : null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>

        <?php if ($totalHal > 1): ?>
        <div class="aksi-baris">
            <?php if ($halaman > 1): ?>
                <a class="tombol tombol-garis tombol-kecil" href="<?= e(srm_tautan(['hal' => $halaman - 1])) ?>">&larr; Sebelumnya</a>
            <?php endif; ?>
            <span class="text-redup" style="font-size:13px">Halaman <?= (int) $halaman ?> dari <?= (int) $totalHal ?></span>
            <?php if ($halaman < $totalHal): ?>
                <a class="tombol tombol-garis tombol-kecil" href="<?= e(srm_tautan(['hal' => $halaman + 1])) ?>">Berikutnya &rarr;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
akhiri_halaman();
