#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

set -a
source "${ROOT_DIR}/.env"
set +a

cd "${ROOT_DIR}/apps/api"

php artisan schedule:work
