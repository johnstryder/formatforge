#!/bin/sh
# Run on the production host (SSH). Diagnoses typical Cloudflare/origin 502 causes.
set -e
echo "=== nginx ==="
systemctl is-active nginx 2>/dev/null || echo "nginx: not active"
echo "=== php-fpm units (whichever is active) ==="
for u in php-fpm php8.4-fpm php8.3-fpm php8.2-fpm php8.1-fpm; do
  systemctl is-active "$u" >/dev/null 2>&1 && echo "$u: active"
done
echo "=== sockets ==="
ls -la /run/php/*.sock 2>/dev/null || echo "(none — php-fpm not running?)"
echo "=== curl localhost (HTTP) ==="
curl -sS -o /dev/null -w "code %{http_code}\n" --max-time 5 -H "Host: formatforgeplus.com" http://127.0.0.1/ || echo "curl failed"
echo "=== last nginx errors (need sudo for log) ==="
if [ -r /var/log/nginx/error.log ]; then
  tail -n 25 /var/log/nginx/error.log
else
  echo "Run: sudo tail -25 /var/log/nginx/error.log"
fi
