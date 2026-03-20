#!/usr/bin/env bash
# Mirror the latest local Cursor CLI bundle into /opt/cursor-agent so php-fpm (www-data) can run it.
# Typical: Cursor updates ~/.local/share/cursor-agent/versions/<ver>/; this copies the newest dir to /opt.
#
# Usage (as deploy user jhs, needs sudo for /opt unless root):
#   ./scripts/sync-cursor-agent-to-opt.sh
#   ./scripts/sync-cursor-agent-to-opt.sh --dry-run
# Cron as root: scripts/cursor-agent-sync.cron.example + cursor-agent-sync-root-invoke.sh.example
# (wrapper keeps the crontab line short — one physical line required in /etc/cron.d).
#
# Env:
#   CURSOR_AGENT_VERSIONS_DIR  — override versions root (default: $HOME/.local/share/cursor-agent/versions)
#   CURSOR_AGENT_OPT_DIR         — default /opt/cursor-agent
#   CURSOR_AGENT_SOURCE_USER     — when running as root (e.g. systemd): Unix user whose ~/.local/share/... to read (e.g. jhs)
#   CURSOR_AGENT_SYNC_RELOAD_PHP — set to 1 to reload an active php*-fpm unit after sync
#
set -euo pipefail

OPT_DIR="${CURSOR_AGENT_OPT_DIR:-/opt/cursor-agent}"
DRY_RUN=0
RELOAD_PHP="${CURSOR_AGENT_SYNC_RELOAD_PHP:-0}"

while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run) DRY_RUN=1; shift ;;
    --reload-php-fpm) RELOAD_PHP=1; shift ;;
    --systemd) :; shift ;; # reserved for docs; no special behavior
    -h|--help)
      head -n 20 "$0" | tail -n +2
      exit 0
      ;;
    *)
      echo "Unknown option: $1" >&2
      exit 1
      ;;
  esac
done

home_of() {
  getent passwd "$1" | cut -d: -f6
}

if [ -n "${CURSOR_AGENT_VERSIONS_DIR:-}" ]; then
  VERSIONS_ROOT="$CURSOR_AGENT_VERSIONS_DIR"
elif [ "$(id -u)" -eq 0 ] && [ -n "${CURSOR_AGENT_SOURCE_USER:-}" ]; then
  VERSIONS_ROOT="$(home_of "$CURSOR_AGENT_SOURCE_USER")/.local/share/cursor-agent/versions"
else
  VERSIONS_ROOT="${HOME}/.local/share/cursor-agent/versions"
fi

if [ ! -d "$VERSIONS_ROOT" ]; then
  echo "sync-cursor-agent-to-opt: missing versions dir: $VERSIONS_ROOT" >&2
  exit 1
fi

# Newest install wins (mtime).
mapfile -t _cands < <(find "$VERSIONS_ROOT" -mindepth 1 -maxdepth 1 -type d \( -name '[!.]*' \) -printf '%T@ %p\n' 2>/dev/null | sort -nr | cut -d' ' -f2-)
if [ ${#_cands[@]} -eq 0 ] || [ -z "${_cands[0]:-}" ]; then
  echo "sync-cursor-agent-to-opt: no version subdirs in $VERSIONS_ROOT" >&2
  exit 1
fi
# Normalize path: mapfile/pipeline can leave trailing \\r/\\n, which breaks the status line.
IFS= read -r LATEST <<< "${_cands[0]}"
LATEST="${LATEST%$'\r'}"
LATEST="${LATEST%/}"

require_exec() {
  local f="$1"
  if [ ! -f "$f" ] || [ ! -x "$f" ]; then
    echo "sync-cursor-agent-to-opt: expected executable missing: $f" >&2
    exit 1
  fi
}
require_exec "$LATEST/cursor-agent"
require_exec "$LATEST/node"

RSYNC=(rsync -a)
if [ "$DRY_RUN" -eq 1 ]; then
  RSYNC+=(--dry-run --itemize-changes)
else
  RSYNC+=(--delete)
fi

run() {
  if [ "$(id -u)" -eq 0 ]; then
    "$@"
  else
    sudo "$@"
  fi
}

printf 'sync-cursor-agent-to-opt: %s/ -> %s/\n' "$LATEST" "$OPT_DIR"
run mkdir -p "$OPT_DIR"
run "${RSYNC[@]}" "$LATEST/" "$OPT_DIR/"
run chmod -R a+rX "$OPT_DIR"

if [ "$RELOAD_PHP" -eq 1 ]; then
  for u in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    if systemctl is-active --quiet "$u" 2>/dev/null; then
      echo "sync-cursor-agent-to-opt: reloading $u"
      run systemctl reload "$u"
      break
    fi
  done
fi

echo "sync-cursor-agent-to-opt: done"
