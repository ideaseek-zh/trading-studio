#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

set -a
source "${ROOT_DIR}/.env"
set +a

cd "${ROOT_DIR}/apps/api"

if [[ ! -f .env ]]; then
  cp .env.example .env
fi

php artisan key:generate --force
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port="${API_PORT:-8000}"
