@php
    $menitBerlaku = (int) ceil(((int) config('keamanan.otp_detik')) / 60);
@endphp
{{ config('keamanan.aplikasi_singkat') }} — {{ config('keamanan.organisasi') }}

KODE VERIFIKASI DUA FAKTOR

Halo {{ $nama }},

Kode verifikasi dua faktor Anda: {{ $kode }}

Berlaku selama {{ $menitBerlaku }} menit.

Jika Anda tidak meminta kode ini, abaikan pesan ini dan segera ganti kata sandi Anda.
Jangan bagikan kode ini kepada siapa pun.

--
Pesan ini dikirim otomatis oleh {{ config('app.name') }}. Jangan balas pesan ini.
