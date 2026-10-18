@echo off
REM =====================================================================
REM  scripts/backup_db.cmd
REM  Pembungkus untuk Windows Task Scheduler: menjalankan backup_db.ps1.
REM  Argumen diteruskan, mis.: backup_db.cmd -HariSimpan 30 -Tujuan D:\backups
REM =====================================================================
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0backup_db.ps1" %*
exit /b %ERRORLEVEL%
