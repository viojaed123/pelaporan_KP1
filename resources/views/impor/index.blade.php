@extends('layouts.app')
@section('judul', 'Impor Data')
@section('isi')
<section class="lembar">
  <h1>Impor Data</h1>
  <p>Pilih jenis data dan unggah file Excel/CSV untuk diimpor.</p>
  <form method="POST" action="{{ route('impor.unggah') }}" enctype="multipart/form-data">
    @csrf
    <div style="margin-bottom:14px">
      <label>Jenis Data</label>
      <select name="jenis" class="input">
        <option value="laporan">Laporan Harian</option>
        <option value="absensi">Absensi</option>
      </select>
    </div>
    <div style="margin-bottom:14px">
      <label>Berkas (XLSX, XLS, CSV)</label>
      <input type="file" name="berkas" accept=".xlsx,.xls,.csv" required>
    </div>
    <button type="submit" class="btn btn-primary">Unggah</button>
  </form>
</section>
@endsection
