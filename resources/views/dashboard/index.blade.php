@extends('layouts.app')

@section('judul', 'Dashboard Karyawan')

@section('isi')
  @php
    $pengguna = Auth::user();
  @endphp
  <section class="lembar">
    <h1>Halo, {{ $pengguna->nama ?? 'Karyawan' }}!</h1>
    <p>Selamat datang di Dashboard Karyawan. Laporan harian, absensi, dan notifikasi terintegrasi.</p>
    <a href="{{ asset('../buat_laporan.php') }}" class="btn btn-primary">Buat Laporan Baru</a>
  </section>
@endsection