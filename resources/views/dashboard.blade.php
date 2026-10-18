@extends('layouts.app')

@section('judul', 'Dashboard')

@section('isi')
  @php
    $pengguna = Auth::user() ?? $pengguna ?? null;
    $role = $pengguna->role ?? 'karyawan';
    $sebutanPeran = match ($role) {
      'admin'  => 'Administrator',
      'mentor' => 'Mentor',
      default  => 'Karyawan',
    };
    $sisaIdle = $sisaIdle ?? 0;
    $sisaUsia = $sisaUsia ?? 0;
    $jamIdle = intdiv($sisaIdle, 3600);
    $menitIdle = intdiv($sisaIdle % 3600, 60);
  @endphp

  <section class="lembar">
    <h1>Selamat datang, {{ $pengguna->nama ?? 'Pengguna' }}.</h1>
    <p class="catatan-kaki">
      Anda masuk sebagai <strong>{{ $sebutanPeran }}</strong>.
      Dashboard ini memakai layout penuh terintegrasi (sidebar + topbar) sesuai migrasi Blade.
    </p>
  </section>

  <section class="lembar">
    <h2>Ringkasan akun</h2>
    <div class="kisi-info">
      <div class="kartu-info">
        <div class="label">Nama</div>
        <div class="nilai">{{ $pengguna->nama ?? '—' }}</div>
      </div>
      <div class="kartu-info">
        <div class="label">Surel</div>
        <div class="nilai" style="font-size:14px">{{ $pengguna->email ?? '—' }}</div>
      </div>
      <div class="kartu-info">
        <div class="label">Peran</div>
        <div class="nilai">{{ $sebutanPeran }}</div>
      </div>
      <div class="kartu-info">
        <div class="label">Status akun</div>
        <div class="nilai">{{ ucfirst((string) ($pengguna->status ?? 'aktif')) }}</div>
      </div>
    </div>
  </section>

  <section class="lembar">
    <h2>Keamanan sesi</h2>
    <div class="kisi-info">
      <div class="kartu-info">
        <div class="label">Batas diam</div>
        <div class="nilai">{{ $jamIdle }}j {{ $menitIdle }}m tersisa</div>
        <div class="catatan">Keluar otomatis setelah {{ intdiv((int) config('keamanan.sesi_idle_detik'), 60) }} menit tanpa aktivitas.</div>
      </div>
      <div class="kartu-info">
        <div class="label">Batas usia sesi</div>
        <div class="nilai">{{ intdiv($sisaUsia, 3600) }}j tersisa</div>
        <div class="catatan">Sesi wajib diperbarui setelah {{ intdiv((int) config('keamanan.sesi_absolut_detik'), 3600) }} jam.</div>
      </div>
      <div class="kartu-info">
        <div class="label">Verifikasi dua faktor</div>
        <div class="nilai">{{ method_exists($pengguna, 'duaFaktorAktif') && $pengguna->duaFaktorAktif() ? 'Aktif' : 'Nonaktif' }}</div>
        <div class="catatan">{{ method_exists($pengguna, 'duaFaktorAktif') && $pengguna->duaFaktorAktif() ? 'Kode dikirim ke surel saat masuk.' : 'Dapat diaktifkan di pengaturan keamanan.' }}</div>
      </div>
    </div>
  </section>
@endsection
