@extends('layouts.app')

@section('judul', 'Dashboard Mentor')

@section('isi')
  @php
    $pengguna = Auth::user();
  @endphp
  <section class="lembar">
    <h1>Halo Mentor, {{ $pengguna->nama ?? '' }}</h1>
    <p>Rekap laporan, validasi, dan monitoring kegiatan tim.</p>
    <a href="{{ asset('../rekap_laporan.php') }}" class="btn btn-primary">Lihat Rekap Laporan</a>
  </section>
@endsection