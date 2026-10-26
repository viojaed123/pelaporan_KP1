# Demo Cepat via Cloudflare Tunnel

Panduan ini membuka aplikasi di internet **tanpa VPS atau domain** memakai
Cloudflare Quick Tunnel. Cocok untuk demo sementara, bukan produksi.

## Prasyarat

- XAMPP (Apache + MySQL) berjalan dan aplikasi terbuka di
  `http://localhost/pelaporan/`.
- `php` dan `node` tersedia di `PATH`.
- `cloudflared` terpasang di
  `%LOCALAPPDATA%\cloudflared\cloudflared.exe`. Jika belum:

```powershell
New-Item -ItemType Directory -Force "$env:LOCALAPPDATA\cloudflared" | Out-Null
curl.exe -L --fail -o "$env:LOCALAPPDATA\cloudflared\cloudflared.exe" `
  "https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe"
```

## Jalankan

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\demo_cloudflare.ps1
```

Skrip akan menyalakan empat proses dan mencetak dua alamat:

- **Aplikasi utama** (legacy) di alamat `trycloudflare.com`.
- **SPA / Laravel** di `<alamat>/app`.

Alamat juga disimpan di `%TEMP%\pelaporan-cloudflare\alamat.txt`.

## Hentikan

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\demo_cloudflare.ps1 -Stop
```

## Cara kerjanya

| Komponen | Port | Fungsi |
|---|---|---|
| `scripts/proxy_demo.mjs` | 8099 | Meneruskan hanya `/pelaporan` ke Apache lokal. |
| `php artisan serve` | 8088 | Melayani SPA/Laravel dan aset Vite. |
| `cloudflared` | - | Dua Quick Tunnel: aplikasi utama dan SPA. |

Proksi Node sengaja dipakai agar tunnel tidak membuka seluruh
`C:\xampp\htdocs`. Aturan `.htaccess` Apache tetap berlaku, sehingga
`includes/`, `.env`, dan berkas sensitif lain tetap `403`.

## Batasan

- Alamat `trycloudflare.com` **berubah setiap kali** skrip dijalankan.
- Komputer harus tetap menyala; tidak ada jaminan uptime.
- Login terbuka untuk siapa pun yang mengetahui alamatnya. Gunakan akun demo
  dengan kata sandi kuat, jangan akun admin produksi.
- Tanpa domain/akun Cloudflare, Cloudflare Access tidak bisa dipakai.
- Untuk permanen, gunakan **named tunnel** + domain sendiri, lalu arahkan:

| Hostname | Origin |
|---|---|
| `pelaporan.domain.id` | `http://127.0.0.1:8099` (atau Apache langsung) |
| `app.pelaporan.domain.id` | `http://127.0.0.1:8088` |

## Jika bermasalah

- Cek log di `%TEMP%\pelaporan-cloudflare\`.
- Hentikan proses lama lebih dulu dengan `-Stop` bila port 8088/8099 terpakai.
- Di Windows, pengujian dengan `curl` kadang menggantung lewat IPv6/HTTP2.
  Uji dengan `curl.exe -4 --http1.1` sebelum menyimpulkan tunnel mati.
- Pastikan `APP_DEBUG=false` bila demo dibuka ke publik.
