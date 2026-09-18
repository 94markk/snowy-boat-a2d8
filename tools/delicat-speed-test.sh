#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Delicat Builder V9 — storefront speed + health probe
#
# Run this from YOUR machine (Mac or Linux Terminal). It needs only bash + curl.
# It answers three questions:
#
#   1. Where is the time actually going (DNS / TLS / server think time / transfer)?
#   2. Is LiteSpeed serving a cache HIT, or is every request hitting PHP?
#   3. Is the Pro Kernel alive after installing pro.38 — instant nav, hashed
#      asset versions, product prerender rules?
#
#   ./delicat-speed-test.sh                       # defaults to delicastoreha.com
#   ./delicat-speed-test.sh https://example.com   # any origin
#   ./delicat-speed-test.sh https://delicastoreha.com /produit/some-product/
# ---------------------------------------------------------------------------
set -uo pipefail

ORIGIN="${1:-https://delicastoreha.com}"
ORIGIN="${ORIGIN%/}"
PRODUCT_PATH="${2:-}"

UA_MOBILE='Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1'
RUNS="${RUNS:-3}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

bold() { printf '\033[1m%s\033[0m\n' "$*"; }
dim()  { printf '\033[2m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32mok\033[0m    %s\n' "$*"; }
warn() { printf '  \033[33mwarn\033[0m  %s\n' "$*"; }
bad()  { printf '  \033[31mBAD\033[0m   %s\n' "$*"; }

fetch() { # url outfile headerfile ua -> prints "dns connect tls ttfb total size code"
  curl -sS -o "$2" -D "$3" -A "$4" --compressed --max-time 60 \
    -w '%{time_namelookup} %{time_connect} %{time_appconnect} %{time_starttransfer} %{time_total} %{size_download} %{http_code}' \
    "$1" 2>/dev/null
}

bold "Delicat Builder V9 — speed probe"
dim  "origin: $ORIGIN   runs per URL: $RUNS   $(date)"
echo

# --- discover a real product URL from the sitemap or the shop page -----------
if [ -z "$PRODUCT_PATH" ]; then
  dim "discovering a product URL..."
  PRODUCT_URL="$(curl -sS --max-time 25 -A "$UA_MOBILE" "$ORIGIN/wp-sitemap-posts-product-1.xml" 2>/dev/null \
    | grep -oE '<loc>[^<]+</loc>' | sed -E 's|</?loc>||g' | head -1)"
  if [ -z "${PRODUCT_URL:-}" ]; then
    PRODUCT_URL="$(curl -sS --max-time 25 -A "$UA_MOBILE" "$ORIGIN/" 2>/dev/null \
      | grep -oE 'href="[^"]*/(produit|product)/[^"]+"' | sed -E 's/^href="//; s/"$//' | head -1)"
  fi
else
  PRODUCT_URL="$ORIGIN$PRODUCT_PATH"
fi
[ -n "${PRODUCT_URL:-}" ] && dim "product: $PRODUCT_URL" || warn "no product URL found — skipping product timings"
echo

# --- timings ----------------------------------------------------------------
bold "1. Timing  (ttfb = server think time; the number that matters most)"
printf '  %-30s %8s %8s %8s %8s %10s\n' 'URL' 'dns' 'tls' 'TTFB' 'total' 'bytes'

probe() {
  local label="$1" url="$2"
  [ -z "$url" ] && return
  # Report every column from the SAME run — the fastest one. Mixing the best
  # TTFB with another run's total would misattribute where the time went.
  local best_ttfb=99 b_dns=0 b_tls=0 b_total=0 b_size=0 b_code=000
  local line dns tls ttfb total size code
  for _ in $(seq 1 "$RUNS"); do
    line="$(fetch "$url" "$TMP/body" "$TMP/hdr" "$UA_MOBILE")"
    read -r dns _ tls ttfb total size code <<<"$line"
    if awk -v a="$ttfb" -v b="$best_ttfb" 'BEGIN{exit !(a<b)}'; then
      best_ttfb="$ttfb"; b_dns="$dns"; b_tls="$tls"; b_total="$total"; b_size="$size"; b_code="$code"
      cp "$TMP/body" "$TMP/best-$label" 2>/dev/null
      cp "$TMP/hdr"  "$TMP/besthdr-$label" 2>/dev/null
    fi
  done
  printf '  %-30s %7.3fs %7.3fs %7.3fs %7.3fs %9s  [%s]\n' "$label" "$b_dns" "$b_tls" "$best_ttfb" "$b_total" "$b_size" "$b_code"
  awk -v t="$best_ttfb" 'BEGIN{ if (t>1.5) exit 2; else if (t>0.6) exit 1; else exit 0 }'
  case $? in
    2) bad  "   ^ TTFB over 1.5s — PHP is doing real work on every request (cache miss)";;
    1) warn "   ^ TTFB 0.6-1.5s — likely uncached; a LiteSpeed HIT should be under ~0.2s";;
  esac
}

probe home     "$ORIGIN/"
probe shop     "$ORIGIN/boutique/"
[ -n "${PRODUCT_URL:-}" ] && probe product "$PRODUCT_URL"
echo

# --- cache posture ----------------------------------------------------------
bold "2. Cache posture  (a storefront that never HITs will always feel slow)"
for label in home product; do
  H="$TMP/besthdr-$label"; [ -f "$H" ] || continue
  LS="$(grep -i '^x-litespeed-cache:' "$H" | tr -d '\r' | awk '{print $2}')"
  CF="$(grep -i '^cf-cache-status:' "$H" | tr -d '\r' | awk '{print $2}')"
  CC="$(grep -i '^cache-control:' "$H" | tr -d '\r' | cut -d' ' -f2-)"
  printf '  %-9s litespeed=%-8s cloudflare=%-8s cache-control=%s\n' "$label" "${LS:-none}" "${CF:-none}" "${CC:-none}"
  case "${LS:-}" in
    hit|HIT) ok "   LiteSpeed HIT";;
    miss|MISS) warn "   LiteSpeed MISS — this request ran PHP end to end";;
    "") bad  "   no X-LiteSpeed-Cache header — page caching is not active for this URL";;
  esac
done
echo

# --- is the Pro Kernel alive? -----------------------------------------------
bold "3. Pro Kernel health  (after installing 9.2.0-pro.38)"
B="$TMP/best-product"; [ -f "$B" ] || B="$TMP/best-home"
if [ -f "$B" ]; then
  grep -q 'dbp-nav' "$B" \
    && ok  "dbp-nav.js present — instant navigation engine is running" \
    || bad "dbp-nav.js ABSENT — the Pro Kernel is not booting (this is the big one)"

  grep -qE '\?ver=[0-9a-f]{12}' "$B" \
    && ok  "assets carry 12-hex content-hash ?ver — pro.37 pipeline is live" \
    || warn "no content-hash ?ver seen — still on the pre-pro.37 asset pipeline"

  grep -q 'speculationrules' "$B" \
    && ok  "speculation rules present — product pages prerender on hover/touch" \
    || warn "no speculation rules — product opens will not be instant"

  grep -qE '\.[0-9a-f]{12}\.(css|js)' "$B" \
    && bad "hashed asset FILENAMES still referenced — old duplicate pipeline is active" \
    || ok  "no duplicate hashed filenames referenced"

  CSS=$(grep -oE '<link[^>]+stylesheet[^>]*>' "$B" | wc -l | tr -d ' ')
  JS=$(grep -oE '<script[^>]+src=' "$B" | wc -l | tr -d ' ')
  HTML=$(wc -c < "$B" | tr -d ' ')
  printf '  stylesheets=%s  external scripts=%s  html=%s bytes\n' "$CSS" "$JS" "$HTML"
  [ "$CSS" -gt 12 ] && warn "   $CSS stylesheets is a lot — route scoping may not be applying"
  grep -q 'delicat_builder_v9_pro_boot_error' "$B" && bad "   a Pro boot error is still recorded"
else
  warn "no page body captured"
fi
echo

bold "4. Next"
cat <<'TXT'
  - Admin-only live overlay (query counts, asset bytes, cache state), on any
    storefront page while logged in as an administrator:
        <your-site>/?dbv9_turbo_diag=1
  - Delicat Builder -> Self-Test in wp-admin runs the module + surface checks.
  - Clear the stale diagnostic records the admin panel cannot clear itself:
        wp option delete delicat_builder_v9_circuit_breaker_tripped
        wp option delete delicat_builder_v9_boot_failures
  - A LiteSpeed MISS on every run means page caching is off or being bypassed;
    that dominates every code-level optimisation.
TXT
