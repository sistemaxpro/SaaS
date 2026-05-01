Param(
  [string]$Version = "0.1.1",
  [string]$OutDir = "dist"
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

New-Item -ItemType Directory -Force -Path $OutDir | Out-Null

Write-Host "Building sistemax-agent.exe ($Version)..."
$env:GOOS = "windows"
$env:GOARCH = "amd64"
go build -trimpath -ldflags "-s -w" -o "$OutDir\sistemax-agent.exe" .

$iss = Join-Path $PSScriptRoot "installer.iss"
if (Get-Command iscc -ErrorAction SilentlyContinue) {
  Write-Host "Building installer with Inno Setup..."
  iscc "/DAppVersion=$Version" "/DSourceDir=$root\$OutDir" $iss
} else {
  Write-Warning "Inno Setup (iscc) no encontrado. Instalador .exe no generado."
}
