<?php
/**
 * distribusi_laporan.php — Pengiriman laporan ke pihak luar
 * melalui email atau WhatsApp, dengan alur PERSETUJUAN.
 *
 *   · Mentor/Admin : mengajukan pengiriman (status: menunggu).
 *   · Admin        : menyetujui & mengirim, atau menolak.
 *
 * Pengiriman hanya terjadi setelah admin menyetujui — sesuai
 * kebutuhan "kirim setelah disetujui pengguna".
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['mentor', 'admin'], null, $conn);

global $conn;

$uid   = (int) sesi_user_id();
$role  = sesi_role();
$adalahAdmin = ($role === 'admin');

/* ---------------------------------------------------------------
 * Pengiriman sesungguhnya
 * ------------------------------------------------------------- */
/**
 * Kirim satu baris antrean. Mengubah status menjadi terkirim/gagal.
 *
 * @param array<string,mixed> $baris baris pengiriman_laporan
 * @return array{ok: bool, galat: ?string, mode: string}
 */
function distribusi_kirim(mysqli $conn, array $baris): array
{
    $kanal    = (string) $baris['kanal'];
    $penerima = (string) $baris['penerima'];
    $subjek   = (string) ($baris['subjek'] ?? '');
    $pesan    = (string) ($baris['pesan'] ?? '');

    /* Susun isi laporan bila tertaut ke satu laporan. */
    $ringkas = '';
    if (!empty($baris['laporan_id'])) {
        $stmt = $conn->prepare(
            'SELECT l.*, u.nama, u.divisi
               FROM laporan_harian l
               JOIN users u ON u.id = l.user_id
              WHERE l.id = ? LIMIT 1'
        );
        if ($stmt) {
            $lid = (int) $baris['laporan_id'];
            $stmt->bind_param('i', $lid);
            $stmt->execute();
            $res = $stmt->get_result();
            $lap = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if ($lap) {
                $ringkas = "Laporan kegiatan\n"
                    . "Nama    : {$lap['nama']}\n"
                    . ($lap['divisi'] !== null && $lap['divisi'] !== ''
                        ? "Divisi  : {$lap['divisi']}\n" : '')
                    . 'Tanggal : ' . tanggal_id((string) $lap['tanggal']) . "\n"
                    . ((string) $lap['jam_mulai'] !== ''
                        ? "Waktu   : {$lap['jam_mulai']}–{$lap['jam_selesai']}\n" : '')
                    . "Kegiatan: {$lap['kegiatan']}\n\n"
                    . "Deskripsi:\n{$lap['deskripsi']}\n";
                if (trim((string) $lap['hasil']) !== '') {
                    $ringkas .= "\nHasil:\n{$lap['hasil']}\n";
                }
                if (trim((string) $lap['kendala']) !== '') {
                    $ringkas .= "\nKendala:\n{$lap['kendala']}\n";
                }
            }
        }
    }

    $isiLengkap = trim($pesan . "\n\n" . $ringkas);

    if ($kanal === 'whatsapp') {
        return kirim_whatsapp($penerima, $isiLengkap);
    }

    /* Email */
    if ($subjek === '') {
        $subjek = 'Laporan Kegiatan Harian — ' . APP_SINGKAT;
    }
    $html = template_surat(
        e($subjek),
        nl2br(e($isiLengkap)),
        'Pesan otomatis dari ' . APP_NAMA . '. Mohon tidak membalas surel ini.'
    );
    return kirim_email($penerima, $subjek, $html, $isiLengkap);
}

/* ---------------------------------------------------------------
 * Proses POST
 * ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    /* ---------- Ajukan pengiriman ---------- */
    if ($aksi === 'distribusi_ajukan') {
        csrf_cek_atau_matikan('distribusi_ajukan');

        $laporanId = (int) ($_POST['laporan_id'] ?? 0);
        $kanal     = ((string) ($_POST['kanal'] ?? 'email') === 'whatsapp') ? 'whatsapp' : 'email';
        $penerima  = trim((string) ($_POST['penerima'] ?? ''));
        $namaPenerima = trim((string) ($_POST['nama_penerima'] ?? ''));
        $subjek    = trim((string) ($_POST['subjek'] ?? ''));
        $pesan     = trim((string) ($_POST['pesan'] ?? ''));

        $galat = [];
        if ($laporanId < 1) {
            $galat[] = 'Pilih laporan yang akan dikirim.';
        }
        if ($kanal === 'email' && !filter_var($penerima, FILTER_VALIDATE_EMAIL)) {
            $galat[] = 'Alamat email penerima tidak valid.';
        }
        if ($kanal === 'whatsapp' && wa_normalkan_nomor($penerima) === '') {
            $galat[] = 'Nomor WhatsApp penerima tidak valid.';
        }
        if ($subjek !== '' && mb_strlen($subjek) > 190) {
            $galat[] = 'Subjek maksimal 190 karakter.';
        }
        if (mb_strlen($pesan) > 4000) {
            $galat[] = 'Pesan maksimal 4000 karakter.';
        }

        if ($galat !== []) {
            flash_set('gagal', implode(' ', $galat));
        } else {
            $stmt = $conn->prepare(
                'INSERT INTO pengiriman_laporan
                    (laporan_id, penerima, nama_penerima, kanal, subjek, pesan,
                     status, diajukan_oleh)
                 VALUES (?, ?, ?, ?, ?, ?, \'menunggu\', ?)'
            );
            if ($stmt) {
                $namaPenerima = $namaPenerima !== '' ? $namaPenerima : null;
                $subjek       = $subjek !== '' ? $subjek : null;
                $pesan        = $pesan !== '' ? $pesan : null;
                $stmt->bind_param(
                    'isssssi',
                    $laporanId, $penerima, $namaPenerima, $kanal, $subjek, $pesan, $uid
                );
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    catat_audit($conn, 'distribusi_ajukan', 'pengiriman_laporan',
                        (int) $conn->insert_id,
                        'Pengajuan kirim laporan #' . $laporanId . ' via ' . $kanal,
                        null, null);
                    flash_set('sukses', 'Pengajuan pengiriman dibuat. Menunggu persetujuan admin.');
                } else {
                    flash_set('gagal', 'Gagal menyimpan pengajuan.');
                }
            } else {
                flash_set('gagal', 'Gagal menyiapkan pengajuan.');
            }
        }

        header('Location: distribusi_laporan.php');
        exit;
    }

    /* ---------- Setujui & kirim / tolak / kirim ulang (admin) ---------- */
    if (in_array($aksi, ['distribusi_setujui', 'distribusi_tolak', 'distribusi_ulang'], true)) {
        if (!$adalahAdmin) {
            http_response_code(403);
            exit('Hanya admin yang dapat menyetujui pengiriman.');
        }
        csrf_cek_atau_matikan($aksi);

        $id = (int) ($_POST['id'] ?? 0);

        $stmt = $conn->prepare('SELECT * FROM pengiriman_laporan WHERE id = ? LIMIT 1');
        $baris = null;
        if ($stmt) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $res = $stmt->get_result();
            $baris = $res ? $res->fetch_assoc() : null;
            $stmt->close();
        }

        if ($baris === null) {
            flash_set('gagal', 'Data pengiriman tidak ditemukan.');
        } elseif ($aksi === 'distribusi_tolak') {
            $stmt = $conn->prepare(
                "UPDATE pengiriman_laporan SET status = 'dibatalkan',
                        disetujui_oleh = ?, disetujui_pada = NOW()
                  WHERE id = ? AND status IN ('menunggu','disetujui','gagal')"
            );
            if ($stmt) {
                $stmt->bind_param('ii', $uid, $id);
                $stmt->execute();
                $stmt->close();
            }
            catat_audit($conn, 'distribusi_tolak', 'pengiriman_laporan', $id,
                'Pengiriman dibatalkan (laporan #' . (int) $baris['laporan_id'] . ')', null, null);
            flash_set('info', 'Pengiriman dibatalkan.');
        } else {
            $hasil = distribusi_kirim($conn, $baris);
            $status = $hasil['ok'] ? 'terkirim' : 'gagal';
            $galat  = $hasil['ok'] ? null : potong((string) $hasil['galat'], 255);

            $stmt = $conn->prepare(
                'UPDATE pengiriman_laporan
                    SET status = ?, disetujui_oleh = ?, disetujui_pada = NOW(),
                        dikirim_pada = NOW(), galat = ?
                  WHERE id = ?'
            );
            if ($stmt) {
                $stmt->bind_param('sssi', $status, $uid, $galat, $id);
                $stmt->execute();
                $stmt->close();
            }

            $label = $baris['kanal'] === 'whatsapp' ? 'WhatsApp' : 'email';
            if ($hasil['ok']) {
                catat_audit($conn, 'distribusi_terkirim', 'pengiriman_laporan', $id,
                    ucfirst($label) . ' terkirim ke ' . $baris['penerima'], null, null);
                flash_set('sukses', ucfirst($label) . ' berhasil dikirim ke ' . $baris['penerima'] . '.');
            } else {
                catat_audit($conn, 'distribusi_gagal', 'pengiriman_laporan', $id,
                    'Gagal kirim via ' . $label . ': ' . (string) $galat, null, null);
                flash_set('gagal', 'Pengiriman gagal: ' . (string) $galat);
            }
        }

        header('Location: distribusi_laporan.php');
        exit;
    }
}

/* ---------------------------------------------------------------
 * Data untuk tampilan
 * ------------------------------------------------------------- */
/* Laporan yang layak dikirim (sudah diperiksa), 50 terbaru. */
$laporanSiap = [];
$res = $conn->query(
    "SELECT l.id, l.tanggal, l.kegiatan, u.nama
       FROM laporan_harian l
       JOIN users u ON u.id = l.user_id
      WHERE l.status = 'diperiksa'
      ORDER BY l.tanggal DESC, l.id DESC
      LIMIT 50"
);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $laporanSiap[] = $r;
    }
}

$statusSaring = (string) ($_GET['status'] ?? '');
$diizinkan = ['menunggu', 'disetujui', 'terkirim', 'gagal', 'dibatalkan'];

$sql = 'SELECT p.*, l.kegiatan, l.tanggal AS tgl_laporan,
               ua.nama AS pengaju, ub.nama AS penyetuju
          FROM pengiriman_laporan p
          LEFT JOIN laporan_harian l ON l.id = p.laporan_id
          LEFT JOIN users ua ON ua.id = p.diajukan_oleh
          LEFT JOIN users ub ON ub.id = p.disetujui_oleh';
if (in_array($statusSaring, $diizinkan, true)) {
    $sql .= " WHERE p.status = '" . $conn->real_escape_string($statusSaring) . "'";
}
$sql .= ' ORDER BY p.id DESC LIMIT 100';

$daftar = [];
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $daftar[] = $r;
    }
}

$menunggu = 0;
$res = $conn->query("SELECT COUNT(*) AS n FROM pengiriman_laporan WHERE status = 'menunggu'");
if ($res && ($r = $res->fetch_assoc())) {
    $menunggu = (int) $r['n'];
}

$aksiTopbar = '';
if (wa_aktif()) {
    $aksiTopbar = '<span class="badge badge-hijau badge-tanpa-titik">WhatsApp aktif</span>';
} else {
    $aksiTopbar = '<span class="badge badge-kuning badge-tanpa-titik">WhatsApp nonaktif</span>';
}

mulai_halaman([
    'judul'  => 'Pengiriman Laporan',
    'crumb'  => APP_SINGKAT,
    'aktif'  => 'distribusi',
    'aksi'   => $aksiTopbar,
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Ajukan pengiriman</h2>
    </div>
    <div class="kartu-badan">
        <?php if ($laporanSiap === []): ?>
            <div class="alert alert-info">
                <span class="ikon">i</span>
                <span>Belum ada laporan berstatus <strong>Diperiksa</strong> yang siap dikirim.</span>
            </div>
        <?php else: ?>
            <form method="post" action="distribusi_laporan.php">
                <?= csrf_field('distribusi_ajukan') ?>
                <input type="hidden" name="aksi" value="distribusi_ajukan">

                <div class="baris-form">
                    <div class="kolom">
                        <label>Laporan
                            <select name="laporan_id" required>
                                <option value="">— pilih laporan —</option>
                                <?php foreach ($laporanSiap as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>">
                                        #<?= (int) $l['id'] ?> · <?= e(tanggal_id((string) $l['tanggal'])) ?>
                                        · <?= e((string) $l['nama']) ?> · <?= e(potong((string) $l['kegiatan'], 40)) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <div class="kolom">
                        <label>Kanal
                            <select name="kanal" id="kanalPilih">
                                <option value="email">Email</option>
                                <option value="whatsapp">WhatsApp</option>
                            </select>
                        </label>
                    </div>
                </div>

                <div class="baris-form">
                    <div class="kolom">
                        <label>Penerima (email / nomor WA)
                            <input type="text" name="penerima" required
                                   placeholder="nama@instansi.go.id atau 0812xxxxxxx">
                        </label>
                    </div>
                    <div class="kolom">
                        <label>Nama penerima <span class="bantuan">opsional</span>
                            <input type="text" name="nama_penerima" placeholder="mis. Pembimbing Lapangan">
                        </label>
                    </div>
                </div>

                <label>Subjek <span class="bantuan">khusus email; dikosongkan = otomatis</span>
                    <input type="text" name="subjek" maxlength="190">
                </label>

                <label>Pesan pengantar <span class="bantuan">opsional</span>
                    <textarea name="pesan" rows="3" placeholder="mis. Berikut laporan kegiatan minggu ini."></textarea>
                </label>

                <div class="kartu-kaki" style="padding-left:0;padding-right:0">
                    <span class="text-redup" style="font-size:13px">
                        Pengajuan akan menunggu persetujuan admin sebelum dikirim.
                    </span>
                    <button class="tombol tombol-utama" type="submit" style="margin-left:auto">Ajukan pengiriman</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($menunggu > 0): ?>
<div class="kartu">
    <div class="kartu-kepala">
        <h2>Menunggu persetujuan</h2>
        <div class="aksi"><span class="badge badge-kuning badge-tanpa-titik"><?= (int) $menunggu ?> menunggu</span></div>
    </div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
        <table class="tabel">
            <thead>
                <tr>
                    <th>Laporan</th><th>Penerima</th><th>Kanal</th>
                    <th>Diajukan oleh</th><th class="text-kanan">Tindakan</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($daftar as $d): ?>
                <?php if ($d['status'] !== 'menunggu') { continue; } ?>
                <tr>
                    <td>
                        <?php if ($d['laporan_id']): ?>#<?= (int) $d['laporan_id'] ?> · <?php endif; ?>
                        <?= e(potong((string) ($d['kegiatan'] ?? '—'), 40)) ?>
                    </td>
                    <td>
                        <?= e((string) $d['penerima']) ?>
                        <?php if (!empty($d['nama_penerima'])): ?>
                            <div class="text-redup" style="font-size:12px"><?= e((string) $d['nama_penerima']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge badge-navy badge-tanpa-titik"><?= e((string) $d['kanal']) ?></span></td>
                    <td><?= e((string) ($d['pengaju'] ?? '—')) ?></td>
                    <td class="text-kanan">
                        <?php if ($adalahAdmin): ?>
                            <div class="aksi-baris" style="justify-content:flex-end">
                                <form method="post" action="distribusi_laporan.php" style="display:inline">
                                    <?= csrf_field('distribusi_setujui') ?>
                                    <input type="hidden" name="aksi" value="distribusi_setujui">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button class="tombol tombol-kecil tombol-utama" type="submit">Setujui &amp; kirim</button>
                                </form>
                                <form method="post" action="distribusi_laporan.php" style="display:inline"
                                      onsubmit="return confirm('Tolak pengiriman ini?');">
                                    <?= csrf_field('distribusi_tolak') ?>
                                    <input type="hidden" name="aksi" value="distribusi_tolak">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button class="tombol tombol-kecil tombol-garis" type="submit">Tolak</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <span class="text-redup" style="font-size:13px">Menunggu admin</span>
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

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Riwayat pengiriman</h2>
        <div class="aksi">
            <form method="get" action="distribusi_laporan.php" class="kalender-filter">
                <select name="status" onchange="this.form.submit()">
                    <option value="">Semua status</option>
                    <?php foreach ($diizinkan as $s): ?>
                        <option value="<?= e($s) ?>" <?= $statusSaring === $s ? 'selected' : '' ?>>
                            <?= e(label_status_kirim($s)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
    <div class="kartu-badan padat">
        <?php if ($daftar === []): ?>
            <div class="tabel-kosong">Belum ada pengiriman.</div>
        <?php else: ?>
            <div class="bungkus-tabel">
            <table class="tabel">
                <thead>
                    <tr>
                        <th>#</th><th>Laporan</th><th>Penerima</th><th>Kanal</th>
                        <th>Status</th><th>Pengaju</th><th>Penyetuju</th><th>Waktu</th>
                        <th class="text-kanan">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($daftar as $d): ?>
                    <tr>
                        <td><?= (int) $d['id'] ?></td>
                        <td><?= $d['laporan_id'] ? '#' . (int) $d['laporan_id'] : '—' ?></td>
                        <td><?= e((string) $d['penerima']) ?></td>
                        <td><?= e((string) $d['kanal']) ?></td>
                        <td>
                            <span class="badge badge-tanpa-titik <?= e(kelas_status_kirim((string) $d['status'])) ?>">
                                <?= e(label_status_kirim((string) $d['status'])) ?>
                            </span>
                            <?php if (!empty($d['galat'])): ?>
                                <div class="text-redup" style="font-size:12px"><?= e(potong((string) $d['galat'], 60)) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) ($d['pengaju'] ?? '—')) ?></td>
                        <td><?= e((string) ($d['penyetuju'] ?? '—')) ?></td>
                        <td><?= e($d['dikirim_pada'] !== null
                                ? tanggal_id((string) $d['dikirim_pada'], 'd M Y H:i')
                                : tanggal_id((string) $d['dibuat_pada'], 'd M Y H:i')) ?></td>
                        <td class="text-kanan">
                            <?php if ($adalahAdmin && in_array($d['status'], ['gagal', 'disetujui'], true)): ?>
                                <form method="post" action="distribusi_laporan.php" style="display:inline">
                                    <?= csrf_field('distribusi_ulang') ?>
                                    <input type="hidden" name="aksi" value="distribusi_ulang">
                                    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                    <button class="tombol tombol-kecil tombol-garis" type="submit">Kirim ulang</button>
                                </form>
                            <?php else: ?>
                                <span class="text-redup">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
akhiri_halaman();
