{{-- Kerangka halaman login --}}
{{--
    STATUS: kerangka. Proses login penuh (verifikasi sandi, pembatas laju,
    penguncian akun, 2FA OTP) dipindahkan pada fase modul autentikasi.
    Tata letak dan skema warna mengikuti identitas korporat PHR.
--}}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Masuk — {{ config('app.name') }}</title>
    <style>
        :root {
            --biru-tua: {{ config('keamanan.warna_biru_tua', '#003D7D') }};
            --biru:     {{ config('keamanan.warna_biru', '#005EB8') }};
            --merah:    {{ config('keamanan.warna_merah', '#EB1C24') }};
            --hijau:    {{ config('keamanan.warna_hijau', '#159447') }};
            --bg:       {{ config('keamanan.warna_bg', '#F5F5F5') }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background:
                linear-gradient(160deg, var(--biru-tua) 0%, var(--biru) 100%);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            padding: 24px;
        }

        .kartu {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.25);
            width: 100%;
            max-width: 400px;
            padding: 40px 36px;
        }

        .merek { text-align: center; margin-bottom: 28px; }
        .merek .logo {
            width: 56px; height: 56px; margin: 0 auto 12px;
            border-radius: 14px;
            background: linear-gradient(135deg, var(--biru-tua), var(--biru));
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 22px;
        }
        .merek h1 { font-size: 17px; margin: 0 0 4px; color: var(--biru-tua); }
        .merek p  { font-size: 13px; margin: 0; color: #64748b; }

        .peringatan {
            background: #fef2f2;
            border: 1px solid rgba(235, 28, 36, 0.30);
            color: #991b1b;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13.5px;
            margin-bottom: 20px;
            line-height: 1.5;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #334155;
            margin-bottom: 6px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            font-size: 15px;
            margin-bottom: 18px;
            transition: border-color .15s, box-shadow .15s;
        }
        input:focus {
            outline: none;
            border-color: var(--biru);
            box-shadow: 0 0 0 3px rgba(0, 94, 184, 0.15);
        }

        button {
            width: 100%;
            padding: 13px;
            border: 0;
            border-radius: 9px;
            background: var(--biru);
            color: #fff;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s, box-shadow .15s;
        }
        button:hover { background: var(--biru-tua); box-shadow: 0 6px 18px rgba(0, 94, 184, .30); }

        .bawah {
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: #64748b;
        }
        .bawah a { color: var(--biru); text-decoration: none; font-weight: 600; }
        .bawah a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <main class="kartu" role="main">
        <div class="merek">
            <div class="logo" aria-hidden="true">PH</div>
            <h1>Sistem Pelaporan Kegiatan Harian</h1>
            <p>Pertamina Hulu Rokan</p>
        </div>

        @if ($alasan === 'sesi_habis')
            <div class="peringatan" role="alert">
                Sesi Anda telah berakhir karena terlalu lama tanpa aktivitas
                atau telah melewati batas waktu. Silakan masuk kembali.
            </div>
        @endif

        {{-- Formulir akan dihubungkan ke proses login pada fase modul autentikasi. --}}
        <form method="post" action="{{ route('login') }}" autocomplete="on">
            @csrf
            <label for="email">Surel</label>
            <input type="email" id="email" name="email" required autofocus
                   placeholder="nama@perusahaan.com">

            <label for="password">Kata sandi</label>
            <input type="password" id="password" name="password" required
                   placeholder="Masukkan kata sandi">

            <button type="submit">Masuk</button>
        </form>

        <p class="bawah">
            Lupa kata sandi? <a href="#">Atur ulang</a>
        </p>
    </main>
</body>
</html>
