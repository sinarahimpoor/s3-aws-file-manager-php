@echo off
cd /d %~dp0
echo Starting PHP built-in server at http://localhost:8000
php -S localhost:8000
pause