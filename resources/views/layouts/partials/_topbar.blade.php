{{-- layouts/partials/_topbar.blade.php --}}
@php
  $opsi = $opsi ?? [];
  $opsi += ['judul' => config('keamanan.aplikasi_singkat'), 'crumb' => '', 'aksi' => ''];
  $pengguna = Auth::user();
  $nama = $pengguna->nama ?? session('nama') ?? '';
  $role = $pengguna->role ?? session('role') ?? 'karyawan';
  $foto = $pengguna->foto_profil ?? session('foto') ?? '';
  $unread = 0;
@endphp
<header class="topbar">
  <button class="tombol-burger" type="button" id="tombolBurger" aria-label="Buka menu">{!! ikon_sp('buku-besar') !!}</button>
  
  <h1 class="judul-halaman">
    @if($opsi['crumb'] !== '')
      <span class="crumb">{{ $opsi['crumb'] }} &middot; </span>
    @endif
    {{ $opsi['judul'] }}
  </h1>
  
  <div class="topbar-kanan">
    {!! $opsi['aksi'] !!}

    <button type="button" id="tombolTema" class="tombol-lonceng" aria-label="Ganti tema"
            title="Ganti tema terang/gelap"
            style="border:0;background:transparent;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;padding:6px;">&#127769;</button>

    <a class="tombol-lonceng" href="{{ Route::has('notifikasi.index') ? route('notifikasi.index') : 'notifikasi.php' }}"
       title="Notifikasi{{ $unread > 0 ? ' (' . $unread . ' baru)' : '' }}">
      {!! ikon_sp('lonceng') !!}
      @if($unread > 0)
        <span class="titik">{{ $unread > 99 ? '99+' : $unread }}</span>
      @endif
    </a>
    
    <div class="dropdown">
      <button class="dropdown-tombol" type="button" data-dropdown="menu-user">
        {!! avatar_tampil($nama, $role, $foto, 'avatar avatar-kecil') !!}
        <span class="sembunyi-mobile">{{ potong($nama, 18) }}</span>
        <span>&#9662;</span>
      </button>
      <div class="dropdown-menu" id="menu-user">
        <div class="label-menu">
          {{ $nama }}<br>
          <span style="text-transform:none;letter-spacing:0;font-weight:500">
            {{ ucfirst($role) }}
          </span>
        </div>
        <div class="pisah"></div>
        <a href="{{ Route::has('profil.index') ? route('profil.index') : 'profil.php' }}">{!! ikon_sp('pengguna') !!} Akun &amp; Keamanan</a>
        <a href="{{ Route::has('asisten.index') ? route('asisten.index') : 'asisten.php' }}">{!! ikon_sp('robot') !!} Asisten AI</a>
        @if($role === 'karyawan')
          <a href="{{ Route::has('riwayat.index') ? route('riwayat.index') : 'riwayat.php' }}">{!! ikon_sp('jam') !!} Riwayat Laporan</a>
        @endif
        <div class="pisah"></div>
        <form method="POST" action="{{ route('keluar') }}" style="margin:0">
          @csrf
          <button type="submit" class="bahaya" style="background:none;border:none;padding:10px 14px;width:100%;text-align:left;cursor:pointer">{!! ikon_sp('keluar') !!} Keluar</button>
        </form>
      </div>
    </div>
  </div>
</header>