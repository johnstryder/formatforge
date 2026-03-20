#!/bin/sh
# Run ON THE SERVER (SSH). Explains who answers HTTP and why you might see a "default" page.
# Usage: ./scripts/diagnose-web-front.sh [Host_header]
# Example: ./scripts/diagnose-web-front.sh formatforgeplus.com
HOST="${1:-formatforgeplus.com}"

echo "=== Processes on 80 / 443 (one line each; full ss lists every nginx worker) ==="
sudo ss -tlnp 2>/dev/null | grep -E ':80\s' | head -1 || true
sudo ss -tlnp 2>/dev/null | grep -E ':443\s' | head -1 || true

echo ""
echo "=== Leftovers from Apache (safe to purge apache2* if you use nginx only) ==="
ps aux 2>/dev/null | grep -E '[h]tcacheclean|[a]pache2|[h]ttpd' || echo "(none obvious)"

echo ""
echo "=== nginx sites-enabled ==="
ls -la /etc/nginx/sites-enabled/ 2>/dev/null || echo "(no /etc/nginx/sites-enabled)"

if [ -L /etc/nginx/sites-enabled/formatforgeplus ] || [ -f /etc/nginx/sites-enabled/formatforgeplus ]; then
  echo "OK: formatforgeplus is enabled."
else
  echo "!!! NO formatforgeplus in sites-enabled — nginx will ONLY serve the stock default site."
  echo "    Fix: sudo rm -f /etc/nginx/sites-enabled/default"
  echo "         sudo cp PATH/TO/formatforge/nginx/formatforgeplus.conf /etc/nginx/sites-available/formatforgeplus"
  echo "         sudo ln -sf /etc/nginx/sites-available/formatforgeplus /etc/nginx/sites-enabled/formatforgeplus"
  echo "         sudo nginx -t && sudo systemctl reload nginx"
fi

echo ""
echo "=== active default_server on :80 (from nginx -T) ==="
sudo nginx -T 2>/dev/null | grep -E '^\s*listen\s+.*80' | head -15 || echo "(sudo nginx -T failed — run with sudo?)"

echo ""
echo "=== curl 127.0.0.1/ (no Host) — first lines ==="
curl -sS --max-time 5 http://127.0.0.1/ 2>/dev/null | head -5 || echo "curl failed"

echo ""
echo "=== curl with Host: $HOST — first lines ==="
curl -sS --max-time 5 -H "Host: $HOST" http://127.0.0.1/ 2>/dev/null | head -5 || echo "curl failed"

echo ""
echo "=== Title sniff (empty = no <title> or HTML) ==="
printf 'no Host:    '; curl -sS --max-time 5 http://127.0.0.1/ 2>/dev/null | tr '\n' ' ' | sed 's/.*<title>\([^<]*\).*/\1/' | head -c 80; echo
printf 'Host %s: ' "$HOST"; curl -sS --max-time 5 -H "Host: $HOST" http://127.0.0.1/ 2>/dev/null | tr '\n' ' ' | sed 's/.*<title>\([^<]*\).*/\1/' | head -c 80; echo
