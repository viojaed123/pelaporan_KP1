@extends('layouts.auth')

@section('judul', 'Verifikasi Dua Faktor')

@section('kiri')
    <h2 class="judul-auth">Langkah terakhir.</h2>
    <p class="teks-auth">
        Kami baru saja mengirim kode {{ (int) config('keamanan.otp_panjang') }} angka ke
        <strong>{{ $email }}</strong>. Masukkan kode tersebut untuk menyelesaikan proses masuk.
    </p>
    <ul class="daftar-fitur">
        <li><span class="cek">✓</span> Kode berlaku {{ $menitBerlaku }} menit</li>
        <li><span class="cek">✓</span> Kode hanya berlaku satu kali</li>
        <li><span class="cek">✓</span> Tidak menerima surel? Pakai kode cadangan</li>
    </ul>
@endsection

@section('isi')
    <h1>Verifikasi Dua Faktor</h1>
    <p class="pengantar">
        Masukkan kode dari surel Anda. Jika mengaktifkan kode cadangan,
        ketik format <strong>XXXXXX-XXXX</strong>.
    </p>

    @if (session('info'))
        <div class="alert alert-info" role="alert">
            <span class="ikon">ℹ</span>
            <span>{{ session('info') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-gagal" role="alert">
            <span class="ikon">✕</span>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('dua.faktor.verifikasi') }}" autocomplete="one-time-code">
        @csrf

        <div class="grup-form">
            <label for="kode">Kode verifikasi</label>
            <input class="kode-masuk @error('kode') input-galat @enderror"
                   id="kode" name="kode" type="text"
                   inputmode="numeric" maxlength="12"
                   placeholder="000000"
                   autocomplete="one-time-code"
                   required autofocus>
            <span class="bantuan">
                {{ (int) config('keamanan.otp_panjang') }} angka — tanpa spasi atau tanda baca lain.
            </span>
        </div>

        <button class="tombol tombol-utama" type="submit">Verifikasi &amp; Masuk</button>
    </form>

    <form method="POST" action="{{ route('dua.faktor.kirim-ulang') }}" style="margin-top:12px">
        @csrf
        <button class="tombol tombol-sekunder" type="submit">
            ↻ Kirim ulang kode (sisa {{ $sisaKirim }})
        </button>
    </form>

    <p class="tautan-bawah">
        <a href="{{ route('login') }}">← Kembali ke halaman masuk</a>
    </p>
@endsection
