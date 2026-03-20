#!/bin/sh
# Writes nginx/fastcgi-pass.conf with the newest php*-fpm.sock under /run/php/.
# Run on the server from the app root, then: sudo nginx -t && sudo systemctl reload nginx
set -e
cd "$(dirname "$0")/.."
CONF="$PWD/nginx/fastcgi-pass.conf"
sock=$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1)
if [ -z "$sock" ]; then
  echo "No socket in /run/php/php*-fpm.sock. Start FPM first, e.g.:" >&2
  echo "  sudo systemctl start php8.3-fpm" >&2
  echo "  sudo systemctl enable php8.3-fpm" >&2
  exit 1
fi
{
  echo "# Auto-updated by scripts/align-php-fpm-socket.sh ($(date -u +%Y-%m-%dT%H:%MZ))"
  echo "fastcgi_pass unix:$sock;"
} >"$CONF.tmp"
mv "$CONF.tmp" "$CONF"
echo "fastcgi_pass unix:$sock; -> nginx/fastcgi-pass.conf"
echo "Next: sudo nginx -t && sudo systemctl reload nginx"
