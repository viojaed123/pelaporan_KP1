@extends('layouts.app')
@section('judul', 'Hasil Impor')
@section('isi')
<section class="lembar">
  <h1>Hasil Impor ({{ strtoupper($jenis) }})</h1>

  <div class="kartu-ringkas" style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:18px">
    <div style="color:#159447"><strong>Masuk:</strong> {{ $res['masuk'] ?? 0 }}</div>
    <div><strong>Dilewati (duplikat):</strong> {{ $res['dilewati'] ?? 0 }}</div>
    <div style="color:#EB1C24"><strong>Gagal:</strong> {{ $res['gagal'] ?? 0 }}</div>
    <div style="color:#005EB8"><strong>Diperbarui:</strong> {{ $res['diperbarui'] ?? 0 }}</div>
  </div>

  <div class="panel-grafik" style="max-width:480px">
    <canvas id="grafikHasil" height="220"></canvas>
  </div>

  <div style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap">
    <a href="{{ route('impor.index') }}" class="btn btn-primary">Impor Berkas Lain</a>
    @if($jenis==='laporan')
      <a href="{{ route('rekap.laporan') }}" class="btn btn-secondary">Lihat Rekap Laporan</a>
    @else
      <a href="{{ route('rekap.absensi') }}" class="btn btn-secondary">Lihat Rekap Absensi</a>
    @endif
  </div>
</section>

<script src="{{ asset('assets/vendor/chart.umd.min.js') }}"></script>
<script>
new Chart(document.getElementById('grafikHasil'), {
  type: 'doughnut',
  data: {
    labels: ['Masuk','Dilewati','Gagal','Diperbarui'],
    datasets: [{
      data: [{{ (int)($res['masuk'] ?? 0) }}, {{ (int)($res['dilewati'] ?? 0) }}, {{ (int)($res['gagal'] ?? 0) }}, {{ (int)($res['diperbarui'] ?? 0) }}],
      backgroundColor: ['#159447','#F0A500','#EB1C24','#005EB8']
    }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});
</script>
@endsection
