#!/usr/bin/env bash
# Packe le dossier blueprint prêt pour `blueprint -i` (installer depuis le dossier panel).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT}/dist"
OUT_FILE="${OUT_DIR}/pteromcplugins.blueprint"

mkdir -p "${OUT_DIR}"
rm -f "${OUT_FILE}"

(
  cd "${ROOT}"
  zip -rq "${OUT_FILE}" conf.yml ext/
)

echo "OK  ${OUT_FILE}"
