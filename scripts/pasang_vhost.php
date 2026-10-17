<?php
/**
 * Memasang VirtualHost Apache untuk Laravel 12 + aplikasi flat-PHP lama.
 *
 *   php scripts/pasang_vhost.php            # pasang
 *   php scripts/pasang_vhost.php --periksa  # hanya tampilkan isi, jangan tulis
 *
 * Rancangan (migrasi strangler-fig — kedua sistem hidup berdampingan):
 *
 *   http://localhost/pelaporan/   -> aplikasi flat-PHP LAMA  (tidak berubah)
 *   http://127.0.0.1/pelaporan/   -> aplikasi flat-PHP LAMA  (tidak berubah)
 *   http://192.168.56.1/          -> LARAVEL (DocumentRoot public/)
 *   http://pelaporan.local/       -> LARAVEL (aktif setelah entry hosts)
 *
 * Mengapa memakai skrip, bukan menyunting tangan:
 *   - Berkas berada DI LUAR direktori proyek (C:\xampp\apache\conf\extra\).
 *   - Menulis langsung ke sana tidak selalu andal; skrip ini memverifikasi
 *     hasil tulis dengan membaca ulang berkas dan memeriksa tanda tangan konten.
 *   - Dapat dijalankan ulang dengan aman (idempoten).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$TUJUAN = 'C:/xampp/apache/conf/extra/httpd-vhosts.conf';
$CADANGAN = $root . '/backups/httpd-vhosts.conf.cadangan';

$konten = <<<'CONF'
# =============================================================================
# pelaporan -- VirtualHost Apache (Laravel 12 + aplikasi flat-PHP lama)
#
# Rancangan selama masa migrasi strangler-fig:
#
#   http://localhost/pelaporan/      -> aplikasi flat-PHP LAMA  (tidak berubah)
#   http://127.0.0.1/pelaporan/      -> aplikasi flat-PHP LAMA  (tidak berubah)
#   http://192.168.56.1/             -> LARAVEL (baru)
#   http://pelaporan.local/          -> LARAVEL (aktif setelah entry hosts)
#
# Catatan:
#   - 192.168.56.1 sudah ada di mesin ini (adapter VirtualBox host-only),
#     sehingga Laravel BISA diuji tanpa mengedit C:\Windows\...\hosts.
#   - File hosts butuh hak admin. Setelah entry `127.0.0.1 pelaporan.local`
#     ditambahkan, nama pelaporan.local ikut melayani Laravel (ServerAlias).
#   - VirtualHost PERTAMA adalah default: request dengan Host yang tidak
#     cocok dengan mana pun jatuh ke aplikasi lama, bukan ke Laravel.
#     Ini disengaja agar sistem lama tidak pernah putus selama migrasi.
#
# Dirujuk oleh httpd.conf via: Include conf/extra/httpd-vhosts.conf
# Dipasang oleh: scripts/pasang_vhost.php
# =============================================================================

# -----------------------------------------------------------------------------
# 1. DEFAULT -- aplikasi flat-PHP lama (masih dipakai sebagai sistem aktif)
# -----------------------------------------------------------------------------
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1

    DocumentRoot "C:/xampp/htdocs"

    <Directory "C:/xampp/htdocs">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  "logs/localhost-error.log"
    CustomLog "logs/localhost-access.log" combined
</VirtualHost>

# -----------------------------------------------------------------------------
# 2. LARAVEL 12 -- aplikasi baru (DocumentRoot menunjuk ke public/)
#
# Hanya isi public/ yang boleh tersaji. Seluruh berkas lain (app/, config/,
# .env, vendor/, .git/) berada DI LUAR DocumentRoot sehingga tidak terjangkau
# secara struktural -- lapisan pengaman pertama sebelum aturan .htaccess.
# -----------------------------------------------------------------------------
<VirtualHost *:80>
    ServerName 192.168.56.1
    ServerAlias pelaporan.local

    DocumentRoot "C:/xampp/htdocs/pelaporan/public"

    <Directory "C:/xampp/htdocs/pelaporan/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted

        # Aturan bawaan Laravel: semua request non-file diteruskan ke index.php
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>
    </Directory>

    # Laravel tidak boleh menyajikan berkas tersembunyi (.env, .git, dsb.)
    <FilesMatch "^\.">
        Require all denied
    </FilesMatch>

    ErrorLog  "logs/laravel-error.log"
    CustomLog "logs/laravel-access.log" combined
</VirtualHost>
CONF;

/* ---- Mode periksa: tampilkan tanpa menulis ---- */
if (in_array('--periksa', $argv, true)) {
    printf("Mode periksa — %s TIDAK ditulis.\n\n", $TUJUAN);
    echo $konten, PHP_EOL;
    exit(0);
}

/* ----1. Cadangkan berkas asli bila belum pernah ---- */
if (is_file($TUJUAN) && ! is_file($CADANGAN)) {
    $dirCad = dirname($CADANGAN);
    if (! is_dir($dirCad)) {
        mkdir($dirCad, 0775, true);
    }
    copy($TUJUAN, $CADANGAN);
    printf("Cadangan dibuat: %s (%d bytes)\n", $CADANGAN, (int) filesize($CADANGAN));
}

/* ---- 2. Tulis ---- */
$bytes = file_put_contents($TUJUAN, $konten);
if ($bytes === false) {
    fwrite(STDERR, "GAGAL menulis $TUJUAN (hak akses?)\n");
    exit(1);
}
printf("Menulis %s (%d bytes).\n", $TUJUAN, $bytes);

/* ---- 3. Verifikasi dengan membaca ulang ---- */
$balik = (string) file_get_contents($TUJUAN);

$tanda = [
    '<VirtualHost *:80>'                       => 2,
    'ServerName localhost'                     => 1,
    'ServerName 192.168.56.1'                  => 1,
    'ServerAlias pelaporan.local'              => 1,
    'DocumentRoot "C:/xampp/htdocs/pelaporan/public"' => 1,
    'DocumentRoot "C:/xampp/htdocs"'           => 1,
];

$gagal = [];
foreach ($tanda as $cari => $harap) {
    $jumlah = substr_count($balik, $cari);
    $ok     = $jumlah === $harap;
    printf("  %-52s %d (harus %d) %s\n", $cari, $jumlah, $harap, $ok ? 'OK' : 'GAGAL');
    if (! $ok) {
        $gagal[] = $cari;
    }
}

/* ---- 4. Pastikan tidak ada karakter non-ASCII (aman untuk mod_rewrite) ---- */
if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $balik)) {
    $gagal[] = 'karakter non-ASCII';
    fwrite(STDERR, "PERINGATAN: terdapat karakter non-ASCII di dalam berkas konfigurasi.\n");
}

printf("\nHasil: %s\n", $gagal === [] ? 'TERPASANG dan terverifikasi' : 'GAGAL verifikasi: ' . implode(', ', $gagal));
exit($gagal === [] ? 0 : 1);
