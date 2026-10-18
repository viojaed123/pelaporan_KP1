<?php
/**
 * detail_kasus.php — detail satu kasus, pihak terlibat, riwayat, dan peta.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga login terpusat (semula session_start manual)
 *   · layout terpusat — <style> inline, <head>, dan <body> lama dihapus
 *   · fungsi e() lokal dihapus (sudah tersedia di includes/helpers.php)
 *   · token CSRF global ($_SESSION['csrf_token']) diganti skema per-aksi
 *   · warna teal #0f766e dihapus, memakai palet PHR lewat kelas app.css
 *   · semua info tetap: detail kasus, PIC, riwayat, peta, dan lampiran
 *
 * KEAMANAN (IDOR) — penting:
 * Tabel `kasus` kini memiliki kolom kepemilikan (dibuat_oleh, penanggung_jawab_id)
 * dan tabel `kasus_penugasan` untuk anggota tim. Maka:
 *   · MELIHAT detail hanya untuk pembuat, PIC, anggota tim, atau mentor/admin
 *     (kasus_izin_lihat()); pengguna lain mendapat HTTP 403;
 *   · MENULIS (tambah riwayat, ubah status) untuk pihak yang sama
 *     (kasus_izin_kelola()); tombol Edit/Hapus hanya muncul bila berhak.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_login  = (int) sesi_user_id();
$role_login  = sesi_role();

/* ---------------------------------------------------------------
 * Kelas badge status kasus (app.css).
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
 * 1. VALIDASI ID KASUS
 * ========================================== */

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('ID kasus tidak valid.');
}

/* ==========================================
 * 2. AMBIL DETAIL KASUS (sebelum menampilkan apa pun)
 * ========================================== */

$sql = "
    SELECT
        id,
        kode_kasus,
        judul,
        deskripsi,
        nama_lokasi,
        garis_lintang,
        garis_bujur,
        tingkat_urgensi,
        status_kasus,
        progres,
        kategori,
        sumber,
        target_selesai,
        dibuat_oleh,
        penanggung_jawab_id,
        penanggung_jawab_teks,
        dibuat_pada,
        diperbarui_pada
    FROM kasus
    WHERE id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    http_response_code(500);
    exit('Gagal menyiapkan query detail kasus.');
}

$stmt->bind_param('i', $id);
$stmt->execute();

$result = $stmt->get_result();
$kasus = $result->fetch_assoc();

$stmt->close();

if (!$kasus) {
    http_response_code(404);
    exit('Kasus tidak ditemukan.');
}

/* Izin akses berbasis kepemilikan (pembuat / PIC / anggota tim / mentor-admin). */
if (!kasus_izin_lihat($conn, $kasus, $user_login, $role_login)) {
    http_response_code(403);
    exit('Anda tidak berhak melihat kasus ini.');
}

$boleh_kelola = kasus_izin_kelola($conn, $kasus, $user_login, $role_login);
$boleh_hapus  = kasus_izin_hapus($conn, $kasus, $user_login, $role_login);

/* ==========================================
 * 3. PROSES TAMBAH RIWAYAT TINDAK LANJUT
 *    Boleh oleh pembuat, PIC, anggota tim, atau mentor/admin.
 * ========================================== */

$pesan = '';
$jenis_pesan = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$boleh_kelola) {
        http_response_code(403);
        exit('Akses ditolak.');
    }

    csrf_cek_atau_matikan('tambah_tindak_lanjut');

    $tindakan = trim((string) ($_POST['tindakan'] ?? ''));
    $hasil    = trim((string) ($_POST['hasil'] ?? ''));
    $kendala  = trim((string) ($_POST['kendala'] ?? ''));
    $rencana  = trim((string) ($_POST['rencana_selanjutnya'] ?? ''));

    if ($tindakan === '') {
        $pesan = 'Tindakan wajib diisi.';
        $jenis_pesan = 'error';
    } elseif (
        mb_strlen($tindakan) > 10000 ||
        mb_strlen($hasil) > 10000 ||
        mb_strlen($kendala) > 10000 ||
        mb_strlen($rencana) > 10000
    ) {
        $pesan = 'Setiap isian maksimal 10.000 karakter.';
        $jenis_pesan = 'error';
    } else {
        $sql_insert = "
            INSERT INTO riwayat_tindak_lanjut (
                kasus_id,
                user_id,
                tindakan,
                hasil,
                kendala,
                rencana_selanjutnya
            )
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $stmt_insert = $conn->prepare($sql_insert);

        if (!$stmt_insert) {
            $pesan = 'Gagal menyiapkan penyimpanan riwayat.';
            $jenis_pesan = 'error';
        } else {
            $stmt_insert->bind_param(
                'iissss',
                $id,
                $user_login,
                $tindakan,
                $hasil,
                $kendala,
                $rencana
            );

            if ($stmt_insert->execute()) {
                $riwayat_baru_id = (int) $conn->insert_id;
                $stmt_insert->close();

                /* Catat perubahan data (audit trail). */
                catat_audit(
                    $conn,
                    'tambah_riwayat',
                    'riwayat_tindak_lanjut',
                    $riwayat_baru_id,
                    'Menambah riwayat tindak lanjut kasus ' . (string) $kasus['kode_kasus'],
                    null,
                    [
                        'kasus_id'            => $id,
                        'tindakan'            => $tindakan,
                        'hasil'               => $hasil,
                        'kendala'             => $kendala,
                        'rencana_selanjutnya' => $rencana,
                    ]
                );

                /* Beri tahu pihak terkait kasus. */
                kasus_notif_pihak($conn, $kasus, 'Tindak lanjut baru pada kasus ' . (string) $kasus['kode_kasus'], $user_login);

                /* PRG: flash + redirect. */
                flash_set('sukses', 'Riwayat tindak lanjut berhasil disimpan.');
                header('Location: detail_kasus.php?id=' . $id);
                exit;
            }

            $pesan = 'Riwayat gagal disimpan. Silakan coba lagi.';
            $jenis_pesan = 'error';
            $stmt_insert->close();
        }
    }
}

/* ==========================================
 * 4. AMBIL DAFTAR PIC
 * ========================================== */

$sql_pic = "
    SELECT DISTINCT pt.*
    FROM kasus_pihak kp
    INNER JOIN pihak_terlibat pt
        ON pt.id = kp.pihak_id
    WHERE kp.kasus_id = ?
";

$daftar_pic = [];
$stmt_pic = $conn->prepare($sql_pic);

if ($stmt_pic) {
    $stmt_pic->bind_param('i', $id);
    $stmt_pic->execute();

    $result_pic = $stmt_pic->get_result();

    while ($pic = $result_pic->fetch_assoc()) {
        $daftar_pic[] = $pic;
    }

    $stmt_pic->close();
}

/* ==========================================
 * 5. AMBIL RIWAYAT TINDAK LANJUT
 * ========================================== */

$daftar_riwayat = [];
$error_riwayat = false;

$sql_riwayat = "
    SELECT
        r.id,
        r.kasus_id,
        r.user_id,
        r.tindakan,
        r.hasil,
        r.kendala,
        r.rencana_selanjutnya,
        r.tanggal_tindak_lanjut,
        u.nama AS nama_pencatat,
        u.role AS role_pencatat
    FROM riwayat_tindak_lanjut r
    LEFT JOIN users u
        ON u.id = r.user_id
    WHERE r.kasus_id = ?
    ORDER BY r.tanggal_tindak_lanjut DESC, r.id DESC
";

$stmt_riwayat = $conn->prepare($sql_riwayat);

if ($stmt_riwayat) {
    $stmt_riwayat->bind_param('i', $id);

    if ($stmt_riwayat->execute()) {
        $result_riwayat = $stmt_riwayat->get_result();

        while ($riwayat = $result_riwayat->fetch_assoc()) {
            $daftar_riwayat[] = $riwayat;
        }
    } else {
        $error_riwayat = true;
    }

    $stmt_riwayat->close();
} else {
    $error_riwayat = true;
}

/* ==========================================
 * 5b. LINIMASA STATUS, TIM, DAN NAMA TERKAIT
 * ========================================== */

$daftar_log = kasus_log_daftar($conn, (int) $id);
$daftar_tim = kasus_penugasan_daftar($conn, (int) $id);

$nama_pembuat = '';
$nama_pic     = '';
$uidPembuat   = (int) ($kasus['dibuat_oleh'] ?? 0);
$uidPic       = (int) ($kasus['penanggung_jawab_id'] ?? 0);
if ($uidPembuat > 0 || $uidPic > 0) {
    $petaNamaKasus = [];
    foreach (kasus_daftar_user($conn) as $u) {
        $petaNamaKasus[(int) $u['id']] = (string) $u['nama'];
    }
    $nama_pembuat = $petaNamaKasus[$uidPembuat] ?? '';
    $nama_pic     = $petaNamaKasus[$uidPic] ?? '';
}
/* PIC bukan pengguna sistem (atau kasus dibuat lewat ketikan bebas):
 * tampilkan nama sebagaimana diketik. */
if (trim($nama_pic) === '') {
    $nama_pic = trim((string) ($kasus['penanggung_jawab_teks'] ?? ''));
}

/* ==========================================
 * 6. VALIDASI KOORDINAT
 * ========================================== */

$lat = null;
$lng = null;

if (
    $kasus['garis_lintang'] !== null &&
    $kasus['garis_bujur'] !== null &&
    is_numeric($kasus['garis_lintang']) &&
    is_numeric($kasus['garis_bujur'])
) {
    $calon_lat = (float) $kasus['garis_lintang'];
    $calon_lng = (float) $kasus['garis_bujur'];

    if (
        $calon_lat >= -90 &&
        $calon_lat <= 90 &&
        $calon_lng >= -180 &&
        $calon_lng <= 180 &&
        !($calon_lat == 0.0 && $calon_lng == 0.0)
    ) {
        $lat = $calon_lat;
        $lng = $calon_lng;
    }
}

$urgensi = (string) ($kasus['tingkat_urgensi'] ?? 'Rendah');
$status  = (string) ($kasus['status_kasus'] ?? 'Terbuka');

$progres = max(0, min(100, (int) ($kasus['progres'] ?? 0)));

$kode_kasus_json = json_encode(
    (string) ($kasus['kode_kasus'] ?? ''),
    JSON_HEX_TAG |
    JSON_HEX_APOS |
    JSON_HEX_QUOT |
    JSON_HEX_AMP
);

if ($kode_kasus_json === false) {
    $kode_kasus_json = '""';
}

/* ==========================================
 * 7. TAMPILAN
 * ========================================== */

$opsi = [
    'judul' => 'Detail Kasus',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => ($boleh_kelola
                ? '<a class="tombol tombol-garis tombol-kecil" href="edit_kasus.php?id=' . (int) $id . '">' . ikon_sp('tulis') . ' Edit</a>'
                : '')
                . ($boleh_hapus
                ? '<a class="tombol tombol-bahaya tombol-kecil" href="hapus_kasus.php?id=' . (int) $id . '">' . ikon_sp('hapus') . ' Hapus</a>'
                : '')
                . '<a class="tombol tombol-garis tombol-kecil" href="dashboard_kasus.php">← Daftar Kasus</a>',
    'head'  => '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
                . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>',
];

mulai_halaman($opsi);
?>

<!-- ================= PESAN VALIDASI ================= -->
<?php if ($pesan !== ''): ?>
    <div class="alert alert-<?= $jenis_pesan === 'success' ? 'sukses' : 'gagal' ?>" role="alert">
        <span class="ikon"><?= $jenis_pesan === 'success' ? '✓' : '✕' ?></span>
        <span><?= e($pesan) ?></span>
    </div>
<?php endif; ?>

<!-- ================= RINGKASAN KASUS ================= -->
<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3><?= e($kasus['judul']) ?></h3>
            <span class="text-redup text-kecil">Kode kasus: <?= e($kasus['kode_kasus']) ?></span>
        </div>
        <span class="badge <?= e(kelas_urgensi($urgensi)) ?>">Urgensi: <?= e($urgensi) ?></span>
        <span class="badge <?= e(kelas_status_kasus($status)) ?>">Status: <?= e(label_status_kasus($status)) ?></span>
    </div>
</section>

<!-- ================= INFORMASI & STATUS ================= -->
<section class="grid grid-2">
    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-kepala"><h3>Informasi Kasus</h3></div>
        <div class="kartu-badan">
            <div class="rincian">
                <div class="baris">
                    <span class="kunci">Deskripsi</span>
                    <span class="nilai teks-konten"><?= e($kasus['deskripsi'] ?: 'Belum ada deskripsi.') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Kategori</span>
                    <span class="nilai"><?= e($kasus['kategori'] ?: 'Belum ditentukan') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Sumber</span>
                    <span class="nilai"><?= e(kasus_label_sumber((string) ($kasus['sumber'] ?? 'Manual'))) ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Pembuat</span>
                    <span class="nilai"><?= e($nama_pembuat !== '' ? $nama_pembuat : 'Tidak diketahui') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Penanggung Jawab</span>
                    <span class="nilai"><?= e($nama_pic !== '' ? $nama_pic : 'Belum ada PIC') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Target Selesai</span>
                    <span class="nilai"><?= e($kasus['target_selesai'] ? tanggal_id((string) $kasus['target_selesai']) : 'Belum ditentukan') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Lokasi</span>
                    <span class="nilai"><?= e($kasus['nama_lokasi'] ?: 'Belum ditentukan') ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Garis Lintang</span>
                    <span class="nilai"><?= $lat !== null ? e($lat) : 'Belum tersedia' ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Garis Bujur</span>
                    <span class="nilai"><?= $lng !== null ? e($lng) : 'Belum tersedia' ?></span>
                </div>
                <div class="baris">
                    <span class="kunci">Anggota Tim</span>
                    <span class="nilai">
                        <?php if ($daftar_tim === []): ?>
                            <span class="text-redup">Belum ada anggota tim</span>
                        <?php else: ?>
                            <?php
                            $namaTim = [];
                            foreach ($daftar_tim as $anggota) {
                                $namaTim[] = (string) ($anggota['nama'] ?? 'Pengguna');
                            }
                            echo e(implode(', ', $namaTim));
                            ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="kartu" style="margin-bottom:0">
        <div class="kartu-kepala"><h3>Status Penanganan</h3></div>
        <div class="kartu-badan">
            <div class="rincian">
                <div class="baris">
                    <span class="kunci">Status Kasus</span>
                    <span class="nilai">
                        <span class="badge <?= e(kelas_status_kasus($status)) ?>">
                            <?= e(label_status_kasus($status)) ?>
                        </span>
                    </span>
                </div>
                <div class="baris">
                    <span class="kunci">Tingkat Urgensi</span>
                    <span class="nilai">
                        <span class="badge <?= e(kelas_urgensi($urgensi)) ?>"><?= e($urgensi) ?></span>
                    </span>
                </div>
                <div class="baris">
                    <span class="kunci">Progres Penanganan</span>
                    <span class="nilai" style="flex:1">
                        <strong><?= $progres ?>%</strong>
                        <div class="progres mt-2" role="progressbar"
                             aria-valuenow="<?= $progres ?>" aria-valuemin="0" aria-valuemax="100"
                             aria-label="Progres penanganan">
                            <span style="width:<?= $progres ?>%"></span>
                        </div>
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ================= DAFTAR PIC ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Penanggung Jawab (PIC)</h3>
            <span class="text-redup text-kecil"><?= count($daftar_pic) ?> pihak terlibat</span>
        </div>
    </div>
    <div class="kartu-badan">
        <?php if (count($daftar_pic) > 0): ?>
            <?php foreach ($daftar_pic as $pic): ?>
                <div class="kartu" style="margin-bottom:14px;box-shadow:none">
                    <div class="kartu-badan">
                        <div class="rincian">
                            <?php foreach ($pic as $nama_kolom => $nilai): ?>
                                <?php
                                if ($nilai === null || trim((string) $nilai) === '') {
                                    continue;
                                }
                                if (in_array(
                                    strtolower((string) $nama_kolom),
                                    ['id', 'created_at', 'updated_at', 'dibuat_pada'],
                                    true
                                )) {
                                    continue;
                                }
                                ?>
                                <div class="baris">
                                    <span class="kunci">
                                        <?= e(ucwords(str_replace('_', ' ', (string) $nama_kolom))) ?>
                                    </span>
                                    <span class="nilai"><?= e($nilai) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="text-redup mb-0">Belum ada PIC yang terhubung dengan kasus ini.</p>
        <?php endif; ?>
    </div>
</section>

<!-- ================= LINIMASA STATUS ================= -->
<section class="kartu" style="margin-top:20px">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Linimasa Status &amp; Progres</h3>
            <span class="text-redup text-kecil">Total catatan: <?= count($daftar_log) ?></span>
        </div>
    </div>
    <div class="kartu-badan">
        <?php if ($daftar_log === []): ?>
            <p class="text-redup mb-0">Belum ada perubahan status yang tercatat.</p>
        <?php else: ?>
            <div class="riwayat-unggah">
                <?php foreach ($daftar_log as $log): ?>
                    <?php $waktuLog = strtotime((string) ($log['dibuat_pada'] ?? '')); ?>
                    <div class="riwayat-item">
                        <div class="utama">
                            <div class="nama">
                                <?php if (!empty($log['status_lama'])): ?>
                                    <?= e((string) $log['status_lama']) ?> &rarr; <?= e((string) $log['status_baru']) ?>
                                <?php else: ?>
                                    <?= e((string) $log['status_baru']) ?>
                                <?php endif; ?>
                                <span class="badge <?= e(kelas_status_kasus((string) $log['status_baru'])) ?>"><?= e(label_status_kasus((string) $log['status_baru'])) ?></span>
                            </div>
                            <div class="meta">
                                Progres <?= (int) $log['progres'] ?>%
                                &middot; oleh <?= e((string) ($log['nama_pengguna'] ?? 'Pengguna')) ?>
                                &middot; <?= $waktuLog !== false ? e(date('d-m-Y H:i', $waktuLog)) : '-' ?>
                                <?php if (!empty($log['catatan'])): ?>
                                    <br>Catatan: <?= e((string) $log['catatan']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- ================= FORM TAMBAH TINDAK LANJUT ================= -->
<?php if ($boleh_kelola): ?>
    <section class="kartu">
        <div class="kartu-kepala">
            <div class="flex-1">
                <h3>Tambah Tindak Lanjut</h3>
                <span class="text-redup text-kecil">
                    Catat perkembangan penanganan untuk kasus <?= e($kasus['kode_kasus']) ?>.
                    Tindakan wajib diisi.
                </span>
            </div>
        </div>

        <form method="POST" action="detail_kasus.php?id=<?= (int) $id ?>">
            <?= csrf_field('tambah_tindak_lanjut') ?>

            <div class="kartu-badan">
                <div class="grup-form">
                    <label class="label-form" for="tindakan">
                        Tindakan yang Dilakukan <span class="wajib">*</span>
                    </label>
                    <textarea id="tindakan" name="tindakan" maxlength="10000" required
                              placeholder="Contoh: Melakukan pemeriksaan awal di lokasi kasus."><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['tindakan'] ?? '') : '') ?></textarea>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="hasil">Hasil Tindak Lanjut</label>
                    <textarea id="hasil" name="hasil" maxlength="10000"
                              placeholder="Jelaskan hasil yang diperoleh."><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['hasil'] ?? '') : '') ?></textarea>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="kendala">Kendala</label>
                    <textarea id="kendala" name="kendala" maxlength="10000"
                              placeholder="Tuliskan kendala, jika ada."><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['kendala'] ?? '') : '') ?></textarea>
                </div>

                <div class="grup-form mb-0">
                    <label class="label-form" for="rencana_selanjutnya">Rencana Selanjutnya</label>
                    <textarea id="rencana_selanjutnya" name="rencana_selanjutnya" maxlength="10000"
                              placeholder="Tuliskan rencana tindak lanjut berikutnya."><?= e($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['rencana_selanjutnya'] ?? '') : '') ?></textarea>
                </div>
            </div>

            <div class="kartu-kaki">
                <button type="submit" class="tombol tombol-utama">Simpan Tindak Lanjut</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <section class="kartu">
        <div class="kartu-badan">
            <div class="alert alert-info mb-0" role="status">
                <span class="ikon"><?= ikon_sp('info') ?></span>
                <span>
                    <strong>Mode lihat saja.</strong>
                    Anda hanya dapat menambah tindak lanjut pada kasus yang Anda
                    buat, yang menjadi tanggung jawab Anda, atau yang Anda ikuti
                    sebagai anggota tim.
                </span>
            </div>
        </div>
    </section>
<?php endif; ?>

<!-- ================= DAFTAR RIWAYAT TINDAK LANJUT ================= -->
<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Riwayat Tindak Lanjut</h3>
            <span class="text-redup text-kecil">Total catatan: <?= count($daftar_riwayat) ?></span>
        </div>
    </div>
    <div class="kartu-badan">
        <?php if ($error_riwayat): ?>
            <div class="alert alert-gagal mb-0">
                <span class="ikon">✕</span>
                <span>
                    Riwayat belum dapat ditampilkan. Periksa koneksi database dan
                    struktur tabel riwayat_tindak_lanjut.
                </span>
            </div>

        <?php elseif (count($daftar_riwayat) > 0): ?>

            <?php foreach ($daftar_riwayat as $riwayat): ?>
                <?php
                $timestamp = strtotime((string) ($riwayat['tanggal_tindak_lanjut'] ?? ''));
                ?>
                <section class="kartu" style="margin-bottom:14px;border-left:4px solid var(--phr-biru);box-shadow:none">
                    <div class="kartu-kepala">
                        <div class="flex-1">
                            <strong>Dicatat oleh: <?= e($riwayat['nama_pencatat'] ?? 'Pengguna tidak diketahui') ?>
                                <?php if (!empty($riwayat['role_pencatat'])): ?>
                                    <span class="text-redup text-kecil">(<?= e(ucfirst((string) $riwayat['role_pencatat'])) ?>)</span>
                                <?php endif; ?>
                            </strong>
                            <span class="text-redup text-kecil">
                                <?= $timestamp !== false ? e(date('d-m-Y H:i', $timestamp)) : 'Waktu tidak tersedia' ?>
                            </span>
                        </div>

                        <?php if ($boleh_kelola): ?>
                            <div class="aksi">
                                <a class="tombol tombol-kecil tombol-garis"
                                   href="edit_riwayat.php?id=<?= (int) $riwayat['id'] ?>">✎ Edit</a>

                                <form action="hapus_riwayat.php" method="POST" style="margin:0"
                                      data-konfirmasi="Apakah kamu yakin ingin menghapus riwayat ini? Data yang dihapus tidak dapat dikembalikan.">
                                    <?= csrf_field('hapus_riwayat') ?>
                                    <input type="hidden" name="riwayat_id" value="<?= (int) $riwayat['id'] ?>">
                                    <button type="submit" class="tombol tombol-kecil tombol-bahaya"><?= ikon_sp('hapus') ?> Hapus</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="kartu-badan">
                        <div class="rincian">
                            <?php if (trim((string) ($riwayat['tindakan'] ?? '')) !== ''): ?>
                                <div class="baris">
                                    <span class="kunci">Tindakan</span>
                                    <span class="nilai teks-konten"><?= e($riwayat['tindakan']) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (trim((string) ($riwayat['hasil'] ?? '')) !== ''): ?>
                                <div class="baris">
                                    <span class="kunci">Hasil Tindak Lanjut</span>
                                    <span class="nilai teks-konten"><?= e($riwayat['hasil']) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (trim((string) ($riwayat['kendala'] ?? '')) !== ''): ?>
                                <div class="baris">
                                    <span class="kunci">Kendala</span>
                                    <span class="nilai teks-konten"><?= e($riwayat['kendala']) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if (trim((string) ($riwayat['rencana_selanjutnya'] ?? '')) !== ''): ?>
                                <div class="baris">
                                    <span class="kunci">Rencana Selanjutnya</span>
                                    <span class="nilai teks-konten"><?= e($riwayat['rencana_selanjutnya']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endforeach; ?>

        <?php else: ?>
            <p class="text-redup mb-0">
                Belum ada riwayat tindak lanjut untuk kasus ini.
                <?php if ($boleh_kelola): ?>
                    Gunakan formulir di atas untuk menambahkan catatan pertama.
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
</section>

<!-- ================= PETA LOKASI ================= -->
<section class="kartu">
    <div class="kartu-kepala"><h3>Lokasi pada Peta</h3></div>
    <div class="kartu-badan">
        <?php if ($lat !== null && $lng !== null): ?>
            <div id="petaDetail" style="width:100%;height:340px;border-radius:10px"></div>

            <p class="text-redup text-kecil mt-2 mb-0">
                Peta menggunakan OpenStreetMap. Pastikan lokasi dan koordinat sudah
                diverifikasi sebelum digunakan untuk kebutuhan operasional.
            </p>
        <?php else: ?>
            <p class="text-redup mb-0">
                Peta belum dapat ditampilkan karena koordinat kasus belum tersedia
                atau tidak valid.
            </p>
        <?php endif; ?>
    </div>
</section>

<?php if ($lat !== null && $lng !== null): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof L === 'undefined') return;

    const latitude = <?= json_encode($lat) ?>;
    const longitude = <?= json_encode($lng) ?>;
    const kodeKasus = <?= $kode_kasus_json ?>;

    const map = L.map('petaDetail').setView([latitude, longitude], 15);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const marker = L.marker([latitude, longitude]).addTo(map);

    const popupContent = document.createElement('div');
    popupContent.textContent = kodeKasus;

    marker.bindPopup(popupContent).openPopup();
});
</script>
<?php endif; ?>

<?php akhiri_halaman(); ?>
