#!/bin/zsh
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

set -a
source "${ROOT_DIR}/.env"
set +a

cd "${ROOT_DIR}/services/intelligence"

if [[ ! -d .venv ]]; then
  python3 -m venv .venv
fi

source .venv/bin/activate
python -m pip install -e . >/dev/null
python -m uvicorn app.main:app --host 127.0.0.1 --port "${INTELLIGENCE_PORT:-8080}" --reload
