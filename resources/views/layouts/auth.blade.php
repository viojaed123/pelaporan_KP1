{{--
    Tata letak halaman otentikasi (masuk & verifikasi dua faktor).

    Porting tampilan layar-auth sistem lama (login.php, dua_faktor.php).
    Dua panel: kiri identitas korporat, kanan formulir.

    Warna diambil dari config/keamanan.php -- satu sumber kebenaran, sama
    seperti yang dipakai surel dan halaman error. Bila warna korporat
    berubah, seluruh antarmuka ikut berubah tanpa menyentuh berkas ini.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('judul') — {{ config('keamanan.aplikasi_singkat') }}</title>
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
            font-family: system-ui, -apple-system, "Segoe UI", Roboto,
                         "Helvetica Neue", Arial, sans-serif;
            background: var(--bg);
            color: #1e293b;
        }

        .layar-auth { display: flex; min-height: 100vh; }

        /* ---------------- Panel kiri: identitas ---------------- */
        .panel-kiri-auth {
            flex: 1 1 46%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 40px;
            padding: 48px;
            color: #fff;
            background: linear-gradient(158deg, var(--biru-tua) 0%, var(--biru) 100%);
        }

        .merek-auth { display: flex; align-items: center; gap: 14px; }
        .merek-auth .logo {
            width: 52px; height: 52px; border-radius: 14px;
            background: rgba(255, 255, 255, 0.16);
            display: flex; align-items: center; justify-content: center;
            font-weight: 800; font-size: 20px; letter-spacing: .5px;
        }
        .merek-auth .nama { font-size: 17px; font-weight: 700; }
        .merek-auth .sub  { font-size: 12.5px; opacity: .78; }

        .judul-auth { font-size: 26px; line-height: 1.3; margin: 0 0 14px; }
        .teks-auth  { font-size: 14.5px; line-height: 1.7; opacity: .88; margin: 0 0 22px; }

        .daftar-fitur { list-style: none; padding: 0; margin: 0; display: grid; gap: 12px; }
        .daftar-fitur li { display: flex; gap: 10px; font-size: 14px; opacity: .92; }
        .daftar-fitur .cek {
            flex: 0 0 20px; height: 20px; border-radius: 50%;
            background: var(--hijau); color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: 700;
        }

        .foot-auth { font-size: 12.5px; opacity: .72; }

        /* ---------------- Panel kanan: formulir ---------------- */
        .panel-kanan-auth {
            flex: 1 1 54%;
            display: flex; align-items: center; justify-content: center;
            padding: 40px 24px;
        }

        .kartu-auth { width: 100%; max-width: 400px; }

        .kartu-auth h1 { font-size: 24px; margin: 0 0 6px; color: var(--biru-tua); }
        .kartu-auth .pengantar { font-size: 14px; color: #64748b; margin: 0 0 24px; }

        .alert {
            display: flex; gap: 10px; align-items: flex-start;
            border-radius: 10px; padding: 12px 14px; font-size: 13.5px;
            line-height: 1.55; margin-bottom: 18px; border: 1px solid transparent;
        }
        .alert .ikon { font-weight: 700; line-height: 1.4; }
        .alert-gagal { background: #fef2f2; border-color: rgba(235, 28, 36, .30); color: #991b1b; }
        .alert-info  { background: #eff6ff; border-color: rgba(0, 94, 184, .30); color: #1e40af; }
        .alert-sukses{ background: #ecfdf5; border-color: rgba(21, 148, 71, .30); color: #065f46; }

        .grup-form { margin-bottom: 18px; }
        .grup-form label {
            display: block; font-size: 13px; font-weight: 600;
            color: #334155; margin-bottom: 6px;
        }

        .field-input { position: relative; display: flex; }
        input[type="email"], input[type="password"], input[type="text"] {
            width: 100%; padding: 12px 14px;
            border: 1px solid #cbd5e1; border-radius: 9px;
            font-size: 15px; transition: border-color .15s, box-shadow .15s;
        }
        input:focus {
            outline: none; border-color: var(--biru);
            box-shadow: 0 0 0 3px rgba(0, 94, 184, .15);
        }
        input.input-galat { border-color: var(--merah); }

        .bantuan { display: block; font-size: 12px; color: #94a3b8; margin-top: 6px; }

        .tombol {
            width: 100%; padding: 13px; border: 0; border-radius: 9px;
            font-size: 15px; font-weight: 700; cursor: pointer;
            transition: background .15s, box-shadow .15s, opacity .15s;
        }
        .tombol-utama { background: var(--biru); color: #fff; }
        .tombol-utama:hover { background: var(--biru-tua); box-shadow: 0 6px 18px rgba(0, 94, 184, .30); }
        .tombol-sekunder {
            background: #fff; color: var(--biru);
            border: 1px solid #cbd5e1;
        }
        .tombol-sekunder:hover { border-color: var(--biru); }

        .tautan-bawah { font-size: 13px; color: #64748b; margin: 16px 0 0; }
        .tautan-bawah a { color: var(--biru); text-decoration: none; font-weight: 600; }
        .tautan-bawah a:hover { text-decoration: underline; }

        .kode-masuk {
            font-family: Consolas, Menlo, monospace;
            font-size: 26px; letter-spacing: 10px; text-align: center;
            padding-left: 10px; font-weight: 700;
        }

        /* Pada layar sempit, panel identitas disembunyikan agar formulir
           tetap lapang dan tidak perlu menggulir. */
        @media (max-width: 860px) {
            .layar-auth { flex-direction: column; }
            .panel-kiri-auth { flex: 0 0 auto; padding: 28px 24px; gap: 20px; }
            .judul-auth { font-size: 20px; }
            .daftar-fitur, .teks-auth { display: none; }
        }
    </style>
</head>
<body>
    <div class="layar-auth">
        {{-- Panel kiri: identitas --}}
        <aside class="panel-kiri-auth">
            <div class="merek-auth">
                <div class="logo" aria-hidden="true">PH</div>
                <span>
                    <span class="nama">{{ config('keamanan.aplikasi_singkat') }}</span><br>
                    <span class="sub">{{ config('keamanan.organisasi') }}</span>
                </span>
            </div>

            <div>
                @section('kiri')
                    <h2 class="judul-auth">Catat, periksa, dan laporkan kegiatan harian dalam satu tempat.</h2>
                    <p class="teks-auth">
                        Sistem pelaporan kegiatan terintegrasi untuk karyawan, mentor,
                        dan pengelola — lengkap dengan absensi, pelacakan kasus, serta
                        asisten kecerdasan buatan.
                    </p>
                    <ul class="daftar-fitur">
                        <li><span class="cek">✓</span> Laporan harian dengan bukti foto &amp; verifikasi mentor</li>
                        <li><span class="cek">✓</span> Absensi dan rekap otomatis siap ekspor</li>
                        <li><span class="cek">✓</span> Verifikasi dua faktor lewat kode di surel</li>
                        <li><span class="cek">✓</span> Asisten AI untuk narasi, statistik, dan pemeriksaan kelengkapan</li>
                    </ul>
                @show
            </div>

            <div class="foot-auth">
                &copy; {{ date('Y') }} {{ config('keamanan.organisasi') }}. Seluruh data terlindungi.
            </div>
        </aside>

        {{-- Panel kanan: formulir --}}
        <section class="panel-kanan-auth">
            <main class="kartu-auth">
                @yield('isi')
            </main>
        </section>
    </div>
</body>
</html>
