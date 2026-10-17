<?php
/**
 * buat_kasus.php — membuat kasus baru (modul Pelacakan Kasus multi-pengguna).
 *
 * Semua pengguna yang sudah masuk boleh membuat kasus. Pembuat otomatis
 * menjadi pembuat (dibuat_oleh) dan, bila tak memilih PIC lain, sekaligus
 * penanggung jawab. Bila teks lokasi diisi tanpa koordinat, sistem mencoba
 * geokode otomatis (master wilayah + cache, lalu Nominatim).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();

$uid  = (int) sesi_user_id();
$role = sesi_role();

/* ==========================================
 * 1. NILAI AWAL FORM
 * ========================================== */

$draft = [
    'judul'               => '',
    'deskripsi'           => '',
    'kategori'            => '',
    'tingkat_urgensi'     => 'Sedang',
    'status_kasus'        => 'Terbuka',
    'progres'             => 0,
    'nama_lokasi'         => '',
    'garis_lintang'       => '',
    'garis_bujur'         => '',
    'target_selesai'      => '',
    'penanggung_jawab_id' => $uid,
];
$anggotaTerpilih = [];

$pesan = '';

/* ==========================================
 * 2. PROSES SIMPAN
 * ========================================== */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_cek_atau_matikan('buat_kasus');

    $draft['judul']         = trim((string) ($_POST['judul'] ?? ''));
    $draft['deskripsi']     = trim((string) ($_POST['deskripsi'] ?? ''));
    $draft['kategori']      = trim((string) ($_POST['kategori'] ?? ''));
    $draft['tingkat_urgensi'] = (string) ($_POST['tingkat_urgensi'] ?? 'Sedang');
    $draft['status_kasus']  = (string) ($_POST['status_kasus'] ?? 'Terbuka');
    $draft['progres']       = (int) ($_POST['progres'] ?? 0);
    $draft['nama_lokasi']   = trim((string) ($_POST['nama_lokasi'] ?? ''));
    $draft['garis_lintang'] = trim((string) ($_POST['garis_lintang'] ?? ''));
    $draft['garis_bujur']   = trim((string) ($_POST['garis_bujur'] ?? ''));
    $draft['target_selesai'] = trim((string) ($_POST['target_selesai'] ?? ''));
    $draft['penanggung_jawab_id'] = (int) ($_POST['penanggung_jawab_id'] ?? 0);

    $anggotaTerpilih = array_values(array_filter(
        array_map('intval', (array) ($_POST['anggota'] ?? [])),
        static fn ($v) => $v > 0
    ));

    /* ---- Validasi ---- */
    if ($draft['judul'] === '') {
        $pesan = 'Judul kasus wajib diisi.';
    } elseif (mb_strlen($draft['judul']) > 200) {
        $pesan = 'Judul maksimal 200 karakter.';
    } elseif (mb_strlen($draft['deskripsi']) > 20000) {
        $pesan = 'Deskripsi maksimal 20.000 karakter.';
    } elseif (!in_array($draft['tingkat_urgensi'], kasus_urgensi_daftar(), true)) {
        $pesan = 'Tingkat urgensi tidak dikenal.';
    } elseif (!in_array($draft['status_kasus'], kasus_status_daftar(), true)) {
        $pesan = 'Status kasus tidak dikenal.';
    } elseif ($draft['kategori'] !== '' && !in_array($draft['kategori'], kasus_kategori_daftar(), true)) {
        $pesan = 'Kategori tidak dikenal.';
    } elseif (mb_strlen($draft['nama_lokasi']) > 200) {
        $pesan = 'Nama lokasi maksimal 200 karakter.';
    } elseif ($draft['target_selesai'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $draft['target_selesai'])) {
        $pesan = 'Tanggal target tidak valid.';
    }

    /* Koordinat: kosong atau angka dalam rentang yang wajar. */
    $lat = null;
    $lng = null;
    if ($pesan === '' && ($draft['garis_lintang'] !== '' || $draft['garis_bujur'] !== '')) {
        if (!is_numeric($draft['garis_lintang']) || !is_numeric($draft['garis_bujur'])) {
            $pesan = 'Koordinat harus berupa angka.';
        } else {
            $lat = (float) $draft['garis_lintang'];
            $lng = (float) $draft['garis_bujur'];
            if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
                $pesan = 'Koordinat di luar rentang yang sah.';
            }
        }
    }

    /* Penanggung jawab harus pengguna aktif yang sah. */
    $petaUser = [];
    foreach (kasus_daftar_user($conn) as $u) {
        $petaUser[(int) $u['id']] = $u;
    }
    if ($pesan === '' && $draft['penanggung_jawab_id'] > 0 && !isset($petaUser[$draft['penanggung_jawab_id']])) {
        $pesan = 'Penanggung jawab tidak dikenal.';
    }
    if ($pesan === '' && ($draft['penanggung_jawab_id'] < 1)) {
        $draft['penanggung_jawab_id'] = $uid;
    }
    /* Anggota tim: hanya id yang sah, dan bukan PIC utama. */
    $anggotaTerpilih = array_values(array_unique(array_filter(
        $anggotaTerpilih,
        static fn ($v) => isset($petaUser[$v]) && $v !== (int) $draft['penanggung_jawab_id']
    )));

    if ($pesan === '') {
        $kode = kasus_buat_kode($conn);
        $progres = max(0, min(100, (int) $draft['progres']));
        $target = $draft['target_selesai'] !== '' ? $draft['target_selesai'] : null;
        $pj = $draft['penanggung_jawab_id'] > 0 ? $draft['penanggung_jawab_id'] : null;
        $lokasi = $draft['nama_lokasi'] !== '' ? $draft['nama_lokasi'] : null;

        $kasusId = kasus_simpan($conn, [
            'kode_kasus'        => $kode,
            'judul'             => $draft['judul'],
            'deskripsi'         => $draft['deskripsi'] !== '' ? $draft['deskripsi'] : null,
            'nama_lokasi'       => $lokasi,
            'garis_lintang'     => $lat,
            'garis_bujur'       => $lng,
            'tingkat_urgensi'   => $draft['tingkat_urgensi'],
            'status_kasus'      => $draft['status_kasus'],
            'progres'           => $progres,
            'kategori'          => $draft['kategori'] !== '' ? $draft['kategori'] : null,
            'sumber'            => 'Manual',
            'target_selesai'    => $target,
            'dibuat_oleh'       => $uid,
            'penanggung_jawab_id' => $pj,
        ]);

        if ($kasusId < 1) {
            $pesan = 'Kasus gagal disimpan. Silakan coba lagi.';
        } else {
            /* Linimasa awal + anggota tim. */
            kasus_catat_status($conn, $kasusId, null, $draft['status_kasus'], $progres, 'Kasus dibuat.', $uid);
            kasus_sinkron_tim($conn, $kasusId, $anggotaTerpilih, $uid);

            /* Geokode otomatis bila lokasi ada tetapi koordinat kosong. */
            if ($lat === null && $lng === null && $lokasi !== null && function_exists('wilayah_geokode_teks')) {
                @set_time_limit(30);
                $g = wilayah_geokode_teks($conn, $lokasi);
                if ($g !== null) {
                    $glat = (float) $g['lat'];
                    $glng = (float) $g['lng'];
                    $upd = $conn->prepare('UPDATE kasus SET garis_lintang = ?, garis_bujur = ? WHERE id = ?');
                    if ($upd) {
                        $upd->bind_param('ddi', $glat, $glng, $kasusId);
                        $upd->execute();
                        $upd->close();
                    }
                }
            }

            catat_audit(
                $conn,
                'buat_kasus',
                'kasus',
                $kasusId,
                'Membuat kasus ' . $kode . ' — ' . $draft['judul']
            );

            $kasusBaru = kasus_ambil($conn, $kasusId) ?? [];
            kasus_notif_pihak($conn, $kasusBaru, 'Kasus baru: ' . $draft['judul'], $uid);

            flash_set('sukses', 'Kasus ' . $kode . ' berhasil dibuat.');
            header('Location: detail_kasus.php?id=' . $kasusId);
            exit;
        }
    }
}

/* ==========================================
 * 3. TAMPILAN
 * ========================================== */

$daftarUser = kasus_daftar_user($conn);

mulai_halaman([
    'judul' => 'Buat Kasus',
    'crumb' => 'Kasus',
    'aktif' => 'kasus',
    'aksi'  => '<a class="tombol tombol-garis tombol-kecil" href="dashboard_kasus.php">← Daftar Kasus</a>',
]);
?>

<section class="kartu">
    <div class="kartu-kepala">
        <div class="flex-1">
            <h3>Buat Kasus Baru</h3>
            <span class="text-redup text-kecil">
                Isi judul dan minimal salah satu detail. Kolom bertanda
                <span class="wajib">*</span> wajib diisi.
            </span>
        </div>
    </div>

    <form method="POST" action="buat_kasus.php">
        <?= csrf_field('buat_kasus') ?>

        <div class="kartu-badan">
            <?php if ($pesan !== ''): ?>
                <div class="alert alert-gagal" role="alert">
                    <span class="ikon"><?= ikon_sp('peringatan') ?></span>
                    <span><?= e($pesan) ?></span>
                </div>
            <?php endif; ?>

            <div class="grup-form">
                <label class="label-form" for="judul">Judul Kasus <span class="wajib">*</span></label>
                <input type="text" id="judul" name="judul" maxlength="200" required
                       value="<?= e($draft['judul']) ?>" placeholder="Ringkasan singkat permasalahan">
            </div>

            <div class="grup-form">
                <label class="label-form" for="deskripsi">Deskripsi</label>
                <textarea id="deskripsi" name="deskripsi" maxlength="20000"
                          placeholder="Uraian permasalahan, dampak, dan konteks lainnya"><?= e($draft['deskripsi']) ?></textarea>
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="kategori">Kategori</label>
                    <select id="kategori" name="kategori">
                        <option value="">— Pilih kategori —</option>
                        <?php foreach (kasus_kategori_daftar() as $kat): ?>
                            <option value="<?= e($kat) ?>" <?= $draft['kategori'] === $kat ? 'selected' : '' ?>><?= e($kat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="tingkat_urgensi">Tingkat Urgensi <span class="wajib">*</span></label>
                    <select id="tingkat_urgensi" name="tingkat_urgensi" required>
                        <?php foreach (kasus_urgensi_daftar() as $urg): ?>
                            <option value="<?= e($urg) ?>" <?= $draft['tingkat_urgensi'] === $urg ? 'selected' : '' ?>><?= e($urg) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="grid grid-3">
                <div class="grup-form">
                    <label class="label-form" for="status_kasus">Status Awal</label>
                    <select id="status_kasus" name="status_kasus">
                        <?php foreach (kasus_status_daftar() as $st): ?>
                            <option value="<?= e($st) ?>" <?= $draft['status_kasus'] === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="progres">Progres (%)</label>
                    <input type="number" id="progres" name="progres" min="0" max="100" step="1"
                           value="<?= (int) $draft['progres'] ?>">
                </div>

                <div class="grup-form">
                    <label class="label-form" for="target_selesai">Target Selesai</label>
                    <input type="date" id="target_selesai" name="target_selesai"
                           value="<?= e($draft['target_selesai']) ?>">
                </div>
            </div>

            <div class="grup-form">
                <label class="label-form" for="nama_lokasi">Nama Lokasi</label>
                <input type="text" id="nama_lokasi" name="nama_lokasi" maxlength="200"
                       value="<?= e($draft['nama_lokasi']) ?>"
                       placeholder="Contoh: Desa Duri, Kecamatan Mandau, Kabupaten Bengkalis">
                <span class="text-redup text-kecil">
                    Jika koordinat dikosongkan, sistem mencoba mencari lokasi secara otomatis dari teks ini.
                </span>
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="garis_lintang">Garis Lintang (opsional)</label>
                    <input type="text" id="garis_lintang" name="garis_lintang"
                           value="<?= e($draft['garis_lintang']) ?>" placeholder="Contoh: 1.28111">
                </div>
                <div class="grup-form">
                    <label class="label-form" for="garis_bujur">Garis Bujur (opsional)</label>
                    <input type="text" id="garis_bujur" name="garis_bujur"
                           value="<?= e($draft['garis_bujur']) ?>" placeholder="Contoh: 101.21345">
                </div>
            </div>

            <div class="grid grid-2">
                <div class="grup-form">
                    <label class="label-form" for="penanggung_jawab_id">Penanggung Jawab (PIC)</label>
                    <select id="penanggung_jawab_id" name="penanggung_jawab_id">
                        <?php foreach ($daftarUser as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= (int) $draft['penanggung_jawab_id'] === (int) $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['nama']) ?> (<?= e($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="anggota">Anggota Tim (boleh lebih dari satu)</label>
                    <select id="anggota" name="anggota[]" multiple size="6">
                        <?php foreach ($daftarUser as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $anggotaTerpilih, true) ? 'selected' : '' ?>>
                                <?= e($u['nama']) ?> (<?= e($u['role']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="text-redup text-kecil">Pilih anggota tambahan bila kasus ditangani bersama.</span>
                </div>
            </div>
        </div>

        <div class="kartu-kaki">
            <button type="submit" class="tombol tombol-utama">Simpan Kasus</button>
            <a class="tombol tombol-garis" href="dashboard_kasus.php">Batal</a>
        </div>
    </form>
</section>

<?php akhiri_halaman(); ?>
