@echo off
title Detener Moodle Portable

echo ====================================================
echo  Deteniendo Entorno Moodle (Windows)
echo ====================================================
echo.

echo Deteniendo MariaDB (mysqld.exe)...
taskkill /F /IM mysqld.exe /T

echo.
echo Deteniendo Servidor Web PHP (php.exe)...
taskkill /F /IM php.exe /T

echo.
echo ====================================================
echo  El sistema se ha detenido correctamente.
echo ====================================================
echo.
pause
