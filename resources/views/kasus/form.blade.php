@extends('layouts.app')
@section('judul', $kasus ? 'Edit Kasus' : 'Buat Kasus Baru')
@section('isi')
@php
  $judulHal = $kasus ? 'Edit Kasus' : 'Buat Kasus Baru';
  /* Nilai awal kolom (edit memakai teks tersimpan; buat kosong). */
  $vJudul   = old('judul', $kasus->judul ?? '');
  $vDesk    = old('deskripsi', $kasus->deskripsi ?? '');
  $vKat     = old('kategori', $kasus->kategori ?? '');
  $vUrg     = old('tingkat_urgensi', $kasus->tingkat_urgensi ?? 'Sedang');
  $vStat    = old('status_kasus', $kasus->status_kasus ?? 'Terbuka');
  $vProg    = old('progres', $kasus->progres ?? 0);
  $vTarget  = old('target_selesai', isset($kasus->target_selesai) ? $kasus->target_selesai->format('Y-m-d') : '');
  $vLokasi  = old('nama_lokasi', $kasus->nama_lokasi ?? '');
  $vLat     = old('garis_lintang', $kasus->garis_lintang ?? '');
  $vLng     = old('garis_bujur', $kasus->garis_bujur ?? '');
  /* PIC & anggota: DIKETIK BEBAS, bukan dropdown. */
  $vPIC     = old('penanggung_jawab', $kasus->penanggung_jawab_teks ?? '');
  $vAnggota = old('anggota', isset($kasus) ? $kasus->anggota->pluck('nama_teks')->filter()->implode("\n") : '');
  $aksi = $kasus ? route('kasus.perbarui', $kasus->id) : route('kasus.simpan');
@endphp

<section class="lembar">
  <h1>{{ $judulHal }}</h1>

  @if($errors->any())
    <div class="peringatan">
      <strong>Periksa kembali isian:</strong>
      <ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
  @endif

  <form method="POST" action="{{ $aksi }}" class="kartu">
    @csrf
    <div class="kartu-tubuh grup-form">
      <div class="form-grup">
        <label for="judul">Judul Kasus <span class="wajib">*</span></label>
        <input id="judul" name="judul" type="text" class="isian" maxlength="200" required
               value="{{ $vJudul }}" placeholder="Ringkasan singkat permasalahan">
      </div>

      <div class="form-grup">
        <label for="deskripsi">Deskripsi</label>
        <textarea id="deskripsi" name="deskripsi" class="isian" rows="4"
                  placeholder="Uraian permasalahan, dampak, dan konteks">{{ $vDesk }}</textarea>
      </div>

      <div class="grup-form-dua">
        <div class="form-grup">
          <label for="kategori">Kategori</label>
          <select id="kategori" name="kategori" class="isian">
            <option value="">— Pilih kategori —</option>
            @foreach($opsiKategori as $kat)
              <option value="{{ $kat }}" @selected($vKat === $kat)>{{ $kat }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-grup">
          <label for="tingkat_urgensi">Tingkat Urgensi <span class="wajib">*</span></label>
          <select id="tingkat_urgensi" name="tingkat_urgensi" class="isian" required>
            @foreach($opsiUrgensi as $urg)
              <option value="{{ $urg }}" @selected($vUrg === $urg)>{{ $urg }}</option>
            @endforeach
          </select>
        </div>
      </div>

      <div class="grup-form-tiga">
        <div class="form-grup">
          <label for="status_kasus">Status</label>
          <select id="status_kasus" name="status_kasus" class="isian">
            @foreach($opsiStatus as $st)
              <option value="{{ $st }}" @selected($vStat === $st)>{{ $st }}</option>
            @endforeach
          </select>
        </div>
        <div class="form-grup">
          <label for="progres">Progres (%)</label>
          <input id="progres" name="progres" type="number" class="isian" min="0" max="100" value="{{ $vProg }}">
        </div>
        <div class="form-grup">
          <label for="target_selesai">Target Selesai</label>
          <input id="target_selesai" name="target_selesai" type="date" class="isian" value="{{ $vTarget }}">
        </div>
      </div>

      <div class="form-grup">
        <label for="nama_lokasi">Nama Lokasi</label>
        <input id="nama_lokasi" name="nama_lokasi" type="text" class="isian" maxlength="200"
               value="{{ $vLokasi }}" placeholder="Contoh: Desa Duri, Kecamatan Mandau">
      </div>

      <div class="grup-form-dua">
        <div class="form-grup">
          <label for="garis_lintang">Garis Lintang (opsional)</label>
          <input id="garis_lintang" name="garis_lintang" type="text" class="isian"
                 value="{{ $vLat }}" placeholder="Contoh: 1.28111">
        </div>
        <div class="form-grup">
          <label for="garis_bujur">Garis Bujur (opsional)</label>
          <input id="garis_bujur" name="garis_bujur" type="text" class="isian"
                 value="{{ $vLng }}" placeholder="Contoh: 101.21345">
        </div>
      </div>

      {{--
        PIC & anggota tim DIKETIK BEBAS (bukan dropdown): fleksibel dan
        tidak terbatas pada daftar pengguna terdaftar. Nama yang cocok
        dengan pengguna terdaftar otomatis memperoleh user_id sehingga
        notifikasi & hak akses tetap berfungsi; yang tidak cocok tetap
        tersimpan sebagai teks. Anggota: satu nama per baris.
      --}}
      <div class="form-grup">
        <label for="penanggung_jawab">Penanggung Jawab (PIC)</label>
        <input id="penanggung_jawab" name="penanggung_jawab" type="text" class="isian"
               maxlength="150" value="{{ $vPIC }}"
               placeholder="Ketik nama atau surel penanggung jawab">
        <span class="teks-redup">Ketik bebas — bila cocok dengan pengguna terdaftar, otomatis tertaut.</span>
      </div>

      <div class="form-grup">
        <label for="anggota">Anggota Tim (satu nama per baris)</label>
        <textarea id="anggota" name="anggota" class="isian" rows="4"
                  placeholder="Budi&#10;Sari&#10;Andi">{{ $vAnggota }}</textarea>
        <span class="teks-redup">Ketik bebas, satu nama per baris. Nama yang cocok dengan pengguna terdaftar otomatis tertaut.</span>
      </div>
    </div>

    <div class="kartu-kaki">
      <button type="submit" class="btn btn-utama">Simpan Kasus</button>
      <a href="{{ route('kasus.index') }}" class="btn">Batal</a>
    </div>
  </form>
</section>
@endsection
