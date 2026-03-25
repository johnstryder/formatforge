#!/usr/bin/env bash
# Install formatforge-cursor-agent-run wrapper + /etc/default config; print sudoers reminder.
# Run on the FormatForge host as root (or with sudo). Repo root = parent of scripts/.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WRAPPER_SRC="$ROOT/scripts/formatforge-cursor-agent-run.sh"
WRAPPER_DST="${CURSOR_AGENT_WRAPPER_INSTALL_PATH:-/usr/local/sbin/formatforge-cursor-agent-run}"
DEFAULT_DST="/etc/default/formatforge-cursor-agent"
RUNAS="${FORMATFORGE_CURSOR_SUDO_RUNAS:-jhs}"
FPM_USER="${FORMATFORGE_PHP_FPM_USER:-www-data}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

if [ ! -f "$WRAPPER_SRC" ]; then
  echo "Missing $WRAPPER_SRC" >&2
  exit 1
fi

install -m 0755 -o root -g root "$WRAPPER_SRC" "$WRAPPER_DST"

if [ ! -f "$DEFAULT_DST" ]; then
  echo "Creating $DEFAULT_DST"
  {
    echo "# FormatForge Cursor agent (deploy user runs cursor-agent-run)"
    echo "FORMATFORGE_ROOT=$ROOT"
    echo "PHP_CLI=${PHP_CLI:-/usr/bin/php}"
  } >"$DEFAULT_DST"
  chmod 0644 "$DEFAULT_DST"
else
  echo "Leaving existing $DEFAULT_DST (edit FORMATFORGE_ROOT / PHP_CLI if needed)"
fi

echo ""
echo "Installed wrapper: $WRAPPER_DST"
echo "Config: $DEFAULT_DST"
echo ""
echo "=== sudoers (install manually after review) ==="
echo "Defaults:$FPM_USER !requiretty"
echo ""
echo "$FPM_USER ALL=($RUNAS) NOPASSWD: $WRAPPER_DST"
echo ""
echo "Example file: $ROOT/scripts/formatforge-cursor-agent.sudoers"
echo "  sudo cp $ROOT/scripts/formatforge-cursor-agent.sudoers /etc/sudoers.d/formatforge-cursor-agent"
echo "  sudo sed -i 's/www-data/$FPM_USER/g; s/jhs/$RUNAS/g' /etc/sudoers.d/formatforge-cursor-agent   # if defaults differ"
echo "  sudo chmod 440 /etc/sudoers.d/formatforge-cursor-agent && sudo visudo -cf /etc/sudoers.d/formatforge-cursor-agent"
echo ""
echo "App .env:"
echo "  CURSOR_AGENT_SUDO_USER=$RUNAS"
echo "  CURSOR_AGENT_RUN_WRAPPER=$WRAPPER_DST"
echo ""
echo "Policy: pipeline Cursor agent edits only pipelines/<subdir>/ (not index.php). Sudo is for permissions/PATH, not app-core edits."
echo ""
echo "Permissions: add $RUNAS to group $FPM_USER and use shared ownership on the cursor runtime tree, e.g.:"
echo "  sudo usermod -aG $FPM_USER $RUNAS"
echo "  sudo chown -R $RUNAS:$FPM_USER $ROOT/.cursor-pipeline $ROOT/storage/cursor-pipeline 2>/dev/null || true"
echo "  sudo chmod -R u+rwX,g+rwX $ROOT/.cursor-pipeline $ROOT/storage/cursor-pipeline 2>/dev/null || true"
echo "  sudo chmod g+s $ROOT/.cursor-pipeline/triggers $ROOT/.cursor-pipeline/prompts $ROOT/.cursor-pipeline/runs 2>/dev/null || true"
echo ""
echo "Verify: sudo -u $FPM_USER sudo -n -u $RUNAS -- $WRAPPER_DST $ROOT/.cursor-pipeline/prompts/*.md  (use a real prompt path; may no-op if glob empty)"
