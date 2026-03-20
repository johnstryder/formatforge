#!/bin/sh
# Local smoke test — avoids fragile multi-line curl copy-paste.
# Usage: ./scripts/curl-local-formatforge.sh [Host]
HOST="${1:-formatforgeplus.com}"
set +e
code="$(curl -sS --max-time 15 -o /tmp/ff-curl-loc.$$ -w '%{http_code}' -H "Host: $HOST" http://127.0.0.1/)"
cret=$?
set -e
printf 'HTTP %s\n' "$code"
head -12 /tmp/ff-curl-loc.$$ 2>/dev/null || true
rm -f /tmp/ff-curl-loc.$$
if [ "$cret" -ne 0 ]; then exit "$cret"; fi
