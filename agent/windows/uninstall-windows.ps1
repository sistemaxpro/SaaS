$ErrorActionPreference = "SilentlyContinue"

Get-Process -Name "sistemax-agent" | Stop-Process -Force

$AgentHome = Join-Path $env:LOCALAPPDATA "SistemaxAgent"
$StartupDir = Join-Path $env:APPDATA "Microsoft\Windows\Start Menu\Programs\Startup"
$StartupCmd = Join-Path $StartupDir "sistemax-agent-start.cmd"

Remove-Item -Force $StartupCmd
Remove-Item -Recurse -Force $AgentHome
Write-Host "Sistemax Agent desinstalado" -ForegroundColor Green
