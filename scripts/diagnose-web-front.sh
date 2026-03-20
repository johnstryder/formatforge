#!/bin/sh
# Run ON THE SERVER (SSH). Explains who answers HTTP and why you might see a "default" page.
#
# Prefer: sudo ./scripts/diagnose-web-front.sh formatforgeplus.com
# (Otherwise sudo may prompt once per command, or fail if tty-less.)
#
# Usage: ./scripts/diagnose-web-front.sh [Host_header]
# Example: ./scripts/diagnose-web-front.sh formatforgeplus.com
HOST="${1:-formatforgeplus.com}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# ss / nginx -T: use sudo when not root
if [ "$(id -u)" -eq 0 ]; then
  DO=""
else
  DO="sudo"
fi

echo "=== Who listens on 80 / 443 (first line each) ==="
# Try without sudo first (often enough); then sudo for process names if still blank.
pick80() { grep -E ':80([^0-9]|$)' | head -1; }
pick443() { grep -E ':443([^0-9]|$)' | head -1; }
line80="$(ss -tlnp 2>/dev/null | pick80 || true)"
line443="$(ss -tlnp 2>/dev/null | pick443 || true)"
if [ -z "$line80" ]; then line80="$($DO ss -tlnp 2>/dev/null | pick80 || true)"; fi
if [ -z "$line443" ]; then line443="$($DO ss -tlnp 2>/dev/null | pick443 || true)"; fi
if [ -n "$line80" ]; then echo "$line80"; else echo "(no TCP :80 LISTEN — is nginx running? systemctl status nginx)"; fi
if [ -n "$line443" ]; then echo "$line443"; else echo "(no :443 — OK if TLS not configured yet)"; fi

echo ""
echo "=== Leftovers from Apache (safe: apt purge apache2*) ==="
ps aux 2>/dev/null | grep -E '[h]tcacheclean|[a]pache2|[h]ttpd' || echo "(none obvious)"

echo ""
echo "=== nginx sites-enabled ==="
if ls /etc/nginx/sites-enabled/ >/dev/null 2>&1; then
  ls -la /etc/nginx/sites-enabled/
else
  echo "(cannot read /etc/nginx/sites-enabled — run with sudo?)"
fi

if [ -L /etc/nginx/sites-enabled/formatforgeplus ] || [ -f /etc/nginx/sites-enabled/formatforgeplus ]; then
  echo "OK: formatforgeplus is enabled."
else
  echo "!!! NO formatforgeplus in sites-enabled — nginx will only use whatever is linked (often default)."
  echo "    Run (adjust paths if your clone is not $ROOT):"
  echo "    sudo \"$ROOT/scripts/install-formatforge-nginx-site.sh\""
  echo "    (one line each if you do it by hand — never break 'cp SRC DEST' across lines)"
fi

echo ""
echo "=== nginx listen lines (nginx -T) ==="
ng="$($DO nginx -T 2>&1)" || true
echo "$ng" | grep -E '^\s*listen\s+.*\b(80|443)\b' | head -20 || echo "(no listen lines — config error? try: sudo nginx -t)"
echo "$ng" | grep -iE 'emerg|alert|crit' | head -5 || true

echo ""
echo "=== curl http://127.0.0.1/ (no Host) — code + body ==="
code0="$(curl -sS --max-time 5 -o /tmp/ff-diag-c0.$$ -w '%{http_code}' http://127.0.0.1/ 2>&1)" || true
echo "result: $code0"
if [ -f /tmp/ff-diag-c0.$$ ]; then head -5 /tmp/ff-diag-c0.$$; else echo "(no body file — connection failed?)"; fi
rm -f /tmp/ff-diag-c0.$$

echo ""
echo "=== curl with Host: $HOST — code + body ==="
code1="$(curl -sS --max-time 5 -o /tmp/ff-diag-c1.$$ -w '%{http_code}' -H "Host: $HOST" http://127.0.0.1/ 2>&1)" || true
echo "result: $code1"
if [ -f /tmp/ff-diag-c1.$$ ]; then head -5 /tmp/ff-diag-c1.$$; else echo "(no body file — connection failed?)"; fi
rm -f /tmp/ff-diag-c1.$$
