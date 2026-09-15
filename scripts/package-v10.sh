#!/usr/bin/env bash
# Build and package Delicat Storefront V10 as an installable WordPress zip.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN="$ROOT/delicat-storefront-v10"

cd "$PLUGIN"

node build/build.mjs
php tests/run.php

VERSION="$(grep -oP '^\s*\*\s*Version:\s*\K[0-9a-zA-Z.\-]+' delicat-storefront-v10.php | head -1)"
OUT="$ROOT/dist/delicat-storefront-v10-${VERSION}.zip"

mkdir -p "$ROOT/dist"
rm -f "$OUT"

cd "$ROOT"
zip -qr "$OUT" delicat-storefront-v10 \
  -x '*/node_modules/*' '*/.git/*' '*/tests/*' '*.map'

printf '\nbuilt %s (%s)\n' "$OUT" "$(du -h "$OUT" | cut -f1)"
