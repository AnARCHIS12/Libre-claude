#!/bin/sh
set -eu

APP_NAME="Libre Claude"
DEFAULT_DIR="$HOME/libre-claude"
INSTALL_DIR="${LIBRE_CLAUDE_DIR:-$DEFAULT_DIR}"
IMAGE="${LIBRE_CLAUDE_IMAGE:-liberchat/libre-claude:latest}"
PORT="${LIBRE_CLAUDE_PORT:-8173}"
CLIENT_ID="${GITHUB_OAUTH_CLIENT_ID:-}"
CLIENT_SECRET="${GITHUB_OAUTH_CLIENT_SECRET:-}"
YES="${LIBRE_CLAUDE_YES:-0}"

red() { printf '\033[31m%s\033[0m\n' "$*"; }
green() { printf '\033[32m%s\033[0m\n' "$*"; }
yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
bold() { printf '\033[1m%s\033[0m\n' "$*"; }

usage() {
  cat <<EOF
Libre Claude installer

Usage:
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh | sh

Options via environment variables:
  LIBRE_CLAUDE_DIR=/opt/libre-claude
  LIBRE_CLAUDE_PORT=8173
  LIBRE_CLAUDE_IMAGE=liberchat/libre-claude:latest
  GITHUB_OAUTH_CLIENT_ID=...
  GITHUB_OAUTH_CLIENT_SECRET=...
  LIBRE_CLAUDE_YES=1

Example:
  curl -fsSL https://raw.githubusercontent.com/AnARCHIS12/Libre-claude/main/install.sh \\
    | LIBRE_CLAUDE_PORT=8080 sh
EOF
}

confirm() {
  if [ "$YES" = "1" ]; then
    return 0
  fi
  if [ ! -r /dev/tty ]; then
    yellow "No interactive terminal detected. Set LIBRE_CLAUDE_YES=1 to run non-interactively."
    return 1
  fi
  printf "%s [Y/n] " "$1"
  read ans < /dev/tty || ans=""
  case "$ans" in
    ""|y|Y|yes|YES|Yes) return 0 ;;
    *) return 1 ;;
  esac
}

need_cmd() {
  if ! command -v "$1" >/dev/null 2>&1; then
    red "Missing command: $1"
    return 1
  fi
}

compose_cmd() {
  if docker compose version >/dev/null 2>&1; then
    printf "docker compose"
    return 0
  fi
  if command -v docker-compose >/dev/null 2>&1; then
    printf "docker-compose"
    return 0
  fi
  return 1
}

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ]; then
  usage
  exit 0
fi

bold "Libre Claude installer"
printf "Image: %s\n" "$IMAGE"
printf "Install dir: %s\n" "$INSTALL_DIR"
printf "Port: %s\n\n" "$PORT"

need_cmd docker || {
  cat <<EOF

Docker is required.
Install Docker first:
  https://docs.docker.com/engine/install/

Then run this installer again.
EOF
  exit 1
}

COMPOSE="$(compose_cmd)" || {
  red "Docker Compose is required."
  cat <<EOF

Install the Docker Compose plugin:
  https://docs.docker.com/compose/install/
EOF
  exit 1
}

if ! docker info >/dev/null 2>&1; then
  red "Docker is installed but not running, or your user cannot access Docker."
  cat <<EOF

Try:
  sudo systemctl start docker

If permissions fail:
  sudo usermod -aG docker \$USER
  newgrp docker
EOF
  exit 1
fi

if [ -e "$INSTALL_DIR" ] && [ ! -d "$INSTALL_DIR" ]; then
  red "$INSTALL_DIR exists and is not a directory."
  exit 1
fi

if [ -d "$INSTALL_DIR" ]; then
  yellow "Directory already exists: $INSTALL_DIR"
  confirm "Continue and update this installation?" || exit 0
fi

mkdir -p "$INSTALL_DIR/data" "$INSTALL_DIR/sandbox"

cat > "$INSTALL_DIR/.env" <<EOF
GITHUB_OAUTH_CLIENT_ID=$CLIENT_ID
GITHUB_OAUTH_CLIENT_SECRET=$CLIENT_SECRET
EOF

cat > "$INSTALL_DIR/docker-compose.yml" <<EOF
services:
  libre-claude:
    image: $IMAGE
    container_name: libre-claude
    ports:
      - "$PORT:80"
    environment:
      GITHUB_OAUTH_CLIENT_ID: \${GITHUB_OAUTH_CLIENT_ID:-}
      GITHUB_OAUTH_CLIENT_SECRET: \${GITHUB_OAUTH_CLIENT_SECRET:-}
    volumes:
      - ./data:/var/www/html/data
      - ./sandbox:/var/www/html/sandbox
    restart: unless-stopped
EOF

cd "$INSTALL_DIR"

green "Pulling image..."
$COMPOSE pull

green "Starting Libre Claude..."
$COMPOSE up -d

cat <<EOF

Libre Claude is installed.

Open:
  http://127.0.0.1:$PORT

Files:
  $INSTALL_DIR/docker-compose.yml
  $INSTALL_DIR/.env
  $INSTALL_DIR/data

Useful commands:
  cd "$INSTALL_DIR"
  $COMPOSE logs -f
  $COMPOSE pull && $COMPOSE up -d
  $COMPOSE down

GitHub OAuth:
  Edit $INSTALL_DIR/.env
  Set GITHUB_OAUTH_CLIENT_ID and GITHUB_OAUTH_CLIENT_SECRET
  Then run: $COMPOSE up -d
EOF
