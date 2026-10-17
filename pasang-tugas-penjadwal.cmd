@echo off
REM ==================================================================
REM  pasang-tugas-penjadwal.cmd
REM  Daftarkan tugas otomatis Pelaporan Harian. JALANKAN SEBAGAI ADMINISTRATOR
REM  (klik kanan -> Run as administrator), karena tugas berjalan sebagai
REM  SYSTEM agar tetap jalan walau tidak ada yang login.
REM
REM  Membuat:
REM   1) "Pelaporan Harian - Pengingat Harian"  -> tiap hari 08:00
REM   2) "Pelaporan Harian - Layanan AI"        -> saat Windows menyala
REM
REM  Hapus tugas (jalankan juga sebagai Administrator):
REM   schtasks /Delete /TN "Pelaporan Harian - Pengingat Harian" /F
REM   schtasks /Delete /TN "Pelaporan Harian - Layanan AI" /F
REM ==================================================================
setlocal

net session >nul 2>&1
if errorlevel 1 (
  echo [!] Harus dijalankan sebagai Administrator.
  echo     Klik kanan berkas ini -^> Run as administrator.
  pause
  exit /b 1
)

echo Mendaftarkan pengingat harian ^(08:00, sebagai SYSTEM^)...
schtasks /Create /TN "Pelaporan Harian - Pengingat Harian" ^
  /TR "C:\xampp\php\php.exe C:\xampp\htdocs\pelaporan\scripts\pengingat_harian.php" ^
  /SC DAILY /ST 08:00 /RU SYSTEM /RL HIGHEST /F

echo Mendaftarkan layanan AI ^(saat Windows menyala, sebagai SYSTEM^)...
schtasks /Create /TN "Pelaporan Harian - Layanan AI" ^
  /TR "C:\xampp\htdocs\pelaporan\ai-service\jalankan-ai.cmd" ^
  /SC ONSTART /RU SYSTEM /RL HIGHEST /F

echo.
echo Selesai. Verifikasi:
schtasks /Query /TN "Pelaporan Harian - Pengingat Harian" /FO LIST
schtasks /Query /TN "Pelaporan Harian - Layanan AI" /FO LIST

pause
endlocal
