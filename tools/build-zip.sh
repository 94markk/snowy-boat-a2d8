#!/usr/bin/env bash
# Build the installable plugin ZIP (top-level folder: delicat-builder-v9/).
# Usage: tools/build-zip.sh [output-dir]
set -euo pipefail
here="$(cd "$(dirname "$0")/.." && pwd)"
out="${1:-$here/releases}"
mkdir -p "$out"
version="$(sed -n 's/^ \* Version: *\([^ ]*\).*/\1/p' "$here/delicat-builder-v9/delicat-builder-v9.php" | head -1)"
php "$here/tools/build-assets.php" "$here/delicat-builder-v9"
php "$here/tools/build-manifest.php" "$here/delicat-builder-v9"
zip_path="$out/delicat-builder-v9-$version.zip"
rm -f "$zip_path"
( cd "$here" && zip -q -r -X "$zip_path" delicat-builder-v9 -x '*/.DS_Store' -x '*/.git/*' )
unzip -tq "$zip_path"
ls -l "$zip_path"
