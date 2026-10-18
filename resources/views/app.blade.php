{{--
  app.blade.php — cangkang (shell) SPA React.

  Halaman ini TIDAK berisi logika: seluruh antarmuka pengguna untuk
  pelanggan token (web React & APK mobile memakai API yang sama)
  dirender oleh React di #app. Tata letak, warna, dan aset korporat
  PHR dipertahankan persis (asset('assets/css/app.css') + variabel :root).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ config('keamanan.aplikasi_singkat') }} — Sistem Pelaporan Kegiatan Harian</title>
  <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
  <link rel="icon" href="{{ asset('assets/img/favicon.png') }}" type="image/png">
  @vite(['resources/js/app.js'])
  <style>
    :root {
      --biru-tua: {{ config('keamanan.warna_biru_tua') }};
      --biru:     {{ config('keamanan.warna_biru') }};
      --merah:    {{ config('keamanan.warna_merah') }};
      --hijau:    {{ config('keamanan.warna_hijau') }};
      --bg:       {{ config('keamanan.warna_bg') }};
    }
  </style>
</head>
<body>
  <div id="app"></div>
</body>
</html>
