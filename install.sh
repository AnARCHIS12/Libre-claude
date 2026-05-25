#!/bin/sh
set -eu

APP_NAME="Libre Claude"
DEFAULT_DIR="${HOME:-.}/libre-claude"
DEFAULT_IMAGE="liberchat/libre-claude:latest"
DEFAULT_PORT="8173"

INSTALL_DIR="${LIBRE_CLAUDE_DIR:-$DEFAULT_DIR}"
IMAGE="${LIBRE_CLAUDE_IMAGE:-$DEFAULT_IMAGE}"
PORT="${LIBRE_CLAUDE_PORT:-$DEFAULT_PORT}"
PUBLIC_URL_VALUE="${PUBLIC_URL:-}"
CLIENT_ID="${GITHUB_OAUTH_CLIENT_ID:-}"
CLIENT_SECRET="${GITHUB_OAUTH_CLIENT_SECRET:-}"
CLIENT_SCOPE="${GITHUB_OAUTH_SCOPE:-}"
YES="${LIBRE_CLAUDE_YES:-0}"
NO_START="${LIBRE_CLAUDE_NO_START:-0}"
INSTALL_DOCKER="${LIBRE_CLAUDE_INSTALL_DOCKER:-1}"
DRY_RUN=0

if [ -t 1 ]; then
  RED="$(printf '\033[31m')"
  GREEN="$(printf '\033[32m')"
  YELLOW="$(printf '\033[33m')"
  BOLD="$(printf '\033[1m')"
  RESET="$(printf '\033[0m')"
else
  RED=""; GREEN=""; YELLOW=""; BOLD=""; RESET=""
fi

red() { printf '%s%s%s\n' "$RED" "$*" "$RESET"; }
green() { printf '%s%s%s\n' "$GREEN" "$*" "$RESET"; }
yellow() { printf '%s%s%s\n' "$YELLOW" "$*" "$RESET"; }
bold() { printf '%s%s%s\n' "$BOLD" "$*" "$RESET"; }
die() { red "Erreur: $*"; exit 1; }

usage() {
  cat <<EOF
Libre Claude - installateur interactif

Usage:
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | sh

Options:
  --yes             utilise les valeurs par défaut / variables d'environnement
  --no-start        crée les fichiers sans lancer Docker
  --dry-run         affiche la configuration sans rien écrire
  --dir PATH        dossier d'installation
  --port PORT       port HTTP local
  --image IMAGE     image Docker à utiliser
  --install-docker  installe Docker si absent
  --no-install-docker
                    n'installe pas Docker automatiquement
  -h, --help        aide

Variables d'environnement:
  LIBRE_CLAUDE_DIR=/opt/libre-claude
  LIBRE_CLAUDE_PORT=8173
  LIBRE_CLAUDE_IMAGE=liberchat/libre-claude:latest
  PUBLIC_URL=https://libre-claude.example.com
  GITHUB_OAUTH_CLIENT_ID=...
  GITHUB_OAUTH_CLIENT_SECRET=...
  LIBRE_CLAUDE_YES=1
  LIBRE_CLAUDE_NO_START=1
  LIBRE_CLAUDE_INSTALL_DOCKER=1

Exemples:
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | sh
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | LIBRE_CLAUDE_YES=1 sh
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | sh -s -- --port 8080
EOF
}

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help) usage; exit 0 ;;
    --yes) YES=1 ;;
    --no-start) NO_START=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --install-docker) INSTALL_DOCKER=1 ;;
    --no-install-docker) INSTALL_DOCKER=0 ;;
    --dir)
      [ $# -ge 2 ] || die "--dir attend un chemin"
      INSTALL_DIR="$2"
      shift
      ;;
    --port)
      [ $# -ge 2 ] || die "--port attend un port"
      PORT="$2"
      shift
      ;;
    --image)
      [ $# -ge 2 ] || die "--image attend une image Docker"
      IMAGE="$2"
      shift
      ;;
    *) die "option inconnue: $1" ;;
  esac
  shift
done

has_tty() {
  [ -r /dev/tty ] && [ "$YES" != "1" ]
}

ask_value() {
  prompt="$1"
  default="$2"
  if ! has_tty; then
    printf '%s\n' "$default"
    return 0
  fi
  printf '%s [%s]: ' "$prompt" "$default" > /dev/tty
  IFS= read -r value < /dev/tty || value=""
  if [ -z "$value" ]; then value="$default"; fi
  printf '%s\n' "$value"
}

ask_secret() {
  prompt="$1"
  default="$2"
  if ! has_tty; then
    printf '%s\n' "$default"
    return 0
  fi
  if [ -n "$default" ]; then
    label="$prompt [déjà renseigné, Entrée pour garder]: "
  else
    label="$prompt [optionnel]: "
  fi
  printf '%s' "$label" > /dev/tty
  if command -v stty >/dev/null 2>&1; then
    old_stty="$(stty -g < /dev/tty 2>/dev/null || true)"
    stty -echo < /dev/tty 2>/dev/null || true
    IFS= read -r value < /dev/tty || value=""
    [ -n "$old_stty" ] && stty "$old_stty" < /dev/tty 2>/dev/null || true
    printf '\n' > /dev/tty
  else
    IFS= read -r value < /dev/tty || value=""
  fi
  if [ -z "$value" ]; then value="$default"; fi
  printf '%s\n' "$value"
}

ask_yes_no() {
  prompt="$1"
  default="$2"
  if ! has_tty; then
    [ "$default" = "yes" ]
    return $?
  fi
  if [ "$default" = "yes" ]; then suffix="[O/n]"; else suffix="[o/N]"; fi
  printf '%s %s ' "$prompt" "$suffix" > /dev/tty
  IFS= read -r answer < /dev/tty || answer=""
  case "$answer" in
    o|O|oui|Oui|OUI|y|Y|yes|YES|Yes) return 0 ;;
    n|N|non|Non|NON|no|NO|No) return 1 ;;
    "") [ "$default" = "yes" ] ;;
    *) return 1 ;;
  esac
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "commande manquante: $1"
}

find_docker() {
  if command -v docker >/dev/null 2>&1; then
    return 0
  fi
  for docker_bin in /usr/bin/docker /usr/local/bin/docker /snap/bin/docker /Applications/Docker.app/Contents/Resources/bin/docker; do
    if [ -x "$docker_bin" ]; then
      docker_dir="$(dirname "$docker_bin")"
      PATH="$docker_dir:$PATH"
      export PATH
      return 0
    fi
  done
  return 1
}

persist_path_dir() {
  path_dir="$1"
  case ":$PATH:" in
    *":$path_dir:"*) ;;
    *) PATH="$path_dir:$PATH"; export PATH ;;
  esac
  if [ -n "${HOME:-}" ] && [ -w "$HOME" ]; then
    profile="$HOME/.profile"
    line="export PATH=\"$path_dir:\$PATH\""
    if [ ! -f "$profile" ] || ! grep -F "$line" "$profile" >/dev/null 2>&1; then
      printf '\n# Libre Claude / Docker\n%s\n' "$line" >> "$profile" 2>/dev/null || true
    fi
  fi
}

install_docker_linux() {
  need_cmd curl
  if [ "$(id -u)" -eq 0 ]; then
    sudo_cmd=""
  else
    need_cmd sudo
    sudo_cmd="sudo"
  fi

  yellow "Installation de Docker Engine avec le script officiel Docker..."
  curl -fsSL https://get.docker.com -o /tmp/libre-claude-get-docker.sh
  $sudo_cmd sh /tmp/libre-claude-get-docker.sh

  if command -v systemctl >/dev/null 2>&1; then
    $sudo_cmd systemctl enable --now docker >/dev/null 2>&1 || true
  fi
  if command -v usermod >/dev/null 2>&1 && [ -n "${USER:-}" ] && [ "$(id -u)" -ne 0 ]; then
    $sudo_cmd usermod -aG docker "$USER" >/dev/null 2>&1 || true
    yellow "Votre utilisateur a été ajouté au groupe docker. Une reconnexion peut être nécessaire."
  fi
  persist_path_dir "/usr/bin"
}

install_docker_macos() {
  if command -v brew >/dev/null 2>&1; then
    yellow "Installation de Docker Desktop avec Homebrew..."
    brew install --cask docker
    persist_path_dir "/Applications/Docker.app/Contents/Resources/bin"
    open -a Docker >/dev/null 2>&1 || true
    yellow "Docker Desktop vient d'être lancé. Attendez qu'il soit prêt, puis relancez ce script si nécessaire."
    return 0
  fi
  die "Docker Desktop est requis sur macOS. Installez Homebrew ou Docker Desktop: https://docs.docker.com/desktop/setup/install/mac-install/"
}

install_docker() {
  os_name="$(uname -s 2>/dev/null || printf unknown)"
  case "$os_name" in
    Linux) install_docker_linux ;;
    Darwin) install_docker_macos ;;
    *) die "installation Docker automatique non supportée pour $os_name" ;;
  esac
}

ensure_docker_available() {
  if find_docker; then
    return 0
  fi
  yellow "Docker est absent ou introuvable dans le PATH."
  if [ "$INSTALL_DOCKER" = "1" ] && ask_yes_no "Installer Docker automatiquement maintenant ?" "yes"; then
    install_docker
    find_docker || die "Docker a été installé mais reste introuvable dans le PATH. Ouvrez un nouveau terminal puis relancez ce script."
    return 0
  fi
  die "Docker est requis. Installez Docker ou relancez avec --install-docker."
}

compose_cmd() {
  if docker compose version >/dev/null 2>&1; then
    printf 'docker compose'
    return 0
  fi
  if command -v docker-compose >/dev/null 2>&1; then
    printf 'docker-compose'
    return 0
  fi
  return 1
}

validate_port() {
  case "$1" in
    ''|*[!0-9]*) die "port invalide: $1" ;;
  esac
  [ "$1" -ge 1 ] && [ "$1" -le 65535 ] || die "port hors limites: $1"
}

bold "$APP_NAME - installation"

if has_tty; then
  yellow "Assistant interactif. Appuyez sur Entrée pour garder une valeur par défaut."
  INSTALL_DIR="$(ask_value "Dossier d'installation" "$INSTALL_DIR")"
  PORT="$(ask_value "Port web local" "$PORT")"
  IMAGE="$(ask_value "Image Docker" "$IMAGE")"
  PUBLIC_URL_VALUE="$(ask_value "URL publique de l'app" "$PUBLIC_URL_VALUE")"
  if ask_yes_no "Configurer GitHub OAuth maintenant ?" "no"; then
    CLIENT_ID="$(ask_value "GitHub OAuth Client ID" "$CLIENT_ID")"
    CLIENT_SECRET="$(ask_secret "GitHub OAuth Client Secret" "$CLIENT_SECRET")"
  fi
  if ask_yes_no "Lancer Libre Claude après l'installation ?" "yes"; then
    NO_START=0
  else
    NO_START=1
  fi
else
  yellow "Mode non-interactif: utilisation des variables d'environnement et valeurs par défaut."
fi

validate_port "$PORT"

cat <<EOF

Configuration:
  Dossier : $INSTALL_DIR
  Port    : $PORT
  Image   : $IMAGE
  URL     : $(if [ -n "$PUBLIC_URL_VALUE" ]; then printf '%s' "$PUBLIC_URL_VALUE"; else printf 'auto'; fi)
  OAuth   : $(if [ -n "$CLIENT_ID" ] && [ -n "$CLIENT_SECRET" ]; then printf 'activé'; else printf 'désactivé'; fi)
  Lancer  : $(if [ "$NO_START" = "1" ]; then printf 'non'; else printf 'oui'; fi)
EOF

if [ "$DRY_RUN" = "1" ]; then
  green "Dry-run terminé. Aucun fichier écrit."
  exit 0
fi

if ! ask_yes_no "Continuer ?" "yes"; then
  yellow "Installation annulée."
  exit 0
fi

if [ -e "$INSTALL_DIR" ] && [ ! -d "$INSTALL_DIR" ]; then
  die "$INSTALL_DIR existe mais n'est pas un dossier"
fi

if [ -d "$INSTALL_DIR" ] && [ -f "$INSTALL_DIR/docker-compose.yml" ]; then
  yellow "Installation existante détectée dans $INSTALL_DIR."
  if ! ask_yes_no "Mettre à jour les fichiers de lancement ?" "yes"; then
    yellow "Aucun changement effectué."
    exit 0
  fi
fi

mkdir -p "$INSTALL_DIR/data" "$INSTALL_DIR/sandbox"

cat > "$INSTALL_DIR/.env" <<EOF
PUBLIC_URL=$PUBLIC_URL_VALUE
GITHUB_OAUTH_CLIENT_ID=$CLIENT_ID
GITHUB_OAUTH_CLIENT_SECRET=$CLIENT_SECRET
GITHUB_OAUTH_SCOPE=$CLIENT_SCOPE
EOF

cat > "$INSTALL_DIR/docker-compose.yml" <<EOF
services:
  libre-claude:
    image: $IMAGE
    ports:
      - "$PORT:80"
    env_file:
      - .env
    environment:
      PUBLIC_URL: \${PUBLIC_URL:-}
      GITHUB_OAUTH_CLIENT_ID: \${GITHUB_OAUTH_CLIENT_ID:-}
      GITHUB_OAUTH_CLIENT_SECRET: \${GITHUB_OAUTH_CLIENT_SECRET:-}
      GITHUB_OAUTH_SCOPE: \${GITHUB_OAUTH_SCOPE:-}
    volumes:
      - ./data:/var/www/html/data
      - ./sandbox:/var/www/html/sandbox
    restart: unless-stopped
EOF

green "Fichiers créés:"
printf '  %s\n' "$INSTALL_DIR/docker-compose.yml" "$INSTALL_DIR/.env" "$INSTALL_DIR/data" "$INSTALL_DIR/sandbox"

if [ "$NO_START" = "1" ]; then
  cat <<EOF

Installation préparée sans lancement.

Pour démarrer:
  cd "$INSTALL_DIR"
  docker compose up -d
EOF
  exit 0
fi

ensure_docker_available
COMPOSE="$(compose_cmd)" || die "Docker Compose est requis. Installez le plugin Docker Compose."

if ! docker info >/dev/null 2>&1; then
  cat <<EOF
$(red "Docker est installé mais inaccessible.")

Essayez:
  sudo systemctl start docker

Si c'est un problème de permissions:
  sudo usermod -aG docker \$USER
  newgrp docker
EOF
  exit 1
fi

cd "$INSTALL_DIR"

green "Téléchargement de l'image..."
$COMPOSE pull

green "Démarrage de Libre Claude..."
$COMPOSE up -d

cat <<EOF

Libre Claude est installé.

Ouvrir:
  http://127.0.0.1:$PORT

Commandes utiles:
  cd "$INSTALL_DIR"
  $COMPOSE ps
  $COMPOSE logs -f
  $COMPOSE pull && $COMPOSE up -d
  $COMPOSE down

GitHub OAuth:
  Éditez $INSTALL_DIR/.env
  Puis relancez: $COMPOSE up -d
EOF
