{{--
    Tata letak halaman terautentikasi.

    Porting includes/layout/ sistem lama: kepala (merek + pengguna), menu
    navigasi, dan wadah isi. Dipakai oleh dashboard dan seluruh modul yang
    dipindahkan setelahnya, sehingga identitas visual konsisten.

    Warna diambil dari config/keamanan.php (satu sumber kebenaran).
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('judul', 'Beranda') — {{ config('keamanan.aplikasi_singkat') }}</title>
    @stack('kepala')
    <style>
        :root {
            --biru-tua: {{ config('keamanan.warna_biru_tua') }};
            --biru:     {{ config('keamanan.warna_biru') }};
            --merah:    {{ config('keamanan.warna_merah') }};
            --hijau:    {{ config('keamanan.warna_hijau') }};
            --bg:       {{ config('keamanan.warna_bg') }};
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: #1e293b;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
        }

        /* ---------------- Bilah atas ---------------- */
        .bilah-atas {
            background: var(--biru-tua);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 0 24px;
            min-height: 60px;
        }

        .bilah-merek { display: flex; align-items: center; gap: 12px; }
        .bilah-merek .logo {
            width: 36px; height: 36px; border-radius: 10px;
            background: rgba(255, 255, 255, .16);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 15px;
        }
        .bilah-merek .nama { font-size: 15px; font-weight: 700; }
        .bilah-merek .sub  { font-size: 11.5px; opacity: .75; }

        .bilah-pengguna { display: flex; align-items: center; gap: 16px; }
        .bilah-pengguna .identitas { text-align: right; line-height: 1.25; }
        .bilah-pengguna .identitas .nama { font-size: 13.5px; font-weight: 600; }
        .bilah-pengguna .identitas .peran {
            font-size: 11px; opacity: .75; text-transform: uppercase;
            letter-spacing: .04em;
        }

        .lencana {
            display: inline-block; padding: 2px 8px; border-radius: 999px;
            font-size: 11px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .03em;
        }
        .lencana-admin    { background: rgba(235, 28, 36, .18); color: #ffd7d9; }
        .lencana-mentor   { background: rgba(21, 148, 71, .20); color: #ccf5dd; }
        .lencana-karyawan { background: rgba(255, 255, 255, .18); color: #e2e8f0; }

        .tombol-keluar {
            background: rgba(255, 255, 255, .12);
            color: #fff; border: 1px solid rgba(255, 255, 255, .22);
            border-radius: 8px; padding: 8px 14px; font-size: 13px;
            font-weight: 600; cursor: pointer;
        }
        .tombol-keluar:hover { background: rgba(235, 28, 36, .85); border-color: transparent; }

        /* ---------------- Wadah isi ---------------- */
        .wadah { max-width: 1080px; margin: 0 auto; padding: 28px 24px 64px; }

        .lembar {
            background: #fff; border: 1px solid #e2e8f0; border-radius: 14px;
            padding: 24px; margin-bottom: 20px;
            box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
        }
        .lembar h1 { font-size: 22px; margin: 0 0 8px; color: var(--biru-tua); }
        .lembar h2 { font-size: 16px; margin: 0 0 12px; color: var(--biru-tua); }
        .lembar p  { margin: 0 0 10px; line-height: 1.65; }

        .kisi-info {
            display: grid; gap: 14px;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
        }
        .kartu-info {
            background: #f8fafc; border: 1px solid #e2e8f0;
            border-radius: 10px; padding: 14px 16px;
        }
        .kartu-info .label {
            font-size: 11.5px; text-transform: uppercase; letter-spacing: .04em;
            color: #64748b; font-weight: 700;
        }
        .kartu-info .nilai { font-size: 17px; font-weight: 700; margin-top: 4px; color: #0f172a; }
        .kartu-info .catatan { font-size: 12px; color: #94a3b8; margin-top: 2px; }

        .catatan-kaki { font-size: 13px; color: #64748b; }
    </style>
</head>
<body>
    <header class="bilah-atas">
        <div class="bilah-merek">
            <div class="logo" aria-hidden="true">PH</div>
            <div>
                <div class="nama">{{ config('keamanan.aplikasi_singkat') }}</div>
                <div class="sub">{{ config('keamanan.organisasi') }}</div>
            </div>
        </div>

        <div class="bilah-pengguna">
            <div class="identitas">
                <div class="nama">{{ $pengguna->nama ?? 'Pengguna' }}</div>
                <div class="peran">
                    <span class="lencana lencana-{{ $pengguna->role ?? 'karyawan' }}">
                        {{ $pengguna->role ?? 'karyawan' }}
                    </span>
                </div>
            </div>

            <form method="POST" action="{{ route('keluar') }}">
                @csrf
                <button class="tombol-keluar" type="submit">Keluar</button>
            </form>
        </div>
    </header>

    <main class="wadah">
        @if (session('info'))
            <div class="lembar" style="border-color: rgba(0,94,184,.4); background:#eff6ff; color:#1e40af;">
                {{ session('info') }}
            </div>
        @endif

        @yield('isi')
    </main>
</body>
</html>
