@extends('emails.kerangka')

@section('judul', 'Kode Verifikasi Dua Faktor')

@section('badan')
    @php
        /* Masa berlaku ditampilkan ke pengguna. Dihitung dari konfigurasi
           (bukan angka tetap) agar tidak pernah berbeda dengan yang
           sebenarnya diberlakukan layanan OTP. */
        $menitBerlaku = (int) ceil(((int) config('keamanan.otp_detik')) / 60);
    @endphp

    <p style="margin:0 0 14px;color:#334155;font-size:14px;">
        {{ $nama }}, gunakan kode berikut untuk menyelesaikan proses masuk:
    </p>

    {{-- Kode ditampilkan besar & berjarak huruf agar mudah dibaca/disalin --}}
    <div style="font-family:Consolas,Menlo,monospace;font-size:30px;letter-spacing:8px;
                font-weight:700;color:{{ config('keamanan.warna_biru_tua') }};background:#F1F5F9;
                padding:16px 0;text-align:center;border-radius:8px;margin-bottom:14px;">
        {{ $kode }}
    </div>

    <p style="margin:0 0 6px;color:#64748b;font-size:13px;">
        Berlaku selama {{ $menitBerlaku }} menit. Jangan bagikan kode ini kepada siapa pun.
    </p>
@endsection

@section('catatan')
    Jika Anda tidak merasa meminta kode ini, abaikan pesan ini dan segera ganti kata sandi Anda.
@endsection
