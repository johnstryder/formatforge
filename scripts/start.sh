#!/bin/sh
# Start PocketBase + PHP built-in server. Ctrl+C to stop.
cd "$(dirname "$0")/.."
PB_NAME="$(basename "$(pwd)")-pb"
[ -x "./$PB_NAME" ] || { echo "Run: ./scripts/download-pocketbase.sh first"; exit 1; }

is_free() { ! ss -tln 2>/dev/null | grep -q ":${1} " && ! netstat -tln 2>/dev/null | grep -q ":${1} "; }

port=""
[ -f .pb-port ] && port=$(cat .pb-port)
[ -z "$port" ] && [ -f .env ] && {
  url=$(grep -E '^POCKETBASE_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d ' "')
  case "$url" in *:[0-9]*) port="${url##*:}"; port="${port%%/*}"; port="${port%%?*}"; ;; esac
}
[ -n "$port" ] && ! is_free "$port" && port=""
[ -z "$port" ] && {
  while true; do
    r=$(od -An -N2 -tu2 /dev/urandom 2>/dev/null | tr -d ' \n') || r=0
    port=$((8090 + (r % 500)))
    is_free "$port" && break
  done
  echo "$port" > .pb-port
  echo "PocketBase port $port (saved to .pb-port)"
}

# PHP app port (check .env, .app-port, or pick random free)
app_port=""
[ -f .app-port ] && app_port=$(cat .app-port)
[ -z "$app_port" ] && [ -f .env ] && {
  p=$(grep -E '^APP_PORT=' .env 2>/dev/null | cut -d= -f2- | tr -d ' "')
  [ -n "$p" ] && app_port="$p"
}
[ -n "$app_port" ] && ! is_free "$app_port" && app_port=""
[ -z "$app_port" ] && {
  while true; do
    r=$(od -An -N2 -tu2 /dev/urandom 2>/dev/null | tr -d ' \n') || r=0
    app_port=$((8000 + (r % 500)))
    is_free "$app_port" && break
  done
  echo "$app_port" > .app-port
}
./"$PB_NAME" serve --http="127.0.0.1:$port" &
PB_PID=$!
trap "kill $PB_PID 2>/dev/null; exit" INT TERM

sleep 1
command -v php >/dev/null || { echo "PHP not found. Install php or run PocketBase only: ./$PB_NAME serve --http=127.0.0.1:$port"; kill $PB_PID 2>/dev/null; exit 1; }
echo "App: http://127.0.0.1:$app_port"
php -S "127.0.0.1:$app_port" >/dev/null 2>&1
