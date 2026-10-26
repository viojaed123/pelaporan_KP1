<?php
/**
 * ringkasan.php — Ringkasan mingguan / bulanan.
 *
 *   · Karyawan   : ringkasan kegiatan pribadi.
 *   · Mentor/Admin: ringkasan tim, atau satu karyawan tertentu.
 *
 * Parameter GET : periode=mingguan|bulanan, tanggal=YYYY-MM-DD (titik acuan),
 *                 user=<id> (khusus mentor/admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

global $conn;

$role = sesi_role();
$uid  = (int) sesi_user_id();

/* ---------------------------------------------------------------
 * Rentang periode
 * ------------------------------------------------------------- */
$periode = ((string) ($_GET['periode'] ?? '') === 'bulanan') ? 'bulanan' : 'mingguan';

$acuan = (string) ($_GET['tanggal'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $acuan)) {
    $acuan = date('Y-m-d');
}
$acuanTs = (int) strtotime($acuan);

if ($periode === 'mingguan') {
    $mulai   = date('Y-m-d', (int) strtotime('monday this week', $acuanTs));
    $selesai = date('Y-m-d', (int) strtotime($mulai . ' +6 days'));
    $mulaiLalu   = date('Y-m-d', (int) strtotime($mulai . ' -7 days'));
    $selesaiLalu = date('Y-m-d', (int) strtotime($mulaiLalu . ' +6 days'));
    $labelPeriode = 'Minggu ' . tanggal_id($mulai, 'd M') . ' – ' . tanggal_id($selesai, 'd M Y');
} else {
    $mulai   = date('Y-m-01', $acuanTs);
    $selesai = date('Y-m-t', $acuanTs);
    $mulaiLalu   = date('Y-m-01', (int) strtotime($mulai . ' -1 month'));
    $selesaiLalu = date('Y-m-t', (int) strtotime($mulai . ' -1 month'));
    $labelPeriode = tanggal_id($mulai, 'F Y');
}

/* ---------------------------------------------------------------
 * Sasaran
 * ------------------------------------------------------------- */
$lihatUser = ($role === 'karyawan') ? $uid : null;
$daftarKaryawan = [];

if ($role !== 'karyawan') {
    $resK = $conn->query("SELECT id, nama FROM users WHERE role = 'karyawan' ORDER BY nama ASC");
    if ($resK) {
        while ($row = $resK->fetch_assoc()) {
            $daftarKaryawan[(int) $row['id']] = (string) $row['nama'];
        }
    }
    $mintaUser = (string) ($_GET['user'] ?? '');
    if ($mintaUser !== '' && ctype_digit($mintaUser) && isset($daftarKaryawan[(int) $mintaUser])) {
        $lihatUser = (int) $mintaUser;
    }
}

$namaSasaran = $lihatUser !== null
    ? (string) ($daftarKaryawan[$lihatUser] ?? sesi_nama())
    : 'Seluruh Karyawan';

/* ---------------------------------------------------------------
 * Fungsi bantu ringkasan
 * ------------------------------------------------------------- */
/**
 * Hitung statistik laporan pada rentang tertentu.
 *
 * @return array<string,int|float>
 */
function ringkas_statistik(mysqli $conn, string $mulai, string $selesai, ?int $userId): array
{
    $sql = "SELECT
                COUNT(*) AS jml,
                COUNT(DISTINCT tanggal) AS hari,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE, jam_mulai, jam_selesai)), 0) AS menit,
                SUM(status = 'perlu_revisi') AS revisi,
                SUM(status = 'diperiksa')    AS diperiksa,
                SUM(status = 'dikirim')      AS dikirim,
                SUM(status = 'draft')        AS draft,
                SUM(kendala IS NOT NULL AND TRIM(kendala) <> '') AS kendala
            FROM laporan_harian
            WHERE tanggal BETWEEN ? AND ?";
    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
    }

    $kosong = ['jml' => 0, 'hari' => 0, 'menit' => 0, 'revisi' => 0,
               'diperiksa' => 0, 'dikirim' => 0, 'draft' => 0, 'kendala' => 0];

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $kosong;
    }
    if ($userId !== null) {
        $stmt->bind_param('ssi', $mulai, $selesai, $userId);
    } else {
        $stmt->bind_param('ss', $mulai, $selesai);
    }
    $stmt->execute();
    $res  = $stmt->get_result();
    $row  = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row) {
        return $kosong;
    }
    return [
        'jml'       => (int) $row['jml'],
        'hari'      => (int) $row['hari'],
        'menit'     => (int) $row['menit'],
        'revisi'    => (int) $row['revisi'],
        'diperiksa' => (int) $row['diperiksa'],
        'dikirim'   => (int) $row['dikirim'],
        'draft'     => (int) $row['draft'],
        'kendala'   => (int) $row['kendala'],
    ];
}

$stat       = ringkas_statistik($conn, $mulai, $selesai, $lihatUser);
$statLalu   = ringkas_statistik($conn, $mulaiLalu, $selesaiLalu, $lihatUser);
$selisih    = $stat['jml'] - $statLalu['jml'];
$jam         = $stat['menit'] > 0 ? round($stat['menit'] / 60, 1) : 0;

/* Rincian harian */
$rincian = [];
$stmt = $conn->prepare(
    "SELECT tanggal, COUNT(*) AS jml,
            COALESCE(SUM(TIMESTAMPDIFF(MINUTE, jam_mulai, jam_selesai)), 0) AS menit
       FROM laporan_harian
      WHERE tanggal BETWEEN ? AND ?" . ($lihatUser !== null ? ' AND user_id = ?' : '') . "
      GROUP BY tanggal ORDER BY tanggal ASC"
);
if ($stmt) {
    if ($lihatUser !== null) {
        $stmt->bind_param('ssi', $mulai, $selesai, $lihatUser);
    } else {
        $stmt->bind_param('ss', $mulai, $selesai);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $rincian[] = $r;
    }
    $stmt->close();
}

/* Peringkat karyawan (khusus tim) */
$peringkat = [];
if ($lihatUser === null) {
    $stmt = $conn->prepare(
        "SELECT u.id, u.nama, u.divisi,
                COUNT(l.id) AS jml,
                COUNT(DISTINCT l.tanggal) AS hari
           FROM users u
           LEFT JOIN laporan_harian l
                  ON l.user_id = u.id AND l.tanggal BETWEEN ? AND ?
          WHERE u.role = 'karyawan'
          GROUP BY u.id, u.nama, u.divisi
          ORDER BY jml DESC, u.nama ASC"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $mulai, $selesai);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $peringkat[] = $r;
        }
        $stmt->close();
    }
}

/* Absensi (bila satu orang) */
$absensi = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
if ($lihatUser !== null) {
    $stmt = $conn->prepare(
        'SELECT status, COUNT(*) AS n FROM absensi
          WHERE user_id = ? AND tanggal BETWEEN ? AND ? GROUP BY status'
    );
    if ($stmt) {
        $stmt->bind_param('iss', $lihatUser, $mulai, $selesai);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $absensi[(string) $r['status']] = (int) $r['n'];
        }
        $stmt->close();
    }
}

/* ---------------------------------------------------------------
 * Narasi AI (opsional)
 * ------------------------------------------------------------- */
$narasi = '';
$narasiGalat = '';
$aiSiap = AI_AKTIF && ai_hidup();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'buat_narasi') {
    csrf_cek_atau_matikan('ringkasan_narasi');

    $param = [
        'periode'  => $periode,
        'rentang'  => ['mulai' => $mulai, 'selesai' => $selesai],
        'sasaran'  => $namaSasaran,
        'statistik' => $stat,
        'statistik_sebelumnya' => $statLalu,
        'rincian_harian' => $rincian,
        'absensi'  => $absensi,
    ];

    $hasil = ai_ringkas($periode, $param);

    if ($hasil['ok'] && is_array($hasil['data'])) {
        $d = $hasil['data'];
        $narasi = (string) ($d['balasan'] ?? $d['ringkasan'] ?? $d['narasi'] ?? $d['jawaban'] ?? '');
        if ($narasi === '') {
            $narasiGalat = 'Layanan AI tidak mengembalikan teks narasi.';
        } else {
            catat_audit($conn, 'ringkasan_ai', 'laporan_harian', $lihatUser,
                'Narasi ' . $periode . ' dibuat (' . $mulai . ' s/d ' . $selesai . ')', null, null);
        }
    } else {
        $narasiGalat = (string) ($hasil['galat'] ?? 'Layanan AI tidak tersedia.');
    }
}

/* ---------------------------------------------------------------
 * Kontrol topbar
 * ------------------------------------------------------------- */
$aktifPeriode = ($periode === 'bulanan') ? 'bulanan' : 'mingguan';
$optsKaryawan = '';
foreach ($daftarKaryawan as $kId => $kNama) {
    $sel = ($lihatUser === $kId) ? ' selected' : '';
    $optsKaryawan .= '<option value="' . (int) $kId . '"' . $sel . '>' . e($kNama) . '</option>';
}
$aksiTopbar = '<form class="kalender-filter" method="get" action="ringkasan.php">'
    . '<select name="periode" aria-label="Periode">'
    . '<option value="mingguan"' . ($aktifPeriode === 'mingguan' ? ' selected' : '') . '>Mingguan</option>'
    . '<option value="bulanan"' . ($aktifPeriode === 'bulanan' ? ' selected' : '') . '>Bulanan</option>'
    . '</select>'
    . '<input type="date" name="tanggal" value="' . e($acuan) . '" aria-label="Tanggal acuan">'
    . ($role !== 'karyawan'
        ? '<select name="user" aria-label="Karyawan"><option value="">Seluruh Karyawan</option>' . $optsKaryawan . '</select>'
        : '')
    . '<button class="tombol tombol-kecil" type="submit">Tampilkan</button>'
    . '</form>';

mulai_halaman([
    'judul'  => 'Ringkasan ' . ($periode === 'bulanan' ? 'Bulanan' : 'Mingguan'),
    'crumb'  => APP_SINGKAT,
    'aktif'  => 'ringkasan',
    'aksi'   => $aksiTopbar,
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2><?= e($labelPeriode) ?></h2>
        <div class="aksi"><span class="badge badge-navy badge-tanpa-titik"><?= e($namaSasaran) ?></span></div>
    </div>
    <div class="kartu-badan">
        <div class="kalender-ringkas" style="padding:0">
            <div class="ringkas-butir">
                <span class="angka"><?= (int) $stat['jml'] ?></span>
                <span class="ket">Total laporan</span>
            </div>
            <div class="ringkas-butir">
                <span class="angka"><?= (int) $stat['hari'] ?></span>
                <span class="ket">Hari aktif</span>
            </div>
            <div class="ringkas-butir">
                <span class="angka"><?= e((string) $jam) ?></span>
                <span class="ket">Jam kerja</span>
            </div>
            <div class="ringkas-butir <?= $selisih >= 0 ? 'ringkas-hadir' : 'ringkas-revisi' ?>">
                <span class="angka"><?= $selisih >= 0 ? '+' : '' ?><?= (int) $selisih ?></span>
                <span class="ket">vs periode lalu (<?= (int) $statLalu['jml'] ?>)</span>
            </div>
            <?php if ($stat['revisi'] > 0): ?>
            <div class="ringkas-butir ringkas-revisi">
                <span class="angka"><?= (int) $stat['revisi'] ?></span>
                <span class="ket">Perlu revisi</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="ringkasan-pil">
            <span class="badge badge-hijau badge-tanpa-titik"><?= (int) $stat['diperiksa'] ?> diperiksa</span>
            <span class="badge badge-biru badge-tanpa-titik"><?= (int) $stat['dikirim'] ?> menunggu</span>
            <span class="badge badge-navy badge-tanpa-titik"><?= (int) $stat['draft'] ?> draf</span>
            <span class="badge badge-kuning badge-tanpa-titik"><?= (int) $stat['kendala'] ?> ada kendala</span>
            <?php if ($lihatUser !== null): ?>
                <span class="badge badge-hijau badge-tanpa-titik"><?= (int) $absensi['hadir'] ?> hadir</span>
                <?php if ($absensi['izin'] + $absensi['sakit'] + $absensi['alpa'] > 0): ?>
                    <span class="badge badge-kuning badge-tanpa-titik"><?= (int) ($absensi['izin'] + $absensi['sakit']) ?> izin/sakit</span>
                    <?php if ($absensi['alpa'] > 0): ?>
                        <span class="badge badge-merah badge-tanpa-titik"><?= (int) $absensi['alpa'] ?> alpa</span>
                    <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Narasi otomatis</h2>
        <div class="aksi">
            <?php if ($aiSiap): ?>
                <span class="badge badge-hijau badge-tanpa-titik">AI siap</span>
            <?php else: ?>
                <span class="badge badge-kuning badge-tanpa-titik">AI tidak aktif</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="kartu-badan">
        <?php if ($narasiGalat !== ''): ?>
            <div class="alert alert-gagal"><span class="ikon">✕</span><span><?= e($narasiGalat) ?></span></div>
        <?php endif; ?>

        <?php if ($narasi !== ''): ?>
            <div class="narasi-hasil"><?= nl2br(e($narasi)) ?></div>
        <?php else: ?>
            <p class="text-redup">Buat narasi ringkasan <?= e($periode) ?> secara otomatis dari data di atas.
               Narasi dapat disunting lalu disalin ke laporan cetak.</p>
        <?php endif; ?>

        <form method="post" action="ringkasan.php?periode=<?= e($periode) ?>&tanggal=<?= e($acuan) ?><?= $lihatUser !== null && $role !== 'karyawan' ? '&user=' . (int) $lihatUser : '' ?>" style="margin-top:14px">
            <?= csrf_field('ringkasan_narasi') ?>
            <input type="hidden" name="aksi" value="buat_narasi">
            <button class="tombol tombol-utama" type="submit" <?= $aiSiap ? '' : 'disabled' ?>>
                ✦ Buat narasi <?= e($periode) ?>
            </button>
            <?php if (!$aiSiap): ?>
                <span class="text-redup" style="margin-left:10px;font-size:13px">
                    Layanan AI belum berjalan (lihat README pada folder <span class="text-mono">ai-service</span>).
                </span>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($lihatUser === null && $peringkat !== []): ?>
<div class="kartu">
    <div class="kartu-kepala"><h2>Peringkat kegiatan karyawan</h2></div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr><th>#</th><th>Karyawan</th><th>Divisi</th><th class="text-kanan">Laporan</th><th class="text-kanan">Hari aktif</th></tr>
                </thead>
                <tbody>
                <?php foreach ($peringkat as $i => $p): ?>
                    <tr>
                        <td><?= (int) ($i + 1) ?></td>
                        <td><?= e((string) $p['nama']) ?></td>
                        <td><?= e((string) ($p['divisi'] ?? '-')) ?></td>
                        <td class="text-kanan"><strong><?= (int) $p['jml'] ?></strong></td>
                        <td class="text-kanan"><?= (int) $p['hari'] ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="kartu">
    <div class="kartu-kepala"><h2>Rincian harian</h2></div>
    <div class="kartu-badan padat">
        <?php if ($rincian === []): ?>
            <div class="tabel-kosong">Belum ada laporan pada periode ini.</div>
        <?php else: ?>
            <div class="bungkus-tabel">
                <table class="tabel">
                    <thead>
                        <tr><th>Tanggal</th><th>Hari</th><th class="text-kanan">Laporan</th><th class="text-kanan">Durasi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rincian as $r): ?>
                        <tr>
                            <td><?= e(tanggal_id((string) $r['tanggal'])) ?></td>
                            <td><?= e([
                                1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
                                5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
                            ][(int) date('N', (int) strtotime((string) $r['tanggal']))] ?? '') ?></td>
                            <td class="text-kanan"><?= (int) $r['jml'] ?></td>
                            <td class="text-kanan"><?= (int) $r['menit'] > 0 ? e((string) round(((int) $r['menit']) / 60, 1)) . ' jam' : '—' ?></td>
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
