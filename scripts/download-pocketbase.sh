#!/bin/sh
# Downloads PocketBase into the project folder. Binary named: <parent_folder>-pb
set -e
cd "$(dirname "$0")/.."
PB_DIR="$(pwd)"
PB_NAME="$(basename "$PB_DIR")-pb"
PB_BIN="$PB_DIR/$PB_NAME"

case "$(uname -s)" in
  Linux)  OS="linux"   ;;
  Darwin) OS="darwin"  ;;
  *) echo "Unsupported OS"; exit 1 ;;
esac
ARCH="$(uname -m)"
[ "$ARCH" = "x86_64" ] && ARCH="amd64"
[ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ] && ARCH="arm64"

VERSION="${1:-0.22.22}"
URL="https://github.com/pocketbase/pocketbase/releases/download/v${VERSION}/pocketbase_${VERSION}_${OS}_${ARCH}.zip"

echo "Downloading PocketBase v${VERSION}..."
curl -sL "$URL" -o /tmp/pb.zip
unzip -o /tmp/pb.zip pocketbase -d "$PB_DIR"
rm /tmp/pb.zip
mv "$PB_DIR/pocketbase" "$PB_BIN"
chmod +x "$PB_BIN"
echo "Done. Run: ./scripts/start.sh  (binary: ./$PB_NAME)"
