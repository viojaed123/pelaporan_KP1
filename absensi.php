<?php
/**
 * absensi.php — pencatatan kehadiran karyawan.
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + penjaga sesi terpusat (semula session_start manual)
 *   · layout terpusat — <style> inline dihapus
 *   · CSRF per-aksi (csrf_field / csrf_cek_atau_matikan)
 *   · PERBAIKAN BUG: hash_equals() tanpa is_string() bisa memicu TypeError
 *     (HTTP 500); kini verifikasi memakai csrf_cek_atau_matikan() yang
 *     memeriksa is_string() sebelum hash_equals().
 *   · INSERT ... ON DUPLICATE KEY UPDATE dipertahankan (tidak ada duplikat).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();
$nama    = sesi_nama();
$role    = sesi_role();

// Mentor & admin memakai halaman pemantauan absensi.
if ($role === 'mentor' || $role === 'admin') {
    header('Location: mentor_absensi.php');
    exit;
}

date_default_timezone_set('Asia/Jakarta');

$tanggal_hari_ini = date('Y-m-d');

/** Ambil id baris absensi tertentu (dipakai untuk audit trail). */
function ambil_id_absensi(mysqli $conn, int $userId, string $tanggal): ?int
{
    $stmt = $conn->prepare(
        'SELECT id FROM absensi WHERE user_id = ? AND tanggal = ? LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('is', $userId, $tanggal);
    $stmt->execute();
    $stmt->bind_result($id);
    $ada = $stmt->fetch();
    $stmt->close();

    return $ada ? (int) $id : null;
}

/* ---------------------------------------------------------------
 * Proses absensi masuk & pulang
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verifikasi CSRF. Aman terhadap nilai non-string (perbaikan bug).
    csrf_cek_atau_matikan('absensi');

    $aksi = $_POST['aksi'] ?? '';
    if (!is_string($aksi)) {
        $aksi = '';
    }

    /* Kegiatan hari ini & kondisi kesehatan (dipakai aksi masuk/ubah_kegiatan). */
    $kegiatan = trim((string) ($_POST['kegiatan'] ?? ''));
    if (mb_strlen($kegiatan) > 2000) {
        $kegiatan = mb_substr($kegiatan, 0, 2000);
    }
    $kegiatanDb = $kegiatan === '' ? null : $kegiatan;
    $kondisiDb  = kondisi_kesehatan_valid($_POST['kondisi_kesehatan'] ?? 'sehat');

    /* Lokasi absensi (opsional): teks alamat + koordinat (GPS / klik peta). */
    $lokasi_teks = trim((string) ($_POST['lokasi_teks'] ?? ''));
    if (mb_strlen($lokasi_teks) > 191) {
        $lokasi_teks = mb_substr($lokasi_teks, 0, 191);
    }
    $garis_lintang = trim((string) ($_POST['garis_lintang'] ?? ''));
    $garis_bujur   = trim((string) ($_POST['garis_bujur'] ?? ''));
    if (!wilayah_koordinat_valid($garis_lintang === '' ? null : $garis_lintang, $garis_bujur === '' ? null : $garis_bujur)) {
        $garis_lintang = '';
        $garis_bujur   = '';
    } else {
        $garis_lintang = number_format((float) $garis_lintang, 7, '.', '');
        $garis_bujur   = number_format((float) $garis_bujur, 7, '.', '');
    }
    $wilayah_id = 0;
    if ($lokasi_teks !== '') {
        $w = wilayah_cari_teks($conn, $lokasi_teks);
        if ($w !== null) {
            $wilayah_id = (int) $w['id'];
            if ($garis_lintang === '' && wilayah_koordinat_valid($w['garis_lintang'], $w['garis_bujur'])) {
                $garis_lintang = number_format((float) $w['garis_lintang'], 7, '.', '');
                $garis_bujur   = number_format((float) $w['garis_bujur'], 7, '.', '');
            }
        }
    }
    $wilayahDb  = $wilayah_id > 0 ? (string) $wilayah_id : '';
    $lokasiDb   = $lokasi_teks;
    $garisLatDb = $garis_lintang;
    $garisLngDb = $garis_bujur;

    /** Nilai kegiatan/kondisi yang tersimpan saat ini (untuk jejak audit). */
    $nilaiLama = static function () use ($conn, $user_id, $tanggal_hari_ini): array {
        $stmt = $conn->prepare(
            'SELECT kegiatan_hari_ini, kondisi_kesehatan
               FROM absensi WHERE user_id = ? AND tanggal = ? LIMIT 1'
        );
        if (!$stmt) {
            return ['kegiatan_hari_ini' => null, 'kondisi_kesehatan' => null];
        }
        $stmt->bind_param('is', $user_id, $tanggal_hari_ini);
        $stmt->execute();
        $res = $stmt->get_result();
        $baris = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        return $baris ?: ['kegiatan_hari_ini' => null, 'kondisi_kesehatan' => null];
    };

    if ($aksi === 'masuk') {
        $stmt = $conn->prepare(
            'INSERT INTO absensi
                (user_id, tanggal, jam_masuk, status, kegiatan_hari_ini, kondisi_kesehatan,
                 lokasi_teks, garis_lintang, garis_bujur, wilayah_id)
             VALUES (?, ?, CURTIME(), \'hadir\', ?, ?,
                     NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))
             ON DUPLICATE KEY UPDATE
                jam_masuk = IF(jam_masuk IS NULL, CURTIME(), jam_masuk),
                kegiatan_hari_ini = COALESCE(VALUES(kegiatan_hari_ini), kegiatan_hari_ini),
                kondisi_kesehatan = COALESCE(VALUES(kondisi_kesehatan), kondisi_kesehatan),
                lokasi_teks = COALESCE(VALUES(lokasi_teks), lokasi_teks),
                garis_lintang = COALESCE(VALUES(garis_lintang), garis_lintang),
                garis_bujur = COALESCE(VALUES(garis_bujur), garis_bujur),
                wilayah_id = COALESCE(VALUES(wilayah_id), wilayah_id)'
        );

        if ($stmt) {
            $lamaKegiatan   = $nilaiLama();
            $stmt->bind_param(
                'isssssss',
                $user_id,
                $tanggal_hari_ini,
                $kegiatanDb,
                $kondisiDb,
                $lokasiDb,
                $garisLatDb,
                $garisLngDb,
                $wilayahDb
            );
            $stmt->execute();
            $terpengaruh = $stmt->affected_rows;
            $stmt->close();

            if ($terpengaruh === 1) {
                catat_audit(
                    $conn,
                    'absensi_masuk',
                    'absensi',
                    ambil_id_absensi($conn, $user_id, $tanggal_hari_ini),
                    'Absensi masuk dicatat',
                    null,
                    [
                        'kegiatan_hari_ini'   => $kegiatanDb,
                        'kondisi_kesehatan'   => $kondisiDb,
                    ]
                );
                flash_set('sukses', 'Absensi masuk berhasil dicatat.');
            } elseif ($terpengaruh === 0) {
                flash_set('info', 'Absensi masuk sudah tercatat sebelumnya.');
            } else {
                catat_audit(
                    $conn,
                    'absensi_masuk',
                    'absensi',
                    ambil_id_absensi($conn, $user_id, $tanggal_hari_ini),
                    'Jam masuk dilengkapi',
                    $lamaKegiatan,
                    [
                        'kegiatan_hari_ini' => $kegiatanDb,
                        'kondisi_kesehatan' => $kondisiDb,
                    ]
                );
                flash_set('sukses', 'Absensi diproses. Periksa jam masuk hari ini.');
            }
        } else {
            flash_set('gagal', 'Terjadi kesalahan saat menyiapkan absensi.');
        }
    } elseif ($aksi === 'ubah_kegiatan') {
        $idAbsen = ambil_id_absensi($conn, $user_id, $tanggal_hari_ini);

        if ($idAbsen === null) {
            flash_set('gagal', 'Belum ada absensi hari ini. Silakan Absen Masuk terlebih dahulu.');
        } else {
            $stmt = $conn->prepare(
                'UPDATE absensi
                    SET kegiatan_hari_ini = ?, kondisi_kesehatan = ?,
                        lokasi_teks = NULLIF(?, \'\'),
                        garis_lintang = NULLIF(?, \'\'),
                        garis_bujur = NULLIF(?, \'\'),
                        wilayah_id = NULLIF(?, \'\')
                  WHERE id = ?'
            );

            if ($stmt) {
                $stmt->bind_param(
                    'ssssssi',
                    $kegiatanDb,
                    $kondisiDb,
                    $lokasiDb,
                    $garisLatDb,
                    $garisLngDb,
                    $wilayahDb,
                    $idAbsen
                );
                $stmt->execute();
                $terpengaruh = $stmt->affected_rows;
                $stmt->close();

                catat_audit(
                    $conn,
                    'ubah_kegiatan_absensi',
                    'absensi',
                    $idAbsen,
                    'Kegiatan/kondisi kesehatan diperbarui',
                    $nilaiLama(),
                    [
                        'kegiatan_hari_ini' => $kegiatanDb,
                        'kondisi_kesehatan' => $kondisiDb,
                        'lokasi_teks'       => $lokasiDb,
                    ]
                );

                flash_set(
                    $terpengaruh > 0 ? 'sukses' : 'info',
                    $terpengaruh > 0
                        ? 'Kegiatan hari ini dan kondisi kesehatan tersimpan.'
                        : 'Kegiatan dan kondisi kesehatan tidak berubah.'
                );
            } else {
                flash_set('gagal', 'Terjadi kesalahan saat menyimpan kegiatan.');
            }
        }
    } elseif ($aksi === 'pulang') {
        $stmt = $conn->prepare(
            "UPDATE absensi
             SET jam_pulang = CURTIME()
             WHERE user_id = ?
               AND tanggal = ?
               AND jam_masuk IS NOT NULL
               AND jam_pulang IS NULL"
        );

        if ($stmt) {
            $stmt->bind_param('is', $user_id, $tanggal_hari_ini);
            $stmt->execute();
            $terpengaruh = $stmt->affected_rows;
            $stmt->close();

            if ($terpengaruh > 0) {
                catat_audit(
                    $conn,
                    'absensi_pulang',
                    'absensi',
                    ambil_id_absensi($conn, $user_id, $tanggal_hari_ini),
                    'Absensi pulang dicatat'
                );
                flash_set('sukses', 'Absensi pulang berhasil dicatat.');
            } else {
                flash_set(
                    'gagal',
                    'Absen masuk belum tercatat atau absensi pulang sudah dilakukan.'
                );
            }
        } else {
            flash_set('gagal', 'Terjadi kesalahan saat menyiapkan absensi pulang.');
        }
    } else {
        flash_set('gagal', 'Aksi tidak dikenali.');
    }

    header('Location: absensi.php');
    exit;
}

/* ---------------------------------------------------------------
 * Ambil absensi hari ini
 * ------------------------------------------------------------- */
$absensi_hari_ini = null;
$stmt = $conn->prepare(
    'SELECT jam_masuk, jam_pulang, status, keterangan,
            kegiatan_hari_ini, kondisi_kesehatan,
            lokasi_teks, garis_lintang, garis_bujur
     FROM absensi
     WHERE user_id = ? AND tanggal = ?'
);
if ($stmt) {
    $stmt->bind_param('is', $user_id, $tanggal_hari_ini);
    $stmt->execute();
    $res = $stmt->get_result();
    $absensi_hari_ini = $res ? $res->fetch_assoc() : null;
    $stmt->close();
}

/* ---------------------------------------------------------------
 * Riwayat absensi terbaru (maksimal 30)
 * ------------------------------------------------------------- */
$riwayat = [];
$stmt = $conn->prepare(
    'SELECT tanggal, jam_masuk, jam_pulang, status, keterangan,
            kegiatan_hari_ini, kondisi_kesehatan,
            lokasi_teks, garis_lintang, garis_bujur
     FROM absensi
     WHERE user_id = ?
     ORDER BY tanggal DESC
     LIMIT 30'
);
if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($baris = $res->fetch_assoc())) {
        $riwayat[] = $baris;
    }
    $stmt->close();
}

/* Nilai terisi untuk formulir kegiatan & kesehatan. */
$kegiatanKini = (string) ($absensi_hari_ini['kegiatan_hari_ini'] ?? '');
$kondisiKini  = kondisi_kesehatan_valid($absensi_hari_ini['kondisi_kesehatan'] ?? 'sehat');

/* Nilai terisi untuk lokasi (teks + koordinat). */
$lokasiKini = (string) ($absensi_hari_ini['lokasi_teks'] ?? '');
$latKini    = ($absensi_hari_ini['garis_lintang'] ?? null) !== null
    ? (string) $absensi_hari_ini['garis_lintang'] : '';
$lngKini    = ($absensi_hari_ini['garis_bujur'] ?? null) !== null
    ? (string) $absensi_hari_ini['garis_bujur'] : '';

mulai_halaman([
    'judul' => 'Absensi',
    'crumb' => 'Karyawan',
    'aksi'  => '<a class="tombol tombol-kecil" href="dashboard.php">▦ Dashboard</a>',
    'head'  => '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
             . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>',
]);
?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Absensi Hari Ini</h3>
            <span class="text-redup text-kecil">
                <?= e(date('l, d F Y')) ?> · halo, <?= e($nama) ?>.
            </span>
        </div>
    </div>
    <div class="kartu-badan">
        <div style="font-size:34px;font-weight:700;color:var(--phr-biru);letter-spacing:1px"
             id="jamSekarang">--:--:--</div>
        <p class="text-redup text-kecil">
            Jam di atas mengikuti waktu browser. Pencatatan absensi memakai waktu server database.
        </p>

        <div class="d-flex gap-2 flex-wrap" style="margin:14px 0 6px">
            <span class="badge <?= $absensi_hari_ini
                ? 'absensi-' . e(strtolower(trim((string) $absensi_hari_ini['status'])))
                : 'badge-kuning' ?>">
                <?= $absensi_hari_ini
                    ? e(label_status_absensi((string) $absensi_hari_ini['status']))
                    : 'Belum absen' ?>
            </span>
            <span class="badge badge-navy">
                Masuk: <?= e(($absensi_hari_ini['jam_masuk'] ?? null) ?: 'Belum tercatat') ?>
            </span>
            <span class="badge badge-navy">
                Pulang: <?= e(($absensi_hari_ini['jam_pulang'] ?? null) ?: 'Belum tercatat') ?>
            </span>
            <span class="badge <?= e(badge_kondisi_kesehatan($kondisiKini)) ?>">
                <?= ikon_sp('kesehatan') ?> <?= e(label_kondisi_kesehatan($kondisiKini)) ?>
            </span>
        </div>

        <form method="post" style="margin-top:16px">
            <?= csrf_field('absensi') ?>

            <div class="grup-form">
                <label for="kegiatan">Kegiatan hari ini</label>
                <textarea id="kegiatan" name="kegiatan" rows="3" maxlength="2000"
                          placeholder="Ringkasan pekerjaan hari ini, mis. memperbaiki modul laporan, rapat koordinasi pukul 10.00, ..."><?= e($kegiatanKini) ?></textarea>
                <span class="bantuan">
                    Opsional. Bantu mentor memahami beban kerja harian Anda (maks. 2000 karakter).
                </span>
            </div>

            <div class="grup-form">
                <label for="kondisi_kesehatan">Kondisi kesehatan</label>
                <select id="kondisi_kesehatan" name="kondisi_kesehatan">
                    <?php foreach (daftar_kondisi_kesehatan() as $nilai => $label): ?>
                        <option value="<?= e($nilai) ?>" <?= $kondisiKini === $nilai ? 'selected' : '' ?>>
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="bantuan">
                    Swa-laporan kondisi Anda saat absen. Dipakai mentor untuk menyesuaikan penugasan.
                </span>
            </div>

            <div class="grup-form">
                <label for="lokasi_teks">Lokasi absen <span class="text-redup text-kecil">(opsional)</span></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" id="lokasi_teks" name="lokasi_teks" maxlength="191"
                           value="<?= e($lokasiKini) ?>" style="flex:1;min-width:220px"
                           placeholder="Contoh: Kantor PHR, Dumai / Desa Buluh Manis">
                    <button type="button" class="tombol tombol-garis" id="tombolGps"><?= ikon_sp('penanda') ?> Ambil lokasi saya</button>
                </div>
                <input type="hidden" id="garis_lintang" name="garis_lintang" value="<?= e($latKini) ?>">
                <input type="hidden" id="garis_bujur" name="garis_bujur" value="<?= e($lngKini) ?>">
                <div id="petaMini" style="height:200px;margin-top:8px;border-radius:8px;border:1px solid var(--abu-200)"></div>
                <span class="bantuan" id="statusLokasi">
                    Ketik nama tempat, klik peta, atau tekan "Ambil lokasi saya" untuk menandai titik lokasi.
                </span>
                <a class="tautan-lokasi" id="tautanLokasi" href="#" target="_blank" rel="noopener"
                   style="display:none"><?= ikon_sp('peta') ?> Cek Lokasi (Google Maps)</a>
            </div>

            <div class="d-flex gap-2 flex-wrap" style="margin-top:16px">
                <button class="tombol tombol-utama" type="submit" name="aksi" value="masuk"
                    <?= ($absensi_hari_ini && $absensi_hari_ini['jam_masuk'] !== null) ? 'disabled' : '' ?>>
                    <?= ikon_sp('jam') ?> Absen Masuk
                </button>

                <button class="tombol tombol-bahaya" type="submit" name="aksi" value="pulang"
                    <?= (!$absensi_hari_ini || $absensi_hari_ini['jam_masuk'] === null || $absensi_hari_ini['jam_pulang'] !== null) ? 'disabled' : '' ?>>
                    <?= ikon_sp('jam') ?> Absen Pulang
                </button>

                <button class="tombol tombol-garis" type="submit" name="aksi" value="ubah_kegiatan">
                    <?= ikon_sp('floppy') ?> Simpan Kegiatan &amp; Kesehatan
                </button>
            </div>
        </form>
    </div>
</section>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Riwayat Absensi</h3>
            <span class="text-redup text-kecil">Menampilkan maksimal 30 catatan terbaru.</span>
        </div>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Status</th>
                        <th>Jam Masuk</th>
                        <th>Jam Pulang</th>
                        <th>Kesehatan</th>
                        <th>Kegiatan hari ini</th>
                        <th>Lokasi</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($riwayat) === 0): ?>
                    <tr>
                        <td colspan="8" class="tabel-kosong">
                            <span class="ikon"><?= ikon_sp('kalender') ?></span>
                            Belum ada riwayat absensi.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($riwayat as $baris): ?>
                        <tr>
                            <td class="text-kecil text-redup">
                                <?= e(date('d-m-Y', strtotime((string) $baris['tanggal']))) ?>
                            </td>
                            <td>
                                <span class="badge absensi-<?= e(strtolower(trim((string) $baris['status']))) ?>">
                                    <?= e(label_status_absensi((string) $baris['status'])) ?>
                                </span>
                            </td>
                            <td><?= e($baris['jam_masuk'] ?? '-') ?></td>
                            <td><?= e($baris['jam_pulang'] ?? '-') ?></td>
                            <td>
                                <span class="badge <?= e(badge_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? ''))) ?>">
                                    <?= e(label_kondisi_kesehatan((string) ($baris['kondisi_kesehatan'] ?? ''))) ?>
                                </span>
                            </td>
                            <td class="text-kecil" style="max-width:320px;white-space:pre-wrap">
                                <?= e(($baris['kegiatan_hari_ini'] ?? '') ?: '-') ?>
                            </td>
                            <td class="text-kecil">
                                <?php
                                $lteks = trim((string) ($baris['lokasi_teks'] ?? ''));
                                $llat  = $baris['garis_lintang'] ?? null;
                                $llng  = $baris['garis_bujur'] ?? null;
                                if (wilayah_koordinat_valid($llat, $llng)) {
                                    $uPeta = wilayah_titik_url((float) $llat, (float) $llng);
                                } else {
                                    $uPeta = $lteks !== '' ? wilayah_peta_url($lteks) : '';
                                }
                                ?>
                                <?= e($lteks !== '' ? $lteks : '-') ?>
                                <?php if ($uPeta !== ''): ?>
                                    <br><a class="tautan-lokasi" href="<?= e($uPeta) ?>" target="_blank"
                                       rel="noopener"><?= ikon_sp('peta') ?> Cek Lokasi</a>
                                <?php endif; ?>
                            </td>
                            <td><?= e($baris['keterangan'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
function perbaruiJam() {
    const sekarang = new Date();
    const el = document.getElementById('jamSekarang');
    if (el) {
        el.textContent = sekarang.toLocaleTimeString('id-ID', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hour12: false
        });
    }
}
perbaruiJam();
setInterval(perbaruiJam, 1000);
</script>

<style>
.tautan-lokasi { display:inline-block; margin-top:4px; font-size:12px; font-weight:600; color:var(--phr-biru); text-decoration:none; }
.tautan-lokasi:hover { text-decoration:underline; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var elPeta = document.getElementById('petaMini');
    if (!elPeta) { return; }
    if (typeof L === 'undefined') { elPeta.style.display = 'none'; return; }

    var latEl = document.getElementById('garis_lintang');
    var lngEl = document.getElementById('garis_bujur');
    var teksEl = document.getElementById('lokasi_teks');
    var statusEl = document.getElementById('statusLokasi');
    var linkEl = document.getElementById('tautanLokasi');

    var lat0 = parseFloat(latEl.value);
    var lng0 = parseFloat(lngEl.value);
    var ada = isFinite(lat0) && isFinite(lng0);
    var pusat = ada ? [lat0, lng0] : [1.28, 101.21];

    var peta = L.map(elPeta).setView(pusat, ada ? 15 : 8);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19, attribution: '&copy; OpenStreetMap'
    }).addTo(peta);

    var marker = null;

    function perbaruiTautan() {
        if (latEl.value !== '' && lngEl.value !== '') {
            linkEl.href = 'https://www.google.com/maps/search/?api=1&query='
                + encodeURIComponent(latEl.value + ',' + lngEl.value);
            linkEl.style.display = 'inline-block';
        } else if (teksEl.value.trim() !== '') {
            linkEl.href = 'https://www.google.com/maps/search/?api=1&query='
                + encodeURIComponent(teksEl.value.trim() + ', Riau');
            linkEl.style.display = 'inline-block';
        } else {
            linkEl.style.display = 'none';
        }
    }

    function setTitik(lat, lng) {
        latEl.value = lat.toFixed(7);
        lngEl.value = lng.toFixed(7);
        if (marker) { peta.removeLayer(marker); }
        marker = L.circleMarker([lat, lng], {
            radius: 9, color: '#003D7D', fillColor: '#005EB8', fillOpacity: 0.9, weight: 2
        }).addTo(peta);
        peta.setView([lat, lng], 15);
        statusEl.textContent = 'Titik lokasi: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
        perbaruiTautan();
    }

    if (ada) { setTitik(lat0, lng0); }
    peta.on('click', function (e) { setTitik(e.latlng.lat, e.latlng.lng); });

    var btn = document.getElementById('tombolGps');
    if (btn) {
        btn.addEventListener('click', function () {
            if (!navigator.geolocation) {
                statusEl.textContent = 'Peramban ini tidak mendukung geolokasi.';
                return;
            }
            statusEl.textContent = 'Mengambil lokasi...';
            navigator.geolocation.getCurrentPosition(function (pos) {
                setTitik(pos.coords.latitude, pos.coords.longitude);
            }, function () {
                statusEl.textContent = 'Gagal mengambil lokasi. Ketik alamat atau klik peta '
                    + '(GPS hanya aktif pada HTTPS/localhost).';
            }, { enableHighAccuracy: true, timeout: 10000 });
        });
    }

    if (teksEl) {
        teksEl.addEventListener('input', function () {
            if (latEl.value === '') { perbaruiTautan(); }
        });
    }
    perbaruiTautan();
});
</script>

<?php akhiri_halaman(); ?>
