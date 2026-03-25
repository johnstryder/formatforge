#!/bin/sh
# Install system cron: every hour (minute 0), run php index.php pipeline-cron-tick as www-data.
# Requires root. Loads .env from the app tree (PIPELINE_CRON_ENABLED=1, ADMIN_* for superuser).
#
#   sudo ./scripts/install-formatforge-pipeline-cron.sh
#
# Uninstall: sudo rm -f /etc/cron.d/formatforge-pipeline-cron /usr/local/sbin/formatforge-pipeline-cron
set -e
if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
if [ ! -f "$ROOT/index.php" ]; then
  echo "Missing $ROOT/index.php — run from your formatforge clone." >&2
  exit 1
fi

WRAPPER_DST="/usr/local/sbin/formatforge-pipeline-cron"
CRON_DST="/etc/cron.d/formatforge-pipeline-cron"
LOG_FILE="/var/log/formatforge-pipeline-cron.log"
EXAMPLE="$ROOT/scripts/formatforge-pipeline-cron-run.sh.example"

if [ ! -f "$EXAMPLE" ]; then
  echo "Missing $EXAMPLE" >&2
  exit 1
fi

sed "s|REPLACE_WITH_ABSOLUTE_REPO_PATH|$ROOT|g" "$EXAMPLE" >"$WRAPPER_DST.tmp"
mv "$WRAPPER_DST.tmp" "$WRAPPER_DST"
chmod 755 "$WRAPPER_DST"
echo "Installed $WRAPPER_DST (cd $ROOT)"

touch "$LOG_FILE"
if id www-data >/dev/null 2>&1; then
  chown www-data:www-data "$LOG_FILE" 2>/dev/null || chown www-data:adm "$LOG_FILE" 2>/dev/null || true
  chmod 644 "$LOG_FILE"
fi

cat >"$CRON_DST" <<'CRONEOF'
# FormatForge — pipeline cadence tick (php index.php pipeline-cron-tick)
# m h dom mon dow user command
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

0 * * * * www-data /usr/local/sbin/formatforge-pipeline-cron
CRONEOF

chmod 644 "$CRON_DST"
echo "Installed $CRON_DST (hourly at :00 as www-data)"

if id www-data >/dev/null 2>&1; then
  if sudo -u www-data test -r "$ROOT/index.php"; then
    echo "OK: www-data can read $ROOT/index.php"
  else
    echo "WARNING: www-data cannot read $ROOT/index.php — fix permissions or cron will fail." >&2
  fi
fi

echo ""
echo "Next: add to $ROOT/.env (or merge):"
echo "  PIPELINE_CRON_ENABLED=1"
echo "  ADMIN_EMAIL=..."
echo "  ADMIN_PASSWORD=..."
echo "(ADMIN_* must match PocketBase superuser — same as other CLI tools.)"
