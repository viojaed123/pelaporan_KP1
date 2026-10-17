@echo off
REM ==========================================================================
REM Menambahkan  127.0.0.1 pelaporan.local  ke file hosts Windows.
REM
REM SKRIPP INI OPSIONAL dan HARUS dijalankan TANPA memerlukan proses agent:
REM   1. Klik kanan berkas ini
REM   2. Pilih "Run as administrator"
REM   3. Setujui dialog UAC bila muncul
REM
REM Fungsi: setelah entry ini ditambahkan,  http://pelaporan.local/
REM akan melayani aplikasi Laravel (ServerAlias di httpd-vhosts.conf).
REM Tanpa entry ini pun Laravel tetap dapat diakses lewat http://192.168.56.1/
REM (IP host-only yang sudah ada di mesin, tanpa perlu hak admin).
REM
REM Skrip ini IDEMPOTEN: menambahkan entry hanya bila belum ada.
REM ==========================================================================

setlocal
set "TARGET=127.0.0.1 pelaporan.local"
set "HOSTS=%SystemRoot%\System32\drivers\etc\hosts"

echo.
echo ============================================
echo   Menambahkan "%TARGET%" ke file hosts
echo ============================================
echo.

REM --- Pastikan dijalankan sebagai admin ---
net session >nul 2>&1
if %errorlevel% neq 0 (
    echo [GAGAL] Skrip ini memerlukan hak administrator.
    echo         Klik kanan berkas ini -^> "Run as administrator".
    echo.
    pause
    exit /b 1
)

REM --- Cek apakah sudah ada (idempoten) ---
findstr /C:"pelaporan.local" "%HOSTS%" >nul 2>&1
if %errorlevel% equ 0 (
    echo [OK] Entry "pelaporan.local" sudah ada di file hosts. Tidak diubah.
    echo.
    pause
    exit /b 0
)

REM --- Tambahkan dengan komentar penanda agar mudah dilacak/dihapus ---
echo.>> "%HOSTS%"
echo # pelaporan - ditambahkan oleh scripts\tambah_host_pelaporan.cmd>> "%HOSTS%"
echo %TARGET%>> "%HOSTS%"

if %errorlevel% neq 0 (
    echo [GAGAL] Tidak dapat menulis ke "%HOSTS%".
    echo.
    pause
    exit /b 1
)

echo [OK] Entry ditambahkan:
echo       %TARGET%
echo.
echo     Buka  http://pelaporan.local/  untuk mengakses Laravel.
echo     (http://localhost/pelaporan/ tetap memakai aplikasi lama.)
echo.
pause
exit /b 0
