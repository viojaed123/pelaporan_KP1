@extends('layouts.app')
@section('judul', 'Penugasan Tugas')
@section('isi')
<section class="lembar">
  <h1>Penugasan Tugas</h1>

  @if(session('sukses'))
    <div class="notifikasi-sukses">{{ session('sukses') }}</div>
  @endif

  @php $rolePengguna = Auth::user()->role ?? 'karyawan'; @endphp
  @if($rolePengguna !== 'karyawan')
    <p><a href="{{ route('tugas.buat') }}" class="btn btn-primary">+ Tugaskan Tugas Baru</a></p>
  @endif

  @if(($terlambat ?? 0) > 0)
    <div class="peringatan">Ada <strong>{{ $terlambat }}</strong> tugas yang melewati tenggat.</div>
  @endif

  <table class="tabel">
    <thead>
      <tr><th>Tugas</th><th>Penerima</th><th>Ditugaskan Oleh</th><th>Tenggat</th><th>Status</th><th>Aksi</th></tr>
    </thead>
    <tbody>
      @forelse($tugas as $t)
      @php $telat = $t->tenggat && $t->tenggat->isPast() && !in_array($t->status, ['selesai','dibatalkan']); @endphp
      <tr>
        <td>
          <strong>{{ $t->judul }}</strong>
          @if($t->deskripsi)<br><small>{{ \Illuminate\Support\Str::limit($t->deskripsi, 120) }}</small>@endif
        </td>
        <td>{{ $t->penerima->nama ?? '-' }}</td>
        <td>{{ $t->pemberiTugas->nama ?? '-' }}</td>
        <td @if($telat) style="color:#EB1C24" @endif>{{ $t->tenggat ? $t->tenggat->format('d M Y') : '-' }}@if($telat) (terlambat)@endif</td>
        <td>{{ ucfirst($t->status) }}</td>
        <td>
          @if($t->status !== 'selesai' && $t->status !== 'dibatalkan')
          <form method="POST" action="{{ route('tugas.status', $t->id) }}" style="display:inline">
            @csrf
            @if($t->status === 'baru')
              <input type="hidden" name="status" value="dikerjakan">
              <button type="submit" class="btn btn-mini">Mulai</button>
            @else
              <input type="hidden" name="status" value="selesai">
              <button type="submit" class="btn btn-mini">Tandai Selesai</button>
            @endif
          </form>
          @else
            <span style="color:#159447">&#10003; {{ $t->diselesaikan_pada ? $t->diselesaikan_pada->format('d/m/Y') : '' }}</span>
          @endif
        </td>
      </tr>
      @empty
      <tr><td colspan="6">Belum ada tugas.</td></tr>
      @endforelse
    </tbody>
  </table>

  {{ $tugas->links() }}
</section>
@endsection
