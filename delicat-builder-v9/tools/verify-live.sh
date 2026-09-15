#!/usr/bin/env bash
# Verify the 9.2.0-pro.30 latency work against a live store.
#
# Read-only: every request is a GET or HEAD. The one request that carries an
# action parameter uses a deliberately non-existent product id, so nothing is
# ever added to a cart.
#
# Usage:
#   bash verify-live.sh https://your-store.com
#
# Paste the whole output back. Requires only bash and curl.

set -uo pipefail

if [ $# -lt 1 ]; then
  echo "usage: bash verify-live.sh https://your-store.com" >&2
  echo >&2
  echo "The store URL is required rather than defaulted: the plugin header says" >&2
  echo "delicastoreha.com and the operator refers to delicatstoreha.com, and" >&2
  echo "silently testing the wrong host would produce confident nonsense." >&2
  exit 2
fi

SITE="${1%/}"
UA='Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'
CURL=(curl -sS --max-time 30 -A "$UA")

pass=0; fail=0; warn=0
ok()   { printf '  \033[32mok\033[0m    %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; fail=$((fail+1)); }
note() { printf '  \033[33m??\033[0m    %s\n' "$1"; warn=$((warn+1)); }
hdr()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

echo "================================================================"
echo " Delicat Builder V9 — live verification"
echo " target : $SITE"
echo " date   : $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
echo "================================================================"

# ---------------------------------------------------------------- reachability
hdr "0. Reachable / version"
code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$SITE/") || code=000
if [ "$code" = "200" ]; then ok "homepage responds 200"; else bad "homepage responds $code"; fi
if [ "$code" != "200" ]; then
  echo; echo "Cannot continue — the site did not answer 200."; exit 1
fi

# ------------------------------------------------------- discover a product URL
hdr "1. Discovering URLs"
SHOP=""
for cand in /boutique/ /shop/ /produits/ /products/; do
  c=$("${CURL[@]}" -o /dev/null -w '%{http_code}' "$SITE$cand") || c=000
  if [ "$c" = "200" ]; then SHOP="$SITE$cand"; break; fi
done
[ -n "$SHOP" ] && ok "shop archive: $SHOP" || note "no shop archive found at the usual paths"

PRODUCT=$("${CURL[@]}" "$SITE/wp-sitemap-posts-product-1.xml" 2>/dev/null \
  | grep -oE '<loc>[^<]+</loc>' | sed 's/<[^>]*>//g' | head -1)
if [ -z "$PRODUCT" ] && [ -n "$SHOP" ]; then
  PRODUCT=$("${CURL[@]}" "$SHOP" | grep -oE 'href="[^"]*/(produit|product)/[^"#?]+"' \
    | head -1 | sed 's/^href="//; s/"$//')
fi
# Scraped hrefs are usually root-relative; curl needs an absolute URL.
case "$PRODUCT" in
  http*) : ;;
  /*)    PRODUCT="$SITE$PRODUCT" ;;
  "")    : ;;
  *)     PRODUCT="$SITE/$PRODUCT" ;;
esac
[ -n "$PRODUCT" ] && ok "product page: $PRODUCT" || note "could not auto-discover a product URL"

# --------------------------------------------------------------- cache headers
# $1 label, $2 url, $3 expected token in the cache verdict, $4.. extra curl args
check_cache() {
  local label="$1" url="$2" want="$3"; shift 3
  local h
  h=$("${CURL[@]}" -D - -o /dev/null "$@" "$url" 2>/dev/null \
      | tr -d '\r' | grep -iE '^(x-delicat|x-litespeed|x-lsadc|cache-control)' | tr '\n' ' ')
  if [ -z "$h" ]; then note "$label — no cache headers at all: $url"; return; fi
  if printf '%s' "$h" | grep -qiE "$want"; then ok "$label — $h"; else bad "$label — wanted /$want/, got: $h"; fi
}

hdr "2. These SHOULD now be publicly cacheable"
check_cache "homepage                 " "$SITE/" 'public|hit|miss'
[ -n "$SHOP" ]    && check_cache "shop archive             " "$SHOP" 'public|hit|miss'
[ -n "$SHOP" ]    && check_cache "archive page 2           " "${SHOP}page/2/" 'public|hit|miss'
[ -n "$PRODUCT" ] && check_cache "product page             " "$PRODUCT" 'public|hit|miss'
# The case that always bypassed before: a guest who has a cart.
[ -n "$PRODUCT" ] && check_cache "product + cart cookie    " "$PRODUCT" 'public|hit|miss' \
  --cookie 'woocommerce_items_in_cart=2'
# The case that always bypassed before: an ad-tagged landing.
check_cache "ad landing ?fbclid=      " "$SITE/?fbclid=verify123" 'public|hit|miss'
check_cache "ad landing ?utm_source=  " "$SITE/?utm_source=verify" 'public|hit|miss'

hdr "3. These MUST still be private / bypass"
check_cache "cart                     " "$SITE/panier/"     'private|no-cache|no-store|bypass'
check_cache "checkout                 " "$SITE/commande/"   'private|no-cache|no-store|bypass'
check_cache "account                  " "$SITE/mon-compte/" 'private|no-cache|no-store|bypass'
# Non-existent product id on purpose: nothing can be added to any cart.
check_cache "action ?add-to-cart=     " "$SITE/?add-to-cart=999999999" 'private|no-cache|no-store|bypass'
check_cache "unknown query param      " "$SITE/?zz_verify=1" 'private|no-cache|no-store|bypass'

# ------------------------------------------------------------- the actual fixes
hdr "4. Phase 6 — archive critical CSS is inlined"
if [ -n "$SHOP" ]; then
  body=$("${CURL[@]}" "$SHOP")
  crit=$(printf '%s' "$body" | tr -d '\n' | grep -oE '<style id="delicat-builder-v9-critical">.*?</style>' | head -c 200000)
  if [ -z "$crit" ]; then
    bad "no <style id=delicat-builder-v9-critical> on the archive"
  else
    bytes=$(printf '%s' "$crit" | wc -c | tr -d ' ')
    ok "critical <style> present (~${bytes} B)"
    # This is the fix: the grid rules were being dropped at the 7000 budget.
    if printf '%s' "$crit" | grep -q 'grid-template-columns'; then
      ok "archive grid IS in the critical CSS  <-- the Phase 6 fix"
    else
      bad "archive grid MISSING from critical CSS — woo-archive still being dropped"
    fi
    if printf '%s' "$crit" | grep -q 'delicat-woo-ratio-'; then
      ok "image ratio reserved in critical CSS (no card shift)"
    else
      note "image ratio rules not inlined"
    fi
  fi
fi

hdr "5. Phase 1 — no guest cart leaks into a shared document"
if [ -n "$PRODUCT" ]; then
  leak=$("${CURL[@]}" --cookie 'woocommerce_items_in_cart=3' "$PRODUCT" \
        | grep -oE 'data-dsb8-cart-count[^>]*>[^<]*' | head -3)
  if [ -z "$leak" ]; then
    note "cart badge markup not found (theme may render it elsewhere)"
  elif printf '%s' "$leak" | grep -qE '>[[:space:]]*3[[:space:]]*$'; then
    bad "server rendered cart count 3 into a cacheable page — $leak"
  else
    ok "cart badge is neutral in the shared document — $leak"
  fi
fi

hdr "6. Phase 3/6 — archive image sizing"
if [ -n "$SHOP" ]; then
  sizes=$("${CURL[@]}" "$SHOP" | grep -oE 'sizes="\(max-width:640px\)[^"]*"' | head -1)
  [ -n "$sizes" ] && ok "responsive sizes present — $sizes" || note "no archive sizes attribute found"
  pre=$("${CURL[@]}" "$SHOP" | grep -oE '<link rel="preload" as="image"[^>]*imagesizes="[^"]*"' | head -1 \
        | grep -oE 'imagesizes="[^"]*"')
  if [ -n "$pre" ] && [ -n "$sizes" ]; then
    a=$(printf '%s' "$sizes" | sed 's/^sizes=//'); b=$(printf '%s' "$pre" | sed 's/^imagesizes=//')
    [ "$a" = "$b" ] && ok "LCP preload sizes match the img (no double download)" \
                    || bad "preload/img sizes DISAGREE — image downloaded twice: $a vs $b"
  fi
fi

# ------------------------------------------------------------------- timings
hdr "7. TTFB — run twice, the second should be the cached one"
timeit() {
  local label="$1" url="$2"
  local t1 t2
  t1=$("${CURL[@]}" -o /dev/null -w '%{time_starttransfer}' "$url") || t1=0
  sleep 1
  t2=$("${CURL[@]}" -o /dev/null -w '%{time_starttransfer}' "$url") || t2=0
  printf '  %-26s cold %ss   warm %ss\n' "$label" "$t1" "$t2"
}
timeit "homepage"      "$SITE/"
[ -n "$SHOP" ]    && timeit "shop archive"  "$SHOP"
[ -n "$PRODUCT" ] && timeit "product"       "$PRODUCT"
[ -n "$PRODUCT" ] && timeit "product +cart" "$PRODUCT"

echo
echo "================================================================"
printf " passed %d   failed %d   unclear %d\n" "$pass" "$fail" "$warn"
echo "================================================================"
