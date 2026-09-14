#!/usr/bin/env bash
# Build dist/delicat-store.zip — the file to upload in WordPress > Apparence > Thèmes > Ajouter > Téléverser.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/delicat-store"
DIST="$ROOT/dist"
VERSION="$(sed -n 's/^Version: *//p' "$THEME/style.css" | head -1)"

mkdir -p "$DIST"
rm -f "$DIST/delicat-store.zip" "$DIST/delicat-store-$VERSION.zip"
( cd "$ROOT" && zip -qr "$DIST/delicat-store.zip" delicat-store \
	-x 'delicat-store/.DS_Store' 'delicat-store/**/.DS_Store' 'delicat-store/node_modules/*' )
cp "$DIST/delicat-store.zip" "$DIST/delicat-store-$VERSION.zip"
echo "== built $DIST/delicat-store.zip ($(du -h "$DIST/delicat-store.zip" | cut -f1)), version $VERSION"
