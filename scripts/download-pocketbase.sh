#!/bin/sh
# Downloads VectorBase (libSQL PocketBase fork) into the project folder.
# Installed binary name stays: <parent_folder>-pb (see scripts/start.sh).
# Default source: johnstryder/vectorbase — override with VECTORBASE_GITHUB_REPO=owner/repo.
# Prebuilt zips are linux/amd64 only (matches vectorbase .goreleaser.yaml).
set -e
cd "$(dirname "$0")/.."
PB_DIR="$(pwd)"
PB_NAME="$(basename "$PB_DIR")-pb"
PB_BIN="$PB_DIR/$PB_NAME"

VB_REPO="${VECTORBASE_GITHUB_REPO:-johnstryder/vectorbase}"
VB_BINARY="vectorbase"

case "$(uname -s)" in
  Linux)  OS="linux"   ;;
  Darwin) OS="darwin"  ;;
  *) echo "Unsupported OS"; exit 1 ;;
esac
ARCH="$(uname -m)"
[ "$ARCH" = "x86_64" ] && ARCH="amd64"
[ "$ARCH" = "aarch64" ] || [ "$ARCH" = "arm64" ] && ARCH="arm64"

if [ "$OS" != "linux" ] || [ "$ARCH" != "amd64" ]; then
  echo "VectorBase release archives are only built for linux/amd64."
  echo "Clone and build from https://github.com/${VB_REPO} or set VECTORBASE_GITHUB_REPO to a fork that publishes your platform."
  exit 1
fi

# Pass a version like 0.25.0 to pin; omit arg to use latest published GitHub release.
if [ -n "${1:-}" ]; then
  VERSION="$1"
else
  VERSION=$(curl -sSL "https://api.github.com/repos/${VB_REPO}/releases/latest" \
    | sed -n 's/.*"tag_name": "v\([^"]*\)".*/\1/p' | head -n1)
  [ -n "$VERSION" ] || {
    echo "Could not resolve latest release (private repo or no published releases?)."
    echo "Try: $0 <version>   e.g. $0 0.25.0"
    exit 1
  }
fi
URL="https://github.com/${VB_REPO}/releases/download/v${VERSION}/${VB_BINARY}_${VERSION}_${OS}_${ARCH}.zip"

echo "Downloading VectorBase v${VERSION} (${VB_REPO})..."
curl -sfSL "$URL" -o /tmp/vb.zip
if command -v unzip >/dev/null 2>&1; then
  unzip -o /tmp/vb.zip "$VB_BINARY" -d "$PB_DIR"
else
  python3 -c "import zipfile,sys; z=zipfile.ZipFile(sys.argv[1]); z.extract(sys.argv[2],sys.argv[3]); z.close()" /tmp/vb.zip "$VB_BINARY" "$PB_DIR"
fi
rm -f /tmp/vb.zip
mv "$PB_DIR/$VB_BINARY" "$PB_BIN"
chmod +x "$PB_BIN"
echo "Done. Run: ./scripts/start.sh  (binary: ./$PB_NAME)"
