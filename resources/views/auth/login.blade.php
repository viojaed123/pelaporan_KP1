@extends('layouts.auth')

@section('judul', 'Masuk')

@section('isi')
    @php
        /*
         * Pesan dari pengalihan (alasan) dipetakan PERSIS seperti sistem lama,
         * sehingga pengguna yang tiba di sini karena sesi habis / baru keluar /
         * belum masuk mendapat penjelasan yang tepat -- bukan halaman kosong.
         */
        $pesanAlasan = match ($alasan) {
            'sesi_habis' => ['info', 'Sesi Anda berakhir demi keamanan. Silakan masuk kembali.'],
            'keluar'     => ['info', 'Anda telah keluar dengan aman.'],
            'akses'      => ['info', 'Anda harus masuk terlebih dahulu untuk mengakses halaman tersebut.'],
            'kadaluarsa' => ['info', 'Tautan sudah tidak berlaku. Silakan masuk kembali.'],
            default      => null,
        };

        $pesanGalat = $errors->first();
    @endphp

    <h1>Selamat Datang</h1>
    <p class="pengantar">Masuk untuk mengelola laporan kegiatan harian Anda.</p>

    {{-- Pesan informasi dari pengalihan --}}
    @if ($pesanAlasan !== null)
        <div class="alert alert-{{ $pesanAlasan[0] }}" role="alert">
            <span class="ikon">ℹ</span>
            <span>{{ $pesanAlasan[1] }}</span>
        </div>
    @endif

    {{-- Pesan informasi dari aksi sebelumnya (mis. kode dikirim ulang) --}}
    @if (session('info'))
        <div class="alert alert-info" role="alert">
            <span class="ikon">ℹ</span>
            <span>{{ session('info') }}</span>
        </div>
    @endif

    {{-- Pesan kesalahan otentikasi / validasi. Sengaja TIDAK membedakan
         "surel tidak terdaftar" dari "sandi salah". --}}
    @if ($pesanGalat)
        <div class="alert alert-gagal" role="alert">
            <span class="ikon">✕</span>
            <span>{{ $pesanGalat }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('login.masuk') }}" autocomplete="on" novalidate>
        @csrf

        <div class="grup-form">
            <label for="email">Alamat surel</label>
            <input id="email" name="email" type="email"
                   class="@error('email') input-galat @enderror"
                   value="{{ $email }}"
                   autocomplete="username"
                   maxlength="150"
                   placeholder="nama@perusahaan.co.id"
                   required autofocus>
        </div>

        <div class="grup-form">
            <label for="password">Kata sandi</label>
            <div class="field-input">
                <input id="password" name="password" type="password"
                       autocomplete="current-password"
                       placeholder="••••••••"
                       required>
            </div>
        </div>

        <button class="tombol tombol-utama" type="submit">Masuk</button>
    </form>

    <p class="tautan-bawah">
        Belum punya akun? <a href="#">Daftar di sini</a>
    </p>
    <p class="tautan-bawah">
        <a href="#">Lupa kata sandi? Gunakan kode OTP</a>
    </p>
    <p class="tautan-bawah">
        <a href="{{ route('beranda') }}">← Kembali ke halaman utama</a>
    </p>
@endsection
