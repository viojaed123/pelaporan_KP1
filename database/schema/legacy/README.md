# Database `pelaporan`

## Urutan migrasi

| File | Isi | Kapan dijalankan |
|---|---|---|
| `001_skema_awal.sql` | Rekonstruksi 9 tabel asli | Hanya untuk instalasi **baru** (DB lama dilewati) |
| `002_keamanan_login.sql` | `login_attempts`, `login_locks`, `user_security`, `user_otp`, `backup_codes`, `password_history`, `sesi_aktif` | Selalu |
| `003_audit_log.sql` | `audit_log`, `laporan_terkirim`, `pengingat` | Selalu |
| `004_ai_service.sql` | `chat_sessions`, `chat_messages`, `ai_jobs`, `backup_riwayat` | Selalu |
| `010_user_db.sql` | Akun `pelaporan_app` + `bi_reader` | Selalu (ubah sandi dulu!) |
| `026_cost_avoidance.sql` | Tabel `cost_avoidance` (modul Cost Avoidance) | Setelah 001–004 |
| `../../powerbi/sql/02_views.sql` | VIEW untuk Power BI | Setelah 001–004 |

## Cara menjalankan

```powershell
# Migrasi baru (aman dijalankan berulang / idempoten)
& 'C:\xampp\mysql\bin\mysql.exe' -uroot pelaporan < 'database\migrations\002_keamanan_login.sql'
& 'C:\xampp\mysql\bin\mysql.exe' -uroot pelaporan < 'database\migrations\003_audit_log.sql'
& 'C:\xampp\mysql\bin\mysql.exe' -uroot pelaporan < 'database\migrations\004_ai_service.sql'
& 'C:\xampp\mysql\bin\mysql.exe' -uroot        < 'database\migrations\010_user_db.sql'
```

Atau gunakan skrip satu pintu:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\jalankan_migrasi.ps1
```

## Backup skema

```powershell
& 'C:\xampp\mysql\bin\mysqldump.exe' -uroot --no-data pelaporan | Out-File -Encoding utf8 database\skema_terkini.sql
```
