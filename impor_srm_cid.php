<?php
/**
 * impor_srm_cid.php — Impor dataset SRM & CID dari berkas Excel (khusus admin).
 *
 * Berkas harus memuat lembar "SRM" dan/atau "C&D" (nama lain: CID). Impor
 * bersifat ganti-semua per jenis: data lama pada tabel terkait dihapus lalu
 * diisi ulang dari berkas, dalam satu transaksi.
 *
 * Batas akses: admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

/**
 * Buang berkas kerja sementara dengan aman.
 */
function srm_cid_hapus_berkas(?string $berkas): void
{
    if ($berkas !== null && is_file($berkas)) {
        @unlink($berkas);
    }
}

/* ================================================================
 * Aksi tulis (POST)
 * ================================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aksi = (string) ($_POST['aksi'] ?? '');

    if ($aksi === 'impor') {
        csrf_cek_atau_matikan('impor');

        $jenis = (string) ($_POST['jenis'] ?? 'semua');
        if (!in_array($jenis, ['semua', 'srm', 'cid'], true)) {
            $jenis = 'semua';
        }

        $unggah = impor_simpan_tmp($_FILES['berkas'] ?? []);
        if (!$unggah['ok']) {
            flash_set('gagal', $unggah['galat']);
            header('Location: impor_srm_cid.php');
            exit;
        }

        $ekstensi = (string) $unggah['ekstensi'];
        if (!in_array($ekstensi, ['xlsx', 'xls'], true)) {
            srm_cid_hapus_berkas((string) $unggah['berkas']);
            flash_set('gagal', 'Berkas SRM/CID harus berformat Excel (.xlsx atau .xls).');
            header('Location: impor_srm_cid.php');
            exit;
        }

        // Berkas besar bisa memakan waktu; jangan biarkan batas eksekusi
        // memutus proses sebelum berkas kerja dibersihkan.
        @set_time_limit(0);

        $hasil = ['ok' => false, 'pesan' => 'Impor dibatalkan.'];
        try {
            $hasil = srm_cid_impor($conn, (string) $unggah['berkas'], $jenis);
        } catch (\Throwable $e) {
            error_log('impor_srm_cid: ' . $e->getMessage());
            $hasil = ['ok' => false, 'pesan' => 'Terjadi kesalahan saat mengimpor: ' . $e->getMessage()];
        } finally {
            srm_cid_hapus_berkas((string) $unggah['berkas']);
        }

        $ringkas = [];
        $adaGagal = false;
        foreach (['srm', 'cid'] as $k) {
            if (isset($hasil[$k]) && is_array($hasil[$k])) {
                $r = $hasil[$k];
                $ringkas[] = strtoupper($k) . ': ' . (int) ($r['masuk'] ?? 0) . ' masuk'
                    . ((int) ($r['gagal'] ?? 0) > 0 ? ', ' . (int) $r['gagal'] . ' gagal' : '');
                if (empty($r['ok'])) {
                    $adaGagal = true;
                }
            }
        }

        $pesan = 'Impor SRM/CID selesai. ' . implode(' | ', $ringkas);

        // Catat ke riwayat impor agar konsisten dengan jalur impor_laporan.php.
        // `$uid` baru diisi layout.php setelah blok POST ini, jadi ambil
        // langsung dari sesi agar tidak undefined saat impor diproses.
        impor_catat_sejarah(
            $conn, (int) sesi_user_id(), 'srm_cid', (string) $unggah['nama_asli'],
            (int) ($hasil['srm']['masuk'] ?? 0) + (int) ($hasil['cid']['masuk'] ?? 0)
                + (int) ($hasil['srm']['gagal'] ?? 0) + (int) ($hasil['cid']['gagal'] ?? 0),
            (int) ($hasil['srm']['masuk'] ?? 0) + (int) ($hasil['cid']['masuk'] ?? 0),
            0,
            (int) ($hasil['srm']['gagal'] ?? 0) + (int) ($hasil['cid']['gagal'] ?? 0),
            'srm=' . (int) ($hasil['srm']['masuk'] ?? 0) . ' cid=' . (int) ($hasil['cid']['masuk'] ?? 0),
            [],
            'srm,cid'
        );

        catat_audit(
            $conn, 'impor_srm_cid', null, null,
            'Impor SRM/CID (' . $jenis . ') dari ' . (string) $unggah['nama_asli'] . ': ' . implode(' | ', $ringkas),
            null, null
        );

        if (empty($hasil['ok']) || $adaGagal) {
            flash_set('gagal', (string) ($hasil['pesan'] ?? 'Impor tidak sepenuhnya berhasil.') . ' ' . $pesan);
        } else {
            flash_set('sukses', $pesan);
        }

        header('Location: impor_srm_cid.php');
        exit;
    }
}

/* ================================================================
 * Tampilan
 * ================================================================ */
$jSrm = ['total' => 0, 'terakhir' => null];
$res = $conn->query('SELECT COUNT(*) AS c, MAX(dibuat_pada) AS t FROM srm_kegiatan');
if ($res && ($r = $res->fetch_assoc())) {
    $jSrm = ['total' => (int) $r['c'], 'terakhir' => $r['t']];
}

$jCid = ['total' => 0, 'terakhir' => null];
$res = $conn->query('SELECT COUNT(*) AS c, MAX(dibuat_pada) AS t FROM cid_program');
if ($res && ($r = $res->fetch_assoc())) {
    $jCid = ['total' => (int) $r['c'], 'terakhir' => $r['t']];
}

mulai_halaman([
    'judul' => 'Impor SRM & CID',
    'crumb' => APP_SINGKAT,
    'aktif' => 'impor_srmcid',
    'aksi'  => '<a class="tombol tombol-garis" href="powerbi_ekspor.php">' . ikon_sp('grafik') . ' Ekspor Dataset</a>',
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Impor Data SRM &amp; CID</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Unggah berkas Excel yang memuat lembar <strong>SRM</strong> dan/atau
            <strong>C&amp;D</strong> (CID). Data lama pada tabel terkait diganti seluruhnya
            oleh isi berkas. Data langsung tersedia untuk tampilan di aplikasi maupun Power BI.
        </p>
    </div>
    <div class="kartu-badan">
        <div class="kalender-ringkas" style="padding:0;margin-bottom:18px">
            <div class="ringkas-butir">
                <span class="angka"><?= number_format($jSrm['total'], 0, ',', '.') ?></span>
                <span class="ket">Baris SRM</span>
            </div>
            <div class="ringkas-butir ringkas-hadir">
                <span class="angka"><?= number_format($jCid['total'], 0, ',', '.') ?></span>
                <span class="ket">Baris CID</span>
            </div>
        </div>

        <form method="post" action="impor_srm_cid.php" enctype="multipart/form-data">
            <?= csrf_field('impor') ?>
            <input type="hidden" name="aksi" value="impor">
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end">
                <label style="min-width:220px">Jenis data
                    <select name="jenis" required>
                        <option value="semua">SRM &amp; CID (keduanya)</option>
                        <option value="srm">Hanya SRM</option>
                        <option value="cid">Hanya CID</option>
                    </select>
                </label>
                <label style="flex:1;min-width:260px">Berkas Excel
                    <input type="file" name="berkas" accept=".xlsx,.xls" required>
                </label>
                <button class="tombol tombol-utama" type="submit">Impor Sekarang</button>
            </div>
            <p class="text-redup" style="margin-top:10px;font-size:12.5px">
                Maksimal <?= number_format(impor_maks_bytes() / 1048576, 1) ?> MB.
                Baris header dideteksi otomatis pada beberapa baris pertama.
                Berkas disimpan sementara lalu dihapus setelah impor.
            </p>
        </form>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala"><h2>Keterangan Lembar</h2></div>
    <div class="kartu-badan padat">
        <div class="bungkus-tabel">
        <table class="tabel">
            <thead>
                <tr><th>Lembar</th><th>Isi</th><th>Keterangan</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>SRM</strong></td>
                    <td>Kegiatan Social Relations Management</td>
                    <td>Kolom mencakup area, tanggal, PIC, kategori, judul &amp; detail issue, desa/kecamatan/kabupaten, stakeholder, action plan, tim PHR, value creation, status &amp; persentase.</td>
                </tr>
                <tr>
                    <td><strong>C&amp;D</strong></td>
                    <td>Program Community Involvement/Development (TJSL)</td>
                    <td>Kolom mencakup judul &amp; pilar program, desa (aset operasi/RK BOR/konflik sosial) beserta kode wilayah, penerima manfaat, potensi ekonomi &amp; penghematan, indikator lingkungan &amp; kesehatan, bulan, program, dan anggaran.</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php
akhiri_halaman();
