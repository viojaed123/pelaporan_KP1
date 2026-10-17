<?php
/**
 * buat_laporan.php — membuat laporan harian (disimpan sebagai draft).
 *
 * Pembaruan dari versi lama:
 *   · bootstrap + wajib_login() (semula session_start() manual)
 *   · layout terpusat — <style> inline dihapus, memakai kelas app.css
 *   · validasi terpusat validasi_laporan() — jam ketat 00:00–23:59
 *     tanggal tervalidasi, panjang maksimal (semula regex jam lemah)
 *   · token CSRF per-aksi (semula tidak ada CSRF sama sekali)
 *   · batas unggahan memakai konstanta UPLOAD_* di includes/config.php
 *   · flash + PRG + catat_audit() setelah data tersimpan
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$user_id = (int) sesi_user_id();

$galat = [];      // galat per kolom
$pesan = '';      // pesan umum (kegagalan transaksi)

$tanggal            = date('Y-m-d');
$jam_mulai          = '';
$jam_selesai        = '';
$kegiatan           = '';
$deskripsi          = '';
$hasil              = '';
$kendala            = '';
$rencana_berikutnya = '';
$lokasi_teks        = '';
$garis_lintang      = '';
$garis_bujur        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('buat_laporan');

    /* Ambil seluruh isian. Nama field 'rencana_berikutnya' dipakai konsisten. */
    $post = [
        'tanggal'            => (string) ($_POST['tanggal'] ?? ''),
        'jam_mulai'          => (string) ($_POST['jam_mulai'] ?? ''),
        'jam_selesai'        => (string) ($_POST['jam_selesai'] ?? ''),
        'kegiatan'           => (string) ($_POST['kegiatan'] ?? ''),
        'deskripsi'          => (string) ($_POST['deskripsi'] ?? ''),
        'hasil'              => (string) ($_POST['hasil'] ?? ''),
        'kendala'            => (string) ($_POST['kendala'] ?? ''),
        'rencana_berikutnya' => (string) ($_POST['rencana_berikutnya'] ?? ($_POST['rencana'] ?? '')),
    ];

    /* Validasi terpusat (tanggal, jam, panjang teks). */
    $validasi = validasi_laporan($post, true);
    $galat    = $validasi['galat'];
    $bersih   = $validasi['bersih'];

    /* Isi ulang formulir (gunakan nilai bersih bila ada, jika tidak nilai mentah). */
    $tanggal            = (string) ($bersih['tanggal']            ?? $post['tanggal']);
    $jam_mulai          = (string) ($bersih['jam_mulai']          ?? $post['jam_mulai']);
    $jam_selesai        = (string) ($bersih['jam_selesai']        ?? $post['jam_selesai']);
    $kegiatan           = (string) ($bersih['kegiatan']           ?? $post['kegiatan']);
    $deskripsi          = (string) ($bersih['deskripsi']          ?? $post['deskripsi']);
    $hasil              = (string) ($bersih['hasil']              ?? $post['hasil']);
    $kendala            = (string) ($bersih['kendala']            ?? $post['kendala']);
    $rencana_berikutnya = (string) ($bersih['rencana_berikutnya'] ?? $post['rencana_berikutnya']);

    /* --------------------------------------------------------------
     * Lokasi kegiatan (opsional): teks alamat + koordinat (GPS/peta)
     * ------------------------------------------------------------ */
    $lokasi_teks = trim((string) ($_POST['lokasi_teks'] ?? ''));
    if (mb_strlen($lokasi_teks) > 191) {
        $galat['lokasi_teks'] = 'Lokasi maksimal 191 karakter.';
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

    /* --------------------------------------------------------------
     * Validasi unggahan (maks 5 file, JPEG/PNG, ≤ 5 MB per file)
     * ------------------------------------------------------------ */
    $fileAda = isset($_FILES['bukti'])
        && isset($_FILES['bukti']['name'])
        && is_array($_FILES['bukti']['name']);

    $jumlahFoto = 0;
    if ($fileAda) {
        foreach ($_FILES['bukti']['name'] as $nama) {
            if ($nama !== '') {
                $jumlahFoto++;
            }
        }
    }

    $fotoValid    = [];
    $folderUpload = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';

    if ($jumlahFoto > UPLOAD_MAKS_JUMLAH) {
        $galat['bukti'] = 'Maksimal ' . UPLOAD_MAKS_JUMLAH . ' foto untuk setiap laporan.';
    } elseif ($jumlahFoto > 0) {
        if (!is_dir($folderUpload) || !is_writable($folderUpload)) {
            $galat['bukti'] = 'Folder uploads belum tersedia atau tidak dapat ditulisi.';
        } elseif (!extension_loaded('fileinfo')) {
            $galat['bukti'] = 'Ekstensi PHP Fileinfo belum aktif.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);

            foreach ($_FILES['bukti']['name'] as $i => $namaAsli) {
                if ($namaAsli === '') {
                    continue;
                }

                $errorUpload = $_FILES['bukti']['error'][$i];
                $ukuran      = (int) $_FILES['bukti']['size'][$i];
                $tmp         = (string) $_FILES['bukti']['tmp_name'][$i];

                if ($errorUpload !== UPLOAD_ERR_OK) {
                    $galat['bukti'] = 'Ada foto yang gagal diunggah.';
                    break;
                }

                if (!is_uploaded_file($tmp) || $ukuran <= 0 || $ukuran > UPLOAD_MAKS_BYTES) {
                    $galat['bukti'] = 'Setiap foto harus berukuran maksimal '
                        . (int) (UPLOAD_MAKS_BYTES / 1048576) . ' MB.';
                    break;
                }

                $mime = $finfo->file($tmp);

                if (!in_array($mime, UPLOAD_MIME, true)) {
                    $galat['bukti'] = 'Format foto harus JPG, JPEG, atau PNG.';
                    break;
                }

                $infoGambar = @getimagesize($tmp);
                if ($infoGambar === false || $infoGambar['mime'] !== $mime) {
                    $galat['bukti'] = 'Ada file yang bukan gambar valid.';
                    break;
                }

                /* Pola nama file lama dipertahankan: 32 karakter heksadesimal. */
                $ekstensi = $mime === 'image/png' ? 'png' : 'jpg';

                $fotoValid[] = [
                    'tmp'       => $tmp,
                    'nama'      => bin2hex(random_bytes(16)) . '.' . $ekstensi,
                    'nama_asli' => basename((string) $namaAsli),
                    'mime'      => $mime,
                    'ukuran'    => $ukuran,
                ];
            }
        }
    }

    /* --------------------------------------------------------------
     * Simpan (transaksi)
     * ------------------------------------------------------------ */
    if ($galat === []) {
        $conn->begin_transaction();

        $fileTersimpan = [];
        $transaksiOK   = false;
        $laporan_id    = 0;

        try {
            $stmt = $conn->prepare(
                'INSERT INTO laporan_harian
                 (user_id, tanggal, jam_mulai, jam_selesai,
                  kegiatan, lokasi_teks, garis_lintang, garis_bujur, wilayah_id,
                  deskripsi, hasil, kendala, rencana_berikutnya)
                 VALUES (?, ?, NULLIF(?, \'\'), NULLIF(?, \'\'),
                         ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'),
                         ?, NULLIF(?, \'\'), NULLIF(?, \'\'), NULLIF(?, \'\'))'
            );

            if (!$stmt) {
                throw new Exception('Gagal menyiapkan penyimpanan laporan.');
            }

            $wilayahDb = $wilayah_id > 0 ? (string) $wilayah_id : '';

            $stmt->bind_param(
                'issssssssssss',
                $user_id,
                $tanggal,
                $jam_mulai,
                $jam_selesai,
                $kegiatan,
                $lokasi_teks,
                $garis_lintang,
                $garis_bujur,
                $wilayahDb,
                $deskripsi,
                $hasil,
                $kendala,
                $rencana_berikutnya
            );

            if (!$stmt->execute()) {
                throw new Exception('Gagal menyimpan laporan.');
            }

            $laporan_id = (int) $conn->insert_id;
            $stmt->close();

            if ($fotoValid !== []) {
                $stmtFoto = $conn->prepare(
                    'INSERT INTO bukti_gambar
                     (laporan_id, nama_file, nama_asli, tipe_mime, ukuran_bytes)
                     VALUES (?, ?, ?, ?, ?)'
                );

                if (!$stmtFoto) {
                    throw new Exception('Gagal menyiapkan penyimpanan foto.');
                }

                foreach ($fotoValid as $foto) {
                    $tujuan = $folderUpload . DIRECTORY_SEPARATOR . $foto['nama'];

                    if (!move_uploaded_file($foto['tmp'], $tujuan)) {
                        throw new Exception('Gagal menyimpan salah satu foto.');
                    }

                    $fileTersimpan[] = $tujuan;

                    $namaFile = $foto['nama'];
                    $namaAsli = $foto['nama_asli'];
                    $mime     = $foto['mime'];
                    $ukuran   = $foto['ukuran'];

                    $stmtFoto->bind_param(
                        'isssi',
                        $laporan_id,
                        $namaFile,
                        $namaAsli,
                        $mime,
                        $ukuran
                    );

                    if (!$stmtFoto->execute()) {
                        throw new Exception('Gagal menyimpan informasi foto.');
                    }
                }

                $stmtFoto->close();
            }

            $conn->commit();
            $transaksiOK = true;
        } catch (Throwable $err) {
            $conn->rollback();

            foreach ($fileTersimpan as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }

            error_log('Simpan laporan gagal: ' . $err->getMessage());
            $pesan = 'Laporan atau foto gagal disimpan. Periksa konfigurasi dan coba kembali.';
        }

        if ($transaksiOK) {
            catat_audit(
                $conn,
                'buat_laporan',
                'laporan_harian',
                $laporan_id,
                'Membuat laporan harian (draft)',
                null,
                [
                    'tanggal'     => $tanggal,
                    'status'      => 'draft',
                    'jumlah_foto' => count($fotoValid),
                ]
            );

            flash_set(
                'sukses',
                'Laporan dan ' . count($fotoValid) . ' foto berhasil disimpan sebagai draft.'
            );
            header('Location: riwayat.php');
            exit;
        }
    }
}
mulai_halaman([
    'judul' => 'Buat Laporan',
    'crumb' => 'Karyawan',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="riwayat.php">☰ Riwayat</a>',
    'head'  => '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">'
             . '<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>',
]);
?>

<?php if ($pesan !== ''): ?>
    <div class="alert alert-gagal" role="alert">
        <span class="ikon">✕</span>
        <span><?= e($pesan) ?></span>
    </div>
<?php endif; ?>

<section class="kartu">
    <div class="kartu-header">
        <div class="flex-1">
            <h2>Buat Laporan Kegiatan</h2>
            <span class="text-redup text-kecil">
                Isi kegiatan yang benar-benar kamu lakukan. Laporan disimpan sebagai draft dan
                dapat dikirim ke mentor dari halaman detail.
            </span>
        </div>
    </div>

    <div class="kartu-tubuh">
        <form method="POST" action="buat_laporan.php" enctype="multipart/form-data">

            <?= csrf_field('buat_laporan') ?>

            <div class="form-grup">
                <label class="label" for="tanggal">Tanggal kegiatan <span class="wajib">*</span></label>
                <input type="date" id="tanggal" name="tanggal"
                       class="<?= isset($galat['tanggal']) ? 'galat' : '' ?>"
                       value="<?= e($tanggal) ?>" required>
                <?php if (isset($galat['tanggal'])): ?>
                    <span class="pesan-galat"><?= e($galat['tanggal']) ?></span>
                <?php endif; ?>
            </div>

            <div class="baris">
                <div class="form-grup">
                    <label class="label" for="jam_mulai">Jam mulai</label>
                    <input type="time" id="jam_mulai" name="jam_mulai"
                           class="<?= isset($galat['jam_mulai']) ? 'galat' : '' ?>"
                           value="<?= e($jam_mulai) ?>">
                    <?php if (isset($galat['jam_mulai'])): ?>
                        <span class="pesan-galat"><?= e($galat['jam_mulai']) ?></span>
                    <?php endif; ?>
                </div>

                <div class="form-grup">
                    <label class="label" for="jam_selesai">Jam selesai</label>
                    <input type="time" id="jam_selesai" name="jam_selesai"
                           class="<?= isset($galat['jam_selesai']) ? 'galat' : '' ?>"
                           value="<?= e($jam_selesai) ?>">
                    <?php if (isset($galat['jam_selesai'])): ?>
                        <span class="pesan-galat"><?= e($galat['jam_selesai']) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-grup">
                <label class="label" for="kegiatan">Nama kegiatan <span class="wajib">*</span></label>
                <input type="text" id="kegiatan" name="kegiatan" maxlength="255"
                       class="<?= isset($galat['kegiatan']) ? 'galat' : '' ?>"
                       value="<?= e($kegiatan) ?>"
                       placeholder="Contoh: Mempelajari alur pelaporan data" required>
                <?php if (isset($galat['kegiatan'])): ?>
                    <span class="pesan-galat"><?= e($galat['kegiatan']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="deskripsi">Deskripsi kegiatan <span class="wajib">*</span></label>
                <textarea id="deskripsi" name="deskripsi"
                          class="<?= isset($galat['deskripsi']) ? 'galat' : '' ?>"
                          placeholder="Jelaskan kegiatan yang kamu lakukan..." required><?= e($deskripsi) ?></textarea>
                <?php if (isset($galat['deskripsi'])): ?>
                    <span class="pesan-galat"><?= e($galat['deskripsi']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="hasil">Hasil kegiatan</label>
                <textarea id="hasil" name="hasil"
                          class="<?= isset($galat['hasil']) ? 'galat' : '' ?>"
                          placeholder="Apa hasil yang diperoleh?"><?= e($hasil) ?></textarea>
                <?php if (isset($galat['hasil'])): ?>
                    <span class="pesan-galat"><?= e($galat['hasil']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="kendala">Kendala</label>
                <textarea id="kendala" name="kendala"
                          class="<?= isset($galat['kendala']) ? 'galat' : '' ?>"
                          placeholder="Tuliskan kendala jika ada."><?= e($kendala) ?></textarea>
                <?php if (isset($galat['kendala'])): ?>
                    <span class="pesan-galat"><?= e($galat['kendala']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="rencana_berikutnya">Rencana kegiatan berikutnya</label>
                <textarea id="rencana_berikutnya" name="rencana_berikutnya"
                          class="<?= isset($galat['rencana_berikutnya']) ? 'galat' : '' ?>"
                          placeholder="Apa yang akan dikerjakan selanjutnya?"><?= e($rencana_berikutnya) ?></textarea>
                <?php if (isset($galat['rencana_berikutnya'])): ?>
                    <span class="pesan-galat"><?= e($galat['rencana_berikutnya']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="lokasi_teks">Lokasi kegiatan <span class="text-redup text-kecil">(opsional)</span></label>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <input type="text" id="lokasi_teks" name="lokasi_teks" maxlength="191"
                           class="<?= isset($galat['lokasi_teks']) ? 'galat' : '' ?>"
                           value="<?= e($lokasi_teks) ?>" style="flex:1;min-width:220px"
                           placeholder="Contoh: Desa Buluh Manis, Bathin Solapan">
                    <button type="button" class="tombol tombol-garis" id="tombolGps"><?= ikon_sp('penanda') ?> Ambil lokasi saya</button>
                </div>
                <input type="hidden" id="garis_lintang" name="garis_lintang" value="<?= e($garis_lintang) ?>">
                <input type="hidden" id="garis_bujur" name="garis_bujur" value="<?= e($garis_bujur) ?>">
                <div id="petaMini" style="height:220px;margin-top:8px;border-radius:8px;border:1px solid var(--abu-200)"></div>
                <span class="bantuan text-redup text-kecil" id="statusLokasi">
                    Ketik nama tempat, klik peta, atau tekan "Ambil lokasi saya" untuk menandai titik lokasi.
                </span>
                <a class="tautan-lokasi" id="tautanLokasi" href="#" target="_blank" rel="noopener"
                   style="display:none"><?= ikon_sp('peta') ?> Cek Lokasi (Google Maps)</a>
                <?php if (isset($galat['lokasi_teks'])): ?>
                    <span class="pesan-galat"><?= e($galat['lokasi_teks']) ?></span>
                <?php endif; ?>
            </div>

            <div class="form-grup">
                <label class="label" for="bukti">Foto bukti kegiatan</label>

                <label class="area-unggah" for="bukti" style="display:block">
                    <span class="ikon"><?= ikon_sp('gambar') ?></span>
                    Klik untuk memilih foto atau seret file ke sini.
                    <input type="file" id="bukti" name="bukti[]"
                           accept=".jpg,.jpeg,.png,image/jpeg,image/png" multiple
                           data-pratinjau="pratinjauBukti"
                           data-maks="<?= (int) UPLOAD_MAKS_JUMLAH ?>"
                           style="display:none">
                </label>

                <div class="pratinjau-gambar" id="pratinjauBukti"></div>

                <?php if (isset($galat['bukti'])): ?>
                    <span class="pesan-galat"><?= e($galat['bukti']) ?></span>
                <?php endif; ?>

                <span class="bantuan text-redup text-kecil">
                    Maksimal <?= (int) UPLOAD_MAKS_JUMLAH ?> foto JPG/JPEG/PNG,
                    maksimal <?= (int) (UPLOAD_MAKS_BYTES / 1048576) ?> MB per foto.
                </span>
            </div>

            <div class="akhir-halaman mt-3">
                <button type="submit" class="tombol tombol-utama">Simpan Draft</button>
                <button type="button" class="tombol tombol-garis"
                        data-validasi-ai data-hasil="#hasilValidasiAI">✦ Periksa kelengkapan (AI)</button>
                <a class="tombol tombol-garis" href="riwayat.php">Batal</a>
            </div>

            <div id="hasilValidasiAI" class="narasi-hasil" style="margin-top:14px" hidden></div>
        </form>
    </div>
</section>

<style>
.tautan-lokasi { display:inline-block; margin-top:4px; font-size:12px; font-weight:600; color:var(--phr-biru); text-decoration:none; }
.tautan-lokasi:hover { text-decoration:underline; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var elPeta = document.getElementById('petaMini');
    if (!elPeta) { return; }
    if (typeof L === 'undefined') {
        elPeta.style.display = 'none';
        return;
    }

    var latEl   = document.getElementById('garis_lintang');
    var lngEl   = document.getElementById('garis_bujur');
    var teksEl  = document.getElementById('lokasi_teks');
    var statusEl = document.getElementById('statusLokasi');
    var linkEl  = document.getElementById('tautanLokasi');

    var lat0 = parseFloat(latEl.value);
    var lng0 = parseFloat(lngEl.value);
    var ada  = isFinite(lat0) && isFinite(lng0);
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

