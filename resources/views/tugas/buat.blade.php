@extends('layouts.app')
@section('judul', 'Tugaskan Tugas Baru')
@section('isi')
<section class="lembar">
  <h1>Tugaskan Tugas Baru</h1>
  <form method="POST" action="{{ route('tugas.simpan') }}">
    @csrf
    <div style="margin-bottom:14px">
      <label for="user_id">Karyawan</label>
      <select name="user_id" id="user_id" class="input" required>
        <option value="">-- Pilih Karyawan --</option>
        @foreach($daftarKaryawan as $k)
        <option value="{{ $k->id }}" @selected(old('user_id') == $k->id)>{{ $k->nama }}</option>
        @endforeach
      </select>
      @error('user_id')<div class="galat">{{ $message }}</div>@enderror
    </div>
    <div style="margin-bottom:14px">
      <label for="judul">Judul Tugas</label>
      <input type="text" name="judul" id="judul" class="input" value="{{ old('judul') }}" required maxlength="200">
      @error('judul')<div class="galat">{{ $message }}</div>@enderror
    </div>
    <div style="margin-bottom:14px">
      <label for="deskripsi">Deskripsi</label>
      <textarea name="deskripsi" id="deskripsi" class="input" rows="5">{{ old('deskripsi') }}</textarea>
    </div>
    <div style="margin-bottom:14px">
      <label for="tenggat">Tenggat</label>
      <input type="date" name="tenggat" id="tenggat" class="input" value="{{ old('tenggat') }}">
      @error('tenggat')<div class="galat">{{ $message }}</div>@enderror
    </div>
    <button type="submit" class="btn btn-primary">Tugaskan</button>
    <a href="{{ route('tugas.index') }}" class="btn btn-secondary">Batal</a>
  </form>
</section>
@endsection
