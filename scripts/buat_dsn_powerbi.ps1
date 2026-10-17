#Requires -Version 5.1
<#
    scripts/buat_dsn_powerbi.ps1
    ---------------------------------------------------------------
    Mendaftarkan User DSN ODBC "PelaporanBI" agar Power BI Desktop
    dapat membaca basis data `pelaporan` lewat driver MySQL ODBC
    (tanpa perlu MySQL Connector/NET).

    Contoh pemakaian (dari akar proyek):

        powershell -ExecutionPolicy Bypass -File scripts\buat_dsn_powerbi.ps1

        # sertakan sandi bi_reader agar Power BI tidak perlu menanyakannya
        powershell -ExecutionPolicy Bypass -File scripts\buat_dsn_powerbi.ps1 `
            -Password 'SANDI_BI_ANDA'

    Catatan:
      * Tanpa -Password, Power BI akan meminta kredensial saat dibuka.
      * DSN bersifat "User" (tidak perlu hak Administrator).
#>
[CmdletBinding()]
param(
    [string]$NamaDsn  = 'PelaporanBI',
    [string]$Driver   = '',
    [string]$Server   = 'localhost',
    [int]   $Port     = 3306,
    [string]$Database = 'pelaporan',
    [string]$User     = 'bi_reader',
    [string]$Password = ''
)

$ErrorActionPreference = 'Stop'

Write-Host '== Daftar User DSN ODBC untuk Power BI ==' -ForegroundColor Cyan

# 1. Pastikan modul Odbc tersedia
if (-not (Get-Command Add-OdbcDsn -ErrorAction SilentlyContinue)) {
    throw "Cmdlet Add-OdbcDsn tidak tersedia. Jalankan pada Windows 10/11 dengan modul 'Odbc'."
}

# 2. Deteksi driver MySQL ODBC
if ([string]::IsNullOrWhiteSpace($Driver)) {
    $kandidat = @(Get-OdbcDriver |
        Where-Object { $_.Name -like '*MySQL ODBC*Unicode*' } |
        Select-Object -ExpandProperty Name)
    if ($kandidat.Count -eq 0) {
        $kandidat = @(Get-OdbcDriver |
            Where-Object { $_.Name -like '*MySQL ODBC*' } |
            Select-Object -ExpandProperty Name)
    }
    if ($kandidat.Count -eq 0) {
        throw 'Driver MySQL ODBC tidak ditemukan. Pasang MySQL ODBC Driver terlebih dahulu.'
    }
    $Driver = $kandidat[0]
}
Write-Host "Driver : $Driver"

# 3. Susun properti DSN
$properti = @(
    "SERVER=$Server",
    "PORT=$Port",
    "DATABASE=$Database",
    "USER=$User",
    "OPTION=3",
    "CHARSET=utf8mb4"
)
$connStr = "Driver={$Driver};Server=$Server;Port=$Port;Database=$Database;User=$User;Option=3;charset=utf8mb4;"
if (-not [string]::IsNullOrWhiteSpace($Password)) {
    $properti += "PASSWORD=$Password"
    $connStr += "Password=$Password;"
}

# 4. Hapus DSN lama bila ada
$lama = Get-OdbcDsn -Name $NamaDsn -DsnType User -ErrorAction SilentlyContinue
if ($lama) {
    Remove-OdbcDsn -Name $NamaDsn -DsnType User | Out-Null
    Write-Host "DSN lama '$NamaDsn' dihapus."
}

# 5. Daftarkan DSN baru
Add-OdbcDsn -Name $NamaDsn -DriverName $Driver -DsnType User -SetPropertyValue $properti | Out-Null
Write-Host "DSN '$NamaDsn' terdaftar (User DSN)." -ForegroundColor Green

# 6. Verifikasi
$dsn = Get-OdbcDsn -Name $NamaDsn -DsnType User -ErrorAction SilentlyContinue
if (-not $dsn) {
    throw 'Verifikasi gagal: DSN tidak ditemukan setelah pendaftaran.'
}
Write-Host "Terdaftar: $($dsn.Name)  [$($dsn.DriverName)]"

Write-Host ''
Write-Host 'Connection string (untuk referensi):'
Write-Host "  $connStr"
Write-Host ''
Write-Host 'Selanjutnya:' -ForegroundColor Yellow
Write-Host "  * Buka powerbi\pelaporan-odbc.pbids, atau" -ForegroundColor Yellow
Write-Host "  * Get Data -> Database -> ODBC -> pilih DSN '$NamaDsn'." -ForegroundColor Yellow
if ([string]::IsNullOrWhiteSpace($Password)) {
    Write-Host "  * Power BI akan meminta sandi: gunakan user '$User'." -ForegroundColor Yellow
}
