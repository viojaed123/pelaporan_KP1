@extends('layouts.app')
@section('judul', 'Detail Kasus')
@section('isi')
@php
  use App\Models\User;
  /* Fungsi bantu tampilan PIC/anggota: teks ketikan, dengan jangkar
     tautan ke pengguna terdaftar bila user_id terisi (pencocokan otomatis). */
  $tampilPihak = function (?int $userId, ?string $teks) {
      $nama = $teks;
      if (($nama === null || $nama === '') && $userId !== null) {
          $nama = User::find($userId)?->nama;
      }
      $nama = $nama ?: '—';
      if ($userId !== null) {
          return '<a href="' . e(route('profil.index')) . '">' . e($nama) . '</a>';
      }
      return e($nama) . ' <span class="teks-redup">(teks bebas)</span>';
  };
@endphp
<section class="lembar">
  <h1>{{ $kasus->judul }}</h1>
  <p class="teks-redup">Kode {{ $kasus->kode_kasus }} · Dibuat {{ $kasus->dibuat_pada?->format('d M Y H:i') }}</p>

  @if(session('sukses'))
    <div class="notifikasi-sukses">{{ session('sukses') }}</div>
  @endif

  <div class="kartu">
    <div class="kartu-tubuh">
      <p><strong>Status:</strong> <span class="badge badge-{{ $kasus->status_kasus === 'Selesai' ? 'hijau' : 'biru' }}">{{ $kasus->status_kasus }}</span>
         &nbsp; <strong>Urgensi:</strong> {{ $kasus->tingkat_urgensi ?? '—' }}
         &nbsp; <strong>Progres:</strong> {{ (int) $kasus->progres }}%</p>
      <p><strong>Kategori:</strong> {{ $kasus->kategori ?? '—' }}
         &nbsp; <strong>Target selesai:</strong> {{ $kasus->target_selesai?->format('d M Y') ?? '—' }}</p>
      <p><strong>Lokasi:</strong> {{ $kasus->nama_lokasi ?? '—' }}</p>
      @if($kasus->deskripsi)
        <p><strong>Deskripsi:</strong><br>{!! nl2br(e($kasus->deskripsi)) !!}</p>
      @endif
    </div>
  </div>

  <div class="kartu">
    <div class="kartu-kepala">Pihak Terkait</div>
    <div class="kartu-tubuh">
      <p><strong>Penanggung Jawab (PIC):</strong><br>{!! $tampilPihak($kasus->penanggung_jawab_id, $kasus->penanggung_jawab_teks) !!}</p>
      <p><strong>Anggota Tim:</strong></p>
      @if($anggota->count() === 0)
        <p class="tabel-kosong">Tidak ada anggota tim tercatat.</p>
      @else
        <ul>
          @foreach($anggota as $a)
            <li>{!! $tampilPihak($a->user_id, $a->nama_teks) !!} <span class="teks-redup">— {{ $a->peran ?? 'Anggota Tim' }}</span></li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>

  @if($bolehKelola)
    <p>
      <a href="{{ route('kasus.edit', $kasus->id) }}" class="btn btn-utama">Edit Kasus</a>
    </p>
  @endif

  <div class="kartu">
    <div class="kartu-kepala">Riwayat Status</div>
    <div class="kartu-tubuh">
      @if($log->count() === 0)
        <p class="tabel-kosong">Belum ada perubahan status tercatat.</p>
      @else
        <table class="tabel">
          <thead><tr><th>Waktu</th><th>Perubahan</th><th>Progres</th><th>Catatan</th><th>Oleh</th></tr></thead>
          <tbody>
            @foreach($log as $l)
              <tr>
                <td>{{ $l->dibuat_pada?->format('d M Y H:i') }}</td>
                <td>{{ $l->status_lama ?? '—' }} → {{ $l->status_baru }}</td>
                <td>{{ (int) $l->progres }}%</td>
                <td>{{ $l->catatan ?? '—' }}</td>
                <td>{{ $l->user?->nama ?? '—' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
    </div>
  </div>

  <p><a href="{{ route('kasus.index') }}" class="btn">← Kembali ke daftar</a></p>
</section>
@endsection
