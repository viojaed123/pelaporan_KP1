@echo off
REM ------------------------------------------------------------------
REM  Pelaporan Harian - jalankan layanan AI (FastAPI + Ollama) di latar.
REM  Aman dijalankan berulang: bila port 8000 sudah dipakai, tidak
REM  menggandakan proses.
REM
REM  Pemakaian:  double-click, atau salin ke folder Startup agar
REM  otomatis saat login (tanpa hak administrator).
REM ------------------------------------------------------------------
cd /d "%~dp0"

if not exist ".venv\Scripts\python.exe" (
  echo [!] Lingkungan .venv belum ada. Jalankan dulu: run.ps1
  pause
  exit /b 1
)

REM Sudah berjalan di port 8000?
powershell -NoProfile -Command "try{ (New-Object Net.Sockets.TcpClient('127.0.0.1',8000)).Close(); exit 0 }catch{ exit 1 }"
if errorlevel 1 goto mulai
echo Layanan AI sudah berjalan di http://127.0.0.1:8000
exit /b 0

:mulai
start "Pelaporan Harian - Layanan AI" ".venv\Scripts\python.exe" -m uvicorn app.main:app --host 127.0.0.1 --port 8000
echo Layanan AI dijalankan. Cek: http://127.0.0.1:8000/health
