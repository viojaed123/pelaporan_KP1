@extends('layouts.app')

@section('judul', 'Akun & Keamanan')

@section('isi')
  <section class='lembar'>
    <h1>Akun &amp; Keamanan</h1>
    <p>Kelola identitas, keamanan, dan sesi Anda.</p>
  </section>

  @include('layouts.partials._flash')

  <section class='lembar'>
    <h2>Ganti Kata Sandi</h2>
    <form method='POST' action='{{ route('profil.gantiPassword') }}' autocomplete='off'>
      @csrf
      <div style='margin-bottom:12px'>
        <label>Kata Sandi Lama</label>
        <input type='password' name='password_lama' class='input' required>
      </div>
      <div style='margin-bottom:12px'>
        <label>Kata Sandi Baru</label>
        <input type='password' name='password_baru' class='input' required minlength='8'>
      </div>
      <div style='margin-bottom:12px'>
        <label>Ulangi Kata Sandi Baru</label>
        <input type='password' name='password_baru_confirmation' class='input' required minlength='8'>
      </div>
      <button type='submit' class='btn btn-primary'>Simpan Perubahan</button>
    </form>
    <div class='catatan-kaki' style='margin-top:10px'>
      Kebijakan: minimal 8 karakter, tidak boleh umum/mudah ditebak, tidak boleh memuat surel/nama, dan tidak boleh sama dengan riwayat terakhir.
    </div>
  </section>
@endsection
