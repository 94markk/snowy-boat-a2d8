#!/bin/sh
# Delicat Recharge — rebuild the minified front-end files the site loads.
# Run after editing assets/css/form.css or assets/js/form.js (needs Node.js 18+):
#   sh tools/build-assets.sh
# form.css / form.js stay the readable sources; with SCRIPT_DEBUG set to true in
# wp-config.php the site loads them instead of the .min files.
set -e
cd "$(dirname "$0")/.."
npx --yes csso-cli@4.0.2 assets/css/form.css --output assets/css/form.min.css
npx --yes esbuild@0.28.2 assets/js/form.js --minify --target=es2017 --charset=utf8 --tsconfig-raw='{}' --log-level=warning --outfile=assets/js/form.min.js
echo "OK: assets/css/form.min.css, assets/js/form.min.js"
