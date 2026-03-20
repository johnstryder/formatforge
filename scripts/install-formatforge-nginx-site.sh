#!/bin/sh
# Install FormatForge nginx vhost on Ubuntu/Debian (one shot — avoids broken multi-line cp).
# Run from repo root:  sudo ./scripts/install-formatforge-nginx-site.sh
set -e
if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
AVAIL="/etc/nginx/sites-available/formatforgeplus"
ENAB="/etc/nginx/sites-enabled/formatforgeplus"
SRC="$ROOT/nginx/formatforgeplus.conf"

if [ ! -f "$SRC" ]; then
  echo "Missing $SRC — run from your formatforge clone." >&2
  exit 1
fi

echo "Repo: $ROOT"
mkdir -p /var/www
ln -sfn "$ROOT" /var/www/formatforge

if id www-data >/dev/null 2>&1; then
  if sudo -u www-data test -r "$ROOT/index.php"; then
    echo "OK: www-data can read $ROOT/index.php (symlink + directory permissions)."
  else
    echo "!!! www-data CANNOT read $ROOT/index.php — PHP-FPM often returns: File not found." >&2
    echo "    Repo under \$HOME? Allow traverse for \"others\" on each path segment, e.g.:" >&2
    echo "    sudo chmod o+x \"$(dirname "$ROOT")\"   # e.g. /home/jhs" >&2
    echo "    sudo chmod o+x /home   # only if /home is not world-executable" >&2
  fi
fi

rm -f /etc/nginx/sites-enabled/default
cp -a "$SRC" "$AVAIL"   # one cp, explicit dest — do not break this across shell lines
ln -sfn "$AVAIL" "$ENAB"

echo "sites-enabled:"
ls -la /etc/nginx/sites-enabled/

if [ -x "$ROOT/scripts/align-php-fpm-socket.sh" ]; then
  echo "Updating fastcgi socket map..."
  if ! sh "$ROOT/scripts/align-php-fpm-socket.sh"; then
    echo "WARN: align-php-fpm-socket.sh failed (php-fpm not running?). Fix with:" >&2
    echo "  sudo systemctl enable --now php8.3-fpm   # or php8.2-fpm" >&2
    echo "  (cd $ROOT && ./scripts/align-php-fpm-socket.sh && sudo nginx -t && sudo systemctl reload nginx)" >&2
  fi
fi

nginx -t
systemctl enable nginx 2>/dev/null || true
if ! systemctl is-active --quiet nginx; then
  if ! systemctl start nginx; then
    echo "nginx failed to start. Recent logs:" >&2
    journalctl -u nginx -n 25 --no-pager >&2 || true
    exit 1
  fi
else
  systemctl reload nginx || systemctl restart nginx
fi

HOST="${FORMATFORGE_SERVER_NAME:-formatforgeplus.com}"
echo "--- verify (URL must stay on same line as curl) ---"
set +e
code="$(curl -sS --max-time 15 -o /tmp/ff-install-verify.$$ -w '%{http_code}' -H "Host: $HOST" http://127.0.0.1/)"
cret=$?
set -e
if [ "$cret" -ne 0 ]; then code="(curl exit $cret)"; fi
echo "curl HTTP status (Host: $HOST): $code"
head -8 /tmp/ff-install-verify.$$ 2>/dev/null || true
rm -f /tmp/ff-install-verify.$$
echo "Done."
