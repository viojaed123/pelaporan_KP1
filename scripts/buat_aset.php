<?php
/**
 * buat_aset.php — buat favicon.png & logo-phr.png dari lambang PHR (warna korporat).
 * Jalankan sekali:  php buat_aset.php
 */
declare(strict_types=1);

$dir  = dirname(__DIR__) . '/assets/img';
$font = 'C:/Windows/Fonts/arialbd.ttf';
$S    = 256;         // kanvas besar (supersampling)
$R    = 56;          // radius sudut pada kanvas besar

$cAtas  = [0x00, 0x72, 0xBC];   // #0072BC
$cBawah = [0x12, 0x34, 0x5A];   // #12345A
$merah  = [0xE3, 0x1E, 0x24];   // #E31E24
$hijau  = [0x15, 0x94, 0x47];   // #159447

if (!is_dir($dir)) { mkdir($dir, 0755, true); }

function lerp(int $a, int $b, float $t): int { return (int) round($a + ($b - $a) * $t); }

/** true bila (x,y) berada di dalam persegi-panjang bersudut bulat. */
function dalam_bulat(int $x, int $y, int $S, int $r): bool
{
    $x1 = $r; $x2 = $S - 1 - $r; $y1 = $r; $y2 = $S - 1 - $r;
    $cx = $x < $x1 ? $x1 : ($x > $x2 ? $x2 : $x);
    $cy = $y < $y1 ? $y1 : ($y > $y2 ? $y2 : $y);
    $dx = $x - $cx; $dy = $y - $cy;
    return ($dx * $dx + $dy * $dy) <= ($r * $r);
}

$im = imagecreatetruecolor($S, $S);
imagealphablending($im, false);
imagesavealpha($im, true);
imagefilledrectangle($im, 0, 0, $S - 1, $S - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));

/* Latar bergradasi dengan sudut membulat */
for ($y = 0; $y < $S; $y++) {
    $t   = $y / ($S - 1);
    $col = imagecolorallocate($im, lerp($cAtas[0], $cBawah[0], $t), lerp($cAtas[1], $cBawah[1], $t), lerp($cAtas[2], $cBawah[2], $t));
    for ($x = 0; $x < $S; $x++) {
        if (dalam_bulat($x, $y, $S, $R)) { imagesetpixel($im, $x, $y, $col); }
    }
}

imagealphablending($im, true);

/* Teks PHR */
$fs   = 66;
$bbox = imagettfbbox($fs, 0, $font, 'PHR');
$tw   = $bbox[2] - $bbox[0];
$tx   = (int) (($S - $tw) / 2 - $bbox[0]);
$ty   = 130;                                     // garis dasar
imagettftext($im, $fs, 0, $tx, $ty, imagecolorallocate($im, 255, 255, 255), $font, 'PHR');

/* Garis aksen merah & hijau */
$m  = imagecolorallocate($im, $merah[0], $merah[1], $merah[2]);
$h  = imagecolorallocate($im, $hijau[0], $hijau[1], $hijau[2]);
$bar = function (int $x1, int $y1, int $w, int $ht, $col) use ($im): void {
    $r2 = (int) ($ht / 2);
    imagefilledrectangle($im, $x1 + $r2, $y1, $x1 + $w - $r2, $y1 + $ht, $col);
    imagefilledellipse($im, $x1 + $r2, $y1 + $r2, $ht, $ht, $col);
    imagefilledellipse($im, $x1 + $w - $r2, $y1 + $r2, $ht, $ht, $col);
};
$bar(75, 153, 106, 13, $m);      // merah
$bar(100, 176, 56, 13, $h);      // hijau

/* Simpan versi besar */
imagepng($im, $dir . '/logo-phr.png');

/* Turunkan ke favicon 64x64 (menghaluskan tepi) */
$fav = imagecreatetruecolor(64, 64);
imagealphablending($fav, false);
imagesavealpha($fav, true);
imagefilledrectangle($fav, 0, 0, 63, 63, imagecolorallocatealpha($fav, 0, 0, 0, 127));
imagecopyresampled($fav, $im, 0, 0, 0, 0, 64, 64, $S, $S);
imagepng($fav, $dir . '/favicon.png');

imagedestroy($im);
imagedestroy($fav);

echo "OK: logo-phr.png & favicon.png dibuat di $dir\n";
echo 'favicon ' . filesize($dir . '/favicon.png') . " byte\n";
echo 'logo    ' . filesize($dir . '/logo-phr.png') . " byte\n";
