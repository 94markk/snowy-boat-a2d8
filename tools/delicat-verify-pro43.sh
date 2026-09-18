#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Delicat Builder V9 — verify the pro.40–pro.43 fixes on the live site.
#
# Run from YOUR machine. Needs only bash + curl. Nothing is written or changed.
#
#   ./delicat-verify-pro43.sh
#   ./delicat-verify-pro43.sh https://delicastoreha.com
#
# Each section names the fix it is checking, so a FAIL tells you which one did
# not take effect rather than just "it is still slow".
# ---------------------------------------------------------------------------
set -uo pipefail

ORIGIN="${1:-https://delicastoreha.com}"; ORIGIN="${ORIGIN%/}"
UA='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

bold(){ printf '\033[1m%s\033[0m\n' "$*"; }
dim(){ printf '\033[2m%s\033[0m\n' "$*"; }
ok(){   printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
bad(){  printf '  \033[31mFAIL\033[0m  %s\n' "$*"; }
warn(){ printf '  \033[33m????\033[0m  %s\n' "$*"; }

# get <url> <tag> -> echoes "ttfb total bytes code"; body in $TMP/b.<tag>, headers in $TMP/h.<tag>
get(){
  curl -sS -o "$TMP/b.$2" -D "$TMP/h.$2" -A "$UA" --compressed --max-time 60 \
    -w '%{time_starttransfer} %{time_total} %{size_download} %{http_code}' "$1" 2>/dev/null
}
hdr(){ grep -i "^$2:" "$TMP/h.$1" 2>/dev/null | tr -d '\r' | head -1 | cut -d' ' -f2- ; }
ls_state(){ hdr "$1" 'x-litespeed-cache' | tr 'A-Z' 'a-z' | tr -d ' '; }

bold "Delicat Builder V9 — pro.43 verification"
dim  "$ORIGIN   $(date)"
echo

# Find a real product URL.
PRODUCT="$(curl -sS --max-time 25 -A "$UA" "$ORIGIN/wp-sitemap-posts-product-1.xml" 2>/dev/null \
  | grep -oE '<loc>[^<]+</loc>' | sed -E 's|</?loc>||g' | head -1)"
[ -z "${PRODUCT:-}" ] && PRODUCT="$(curl -sS --max-time 25 -A "$UA" "$ORIGIN/" 2>/dev/null \
  | grep -oE 'href="[^"]*/(produit|product)/[^"]+"' | sed -E 's/^href="//; s/"$//' | head -1)"
[ -n "${PRODUCT:-}" ] && dim "product under test: $PRODUCT" || warn "no product URL found; product checks will be skipped"
echo

# ---------------------------------------------------------------------------
bold "1. pro.40 — an ad-tagged URL must still be cacheable"
dim  "   Every ?fbclid / ?utm_* hit used to define DONOTCACHEPAGE and run full PHP."
for pass in 1 2; do
  A=$(get "$ORIGIN/" "home$pass");            TA=$(echo "$A" | cut -d' ' -f1)
  B=$(get "$ORIGIN/?fbclid=verify123" "fb$pass"); TB=$(echo "$B" | cut -d' ' -f1)
done
SA="$(ls_state home2)"; SB="$(ls_state fb2)"
printf '  clean home   ttfb=%ss  litespeed=%s\n' "$TA" "${SA:-none}"
printf '  ?fbclid=     ttfb=%ss  litespeed=%s\n' "$TB" "${SB:-none}"
case "$SB" in
  hit) ok "?fbclid= is served from the page cache — the pro.40 fix is live";;
  miss) bad "?fbclid= is still a MISS on the second request — page cache is not storing it";;
  "") if [ -z "$SA" ]; then bad "no x-litespeed-cache header at all — LiteSpeed page cache is off for this site"
      else warn "clean home has a cache header but ?fbclid= does not"; fi;;
  *) warn "?fbclid= litespeed=$SB";;
esac
echo

# ---------------------------------------------------------------------------
bold "2. pro.41 — a personalised page must NOT ship cacheable"
dim  "   Site search renders the real basket; it must carry no-store / no cache header."
get "$ORIGIN/?s=test" search >/dev/null
CC="$(hdr search 'cache-control')"; SS="$(ls_state search)"
printf '  /?s=test     cache-control=%s  litespeed=%s\n' "${CC:-none}" "${SS:-none}"
if echo "${CC:-}" | grep -qiE 'no-store|no-cache|private'; then ok "search is marked uncacheable — the pro.41 tier fix is live"
elif [ "$SS" = "hit" ]; then bad "search was served from a SHARED cache — personalised HTML may be cached"
else warn "no explicit no-store; check that x-litespeed-cache never reports hit here"; fi
echo

# ---------------------------------------------------------------------------
bold "3. pro.40 — instant navigation must be able to run"
if [ -n "${PRODUCT:-}" ]; then
  get "$PRODUCT" prod >/dev/null
  grep -q 'dbp-nav' "$TMP/b.prod" && ok "dbp-nav.js is present" || bad "dbp-nav.js absent — the Pro navigation engine is not loading"
  grep -q 'speculationrules' "$TMP/b.prod" && ok "speculation rules present — products prerender on hover/touch" \
    || warn "no speculation rules in the product document"
  MODS=$(grep -oE '<script[^>]+type=["'"'"']module["'"'"']' "$TMP/b.prod" | wc -l | tr -d ' ')
  dim  "   module scripts in the document: $MODS (these used to abort every soft swap)"
else warn "skipped (no product URL)"; fi
echo

# ---------------------------------------------------------------------------
bold "4. pro.41 — no second WordPress bootstrap for a cookieless guest"
dim  "   /?dbp_state=1 is a full WP+Woo boot; it must not fire for a guest with no cart."
if [ -n "${PRODUCT:-}" ]; then
  CFG="$(grep -o 'DBPStateConfig=[^<]*' "$TMP/b.prod" | head -1)"
  if [ -z "$CFG" ]; then warn "DBPStateConfig not found in the product document"
  elif echo "$CFG" | grep -q '"initial":0'; then ok "initial=0 — no extra origin request for cold traffic"
  elif echo "$CFG" | grep -q '"initial":1'; then bad "initial=1 for a cookieless guest — still double-booting on product views"
  else warn "could not read the initial flag: $CFG"; fi
else warn "skipped (no product URL)"; fi
echo

# ---------------------------------------------------------------------------
bold "5. pro.37 — asset pipeline"
B="$TMP/b.prod"; [ -f "$B" ] || B="$TMP/b.home2"
if [ -f "$B" ]; then
  grep -qE '\?ver=[0-9a-f]{12}' "$B" && ok "assets carry content-hash ?ver" || warn "no content-hash ?ver seen"
  grep -qE '\.[0-9a-f]{12}\.(css|js)' "$B" && bad "duplicate hashed FILENAMES still referenced" || ok "no duplicate hashed filenames"
  CSS=$(grep -oE '<link[^>]+stylesheet' "$B" | wc -l | tr -d ' ')
  printf '  stylesheets on this page: %s\n' "$CSS"
  grep -q 'all-components.min.css' "$B" && bad "serving the 176 KB all-components fallback — open any wp-admin page to rebuild" \
    || ok "not on the all-components fallback bundle"
fi
echo

bold "Summary"
cat <<'TXT'
  Any FAIL above names the fix that did not take effect. The usual cause is a
  stale cache, so if you have not already:
      LiteSpeed -> Toolbox -> Purge All, and Cloudflare -> Purge Everything
  then re-run this script.

  If section 1 FAILs with "no x-litespeed-cache header at all", the plugin is
  not the bottleneck: page caching is off for the site and that dominates
  everything else.
TXT
