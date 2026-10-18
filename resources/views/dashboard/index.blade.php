@extends('layouts.app')

@section('judul', 'Dashboard Karyawan')

@section('isi')
  <section class="lembar">
    <h1>Halo, {{ Auth::user()->nama ?? 'Karyawan' }}!</h1>
    <p>Selamat datang di Dashboard Karyawan. Laporan harian, absensi, dan tugas terintegrasi.</p>

    <div class="kartu-ringkas" style="display:flex;gap:16px;flex-wrap:wrap;margin:18px 0">
      <div class="panel-grafik" style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Laporan Bulan Ini</div>
        <div style="font-size:28px;font-weight:700;color:#005EB8">{{ $jumlahLaporanBulanIni ?? 0 }}</div>
      </div>
      <div class="panel-grafik" style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Absensi Hari Ini</div>
        <div style="font-size:28px;font-weight:700;color:#159447">{{ $jumlahAbsensiHariIni ?? 0 }}</div>
      </div>
      <div class="panel-grafik" style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Aktif</div>
        <div style="font-size:28px;font-weight:700;color:#003D7D">{{ $jumlahTugasAktif ?? 0 }}</div>
      </div>
      <div class="panel-grafik" style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Terlambat</div>
        <div style="font-size:28px;font-weight:700;color:#EB1C24">{{ $tugasTerlambat ?? 0 }}</div>
      </div>
    </div>

    @if(($daftarTugas ?? collect())->isNotEmpty())
      <h2>Tugas Terbaru</h2>
      <table class="tabel">
        <thead><tr><th>Tugas</th><th>Dari</th><th>Tenggat</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($daftarTugas as $t)
          @php $telat = $t->tenggat && $t->tenggat->isPast(); @endphp
          <tr>
            <td>{{ $t->judul }}</td>
            <td>{{ $t->pemberiTugas->nama ?? '-' }}</td>
            <td @if($telat) style="color:#EB1C24" @endif>{{ $t->tenggat ? $t->tenggat->format('d M Y') : '-' }}</td>
            <td>{{ ucfirst($t->status) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
      <p><a href="{{ route('tugas.index') }}">Lihat semua tugas &rarr;</a></p>
    @endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
      <a href="{{ route('laporan.buat') }}" class="btn btn-primary">Buat Laporan Baru</a>
      <a href="{{ route('absensi.index') }}" class="btn btn-secondary">Absensi Hari Ini</a>
    </div>
  </section>
@endsection
