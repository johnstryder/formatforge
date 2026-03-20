#!/bin/sh
# Create FormatForge content table in Antfly (run after Antfly is up)
# Usage: ./scripts/init-antfly.sh [ANTFLY_URL]
set -e
cd "$(dirname "$0")/.."
URL="$1"
[ -z "$URL" ] && [ -f .antfly-port ] && URL="http://127.0.0.1:$(cat .antfly-port)"
[ -z "$URL" ] && [ -f .env ] && URL=$(grep -E '^ANTFLY_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d ' "')
URL="${URL:-http://127.0.0.1:8080}"
URL="${URL%/}"

key=""
[ -f .env ] && key=$(grep -E '^ANTFLY_API_KEY=' .env 2>/dev/null | cut -d= -f2- | tr -d ' "')

echo "Checking Antfly at $URL..."
if [ -n "$key" ]; then
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables" >/dev/null || { echo "Antfly not reachable at $URL (set ANTFLY_URL or start Antfly)."; exit 1; }
else
  curl -sf "$URL/api/v1/tables" >/dev/null || { echo "Antfly not reachable at $URL (set ANTFLY_URL or start Antfly)."; exit 1; }
fi

if [ -n "$key" ]; then
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables/content" >/dev/null && { echo "Table 'content' already exists."; exit 0; }
else
  curl -sf "$URL/api/v1/tables/content" >/dev/null && { echo "Table 'content' already exists."; exit 0; }
fi

echo "Creating table 'content' (full-text + OpenRouter embeddings on field prompt)..."
BODY=$(python3 "$(dirname "$0")/build_antfly_content_table_body.py")
if [ -n "$key" ]; then
  curl -sf -X POST "$URL/api/v1/tables/content" -H "Authorization: Bearer $key" -H "Content-Type: application/json" -d "$BODY" >/dev/null
else
  curl -sf -X POST "$URL/api/v1/tables/content" -H "Content-Type: application/json" -d "$BODY" >/dev/null
fi
echo "Table 'content' created."
