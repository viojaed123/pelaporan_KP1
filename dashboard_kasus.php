<?php
/**
 * dashboard_kasus.php — ringkasan & daftar kasus (modul bersama).
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga login terpusat (semula session_start manual)
 *   · layout terpusat — <style> inline, <head>, dan <body> lama dihapus
 *   · fungsi e() lokal dihapus (sudah tersedia di includes/helpers.php)
 *   · palet warna diseragamkan lewat kelas app.css (urgensi-* & kasus-*)
 *   · grafik status, peta lokasi, filter, dan pencarian tetap utuh
 *
 * KEAMANAN (IDOR) — penting:
 * Tabel `kasus` kini memiliki kolom kepemilikan: `dibuat_oleh` (pembuat)
 * dan `penanggung_jawab_id` (PIC pengguna), serta tabel `kasus_penugasan`
 * (anggota tim). Karena itu:
 *   · mentor & admin melihat seluruh kasus;
 *   · karyawan hanya melihat kasus yang ia buat, ditangasi, atau ikuti.
 * Pembatasan diterapkan lewat kasus_sql_visibilitas() (lihat includes/kasus.php)
 * pada setiap kueri di halaman ini. Penulisan (buat/edit/hapus/riwayat)
 * dijaga oleh kasus_izin_kelola()/kasus_izin_hapus() di masing-masing halaman.
 * Halaman ini sendiri murni baca: tidak ada operasi tulis.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$role_login = sesi_role();

/* Klausa pembatas baris kasus sesuai peran ('' = melihat semua). */
$kasus_vis = kasus_sql_visibilitas('k');

/* ---------------------------------------------------------------
 * Fungsi bantu tampilan: kelas badge status kasus (app.css).
 * (label_status_kasus() & kelas_urgensi() sudah tersedia di helpers.php)
 * ------------------------------------------------------------- */

if (!function_exists('kelas_status_kasus')) {
    function kelas_status_kasus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'terbuka'      => 'kasus-terbuka',
            'dalam proses' => 'kasus-dalam-proses',
            'selesai'      => 'kasus-selesai',
            default        => 'badge-navy',
        };
    }
}

/* ==========================================
 * 1. DISTRIBUSI STATUS KASUS
 * ========================================== */

$jumlah_status = [
    'Terbuka' => 0,
    'Dalam Proses' => 0,
    'Selesai' => 0,
];

$sql_status = "
    SELECT k.status_kasus, COUNT(*) AS jumlah
    FROM kasus k
    " . ($kasus_vis !== '' ? "WHERE {$kasus_vis}" : '') . "
    GROUP BY k.status_kasus
";

$result_status = $conn->query($sql_status);

if ($result_status) {
    while ($row = $result_status->fetch_assoc()) {
        $status = $row['status_kasus'];

        if (isset($jumlah_status[$status])) {
            $jumlah_status[$status] = (int) $row['jumlah'];
        }
    }

    $result_status->free();
}

/* ==========================================
 * 2. DISTRIBUSI TINGKAT URGENSI
 * ========================================== */

$jumlah_urgensi = [
    'Rendah' => 0,
    'Sedang' => 0,
    'Tinggi' => 0,
    'Kritis' => 0,
];

$sql_urgensi = "
    SELECT k.tingkat_urgensi, COUNT(*) AS jumlah
    FROM kasus k
    " . ($kasus_vis !== '' ? "WHERE {$kasus_vis}" : '') . "
    GROUP BY k.tingkat_urgensi
";

$result_urgensi = $conn->query($sql_urgensi);

if ($result_urgensi) {
    while ($row = $result_urgensi->fetch_assoc()) {
        $urgensi = $row['tingkat_urgensi'];

        if (isset($jumlah_urgensi[$urgensi])) {
            $jumlah_urgensi[$urgensi] = (int) $row['jumlah'];
        }
    }

    $result_urgensi->free();
}

/* ==========================================
 * 3. EMPAT KARTU RINGKASAN
 * ========================================== */

$total_kasus = 0;
$kasus_terbuka = 0;
$kasus_kritis = 0;
$rata_progres = 0;

$sql_ringkasan = "
    SELECT
        COUNT(*) AS total,
        COALESCE(
            SUM(k.status_kasus = 'Terbuka'), 0
        ) AS terbuka,
        COALESCE(
            SUM(k.tingkat_urgensi = 'Kritis'), 0
        ) AS kritis,
        COALESCE(AVG(k.progres), 0) AS rata_progres
    FROM kasus k
    " . ($kasus_vis !== '' ? "WHERE {$kasus_vis}" : '') . "
";

$result_ringkasan = $conn->query($sql_ringkasan);

if ($result_ringkasan) {
    $ringkasan = $result_ringkasan->fetch_assoc();

    $total_kasus = (int) $ringkasan['total'];
    $kasus_terbuka = (int) $ringkasan['terbuka'];
    $kasus_kritis = (int) $ringkasan['kritis'];
    $rata_progres = (int) round(
        (float) $ringkasan['rata_progres']
    );

    $result_ringkasan->free();
}

/* ==========================================
 * 4. FILTER DAN PENCARIAN
 * ========================================== */

$status_filter = $_GET['status'] ?? '';
$urgensi_filter = $_GET['urgensi'] ?? '';
$kata_kunci = trim((string) ($_GET['cari'] ?? ''));

// Batasi panjang kata kunci
if (strlen($kata_kunci) > 100) {
    $kata_kunci = substr($kata_kunci, 0, 100);
}

$pilihan_status = [
    '',
    'Terbuka',
    'Dalam Proses',
    'Selesai',
];

$pilihan_urgensi = [
    '',
    'Rendah',
    'Sedang',
    'Tinggi',
    'Kritis',
];

// Validasi filter
if (!in_array($status_filter, $pilihan_status, true)) {
    $status_filter = '';
}

if (!in_array($urgensi_filter, $pilihan_urgensi, true)) {
    $urgensi_filter = '';
}

/* ==========================================
 * 5. DETAIL KASUS DAN NAMA PIC
 * ========================================== */

$sql_detail_kasus = "
    SELECT
        k.id,
        k.kode_kasus,
        k.judul,
        k.nama_lokasi,
        k.tingkat_urgensi,
        k.status_kasus,
        k.progres,
        k.kategori,
        k.sumber,
        k.target_selesai,
        k.dibuat_pada,
        (SELECT u2.nama FROM users u2 WHERE u2.id = k.dibuat_oleh) AS nama_pembuat,
        (SELECT u3.nama FROM users u3 WHERE u3.id = k.penanggung_jawab_id) AS nama_pic_user,
        GROUP_CONCAT(
            DISTINCT p.nama_lengkap
            ORDER BY p.nama_lengkap
            SEPARATOR ', '
        ) AS daftar_pic
    FROM kasus k
    LEFT JOIN kasus_pihak kp
        ON kp.kasus_id = k.id
    LEFT JOIN pihak_terlibat p
        ON p.id = kp.pihak_id
    WHERE 1 = 1
";

if ($kasus_vis !== '') {
    $sql_detail_kasus .= " AND {$kasus_vis}";
}

$parameter = [];
$tipe_parameter = '';

// Filter status
if ($status_filter !== '') {
    $sql_detail_kasus .= " AND k.status_kasus = ?";
    $parameter[] = $status_filter;
    $tipe_parameter .= 's';
}

// Filter urgensi
if ($urgensi_filter !== '') {
    $sql_detail_kasus .= " AND k.tingkat_urgensi = ?";
    $parameter[] = $urgensi_filter;
    $tipe_parameter .= 's';
}

// Pencarian kode, judul, atau lokasi
if ($kata_kunci !== '') {
    $sql_detail_kasus .= "
        AND (
            k.kode_kasus LIKE ?
            OR k.judul LIKE ?
            OR k.nama_lokasi LIKE ?
        )
    ";

    $pola_cari = '%' . $kata_kunci . '%';

    $parameter[] = $pola_cari;
    $parameter[] = $pola_cari;
    $parameter[] = $pola_cari;

    $tipe_parameter .= 'sss';
}

$sql_detail_kasus .= "
    GROUP BY
        k.id,
        k.kode_kasus,
        k.judul,
        k.nama_lokasi,
        k.tingkat_urgensi,
        k.status_kasus,
        k.progres,
        k.kategori,
        k.sumber,
        k.target_selesai,
        k.dibuat_pada
    ORDER BY k.id DESC
";

$stmt_detail = $conn->prepare($sql_detail_kasus);
$result_detail_kasus = false;
$error_detail_kasus = false;

if ($stmt_detail) {
    $berhasil_bind = true;

    if (!empty($parameter)) {
        $berhasil_bind = $stmt_detail->bind_param(
            $tipe_parameter,
            ...$parameter
        );
    }

    if ($berhasil_bind && $stmt_detail->execute()) {
        $result_detail_kasus = $stmt_detail->get_result();
    } else {
        $error_detail_kasus = true;
    }
} else {
    $error_detail_kasus = true;
}

/* ==========================================
 * 6. DATA LOKASI UNTUK PETA
 * Mengikuti filter dan pencarian tabel
 * ========================================== */

$data_peta = [];

$sql_peta = "
    SELECT
        k.id,
        k.kode_kasus,
        k.judul,
        k.nama_lokasi,
        k.garis_lintang,
        k.garis_bujur,
        k.tingkat_urgensi,
        k.status_kasus
    FROM kasus k
    WHERE k.garis_lintang IS NOT NULL
      AND k.garis_bujur IS NOT NULL
      AND k.garis_lintang BETWEEN -90 AND 90
      AND k.garis_bujur BETWEEN -180 AND 180
";

if ($kasus_vis !== '') {
    $sql_peta .= " AND {$kasus_vis}";
}

$parameter_peta = [];
$tipe_parameter_peta = '';

// Filter status
if ($status_filter !== '') {
    $sql_peta .= " AND k.status_kasus = ?";
    $parameter_peta[] = $status_filter;
    $tipe_parameter_peta .= 's';
}

// Filter urgensi
if ($urgensi_filter !== '') {
    $sql_peta .= " AND k.tingkat_urgensi = ?";
    $parameter_peta[] = $urgensi_filter;
    $tipe_parameter_peta .= 's';
}

// Filter pencarian
if ($kata_kunci !== '') {
    $sql_peta .= "
        AND (
            k.kode_kasus LIKE ?
            OR k.judul LIKE ?
            OR k.nama_lokasi LIKE ?
        )
    ";

    $pola_peta = '%' . $kata_kunci . '%';

    $parameter_peta[] = $pola_peta;
    $parameter_peta[] = $pola_peta;
    $parameter_peta[] = $pola_peta;

    $tipe_parameter_peta .= 'sss';
}

$sql_peta .= " ORDER BY k.id DESC";

$stmt_peta = $conn->prepare($sql_peta);

if ($stmt_peta) {
    $berhasil_bind_peta = true;

    if (!empty($parameter_peta)) {
        $berhasil_bind_peta = $stmt_peta->bind_param(
            $tipe_parameter_peta,
            ...$parameter_peta
        );
    }

    if ($berhasil_bind_peta && $stmt_peta->execute()) {
        $result_peta = $stmt_peta->get_result();

        while ($row = $result_peta->fetch_assoc()) {
            $lat = (float) $row['garis_lintang'];
            $lng = (float) $row['garis_bujur'];

            // Abaikan koordinat (0, 0).
            if ($lat == 0.0 && $lng == 0.0) {
                continue;
            }

            $data_peta[] = [
                'kode' => $row['kode_kasus'],
                'judul' => $row['judul'],
                'lokasi' => $row['nama_lokasi'],
                'lat' => $lat,
                'lng' => $lng,
                'urgensi' => $row['tingkat_urgensi'],
                'status' => $row['status_kasus'],
            ];
        }

        $result_peta->free();
    }

    $stmt_peta->close();
}

// Encode data peta menjadi JSON yang aman untuk HTML
$json_peta = json_encode(
    $data_peta,
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

if ($json_peta === false) {
    $json_peta = '[]';
}

/* ==========================================
 * 7. DATA UNTUK GRAFIK
 * ========================================== */

$json_status = json_encode(
    array_keys($jumlah_status),
    JSON_HEX_TAG | JSON_HEX_APOS |
    JSON_HEX_QUOT | JSON_HEX_AMP
);

$json_data_status = json_encode(
    array_values($jumlah_status)
);

$json_urgensi = json_encode(
    array_keys($jumlah_urgensi),
    JSON_HEX_TAG | JSON_HEX_APOS |
    JSON_HEX_QUOT | JSON_HEX_AMP
);

$json_data_urgensi = json_encode(
    array_values($jumlah_urgensi)
);

/* ==========================================
 * 8. TAMPILAN
 * ========================================== */

$opsi = [
    'judul' => 'Dashboard Kasus',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => '<a class="tombol tombol-utama tombol-kecil" href="buat_kasus.php">'
                . ikon_sp('tambah') . ' Buat Kasus</a>'
                . '<a class="tombol tombol-garis tombol-kecil" href="'
                . e(halaman_default_peran($role_login)) . '">← Dashboard</a>',
    'head'  => '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>'
                . '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
                . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>',
];

mulai_halaman($opsi);
?>

<!-- ================= KARTU RINGKASAN ================= -->
<section class="grid grid-4">
    <div class="stat biru">
        <span class="ikon">⚑</span>
        <div class="flex-1">
            <div class="nilai"><?= number_format($total_kasus, 0, ',', '.') ?></div>
            <div class="keterangan">Total Kasus</div>
            <div class="tambahan">Seluruh kasus yang tercatat</div>
        </div>
    </div>

    <div class="stat kuning">
        <span class="ikon">◷</span>
        <div class="flex-1">
            <div class="nilai"><?= number_format($kasus_terbuka, 0, ',', '.') ?></div>
            <div class="keterangan">Kasus Terbuka</div>
            <div class="tambahan">Belum ditutup</div>
        </div>
    </div>

    <div class="stat merah">
        <span class="ikon">!</span>
        <div class="flex-1">
            <div class="nilai"><?= number_format($kasus_kritis, 0, ',', '.') ?></div>
            <div class="keterangan">Kasus Kritis</div>
            <div class="tambahan">Tingkat urgensi tertinggi</div>
        </div>
    </div>

    <div class="stat hijau">
        <span class="ikon">▲</span>
        <div class="flex-1">
            <div class="nilai"><?= $rata_progres ?><span class="text-kecil">%</span></div>
            <div class="keterangan">Rata-rata Progres</div>
            <div class="tambahan">Rerata penanganan kasus</div>
        </div>
    </div>
</section>

<!-- ================= PETA LOKASI KASUS ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Mapping Lokasi Kasus</h3>
            <span class="text-redup text-kecil">
                Peta mengikuti pencarian &amp; filter yang diterapkan pada tabel kasus.
                Klik titik untuk melihat informasi kasus.
            </span>
        </div>
    </div>
    <div class="kartu-badan">
        <div id="petaKasus" style="width:100%;height:450px;border-radius:12px;overflow:hidden"></div>

        <p id="statusPeta" class="text-redup text-kecil mt-2" aria-live="polite">
            Menyiapkan peta lokasi...
        </p>

        <div class="d-flex gap-3 flex-wrap mt-2 text-kecil">
            <span><span class="text-hijau">●</span> Rendah</span>
            <span><span class="text-biru">●</span> Sedang</span>
            <span><span style="color:var(--kuning-600)">●</span> Tinggi</span>
            <span><span class="text-merah">●</span> Kritis</span>
        </div>
    </div>
</section>

<!-- ================= GRAFIK ================= -->
<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-kepala">
            <div class="flex-1">
                <h3>Distribusi Status Kasus</h3>
                <span class="text-redup text-kecil">Jumlah kasus per status saat ini.</span>
            </div>
        </div>
        <div class="kartu-badan">
            <div style="position:relative;height:300px">
                <canvas id="grafikStatusKasus" aria-label="Distribusi status kasus" role="img"></canvas>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-kepala">
            <div class="flex-1">
                <h3>Distribusi Tingkat Urgensi</h3>
                <span class="text-redup text-kecil">Jumlah kasus per tingkat urgensi.</span>
            </div>
        </div>
        <div class="kartu-badan">
            <div style="position:relative;height:300px">
                <canvas id="grafikUrgensiKasus" aria-label="Distribusi tingkat urgensi" role="img"></canvas>
            </div>
        </div>
    </div>
</section>

<!-- ================= FILTER & TABEL ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Detail Kasus dan Penanggung Jawab (PIC)</h3>
            <span class="text-redup text-kecil">
                Cari kasus berdasarkan kode, judul, atau lokasi. Pencarian dapat
                digabungkan dengan filter status dan urgensi.
            </span>
        </div>
    </div>

    <div class="kartu-badan">

        <!-- FILTER (form GET — tidak mengubah data, tanpa CSRF) -->
        <form method="GET" action="dashboard_kasus.php" class="filter-bar">
            <div class="grup-form">
                <label class="label-form" for="cari">Cari Kasus</label>
                <input type="text" name="cari" id="cari"
                       placeholder="Contoh: SIM-DUR-002 atau Duri..."
                       value="<?= e($kata_kunci) ?>" maxlength="100">
            </div>

            <div class="grup-form">
                <label class="label-form" for="status">Status Kasus</label>
                <select name="status" id="status">
                    <option value="">Semua Status</option>
                    <option value="Terbuka"      <?= $status_filter === 'Terbuka' ? 'selected' : '' ?>>Terbuka</option>
                    <option value="Dalam Proses" <?= $status_filter === 'Dalam Proses' ? 'selected' : '' ?>>Dalam Proses</option>
                    <option value="Selesai"      <?= $status_filter === 'Selesai' ? 'selected' : '' ?>>Selesai</option>
                </select>
            </div>

            <div class="grup-form">
                <label class="label-form" for="urgensi">Tingkat Urgensi</label>
                <select name="urgensi" id="urgensi">
                    <option value="">Semua Urgensi</option>
                    <option value="Rendah" <?= $urgensi_filter === 'Rendah' ? 'selected' : '' ?>>Rendah</option>
                    <option value="Sedang" <?= $urgensi_filter === 'Sedang' ? 'selected' : '' ?>>Sedang</option>
                    <option value="Tinggi" <?= $urgensi_filter === 'Tinggi' ? 'selected' : '' ?>>Tinggi</option>
                    <option value="Kritis" <?= $urgensi_filter === 'Kritis' ? 'selected' : '' ?>>Kritis</option>
                </select>
            </div>

            <div class="aksi-filter">
                <button type="submit" class="tombol tombol-utama">Cari / Terapkan</button>
                <a href="dashboard_kasus.php" class="tombol">Reset</a>
            </div>
        </form>

        <!-- INFORMASI HASIL FILTER -->
        <p class="text-redup text-kecil">
            <?php if ($error_detail_kasus): ?>
                Data kasus gagal dimuat. Periksa konfigurasi database.
            <?php elseif ($result_detail_kasus): ?>
                Jumlah kasus sesuai pencarian dan filter:
                <strong><?= number_format($result_detail_kasus->num_rows, 0, ',', '.') ?></strong>
            <?php endif; ?>
        </p>

        <!-- TABEL KASUS -->
        <div class="bungkus-tabel">
            <table class="tabel tabel-padat">
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Kode Kasus</th>
                        <th>Judul Kasus</th>
                        <th>Lokasi</th>
                        <th>Urgensi</th>
                        <th>Status</th>
                        <th>Progres</th>
                        <th>PIC / Pembuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($result_detail_kasus && $result_detail_kasus->num_rows > 0): ?>
                    <?php $nomor = 1; ?>

                    <?php while ($kasus = $result_detail_kasus->fetch_assoc()): ?>
                        <?php
                        $urgensi = (string) ($kasus['tingkat_urgensi'] ?? '');
                        $status  = (string) ($kasus['status_kasus'] ?? '');
                        $progres = max(0, min(100, (int) $kasus['progres']));
                        ?>

                        <tr>
                            <td><?= $nomor++ ?></td>

                            <td>
                                <a href="detail_kasus.php?id=<?= (int) $kasus['id'] ?>">
                                    <?= e($kasus['kode_kasus']) ?>
                                </a>
                            </td>

                            <td><?= e($kasus['judul']) ?></td>

                            <td>
                                <?= e($kasus['nama_lokasi']) ?>
                                <?php $uKasus = wilayah_peta_url((string) $kasus['nama_lokasi'], ['Riau']); ?>
                                <?php if ($uKasus !== ''): ?>
                                    <br><a class="tautan-lokasi" href="<?= e($uKasus) ?>" target="_blank"
                                       rel="noopener" title="Buka lokasi di Google Maps"><?= ikon_sp('peta') ?> Cek Lokasi</a>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="badge <?= e(kelas_urgensi($urgensi)) ?>">
                                    <?= e($urgensi) ?>
                                </span>
                            </td>

                            <td>
                                <span class="badge <?= e(kelas_status_kasus($status)) ?>">
                                    <?= e(label_status_kasus($status)) ?>
                                </span>
                            </td>

                            <td style="min-width:120px">
                                <strong><?= $progres ?>%</strong>
                                <div class="progres mt-2" role="progressbar"
                                     aria-valuenow="<?= $progres ?>" aria-valuemin="0" aria-valuemax="100">
                                    <span style="width:<?= $progres ?>%"></span>
                                </div>
                            </td>

                            <td>
                                <?php if (!empty($kasus['nama_pic_user'])): ?>
                                    <strong><?= e($kasus['nama_pic_user']) ?></strong>
                                <?php else: ?>
                                    <span class="text-redup">Belum ada PIC</span>
                                <?php endif; ?>
                                <?php if (!empty($kasus['nama_pembuat'])): ?>
                                    <div class="text-redup text-kecil">Dibuat: <?= e($kasus['nama_pembuat']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($kasus['daftar_pic'])): ?>
                                    <div class="text-redup text-kecil">Pihak: <?= e($kasus['daftar_pic']) ?></div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <a class="tombol tombol-garis tombol-mini" href="detail_kasus.php?id=<?= (int) $kasus['id'] ?>">Detail</a>
                                <?php if (kasus_izin_kelola($conn, $kasus, (int) sesi_user_id(), $role_login)): ?>
                                    <a class="tombol tombol-garis tombol-mini" href="edit_kasus.php?id=<?= (int) $kasus['id'] ?>">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endwhile; ?>

                <?php else: ?>
                    <tr>
                        <td colspan="9" class="tabel-kosong">
                            <?php if ($error_detail_kasus): ?>
                                Tabel kasus tidak dapat dimuat.
                            <?php else: ?>
                                Tidak ada kasus yang sesuai dengan pencarian
                                atau filter yang dipilih.
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<!-- ================= CATATAN ================= -->
<div class="kotak-catatan" role="note">
    <strong>Informasi:</strong>
    <?php if (kasus_peran_penuh($role_login)): ?>
        Kartu ringkasan, grafik, dan daftar menampilkan seluruh kasus.
    <?php else: ?>
        Anda melihat kasus yang Anda buat, yang ditugaskan kepada Anda, atau
        yang Anda ikuti sebagai anggota tim.
    <?php endif; ?>
    Pencarian dan filter memengaruhi tabel detail serta peta.
    Klik <strong>Buat Kasus</strong> untuk menambah kasus baru.
</div>

<!-- ================= JAVASCRIPT GRAFIK ================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const labelStatus = <?= $json_status ?: '[]' ?>;
    const dataStatus = <?= $json_data_status ?: '[]' ?>;

    const labelUrgensi = <?= $json_urgensi ?: '[]' ?>;
    const dataUrgensi = <?= $json_data_urgensi ?: '[]' ?>;

    if (typeof Chart === 'undefined') {
        console.error('Chart.js gagal dimuat. Periksa koneksi internet atau CDN.');
        return;
    }

    const canvasStatus = document.getElementById('grafikStatusKasus');

    if (canvasStatus) {
        new Chart(canvasStatus, {
            type: 'bar',
            data: {
                labels: labelStatus,
                datasets: [{
                    label: 'Jumlah Kasus',
                    data: dataStatus,
                    backgroundColor: ['#d69e2e', '#0072BC', '#159447'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, stepSize: 1 }
                    }
                }
            }
        });
    }

    const canvasUrgensi = document.getElementById('grafikUrgensiKasus');

    if (canvasUrgensi) {
        new Chart(canvasUrgensi, {
            type: 'bar',
            data: {
                labels: labelUrgensi,
                datasets: [{
                    label: 'Jumlah Kasus',
                    data: dataUrgensi,
                    backgroundColor: ['#159447', '#0072BC', '#d69e2e', '#E31E24'],
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, stepSize: 1 }
                    }
                }
            }
        });
    }
});
</script>

<!-- ================= JAVASCRIPT PETA ================= -->
<script>
document.addEventListener('DOMContentLoaded', function () {
    const statusPeta = document.getElementById('statusPeta');
    const dataPeta = <?= $json_peta ?: '[]' ?>;

    if (typeof L === 'undefined') {
        statusPeta.textContent =
            'Library peta gagal dimuat. Periksa koneksi internet.';
        return;
    }

    const peta = L.map('petaKasus');

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(peta);

    const warnaUrgensi = {
        'Rendah': '#159447',
        'Sedang': '#0072BC',
        'Tinggi': '#d69e2e',
        'Kritis': '#E31E24'
    };

    const titikLokasi = [];

    dataPeta.forEach(function (kasus) {
        const warna = warnaUrgensi[kasus.urgensi] || '#64748b';

        const marker = L.circleMarker(
            [kasus.lat, kasus.lng],
            {
                radius: 9,
                color: warna,
                fillColor: warna,
                fillOpacity: 0.85,
                weight: 2
            }
        ).addTo(peta);

        const isiPopup = `
            <div style="min-width:180px; line-height:1.6">
                <strong>${aman(kasus.kode)}</strong><br>
                ${aman(kasus.judul)}<br>
                <span>Lokasi: ${aman(kasus.lokasi || '-')}</span><br>
                <span>Urgensi: ${aman(kasus.urgensi)}</span><br>
                <span>Status: ${aman(kasus.status)}</span>
            </div>
        `;

        marker.bindPopup(isiPopup);
        titikLokasi.push([kasus.lat, kasus.lng]);
    });

    if (titikLokasi.length > 0) {
        peta.fitBounds(titikLokasi, {
            padding: [30, 30],
            maxZoom: 14
        });

        statusPeta.textContent =
            'Berhasil menampilkan ' + titikLokasi.length +
            ' titik lokasi sesuai pencarian dan filter.';
    } else {
        peta.setView([1.28, 101.21], 11);

        statusPeta.textContent =
            'Tidak ada kasus dengan koordinat valid yang sesuai filter.';
    }

    // Mencegah teks dari database diperlakukan sebagai HTML.
    function aman(nilai) {
        return String(nilai ?? '').replace(/[&<>"']/g, function (karakter) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[karakter];
        });
    }
});
</script>

<?php akhiri_halaman(); ?>
