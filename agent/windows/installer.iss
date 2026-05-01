#define AppName "Sistemax Agent"
#ifndef AppVersion
  #define AppVersion "0.1.1"
#endif
#ifndef SourceDir
  #define SourceDir ".\\dist"
#endif

[Setup]
AppId={{7E7A2F0E-DB84-4AF1-8897-3D8A070A5A2E}
AppName={#AppName}
AppVersion={#AppVersion}
DefaultDirName={autopf}\SistemaxAgent
DefaultGroupName={#AppName}
OutputDir=.
OutputBaseFilename=sistemax-agent-setup-{#AppVersion}
Compression=lzma
SolidCompression=yes
ArchitecturesAllowed=x64
ArchitecturesInstallIn64BitMode=x64
PrivilegesRequired=admin

[Files]
Source: "{#SourceDir}\sistemax-agent.exe"; DestDir: "{app}"; Flags: ignoreversion

[Icons]
Name: "{group}\Sistemax Agent"; Filename: "{app}\sistemax-agent.exe"
Name: "{group}\Desinstalar Sistemax Agent"; Filename: "{uninstallexe}"

[Run]
Filename: "{app}\sistemax-agent.exe"; Description: "Iniciar Sistemax Agent"; Flags: nowait postinstall skipifsilent

[Registry]
Root: HKCU; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "SistemaxAgent"; ValueData: """{app}\sistemax-agent.exe"""; Flags: uninsdeletevalue
