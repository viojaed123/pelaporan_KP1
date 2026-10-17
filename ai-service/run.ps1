<#
.SYNOPSIS
    Menjalankan layanan AI (FastAPI) Pelaporan Harian.

.DESCRIPTION
    Skrip ini menyiapkan lingkungan virtual (.venv), memasang dependensi bila
    perlu, menyiapkan berkas .env, lalu menjalankan uvicorn.

.PARAMETER Worker
    Jalankan pekerja antrean ai_jobs (bukan server web).

.PARAMETER Sekali
    (Hanya untuk -Worker) Proses seluruh job yang mengantre lalu keluar.

.PARAMETER Host_ / Port
    Alamat & port bind uvicorn. Bawaan dari .env (127.0.0.1:8000).

.EXAMPLE
    .\run.ps1
    .\run.ps1 -Port 8080
    .\run.ps1 -Worker
    .\run.ps1 -Worker -Sekali
#>
[CmdletBinding()]
param(
    [switch]$Worker,
    [switch]$Sekali,
    [string]$Host_,
    [int]$Port = 0
)

$ErrorActionPreference = "Continue"
Set-Location -Path $PSScriptRoot

$venvPython = Join-Path $PSScriptRoot ".venv\Scripts\python.exe"

if (-not (Test-Path $venvPython)) {
    Write-Host "[setup] Membuat lingkungan virtual .venv ..." -ForegroundColor Cyan
    $basePython = if (Get-Command py -ErrorAction SilentlyContinue) { "py" } else { "python" }
    & $basePython -m venv .venv
    $venvPython = Join-Path $PSScriptRoot ".venv\Scripts\python.exe"
}

Write-Host "[setup] Memastikan dependensi terpasang ..." -ForegroundColor Cyan
& $venvPython -m pip install --disable-pip-version-check -q -r requirements.txt

if (-not (Test-Path (Join-Path $PSScriptRoot ".env"))) {
    Write-Host "[setup] Membuat .env dari .env.example ..." -ForegroundColor Yellow
    Copy-Item -Path ".env.example" -Destination ".env"
}

if ($Worker) {
    $argList = @("-m", "app.jobs.worker")
    if ($Sekali) { $argList += "--sekali" } else { $argList += "--gelung" }
    Write-Host "[run] Menjalankan pekerja ai_jobs ..." -ForegroundColor Green
    & $venvPython @argList
    exit $LASTEXITCODE
}

# Ambil host/port dari .env bila tidak diberikan.
if (-not $Host_) {
    $envHost = (Select-String -Path ".env" -Pattern '^\s*AI_HOST\s*=\s*(.+)$' -ErrorAction SilentlyContinue |
        Select-Object -First 1).Matches.Groups[1].Value
    $Host_ = if ($envHost) { $envHost.Trim() } else { "127.0.0.1" }
}
if ($Port -le 0) {
    $envPort = (Select-String -Path ".env" -Pattern '^\s*AI_PORT\s*=\s*(\d+)$' -ErrorAction SilentlyContinue |
        Select-Object -First 1).Matches.Groups[1].Value
    $Port = if ($envPort) { [int]$envPort } else { 8000 }
}

Write-Host "[run] Layanan AI di http://$Host_`:$Port  (Ctrl+C untuk berhenti)" -ForegroundColor Green
& $venvPython -m uvicorn app.main:app --host $Host_ --port $Port
