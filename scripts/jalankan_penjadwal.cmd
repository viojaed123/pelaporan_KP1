@echo off
REM =====================================================================
REM  scripts/jalankan_penjadwal.cmd
REM  Dipanggil Windows Task Scheduler TIAP MENIT untuk menjalankan
REM  penjadwal Laravel (pengingat harian, dll.).
REM
REM  Pemasangan:
REM    schtasks /Create /SC MINUTE /MO 1 /TN "PHR Penjadwal" ^
REM      /TR "C:\xampp\htdocs\pelaporan\scripts\jalankan_penjadwal.cmd"
REM =====================================================================
pushd "%~dp0.."
"C:\xampp\php\php.exe" artisan schedule:run >> "storage\logs\penjadwal.log" 2>&1
popd
exit /b %ERRORLEVEL%
