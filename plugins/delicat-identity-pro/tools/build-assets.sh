#!/bin/sh
# Delicat Identity Pro — rebuild the minified front-end scripts the site loads.
# Run after editing any script listed in DIP_Boot::MINIFIED (includes/class-dip-boot.php):
#   sh tools/build-assets.sh
# The readable assets/<name>.js files stay the sources; with SCRIPT_DEBUG set to true
# in wp-config.php the site loads them instead of the .min.js twins.
# Output is ES2019 (no ?. or ??). ESBUILD can point to a local esbuild binary.
set -e
cd "$(dirname "$0")/.."
ESBUILD="${ESBUILD:-npx --yes esbuild@0.24.2}"
if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; else SHA="shasum -a 256"; fi
LIST=$(sed -n '/const MINIFIED = \[/,/\];/p' includes/class-dip-boot.php | grep -o "'[a-z0-9-]*\.js'" | tr -d "'")
[ -n "$LIST" ] || { echo "No script list found in includes/class-dip-boot.php" >&2; exit 1; }
for name in $LIST; do
  base="${name%.js}"
  hash=$($SHA "assets/$name" | cut -c1-16)
  $ESBUILD "assets/$name" --minify --target=es2019 --charset=utf8 --legal-comments=none --log-level=warning \
    "--banner:js=/*! Delicat Identity Pro: minified $name (source sha256 $hash); edit the source, then rebuild. */" \
    --outfile="assets/$base.min.js"
  echo "OK: assets/$base.min.js"
done
