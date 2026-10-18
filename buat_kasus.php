<?php
/**
 * buat_kasus.php — membuat kasus baru (modul Pelacakan Kasus multi-pengguna).
 *
 * Semua pengguna yang sudah masuk boleh membuat kasus. Pembuat otomatis
 * menjadi pembuat (dibuat_oleh) dan, bila PIC dikosongkan, sekaligus
 * penanggung jawab. PIC & anggota tim DIIKETIK BEBAS (bukan dropdown):
 * nama yang cocok dengan pengguna terdaftar otomatis tertaut ke user_id
 * (kasus_cocok_pengguna), yang tidak cocok tetap tersimpan sebagai teks.
 * Bila teks lokasi diisi tanpa koordinat, sistem mencoba geokode otomatis
 * (master wilayah + cache, lalu Nominatim).
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
    'penanggung_jawab'    => sesi_nama(),
];
$anggotaTeks = '';

$pesan = '';

/* Dipakai untuk pencocokan ketikan dan pilihan saran. */
$daftarUser = kasus_daftar_user($conn);

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
    $draft['penanggung_jawab'] = (string) ($_POST['penanggung_jawab'] ?? '');
    $anggotaTeks               = (string) ($_POST['anggota_teks'] ?? '');

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

    /* PIC & anggota tim: ketik bebas, dicocokkan ke pengguna terdaftar. */
    if ($pesan === '' && mb_strlen($draft['penanggung_jawab']) > 150) {
        $pesan = 'Penanggung jawab maksimal 150 karakter.';
    }
    if ($pesan === '' && mb_strlen($anggotaTeks) > 4000) {
        $pesan = 'Daftar anggota tim maksimal 4.000 karakter.';
    }

    $pic        = ['teks' => '', 'user_id' => null];
    $anggotaTim = [];
    if ($pesan === '') {
        $pic = kasus_cocok_pengguna($daftarUser, $draft['penanggung_jawab']);

        /* Dikosongkan → pembuat menjadi PIC (perilaku lama dipertahankan). */
        if ($pic['teks'] === '') {
            $pic = ['teks' => sesi_nama(), 'user_id' => $uid];
        }

        $anggotaTim = kasus_tim_teks_cocok($daftarUser, $anggotaTeks);

        /* Anggota tidak boleh duplikat: nama sama dengan PIC, atau pengguna
           yang sama dengan PIC (bila PIC terdaftar). Pengguna TIDAK terdaftar
           (user_id null) tidak dianggap sama hanya karena sama-sama null. */
        $kunciPic = mb_strtolower($pic['teks']);
        $anggotaTim = array_values(array_filter(
            $anggotaTim,
            static fn ($a) => mb_strtolower($a['teks']) !== $kunciPic
                && ($pic['user_id'] === null || $a['user_id'] !== $pic['user_id'])
        ));
    }

    if ($pesan === '') {
        $kode = kasus_buat_kode($conn);
        $progres = max(0, min(100, (int) $draft['progres']));
        $target = $draft['target_selesai'] !== '' ? $draft['target_selesai'] : null;
        $pj = $pic['user_id'];
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
            'penanggung_jawab_teks' => $pic['teks'] !== '' ? $pic['teks'] : null,
        ]);

        if ($kasusId < 1) {
            $pesan = 'Kasus gagal disimpan. Silakan coba lagi.';
        } else {
            /* Linimasa awal + anggota tim. */
            kasus_catat_status($conn, $kasusId, null, $draft['status_kasus'], $progres, 'Kasus dibuat.', $uid);
            kasus_sinkron_tim_teks($conn, $kasusId, $anggotaTim, $uid);

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
                    <label class="label-form" for="penanggung_jawab">Penanggung Jawab (PIC)</label>
                    <input type="text" id="penanggung_jawab" name="penanggung_jawab" maxlength="150"
                           value="<?= e($draft['penanggung_jawab']) ?>"
                           placeholder="Nama atau surel PIC — ketik sendiri">
                    <span class="text-redup text-kecil">
                        Ketik bebas. Nama yang sama dengan pengguna terdaftar otomatis menerima notifikasi;
                        bila kosong, Anda menjadi PIC.
                    </span>
                </div>

                <div class="grup-form">
                    <label class="label-form" for="anggota_teks">Anggota Tim (boleh lebih dari satu)</label>
                    <textarea id="anggota_teks" name="anggota_teks" rows="4" maxlength="4000"
                              placeholder="Satu nama per baris, contoh:&#10;Budi Santoso&#10;Siti Aminah"><?= e($anggotaTeks) ?></textarea>
                    <span class="text-redup text-kecil">
                        Ketik mandiri tanpa harus memilih daftar. Nama yang cocok dengan pengguna
                        terdaftar tertaut otomatis; yang tidak cocok tetap tersimpan sebagai teks.
                    </span>
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
