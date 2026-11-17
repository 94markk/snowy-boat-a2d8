#!/usr/bin/env bash
# Build one installable zip per plugin: <slug>-<version>.zip containing <slug>/,
# without tests or OS/editor junk. Usage: package.sh <plugins-dir> <out-dir>
set -euo pipefail
SRC=$(cd "$1" && pwd); OUT=$(mkdir -p "$2" && cd "$2" && pwd)
for dir in "$SRC"/*/; do
  slug=$(basename "$dir")
  main="$dir$slug.php"
  [ -f "$main" ] || main=$(grep -l "Plugin Name:" "$dir"*.php | head -1)
  ver=$(grep -m1 -iE '^\s*\*?\s*Version:' "$main" | sed -E 's/.*Version:\s*//I' | tr -d '[:space:]')
  zip="$OUT/$slug-$ver.zip"
  rm -f "$zip"
  (cd "$SRC" && zip -qr -X "$zip" "$slug" -x "$slug/tests/*" "$slug/tools/*" "*/.DS_Store" "*/__MACOSX/*" "*/.git*" "*/node_modules/*" "*.orig" "*.rej" "*~")
  echo "$(du -h "$zip" | cut -f1)  $zip"
done
