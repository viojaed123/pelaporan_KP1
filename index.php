<?php
/**
 * index.php — halaman pembuka (pintu masuk / ringkasan sistem).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

// Sudah masuk? Langsung ke ruang kerja.
if (sudah_masuk()) {
    header('Location: ' . halaman_default_peran(sesi_role()));
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAMA) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="icon" href="assets/img/favicon.png" type="image/png">
</head>
<body>
<div class="pembuka">
    <header class="pembuka-atas">
        <?= logo_phr() ?>
        <span>
            <strong style="font-size:16px"><?= e(APP_SINGKAT) ?></strong><br>
            <span style="font-size:12.5px;color:#a9c4dd"><?= e(APP_ORGANISASI) ?></span>
        </span>
        <span class="aksi-masuk">
            <a class="tombol tombol-garis" href="register.php" style="border-color:rgba(255,255,255,.6);color:#fff">Daftar</a>
            <a class="tombol tombol-utama" href="login.php">Masuk</a>
        </span>
    </header>

    <section class="hero">
        <div class="hero-visual">
            <div class="logo-bingkai">
                <span class="hero-cincin"></span>
                <span class="hero-cincin hero-cincin-2"></span>
                <img src="assets/img/logo-phr.png" alt="<?= e(APP_ORGANISASI) ?>">
            </div>
        </div>
        <span class="hero-bunga"><span class="hero-titik"></span>Platform Pelaporan Migas Terintegrasi</span>
        <h1>Sistem <span class="aksen">Pelaporan Kegiatan Harian</span></h1>
        <p>
            Pertamina Hulu Rokan (PHR) berkomitmen mengelola operasional migas secara terintegrasi dan bertanggung jawab. Sistem ini mempermudah pencatatan kegiatan harian, pemantauan, dokumentasi dan pelaporan terpusat untuk mendukung kinerja, akuntabilitas dan tata kelola yang baik.
        </p>
        <div class="aksi">
            <a class="tombol tombol-utama tombol-besar" href="login.php">Masuk ke Akun</a>
            <a class="tombol tombol-besar" href="register.php">Daftar Akun Baru</a>
        </div>
        <div class="papan-plang">
            <div class="pil"><div class="angka">1</div><div class="ket">Satu sumber data kegiatan harian</div></div>
            <div class="pil"><div class="angka">3</div><div class="ket">Alur: catat, periksa, sahkan</div></div>
            <div class="pil"><div class="angka">24/7</div><div class="ket">Diakses aman dari perangkat apa pun</div></div>
        </div>
    </section>

    <!-- ================= SOROTAN FITUR ================= -->
    <section class="bagian" id="sorotan">
        <div class="sorotan-grid">
            <article class="sorotan">
                <span class="sorotan-ikon">&#9203;</span>
                <h3>Rekap Otomatis</h3>
                <p>Data laporan, absensi, dan kasus terangkum rapi jadi rekap siap ekspor tanpa olah manual.</p>
            </article>
            <article class="sorotan">
                <span class="sorotan-ikon">&#128202;</span>
                <h3>Visualisasi Grafis</h3>
                <p>Tren, distribusi, dan sudut pandang langsung terlihat lewat grafik interaktif yang mudah dipahami.</p>
            </article>
            <article class="sorotan">
                <span class="sorotan-ikon">&#128274;</span>
                <h3>Akses Terlindungi</h3>
                <p>Setiap akun punya peran dan akses berbeda, lengkap dengan catatan audit untuk ACCOUNTABILITY.</p>
            </article>
            <article class="sorotan">
                <span class="sorotan-ikon">&#128241;</span>
                <h3>Bisa Dipakai di Ponsel</h3>
                <p>Tampilan menyesuaikan layar HP maupun tablet, jadi catat laporan di lapangan tetap nyaman.</p>
            </article>
        </div>
    </section>

    <section class="bagian" id="fitur">
        <div class="bagian-judul">
            <span class="label">Fitur Utama</span>
            <h2>Semua kebutuhan pelaporan dalam satu tempat</h2>
            <p>Dari pencatatan harian hingga visualisasi &mdash; dirancang agar ringkas, akurat, dan mudah dipakai bersama.</p>
        </div>
        <div class="baris-fitur">
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('tulis') ?></span>
            <h3>Laporan Harian</h3>
            <p>Catat rencana, pelaksanaan, hasil, kendala, dan rencana berikutnya lengkap dengan bukti foto.</p>
        </article>
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('centang') ?></span>
            <h3>Pemeriksaan & Revisi</h3>
            <p>Mentor dapat menilai, memberi catatan revisi, dan melacak status laporan secara terpusat.</p>
        </article>
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('kalender') ?></span>
            <h3>Absensi Terintegrasi</h3>
            <p>Pencatatan kehadiran terhubung dengan laporan harian dan rekap untuk analisis kinerja.</p>
        </article>
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('robot') ?></span>
            <h3>Asisten AI</h3>
            <p>Bantuan verifikasi kelengkapan, penyempurnaan narasi, dan insight berbasis data.</p>
        </article>
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('grafik') ?></span>
            <h3>Rekap & Ekspor</h3>
            <p>Hasilkan rekap otomatis dalam bentuk Excel, Word, dan visualisasi Power BI.</p>
        </article>
        <article class="fitur">
            <span class="ikon"><?= ikon_sp('kunci') ?></span>
            <h3>Keamanan Terjaga</h3>
            <p>CSRF, session aman, proteksi berkas, dan pencatatan audit untuk transparansi.</p>
        </article>
    </section>

    <section class="bagian" id="cara-kerja">
        <div class="bagian-judul">
            <span class="label">Cara Kerja</span>
            <h2>Empat langkah sederhana setiap hari</h2>
            <p>Alur yang ringkas menjaga setiap laporan tercatat, diperiksa, dan tersimpan rapi.</p>
        </div>
        <div class="langkah-wadah">
            <div class="langkah">
                <h3>Masuk &amp; Isi Laporan</h3>
                <p>Masuk ke akun, lalu isi rencana, pelaksanaan, hasil, kendala, dan rencana berikutnya lengkap dengan bukti foto.</p>
            </div>
            <div class="langkah">
                <h3>Lampirkan Bukti</h3>
                <p>Unggah foto kegiatan dan catat lokasi agar setiap aktivitas terdokumentasi secara lengkap dan dapat diverifikasi.</p>
            </div>
            <div class="langkah">
                <h3>Periksa oleh Mentor</h3>
                <p>Mentor menilai, memberi catatan atau permintaan revisi, dan memantau status laporan secara terpusat.</p>
            </div>
            <div class="langkah">
                <h3>Sahkan &amp; Rekap</h3>
                <p>Laporan yang disetujui masuk ke rekap otomatis, siap diekspor ke Excel, Word, atau divisualkan di Power BI.</p>
            </div>
        </div>
    </section>

    <section class="bagian" id="faq">
        <div class="bagian-judul">
            <span class="label">Tanya Jawab</span>
            <h2>Pertanyaan yang sering diajukan</h2>
            <p>Temukan jawaban cepat seputar penggunaan sistem pelaporan harian.</p>
        </div>
        <div class="faq-wadah">
            <details class="faq">
                <summary>Bagaimana cara membuat akun baru?</summary>
                <div class="jawab">Klik tombol &ldquo;Daftar Akun Baru&rdquo;, isi data diri yang diminta, lalu akun Anda dapat langsung digunakan untuk masuk dan mengisi laporan.</div>
            </details>
            <details class="faq">
                <summary>Lupa kata sandi, apa yang harus dilakukan?</summary>
                <div class="jawab">Gunakan opsi &ldquo;Lupa Kata Sandi&rdquo; pada halaman masuk. Kode verifikasi (OTP) akan dikirim ke email terdaftar untuk mengatur ulang kata sandi Anda.</div>
            </details>
            <details class="faq">
                <summary>Apakah kata sandi harus rumit?</summary>
                <div class="jawab">Cukup gunakan kata sandi minimal 8 karakter. Untuk keamanan tambahan, hindari menggunakan kata sandi yang sama dengan akun lain.</div>
            </details>
            <details class="faq">
                <summary>Siapa yang memeriksa laporan saya?</summary>
                <div class="jawab">Laporan Anda akan diperiksa oleh mentor yang ditugaskan. Anda dapat memantau status laporan (menunggu, diperiksa, diterima, atau perlu revisi) langsung dari dasbor.</div>
            </details>
            <details class="faq">
                <summary>Apakah data laporan dapat diekspor?</summary>
                <div class="jawab">Ya. Rekap laporan dapat diekspor ke Excel dan Word, serta divisualisasikan melalui Power BI untuk kebutuhan analisis dan pelaporan lebih lanjut.</div>
            </details>
            <details class="faq">
                <summary>Apakah sistem bisa diakses dari ponsel?</summary>
                <div class="jawab">Bisa. Seluruh halaman sudah menyesuaikan tampilan untuk layar HP maupun tablet, jadi pencatatan laporan di lapangan tetap ringkas dan nyaman.</div>
            </details>
        </div>
    </section>

    <section class="bagian" id="kontak">
        <div class="bagian-judul">
            <span class="label">Kontak</span>
            <h2>Butuh bantuan?</h2>
            <p>Tim kami siap membantu Anda terkait penggunaan sistem pelaporan harian.</p>
        </div>
        <div class="kontak-wadah">
            <div class="kontak">
                <span class="ikon"><?= ikon_sp('surat') ?></span>
                <a class="nilai" href="mailto:<?= e(APP_KONTAK_EMAIL) ?>"><?= e(APP_KONTAK_EMAIL) ?></a>
                <div class="ket">Email bantuan</div>
            </div>
            <div class="kontak">
                <span class="ikon"><?= ikon_sp('telepon') ?></span>
                <a class="nilai" href="tel:<?= e(APP_KONTAK_TELP) ?>"><?= e(APP_KONTAK_TELP) ?></a>
                <div class="ket">Telepon / WhatsApp</div>
            </div>
        </div>
    </section>

    <footer class="pembuka-bawah">
        <div class="kaki-taut">
            <a href="#fitur">Fitur</a>
            <a href="#cara-kerja">Cara Kerja</a>
            <a href="#faq">Tanya Jawab</a>
            <a href="#kontak">Kontak</a>
        </div>
        &copy; <?= date('Y') ?> <?= e(APP_ORGANISASI) ?> &mdash; <?= e(APP_NAMA) ?> (v<?= e(APP_VERSI) ?>)
    </footer>
</div>
</body>
</html>