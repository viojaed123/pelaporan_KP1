[CmdletBinding()]
param(
    [string]$Cloudflared = "$env:LOCALAPPDATA\cloudflared\cloudflared.exe",
    [int]$ProxyPort = 8099,
    [int]$SpaPort = 8088,
    [switch]$Stop
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$logDir = Join-Path $env:TEMP 'pelaporan-cloudflare'
$pidFile = Join-Path $logDir 'pids.json'

New-Item -ItemType Directory -Force -Path $logDir | Out-Null

function Hentikan-Demo {
    if (-not (Test-Path $pidFile)) {
        return
    }

    $lama = Get-Content $pidFile -Raw | ConvertFrom-Json
    foreach ($procId in @($lama.proxy, $lama.spa, $lama.tunnelLegacy, $lama.tunnelSpa)) {
        if ($procId) {
            Stop-Process -Id ([int]$procId) -Force -ErrorAction SilentlyContinue
        }
    }

    Remove-Item $pidFile -Force -ErrorAction SilentlyContinue
}

function Cari-Alamat([string]$berkas) {
    if (-not (Test-Path $berkas)) {
        return $null
    }

    $isi = [string] (Get-Content $berkas -Raw -ErrorAction SilentlyContinue)
    $cocok = [regex]::Match($isi, 'https://[a-z0-9-]+\.trycloudflare\.com')

    if ($cocok.Success) {
        return $cocok.Value
    }

    return $null
}

if ($Stop) {
    Hentikan-Demo
    Write-Host 'Demo Cloudflare dihentikan.'
    exit 0
}

if (-not (Test-Path $Cloudflared)) {
    throw "cloudflared tidak ditemukan di $Cloudflared. Unduh dari https://github.com/cloudflare/cloudflared/releases"
}

Hentikan-Demo
Get-ChildItem $logDir -Filter '*.log' -ErrorAction SilentlyContinue | Remove-Item -Force -ErrorAction SilentlyContinue

$proxy = Start-Process -FilePath 'node' `
    -ArgumentList @((Join-Path $PSScriptRoot 'proxy_demo.mjs')) `
    -WorkingDirectory $root -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logDir 'proxy.out.log') `
    -RedirectStandardError (Join-Path $logDir 'proxy.err.log') -PassThru

$spa = Start-Process -FilePath 'php' `
    -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$SpaPort") `
    -WorkingDirectory $root -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logDir 'spa.out.log') `
    -RedirectStandardError (Join-Path $logDir 'spa.err.log') -PassThru

$tunnelLegacy = Start-Process -FilePath $Cloudflared `
    -ArgumentList @('tunnel', '--url', "http://127.0.0.1:$ProxyPort", '--no-autoupdate') `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logDir 'legacy.out.log') `
    -RedirectStandardError (Join-Path $logDir 'legacy.err.log') -PassThru

$tunnelSpa = Start-Process -FilePath $Cloudflared `
    -ArgumentList @('tunnel', '--url', "http://127.0.0.1:$SpaPort", '--no-autoupdate') `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logDir 'spa-tunnel.out.log') `
    -RedirectStandardError (Join-Path $logDir 'spa-tunnel.err.log') -PassThru

@{
    proxy = $proxy.Id
    spa = $spa.Id
    tunnelLegacy = $tunnelLegacy.Id
    tunnelSpa = $tunnelSpa.Id
} | ConvertTo-Json | Set-Content -Path $pidFile -Encoding utf8

$alamatLegacy = $null
$alamatSpa = $null

for ($i = 0; $i -lt 80; $i++) {
    if (-not $alamatLegacy) {
        $alamatLegacy = Cari-Alamat (Join-Path $logDir 'legacy.err.log')
    }
    if (-not $alamatSpa) {
        $alamatSpa = Cari-Alamat (Join-Path $logDir 'spa-tunnel.err.log')
    }

    if ($alamatLegacy -and $alamatSpa) {
        break
    }

    Start-Sleep -Milliseconds 750
}

if (-not $alamatLegacy -or -not $alamatSpa) {
    Write-Host 'URL Cloudflare belum muncul. Log terakhir:'
    Get-Content (Join-Path $logDir 'legacy.err.log') -Tail 12 -ErrorAction SilentlyContinue
    Get-Content (Join-Path $logDir 'spa-tunnel.err.log') -Tail 12 -ErrorAction SilentlyContinue
    throw 'Gagal membuat quick tunnel.'
}

$teks = @"
Aplikasi utama : $alamatLegacy
SPA / Laravel  : $alamatSpa/app

Hentikan dengan: powershell -ExecutionPolicy Bypass -File "$PSCommandPath" -Stop
Log & PID      : $logDir
"@

$teks | Write-Host
$teks | Set-Content -Path (Join-Path $logDir 'alamat.txt') -Encoding utf8
