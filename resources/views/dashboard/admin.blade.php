@extends('layouts.app')

@section('judul', 'Dashboard Admin')

@section('isi')
  @php
    $pengguna = Auth::user();
  @endphp
  <section class="lembar">
    <h1>Halo Admin, {{ $pengguna->nama ?? '' }}</h1>
    <p>Pengelolaan pengguna, riwayat audit, backup, dan konfigurasi sistem.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="{{ asset('../riwayat_audit.php') }}" class="btn btn-primary">Riwayat Audit</a>
      <a href="{{ asset('../status.php') }}" class="btn btn-primary">Status Sistem</a>
      <a href="{{ asset('../backup.php') }}" class="btn btn-primary">Backup Data</a>
    </div>
  </section>
@endsection