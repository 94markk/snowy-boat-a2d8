#!/bin/sh
# Delicat Identity Pro — rebuild the minified front-end scripts the site loads.
# Run after editing any script listed in DIP_Boot::MINIFIED (includes/class-dip-boot.php):
#   sh tools/build-assets.sh           rebuild every assets/<name>.min.js
#   sh tools/build-assets.sh --check   rebuild in a temporary folder only; fails when a shipped
#                                      twin is stale (differs from its source build) or not ES5
# The readable assets/<name>.js files stay the sources; with SCRIPT_DEBUG set to true
# in wp-config.php the site loads them instead of the .min.js twins.
# Sources and twins are ES5: old Android WebViews (Chrome < 66, also inside the app) throw a
# SyntaxError on newer syntax, and the sign-in buttons would then do nothing. acorn parses
# every source and every twin as ES5 and the build stops otherwise (nothing is overwritten).
# ESBUILD and ACORN can point to local binaries (e.g. ACORN=node_modules/.bin/acorn).
# --tsconfig-raw={}: the output does not depend on a tsconfig.json found above the plugin.
set -e
cd "$(dirname "$0")/.."
ESBUILD="${ESBUILD:-npx --yes esbuild@0.24.2}"
ACORN="${ACORN:-npx --yes acorn@8.15.0}"
CHECK=0; [ "${1:-}" = "--check" ] && CHECK=1
if command -v sha256sum >/dev/null 2>&1; then SHA="sha256sum"; else SHA="shasum -a 256"; fi
LIST=$(sed -n '/const MINIFIED = \[/,/\];/p' includes/class-dip-boot.php | grep -o "'[a-z0-9-]*\.js'" | tr -d "'")
[ -n "$LIST" ] || { echo "No script list found in includes/class-dip-boot.php" >&2; exit 1; }
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT
for name in $LIST; do
  $ACORN --ecma5 --silent "assets/$name" || { echo "NOT ES5: assets/$name (sources stay ES5)" >&2; exit 1; }
done
fail=0
for name in $LIST; do
  base="${name%.js}"
  hash=$($SHA "assets/$name" | cut -c1-16)
  $ESBUILD "assets/$name" --minify --target=es5 --charset=utf8 --legal-comments=none --log-level=warning '--tsconfig-raw={}' \
    "--banner:js=/*! Delicat Identity Pro: minified $name (source sha256 $hash); edit the source, then rebuild. */" \
    --outfile="$TMP/$base.min.js"
  $ACORN --ecma5 --silent "$TMP/$base.min.js" || { echo "NOT ES5: $base.min.js output" >&2; exit 1; }
  if [ $CHECK = 1 ]; then
    if cmp -s "$TMP/$base.min.js" "assets/$base.min.js"; then echo "fresh: assets/$base.min.js"
    elif [ -f "assets/$base.min.js" ] && ! $ACORN --ecma5 --silent "assets/$base.min.js" >/dev/null 2>&1; then echo "STALE (not ES5): assets/$base.min.js" >&2; fail=1
    else echo "STALE: assets/$base.min.js" >&2; fail=1; fi
  else
    cp "$TMP/$base.min.js" "assets/$base.min.js"
    echo "OK: assets/$base.min.js"
  fi
done
exit $fail
