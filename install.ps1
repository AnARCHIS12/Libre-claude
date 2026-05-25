param(
    [string]$Dir = $(if ($env:LIBRE_CLAUDE_DIR) { $env:LIBRE_CLAUDE_DIR } else { Join-Path $HOME "libre-claude" }),
    [int]$Port = $(if ($env:LIBRE_CLAUDE_PORT) { [int]$env:LIBRE_CLAUDE_PORT } else { 8173 }),
    [string]$Image = $(if ($env:LIBRE_CLAUDE_IMAGE) { $env:LIBRE_CLAUDE_IMAGE } else { "liberchat/libre-claude:latest" }),
    [string]$GitHubOAuthClientId = $(if ($env:GITHUB_OAUTH_CLIENT_ID) { $env:GITHUB_OAUTH_CLIENT_ID } else { "" }),
    [string]$GitHubOAuthClientSecret = $(if ($env:GITHUB_OAUTH_CLIENT_SECRET) { $env:GITHUB_OAUTH_CLIENT_SECRET } else { "" }),
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

    Write-Warn "Installation de Docker Desktop avec winget..."
    & winget install --id Docker.DockerDesktop -e --accept-package-agreements --accept-source-agreements
    if ($LASTEXITCODE -ne 0) { Fail "installation Docker Desktop echouee avec winget" }

    Ensure-DockerPath
    $dockerDesktop = Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"
    if (Test-Path $dockerDesktop) {
        Start-Process $dockerDesktop | Out-Null
        Write-Warn "Docker Desktop vient d'etre lance. Attendez qu'il soit pret."
    }
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
        $shouldInstall = Ask-YesNo "Installer Docker Desktop automatiquement maintenant ?" $true
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

    & docker compose version *> $null
    if ($LASTEXITCODE -eq 0) { return @("docker", "compose") }

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
GITHUB_OAUTH_CLIENT_ID=$GitHubOAuthClientId
GITHUB_OAUTH_CLIENT_SECRET=$GitHubOAuthClientSecret
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
      GITHUB_OAUTH_CLIENT_ID: `${GITHUB_OAUTH_CLIENT_ID:-}
      GITHUB_OAUTH_CLIENT_SECRET: `${GITHUB_OAUTH_CLIENT_SECRET:-}
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

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    Fail "Docker Desktop est installe mais ne semble pas lance. Ouvrez Docker Desktop puis relancez ce script."
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
