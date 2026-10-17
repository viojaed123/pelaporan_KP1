{{-- Halaman 403 Akses Ditolak --}}
{{--
    Meniru pesan dan kode status yang sama dengan sistem lama:
        http_response_code(403);
        exit('Anda tidak memiliki akses ke halaman ini.');

    Halaman ini berdiri sendiri (tidak memperluas tata letak utama) agar
    tetap tampil meski pengguna belum terautentikasi dan tata letak
    membutuhkan data sesi. Warna memakai konfigurasi korporat PHR.
--}}

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>403 — Akses Ditolak</title>
    <style>
        :root {
            --biru-tua: {{ config('keamanan.warna_biru_tua', '#003D7D') }};
            --biru:     {{ config('keamanan.warna_biru', '#005EB8') }};
            --merah:    {{ config('keamanan.warna_merah', '#EB1C24') }};
            --bg:       {{ config('keamanan.warna_bg', '#F5F5F5') }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            color: #1e293b;
            padding: 24px;
        }

        .kartu {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0, 61, 125, 0.10);
            max-width: 460px;
            width: 100%;
            padding: 40px 36px;
            text-align: center;
            border-top: 6px solid var(--merah);
        }

        .kode {
            font-size: 68px;
            font-weight: 800;
            line-height: 1;
            margin: 0 0 8px;
            color: var(--merah);
            letter-spacing: -2px;
        }

        .ikon {
            width: 64px;
            height: 64px;
            margin: 0 auto 18px;
            border-radius: 50%;
            background: rgba(235, 28, 36, 0.10);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .ikon svg { width: 30px; height: 30px; stroke: var(--merah); }

        h1 {
            font-size: 21px;
            margin: 0 0 10px;
            color: var(--biru-tua);
            font-weight: 700;
        }

        p.pesan {
            margin: 0 0 24px;
            color: #475569;
            font-size: 15px;
            line-height: 1.6;
        }

        .aksi { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }

        a.tombol {
            display: inline-block;
            padding: 11px 22px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: background .15s ease, box-shadow .15s ease;
        }

        a.utama { background: var(--biru); color: #fff; }
        a.utama:hover { background: var(--biru-tua); box-shadow: 0 4px 14px rgba(0, 94, 184, .30); }

        a.kedua { background: #fff; color: var(--biru); border: 1px solid #cbd5e1; }
        a.kedua:hover { background: #f1f5f9; }

        .garis {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <main class="kartu" role="main" aria-labelledby="judul-403">
        <div class="ikon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
            </svg>
        </div>

        <p class="kode">403</p>
        <h1 id="judul-403">Akses Ditolak</h1>

        <p class="pesan">{{ $pesan ?? 'Anda tidak memiliki akses ke halaman ini.' }}</p>

        <div class="aksi">
            <a class="tombol utama" href="{{ route('login') }}">Masuk kembali</a>
            <a class="tombol kedua" href="javascript:history.back()">Kembali</a>
        </div>

        <p class="garis">
            {{ config('keamanan.warna_biru') ? 'Sistem Pelaporan Kegiatan Harian' : 'Sistem Pelaporan Kegiatan Harian' }}
            — {{ config('app.name') }}
        </p>
    </main>
</body>
</html>
