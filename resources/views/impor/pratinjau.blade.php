@extends('layouts.app')
@section('judul', 'Pratinjau Impor')
@section('isi')
<section class="lembar">
  <h1>Pratinjau Impor ({{ strtoupper($jenis) }})</h1>

  @if(!empty($valid['galat']))
    <div class="peringatan">
      @foreach($valid['galat'] as $g)<p>{{ $g }}</p>@endforeach
    </div>
  @else
    <div class="kartu-ringkas" style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:16px">
      <div><strong>Total:</strong> {{ $valid['ringkas']['total'] }}</div>
      <div style="color:#159447"><strong>Sah:</strong> {{ $valid['ringkas']['sah'] }}</div>
      <div style="color:#EB1C24"><strong>Gagal:</strong> {{ $valid['ringkas']['gagal'] }}</div>
    </div>

    <div style="margin-bottom:12px">
      <label for="filterBaris">Filter baris:</label>
      <select id="filterBaris" class="input" onchange="saringBaris()">
        <option value="semua">Semua ({{ $valid['ringkas']['total'] }})</option>
        <option value="sah">Hanya sah ({{ $valid['ringkas']['sah'] }})</option>
        <option value="gagal">Hanya gagal ({{ $valid['ringkas']['gagal'] }})</option>
      </select>
    </div>

    <div style="overflow-x:auto">
      <table class="tabel" id="tabelPratinjau">
        <thead>
          <tr><th>Baris</th><th>Status</th><th>Pesan</th><th>Data</th></tr>
        </thead>
        <tbody>
          @foreach($valid['baris'] as $b)
          <tr data-status="{{ $b['sah'] ? 'sah' : 'gagal' }}">
            <td>{{ $b['nomor'] }}</td>
            <td>@if($b['sah'])<span style="color:#159447">Sah</span>@else<span style="color:#EB1C24">Gagal</span>@endif</td>
            <td>{{ implode('; ', $b['pesan']) }}</td>
            <td>
              <details>
                <summary>Lihat</summary>
                <pre style="white-space:pre-wrap">{{ json_encode($b['data'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre>
              </details>
            </td>
          </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <form method="POST" action="{{ route('impor.simpan') }}" style="margin-top:18px" onsubmit="return confirm('Simpan {{ $valid['ringkas']['sah'] }} baris sah?');">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <input type="hidden" name="jenis" value="{{ $jenis }}">
      <input type="hidden" name="ext" value="{{ $ext }}">
      <button type="submit" class="btn btn-primary" {{ $valid['ringkas']['sah']===0 ? 'disabled' : '' }}>Simpan {{ $valid['ringkas']['sah'] }} Baris Sah</button>
      <a href="{{ route('impor.pemetaan', ['token'=>$token,'jenis'=>$jenis,'ext'=>$ext]) }}" class="btn btn-secondary">Kembali ke Pemetaan</a>
      <a href="{{ route('impor.index') }}" class="btn btn-secondary">Batal</a>
    </form>
  @endif
</section>
<script>
function saringBaris(){
  var f = document.getElementById('filterBaris').value;
  document.querySelectorAll('#tabelPratinjau tbody tr').forEach(function(tr){
    tr.style.display = (f==='semua' || tr.dataset.status===f) ? '' : 'none';
  });
}
</script>
@endsection
