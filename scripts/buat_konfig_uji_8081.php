<?php
/**
 * Membuat konfigurasi uji Apache pada port 8081 (tanpa menyentuh port 80).
 *
 *   php scripts/buat_konfig_uji_8081.php
 *
 * Tujuan: memvalidasi routing VirtualHost (aplikasi lama vs Laravel) sebelum
 * restart Apache yang sesungguhnya. Konfigurasi uji menyalin httpd.conf,
 * mengganti Listen 80 -> 8081, dan memindahkan vhost ke *:8081.
 */

declare(strict_types=1);

$apache = 'C:/xampp/apache';
$src    = "$apache/conf/httpd.conf";
$vh     = "$apache/conf/extra/httpd-vhosts.conf";
$outCf  = "$apache/conf/httpd-test-8081.conf";
$outVh  = "$apache/conf/extra/httpd-test-8081-vhosts.conf";

/* ---- 1. Salin vhosts, ganti *:80 -> *:8081 ---- */
$vhSrc = (string) file_get_contents($vh);
$vhOut = str_replace('<VirtualHost *:80>', '<VirtualHost *:8081>', $vhSrc);
if ($vhOut === $vhSrc) {
    fwrite(STDERR, "GAGAL: tidak menemukan <VirtualHost *:80> di httpd-vhosts.conf\n");
    exit(1);
}
file_put_contents($outVh, $vhOut);

/* ---- 2. Salin httpd.conf: buang Listen 80, tambah Listen 8081 ---- */
$cf    = (string) file_get_contents($src);
$lines = preg_split('/\r\n|\n|\r/', $cf);
$buf   = [];
foreach ($lines as $line) {
    // Jangan gandakan baris Listen test bila dijalankan ulang.
    if (preg_match('/^\s*Listen\s+8081\s*$/i', $line)) {
        continue;
    }
    if (preg_match('/^\s*Listen\s+\*?:?80\s*$/i', $line)) {
        $buf[] = 'Listen 8081';
        continue;
    }
    // Port 443 SSL dipakai instance utama -> jangan diikat oleh instance uji.
    if (preg_match('/^\s*Listen\s+\*?:?443\s*$/i', $line)) {
        continue;
    }
    // Nonaktifkan modul & include SSL (butuh port 443 dan sertifikat).
    if (preg_match('/^\s*(LoadModule\s+ssl_module|Include\s+conf\/extra\/httpd-ssl\.conf)/i', $line)) {
        $buf[] = '# [uji-8081 dinonaktifkan] ' . $line;
        continue;
    }
    $buf[] = $line;
}
$buf[] = 'Include conf/extra/httpd-test-8081-vhosts.conf';
file_put_contents($outCf, implode(PHP_EOL, $buf) . PHP_EOL);

printf("Konfig uji dibuat:\n  %s\n  %s\n", basename($outCf), basename($outVh));

/* ---- 3. Laporkan berapa vhost uji yang dihasilkan ---- */
printf("Jumlah <VirtualHost *:8081> : %d\n", substr_count($vhOut, '<VirtualHost *:8081>'));
printf("Baris Listen 8081           : %d\n", count(preg_grep('/^\s*Listen\s+8081\s*$/i', $buf)));
