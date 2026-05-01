$ErrorActionPreference = "Stop"

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ExeSource = Join-Path $ScriptDir "sistemax-agent.exe"
if (!(Test-Path $ExeSource)) {
  Write-Host "No se encontro sistemax-agent.exe" -ForegroundColor Red
  exit 1
}

$AgentHome = Join-Path $env:LOCALAPPDATA "SistemaxAgent"
$BinDest = Join-Path $AgentHome "sistemax-agent.exe"
$LogDir = Join-Path $AgentHome "logs"
New-Item -ItemType Directory -Force -Path $AgentHome | Out-Null
New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
Copy-Item -Force $ExeSource $BinDest

$StartupDir = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\Startup"
$StartupCmd = Join-Path $StartupDir "sistemax-agent-start.cmd"
$CmdContent = "@echo off`r`nstart `"Sistemax Agent`" /MIN `"$BinDest`"`r`n"
Set-Content -Path $StartupCmd -Value $CmdContent -Encoding ASCII

# Restart agent process
Get-Process -Name "sistemax-agent" -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
Start-Process -FilePath $BinDest -WindowStyle Minimized

Start-Sleep -Seconds 1
try {
  $h = Invoke-RestMethod -Uri "http://127.0.0.1:17890/health" -Method GET -TimeoutSec 5
  if ($h.ok -eq $true) {
    Write-Host "Sistemax Agent instalado y activo en Windows" -ForegroundColor Green
    Write-Host "Health: http://127.0.0.1:17890/health"
  } else {
    Write-Host "Instalado, pero /health no devolvio ok=true" -ForegroundColor Yellow
  }
} catch {
  Write-Host "Instalado, pero aun no responde /health. Revise firewall/antivirus." -ForegroundColor Yellow
}
