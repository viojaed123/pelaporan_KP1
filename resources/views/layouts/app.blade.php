{{-- 
  layouts/app.blade.php
  Tata letak penuh untuk halaman terautentikasi (sidebar + topbar + footer + flash).
  Warna mengikuti config/keamanan.php agar satu sumber kebenaran.
--}}
@php
  $opsi = $opsi ?? [];
  $opsi += [
    'judul' => config('keamanan.aplikasi_singkat'),
    'crumb' => '',
    'menuAktif' => $menuAktif ?? null,
    'deskripsi' => '',
  ];
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>{{ $opsi['judul'] }} - {{ config('keamanan.aplikasi_singkat') }}</title>
  <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
  <link rel="icon" href="{{ asset('assets/img/favicon.png') }}" type="image/png">
  {{-- Dimuat di <head> agar tema tersimpan diterapkan sebelum halaman dicat
       (mencegah kedip terang->gelap) dan tetap hidup walau skrip lain gagal. --}}
  <script src="{{ asset('assets/js/toggle.js') }}"></script>
  @stack('kepala')
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
  <a class="lewati-ke-konten" href="#konten-utama">Lewati ke konten utama</a>
  
  <div class="app">
    @include('layouts.partials._sidebar', ['menuAktif' => $opsi['menuAktif']])
    
    <div class="area-utama">
      @include('layouts.partials._topbar', ['opsi' => $opsi])
      
      <main class="isi" id="konten-utama">
        @include('layouts.partials._flash')
        @yield('isi')
      </main>
    </div>
  </div>
  
  @if(! empty($tampilkanChat) || ! defined('DILARANG_CHAT') || (defined('AI_AKTIF') && AI_AKTIF))
    @include('layouts.partials._chat', ['tampilkanChat' => true])
  @endif
  
  <script src="{{ asset('assets/js/app.js') }}" defer></script>
  <script>
    (function () {
      var b = document.getElementById('tombolBurger');
      var s = document.getElementById('sidebar');
      if (b && s) {
        b.addEventListener('click', function () { s.classList.toggle('tampil'); });
        document.addEventListener('click', function (e) {
          if (!s.contains(e.target) && !b.contains(e.target)) s.classList.remove('tampil');
        });
      }
      
      var mini = document.getElementById('tombolMini');
      var app = document.querySelector('.app');
      if (mini && app) {
        mini.addEventListener('click', function () {
          var miniAktif = app.classList.toggle('mini');
          mini.setAttribute('aria-pressed', miniAktif ? 'true' : 'false');
          try { localStorage.setItem('sidebar-mini', miniAktif ? '1' : '0'); } catch(e) {}
        });
        try {
          if (localStorage.getItem('sidebar-mini') === '1') {
            app.classList.add('mini');
            mini.setAttribute('aria-pressed', 'true');
          }
        } catch(e) {}
      }
    })();
  </script>
  @stack('kaki')
</body>
</html>