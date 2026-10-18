# =====================================================================
#  scripts/backup_db.ps1
#  Pencadangan basis data MySQL/MariaDB (XAMPP) + rotasi.
#
#  - Membaca kredensial dari .env Laravel (tidak ada sandi di skrip).
#  - Menjalankan mysqldump dengan --single-transaction (aman saat live).
#  - Menyimpan ke backups/pelaporan-YYYYMMDD-HHmmss.sql
#  - Menghapus cadangan lebih tua dari -HariSimpan hari.
#  - Mencatat ringkasan ke backups/backup.log
#
#  Contoh:
#    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\backup_db.ps1
#    powershell ... -File scripts\backup_db.ps1 -HariSimpan 30 -Tujuan D:\backups
#
#  Pemasangan harian (Windows Task Scheduler, 23:30):
#    schtasks /Create /SC DAILY /ST 23:30 /TN "PHR Backup DB" ^
#      /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\xampp\htdocs\pelaporan\scripts\backup_db.ps1"
# =====================================================================
[CmdletBinding()]
param(
    [int]$HariSimpan = 30,
    [string]$Tujuan = '',
    [string]$Mysqldump = 'C:\xampp\mysql\bin\mysqldump.exe'
)

$ErrorActionPreference = 'Stop'

$akar = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($Tujuan)) {
    $Tujuan = Join-Path $akar 'backups'
}
if (-not (Test-Path $Tujuan)) {
    New-Item -ItemType Directory -Path $Tujuan -Force | Out-Null
}

$berkasEnv = Join-Path $akar '.env'
if (-not (Test-Path $berkasEnv)) { throw "Berkas .env tidak ditemukan: $berkasEnv" }

# --- Baca .env (kunci=nilai; abaikan komentar) -----------------------
$konfig = @{}
foreach ($baris in Get-Content $berkasEnv) {
    if ($baris -match '^\s*#' -or $baris -notmatch '=') { continue }
    $bagian = $baris -split '=', 2
    $kunci = $bagian[0].Trim()
    $nilai = $bagian[1].Trim().Trim('"').Trim("'")
    $konfig[$kunci] = $nilai
}

$hostDb = if ($konfig['DB_HOST']) { $konfig['DB_HOST'] } else { '127.0.0.1' }
$portDb = if ($konfig['DB_PORT']) { $konfig['DB_PORT'] } else { '3306' }
$namaDb = $konfig['DB_DATABASE']
$userDb = $konfig['DB_USERNAME']
$sandiDb = if ($konfig.ContainsKey('DB_PASSWORD')) { $konfig['DB_PASSWORD'] } else { '' }

if ([string]::IsNullOrWhiteSpace($namaDb) -or [string]::IsNullOrWhiteSpace($userDb)) {
    throw 'DB_DATABASE / DB_USERNAME tidak ada di .env.'
}
if (-not (Test-Path $Mysqldump)) { throw "mysqldump tidak ditemukan: $Mysqldump" }

# --- Jalankan mysqldump (sandi lewat MYSQL_PWD, bukan argumen) -------
$cap = Get-Date -Format 'yyyyMMdd-HHmmss'
$berkasSql = Join-Path $Tujuan ("pelaporan-$cap.sql")
$berkasErr = Join-Path $Tujuan '.backup.err'
$berkasLog = Join-Path $Tujuan 'backup.log'

$argumen = @(
    "--host=$hostDb",
    "--port=$portDb",
    "--user=$userDb",
    '--single-transaction',
    '--quick',
    '--default-character-set=utf8mb4',
    '--databases', $namaDb
)

$pwdLama = $env:MYSQL_PWD
$env:MYSQL_PWD = $sandiDb
try {
    $proses = Start-Process -FilePath $Mysqldump -ArgumentList $argumen `
        -NoNewWindow -Wait -PassThru `
        -RedirectStandardOutput $berkasSql -RedirectStandardError $berkasErr
    $kode = $proses.ExitCode
} finally {
    $env:MYSQL_PWD = $pwdLama
}

$ukuran = if (Test-Path $berkasSql) { [math]::Round((Get-Item $berkasSql).Length / 1MB, 2) } else { 0 }
$waktu = Get-Date -Format 'yyyy-MM-dd HH:mm:ss'

if ($kode -eq 0) {
    $pesan = "$waktu  OK     $berkasSql  ($ukuran MB)"
} else {
    $pesan = "$waktu  GAGAL  (kode $kode) -> $berkasSql"
}
Add-Content -Path $berkasLog -Value $pesan
Write-Output $pesan

if ((Test-Path $berkasErr) -and (Get-Item $berkasErr).Length -gt 0) {
    Add-Content -Path $berkasLog -Value ("$waktu  stderr: " + ((Get-Content $berkasErr) -join ' | '))
}
Remove-Item $berkasErr -Force -ErrorAction SilentlyContinue

# --- Rotasi: hapus cadangan lebih tua dari -HariSimpan hari ----------
$batas = (Get-Date).AddDays(-$HariSimpan)
Get-ChildItem -Path $Tujuan -Filter 'pelaporan-*.sql' -File |
    Where-Object { $_.LastWriteTime -lt $batas } |
    ForEach-Object {
        Remove-Item $_.FullName -Force
        Add-Content -Path $berkasLog -Value "$waktu  HAPUS  $($_.FullName)"
    }

if ($kode -ne 0) { exit $kode }
