<?php
/**
 * dashboard.php - ruang kerja karyawan (halaman rujukan tampilan baru).
 *
 * Ringkasan:
 *   - bootstrap + penjaga peran (karyawan)
 *   - layout terpusat: memakai kelas app.css
 *   - grafik status, progres, revisi, aktivitas
 *   - statistik absensi & kelengkapan laporan
 *   - kartu asisten AI
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
if (sesi_role() !== 'karyawan') {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}

$user_id = (int) sesi_user_id();

/* ---------------------------------------------------------------
 * Ringkasan
 * ------------------------------------------------------------- */

function hitungLaporan(mysqli $conn, int $user_id, ?string $status = null): int
{
    if ($status === null) {
        $stmt = $conn->prepare('SELECT COUNT(*) FROM laporan_harian WHERE user_id = ?');
        $stmt->bind_param('i', $user_id);
    } else {
        $stmt = $conn->prepare(
            'SELECT COUNT(*) FROM laporan_harian WHERE user_id = ? AND status = ?'
        );
        $stmt->bind_param('is', $user_id, $status);
    }
    if (!$stmt) {
        return 0;
    }
    $stmt->execute();
    $stmt->bind_result($jumlah);
    $stmt->fetch();
    $stmt->close();
    return (int) $jumlah;
}

$total_laporan = hitungLaporan($conn, $user_id);
$menunggu      = hitungLaporan($conn, $user_id, 'dikirim');
$diperiksa     = hitungLaporan($conn, $user_id, 'diperiksa');
$perlu_revisi  = hitungLaporan($conn, $user_id, 'perlu_revisi');
$draft         = hitungLaporan($conn, $user_id, 'draft');

$ca_ringkas = cost_avoidance_ringkas($conn, ['pemilik' => $user_id]);
$ca_nilai   = (float) ($ca_ringkas['total_disetujui'] ?? 0);
$ca_jumlah  = (array) ($ca_ringkas['jumlah'] ?? []);

$persentase_progres = $total_laporan > 0
    ? (int) round(($diperiksa / $total_laporan) * 100)
    : 0;
$persentase_progres = max(0, min(100, $persentase_progres));

$jumlah_notifikasi = jumlah_notifikasi($conn, $user_id);

/* Absensi bulan berjalan */
$absensi_bulan = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0];
$absensi_ada   = false;
$awal_bulan    = date('Y-m-01');
try {
    $stmt = $conn->prepare(
        'SELECT status, COUNT(*) AS jumlah FROM absensi
         WHERE user_id = ? AND tanggal >= ? GROUP BY status'
    );
    if ($stmt) {
        $stmt->bind_param('is', $user_id, $awal_bulan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $k = strtolower((string) $b['status']);
            if (array_key_exists($k, $absensi_bulan)) {
                $absensi_bulan[$k] = (int) $b['jumlah'];
            }
            $absensi_ada = true;
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard absensi: ' . $t->getMessage());
}

/* Laporan terakhir */
$aktivitas = [];
$pesan_aktivitas = '';
try {
    $stmt = $conn->prepare(
        'SELECT id, tanggal, kegiatan, status, updated_at
         FROM laporan_harian WHERE user_id = ?
         ORDER BY tanggal DESC, id DESC LIMIT 5'
    );
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $aktivitas[] = $row;
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Dashboard aktivitas: ' . $e->getMessage());
    $pesan_aktivitas = 'Aktivitas belum dapat ditampilkan.';
}

$dataGrafik = [
    'Draft'        => $draft,
    'Menunggu'     => $menunggu,
    'Diperiksa'    => $diperiksa,
    'Perlu Revisi' => $perlu_revisi,
];
$labelsGrafik = array_keys($dataGrafik);
$angkaGrafik  = array_values($dataGrafik);

/* Tren 14 hari laporan pribadi (untuk grafik). */
$tren14 = [];
try {
    $stmt = $conn->prepare(
        'SELECT DATE(tanggal) AS tgl, COUNT(*) AS jumlah
           FROM laporan_harian
          WHERE user_id = ? AND tanggal >= CURDATE() - INTERVAL 13 DAY
          GROUP BY DATE(tanggal)'
    );
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $tren14[(string) $b['tgl']] = (int) $b['jumlah'];
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard tren: ' . $t->getMessage());
}

$labelTren = [];
$angkaTren = [];
for ($i = 13; $i >= 0; $i--) {
    $tgl         = date('Y-m-d', strtotime("-{$i} days"));
    $labelTren[] = tanggal_id($tgl, 'd M');
    $angkaTren[] = $tren14[$tgl] ?? 0;
}

/* ---------------------------------------------------------------
 * Grafik lanjutan: durasi kerja, kesehatan, jadwal, asisten AI.
 * ------------------------------------------------------------- */

/* Durasi kerja 14 hari (menit) dari absensi pribadi. */
$durasi14 = [];
$sehatBulan = ['sehat' => 0, 'kurang_sehat' => 0, 'sakit' => 0, 'pemulihan' => 0];
try {
    $stmt = $conn->prepare(
        'SELECT DATE(tanggal) AS tgl, jam_masuk, jam_pulang
           FROM absensi
          WHERE user_id = ? AND tanggal >= CURDATE() - INTERVAL 13 DAY
          ORDER BY tanggal'
    );
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $menit = 0;
            if ($b['jam_masuk'] !== null && $b['jam_pulang'] !== null) {
                [$h1, $m1] = array_map('intval', explode(':', (string) $b['jam_masuk']));
                [$h2, $m2] = array_map('intval', explode(':', (string) $b['jam_pulang']));
                $menit = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
                if ($menit < 0) {
                    $menit = 0;
                }
            }
            $durasi14[(string) $b['tgl']] = $menit;
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard durasi kerja: ' . $t->getMessage());
}

$labelDurasi = [];
$angkaDurasi = [];
for ($i = 13; $i >= 0; $i--) {
    $tgl           = date('Y-m-d', strtotime("-{$i} days"));
    $labelDurasi[] = tanggal_id($tgl, 'd M');
    $angkaDurasi[] = $durasi14[$tgl] ?? 0;
}

/* Kondisi kesehatan bulan berjalan. */
try {
    $stmt = $conn->prepare(
        'SELECT kondisi_kesehatan, COUNT(*) AS jumlah FROM absensi
          WHERE user_id = ? AND tanggal >= ? GROUP BY kondisi_kesehatan'
    );
    if ($stmt) {
        $stmt->bind_param('is', $user_id, $awal_bulan);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $k = (string) $b['kondisi_kesehatan'];
            if (array_key_exists($k, $sehatBulan)) {
                $sehatBulan[$k] = (int) $b['jumlah'];
            }
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard kesehatan: ' . $t->getMessage());
}

/* Jadwal pribadi per status. */
$jdwStatus = ['rencana' => 0, 'selesai' => 0, 'batal' => 0];
try {
    $stmt = $conn->prepare('SELECT status, COUNT(*) AS jumlah FROM jadwal WHERE user_id = ? GROUP BY status');
    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $k = (string) $b['status'];
            if (array_key_exists($k, $jdwStatus)) {
                $jdwStatus[$k] = (int) $b['jumlah'];
            }
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard jadwal: ' . $t->getMessage());
}

/* Penggunaan asisten AI pribadi 30 hari (pertanyaan pengguna). */
$chat30 = [];
try {
    $stmt = $conn->prepare(
        'SELECT DATE(cm.dibuat_pada) AS tgl, COUNT(*) AS jumlah
           FROM chat_messages cm
           JOIN chat_sessions cs ON cs.id = cm.session_id
          WHERE cs.user_id = ? AND cm.peran = ?
            AND cm.dibuat_pada >= CURDATE() - INTERVAL 29 DAY
          GROUP BY DATE(cm.dibuat_pada)'
    );
    if ($stmt) {
        $peranUser = 'user';
        $stmt->bind_param('is', $user_id, $peranUser);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($b = $res->fetch_assoc()) {
            $chat30[(string) $b['tgl']] = (int) $b['jumlah'];
        }
        $stmt->close();
    }
} catch (Throwable $t) {
    error_log('Dashboard chat AI: ' . $t->getMessage());
}

$labelChat30   = [];
$angkaChat30   = [];
for ($i = 29; $i >= 0; $i--) {
    $tgl          = date('Y-m-d', strtotime("-{$i} days"));
    $labelChat30[] = tanggal_id($tgl, 'd M');
    $angkaChat30[] = $chat30[$tgl] ?? 0;
}

/* Cek ketersediaan layanan AI */
$aiHidup = false;
if (AI_AKTIF) {
    $aiHidup = ai_hidup();
}

$opsi = [
    'judul' => 'Dashboard',
    'crumb' => 'Karyawan',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="buat_laporan.php">+ Buat Laporan</a>',
    'head'  => '<script src="assets/js/vendor/chart.umd.min.js" defer></script>'
             . '<script src="assets/js/grafik.js" defer></script>',
];

mulai_halaman($opsi);
?>

<!-- ================= SAMBUTAN ================= -->
<section class="kartu" style="background:linear-gradient(135deg, var(--navy-600), var(--navy-700) 55%, var(--phr-biru) 165%);border:0;color:#fff;">
    <div class="kartu-tubuh" style="padding:26px 26px 24px">
        <span class="badge badge-tanpa-titik" style="background:rgba(255,255,255,.16);color:#cfe6f7">
            PORTAL KEGIATAN <?= e(APP_ORGANISASI) ?>
        </span>
        <h2 style="color:#fff;font-size:23px;margin:12px 0 6px">
            Halo, <?= e(sesi_nama()) ?>!
        </h2>
        <p style="color:#c2d7ea;max-width:640px;margin-bottom:18px">
            Catat kegiatan harianmu, pantau perkembangan laporan, dan periksa arahan
            mentor melalui halaman ini.
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <a class="tombol" style="background:#fff;border-color:#fff;color:var(--navy-600)" href="buat_laporan.php">Buat Laporan</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="riwayat.php">Lihat Riwayat</a>
            <a class="tombol tombol-garis" style="border-color:rgba(255,255,255,.5);color:#fff" href="absensi.php">Absensi</a>
        </div>
    </div>
</section>

<?php if ($perlu_revisi > 0): ?>
    <div class="alert alert-peringatan" role="alert">
        <span class="ikon">!</span>
        <span>
            <strong>Perlu Tindak Lanjut Revisi</strong>
            Ada <?= (int) $perlu_revisi ?> laporan yang perlu diperbaiki berdasarkan
            arahan mentor.
            <a href="riwayat.php?status=perlu_revisi" style="font-weight:700">Buka daftar revisi</a>
        </span>
    </div>
<?php endif; ?>

<?php if ($draft > 0): ?>
    <div class="alert alert-info" role="status">
        <span class="ikon">i</span>
        <span>
            <strong><?= (int) $draft ?> laporan masih berupa draf</strong>
            Draf belum terkirim ke mentor. Selesaikan lalu tekan <em>Kirim ke Mentor</em>.
            <a href="riwayat.php?status=draft">Lihat draf</a>
        </span>
    </div>
<?php endif; ?>

<!-- ================= STATISTIK ================= -->
<h2 class="bagian-judul">Ringkasan Laporan</h2>

<section class="grid grid-4">
    <div class="stat navy">
        <span class="ikon"><?= ikon_sp('dokumen') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $total_laporan ?></div>
            <div class="keterangan">Total Laporan</div>
            <div class="tambahan">Seluruh laporan yang tercatat</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon"><?= ikon_sp('jam') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $menunggu ?></div>
            <div class="keterangan">Menunggu Pemeriksaan</div>
            <div class="tambahan">Status dikirim ke mentor</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon"><?= ikon_sp('centang') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $diperiksa ?></div>
            <div class="keterangan">Selesai Diperiksa</div>
            <div class="tambahan">Tidak perlu revisi lagi</div>
        </div>
    </div>

    <div class="stat biru">
        <span class="ikon"><?= ikon_sp('lonceng') ?></span>
        <div class="flex-1">
            <div class="nilai"><?= (int) $jumlah_notifikasi ?></div>
            <div class="keterangan">Notifikasi Baru</div>
            <div class="tambahan">Pesan yang belum dibaca</div>
        </div>
    </div>
</section>

<section class="ca-strip">
    <div class="ca-strip-utama">
        <p class="ca-eyebrow">Cost avoidance disetujui</p>
        <p class="ca-strip-angka"><?= e(cost_avoidance_rupiah($ca_nilai)) ?></p>
        <p class="ca-meta">
            <?= (int) ($ca_jumlah['disetujui'] ?? 0) ?> entri disetujui
            &middot; <?= (int) ($ca_jumlah['diajukan'] ?? 0) ?> menunggu verifikasi
        </p>
    </div>
    <a class="ca-tombol" href="cost_avoidance.php">Lihat rincian</a>
</section>

<!-- ================= AKSI CEPAT ================= -->
<h2 class="bagian-judul">Aksi Cepat</h2>
<section class="aksi-cepat">
    <a href="buat_laporan.php">
        <span class="ikon"><?= ikon_sp('tulis') ?></span>
        <span>Buat Laporan Baru</span>
    </a>
    <a href="riwayat.php">
        <span class="ikon"><?= ikon_sp('buku') ?></span>
        <span>Riwayat Laporan</span>
    </a>
    <a href="absensi.php">
        <span class="ikon"><?= ikon_sp('kalender') ?></span>
        <span>Isi Absensi</span>
    </a>
    <a href="asisten.php">
        <span class="ikon"><?= ikon_sp('robot') ?></span>
        <span>Asisten AI</span>
    </a>
</section>

<!-- ================= SOROTAN CEPAT ================= -->
<section class="sorotan-ringkas" style="margin-top:16px">
    <a class="s-chip" href="riwayat.php?status=dikirim">
        <span class="s-ikon">&#9203;</span>
        <span class="s-teks">
            <span class="s-judul">Menunggu Pemeriksaan</span>
            <span class="s-ket"><?= (int) $menunggu ?> laporan dikirim</span>
        </span>
    </a>
    <a class="s-chip" href="riwayat.php?status=diperiksa">
        <span class="s-ikon">&#10003;</span>
        <span class="s-teks">
            <span class="s-judul">Sudah Diperiksa</span>
            <span class="s-ket"><?= (int) $diperiksa ?> laporan selesai</span>
        </span>
    </a>
    <a class="s-chip" href="riwayat.php?status=perlu_revisi">
        <span class="s-ikon">&#9998;</span>
        <span class="s-teks">
            <span class="s-judul">Perlu Revisi</span>
            <span class="s-ket"><?= (int) $perlu_revisi ?> laporan perbaikan</span>
        </span>
    </a>
    <a class="s-chip" href="notifikasi.php">
        <span class="s-ikon">&#128276;</span>
        <span class="s-teks">
            <span class="s-judul">Notifikasi</span>
            <span class="s-ket"><?= (int) $jumlah_notifikasi ?> pesan baru</span>
        </span>
    </a>
</section>



<!-- ================= PROGRES ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Progres Penyelesaian Laporan</h3>
            <span class="text-redup text-kecil">Hanya laporan berstatus diperiksa yang dihitung selesai.</span>
        </div>
        <strong style="font-size:26px;color:var(--phr-biru)"><?= (int) $persentase_progres ?>%</strong>
    </div>
    <div class="kartu-tubuh">
        <div class="progres" role="progressbar"
             aria-label="Progres penyelesaian laporan"
             aria-valuenow="<?= (int) $persentase_progres ?>"
             aria-valuemin="0" aria-valuemax="100">
            <span style="width:<?= (int) $persentase_progres ?>%"></span>
        </div>
        <div class="d-flex justify-between text-kecil text-redup mt-2 flex-wrap gap-2">
            <span><?= (int) $diperiksa ?> dari <?= (int) $total_laporan ?> laporan selesai</span>
            <span><?= (int) $perlu_revisi ?> perlu revisi &middot; <?= (int) $draft ?> draf</span>
        </div>
    </div>
</section>

<!-- ================= GRAFIK + AKTIVITAS ================= -->
<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div>
                <h3>Grafik Status Laporan</h3>
                <span class="text-redup text-kecil">Perbandingan laporan berdasarkan status saat ini.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:250px">
                <canvas id="grafikStatus" aria-label="Grafik status laporan" role="img"></canvas>
            </div>
            <div class="d-flex gap-2 flex-wrap mt-3 text-kecil">
                <span class="badge badge-navy badge-tanpa-titik">Total: <?= (int) $total_laporan ?></span>
                <span class="badge badge-hijau badge-tanpa-titik">Diperiksa: <?= (int) $diperiksa ?></span>
                <span class="badge badge-merah badge-tanpa-titik">Revisi: <?= (int) $perlu_revisi ?></span>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Aktivitas Terbaru</h3>
                <span class="text-redup text-kecil">Lima laporan terakhir yang Anda buat.</span>
            </div>
            <a class="tombol tombol-kecil" href="riwayat.php">Semua</a>
        </div>
        <div class="kartu-tubuh padat">
            <?php if ($pesan_aktivitas !== ''): ?>
                <div class="empty"><p class="empty-teks"><?= e($pesan_aktivitas) ?></p></div>
            <?php elseif (count($aktivitas) === 0): ?>
                <div class="empty">
                    <div class="empty-ikon"><?= ikon_sp('dokumen') ?></div>
                    <div class="empty-judul">Belum ada laporan</div>
                    <p class="empty-teks">Mulai catat kegiatan harianmu sekarang.</p>
                    <a class="tombol tombol-utama tombol-kecil" style="margin-top:12px" href="buat_laporan.php">Buat Laporan</a>
                </div>
            <?php else: ?>
                <div class="bungkus-tabel">
                    <table class="tabel tabel-padat">
                        <thead>
                            <tr>
                                <th>Tanggal</th>
                                <th>Kegiatan</th>
                                <th>Status</th>
                                <th class="text-kanan">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($aktivitas as $item): ?>
                            <tr>
                                <td class="text-kecil text-redup"><?= e(tanggal_id((string) $item['tanggal'])) ?></td>
                                <td>
                                    <span class="potong-2"><?= e(potong((string) $item['kegiatan'], 70)) ?></span>
                                </td>
                                <td><?= badge_status((string) $item['status']) ?></td>
                                <td class="text-kanan">
                                    <a class="tombol tombol-kecil" href="detail_laporan.php?id=<?= (int) $item['id'] ?>">
                                        Lihat
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- ================= TREN + ABSENSI GRAFIK ================= -->
<section class="grid grid-2" style="margin-top:20px">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div>
                <h3>Tren Laporan 14 Hari Terakhir</h3>
                <span class="text-redup text-kecil">Jumlah laporan yang Anda catat tiap hari.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikTren14" aria-label="Tren laporan 14 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <?php if ($absensi_ada): ?>
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Diagram Absensi Bulan Ini</h3>
                <span class="text-redup text-kecil">Proporsi kehadiran dari total catatan absensi.</span>
            </div>
            <a class="tombol tombol-kecil" href="absensi.php">Kelola</a>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikAbsensi" aria-label="Diagram absensi bulan ini" role="img"></canvas>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>

<!-- ================= GRAFIK LANJUTAN ================= -->
<section class="grid grid-2" style="margin-top:20px">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div>
                <h3>Durasi Kerja 14 Hari</h3>
                <span class="text-redup text-kecil">Lama kerja (menit) dari jam masuk–pulang absensi Anda.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikDurasi" aria-label="Durasi kerja 14 hari" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Kondisi Kesehatan Bulan Ini</h3>
                <span class="text-redup text-kecil">Laporan kondisi kesehatan pada absensi bulan berjalan.</span>
            </div>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikSehat" aria-label="Kondisi kesehatan bulan ini" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Jadwal Saya</h3>
                <span class="text-redup text-kecil">Jumlah jadwal pribadi per status.</span>
            </div>
            <a class="tombol tombol-kecil" href="jadwal.php">Kelola</a>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikJadwal" aria-label="Jadwal pribadi per status" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <div class="flex-1">
                <h3>Pertanyaan ke Asisten AI 30 Hari</h3>
                <span class="text-redup text-kecil">Jumlah pertanyaan Anda kepada asisten tiap hari.</span>
            </div>
            <a class="tombol tombol-kecil" href="asisten.php">Buka</a>
        </div>
        <div class="kartu-tubuh">
            <div style="position:relative;height:240px">
                <canvas id="grafikChat" aria-label="Penggunaan asisten AI 30 hari" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= ABSENSI ================= -->
<?php if ($absensi_ada): ?>
<section class="kartu" style="margin-top:20px">
    <div class="kartu-header">
        <div class="flex-1">
            <h3>Absensi Bulan Ini</h3>
            <span class="text-redup text-kecil">Ringkasan kehadiran bulan berjalan.</span>
        </div>
        <a class="tombol tombol-kecil" href="absensi.php">Kelola Absensi</a>
    </div>
    <div class="kartu-tubuh">
        <div class="grid grid-3">
            <div class="stat biru">
                <span class="ikon"><?= ikon_sp('kesehatan') ?></span>
                <div class="flex-1">
                    <div class="nilai"><?= (int) $absensi_bulan['sakit'] ?></div>
                    <div class="keterangan">Sakit</div>
                </div>
            </div>
            <div class="stat kuning">
                <span class="ikon"><?= ikon_sp('tulis') ?></span>
                <div class="flex-1">
                    <div class="nilai"><?= (int) $absensi_bulan['izin'] ?></div>
                    <div class="keterangan">Izin</div>
                </div>
            </div>
            <div class="stat merah">
                <span class="ikon"><?= ikon_sp('silang') ?></span>
                <div class="flex-1">
                    <div class="nilai"><?= (int) $absensi_bulan['alpa'] ?></div>
                    <div class="keterangan">Alpha</div>
                </div>
            </div>
        </div>
        <p class="text-redup text-kecil mt-3 mb-0">
            Rekap tanggal <?= e(tanggal_id($awal_bulan)) ?> sampai hari ini
            &middot; hadir <?= (int) $absensi_bulan['hadir'] ?> kali.
        </p>
    </div>
</section>
<?php endif; ?>

<!-- ================= INFO + AI ================= -->
<section class="grid grid-2" style="margin-top:20px">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header"><h3>Tips Pelaporan</h3></div>
        <div class="kartu-tubuh">
            <p class="text-redup" style="margin-bottom:10px">
                Pastikan laporan kegiatan selalu lengkap sebelum dikirim kepada mentor:
            </p>
            <ul style="margin:0;padding-left:20px;line-height:1.9;font-size:14.5px">
                <li>Tuliskan kegiatan dengan jelas dan tanggal yang sesuai.</li>
                <li>Lampirkan bukti gambar yang relevan (maksimal 5 foto).</li>
                <li>Isi hasil, kendala, dan rencana berikutnya bila ada.</li>
                <li>Periksa notifikasi untuk catatan mentor.</li>
            </ul>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-header">
            <span class="avatar avatar-kecil" style="background:linear-gradient(135deg,var(--navy-600),var(--phr-biru))">AI</span>
            <h3>Asisten AI</h3>
            <span class="badge <?= $aiHidup ? 'badge-hijau' : 'badge-merah' ?>" style="margin-left:auto">
                <?= $aiHidup ? 'Terhubung' : 'Tidak terhubung' ?>
            </span>
        </div>
        <div class="kartu-tubuh">
            <p class="text-redup" style="margin-bottom:12px">
                Minta bantuan untuk merangkum laporan, memeriksa kelengkapan,
                membuat narasi, atau melihat statistik kegiatan Anda.
            </p>
            <div class="d-flex gap-2 flex-wrap">
                <a class="tombol tombol-garis tombol-kecil" href="asisten.php">Buka Asisten Penuh</a>
                <button class="tombol tombol-kecil" type="button"
                        onclick="document.getElementById('tombolChat').click()">
                    Chat Cepat
                </button>
            </div>
        </div>
    </div>
</section>

<?php
$flagsJson = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$labelsJson = json_encode($labelsGrafik, $flagsJson) ?: '[]';
$angkaJson  = json_encode($angkaGrafik) ?: '[]';
$trenLabelJson = json_encode($labelTren, $flagsJson) ?: '[]';
$trenDataJson  = json_encode($angkaTren) ?: '[]';
$labelDurasiJson = json_encode($labelDurasi, $flagsJson) ?: '[]';
$labelChatJson   = json_encode($labelChat30, $flagsJson) ?: '[]';
$absensiJson = json_encode(
    [$absensi_bulan['hadir'], $absensi_bulan['izin'], $absensi_bulan['sakit'], $absensi_bulan['alpa']]
) ?: '[]';
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof grafikSiap === 'undefined') return;

    var W = window.WARNA_GRAFIK;

    grafikSiap(function (Chart) {
        var canvas = document.getElementById('grafikStatus');
        if (canvas) {
            new Chart(canvas, {
                type: 'doughnut',
                data: {
                    labels: <?= $labelsJson ?>,
                    datasets: [{
                        data: <?= $angkaJson ?>,
                        backgroundColor: window.WARNA_STATUS,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true } }
                    }
                }
            });
        }

        /* Tren 14 hari. */
        var cTren = document.getElementById('grafikTren14');
        if (cTren) {
            new Chart(cTren, {
                type: 'line',
                data: {
                    labels: <?= $trenLabelJson ?>,
                    datasets: [{
                        label: 'Laporan',
                        data: <?= $trenDataJson ?>,
                        borderColor: W.biru,
                        backgroundColor: 'rgba(0,94,184,.12)',
                        fill: true,
                        tension: 0.35,
                        pointRadius: 2.5,
                        pointBackgroundColor: W.biru
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, ticks: { precision: 0 } }
                    },
                    plugins: { legend: { display: false } }
                }
            });
        }

        /* Donut absensi bulan berjalan. */
        var cAbsensi = document.getElementById('grafikAbsensi');
        if (cAbsensi) {
            new Chart(cAbsensi, {
                type: 'doughnut',
                data: {
                    labels: ['Hadir', 'Izin', 'Sakit', 'Alpa'],
                    datasets: [{
                        data: <?= $absensiJson ?>,
                        backgroundColor: window.WARNA_ABSENSI,
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '62%',
                    plugins: {
                        legend: { position: 'bottom', labels: { padding: 16, usePointStyle: true } }
                    }
                }
            });
        }

        /* Durasi kerja 14 hari (batang). */
        var cDurasi = document.getElementById('grafikDurasi');
        if (cDurasi) {
            if (!window.grafikAdaData(<?= json_encode($angkaDurasi) ?: '[]' ?>)) {
                window.grafikKosong('grafikDurasi', 'Belum ada absensi dengan jam masuk–pulang.');
            } else {
                new Chart(cDurasi, {
                    type: 'bar',
                    data: {
                        labels: <?= $labelDurasiJson ?>,
                        datasets: [{
                            label: 'Menit',
                            data: <?= json_encode($angkaDurasi) ?: '[]' ?>,
                            backgroundColor: W.biruMuda,
                            borderRadius: 5,
                            maxBarThickness: 34
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }

        /* Kondisi kesehatan bulan ini (donat). */
        var cSehat = document.getElementById('grafikSehat');
        if (cSehat) {
            var dataSehat = <?= json_encode([$sehatBulan['sehat'], $sehatBulan['kurang_sehat'], $sehatBulan['sakit'], $sehatBulan['pemulihan']]) ?: '[]' ?>;
            if (!window.grafikAdaData(dataSehat)) {
                window.grafikKosong('grafikSehat', 'Belum ada laporan kondisi kesehatan bulan ini.');
            } else {
                new Chart(cSehat, {
                    type: 'doughnut',
                    data: {
                        labels: ['Sehat', 'Kurang Sehat', 'Sakit', 'Pemulihan'],
                        datasets: [{
                            data: dataSehat,
                            backgroundColor: [W.hijau, W.kuning, W.merah, W.biruMuda],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } }
                        }
                    }
                });
            }
        }

        /* Jadwal pribadi per status (donat). */
        var cJadwal = document.getElementById('grafikJadwal');
        if (cJadwal) {
            var dataJadwal = <?= json_encode([$jdwStatus['rencana'], $jdwStatus['selesai'], $jdwStatus['batal']]) ?: '[]' ?>;
            if (!window.grafikAdaData(dataJadwal)) {
                window.grafikKosong('grafikJadwal', 'Belum ada jadwal pribadi.');
            } else {
                new Chart(cJadwal, {
                    type: 'doughnut',
                    data: {
                        labels: ['Rencana', 'Selesai', 'Batal'],
                        datasets: [{
                            data: dataJadwal,
                            backgroundColor: [W.biru, W.hijau, W.abu],
                            borderWidth: 2,
                            borderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom', labels: { padding: 14, usePointStyle: true } }
                        }
                    }
                });
            }
        }

        /* Pertanyaan ke asisten AI 30 hari (garis). */
        var cChat = document.getElementById('grafikChat');
        if (cChat) {
            if (!window.grafikAdaData(<?= json_encode($angkaChat30) ?: '[]' ?>)) {
                window.grafikKosong('grafikChat', 'Anda belum bertanya kepada asisten AI.');
            } else {
                new Chart(cChat, {
                    type: 'line',
                    data: {
                        labels: <?= $labelChatJson ?>,
                        datasets: [{
                            label: 'Pertanyaan',
                            data: <?= json_encode($angkaChat30) ?: '[]' ?>,
                            borderColor: W.hijau,
                            backgroundColor: 'rgba(21,148,71,.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 2.5,
                            pointBackgroundColor: W.hijau
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } }
                        },
                        plugins: { legend: { display: false } }
                    }
                });
            }
        }
    });
});
</script>

<?php akhiri_halaman(); ?>
