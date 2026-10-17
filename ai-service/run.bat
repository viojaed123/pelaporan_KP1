@echo off
REM ============================================================
REM  run.bat - Menjalankan layanan AI (FastAPI) Pelaporan Harian.
REM
REM  Pemakaian:
REM    run.bat                 -> jalankan server (127.0.0.1:8000)
REM    run.bat worker          -> jalankan pekerja ai_jobs (gelung)
REM    run.bat worker sek      -> proses semua job sekali lalu keluar
REM ============================================================
setlocal enabledelayedexpansion
cd /d "%~dp0"

set "VENV_PY=%~dp0.venv\Scripts\python.exe"

if not exist "%VENV_PY%" (
    echo [setup] Membuat lingkungan virtual .venv ...
    where py >nul 2>nul && (set "BASE_PY=py") || (set "BASE_PY=python")
    !BASE_PY! -m venv .venv
    set "VENV_PY=%~dp0.venv\Scripts\python.exe"
)

echo [setup] Memastikan dependensi terpasang ...
"%VENV_PY%" -m pip install --disable-pip-version-check -q -r requirements.txt

if not exist "%~dp0.env" (
    echo [setup] Membuat .env dari .env.example ...
    copy /y ".env.example" ".env" >nul
)

if /i "%~1"=="worker" (
    if /i "%~2"=="sek" (
        echo [run] Menjalankan pekerja ai_jobs sekali ...
        "%VENV_PY%" -m app.jobs.worker --sekali
    ) else (
        echo [run] Menjalankan pekerja ai_jobs (gelung) ...
        "%VENV_PY%" -m app.jobs.worker --gelung
    )
    exit /b %ERRORLEVEL%
)

echo [run] Layanan AI di http://127.0.0.1:8000  (Ctrl+C untuk berhenti)
"%VENV_PY%" -m uvicorn app.main:app --host 127.0.0.1 --port 8000

endlocal
