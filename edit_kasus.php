<?php
/**
 * edit_kasus.php — mengubah data kasus, status/progres, PIC, dan tim.
 *
 * Izin: pembuat, PIC, anggota tim, atau mentor/admin. Karyawan lain ditolak
 * (HTTP 403). Setiap perubahan dicatat pada audit trail; perubahan status
 * atau progres juga dicatat pada linimasa kasus dan dinotifikasikan ke pihak
 * terkait.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid  = (int) sesi_user_id();
$role = sesi_role();

/* ==========================================
 * 1. AMBIL KASUS + IZIN
 * ========================================== */

$kasus_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$kasus_id || $kasus_id < 1) {
    http_response_code(400);
    exit('ID kasus tidak valid.');
}

$kasus = kasus_ambil($conn, (int) $kasus_id);
if (!$kasus) {
    http_response_code(404);
    exit('Kasus tidak ditemukan.');
}

if (!kasus_izin_kelola($conn, $kasus, $uid, $role)) {
    http_response_code(403);
    exit('Anda tidak berhak mengubah kasus ini.');
}

$boleh_hapus = kasus_izin_hapus($conn, $kasus, $uid, $role);

/* ==========================================
 * 2. DATA UNTUK FORM
 * ========================================== */

$daftarUser = kasus_daftar_user($conn);
$petaUser   = [];
foreach ($daftarUser as $u) {
    $petaUser[(int) $u['id']] = $u;
}

$anggotaTerpilih = array_map(
    static fn ($a) => (int) $a['user_id'],
    kasus_penugasan_daftar($conn, (int) $kasus_id)
);

$pesan = '';

/* ==========================================
 * 3. PROSES SIMPAN
 * ========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('edit_kasus');

    $judul     = trim((string) ($_POST['judul'] ?? ''));
    $deskripsi = trim((string) ($_POST['deskripsi'] ?? ''));
    $kategori  = trim((string) ($_POST['kategori'] ?? ''));
    $urgensi   = (string) ($_POST['tingkat_urgensi'] ?? 'Sedang');
    $status    = (string) ($_POST['status_kasus'] ?? 'Terbuka');
    $progres   = max(0, min(100, (int) ($_POST['progres'] ?? 0)));
    $lokasi    = trim((string) ($_POST['nama_lokasi'] ?? ''));
    $latStr    = trim((string) ($_POST['garis_lintang'] ?? ''));
    $lngStr    = trim((string) ($_POST['garis_bujur'] ?? ''));
    $target    = trim((string) ($_POST['target_selesai'] ?? ''));
    $pjId      = (int) ($_POST['penanggung_jawab_id'] ?? 0);
    $catatan   = trim((string) ($_POST['catatan_status'] ?? ''));

    $anggota = array_values(array_unique(array_filter(
        array_map('intval', (array) ($_POST['anggota'] ?? [])),
        static fn ($v) => $v > 0
    )));

    if ($judul === '') {
        $pesan = 'Judul kasus wajib diisi.';
    } elseif (mb_strlen($judul) > 200) {
        $pesan = 'Judul maksimal 200 karakter.';
    } elseif (mb_strlen($deskripsi) > 20000) {
        $pesan = 'Deskripsi maksimal 20.000 karakter.';
    } elseif (!in_array($urgensi, kasus_urgensi_daftar(), true)) {
        $pesan = 'Tingkat urgensi tidak dikenal.';
    } elseif (!in_array($status, kasus_status_daftar(), true)) {
        $pesan = 'Status kasus tidak dikenal.';
    } elseif ($kategori !== '' && !in_array($kategori, kasus_kategori_daftar(), true)) {
        $pesan = 'Kategori tidak dikenal.';
    } elseif (mb_strlen($lokasi) > 200) {
        $pesan = 'Nama lokasi maksimal 200 karakter.';
    } elseif ($target !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $target)) {
        $pesan = 'Tanggal target tidak valid.';
    }

    $lat = null;
    $lng = null;
    if ($pesan === '' && ($latStr !== '' || $lngStr !== '')) {
        if (!is_numeric($latStr) || !is_numeric($lngStr)) {
            $pesan = 'Koordinat harus berupa angka.';
        } else {
            $lat = (float) $latStr;
            $lng = (float) $lngStr;
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $pesan = 'Koordinat di luar rentang yang sah.';
            }
        }
    }

    if ($pesan === '' && $pjId > 0 && !isset($petaUser[$pjId])) {
        $pesan = 'Penanggung jawab tidak dikenal.';
    }
    if ($pesan === '' && $pjId < 1) {
        $pjId = (int) ($kasus['penanggung_jawab_id'] ?? 0);
    }
    $anggota = array_values(array_filter($anggota, static fn ($v) => isset($petaUser[$v]) && $v !== $pjId));

    if ($pesan === '') {
        $statusLama  = (string) $kasus['status_kasus'];
        $progresLama = (int) $kasus['progres'];
        $targetVal   = $target !== '' ? $target : null;
        $pjVal       = $pjId > 0 ? $pjId : null;
        $deskVal     = $deskripsi !== '' ? $deskripsi : null;
        $lokVal      = $lokasi !== '' ? $lokasi : null;
        $katVal      = $kategori !== '' ? $kategori : null;

        $diperbarui = kasus_perbarui($conn, [
            'id'                 => (int) $kasus_id,
            'judul'              => $judul,
            'deskripsi'          => $deskVal,
            'nama_lokasi'        => $lokVal,
            'garis_lintang'      => $lat,
            'garis_bujur'        => $lng,
            'tingkat_urgensi'    => $urgensi,
            'status_kasus'       => $status,
            'progres'            => $progres,
            'kategori'           => $katVal,
            'target_selesai'     => $targetVal,
            'penanggung_jawab_id' => $pjVal,
        ]);

        if (!$diperbarui) {
            $pesan = 'Perubahan gagal disimpan. Silakan coba lagi.';
        } else {
            kasus_sinkron_tim($conn, (int) $kasus_id, $anggota, $uid);

                /* Linimasa bila status atau progres berubah. */
                if ($status !== $statusLama || $progres !== $progresLama) {
                    kasus_catat_status(
                        $conn,
                        (int) $kasus_id,
                        $statusLama,
                        $status,
                        $progres,
                        $catatan !== '' ? $catatan : null,
                        $uid
                    );
                }

                catat_audit(
                    $conn,
                    'edit_kasus',
                    'kasus',
                    (int) $kasus_id,
                    'Mengubah kasus ' . (string) $kasus['kode_kasus'],
                    [
                        'judul'   => (string) $kasus['judul'],
                        'status'  => $statusLama,
                        'progres' => $progresLama,
                    ],
                    ['judul' => $judul, 'status' => $status, 'progres' => $progres]
                );

                $kasusBaru = kasus_ambil($conn, (int) $kasus_id) ?? [];
                kasus_notif_pihak($conn, $kasusBaru, 'Kasus diperbarui: ' . $judul . ' (' . $status . ')', $uid);

                flash_set('sukses', 'Kasus berhasil diperbarui.');
                header('Location: detail_kasus.php?id=' . (int) $kasus_id);
                exit;
        }
    }

    /* Pertahankan input pengguna bila validasi gagal. */
    $kasus['judul']               = $judul;
    $kasus['deskripsi']           = $deskripsi;
    $kasus['kategori']            = $kategori;
    $kasus['tingkat_urgensi']     = $urgensi;
    $kasus['status_kasus']        = $status;
    $kasus['progres']             = $progres;
    $kasus['nama_lokasi']         = $lokasi;
    $kasus['garis_lintang']       = $latStr !== '' ? $latStr : null;
    $kasus['garis_bujur']         = $lngStr !== '' ? $lngStr : null;
    $kasus['target_selesai']      = $target;
    $kasus['penanggung_jawab_id'] = $pjId;
    $anggotaTerpilih              = $anggota;
}

/* ==========================================
 * 4. TAMPILAN
 * ========================================== */

$pjSekarang = (int) ($kasus['penanggung_jawab_id'] ?? 0);

mulai_halaman([
    'judul' => 'Edit Kasus',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="detail_kasus.php?id=' . (int) $kasus_id . '">← Detail Kasus</a>',
]);
?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Edit Kasus <?= e((string) $kasus['kode_kasus']) ?></h3>
            <span class="text-redup text-kecil">Perubahan tercatat pada riwayat audit dan linimasa kasus.</span>
        </div>
    </div>

    <form method="POST" action="edit_kasus.php?id=<?= (int) $kasus_id ?>">
        <?= csrf_field('edit_kasus') ?>

        <div class="kartu-badan">
            <?php if ($pesan !== ''): ?>
                <div class="alert alert-gagal" role="alert">
                    <span class="ikon"><?= ikon_sp('peringatan') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <div class="grup-form">
                <label class="label-form" for="judul">Judul Kasus <span class="wajib">*</span></label>
                <input type="text" id="judul" name="judul" maxlength="200" required value="<?= e((string) $kasus['judul']) ?>">
            </div>

            <div class="grup-form">
                <label class="label-form" for="deskripsi">Deskripsi</label>
                <textarea id="deskripsi" name="deskripsi" maxlength="20000"><?= e((string) ($kasus['deskripsi'] ?? '')) ?></textarea>
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="kategori">Kategori</label>
                    <select id="kategori" name="kategori">
                        <option value="">— Pilih kategori —</option>
                        <?php foreach (kasus_kategori_daftar() as $kat): ?>
                            <option value="<?= e($kat) ?>" <?= (string) ($kasus['kategori'] ?? '') === $kat ? 'selected' : '' ?>><?= e($kat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grup-form">
                    <label class="label-form" for="tingkat_urgensi">Tingkat Urgensi <span class="wajib">*</span></label>
                    <select id="tingkat_urgensi" name="tingkat_urgensi" required>
                        <?php foreach (kasus_urgensi_daftar() as $urg): ?>
                            <option value="<?= e($urg) ?>" <?= (string) $kasus['tingkat_urgensi'] === $urg ? 'selected' : '' ?>><?= e($urg) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="grup-form">
                    <label class="label-form" for="status_kasus">Status</label>
                    <select id="status_kasus" name="status_kasus">
                        <?php foreach (kasus_status_daftar() as $st): ?>
                            <option value="<?= e($st) ?>" <?= (string) $kasus['status_kasus'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grup-form">
                    <label class="label-form" for="progres">Progres (%)</label>
                    <input type="number" id="progres" name="progres" min="0" max="100" step="1" value="<?= (int) $kasus['progres'] ?>">
                </div>
                <div class="grup-form">
                    <label class="label-form" for="target_selesai">Target Selesai</label>
                    <input type="date" id="target_selesai" name="target_selesai" value="<?= e((string) ($kasus['target_selesai'] ?? '')) ?>">
                </div>
            </div>

            <div class="grup-form">
                <label class="label-form" for="catatan_status">Catatan Perubahan Status (opsional)</label>
                <input type="text" id="catatan_status" name="catatan_status" maxlength="255"
                       placeholder="Misalnya: menunggu verifikasi lapangan">
                <span class="text-redup text-kecil">Terisi otomatis pada linimasa bila status/progres berubah.</span>
            </div>

            <div class="grup-form">
                <label class="label-form" for="nama_lokasi">Nama Lokasi</label>
                <input type="text" id="nama_lokasi" name="nama_lokasi" maxlength="200" value="<?= e((string) ($kasus['nama_lokasi'] ?? '')) ?>">
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="garis_lintang">Garis Lintang</label>
                    <input type="text" id="garis_lintang" name="garis_lintang" value="<?= e((string) ($kasus['garis_lintang'] ?? '')) ?>">
                </div>
                <div class="grup-form">
                    <label class="label-form" for="garis_bujur">Garis Bujur</label>
                    <input type="text" id="garis_bujur" name="garis_bujur" value="<?= e((string) ($kasus['garis_bujur'] ?? '')) ?>">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="penanggung_jawab_id">Penanggung Jawab (PIC)</label>
                    <select id="penanggung_jawab_id" name="penanggung_jawab_id">
                        <?php foreach ($daftarUser as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= $pjSekarang === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['nama']) ?> (<?= e($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="grup-form">
                    <label class="label-form" for="anggota">Anggota Tim</label>
                    <select id="anggota" name="anggota[]" multiple size="6">
                        <?php foreach ($daftarUser as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $anggotaTerpilih, true) ? 'selected' : '' ?>>
                                <?= e($u['nama']) ?> (<?= e($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="kartu-kaki">
            <button type="submit" class="tombol tombol-utama">Simpan Perubahan</button>
            <a class="tombol tombol-garis" href="detail_kasus.php?id=<?= (int) $kasus_id ?>">Batal</a>
            <?php if ($boleh_hapus): ?>
                <a class="tombol tombol-bahaya" style="margin-left:auto"
                   href="hapus_kasus.php?id=<?= (int) $kasus_id ?>">Hapus Kasus</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php akhiri_halaman(); ?>
