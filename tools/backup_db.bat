@echo off
REM tools\backup_db.bat — pembungkus untuk penjadwal Windows (Task Scheduler).
REM Contoh:  php.exe tools\backup_db.php --simpan=30
setlocal
set "PHP=%~dp0..\..\..\php\php.exe"
if not exist "%PHP%" set "PHP=php"
"%PHP%" "%~dp0backup_db.php" %*
endlocal
