#!/usr/bin/env bash
# Invoked as: formatforge-cursor-agent-run /abs/path/to/.cursor-pipeline/prompts/pipeline-….md
# Runs under the deploy user (e.g. jhs) via sudo so the Cursor agent can write index.php and pipelines/.
# Configure FORMATFORGE_ROOT and PHP_CLI in /etc/default/formatforge-cursor-agent (see install script).
set -euo pipefail

if [ "$#" -ne 1 ]; then
  echo "usage: formatforge-cursor-agent-run /path/to/prompt.md" >&2
  exit 1
fi

PROMPT_ARG="$1"

if [ -r /etc/default/formatforge-cursor-agent ]; then
  # shellcheck source=/dev/null
  . /etc/default/formatforge-cursor-agent
fi

if [ -z "${FORMATFORGE_ROOT:-}" ]; then
  echo "formatforge-cursor-agent-run: FORMATFORGE_ROOT is not set (add to /etc/default/formatforge-cursor-agent)" >&2
  exit 1
fi

PHP_CLI="${PHP_CLI:-/usr/bin/php}"

resolve() {
  realpath "$1" 2>/dev/null || true
}

R_ROOT=$(resolve "$FORMATFORGE_ROOT")
if [ -z "$R_ROOT" ] || [ ! -f "$R_ROOT/index.php" ]; then
  echo "formatforge-cursor-agent-run: FORMATFORGE_ROOT is not a FormatForge app root: $FORMATFORGE_ROOT" >&2
  exit 1
fi

R_PROMPT=$(resolve "$PROMPT_ARG")
if [ -z "$R_PROMPT" ] || [ ! -f "$R_PROMPT" ]; then
  echo "formatforge-cursor-agent-run: prompt file not found: $PROMPT_ARG" >&2
  exit 1
fi

ok=0
for sub in ".cursor-pipeline/prompts" "storage/cursor-pipeline/prompts"; do
  base="$R_ROOT/$sub"
  R_BASE=$(resolve "$base" 2>/dev/null || true)
  if [ -n "$R_BASE" ] && [[ "$R_PROMPT" == "$R_BASE"/* ]]; then
    ok=1
    break
  fi
done

if [ "$ok" -ne 1 ]; then
  echo "formatforge-cursor-agent-run: prompt path must lie under .cursor-pipeline/prompts or storage/cursor-pipeline/prompts" >&2
  exit 1
fi

exec "$PHP_CLI" "$R_ROOT/index.php" cursor-agent-run "$R_PROMPT"
