# =============================================================================
# whatsapp-service/pc_kur_ve_baslat.ps1 — Tek tık Windows PC kurulum & başlatıcı
# Yaptıkları:
#   1. Docker Desktop ve cloudflared kurulu değilse winget ile kurar
#   2. .env yoksa güçlü rastgele anahtarlarla otomatik oluşturur (varsa korur)
#   3. docker compose up -d ile Evolution API + Postgres + Redis başlatır
#   4. Cloudflare Tunnel açar (port açmadan, IP gizli)
#   5. Tek satırlık WA-KURULUM kodunu panoya kopyalar ve yönetim sayfasını açar
# =============================================================================
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

$SiteBase = 'https://asya.scai.tr'
$PanelUrl = "$SiteBase/whatsapp_yonetim.php"
$Dir      = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Dir

function Write-Step([string]$msg) {
    Write-Host "`n==> $msg" -ForegroundColor Cyan
}

function Refresh-Path {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
}

function New-HexSecret([int]$bytes = 24) {
    $buf = New-Object byte[] $bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([BitConverter]::ToString($buf) -replace '-', '').ToLowerInvariant()
}

Write-Host '============================================================' -ForegroundColor Green
Write-Host '  WhatsApp Senkron - Otomatik PC Kurulum & Baslatici  ' -ForegroundColor Green
Write-Host '============================================================' -ForegroundColor Green

# 1) Docker Desktop kontrol / kurulum
Write-Step '1/5 Docker kontrol ediliyor...'
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Host 'Docker Desktop bulunamadi; winget ile kuruluyor (birkac dakika surebilir)...' -ForegroundColor Yellow
    winget install -e --id Docker.DockerDesktop --accept-package-agreements --accept-source-agreements
    Refresh-Path
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Write-Host 'Docker Desktop kuruldu. Bilgisayari yeniden baslatip PC_BASLAT.bat dosyasina tekrar tiklayin.' -ForegroundColor Yellow
        Read-Host 'Kapatmak icin Enter tusuna basin'
        exit 0
    }
}

$dockerReady = $false
try { docker info *> $null; if ($LASTEXITCODE -eq 0) { $dockerReady = $true } } catch {}
if (-not $dockerReady) {
    $dd = @(
        "$env:ProgramFiles\Docker\Docker\Docker Desktop.exe",
        "$env:LocalAppData\Docker\Docker Desktop.exe"
    ) | Where-Object { Test-Path $_ } | Select-Object -First 1
    if ($dd) {
        Write-Host 'Docker Desktop baslatiliyor, hazir olmasi bekleniyor...' -ForegroundColor Yellow
        Start-Process -FilePath $dd
    } else {
        Write-Host 'Docker Desktop calismiyor; lutfen Docker Desktop uygulamasini acin.' -ForegroundColor Yellow
    }
    for ($i = 0; $i -lt 45; $i++) {
        Start-Sleep -Seconds 2
        try { docker info *> $null; if ($LASTEXITCODE -eq 0) { $dockerReady = $true; break } } catch {}
        Write-Host -NoNewline '.'
    }
    Write-Host ''
    if (-not $dockerReady) {
        throw 'Docker Desktop hazir olmadi. Docker Desktop penceresinde ilk kurulum onayini tamamlayip PC_BASLAT.bat dosyasini tekrar calistirin.'
    }
}
Write-Host 'Docker hazir.' -ForegroundColor Green

# 2) cloudflared kontrol / kurulum
Write-Step '2/5 Cloudflare Tunnel (cloudflared) kontrol ediliyor...'
if (-not (Get-Command cloudflared -ErrorAction SilentlyContinue)) {
    Write-Host 'cloudflared kuruluyor...' -ForegroundColor Yellow
    winget install -e --id Cloudflare.cloudflared --accept-package-agreements --accept-source-agreements
    Refresh-Path
}
$cfExe = (Get-Command cloudflared -ErrorAction SilentlyContinue).Source
if (-not $cfExe) {
    $fallbackCf = "$env:ProgramFiles\cloudflared\cloudflared.exe"
    if (Test-Path $fallbackCf) { $cfExe = $fallbackCf }
}
if (-not $cfExe) {
    $fallbackCf86 = "${env:ProgramFiles(x86)}\cloudflared\cloudflared.exe"
    if (Test-Path $fallbackCf86) { $cfExe = $fallbackCf86 }
}
if (-not $cfExe) {
    throw 'cloudflared bulunamadi. PowerShell penceresini kapatip PC_BASLAT.bat dosyasini yeniden calistirin.'
}
Write-Host "cloudflared hazir: $cfExe" -ForegroundColor Green

# 3) .env kontrol / otomatik uretim
Write-Step '3/5 .env yapilandirmasi kontrol ediliyor...'
$envPath = Join-Path $Dir '.env'
$evoKey  = ''
$whToken = ''
if (Test-Path $envPath) {
    $rawEnv = Get-Content $envPath -Raw
    if ($rawEnv -match 'EVOLUTION_IMAGE_TAG=v2\.2\.3') {
        $rawEnv = $rawEnv -replace 'EVOLUTION_IMAGE_TAG=v2\.2\.3', 'EVOLUTION_IMAGE_TAG=v2.3.7'
        [System.IO.File]::WriteAllText($envPath, $rawEnv, [System.Text.UTF8Encoding]::new($false))
    }
    foreach ($line in Get-Content $envPath) {
        if ($line -match '^EVOLUTION_API_KEY=(.+)$') { $evoKey = $Matches[1].Trim() }
        if ($line -match '^WEBHOOK_URL=.*[?&]token=([^&\s]+)') { $whToken = $Matches[1].Trim() }
    }
}
if ($evoKey -eq '' -or $whToken -eq '') {
    if ($evoKey -eq '')  { $evoKey  = New-HexSecret 24 }
    if ($whToken -eq '') { $whToken = New-HexSecret 24 }
    $pgPass = New-HexSecret 20
    $envBody = @(
        "EVOLUTION_API_KEY=$evoKey"
        'EVOLUTION_IMAGE_TAG=v2.3.7'
        'EVOLUTION_BIND=127.0.0.1'
        'EVOLUTION_PORT=8080'
        'EVOLUTION_SERVER_URL=http://localhost:8080'
        'POSTGRES_USER=evolution'
        "POSTGRES_PASSWORD=$pgPass"
        'POSTGRES_DB=evolution'
        "WEBHOOK_URL=$SiteBase/api_whatsapp_webhook.php?token=$whToken"
    ) -join "`r`n"
    [System.IO.File]::WriteAllText($envPath, $envBody + "`r`n", [System.Text.UTF8Encoding]::new($false))
    Write-Host '.env dosyasi guclu rastgele anahtarlarla otomatik olusturuldu.' -ForegroundColor Green
} else {
    Write-Host 'Mevcut .env anahtarlari korundu.' -ForegroundColor Green
}

# 4) Evolution API baslat
Write-Step '4/5 Evolution API + PostgreSQL + Redis baslatiliyor (ilk seferde imaj indirilir)...'
docker compose up -d
if ($LASTEXITCODE -ne 0) { throw 'docker compose up -d basarisiz oldu.' }

Write-Host 'Evolution API hazir olmasi bekleniyor...' -ForegroundColor Yellow
$apiOk = $false
for ($i = 0; $i -lt 45; $i++) {
    Start-Sleep -Seconds 2
    try {
        $resp = Invoke-WebRequest -Uri 'http://127.0.0.1:8080/' -UseBasicParsing -TimeoutSec 3
        if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500) { $apiOk = $true; break }
    } catch {
        if ($_.Exception.Response) { $apiOk = $true; break }
    }
    Write-Host -NoNewline '.'
}
Write-Host ''
if (-not $apiOk) {
    Write-Host 'Uyari: Evolution API henuz yanit vermedi (arka planda acilmaya devam ediyor olabilir).' -ForegroundColor Yellow
} else {
    Write-Host 'Evolution API (127.0.0.1:8080) calisiyor.' -ForegroundColor Green
}

# 5) Cloudflare Tunnel baslat + URL yakala
Write-Step '5/5 Gizli Cloudflare Tunnel baslatiliyor...'
$logOut = Join-Path $env:TEMP 'yp_cloudflared_out.log'
$logErr = Join-Path $env:TEMP 'yp_cloudflared_err.log'
Remove-Item $logOut, $logErr -ErrorAction SilentlyContinue

$cfProc = Start-Process -FilePath $cfExe -ArgumentList @('tunnel', '--no-autoupdate', '--url', 'http://127.0.0.1:8080') `
    -NoNewWindow -PassThru -RedirectStandardOutput $logOut -RedirectStandardError $logErr

$tunnelUrl = ''
for ($i = 0; $i -lt 40; $i++) {
    Start-Sleep -Seconds 1
    if ($cfProc.HasExited) { break }
    $txt = ''
    if (Test-Path $logErr) { $txt += Get-Content $logErr -Raw -ErrorAction SilentlyContinue }
    if (Test-Path $logOut) { $txt += Get-Content $logOut -Raw -ErrorAction SilentlyContinue }
    if ($txt -match 'https://[a-z0-9-]+\.trycloudflare\.com') {
        $tunnelUrl = $Matches[0]
        break
    }
}

if ($tunnelUrl -eq '') {
    try { Stop-Process -Id $cfProc.Id -Force -ErrorAction SilentlyContinue } catch {}
    throw 'Cloudflare Tunnel adresi alinamadi. Internet baglantinizi kontrol edip tekrar deneyin.'
}

$payload = [ordered]@{
    evolution_url     = $tunnelUrl
    evolution_api_key = $evoKey
    webhook_secret    = $whToken
    instance_adi      = 'yukleme_plani_bot'
} | ConvertTo-Json -Compress
$kurulumKodu = 'WA-KURULUM:' + [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($payload))
try { Set-Clipboard -Value $kurulumKodu } catch {}

Write-Host ''
Write-Host '============================================================' -ForegroundColor Green
Write-Host '  HAZIR! Kurulum kodu PANOYA OTOMATIK KOPYALANDI.' -ForegroundColor Green
Write-Host '============================================================' -ForegroundColor Green
Write-Host "Tunel Adresi : $tunnelUrl"
Write-Host "Panel Adresi : $PanelUrl"
Write-Host ''
Write-Host 'Simdi tarayicida acilan sayfada:' -ForegroundColor Yellow
Write-Host '  1) "0 · Sunucu & API Anahtarlari" bolumundeki "Hizli Kurulum Kodu" kutusuna Ctrl+V yapin'
Write-Host '  2) AI API Key (Groq veya Gemini) girip "Baglanti ayarlarini kaydet"e basin'
Write-Host '  3) "QR kod al / oturum olustur" deyip WhatsApp''tan okutun'
Write-Host ''
Write-Host 'Kurulum Kodu (yedek):' -ForegroundColor DarkGray
Write-Host $kurulumKodu -ForegroundColor DarkGray
Write-Host ''
Write-Host 'ONEMLI: Bu pencereyi KAPATMAYIN (kucultup arka planda acik birakin).' -ForegroundColor Cyan
Write-Host 'Durdurmak icin Ctrl+C tuslarina basin.' -ForegroundColor DarkGray

try { Start-Process $PanelUrl } catch {}

try {
    Wait-Process -Id $cfProc.Id
} finally {
    if (-not $cfProc.HasExited) {
        Stop-Process -Id $cfProc.Id -Force -ErrorAction SilentlyContinue
    }
}
