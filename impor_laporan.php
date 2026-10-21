<?php
/**
 * impor_laporan.php — Impor berkas Excel/CSV ke dalam aplikasi.
 *
 * Alur tiga langkah (semua aksi tulis memakai POST + CSRF + pola PRG):
 *   1. Unggah   — pilih jenis & berkas, berkas disimpan di backups/impor/.
 *   2. Pemetaan — petakan kolom berkas ke medan tujuan (bisa disimpan sebagai profil).
 *   3. Pratinjau— tinjau hasil validasi lalu simpan ke basis data.
 *
 * Peran:
 *   - karyawan : hanya boleh mengunggah data MILIKNYA SENDIRI
 *                (laporan harian & absensi). Kolom "Pengguna" tidak dipakai;
 *                pemilik data dipaksa ke akun yang sedang masuk.
 *   - mentor   : boleh mengunggah data semua karyawan + berkas SRM & CID.
 *   - admin    : sama dengan mentor, ditambah akses penuh.
 *
 * Setelah impor berhasil, koordinat lokasi yang belum terisi dicoba
 * diresolusikan (master wilayah + cache, lalu Nominatim) dan hasilnya
 * disimpan di sesi `unggahan_terakhir` untuk disorot pada peta.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid       = (int) sesi_user_id();
$roleLogin = sesi_role();
$paksaUser = $roleLogin === 'karyawan' ? $uid : 0;
$jenisBoleh = impor_jenis_untuk_role($roleLogin);

/** Bersihkan sesi impor + hapus berkas kerja. */
function impor_reset(?string $berkas = null): void
{
    if ($berkas !== null && is_file($berkas)) {
        @unlink($berkas);
    }
    unset($_SESSION['impor']);
}

/* ================================================================
 * Aksi tulis (POST)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    /* ---------- Langkah 1: unggah berkas ---------- */
    if ($aksi === 'unggah') {
        csrf_cek_atau_matikan('impor');

        $jenis = (string) ($_POST['jenis'] ?? 'laporan_harian');
        if (!array_key_exists($jenis, $jenisBoleh)) {
            $jenis = 'laporan_harian';
        }

        $hasil = impor_simpan_tmp($_FILES['berkas'] ?? []);
        if (!$hasil['ok']) {
            flash_set('gagal', $hasil['galat']);
            header('Location: impor_laporan.php');
            exit;
        }

        $_SESSION['impor'] = [
            'token' => $hasil['token'],
            'ekstensi' => $hasil['ekstensi'],
            'nama_asli' => $hasil['nama_asli'],
            'jenis' => $jenis,
            'pemetaan' => [],
            'opsi' => [],
        ];

        catat_audit($conn, 'impor_unggah', 'impor_sejarah', null,
            'Unggah impor ' . $jenis . ': ' . $hasil['nama_asli'], null, null);

        header('Location: impor_laporan.php?langkah=pemetaan');
        exit;
    }

    /* ---------- Langkah 2: simpan pemetaan -> pratinjau ---------- */
    if ($aksi === 'pratinjau') {
        csrf_cek_atau_matikan('impor');

        if (empty($_SESSION['impor']['token'])) {
            flash_set('gagal', 'Sesi impor tidak ditemukan. Silakan unggah ulang.');
            header('Location: impor_laporan.php');
            exit;
        }

        $jenis = (string) $_SESSION['impor']['jenis'];
        $field = impor_field($jenis);

        $pemetaan = [];
        $kiriman = $_POST['peta'] ?? [];
        if (is_array($kiriman)) {
            foreach ($field as $kunci => $def) {
                $nilai = $kiriman[$kunci] ?? '';
                if ($nilai !== '' && $nilai !== '-') {
                    $pemetaan[$kunci] = (int) $nilai;
                }
            }
        }

        $_SESSION['impor']['pemetaan'] = $pemetaan;
        $_SESSION['impor']['opsi'] = [
            'status_bawaan' => (string) ($_POST['status_bawaan'] ?? ($jenis === 'absensi' ? 'hadir' : 'diperiksa')),
            'kondisi_bawaan' => (string) ($_POST['kondisi_bawaan'] ?? 'sehat'),
            'duplikat' => (string) ($_POST['duplikat'] ?? 'lewati'),
        ];
        $_SESSION['impor']['sheet'] = (string) ($_POST['sheet'] ?? '');

        // Simpan profil pemetaan bila diminta.
        $namaProfil = trim((string) ($_POST['nama_profil'] ?? ''));
        if (($namaProfil !== '') && impor_simpan_profil($conn, $uid, $jenis, $namaProfil, $pemetaan)) {
            flash_set('sukses', 'Profil pemetaan "' . $namaProfil . '" disimpan.');
        }

        header('Location: impor_laporan.php?langkah=pratinjau');
        exit;
    }

    /* ---------- Langkah 3: simpan ke basis data ---------- */
    if ($aksi === 'simpan') {
        csrf_cek_atau_matikan('impor');

        if (empty($_SESSION['impor']['token'])) {
            flash_set('gagal', 'Sesi impor tidak ditemukan. Silakan unggah ulang.');
            header('Location: impor_laporan.php');
            exit;
        }

        $impor = $_SESSION['impor'];
        $jenis = (string) $impor['jenis'];
        $berkas = impor_berkas_dari_token((string) $impor['token'], (string) $impor['ekstensi']);

        if ($berkas === null) {
            impor_reset();
            flash_set('gagal', 'Berkas kerja sudah tidak tersedia. Silakan unggah ulang.');
            header('Location: impor_laporan.php');
            exit;
        }

        $baca = impor_baca($berkas, $impor['sheet'] ?? null);
        if (!$baca['ok']) {
            flash_set('gagal', $baca['galat']);
            header('Location: impor_laporan.php?langkah=pemetaan');
            exit;
        }

        $petaPengguna = impor_peta_pengguna($conn);
        $opsi = [
            'status_bawaan' => (string) ($_POST['status_bawaan'] ?? ($impor['opsi']['status_bawaan'] ?? 'diperiksa')),
            'kondisi_bawaan' => (string) ($_POST['kondisi_bawaan'] ?? ($impor['opsi']['kondisi_bawaan'] ?? 'sehat')),
            'duplikat' => (string) ($_POST['duplikat'] ?? ($impor['opsi']['duplikat'] ?? 'lewati')),
            'paksa_user_id' => $paksaUser,
        ];

        $validasi = impor_validasi($baca['baris'], (array) $impor['pemetaan'], $jenis, $petaPengguna, $opsi);
        if (!$validasi['ok']) {
            flash_set('gagal', implode(' ', $validasi['galat']));
            header('Location: impor_laporan.php?langkah=pemetaan');
            exit;
        }

        $simpan = impor_commit($conn, $validasi['baris'], $jenis, $opsi);

        // ID baris yang tersentuh + nama sumber dipakai untuk sorotan peta
        // dan tombol "sorot ulang" pada riwayat unggahan.
        $tabel      = $jenis === 'absensi' ? 'absensi' : 'laporan_harian';
        $ids        = array_map('intval', (array) ($simpan['id_semua'] ?? []));
        $sumberNama = $jenis === 'absensi' ? 'absensi' : 'laporan';

        impor_catat_sejarah(
            $conn,
            $uid,
            $jenis,
            (string) $impor['nama_asli'],
            (int) $validasi['ringkas']['total'],
            (int) $simpan['masuk'],
            (int) $simpan['dilewati'],
            // impor_commit() sudah menghitung baris tidak sah sebagai gagal,
            // jadi jangan tambahkan ringkas['gagal'] lagi (hindari hitung ganda).
            (int) $simpan['gagal'],
            'diperbarui=' . (int) $simpan['diperbarui'],
            $ids,
            $sumberNama
        );

        catat_audit($conn, 'impor_simpan', 'laporan_harian', null,
            'Impor ' . $jenis . ' dari ' . $impor['nama_asli'] . ': masuk=' . $simpan['masuk']
            . ' diperbarui=' . $simpan['diperbarui'] . ' dilewati=' . $simpan['dilewati']
            . ' gagal=' . $simpan['gagal'], null, null);

        impor_reset($berkas);

        if (!$simpan['ok']) {
            flash_set('gagal', $simpan['galat']);
            header('Location: impor_laporan.php');
            exit;
        }

        // Selesaikan koordinat segera: master wilayah + cache dulu, lalu
        // Nominatim untuk sisanya (dibatasi 15 baris per permintaan).
        $geo = null;
        if (function_exists('wilayah_batch_koordinat') && $ids !== []) {
            $geo = wilayah_batch_koordinat($conn, $tabel, $ids, 15);
        }

        $terisi = (int) ($geo['terisi'] ?? 0);
        $tunggu = (int) ($geo['tunggu'] ?? 0);

        // Kunci sorotan (dipakai peta.php untuk menandai titik baru).
        $prefix = $jenis === 'absensi' ? 'ABS#' : 'LAP#';
        $kunciSorot = array_map(static fn ($i) => $prefix . (int) $i, $ids);

        $_SESSION['unggahan_terakhir'] = [
            'jenis'   => $jenis,
            'tabel'   => $tabel,
            'masuk'   => (int) $simpan['masuk'],
            'diperbarui' => (int) $simpan['diperbarui'],
            'dilewati' => (int) $simpan['dilewati'],
            'gagal'   => (int) $simpan['gagal'],
            'terisi'  => $terisi,
            'tunggu'  => $tunggu,
            'berkas'  => (string) $impor['nama_asli'],
            'waktu'   => date('Y-m-d H:i:s'),
            'sorot_kunci'  => $kunciSorot,
            'sorot_sumber' => [$sumberNama],
        ];

        $pesan = sprintf(
            'Impor selesai: %d baru, %d diperbarui, %d dilewati, %d gagal.',
            $simpan['masuk'], $simpan['diperbarui'], $simpan['dilewati'], $simpan['gagal']
        );
        if ($terisi > 0) {
            $pesan .= sprintf(' %d lokasi berhasil ditempatkan di peta.', $terisi);
        }
        if ($tunggu > 0) {
            $pesan .= sprintf(' %d lokasi masih menunggu geokode.', $tunggu);
        }
        flash_set('sukses', $pesan);

        header('Location: peta.php?sorot=1');
        exit;
    }

    /* ---------- Simpan SRM & CID (satu langkah, tanpa pemetaan) ---------- */
    if ($aksi === 'simpan_srmcid') {
        csrf_cek_atau_matikan('impor');

        if (!array_key_exists('srm_cid', $jenisBoleh)) {
            flash_set('gagal', 'Anda tidak berhak mengimpor data SRM & CID.');
            header('Location: impor_laporan.php');
            exit;
        }

        if (empty($_SESSION['impor']['token'])) {
            flash_set('gagal', 'Sesi impor tidak ditemukan. Silakan unggah ulang.');
            header('Location: impor_laporan.php');
            exit;
        }

        $impor = $_SESSION['impor'];
        $berkas = impor_berkas_dari_token((string) $impor['token'], (string) $impor['ekstensi']);

        if ($berkas === null) {
            impor_reset();
            flash_set('gagal', 'Berkas kerja sudah tidak tersedia. Silakan unggah ulang.');
            header('Location: impor_laporan.php');
            exit;
        }

        if (!function_exists('srm_cid_impor')) {
            impor_reset($berkas);
            flash_set('gagal', 'Pustaka impor SRM & CID tidak tersedia.');
            header('Location: impor_laporan.php');
            exit;
        }

        $hasilImp = srm_cid_impor($conn, $berkas, 'semua');
        $masuk = (int) ($hasilImp['srm']['masuk'] ?? 0) + (int) ($hasilImp['cid']['masuk'] ?? 0);
        $gagal = (int) ($hasilImp['srm']['gagal'] ?? 0) + (int) ($hasilImp['cid']['gagal'] ?? 0);

        $terisi = 0;
        $tanpa = 0;
        if (function_exists('wilayah_resolusi_tabel')) {
            $rs = wilayah_resolusi_tabel($conn, 'srm_kegiatan');
            $rc = wilayah_resolusi_tabel($conn, 'cid_program');
            $terisi = (int) ($rs['terpetakan'] ?? 0) + (int) ($rc['terpetakan'] ?? 0);
            $tanpa  = (int) ($rs['tanpa_koordinat'] ?? 0) + (int) ($rc['tanpa_koordinat'] ?? 0);
        }

        impor_catat_sejarah(
            $conn, $uid, 'srm_cid', (string) $impor['nama_asli'],
            0, $masuk, 0, $gagal,
            'srm=' . (int) ($hasilImp['srm']['masuk'] ?? 0) . ' cid=' . (int) ($hasilImp['cid']['masuk'] ?? 0),
            [],
            'srm,cid'
        );

        catat_audit($conn, 'impor_srm_cid', 'srm_kegiatan', null,
            'Impor SRM & CID dari ' . $impor['nama_asli'] . ': masuk=' . $masuk . ' gagal=' . $gagal, null, null);

        impor_reset($berkas);

        $_SESSION['unggahan_terakhir'] = [
            'jenis'   => 'srm_cid',
            'tabel'   => 'srm_kegiatan,cid_program',
            'masuk'   => $masuk,
            'gagal'   => $gagal,
            'terisi'  => $terisi,
            'tunggu'  => $tanpa,
            'berkas'  => (string) $impor['nama_asli'],
            'waktu'   => date('Y-m-d H:i:s'),
            'sorot_kunci'  => [],
            'sorot_sumber' => ['srm', 'cid'],
        ];

        if (!empty($hasilImp['ok'])) {
            $pesan = sprintf('Impor SRM & CID selesai: %d baris masuk, %d gagal.', $masuk, $gagal);
            if ($terisi > 0) {
                $pesan .= sprintf(' %d lokasi ditempatkan di peta.', $terisi);
            }
            $pesan .= ' <a href="analisis_sosial.php?tab=srm&highlight=baru">Lihat Hasil Analisis &rarr;</a>';
            flash_set('sukses', $pesan);
            header('Location: analisis_sosial.php?tab=srm&highlight=baru');
        } else {
            flash_set('gagal', (string) ($hasilImp['pesan'] ?? 'Impor SRM & CID gagal.'));
            header('Location: impor_laporan.php');
        }
        exit;
    }

    /* ---------- Batal / ganti berkas ---------- */
    if ($aksi === 'batal') {
        csrf_cek_atau_matikan('impor');
        $impor = $_SESSION['impor'] ?? [];
        $berkas = null;
        if (!empty($impor['token'])) {
            $berkas = impor_berkas_dari_token((string) $impor['token'], (string) $impor['ekstensi']);
        }
        impor_reset($berkas);
        flash_set('info', 'Impor dibatalkan.');
        header('Location: impor_laporan.php');
        exit;
    }

    if ($aksi === 'hapus_batch') {
        csrf_cek_atau_matikan('impor');
        $sejarahId = (int) ($_POST['sejarah_id'] ?? 0);
        $hasilHapus = impor_hapus_batch($conn, $sejarahId, $uid, sesi_role() === 'admin');
        if ($hasilHapus['ok']) {
            catat_audit($conn, 'impor_hapus_batch', 'impor_sejarah', $sejarahId,
                $hasilHapus['pesan'], null, null);
            flash_set('sukses', $hasilHapus['pesan']);
        } else {
            flash_set('gagal', $hasilHapus['pesan']);
        }
        header('Location: impor_laporan.php');
        exit;
    }
}

/* ================================================================
 * Tampilan
 * ================================================================ */
$impor = $_SESSION['impor'] ?? [];
$langkah = (string) ($_GET['langkah'] ?? '');

$jenis = (string) ($impor['jenis'] ?? 'laporan_harian');
$namaAsli = (string) ($impor['nama_asli'] ?? '');
$berkas = !empty($impor['token'])
    ? impor_berkas_dari_token((string) $impor['token'], (string) $impor['ekstensi'])
    : null;

// Tanpa berkas kerja, langkah 2/3 tidak mungkin -> kembali ke langkah 1.
if ($berkas === null && in_array($langkah, ['pemetaan', 'pratinjau'], true)) {
    impor_reset();
    flash_set('info', 'Berkas kerja sudah tidak tersedia. Silakan unggah ulang.');
    header('Location: impor_laporan.php');
    exit;
}

$sheetDiminta = (string) ($_GET['sheet'] ?? ($impor['sheet'] ?? ''));

$baca = null;
$field = impor_field($jenis);
if ($paksaUser > 0) {
    // Karyawan: pemilik data dipaksa ke akun sendiri, kolom pengguna disembunyikan.
    unset($field['pengguna']);
}
$srmCid = ($jenis === 'srm_cid');
$pemetaanTerpilih = (array) ($impor['pemetaan'] ?? []);
$opsiTerpilih = (array) ($impor['opsi'] ?? []);
$validasi = null;

if ($berkas !== null) {
    $baca = impor_baca($berkas, $sheetDiminta !== '' ? $sheetDiminta : null);
    if (!$baca['ok']) {
        flash_set('gagal', $baca['galat']);
        impor_reset($berkas);
        $berkas = null;
        $baca = null;
    }
}

// Pemetaan: pakai pilihan tersimpan, atau profil, atau tebakan otomatis.
if ($baca !== null && $langkah === 'pemetaan') {
    if (isset($_GET['profil'])) {
        $idProfil = (int) $_GET['profil'];
        foreach (impor_daftar_profil($conn, $uid, $jenis) as $p) {
            if ($p['id'] === $idProfil) {
                $pemetaanTerpilih = $p['pemetaan'];
                flash_set('info', 'Profil "' . $p['nama'] . '" diterapkan.');
                break;
            }
        }
    } elseif ($pemetaanTerpilih === []) {
        $pemetaanTerpilih = impor_tebak_pemetaan($baca['header'], $jenis);
    }
}

// Pratinjau: jalankan validasi (tanpa menyimpan).
if ($baca !== null && $langkah === 'pratinjau') {
    $petaPengguna = impor_peta_pengguna($conn);
    $validasi = impor_validasi($baca['baris'], $pemetaanTerpilih, $jenis, $petaPengguna, $opsiTerpilih);
}

$daftarProfil = impor_daftar_profil($conn, $uid, $jenis);

// Riwayat impor terakhir.
$riwayat = [];
$stmtR = $conn->prepare(
    'SELECT id, jenis, nama_berkas, jumlah_baris, jumlah_masuk, jumlah_dilewati, jumlah_gagal, dibuat_pada, id_semua
       FROM impor_sejarah WHERE user_id = ? ORDER BY id DESC LIMIT 8'
);
if ($stmtR) {
    $stmtR->bind_param('i', $uid);
    $stmtR->execute();
    $resR = $stmtR->get_result();
    while ($resR && ($row = $resR->fetch_assoc())) {
        $riwayat[] = $row;
    }
    $stmtR->close();
}

$aksiTopbar = '';
if (sesi_role() === 'admin') {
    $aksiTopbar = '<a class="tombol tombol-garis" href="powerbi_ekspor.php">' . ikon_sp('grafik') . ' Ekspor Dataset</a>';
}

mulai_halaman([
    'judul' => 'Impor Data',
    'crumb' => APP_SINGKAT,
    'aktif' => 'impor',
    'aksi'  => $aksiTopbar,
]);
?>

<style>
.langkah-baris { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:6px; }
.langkah-butir { display:flex; gap:9px; align-items:center; padding:9px 14px; border-radius:10px;
    background:#fff; border:1px solid var(--abu-200); font-size:13px; font-weight:600; color:#64748b; }
.langkah-butir.aktif { border-color:var(--phr-biru); color:var(--phr-biru-tua); background:var(--biru-50); }
.langkah-butir.selesai { border-color:#BBF7D0; color:#166534; background:#F0FDF4; }
.langkah-butir .nomor { width:22px; height:22px; border-radius:50%; background:var(--abu-200); color:#fff;
    display:inline-flex; align-items:center; justify-content:center; font-size:12px; }
.langkah-butir.aktif .nomor { background:var(--phr-biru); }
.langkah-butir.selesai .nomor { background:var(--phr-hijau); }
.peta-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.peta-item { background:#fff; border:1px solid var(--abu-200); border-radius:10px; padding:12px 14px; }
.peta-item label { font-weight:600; font-size:13px; display:block; margin-bottom:6px; }
.peta-item .wajib { color:var(--phr-merah); font-weight:700; }
.peta-item select { width:100%; }
.pratinjau-bungkus { max-height:460px; overflow:auto; border:1px solid var(--abu-200); border-radius:10px; }
.pratinjau-bungkus table { font-size:12.5px; }
.baris-gagal { background:#FEF2F2; }
.baris-lewat { background:#FFFBEB; }
</style>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Unggah Data</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Unggah berkas Excel (.xlsx/.xls) atau CSV, petakan kolomnya, lalu simpan.
            Lokasi pada data otomatis ditempatkan pada peta. Data masuk ke basis data
            yang sama dengan Power BI.
        </p>
    </div>
    <div class="kartu-badan">
        <div class="langkah-baris">
            <div class="langkah-butir <?= $berkas === null ? 'aktif' : 'selesai' ?>"><span class="nomor">1</span> Unggah berkas</div>
            <?php if ($srmCid): ?>
                <div class="langkah-butir <?= $berkas !== null ? 'aktif' : '' ?>"><span class="nomor">2</span> Impor &amp; selesai</div>
            <?php else: ?>
                <div class="langkah-butir <?= $langkah === 'pemetaan' ? 'aktif' : ($langkah === 'pratinjau' ? 'selesai' : '') ?>"><span class="nomor">2</span> Pemetaan kolom</div>
                <div class="langkah-butir <?= $langkah === 'pratinjau' ? 'aktif' : '' ?>"><span class="nomor">3</span> Pratinjau &amp; simpan</div>
            <?php endif; ?>
        </div>

        <?php if ($berkas === null): ?>
        <!-- ===================== LANGKAH 1 ===================== -->
        <form method="post" action="impor_laporan.php" enctype="multipart/form-data" style="margin-top:14px">
            <?= csrf_field('impor') ?>
            <input type="hidden" name="aksi" value="unggah">
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
                <label style="min-width:200px">Jenis data
                    <select name="jenis" id="jenis-impor" required>
                        <?php foreach ($jenisBoleh as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1;min-width:260px">Berkas
                    <input type="file" name="berkas" accept=".xlsx,.xls,.csv,.txt" required>
                </label>
                <button class="tombol tombol-utama" type="submit">Unggah &amp; lanjut</button>
            </div>
            <div id="template-srmcid" style="display:none;margin-top:10px;gap:8px;flex-wrap:wrap;align-items:center">
                <span class="text-redup" style="font-size:13px">Template standar:</span>
                <a class="tombol tombol-garis tombol-kecil" href="templates/template_srm.txt" download>Unduh Template SRM</a>
                <a class="tombol tombol-garis tombol-kecil" href="templates/template_cid.txt" download>Unduh Template CID</a>
            </div>
            <script>
            (function(){
                var sel=document.getElementById('jenis-impor');
                var box=document.getElementById('template-srmcid');
                function t(){ if(!sel||!box)return; if(sel.value==='srm_cid'){box.style.display='flex';}else{box.style.display='none';}}
                if(sel){sel.addEventListener('change',t); t();}
            })();
            </script>
            <p class="text-redup" style="margin-top:10px;font-size:13px">
                Maksimal <?= number_format(impor_maks_bytes() / 1048576, 1) ?> MB.
                Baris pertama dianggap <strong>judul kolom</strong>. Berkas disimpan sementara di server
                (di luar akses web) dan dihapus setelah impor.
            </p>
            <?php if ($paksaUser > 0): ?>
            <div class="alert alert-info" style="margin-top:12px">
                <span class="ikon"><?= ikon_sp('info') ?></span>
                <span>Setiap baris yang Anda unggah akan tercatat sebagai data
                      <strong>milik akun Anda</strong> (<?= e(sesi_nama()) ?>).</span>
            </div>
            <?php else: ?>
            <div class="alert alert-info" style="margin-top:12px">
                <span class="ikon"><?= ikon_sp('info') ?></span>
                <span>Anda dapat mengunggah data untuk <strong>semua karyawan</strong>. Isi kolom
                      &ldquo;Pengguna&rdquo; dengan email/nama/ID agar data tercatat pada akun yang tepat.</span>
            </div>
            <?php endif; ?>
        </form>

        <?php else: ?>
        <!-- ===================== LANGKAH 2 ===================== -->
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px">
            <span class="badge badge-navy badge-tanpa-titik"><?= e(namaAsliLabel($namaAsli)) ?></span>
            <span class="badge badge-biru badge-tanpa-titik"><?= e($jenisBoleh[$jenis] ?? $jenis) ?></span>
            <span class="text-redup" style="font-size:13px"><?= (int) count($baca['baris']) ?> baris data</span>
            <?php if ($baca['terpotong']): ?>
                <span class="badge badge-kuning badge-tanpa-titik">dipotong 5000 baris</span>
            <?php endif; ?>
            <form method="post" action="impor_laporan.php" style="margin-left:auto">
                <?= csrf_field('impor') ?>
                <input type="hidden" name="aksi" value="batal">
                <button class="tombol tombol-garis tombol-kecil" type="submit">Ganti berkas</button>
            </form>
        </div>

        <?php if (!empty($baca['daftar_sheet']) && count($baca['daftar_sheet']) > 1): ?>
        <form method="get" action="impor_laporan.php" style="margin-top:12px;display:flex;gap:10px;align-items:flex-end">
            <input type="hidden" name="langkah" value="pemetaan">
            <label>Lembar kerja (sheet)
                <select name="sheet" onchange="this.form.submit()">
                    <?php foreach ($baca['daftar_sheet'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $s === $baca['sheet'] ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <noscript><button class="tombol tombol-garis" type="submit">Ganti sheet</button></noscript>
        </form>
        <?php endif; ?>

        <table class="tabel" style="margin-top:14px">
            <thead>
                <tr>
                    <th style="width:52px">#</th>
                    <?php foreach ($baca['header'] as $h): ?>
                        <th><?= e($h) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($baca['baris'], 0, 5) as $i => $r): ?>
                <tr>
                    <td class="text-redup"><?= $i + 1 ?></td>
                    <?php foreach ($baca['header'] as $j => $h): ?>
                        <td><?= e((string) ($r[$j] ?? '')) ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($srmCid): ?>
        <!-- ===================== SRM & CID (satu langkah) ===================== -->
        <div class="alert alert-kuning" style="margin-top:18px">
            <span class="ikon"><?= ikon_sp('info') ?></span>
            <span>
                Berkas SRM &amp; CID dibaca otomatis dari lembar <strong>SRM</strong> dan
                <strong>C&amp;D/CID</strong>. Header kolom dikenali otomatis, jadi tidak perlu
                pemetaan. <strong>Penting:</strong> impor ini akan
                <strong>mengganti seluruh isi</strong> tabel SRM &amp; CID dengan data berkas.
            </span>
        </div>

        <?php if (!empty($baca['daftar_sheet'])): ?>
        <p style="margin:14px 0 6px;font-weight:600">Lembar kerja terdeteksi</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <?php foreach ($baca['daftar_sheet'] as $s): ?>
                <span class="badge badge-biru badge-tanpa-titik"><?= e((string) $s) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="text-redup" style="margin-top:12px;font-size:13px">
            <?= (int) count($baca['baris']) ?> baris terbaca pada lembar
            &ldquo;<?= e((string) $baca['sheet']) ?>&rdquo;.
            <?php if (in_array('SRM', array_map('strval', (array) ($baca['daftar_sheet'] ?? [])), true)): ?>
                Lembar SRM ditemukan.
            <?php endif; ?>
        </p>

        <div style="margin-top:16px">
            <form method="post" action="impor_laporan.php"
                  onsubmit="return confirm('Impor ini akan mengganti seluruh data SRM &amp; CID. Lanjutkan?');">
                <?= csrf_field('impor') ?>
                <input type="hidden" name="aksi" value="simpan_srmcid">
                <button class="tombol tombol-utama" type="submit">Impor SRM &amp; CID sekarang</button>
            </form>
        </div>

        <?php else: ?>
        <h3 style="margin:20px 0 4px;font-size:15px">Pemetaan kolom</h3>
        <p class="text-redup" style="margin:0 0 12px;font-size:13px">
            Pilih kolom berkas untuk setiap medan. Medan bertanda
            <span class="wajib" style="color:var(--phr-merah)">*</span> wajib dipetakan.
        </p>

        <form method="post" action="impor_laporan.php">
            <?= csrf_field('impor') ?>
            <input type="hidden" name="aksi" value="pratinjau">
            <input type="hidden" name="sheet" value="<?= e((string) $baca['sheet']) ?>">

            <div class="peta-grid">
                <?php foreach ($field as $kunci => $def): ?>
                    <div class="peta-item">
                        <label>
                            <?= e($def['label']) ?>
                            <?php if ($def['wajib']): ?><span class="wajib" style="color:var(--phr-merah)">*</span><?php endif; ?>
                        </label>
                        <select name="peta[<?= e($kunci) ?>]">
                            <option value="">(tidak dipakai)</option>
                            <?php foreach ($baca['header'] as $i => $h): ?>
                                <option value="<?= (int) $i ?>" <?= (($pemetaanTerpilih[$kunci] ?? null) === $i) ? 'selected' : '' ?>>
                                    <?= e($h) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:18px">
                <?php if ($jenis === 'absensi'): ?>
                    <label>Status kehadiran bawaan
                        <select name="status_bawaan">
                            <?php foreach (impor_status_absensi() as $s): ?>
                                <option value="<?= e($s) ?>" <?= ($opsiTerpilih['status_bawaan'] ?? 'hadir') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Kondisi kesehatan bawaan
                        <select name="kondisi_bawaan">
                            <?php foreach (impor_kondisi_kesehatan() as $s): ?>
                                <option value="<?= e($s) ?>" <?= ($opsiTerpilih['kondisi_bawaan'] ?? 'sehat') === $s ? 'selected' : '' ?>><?= e(str_replace('_', ' ', ucfirst($s))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php else: ?>
                    <label>Status laporan hasil impor
                        <select name="status_bawaan">
                            <?php foreach (impor_status_laporan() as $s): ?>
                                <option value="<?= e($s) ?>" <?= ($opsiTerpilih['status_bawaan'] ?? 'diperiksa') === $s ? 'selected' : '' ?>><?= e(label_status($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <label>Bila data sudah ada (duplikat)
                    <select name="duplikat">
                        <option value="lewati" <?= ($opsiTerpilih['duplikat'] ?? 'lewati') === 'lewati' ? 'selected' : '' ?>>Lewati</option>
                        <option value="perbarui" <?= ($opsiTerpilih['duplikat'] ?? '') === 'perbarui' ? 'selected' : '' ?>>Perbarui</option>
                        <?php if ($jenis !== 'absensi'): ?>
                            <option value="gandakan" <?= ($opsiTerpilih['duplikat'] ?? '') === 'gandakan' ? 'selected' : '' ?>>Tetap tambahkan (gandakan)</option>
                        <?php endif; ?>
                    </select>
                </label>
                <label>Simpan pemetaan sebagai profil (opsional)
                    <input type="text" name="nama_profil" placeholder="mis. Template Bulanan" maxlength="100">
                </label>
            </div>

            <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
                <button class="tombol tombol-utama" type="submit">Lanjut ke pratinjau</button>
            </div>
        </form>

        <?php if ($daftarProfil !== []): ?>
            <h3 style="margin:22px 0 8px;font-size:14px">Profil pemetaan tersimpan</h3>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <?php foreach ($daftarProfil as $p): ?>
                    <a class="tombol tombol-garis tombol-kecil"
                       href="impor_laporan.php?langkah=pemetaan&amp;profil=<?= (int) $p['id'] ?>">
                        <?= e($p['nama']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ===================== LANGKAH 3 ===================== -->
        <?php if ($langkah === 'pratinjau' && $validasi !== null): ?>
            <?php if (!$validasi['ok']): ?>
                <div class="alert alert-gagal" style="margin-top:20px">
                    <span class="ikon"><?= ikon_sp('silang') ?></span>
                    <span><?= e(implode(' ', $validasi['galat'])) ?></span>
                </div>
            <?php else: ?>
                <hr style="margin:22px 0;border:none;border-top:1px solid var(--abu-200)">
                <h3 style="margin:0 0 10px;font-size:15px">Pratinjau hasil</h3>

                <div class="kalender-ringkas" style="padding:0">
                    <div class="ringkas-butir"><span class="angka"><?= (int) $validasi['ringkas']['total'] ?></span><span class="ket">Total baris</span></div>
                    <div class="ringkas-butir ringkas-hadir"><span class="angka"><?= (int) $validasi['ringkas']['sah'] ?></span><span class="ket">Siap disimpan</span></div>
                    <div class="ringkas-butir <?= $validasi['ringkas']['gagal'] > 0 ? 'ringkas-revisi' : '' ?>"><span class="angka"><?= (int) $validasi['ringkas']['gagal'] ?></span><span class="ket">Gagal / dilewati</span></div>
                </div>

                <div class="pratinjau-bungkus" style="margin-top:14px">
                    <table class="tabel">
                        <thead>
                            <tr>
                                <th style="width:46px">#</th>
                                <th style="width:90px">Status</th>
                                <th>Ringkasan</th>
                                <th>Catatan</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_slice($validasi['baris'], 0, 200) as $b): ?>
                            <tr class="<?= $b['sah'] ? '' : 'baris-gagal' ?>">
                                <td class="text-redup"><?= (int) $b['nomor'] ?></td>
                                <td>
                                    <?php if ($b['sah']): ?>
                                        <span class="badge badge-hijau badge-tanpa-titik">siap</span>
                                    <?php else: ?>
                                        <span class="badge badge-merah badge-tanpa-titik">gagal</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($jenis === 'absensi'): ?>
                                        <?= e((string) ($b['data']['tanggal'] ?? '-')) ?>
                                        &middot; <?= e((string) ($b['data']['status'] ?? '-')) ?>
                                    <?php else: ?>
                                        <?= e((string) ($b['data']['tanggal'] ?? '-')) ?>
                                        &middot; <?= e((string) ($b['data']['kegiatan'] ?? '-')) ?>
                                    <?php endif; ?>
                                </td>
                                <td class="text-redup"><?= e(implode(' ', $b['pesan'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (count($validasi['baris']) > 200): ?>
                    <p class="text-redup" style="font-size:12.5px;margin-top:8px">
                        Menampilkan 200 baris pertama dari <?= count($validasi['baris']) ?> baris.
                    </p>
                <?php endif; ?>

                <?php if ($validasi['ringkas']['sah'] > 0): ?>
                    <form method="post" action="impor_laporan.php" style="margin-top:16px">
                        <?= csrf_field('impor') ?>
                        <input type="hidden" name="aksi" value="simpan">
                        <input type="hidden" name="status_bawaan" value="<?= e((string) ($opsiTerpilih['status_bawaan'] ?? '')) ?>">
                        <input type="hidden" name="kondisi_bawaan" value="<?= e((string) ($opsiTerpilih['kondisi_bawaan'] ?? 'sehat')) ?>">
                        <input type="hidden" name="duplikat" value="<?= e((string) ($opsiTerpilih['duplikat'] ?? 'lewati')) ?>">
                        <button class="tombol tombol-utama" type="submit">
                            Simpan <?= (int) $validasi['ringkas']['sah'] ?> baris ke basis data
                        </button>
                        <a class="tombol tombol-garis" href="impor_laporan.php?langkah=pemetaan">Kembali ubah pemetaan</a>
                    </form>
                <?php else: ?>
                    <div class="alert alert-info" style="margin-top:14px">
                        <span class="ikon"><?= ikon_sp('info') ?></span>
                        <span>Tidak ada baris yang siap disimpan. Periksa kembali pemetaan kolom Anda.</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; /* srm_cid / pemetaan */ ?>
    </div>
</div>

<?php if ($riwayat !== []): ?>
<div class="kartu">
    <div class="kartu-kepala"><h2>Riwayat impor</h2></div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Berkas</th>
                    <th>Jenis</th>
                    <th class="text-kanan">Baris</th>
                    <th class="text-kanan">Masuk</th>
                    <th class="text-kanan">Dilewati</th>
                    <th class="text-kanan">Gagal</th>
                    <th class="text-kanan">Aksi</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($riwayat as $r): ?>
                <?php
                    // Batch dapat dihapus hanya bila id barisnya tercatat dan
                    // jenisnya menyimpan id per-baris (laporan/absensi).
                    $idBatch = json_decode((string) ($r['id_semua'] ?? ''), true);
                    $bolehHapus = in_array((string) $r['jenis'], ['laporan', 'absensi'], true)
                        && is_array($idBatch) && $idBatch !== [];
                ?>
                <tr>
                    <td class="text-redup"><?= e((string) $r['dibuat_pada']) ?></td>
                    <td><?= e((string) $r['nama_berkas']) ?></td>
                    <td><?= e($jenisBoleh[(string) $r['jenis']] ?? (string) $r['jenis']) ?></td>
                    <td class="text-kanan"><?= (int) $r['jumlah_baris'] ?></td>
                    <td class="text-kanan"><span class="badge badge-hijau badge-tanpa-titik"><?= (int) $r['jumlah_masuk'] ?></span></td>
                    <td class="text-kanan"><?= (int) $r['jumlah_dilewati'] ?></td>
                    <td class="text-kanan"><?= (int) $r['jumlah_gagal'] ?></td>
                    <td class="text-kanan">
                        <?php if ($bolehHapus): ?>
                        <form method="post" style="display:inline"
                              onsubmit="return confirm('Hapus batch ini beserta <?= (int) $r['jumlah_masuk'] ?> baris data yang dibuatnya? Tindakan ini tidak dapat dibatalkan.');">
                            <?= csrf_field('impor') ?>
                            <input type="hidden" name="aksi" value="hapus_batch">
                            <input type="hidden" name="sejarah_id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="tombol tombol-bahaya tombol-kecil" title="Hapus batch impor ini">
                                <?= ikon_sp('silang') ?> Hapus
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-redup" title="Batch ini tidak menyimpan daftar baris (impor SRM/CID atau impor lama)">&mdash;</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
akhiri_halaman();

/** Tampilkan nama berkas dengan aman (dipotong). */
function namaAsliLabel(string $nama): string
{
    $nama = $nama !== '' ? $nama : 'berkas';
    return mb_strlen($nama) > 48 ? mb_substr($nama, 0, 45) . '...' : $nama;
}
