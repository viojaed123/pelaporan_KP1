<?php
/**
 * kalender.php — Kalender aktivitas bulanan.
 *
 *   · Karyawan  : kalender pribadi (laporan + absensi per hari).
 *   · Mentor/Admin: kalender tim (jumlah laporan & karyawan aktif
 *                   per hari), atau kalender satu karyawan tertentu.
 *
 * Parameter GET: bulan=YYYY-MM, user=<id> (khusus mentor/admin).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

global $conn;

$role = sesi_role();
$uid  = (int) sesi_user_id();

/* ---------------------------------------------------------------
 * Validasi parameter
 * ------------------------------------------------------------- */
$bulan = (string) ($_GET['bulan'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
    $bulan = date('Y-m');
}

$tahun = (int) substr($bulan, 0, 4);
$bln   = (int) substr($bulan, 5, 2);

$awal  = sprintf('%04d-%02d-01', $tahun, $bln);
$akhir = date('Y-m-t', (int) strtotime($awal));

$sebulanLalu = date('Y-m', (int) strtotime($awal . ' -1 month'));
$sebulanDepan = date('Y-m', (int) strtotime($awal . ' +1 month'));

$namaBulan = tanggal_id($awal, 'F Y');
// tanggal_id memakai format PHP; pastikan nama bulan Indonesia.
$namaBulan = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
][$bln] . ' ' . $tahun;

/* ---------------------------------------------------------------
 * Sasaran kalender
 * ------------------------------------------------------------- */
$lihatUser = null;   // null = agregat tim (mentor/admin)
$daftarKaryawan = [];

if ($role === 'karyawan') {
    $lihatUser = $uid;
} else {
    // Daftar karyawan untuk pemilih.
    $resK = $conn->query(
        "SELECT id, nama FROM users WHERE role = 'karyawan' ORDER BY nama ASC"
    );
    if ($resK) {
        while ($row = $resK->fetch_assoc()) {
            $daftarKaryawan[(int) $row['id']] = (string) $row['nama'];
        }
    }

    $mintaUser = (string) ($_GET['user'] ?? '');
    if ($mintaUser !== '' && ctype_digit($mintaUser)) {
        $calon = (int) $mintaUser;
        if (isset($daftarKaryawan[$calon])) {
            $lihatUser = $calon;
        }
    }
}

$namaSasaran = $lihatUser !== null
    ? (string) ($daftarKaryawan[$lihatUser] ?? sesi_nama())
    : 'Seluruh Karyawan';

/* ---------------------------------------------------------------
 * Ambil data
 * ------------------------------------------------------------- */
$perHari      = [];   // tanggal => ['jml'=>, 'org'=>, 'revisi'=>, 'diperiksa'=>]
$absensiHari  = [];   // tanggal => ['status'=>, 'masuk'=>, 'pulang'=>]

if ($lihatUser !== null) {
    $stmt = $conn->prepare(
        "SELECT tanggal,
                COUNT(*) AS jml,
                SUM(status = 'perlu_revisi') AS revisi,
                SUM(status = 'diperiksa')    AS diperiksa,
                SUM(status = 'dikirim')      AS dikirim
           FROM laporan_harian
          WHERE user_id = ? AND tanggal BETWEEN ? AND ?
          GROUP BY tanggal"
    );
    if ($stmt) {
        $stmt->bind_param('iss', $lihatUser, $awal, $akhir);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $perHari[(string) $r['tanggal']] = [
                'jml'       => (int) $r['jml'],
                'org'       => 1,
                'revisi'    => (int) $r['revisi'],
                'diperiksa' => (int) $r['diperiksa'],
                'dikirim'   => (int) $r['dikirim'],
            ];
        }
        $stmt->close();
    }

    $stmt = $conn->prepare(
        'SELECT tanggal, status, jam_masuk, jam_pulang
           FROM absensi
          WHERE user_id = ? AND tanggal BETWEEN ? AND ?'
    );
    if ($stmt) {
        $stmt->bind_param('iss', $lihatUser, $awal, $akhir);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $absensiHari[(string) $r['tanggal']] = [
                'status' => (string) $r['status'],
                'masuk'  => (string) ($r['jam_masuk'] ?? ''),
                'pulang' => (string) ($r['jam_pulang'] ?? ''),
            ];
        }
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare(
        "SELECT tanggal,
                COUNT(*)              AS jml,
                COUNT(DISTINCT user_id) AS org,
                SUM(status = 'perlu_revisi') AS revisi
           FROM laporan_harian
          WHERE tanggal BETWEEN ? AND ?
          GROUP BY tanggal"
    );
    if ($stmt) {
        $stmt->bind_param('ss', $awal, $akhir);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $perHari[(string) $r['tanggal']] = [
                'jml'       => (int) $r['jml'],
                'org'       => (int) $r['org'],
                'revisi'    => (int) $r['revisi'],
                'diperiksa' => 0,
                'dikirim'   => 0,
            ];
        }
        $stmt->close();
    }
}

/* Jumlah karyawan aktif (untuk kalender tim). */
$jumlahKaryawan = 0;
$resJK = $conn->query("SELECT COUNT(*) AS n FROM users WHERE role = 'karyawan'");
if ($resJK && ($rowJK = $resJK->fetch_assoc())) {
    $jumlahKaryawan = (int) $rowJK['n'];
}

/* ---------------------------------------------------------------
 * Ringkasan bulan
 * ------------------------------------------------------------- */
$totalLaporan = 0;
$hariAktif    = 0;
$totalRevisi  = 0;
foreach ($perHari as $d) {
    $totalLaporan += $d['jml'];
    $totalRevisi  += $d['revisi'];
    if ($d['jml'] > 0) {
        $hariAktif++;
    }
}

$totalHadir = 0;
foreach ($absensiHari as $a) {
    if ($a['status'] === 'hadir') {
        $totalHadir++;
    }
}

/* ---------------------------------------------------------------
 * Susun kisi kalender (Senin lebih dulu)
 * ------------------------------------------------------------- */
$hariKe      = (int) date('N', (int) strtotime($awal)); // 1=Senin .. 7=Minggu
$offsetAwal  = $hariKe - 1;
$jumlahHari  = (int) date('t', (int) strtotime($awal));
$tanggalIni  = date('Y-m-d');

$namaHariKolom = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

$aksiTopbar = '';
if ($role !== 'karyawan') {
    $opts = '';
    foreach ($daftarKaryawan as $kId => $kNama) {
        $sel = ($lihatUser === $kId) ? ' selected' : '';
        $opts .= '<option value="' . (int) $kId . '"' . $sel . '>'
            . e($kNama) . '</option>';
    }
    $aksiTopbar = '<form class="kalender-filter" method="get" action="kalender.php">'
        . '<input type="month" name="bulan" value="' . e($bulan) . '" aria-label="Bulan">'
        . '<select name="user" aria-label="Karyawan">'
        . '<option value="">Seluruh Karyawan</option>' . $opts
        . '</select>'
        . '<button class="tombol tombol-kecil" type="submit">Tampilkan</button>'
        . '</form>';
}

mulai_halaman([
    'judul'  => 'Kalender Aktivitas',
    'crumb'  => APP_SINGKAT,
    'aktif'  => 'kalender',
    'aksi'   => $aksiTopbar,
]);
?>

<div class="kartu kalender-kartu">
    <div class="kalender-nav">
        <a class="tombol tombol-kecil tombol-garis"
           href="kalender.php?bulan=<?= e($sebulanLalu) ?><?= $lihatUser !== null && $role !== 'karyawan' ? '&user=' . (int) $lihatUser : '' ?>">‹ Bulan lalu</a>

        <div class="kalender-judul">
            <strong><?= e($namaBulan) ?></strong>
            <span class="kalender-sasaran"><?= e($namaSasaran) ?></span>
        </div>

        <a class="tombol tombol-kecil tombol-garis"
           href="kalender.php?bulan=<?= e($sebulanDepan) ?><?= $lihatUser !== null && $role !== 'karyawan' ? '&user=' . (int) $lihatUser : '' ?>">Bulan depan ›</a>
    </div>

    <div class="kalender-ringkas">
        <div class="ringkas-butir">
            <span class="angka"><?= (int) $totalLaporan ?></span>
            <span class="ket">Total laporan</span>
        </div>
        <div class="ringkas-butir">
            <span class="angka"><?= (int) $hariAktif ?></span>
            <span class="ket">Hari aktif</span>
        </div>
        <?php if ($totalRevisi > 0): ?>
        <div class="ringkas-butir ringkas-revisi">
            <span class="angka"><?= (int) $totalRevisi ?></span>
            <span class="ket">Perlu revisi</span>
        </div>
        <?php endif; ?>
        <?php if ($lihatUser !== null): ?>
        <div class="ringkas-butir ringkas-hadir">
            <span class="angka"><?= (int) $totalHadir ?></span>
            <span class="ket">Hari hadir</span>
        </div>
        <?php else: ?>
        <div class="ringkas-butir">
            <span class="angka"><?= (int) $jumlahKaryawan ?></span>
            <span class="ket">Karyawan</span>
        </div>
        <?php endif; ?>
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
            $tgl     = sprintf('%04d-%02d-%02d', $tahun, $bln, $t);
            $dow     = (int) date('N', (int) strtotime($tgl));
            $akhirPekan = $dow >= 6;
            $iniHari = ($tgl === $tanggalIni);

            $lap = $perHari[$tgl] ?? null;
            $abs = $absensiHari[$tgl] ?? null;

            $kelas = 'kalender-sel';
            if ($akhirPekan) {
                $kelas .= ' akhir-pekan';
            }
            if ($iniHari) {
                $kelas .= ' sekarang';
            }
            if ($lap !== null) {
                $kelas .= ' ada-laporan';
            }
            ?>
            <div class="<?= e($kelas) ?>">
                <div class="kalender-tanggal"><?= (int) $t ?></div>

                <?php if ($lap !== null): ?>
                    <div class="kalender-meta">
                        <?php if ($lihatUser !== null): ?>
                            <span class="badge badge-biru badge-tanpa-titik"><?= (int) $lap['jml'] ?> laporan</span>
                            <?php if ($lap['revisi'] > 0): ?>
                                <span class="badge badge-merah badge-tanpa-titik">revisi</span>
                            <?php elseif ($lap['diperiksa'] > 0): ?>
                                <span class="badge badge-hijau badge-tanpa-titik">diperiksa</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-biru badge-tanpa-titik"><?= (int) $lap['jml'] ?> laporan</span>
                            <span class="badge badge-navy badge-tanpa-titik"><?= (int) $lap['org'] ?>/<?= (int) $jumlahKaryawan ?> org</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($abs !== null): ?>
                    <div class="kalender-absensi kalender-absensi-<?= e($abs['status']) ?>">
                        <?= e(label_status_absensi($abs['status'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endfor; ?>
    </div>

    <div class="kalender-legenda">
        <span><i class="tanda tanda-biru"></i> Ada laporan</span>
        <span><i class="tanda tanda-merah"></i> Perlu revisi</span>
        <span><i class="tanda tanda-hijau"></i> Diperiksa</span>
        <?php if ($lihatUser !== null): ?>
            <span><i class="tanda tanda-hadir"></i> Hadir</span>
            <span><i class="tanda tanda-izin"></i> Izin / sakit</span>
        <?php endif; ?>
    </div>
</div>

<?php
akhiri_halaman();
