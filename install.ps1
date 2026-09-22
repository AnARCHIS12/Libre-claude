param(
    [string]$Dir = $(if ($env:LIBRE_CLAUDE_DIR) { $env:LIBRE_CLAUDE_DIR } else { Join-Path $HOME "libre-claude" }),
    [int]$Port = $(if ($env:LIBRE_CLAUDE_PORT) { [int]$env:LIBRE_CLAUDE_PORT } else { 8173 }),
    [string]$Image = $(if ($env:LIBRE_CLAUDE_IMAGE) { $env:LIBRE_CLAUDE_IMAGE } else { "liberchat/libre-claude:latest" }),
    [string]$PublicUrl = $(if ($env:PUBLIC_URL) { $env:PUBLIC_URL } else { "" }),
    [string]$GitHubOAuthClientId = $(if ($env:GITHUB_OAUTH_CLIENT_ID) { $env:GITHUB_OAUTH_CLIENT_ID } else { "" }),
    [string]$GitHubOAuthClientSecret = $(if ($env:GITHUB_OAUTH_CLIENT_SECRET) { $env:GITHUB_OAUTH_CLIENT_SECRET } else { "" }),
    [string]$GitHubOAuthScope = $(if ($env:GITHUB_OAUTH_SCOPE) { $env:GITHUB_OAUTH_SCOPE } else { "" }),
    [switch]$Yes,
    [switch]$NoStart,
    [switch]$NoInstallDocker,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Write-Info($Message) { Write-Host $Message -ForegroundColor Cyan }
function Write-Ok($Message) { Write-Host $Message -ForegroundColor Green }
function Write-Warn($Message) { Write-Host $Message -ForegroundColor Yellow }
function Fail($Message) {
    Write-Host "Erreur: $Message" -ForegroundColor Red
    exit 1
}

function Test-IsAdmin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-VirtualizationEnabled {
    try {
        $cpus = Get-CimInstance Win32_Processor -ErrorAction SilentlyContinue
        if ($cpus) {
            foreach ($cpu in $cpus) {
                if ($cpu.VirtualizationFirmwareEnabled -eq $false) {
                    return $false
                }
            }
        }
    } catch {}
    return $true
}

function Test-WslInstalled {
    $wslExe = Get-Command wsl.exe -ErrorAction SilentlyContinue
    if (-not $wslExe) { return $false }

    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    try {
        & wsl.exe --status 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { return $true }

        & wsl.exe -l -q 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { return $true }

        return $false
    } catch {
        return $false
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Ensure-Wsl {
    if (Test-WslInstalled) {
        Write-Ok "WSL (Windows Subsystem for Linux) est deja configure."
        return $true
    }

    if (-not (Test-VirtualizationEnabled)) {
        Write-Warn "ATTENTION : La virtualisation materielle (VT-x / AMD-V) semble desactivee dans votre BIOS/UEFI."
        Write-Warn "Si WSL ou Docker ne demarre pas, activez la virtualisation dans les parametres BIOS de votre carte mere."
    }

    Write-Warn "WSL (Windows Subsystem for Linux) n'est pas detecte."
    Write-Info "Docker Desktop sous Windows necessite WSL 2 pour fonctionner."
    Write-Info "Installation automatique de WSL 2 en cours..."

    $wslExe = Get-Command wsl.exe -ErrorAction SilentlyContinue
    $installed = $false

    if ($wslExe) {
        try {
            if (Test-IsAdmin) {
                Write-Info "Execution de : wsl --install --no-distribution"
                $prev = $ErrorActionPreference
                $ErrorActionPreference = 'SilentlyContinue'
                & wsl.exe --install --no-distribution 2>&1 | Out-Null
                if ($LASTEXITCODE -eq 0) { $installed = $true }
                $ErrorActionPreference = $prev
            } else {
                Write-Info "Une confirmation Administrateur (UAC) Windows va s'ouvrir pour installer WSL. Cliquez sur 'Oui'."
                $p = Start-Process -FilePath "wsl.exe" -ArgumentList "--install", "--no-distribution" -Verb RunAs -Wait -PassThru -ErrorAction SilentlyContinue
                if ($p -and $p.ExitCode -eq 0) { $installed = $true }
            }
        } catch {
            $installed = $false
        }
    }

    if (-not $installed) {
        $winget = Get-Command winget -ErrorAction SilentlyContinue
        if ($winget) {
            Write-Info "Installation du package WSL via winget (Microsoft.WSL)..."
            $prev = $ErrorActionPreference
            $ErrorActionPreference = 'SilentlyContinue'
            & winget install --id Microsoft.WSL -e --accept-package-agreements --accept-source-agreements 2>&1 | Out-Null
            if ($LASTEXITCODE -eq 0) { $installed = $true }
            $ErrorActionPreference = $prev
        }
    }

    # Mise a jour du noyau Linux WSL
    if (Get-Command wsl.exe -ErrorAction SilentlyContinue) {
        $prev = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        try {
            & wsl.exe --update 2>&1 | Out-Null
        } catch {}
        finally { $ErrorActionPreference = $prev }
    }

    if (Test-WslInstalled) {
        Write-Ok "WSL 2 est operationnel."
        return $true
    }

    Write-Warn "WSL a ete installe ou mis a jour. Si Docker ne demarre pas tout de suite, un REDEMARRAGE de Windows peut etre necessaire."
    return $installed
}

function Add-ToUserPath($PathToAdd) {
    if (-not (Test-Path $PathToAdd)) { return }
    $items = $env:Path -split ";"
    if ($items -notcontains $PathToAdd) {
        $env:Path = "$PathToAdd;$env:Path"
    }

    $userPath = [Environment]::GetEnvironmentVariable("Path", "User")
    $userItems = @()
    if ($userPath) { $userItems = $userPath -split ";" }
    if ($userItems -notcontains $PathToAdd) {
        $newUserPath = if ($userPath) { "$PathToAdd;$userPath" } else { $PathToAdd }
        [Environment]::SetEnvironmentVariable("Path", $newUserPath, "User")
        Write-Warn "PATH utilisateur mis a jour. Un nouveau terminal peut etre necessaire."
    }
}

function Ensure-DockerPath {
    $candidates = @(
        "$env:ProgramFiles\Docker\Docker\resources\bin",
        "$env:ProgramFiles\Docker\Docker\resources",
        "$env:ProgramData\DockerDesktop\version-bin",
        "$env:LOCALAPPDATA\Docker\resources\bin"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path (Join-Path $candidate "docker.exe")) {
            Add-ToUserPath $candidate
        }
    }
}

function Install-DockerDesktop {
    $winget = Get-Command winget -ErrorAction SilentlyContinue
    if (-not $winget) {
        Fail "Docker Desktop est absent et winget est introuvable. Installez Docker Desktop depuis https://docs.docker.com/desktop/setup/install/windows-install/"
    }

    # S'assurer que WSL 2 est installe avant Docker Desktop
    Ensure-Wsl

    Write-Warn "Installation de Docker Desktop avec winget..."
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    & winget install --id Docker.DockerDesktop -e --accept-package-agreements --accept-source-agreements --override "--accept-license --backend=wsl-2"
    $exitCode = $LASTEXITCODE
    $ErrorActionPreference = $prev

    if ($exitCode -ne 0) {
        Write-Warn "Nouvelle tentative d'installation de Docker Desktop sans arguments personnalises..."
        $prev = $ErrorActionPreference
        $ErrorActionPreference = 'SilentlyContinue'
        & winget install --id Docker.DockerDesktop -e --accept-package-agreements --accept-source-agreements
        $exitCode = $LASTEXITCODE
        $ErrorActionPreference = $prev
        if ($exitCode -ne 0) { Fail "L'installation de Docker Desktop a echoue avec winget." }
    }

    Ensure-DockerPath
    $dockerDesktop = Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"
    if (Test-Path $dockerDesktop) {
        Start-Process $dockerDesktop | Out-Null
        Write-Ok "Docker Desktop a ete lance."
    }
}

function Test-DockerDaemon {
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    try {
        & docker info 2>&1 | Out-Null
        return ($LASTEXITCODE -eq 0)
    } catch {
        return $false
    } finally {
        $ErrorActionPreference = $prev
    }
}

function Wait-DockerReady([int]$TimeoutSeconds = 120) {
    if (Test-DockerDaemon) {
        return $true
    }

    # S'assurer que Docker Desktop est bien lance
    $dockerDesktop = Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"
    $dockerProcesses = Get-Process "Docker Desktop" -ErrorAction SilentlyContinue
    if (-not $dockerProcesses -and (Test-Path $dockerDesktop)) {
        Write-Warn "Lancement de Docker Desktop..."
        Start-Process $dockerDesktop | Out-Null
    }

    Write-Info "En attente du demarrage du moteur Docker..."
    Write-Warn "Note : Au premier lancement, Docker Desktop peut prendre 1 a 2 minutes pour initialiser son moteur."

    $elapsed = 0
    $interval = 3
    while ($elapsed -lt $TimeoutSeconds) {
        Start-Sleep -Seconds $interval
        $elapsed += $interval

        if (Test-DockerDaemon) {
            Write-Ok "Moteur Docker connecte et pret (en $elapsed s)."
            return $true
        }

        Write-Host "  ... attente de Docker Desktop ($elapsed s / $TimeoutSeconds s)" -ForegroundColor Gray
    }

    return $false
}

function Get-DockerCommand {
    Ensure-DockerPath
    $docker = Get-Command docker -ErrorAction SilentlyContinue
    if ($docker) { return $docker }

    Write-Warn "Docker est absent ou introuvable dans le PATH."
    $shouldInstall = $false
    if ($Yes -and -not $NoInstallDocker) {
        $shouldInstall = $true
    } elseif (-not $NoInstallDocker) {
        $shouldInstall = Ask-YesNo "Installer Docker Desktop et WSL automatiquement maintenant ?" $true
    }

    if ($shouldInstall) {
        Install-DockerDesktop
        Ensure-DockerPath
        $docker = Get-Command docker -ErrorAction SilentlyContinue
        if ($docker) { return $docker }
        Fail "Docker Desktop a ete installe mais docker.exe reste introuvable. Ouvrez un nouveau PowerShell puis relancez ce script."
    }

    Fail "Docker Desktop est requis. Installez Docker ou relancez sans -NoInstallDocker."
}

function Ask-Value($Prompt, $Default) {
    if ($Yes) { return $Default }
    $value = Read-Host "$Prompt [$Default]"
    if ([string]::IsNullOrWhiteSpace($value)) { return $Default }
    return $value
}

function Ask-SecretValue($Prompt, $Default) {
    if ($Yes) { return $Default }
    if ($Default) {
        $secret = Read-Host "$Prompt [deja renseigne, Entree pour garder]" -AsSecureString
    } else {
        $secret = Read-Host "$Prompt [optionnel]" -AsSecureString
    }
    $plain = ConvertFrom-SecureStringPlain $secret
    if ([string]::IsNullOrWhiteSpace($plain)) { return $Default }
    return $plain
}

function ConvertFrom-SecureStringPlain($SecureString) {
    if (-not $SecureString) { return "" }
    $ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($SecureString)
    try {
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
    } finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
    }
}

function Ask-YesNo($Prompt, $DefaultYes) {
    if ($Yes) { return $DefaultYes }
    $suffix = if ($DefaultYes) { "[O/n]" } else { "[o/N]" }
    $answer = Read-Host "$Prompt $suffix"
    if ([string]::IsNullOrWhiteSpace($answer)) { return $DefaultYes }
    return $answer -match "^(o|oui|y|yes)$"
}

function Get-ComposeCommand {
    Get-DockerCommand | Out-Null

    $hasCompose = $false
    $prev = $ErrorActionPreference
    $ErrorActionPreference = 'SilentlyContinue'
    try {
        & docker compose version 2>&1 | Out-Null
        if ($LASTEXITCODE -eq 0) { $hasCompose = $true }
    } catch {
        $hasCompose = $false
    } finally {
        $ErrorActionPreference = $prev
    }

    if ($hasCompose) { return @("docker", "compose") }

    $dockerCompose = Get-Command docker-compose -ErrorAction SilentlyContinue
    if ($dockerCompose) { return @("docker-compose") }

    Fail "Docker Compose est requis. Activez/installez Docker Compose dans Docker Desktop."
}

function Invoke-Compose($Compose, $Arguments) {
    if ($Compose.Count -eq 2) {
        & $Compose[0] $Compose[1] @Arguments
    } else {
        & $Compose[0] @Arguments
    }
    if ($LASTEXITCODE -ne 0) { Fail "Docker Compose a echoue: $($Arguments -join ' ')" }
}

Write-Host ""
Write-Host "Libre Claude - installation Windows" -ForegroundColor White
Write-Host ""

if (-not $Yes) {
    Write-Warn "Assistant interactif. Appuyez sur Entree pour garder une valeur par defaut."
    $Dir = Ask-Value "Dossier d'installation" $Dir
    $Port = [int](Ask-Value "Port web local" $Port)
    $Image = Ask-Value "Image Docker" $Image
    $PublicUrl = Ask-Value "URL publique de l'app" $PublicUrl

    if (Ask-YesNo "Configurer GitHub OAuth maintenant ?" $false) {
        $GitHubOAuthClientId = Ask-Value "GitHub OAuth Client ID" $GitHubOAuthClientId
        $GitHubOAuthClientSecret = Ask-SecretValue "GitHub OAuth Client Secret" $GitHubOAuthClientSecret
    }

    $NoStart = -not (Ask-YesNo "Lancer Libre Claude apres l'installation ?" $true)
} else {
    Write-Warn "Mode non-interactif: utilisation des parametres et variables d'environnement."
}

if ($Port -lt 1 -or $Port -gt 65535) { Fail "port invalide: $Port" }

$oauthState = if ($GitHubOAuthClientId -and $GitHubOAuthClientSecret) { "active" } else { "desactive" }
$startState = if ($NoStart) { "non" } else { "oui" }

Write-Host ""
Write-Host "Configuration:"
Write-Host "  Dossier : $Dir"
Write-Host "  Port    : $Port"
Write-Host "  Image   : $Image"
Write-Host "  URL     : $(if ($PublicUrl) { $PublicUrl } else { "auto" })"
Write-Host "  OAuth   : $oauthState"
Write-Host "  Lancer  : $startState"

if ($DryRun) {
    Write-Ok "Dry-run termine. Aucun fichier ecrit."
    exit 0
}

if (-not (Ask-YesNo "Continuer ?" $true)) {
    Write-Warn "Installation annulee."
    exit 0
}

if ((Test-Path $Dir) -and -not (Test-Path $Dir -PathType Container)) {
    Fail "$Dir existe mais n'est pas un dossier."
}

if ((Test-Path (Join-Path $Dir "docker-compose.yml")) -and -not $Yes) {
    Write-Warn "Installation existante detectee dans $Dir."
    if (-not (Ask-YesNo "Mettre a jour les fichiers de lancement ?" $true)) {
        Write-Warn "Aucun changement effectue."
        exit 0
    }
}

New-Item -ItemType Directory -Force -Path $Dir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $Dir "data") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $Dir "sandbox") | Out-Null

@"
PUBLIC_URL=$PublicUrl
GITHUB_OAUTH_CLIENT_ID=$GitHubOAuthClientId
GITHUB_OAUTH_CLIENT_SECRET=$GitHubOAuthClientSecret
GITHUB_OAUTH_SCOPE=$GitHubOAuthScope
"@ | Set-Content -Encoding UTF8 -Path (Join-Path $Dir ".env")

@"
services:
  libre-claude:
    image: $Image
    ports:
      - "$Port`:80"
    env_file:
      - .env
    environment:
      PUBLIC_URL: `${PUBLIC_URL:-}
      GITHUB_OAUTH_CLIENT_ID: `${GITHUB_OAUTH_CLIENT_ID:-}
      GITHUB_OAUTH_CLIENT_SECRET: `${GITHUB_OAUTH_CLIENT_SECRET:-}
      GITHUB_OAUTH_SCOPE: `${GITHUB_OAUTH_SCOPE:-}
    volumes:
      - ./data:/var/www/html/data
      - ./sandbox:/var/www/html/sandbox
    restart: unless-stopped
"@ | Set-Content -Encoding UTF8 -Path (Join-Path $Dir "docker-compose.yml")

Write-Ok "Fichiers crees:"
Write-Host "  $(Join-Path $Dir "docker-compose.yml")"
Write-Host "  $(Join-Path $Dir ".env")"
Write-Host "  $(Join-Path $Dir "data")"
Write-Host "  $(Join-Path $Dir "sandbox")"

if ($NoStart) {
    Write-Host ""
    Write-Host "Installation preparee sans lancement."
    Write-Host ""
    Write-Host "Pour demarrer:"
    Write-Host "  cd `"$Dir`""
    Write-Host "  docker compose up -d"
    exit 0
}

$compose = Get-ComposeCommand

# Attente active et intelligente du demarrage complet de Docker
if (-not (Wait-DockerReady -TimeoutSeconds 120)) {
    Write-Host ""
    Write-Host "Le moteur Docker ne repond pas encore." -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Conseils pour resoudre ce probleme :" -ForegroundColor Cyan
    if (-not (Test-WslInstalled)) {
        Write-Host "  - WSL 2 n'a pas pu etre detecte comme operationnel." -ForegroundColor Red
        Write-Host "    Ouvrez PowerShell en tant qu'Administrateur et lancez : wsl --install --no-distribution" -ForegroundColor White
        Write-Host "    Puis REDEMARREZ votre ordinateur pour activer la virtualisation." -ForegroundColor White
    } else {
        Write-Host "  1. Regardez dans la barre des taches (pres de l'horloge) si l'icone Docker Desktop apparait."
        Write-Host "  2. Si Docker Desktop affiche une fenetre, acceptez le contrat de licence (bouton Accept)."
        Write-Host "  3. Si WSL ou Docker Desktop vient d'etre installe pour la 1ere fois, REDEMARREZ votre PC."
        Write-Host "  4. Une fois l'icone Docker verte ('Engine running'), relancez simplement :"
        Write-Host "     irm https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.ps1 | iex" -ForegroundColor Green
    }
    Write-Host ""
    Fail "Docker Desktop n'est pas encore pret. Suivez les instructions ci-dessus."
}

Push-Location $Dir
try {
    Write-Info "Telechargement de l'image..."
    Invoke-Compose $compose @("pull")

    Write-Info "Demarrage de Libre Claude..."
    Invoke-Compose $compose @("up", "-d")
} finally {
    Pop-Location
}

Write-Host ""
Write-Ok "Libre Claude est installe."
Write-Host ""
Write-Host "Ouvrir:"
Write-Host "  http://127.0.0.1:$Port"
Write-Host ""
Write-Host "Commandes utiles:"
Write-Host "  cd `"$Dir`""
Write-Host "  docker compose ps"
Write-Host "  docker compose logs -f"
Write-Host "  docker compose pull; docker compose up -d"
Write-Host "  docker compose down"
