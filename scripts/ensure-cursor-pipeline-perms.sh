#!/usr/bin/env bash
# Make .cursor-pipeline/ writable by PHP-FPM (usually www-data) so fetch/reject can write
# trigger_*.json, pipeline-trace.jsonl, and create prompts/. Run from repo root on the server.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

OWNER="${FORMATFORGE_CURSOR_PIPELINE_OWNER:-www-data}"
GROUP="${FORMATFORGE_CURSOR_PIPELINE_GROUP:-www-data}"

echo "Repo: $ROOT"
echo "Target owner:group = ${OWNER}:${GROUP}"
sudo mkdir -p .cursor-pipeline/triggers .cursor-pipeline/prompts storage/cursor-pipeline/triggers storage/cursor-pipeline/prompts
sudo chown -R "${OWNER}:${GROUP}" .cursor-pipeline storage/cursor-pipeline
sudo chmod -R u+rwX,g+rwX .cursor-pipeline storage/cursor-pipeline
# Allow new files in triggers/prompts if umask is strict
sudo chmod g+s .cursor-pipeline/triggers .cursor-pipeline/prompts storage/cursor-pipeline/triggers storage/cursor-pipeline/prompts 2>/dev/null || true

echo "Done. Verify: sudo -u ${OWNER} test -w .cursor-pipeline/triggers && echo OK"
echo "       (or if using storage fallback) sudo -u ${OWNER} test -w storage/cursor-pipeline/triggers && echo OK"
echo "Reload: sudo systemctl reload php8.3-fpm   # adjust version"
