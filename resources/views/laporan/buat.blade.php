@extends('layouts.app')

@section('judul', 'Buat Laporan Harian')

@section('isi')
  @php
    $pengguna = Auth::user();
  @endphp
  <section class="lembar">
    <h1>Buat Laporan Kegiatan Harian</h1>
    <p>Modul migrasi dari <code>buat_laporan.php</code>. Saat ini dalam bentuk kerangka (placeholder) agar alur navigasi terhubung.</p>
    <div class="catatan-kaki">
      Perlu integrasi Eloquent (Kegiatan, Foto, Verifikasi). Dapat dilanjutkan bertahap tanpa mengubah flat-PHP.
    </div>
  </section>
@endsection