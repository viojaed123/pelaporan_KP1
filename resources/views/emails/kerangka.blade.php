<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('judul')</title>
</head>
<body style="margin:0;padding:0;background:{{ config('keamanan.warna_bg') }};font-family:Segoe UI,Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
           style="background:{{ config('keamanan.warna_bg') }};padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                       style="max-width:560px;background:#FFFFFF;border-radius:12px;overflow:hidden;border:1px solid #E2E8F0;">
                    {{-- Kop: nama sistem + organisasi --}}
                    <tr>
                        <td style="background:{{ config('keamanan.warna_biru_tua') }};padding:18px 24px;">
                            <div style="color:#FFFFFF;font-size:18px;font-weight:700;">
                                {{ config('keamanan.aplikasi_singkat') }}
                            </div>
                            <div style="color:#CBD5E1;font-size:12px;margin-top:2px;">
                                {{ config('keamanan.organisasi') }}
                            </div>
                        </td>
                    </tr>

                    {{-- Isi --}}
                    <tr>
                        <td style="padding:24px;">
                            <h1 style="margin:0 0 16px;font-size:18px;color:{{ config('keamanan.warna_biru_tua') }};">
                                @yield('judul')
                            </h1>
                            @yield('badan')
                        </td>
                    </tr>

                    {{-- Kaki --}}
                    <tr>
                        <td style="padding:16px 24px;background:#F8FAFC;border-top:1px solid #E2E8F0;color:#64748b;font-size:12px;">
                            @yield('catatan', 'Pesan ini dikirim otomatis oleh ' . config('app.name') . '. Jangan balas pesan ini.')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
