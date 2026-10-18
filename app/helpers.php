<?php

declare(strict_types=1);

/**
 * Pada helpers umum yang dipindahkan dari includes/helpers.php dan
 * includes/auth.php sistem lama.
 *
 * Dipasang lewat composer (autoload.files), sehingga tersedia di seluruh
 * aplikasi tanpa perlu use/import.
 *
 * Perubahan pada semantik sengaja dihindari: peran fungsi ini adalah
 * membentuk tampilan, dan dua di antaranya (ikon_sp, avatar_tampil)
 * membangun markup yang boleh berisi karakter khusus. Karena itu pemanggil
 * Blade memakai {!! ... !!}. Fungsi tetap meng-escape SEMUA masukan
 * pengguna di dalamnya (lihat potong(), e() pada atribut), sehingga markup
 * yang keluar aman untuk disisipkan.
 */

use Illuminate\Support\Facades\Route;

if (! function_exists('e')) {
    /**
     * Escape entitas HTML. Disediakan agar kode yang diport dari sistem lama
     * yang memakai e() tetap berfungsi tanpa mengganti seluruh pemanggilan.
     */
    function e(mixed $nilai): string
    {
        return htmlspecialchars((string) ($nilai ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}

if (! function_exists('potong')) {
    /**
     * Pangkas teks, menambahkan tanda elipsis bila terlalu panjang.
     *
     * Batas default 90 mengikuti sistem lama.
     */
    function potong(?string $teks, int $panjang = 90): string
    {
        $teks = trim((string) $teks);

        if ($teks === '') {
            return '';
        }

        return mb_strlen($teks, 'UTF-8') > $panjang
            ? mb_substr($teks, 0, $panjang, 'UTF-8') . '…'
            : $teks;
    }
}

if (! function_exists('inisial')) {
    /**
     * Satu huruf pertama nama, huruf besar. '?' bila nama kosong.
     */
    function inisial(string $nama): string
    {
        $nama = trim($nama);

        if ($nama === '') {
            return '?';
        }

        return mb_strtoupper(mb_substr($nama, 0, 1, 'UTF-8'), 'UTF-8');
    }
}

/**
 * Path dasar public/ yang aman dipanggil dari suite Laravel MAUPUN suite
 * legacy. public_path() bawaan Laravel membutuhkan instance Application;
 * di suite legacy yang hanya memuat Container, fungsi itu melempar error.
 */
if (! function_exists('phr_public_path')) {
    function phr_public_path(string $sub = ''): string
    {
        $app = function_exists('app') ? app() : null;
        $base = $app instanceof \Illuminate\Foundation\Application
            ? public_path()
            : dirname(__DIR__) . '/public';

        return $sub === '' ? $base : $base . '/' . $sub;
    }
}

if (! function_exists('foto_profil_url')) {
    /**
     * URL relatif foto profil bila berkasnya benar-benar ada, atau null.
     *
     * Keamanan: hanya NAMA BERKAS yang diterima. Pemisah path, "..", dan
     * nilai kosong ditolak, sehingga isi kolom foto_profil (yang berasal dari
     * unggahan pengguna) tidak dapat dipakai untuk membaca berkas di luar
     * folder avatar (path traversal).
     */
    function foto_profil_url(string $foto): ?string
    {
        $foto = trim($foto);

        if ($foto === '' || basename($foto) !== $foto || str_contains($foto, '..')) {
            return null;
        }

        return is_file(phr_public_path('uploads/avatar/' . $foto))
            ? 'uploads/avatar/' . rawurlencode($foto)
            : null;
    }
}

if (! function_exists('avatar_kelas')) {
    /**
     * Kelas warna avatar berdasarkan peran.
     */
    function avatar_kelas(string $role): string
    {
        return match ($role) {
            'admin'  => 'avatar-admin',
            'mentor' => 'avatar-mentor',
            default  => 'avatar-karyawan',
        };
    }
}

if (! function_exists('avatar_tampil')) {
    /**
     * Markup avatar: foto bulat bila ada, jika tidak inisial berwarna peran.
     *
     * @param  string  $kelas  kelas dasar, mis. 'avatar avatar-kecil'
     */
    function avatar_tampil(
        string $nama,
        string $role,
        string $foto = '',
        string $kelas = 'avatar avatar-kecil'
    ): string {
        $url = foto_profil_url($foto);

        if ($url !== null) {
            return '<img class="' . e($kelas) . ' avatar-foto" src="' . e($url)
                . '" alt="Foto profil ' . e($nama) . '" loading="lazy">';
        }

        return '<span class="' . e($kelas) . ' ' . e(avatar_kelas($role)) . '">'
            . e(inisial($nama)) . '</span>';
    }
}

if (! function_exists('logo_phr')) {
    /**
     * Lambang (logo) PHR — porting langsung dari includes/layout.php.
     * Mengutamakan PNG di public/assets/img; tanpa berkas gambar, memakai
     * teks "PH" agar tampilan tetap utuh.
     */
    function logo_phr(bool $dengan_wadah = true): string
    {
        $png = phr_public_path('assets/img/logo-phr.png');
        $src = is_file($png) ? 'assets/img/logo-phr.png' : '';

        if ($src === '') {
            return $dengan_wadah ? '<span class="logo">PH</span>' : 'PH';
        }

        $gambar = '<img src="' . e($src) . '" alt="PHR" width="64" height="64">';

        return $dengan_wadah ? '<span class="logo logo-gambar">' . $gambar . '</span>' : $gambar;
    }
}

if (! function_exists('ikon_sp')) {
    /**
     * Markup <svg> ikon garis, pengganti emoji.
     *
     * Warna memakai currentColor sehingga otomatis mengikuti aksen di sekitar
     * ikon. Seluruh isi SVG murni ASCII dan tetap, tanpa masukan pengguna,
     * sehingga aman dirender lewat {!! ikon_sp(...) !!}.
     *
     * @param  string  $nama  nama ikon (default: lingkaran polos)
     * @param  string  $kelas kelas tambahan untuk elemen <svg>
     */
    function ikon_sp(string $nama, string $kelas = ''): string
    {
        $isi = match ($nama) {
            'dokumen' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
                . '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'
                . '<line x1="16" y1="17" x2="8" y2="17"/>',
            'jam' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'centang' => '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>'
                . '<polyline points="22 4 12 14.01 9 11.01"/>',
            'lonceng' => '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>'
                . '<path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            'tulis' => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>'
                . '<path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'buku' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>'
                . '<path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
            'kalender' => '<rect x="3" y="4" width="18" height="18" rx="2"/>'
                . '<line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>'
                . '<line x1="3" y1="10" x2="21" y2="10"/>',
            'robot' => '<rect x="4" y="4" width="16" height="16" rx="2"/>'
                . '<rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/>'
                . '<line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/>'
                . '<line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/>'
                . '<line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/>'
                . '<line x1="1" y1="14" x2="4" y2="14"/>',
            'kesehatan' => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 1 0-7.78 7.78'
                . 'l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
            'silang' => '<circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/>'
                . '<line x1="9" y1="9" x2="15" y2="15"/>',
            'perisai' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            'grafik' => '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/>'
                . '<line x1="6" y1="20" x2="6" y2="16"/>',
            'unggah' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
                . '<polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
            'pengguna' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
                . '<path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/>'
                . '<path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/>'
                . '<path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
            'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
            'alat' => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94'
                . 'l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'peta' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
            'kegiatan' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
            'rumah' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
                . '<polyline points="9 22 9 12 15 12 15 22"/>',
            'koin' => '<line x1="12" y1="1" x2="12" y2="23"/>'
                . '<path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>',
            'cari' => '<circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
            'info' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/>'
                . '<line x1="12" y1="8" x2="12.01" y2="8"/>',
            'keluar' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>'
                . '<polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
            'unduh' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>'
                . '<polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
            'cetak' => '<polyline points="6 9 6 2 18 2 18 9"/>'
                . '<path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>'
                . '<rect x="6" y="14" width="12" height="8"/>',
            'surat' => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>'
                . '<polyline points="22,6 12,13 2,6"/>',
            'kotak_masuk' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/>'
                . '<path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'kirim' => '<line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>',
            'kunci' => '<circle cx="8" cy="15" r="4"/><path d="M12.09 12.09L21 3.18"/>'
                . '<path d="M18 6l3 3"/><path d="M15 9l2 2"/>',
            'komputer' => '<path d="M4 5h16a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1z"/>'
                . '<line x1="2" y1="20" x2="22" y2="20"/>',
            'grup' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>'
                . '<path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'mata' => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
            'obrolan' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'hapus' => '<polyline points="3 6 5 6 21 6"/>'
                . '<path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>'
                . '<line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
            'tambah' => '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
            'peringatan' => '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>'
                . '<line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
            'kotak' => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>'
                . '<polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'arsip' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/>'
                . '<line x1="10" y1="12" x2="14" y2="12"/>',
            'globe' => '<circle cx="12" cy="12" r="10"/>'
                . '<line x1="2" y1="12" x2="22" y2="12"/>'
                . '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
            'floppy' => '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>'
                . '<polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
            'ganti' => '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/>'
                . '<path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>',
            'bendera' => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>'
                . '<line x1="4" y1="22" x2="4" y2="15"/>',
            'panah-kiri' => '<line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>',
            'gulir' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>'
                . '<polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/>'
                . '<line x1="16" y1="17" x2="8" y2="17"/>',
            'penanda' => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>'
                . '<circle cx="12" cy="10" r="3"/>',
            'buku-besar' => '<path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>'
                . '<path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>',
            'lampu' => '<path d="M9 18h6"/><path d="M10 22h4"/>'
                . '<path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5.76.76 1.23 1.52 1.41 2.5"/>',
            'gambar' => '<rect x="3" y="3" width="18" height="18" rx="2"/>'
                . '<circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/>',
            'telepon' => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6'
                . ' 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81'
                . 'a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45'
                . 'c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
            'mata-silang' => '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>'
                . '<path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>'
                . '<path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>',
            default => '<circle cx="12" cy="12" r="9"/>',
        };

        $kelasAttr = 'sp-ikon' . ($kelas !== '' ? ' ' . e($kelas) : '');

        return '<svg class="' . $kelasAttr . '" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">' . $isi . '</svg>';
    }
}

if (! function_exists('menu_url')) {
    /**
     * Selesaikan URL menu: rute Laravel bila sudah ada, selain itu halaman lama.
     *
     * Mekanisme inti pemindahan modul (strangler-fig):
     *
     *   1. Selama sebuah modul BELUM dipindahkan, tautannya menunjuk ke halaman
     *      flat-PHP lama di aplikasi lama (berkas berbeda, database sama).
     *   2. Begitu rute Laravel untuk kunci itu terdaftar, tautannya otomatis
     *      berpindah ke Laravel tanpa perlu mengubah satu per satu isi sidebar.
     *
     * Karena itu pindahnya terlihat sebagai "menu langsung berfungsi penuh
     * di aplikasi lama, lalu satu per satu beralih ke Laravel" -- bukan menu
     * yang terlebih dulu rusak.
     *
     * @param  array<string, mixed>  $item
     */
    function menu_url(array $item): string
    {
        $route = $item['route'] ?? null;
        $file  = $item['file'] ?? null;

        if (is_string($route) && $route !== '' && Route::has($route)) {
            return route($route);
        }

        if (is_string($file) && $file !== '') {
            return rtrim((string) config('legacy.base_url', 'http://localhost/pelaporan'), '/') . '/' . $file;
        }

        return $route === null && $file === null ? '#' : '/';
    }
}
