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
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables" >/dev/null || { echo "Antfly not reachable. Start: docker compose -f docker-compose.antfly.yml up -d"; exit 1; }
else
  curl -sf "$URL/api/v1/tables" >/dev/null || { echo "Antfly not reachable. Start: docker compose -f docker-compose.antfly.yml up -d"; exit 1; }
fi

if [ -n "$key" ]; then
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables/content" >/dev/null && { echo "Table 'content' already exists."; exit 0; }
else
  curl -sf "$URL/api/v1/tables/content" >/dev/null && { echo "Table 'content' already exists."; exit 0; }
fi

echo "Creating table 'content'..."
BODY='{"num_shards":1,"schema":{"document_schemas":{"content":{"schema":{"type":"object","properties":{"id":{"type":"string","x-antfly-types":["keyword"]},"prompt":{"type":"string","x-antfly-types":["text"]},"type":{"type":"string","x-antfly-types":["keyword"]},"status":{"type":"string","x-antfly-types":["keyword"]}},"x-antfly-include-in-all":["prompt"]}},"default_type":"content"},"indexes":{"search_idx":{"type":"full_text"}}}'
if [ -n "$key" ]; then
  curl -sf -X POST "$URL/api/v1/tables/content" -H "Authorization: Bearer $key" -H "Content-Type: application/json" -d "$BODY" >/dev/null
else
  curl -sf -X POST "$URL/api/v1/tables/content" -H "Content-Type: application/json" -d "$BODY" >/dev/null
fi
echo "Table 'content' created."
