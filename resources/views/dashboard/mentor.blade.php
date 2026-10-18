@extends('layouts.app')

@section('judul', 'Dashboard Mentor')

@section('isi')
  <section class="lembar">
    <h1>Halo Mentor, {{ Auth::user()->nama ?? '' }}</h1>
    <p>Rekap laporan, validasi, penugasan, dan monitoring kegiatan tim.</p>

    <div style="display:flex;gap:16px;flex-wrap:wrap;margin:18px 0">
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Laporan Tim Bulan Ini</div>
        <div style="font-size:28px;font-weight:700;color:#005EB8">{{ $jumlahLaporanBulanIni ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Absensi Tim Hari Ini</div>
        <div style="font-size:28px;font-weight:700;color:#159447">{{ $jumlahAbsensiHariIni ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Diberikan Aktif</div>
        <div style="font-size:28px;font-weight:700;color:#003D7D">{{ $jumlahTugasAktif ?? 0 }}</div>
      </div>
      <div style="flex:1;min-width:160px;padding:16px;background:#fff;border-radius:10px">
        <div style="font-size:13px;color:#666">Tugas Terlambat</div>
        <div style="font-size:28px;font-weight:700;color:#EB1C24">{{ $tugasTerlambat ?? 0 }}</div>
      </div>
    </div>

    @if(($daftarTugas ?? collect())->isNotEmpty())
      <h2>Tugas Terbaru</h2>
      <table class="tabel">
        <thead><tr><th>Tugas</th><th>Tenggat</th><th>Status</th></tr></thead>
        <tbody>
          @foreach($daftarTugas as $t)
          @php $telat = $t->tenggat && $t->tenggat->isPast(); @endphp
          <tr>
            <td>{{ $t->judul }}</td>
            <td @if($telat) style="color:#EB1C24" @endif>{{ $t->tenggat ? $t->tenggat->format('d M Y') : '-' }}</td>
            <td>{{ ucfirst($t->status) }}</td>
          </tr>
          @endforeach
        </tbody>
      </table>
    @endif

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:18px">
      <a href="{{ route('rekap.laporan') }}" class="btn btn-primary">Lihat Rekap Laporan</a>
      <a href="{{ route('tugas.buat') }}" class="btn btn-secondary">Tugaskan Tugas</a>
    </div>
  </section>
@endsection
