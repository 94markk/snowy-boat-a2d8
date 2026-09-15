#!/usr/bin/env sh
# Build the installable WordPress plugin zip for Delicat Builder V9.
#   sh scripts/package-delicat.sh            -> dist/delicat-builder-v9-<version>.zip
# Refreshes the hashed asset copies, asset-versions.php and integrity-manifest.json first.
set -eu
cd "$(dirname "$0")/.."
python3 scripts/refresh-delicat-assets.py delicat-builder-v9
VERSION=$(sed -n 's/^ \* Version: *//p' delicat-builder-v9/delicat-builder-v9.php | head -1)
mkdir -p dist
OUT="dist/delicat-builder-v9-${VERSION}.zip"
rm -f "$OUT"
zip -qr -X "$OUT" delicat-builder-v9 -x '*.DS_Store' -x '*/__MACOSX/*'
echo "built $OUT ($(du -h "$OUT" | cut -f1))"
