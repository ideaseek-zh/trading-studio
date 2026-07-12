#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

if [[ ! -f "${ROOT_DIR}/.env" ]]; then
  cp "${ROOT_DIR}/.env.example" "${ROOT_DIR}/.env"
fi

set -a
source "${ROOT_DIR}/.env"
set +a

echo "[1/3] Installing Laravel dependencies..."
cd "${ROOT_DIR}/apps/api"
composer install

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

php artisan key:generate --force

echo "[2/3] Installing Nuxt dependencies..."
cd "${ROOT_DIR}/apps/web"
npm install

echo "[3/3] Creating Python virtualenv and installing FastAPI dependencies..."
cd "${ROOT_DIR}/services/intelligence"
python3 -m venv .venv
source .venv/bin/activate
python -m pip install --upgrade pip
python -m pip install -e .

echo "Bootstrap completed."
