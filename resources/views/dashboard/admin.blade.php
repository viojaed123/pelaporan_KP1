@extends('layouts.app')

@section('judul', 'Dashboard Admin')

@section('isi')
  <section class="lembar">
    <h1>Halo Admin, {{ Auth::user()->nama ?? '' }}</h1>
    <p>Pengelolaan pengguna, riwayat audit, backup, dan konfigurasi sistem.</p>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin:18px 0">
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Laporan Seluruh Bulan Ini</div>
        <div style="font-size:28px;font-weight:700;color:#005EB8">{{ $jumlahLaporanBulanIni ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Absensi Hari Ini</div>
        <div style="font-size:28px;font-weight:700;color:#159447">{{ $jumlahAbsensiHariIni ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Aktif</div>
        <div style="font-size:28px;font-weight:700;color:#003D7D">{{ $jumlahTugasAktif ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Terlambat</div>
        <div style="font-size:28px;font-weight:700;color:#EB1C24">{{ $tugasTerlambat ?? 0 }}</div>
      </div>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="{{ route('rekap.laporan') }}" class="btn btn-primary">Rekap Laporan</a>
      <a href="{{ route('tugas.buat') }}" class="btn btn-secondary">Tugaskan Tugas</a>
      <a href="{{ route('impor.index') }}" class="btn btn-secondary">Impor Excel</a>
      <a href="{{ route('asisten.index') }}" class="btn btn-secondary">Asisten AI</a>
    </div>
  </section>
@endsection
