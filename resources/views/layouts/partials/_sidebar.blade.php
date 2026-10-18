{{-- layouts/partials/_sidebar.blade.php --}}
@php
  $pengguna = Auth::user();
  $role = $pengguna->role ?? session('role') ?? 'karyawan';
  $aktif = $menuAktif ?? null;
  
  $menu = [
    'karyawan' => [
      ['grup' => 'Utama'],
      ['key' => 'dashboard', 'ikon' => 'grafik', 'label' => 'Dashboard', 'file' => 'dashboard.php', 'route' => 'dashboard'],
      ['key' => 'buat', 'ikon' => 'tulis', 'label' => 'Buat Laporan', 'file' => 'buat_laporan.php', 'route' => 'laporan.buat'],
      ['key' => 'riwayat', 'ikon' => 'jam', 'label' => 'Riwayat Laporan', 'file' => 'riwayat.php', 'route' => 'laporan.riwayat'],
      ['key' => 'absensi', 'ikon' => 'kalender', 'label' => 'Absensi', 'file' => 'absensi.php'],
    ],
    'mentor' => [
      ['grup' => 'Utama'],
      ['key' => 'dashboard', 'ikon' => 'grafik', 'label' => 'Dashboard', 'file' => 'mentor_dashboard.php', 'route' => 'mentor.dashboard'],
      ['key' => 'rekap', 'ikon' => 'dokumen', 'label' => 'Rekap Laporan', 'file' => 'rekap_laporan.php'],
    ],
    'admin' => [
      ['grup' => 'Utama'],
      ['key' => 'dashboard', 'ikon' => 'grafik', 'label' => 'Dashboard Admin', 'file' => 'admin_dashboard.php', 'route' => 'admin.dashboard'],
      ['key' => 'periksa', 'ikon' => 'dokumen', 'label' => 'Periksa Laporan', 'file' => 'mentor_dashboard.php'],
    ],
  ];
  
  $list = $menu[$role] ?? $menu['karyawan'];
  $grupMenu = [];
  $namaGrupAktif = '';
  foreach ($list as $item) {
    if (isset($item['grup'])) {
      $namaGrupAktif = $item['grup'];
      if (!isset($grupMenu[$namaGrupAktif])) $grupMenu[$namaGrupAktif] = [];
      continue;
    }
    if (!isset($grupMenu[$namaGrupAktif])) $grupMenu[$namaGrupAktif] = [];
    $grupMenu[$namaGrupAktif][] = $item;
  }
@endphp
<aside class="sidebar" id="sidebar">
  <div class="sidebar-brand">
    <span class="logo">{!! logo_phr() !!}</span>
    <span class="brand-teks">
      <span class="nama">{{ config('keamanan.aplikasi_singkat') }}</span><br>
      <span class="sub">{{ config('keamanan.organisasi') }}</span>
    </span>
    <button class="tombol-mini" type="button" id="tombolMini" aria-pressed="false" aria-label="Perkecil atau perbesar menu">&#9776;</button>
  </div>
  
  <nav class="sidebar-nav" aria-label="Navigasi utama">
    <div class="pencarian-menu">
      <input type="search" id="cariMenu" placeholder="Cari menu..." aria-label="Cari menu" autocomplete="off">
    </div>
    
    @foreach($grupMenu as $namaGrup => $itemsGrup)
      <div class="nav-grup" data-grup="{{ $namaGrup }}">
        @if((string)$namaGrup !== '')
          <button class="nav-judul" type="button" aria-expanded="true">{{ $namaGrup }}</button>
        @endif
        @foreach($itemsGrup as $item)
          @php
            $aktifItem = ($aktif === $item['key']) || (empty($aktif) && basename(request()->path()) === $item['route'] ?? '' || str_contains(menu_url($item), request()->path()));
          @endphp
          <a class="nav-item{{ $aktifItem ? ' aktif' : '' }}" href="{{ menu_url($item) }}" title="{{ $item['label'] }}" {!! $aktifItem ? 'aria-current="page"' : '' !!}>
            <span class="ikon">{!! ikon_sp($item['ikon'] ?? 'kotak') !!}</span>
            <span class="label">{{ $item['label'] }}</span>
          </a>
        @endforeach
      </div>
    @endforeach
  </nav>
  
  <div class="sidebar-footer">
    {{ config('keamanan.aplikasi_versi') }} &middot; {{ config('keamanan.organisasi') }}
  </div>
</aside>