#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

set -a
source "${ROOT_DIR}/.env"
set +a

cd "${ROOT_DIR}/apps/web"

export NUXT_PUBLIC_APP_NAME="${WEB_NUXT_PUBLIC_APP_NAME:-${APP_NAME:-Trading Studio}}"
export NUXT_PUBLIC_API_BASE="${WEB_NUXT_PUBLIC_API_BASE:-/api/v1}"
export NUXT_API_SERVER_BASE="${WEB_NUXT_API_SERVER_BASE:-${API_APP_URL:-http://127.0.0.1:8000}/api/v1}"
export HOST="${WEB_HOST:-127.0.0.1}"
export PORT="${WEB_PORT:-3000}"
export NITRO_HOST="${HOST}"
export NITRO_PORT="${PORT}"

if [[ "${WEB_MODE:-dev}" == "production" ]]; then
  if [[ ! -f ".output/server/index.mjs" ]]; then
    npm run build
  fi

  exec node .output/server/index.mjs
fi

npm run dev -- --host "${HOST}" --port "${PORT}"
