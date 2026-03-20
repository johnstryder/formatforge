#!/bin/sh
# Run ON THE SERVER (SSH) from the FormatForge repo root, e.g. ./scripts/diagnose-502.sh
# (Works from any cwd — resolves paths from this script’s location.)
# Usage: ./scripts/diagnose-502.sh [site_host]
# Example: ./scripts/diagnose-502.sh formatforgeplus.com
set -e
HOST="${1:-formatforgeplus.com}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
echo "Repo root (this clone): $ROOT"
if [ ! -d /var/www/formatforge ]; then
  echo "Note: /var/www/formatforge does not exist — if nginx root/include still says /var/www/formatforge, fix the site config or symlink: sudo ln -sfn $ROOT /var/www/formatforge"
fi

echo "=== nginx ==="
command -v nginx >/dev/null 2>&1 && nginx -v 2>&1 || echo "(nginx binary not in PATH — try sudo)"
systemctl is-active nginx 2>/dev/null || true
if [ -d /etc/nginx/sites-enabled ]; then
  echo "sites-enabled:"
  ls -la /etc/nginx/sites-enabled/ 2>/dev/null || true
  if [ -e /etc/nginx/sites-enabled/formatforgeplus ] || [ -L /etc/nginx/sites-enabled/formatforgeplus ]; then
    echo "(formatforgeplus site is linked)"
  else
    echo "WARNING: no sites-enabled/formatforgeplus — install per DEPLOYMENT.md §5"
  fi
fi

echo "=== php-fpm (common unit names) ==="
for u in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
  systemctl is-active "$u" >/dev/null 2>&1 && echo "$u: active" || true
done

echo "=== sockets under /run/php ==="
if ls /run/php/*.sock 2>/dev/null; then
  :
else
  echo "(none — start php-fpm, e.g. sudo systemctl start php8.3-fpm)"
  if ! dpkg -l 2>/dev/null | grep -qE '^ii[[:space:]]+php[0-9.]*-fpm'; then
    echo "!!! No php*-fpm package installed — nginx cannot run PHP. Install: sudo apt install -y php-fpm php-cli php-curl php-mbstring php-xml php-zip"
  fi
fi

echo "=== this tree: nginx/fastcgi-pass.conf ==="
if [ -f "$ROOT/nginx/fastcgi-pass.conf" ]; then
  cat "$ROOT/nginx/fastcgi-pass.conf"
else
  echo "(missing $ROOT/nginx/fastcgi-pass.conf)"
fi

echo "=== curl origin (nginx on this machine) ==="
curl -sS -o /dev/null -w "HTTP %{http_code}\n" --max-time 5 -H "Host: $HOST" http://127.0.0.1/ || echo "curl failed"

echo "=== last nginx errors (if readable) ==="
if [ -r /var/log/nginx/error.log ]; then
  tail -n 20 /var/log/nginx/error.log
else
  echo "sudo tail -40 /var/log/nginx/error.log"
fi
