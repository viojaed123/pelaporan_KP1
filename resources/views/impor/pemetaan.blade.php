@extends('layouts.app')
@section('judul', 'Pemetaan Kolom')
@section('isi')
<section class="lembar">
  <h1>Pemetaan Kolom ({{ strtoupper($jenis) }})</h1>
  <p>Petakan kolom file ke kolom sistem. Tebakan otomatis bisa disesuaikan.</p>
  <form method="POST" action="{{ route('impor.pratinjau') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <input type="hidden" name="jenis" value="{{ $jenis }}">
    <input type="hidden" name="ext" value="{{ $ext }}">
    <div class="grid" style="gap:12px">
      @foreach($baca['header'] as $idx => $hdr)
      <div>
        <label>{{ $hdr }}</label>
        <select name="peta[{{ $idx }}]" class="input">
          <option value="">-- Lewati --</option>
          @foreach($field as $k=>$def)
          <option value="{{ $k }}" @if(isset($petaTebak[$k]) && $petaTebak[$k]==$idx) selected @endif>{{ $def['label'] }}</option>
          @endforeach
        </select>
      </div>
      @endforeach
    </div>
    <div style="margin-top:16px">
      <button type="submit" class="btn btn-primary">Lanjut ke Pratinjau</button>
      <a href="{{ route('impor.index') }}" class="btn btn-secondary">Batal</a>
    </div>
  </form>

  @if(!empty($profil))
  <div style="margin-top:22px">
    <h2>Profil Pemetaan Tersimpan</h2>
    <form method="GET" action="{{ route('impor.pemetaan') }}">
      <input type="hidden" name="token" value="{{ $token }}">
      <input type="hidden" name="jenis" value="{{ $jenis }}">
      <input type="hidden" name="ext" value="{{ $ext }}">
      <select name="profil" class="input" onchange="this.form.submit()">
        <option value="">-- Pilih Profil --</option>
        @foreach($profil as $p)
        <option value="{{ $p['id'] }}">{{ $p['nama'] }}</option>
        @endforeach
      </select>
    </form>
    <p class="teks-kecil">Memilih profil akan langsung menerapkan pemetaan kolom tersimpan.</p>
  </div>
  @endif

  <div style="margin-top:22px;border-top:1px solid #ddd;padding-top:14px">
    <h2>Simpan Pemetaan Ini sebagai Profil</h2>
    <form method="POST" action="{{ route('impor.profil.simpan') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <input type="hidden" name="jenis" value="{{ $jenis }}">
      <input type="hidden" name="ext" value="{{ $ext }}">
      <input type="text" name="nama_profil" class="input" placeholder="Nama profil (mis. Rekap Mingguan)" required maxlength="100">
      <button type="submit" class="btn btn-secondary">Simpan Profil</button>
    </form>
  </div>
</section>
@endsection
