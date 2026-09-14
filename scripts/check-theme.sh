#!/usr/bin/env bash
# Lint every PHP file, run the unit tests, then build the installable ZIP.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
THEME="$ROOT/delicat-store"

echo "== php -l"
status=0
while IFS= read -r -d '' file; do
	if ! php -l "$file" >/dev/null 2>&1; then
		php -l "$file" || true
		status=1
	fi
done < <(find "$THEME" -name '*.php' -print0)
[ "$status" -eq 0 ] || { echo "Syntax errors found."; exit 1; }
echo "ok"

echo "== unit tests"
php "$ROOT/tests/theme/run.php"

echo "== required theme files"
for f in style.css index.php functions.php screenshot.png; do
	[ -f "$THEME/$f" ] || { echo "missing $f"; exit 1; }
done
echo "ok"

"$ROOT/scripts/package-theme.sh"
