#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

set -a
source "${ROOT_DIR}/.env"
set +a

cd "${ROOT_DIR}/apps/web"

npm run dev -- --host 127.0.0.1 --port "${WEB_PORT:-3000}"
