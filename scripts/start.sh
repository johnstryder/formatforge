#!/bin/sh
# Start PocketBase + PHP app. Optionally start Antfly via Docker. Ctrl+C to stop all.
cd "$(dirname "$0")/.."
PB_NAME="$(basename "$(pwd)")-pb"
[ -x "./$PB_NAME" ] || { echo "Run: ./scripts/download-pocketbase.sh first"; exit 1; }

# Optional: start Antfly via Docker when using local (picks free port)
is_free() { ! ss -tln 2>/dev/null | grep -q ":${1} " && ! netstat -tln 2>/dev/null | grep -q ":${1} "; }
antfly_url=""
[ -f .env ] && antfly_url=$(grep -E '^ANTFLY_URL=' .env 2>/dev/null | cut -d= -f2- | tr -d ' "')
skip_antfly=false
case "$antfly_url" in
  https://*) skip_antfly=true;;
  "") ;;
  *)
    case "$antfly_url" in *127.0.0.1*|*localhost*) ;; *) skip_antfly=true;; esac
    ;;
esac
if ! $skip_antfly && command -v docker >/dev/null 2>&1 && [ -f docker-compose.antfly.yml ]; then
  antfly_port=""
  [ -f .antfly-port ] && antfly_port=$(cat .antfly-port)
  [ -n "$antfly_port" ] && ! is_free "$antfly_port" && antfly_port=""
  [ -z "$antfly_port" ] && for p in 8080 8081 8082 8091 8092 8100 8200; do is_free "$p" && antfly_port="$p" && break; done
  if [ -n "$antfly_port" ]; then
    echo "$antfly_port" > .antfly-port
    ANTFLY_PORT="$antfly_port" docker compose -f docker-compose.antfly.yml up -d 2>/dev/null && echo "Antfly: http://127.0.0.1:$antfly_port"
  fi
fi

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
