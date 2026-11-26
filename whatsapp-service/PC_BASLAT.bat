@echo off
chcp 65001 >nul
title WhatsApp Yukleme Plani — PC Baglanti Baslatici
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0pc_kur_ve_baslat.ps1"
if errorlevel 1 (
    echo.
    echo [HATA] Kurulum/baslatma sirasinda bir sorun olustu. Yukaridaki mesaji kontrol edin.
    pause
)
