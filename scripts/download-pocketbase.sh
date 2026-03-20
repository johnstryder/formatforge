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

# Pass a version like 0.36.7 to pin; omit arg to use latest stable from GitHub API.
if [ -n "${1:-}" ]; then
  VERSION="$1"
else
  VERSION=$(curl -sSL "https://api.github.com/repos/pocketbase/pocketbase/releases/latest" \
    | sed -n 's/.*"tag_name": "v\([^"]*\)".*/\1/p' | head -n1)
  [ -n "$VERSION" ] || VERSION="0.36.7"
fi
URL="https://github.com/pocketbase/pocketbase/releases/download/v${VERSION}/pocketbase_${VERSION}_${OS}_${ARCH}.zip"

echo "Downloading PocketBase v${VERSION}..."
curl -sSL "$URL" -o /tmp/pb.zip
if command -v unzip >/dev/null 2>&1; then
  unzip -o /tmp/pb.zip pocketbase -d "$PB_DIR"
else
  python3 -c "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); z.extract('pocketbase',sys.argv[2]); z.close()" /tmp/pb.zip "$PB_DIR"
fi
rm -f /tmp/pb.zip
mv "$PB_DIR/pocketbase" "$PB_BIN"
chmod +x "$PB_BIN"
echo "Done. Run: ./scripts/start.sh  (binary: ./$PB_NAME)"
