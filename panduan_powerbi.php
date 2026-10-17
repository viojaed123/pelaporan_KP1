<?php
/**
 * panduan_powerbi.php - Panduan menyambungkan Power BI Desktop (jalur ODBC).
 *
 * Halaman bantu (khusus admin) yang merangkum langkah-langkah koneksi ODBC
 * ke basis data `pelaporan` beserta tips membuat visual peta lokasi.
 *
 * Batas akses: admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

wajib_login();
wajib_role(['admin'], null, $conn);

mulai_halaman([
    'judul' => 'Panduan Power BI (ODBC)',
    'crumb' => 'Admin',
    'aktif' => 'powerbi',
    'aksi'  => '<a class="tombol tombol-kecil" href="powerbi_ekspor.php">&larr; Ekspor Dataset</a>',
]);
?>

<div class="kartu">
    <div class="kartu-kepala">
        <h2>Menyambungkan Power BI Desktop lewat ODBC</h2>
        <p class="text-redup" style="margin:6px 0 0;font-size:13px">
            Jalur ini hanya memerlukan <strong>MySQL ODBC Driver</strong> (sudah terpasang bersama
            XAMPP) dan tidak memerlukan <em>MySQL Connector/NET</em>.
        </p>
    </div>
    <div class="kartu-badan">
        <ol style="margin:0;padding-left:20px;line-height:1.9">
            <li>
                Daftarkan DSN (sekali saja). Dari akar proyek jalankan PowerShell:
                <pre style="margin:8px 0;padding:10px;background:var(--abu-100,#f5f5f5);border-radius:6px;overflow:auto">powershell -ExecutionPolicy Bypass -File scripts\buat_dsn_powerbi.ps1</pre>
                Skrip mendaftarkan <em>User DSN</em> bernama <span class="text-mono">PelaporanBI</span>.
            </li>
            <li>
                Buka berkas <span class="text-mono">powerbi\pelaporan-odbc.pbids</span> (klik dua kali),
                atau di Power BI Desktop pilih <strong>Get Data &rarr; Database &rarr; ODBC</strong> lalu
                pilih DSN <span class="text-mono">PelaporanBI</span>.
            </li>
            <li>
                Saat diminta kredensial, pilih jenis <strong>Database</strong> dan isikan:
                <table class="tabel" style="margin-top:8px">
                    <tbody>
                        <tr><th style="width:180px">Pengguna</th><td class="text-mono">bi_reader</td></tr>
                        <tr><th>Kata sandi</th><td>lihat <span class="text-mono">AI_DB_READONLY_PASSWORD</span> pada <span class="text-mono">ai-service/.env</span></td></tr>
                        <tr><th>Mode</th><td>Import (disarankan)</td></tr>
                    </tbody>
                </table>
            </li>
            <li>Pilih VIEW yang diinginkan (mis. <span class="text-mono">vw_srm</span>, <span class="text-mono">vw_cid</span>, <span class="text-mono">vw_kasus</span>, <span class="text-mono">vw_laporan_harian</span>, <span class="text-mono">vw_absensi</span>) lalu klik <strong>Load</strong>.</li>
        </ol>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala"><h2>Membuat peta lokasi di Power BI</h2></div>
    <div class="kartu-badan">
        <p style="margin:0 0 10px">
            Hampir semua fakta analitik kini memiliki kolom <span class="text-mono">garis_lintang</span>,
            <span class="text-mono">garis_bujur</span>, <span class="text-mono">lokasi_teks</span>, dan
            <span class="text-mono">peta_url</span> (tautan Google Maps).
        </p>
        <ol style="margin:0;padding-left:20px;line-height:1.9">
            <li>Tambahkan visual <strong>Map</strong> (atau <em>Azure Maps</em>).</li>
            <li>Taruh <span class="text-mono">garis_lintang</span> pada <em>Latitude</em> dan <span class="text-mono">garis_bujur</span> pada <em>Longitude</em>.</li>
            <li>Gunakan <span class="text-mono">kabupaten_kota</span> / <span class="text-mono">kecamatan</span> / <span class="text-mono">nama</span> sebagai <em>Legend</em> atau <em>Tooltip</em>.</li>
            <li>Untuk membuka detail, gunakan kolom <span class="text-mono">peta_url</span> (jenis data <em>Web URL</em>).</li>
            <li>Dimensi master wilayah tersedia di <span class="text-mono">vw_wilayah</span> (187 titik: kabupaten, kecamatan, desa).</li>
        </ol>
    </div>
</div>

<div class="kartu">
    <div class="kartu-kepala"><h2>Bila terjadi galat</h2></div>
    <div class="kartu-badan">
        <div class="bungkus-tabel">
        <table class="tabel">
            <thead>
                <tr><th style="width:280px">Galat</th><th>Penanganan</th></tr>
            </thead>
            <tbody>
                <tr><td>DSN tidak muncul di daftar ODBC</td><td>Jalankan ulang <span class="text-mono">scripts\buat_dsn_powerbi.ps1</span>; pastikan memakai aplikasi Power BI <strong>64-bit</strong>.</td></tr>
                <tr><td>Driver tidak ditemukan</td><td>Pasang <em>MySQL ODBC Driver</em> (Unicode, 64-bit), lalu ulangi langkah 1.</td></tr>
                <tr><td>&#8220;Access denied&#8221; untuk <span class="text-mono">bi_reader</span></td><td>Periksa sandi pada <span class="text-mono">ai-service/.env</span>; minta admin menjalankan ulang <span class="text-mono">powerbi/sql/02_views.sql</span> sebagai root.</td></tr>
                <tr><td>Data tidak berubah setelah impor baru</td><td>Mode Import bersifat snapshot &mdash; klik <strong>Refresh</strong> di Power BI Desktop.</td></tr>
                <tr><td>Peta tidak menampilkan titik</td><td>Pastikan kolom koordinat dikenali sebagai angka, dan wilayah sudah tergeokode (lihat menu Peta Lokasi).</td></tr>
            </tbody>
        </table>
        </div>
        <p class="text-redup" style="margin-top:12px;font-size:13px">
            Rincian lengkap tersedia di <span class="text-mono">powerbi/README.md</span>.
        </p>
    </div>
</div>

<?php
akhiri_halaman();
