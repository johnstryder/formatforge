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
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables/content" >/dev/null && CONTENT_EXISTS=1 || CONTENT_EXISTS=0
else
  curl -sf "$URL/api/v1/tables/content" >/dev/null && CONTENT_EXISTS=1 || CONTENT_EXISTS=0
fi
if [ -n "$key" ]; then
  curl -sf -H "Authorization: Bearer $key" "$URL/api/v1/tables/pipeline_refs" >/dev/null && REFS_EXISTS=1 || REFS_EXISTS=0
else
  curl -sf "$URL/api/v1/tables/pipeline_refs" >/dev/null && REFS_EXISTS=1 || REFS_EXISTS=0
fi
if [ "$CONTENT_EXISTS" = 1 ] && [ "$REFS_EXISTS" = 1 ]; then
  echo "Tables 'content' and 'pipeline_refs' already exist."
  exit 0
fi

PY="$(dirname "$0")/build_antfly_content_table_body.py"
if [ "$CONTENT_EXISTS" != 1 ]; then
  echo "Creating table 'content' (full-text + semantic template with remoteMedia + OpenRouter)..."
  BODY=$(python3 "$PY" content)
  if [ -n "$key" ]; then
    curl -sf -X POST "$URL/api/v1/tables/content" -H "Authorization: Bearer $key" -H "Content-Type: application/json" -d "$BODY" >/dev/null
  else
    curl -sf -X POST "$URL/api/v1/tables/content" -H "Content-Type: application/json" -d "$BODY" >/dev/null
  fi
  echo "Table 'content' created."
fi
if [ "$REFS_EXISTS" != 1 ]; then
  echo "Creating table 'pipeline_refs' (active pipeline templates for semantic novelty)..."
  BODY=$(python3 "$PY" pipeline_refs)
  if [ -n "$key" ]; then
    curl -sf -X POST "$URL/api/v1/tables/pipeline_refs" -H "Authorization: Bearer $key" -H "Content-Type: application/json" -d "$BODY" >/dev/null
  else
    curl -sf -X POST "$URL/api/v1/tables/pipeline_refs" -H "Content-Type: application/json" -d "$BODY" >/dev/null
  fi
  echo "Table 'pipeline_refs' created."
fi
