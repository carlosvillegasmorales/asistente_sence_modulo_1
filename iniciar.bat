@echo off
title Iniciar Moodle Portable
set "ROOT_DIR=%~dp0"

echo ====================================================
echo  Iniciando Entorno Moodle (Windows)
echo ====================================================
echo.

echo [1/3] Iniciando base de datos MariaDB (Puerto 3307)...
start /MIN "Moodle_MariaDB" "%ROOT_DIR%env\mariadb\bin\mysqld.exe" --datadir="%ROOT_DIR%env\data" --port=3307

echo [2/3] Iniciando servidor web PHP (Puerto 8000)...
start /MIN "Moodle_Web" "%ROOT_DIR%env\php83\php.exe" -c "%ROOT_DIR%env\php83\php.ini" -S 127.0.0.1:8000 -t "%ROOT_DIR%moodle\public"

echo [3/3] Esperando a que los servicios inicien...
timeout /t 3 /nobreak > nul

echo.
echo ====================================================
echo  Moodle se esta ejecutando!
echo  URL: http://localhost:8000
echo.
echo  IMPORTANTE: No cierres las 2 ventanas minimizadas
echo  de MariaDB y PHP.
echo.
echo  Para detener el sistema cuando termines,
echo  ejecuta el archivo "detener.bat".
echo ====================================================
echo.
echo Abriendo el navegador...
start http://localhost:8000

echo.
echo Ya puedes cerrar esta ventana (el sistema seguira corriendo en las ventanas minimizadas).
pause
