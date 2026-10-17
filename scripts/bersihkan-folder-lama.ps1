# =============================================================================
# scripts/bersihkan-folder-lama.ps1
#
# Tujuan
#   Menghapus sisa folder lama "pelaporan-kp" (sisa proses ganti nama menjadi
#   "pelaporan"). Folder itu masih terkunci karena dipakai sebagai direktori
#   kerja oleh proses yang masih berjalan (VS Code, terminal, OpenCode, dsb.).
#
# Cara pakai
#   Skrip ini dipasang otomatis sebagai tugas "Pelaporan Harian - Hapus Folder
#   Lama" (dijalankan saat logon dan diulang setiap 10 menit sampai berhasil).
#   Setelah folder lama terhapus, tugas menghapus dirinya sendiri.
#
#   Menjalankan manual:
#     powershell -NoProfile -ExecutionPolicy Bypass -File "scripts\bersihkan-folder-lama.ps1"
# =============================================================================

$ErrorActionPreference = 'SilentlyContinue'

$lama = 'C:\xampp\htdocs\pelaporan-kp'
$nama = 'Pelaporan Harian - Hapus Folder Lama'

function Hapus-Tugas {
    schtasks.exe /Delete /TN $nama /F 2>$null | Out-Null
}

if (-not (Test-Path -LiteralPath $lama)) {
    Hapus-Tugas
    Write-Host 'Folder lama sudah tidak ada.'
    exit 0
}

for ($i = 1; $i -le 10; $i++) {
    if (-not (Test-Path -LiteralPath $lama)) { break }
    try {
        Remove-Item -LiteralPath $lama -Recurse -Force -ErrorAction Stop
    } catch {
        Start-Sleep -Seconds 3
    }
}

if (Test-Path -LiteralPath $lama) {
    Write-Host 'Folder lama masih terkunci oleh proses lain (mis. VS Code / OpenCode).'
    exit 1
}

Hapus-Tugas
Write-Host 'Folder lama berhasil dihapus.'
exit 0
