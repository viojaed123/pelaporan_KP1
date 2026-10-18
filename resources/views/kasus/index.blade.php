@extends('layouts.app')
@section('judul', 'Pelacakan Kasus')
@section('isi')
<section class="lembar">
  <h1>Pelacakan Kasus</h1>

  @if(session('sukses'))
    <div class="notifikasi-sukses">{{ session('sukses') }}</div>
  @endif
  @if(session('galat'))
    <div class="peringatan">{{ session('galat') }}</div>
  @endif

  <p><a href="{{ route('kasus.buat') }}" class="btn btn-utama">+ Buat Kasus Baru</a></p>

  {{-- Filter sederhana --}}
  <form method="GET" action="{{ route('kasus.index') }}" class="baris-filter">
    <input type="text" name="q" class="isian" placeholder="Cari judul / kode kasus"
           value="{{ request('q') }}">
    <select name="status" class="isian">
      <option value="">— Semua status —</option>
      @foreach($opsiStatus as $st)
        <option value="{{ $st }}" @selected(request('status') === $st)>{{ $st }}</option>
      @endforeach
    </select>
    <select name="urgensi" class="isian">
      <option value="">— Semua urgensi —</option>
      @foreach($opsiUrgensi as $urg)
        <option value="{{ $urg }}" @selected(request('urgensi') === $urg)>{{ $urg }}</option>
      @endforeach
    </select>
    <button type="submit" class="btn">Saring</button>
  </form>

  @if($daftar->count() === 0)
    <p class="tabel-kosong">Belum ada kasus yang melibatkan Anda.</p>
  @else
    <table class="tabel">
      <thead>
        <tr>
          <th>Kode</th>
          <th>Judul</th>
          <th>Kategori</th>
          <th>Urgensi</th>
          <th>Status</th>
          <th>Progres</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach($daftar as $k)
          <tr>
            <td>{{ $k->kode_kasus }}</td>
            <td><a href="{{ route('kasus.detail', $k->id) }}">{{ $k->judul }}</a></td>
            <td>{{ $k->kategori ?? '—' }}</td>
            <td><span class="badge badge-{{ $k->tingkat_urgensi === 'Kritis' || $k->tingkat_urgensi === 'Tinggi' ? 'merah' : ($k->tingkat_urgensi === 'Sedang' ? 'kuning' : 'biru') }}">{{ $k->tingkat_urgensi ?? '—' }}</span></td>
            <td><span class="badge badge-{{ $k->status_kasus === 'Selesai' ? 'hijau' : 'biru' }}">{{ $k->status_kasus ?? '—' }}</span></td>
            <td>{{ (int) $k->progres }}%</td>
            <td><a href="{{ route('kasus.detail', $k->id) }}" class="btn">Detail</a></td>
          </tr>
        @endforeach
      </tbody>
    </table>

    {{ $daftar->links() }}
  @endif
</section>
@endsection
