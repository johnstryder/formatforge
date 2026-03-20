#!/bin/sh
# Run ON THE SERVER (SSH). Explains who answers HTTP and why you might see a "default" page.
# Usage: ./scripts/diagnose-web-front.sh [Host_header]
# Example: ./scripts/diagnose-web-front.sh formatforgeplus.com
HOST="${1:-formatforgeplus.com}"

echo "=== Processes on 80 / 443 ==="
sudo sh -c 'command -v ss >/dev/null && ss -tlnp | grep -E ":(80|443)\s" || true; netstat -tlnp 2>/dev/null | grep -E ":(80|443)\s" || true'

echo ""
echo "=== Any apache left? ==="
ps aux 2>/dev/null | grep -E '[a]pache|[h]ttpd' || echo "(none)"

echo ""
echo "=== nginx sites-enabled ==="
ls -la /etc/nginx/sites-enabled/ 2>/dev/null || echo "(no /etc/nginx/sites-enabled)"

echo ""
echo "=== Which server block is default for :80? (default_server wins for bare IP / unknown Host) ==="
if sudo nginx -T 2>/dev/null | grep -E 'listen.*80' | head -20; then
  :
else
  echo "(run: sudo nginx -T  … if permission denied)"
fi

echo ""
echo "=== Response body — curl by IP, no Host (often hits nginx default → /var/www/html) ==="
curl -sS --max-time 5 http://127.0.0.1/ 2>/dev/null | head -5 || echo "curl failed"

echo ""
echo "=== Response body — curl with Host: $HOST (should be FormatForge if vhost is set up) ==="
curl -sS --max-time 5 -H "Host: $HOST" http://127.0.0.1/ 2>/dev/null | head -5 || echo "curl failed"

echo ""
echo "=== If first block still says Apache but ps shows no apache: purge CDN/browser cache, or you hit another machine ==="
