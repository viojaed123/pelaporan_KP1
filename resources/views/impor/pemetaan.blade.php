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
</section>
@endsection
