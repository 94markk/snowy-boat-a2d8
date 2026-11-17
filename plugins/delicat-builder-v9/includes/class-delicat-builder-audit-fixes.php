<?php
/** PRO14: scoped compatibility fixes for the audited storefront output. */
defined( 'ABSPATH' ) || exit;
final class Delicat_Builder_V9_Audit_Fixes {
 public static function boot(): void {
  foreach ( array( 'script_loader_src', 'style_loader_src' ) as $hook ) { add_filter( $hook, array( __CLASS__, 'asset_url' ), PHP_INT_MAX ); }
  foreach ( array( 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes', 'litespeed_optm_gm_js_exc' ) as $hook ) { add_filter( $hook, array( __CLASS__, 'script_exclusions' ), PHP_INT_MAX ); }
  add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'inline_attributes' ), PHP_INT_MAX );
  /* 9.7.7: LiteSpeed compares its « excludes » list with the image ADDRESS only; class names belong in the
   * class list. The names below sat in the address list since PRO14 and never matched anything. */
  add_filter( 'litespeed_media_lazy_img_cls_excludes', array( __CLASS__, 'image_exclusions' ), PHP_INT_MAX );
  add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), PHP_INT_MAX );
  add_action( 'wp_enqueue_scripts', array( __CLASS__, 'empty_cart_assets' ), PHP_INT_MAX );
  add_action( 'template_redirect', array( __CLASS__, 'buffer' ), -50 );
  // Also run after LiteSpeed's optimizer when it owns the outer buffer. Idempotent.
  add_filter( 'litespeed_buffer_after', array( __CLASS__, 'document' ), PHP_INT_MAX );
  add_filter( 'woocommerce_locate_template', array( __CLASS__, 'result_count_template' ), PHP_INT_MAX, 3 );
  /* 9.7.8 (FE-12): no Gravatar on the storefront. */
  if ( ! is_admin() ) { add_filter( 'get_avatar_data', array( __CLASS__, 'local_avatar' ), PHP_INT_MAX, 2 ); }
  /* 9.7.8 (BLD-07): a page carrying a WooCommerce notice is one shopper's page. */
  if ( ! is_admin() ) { add_action( 'wp', array( __CLASS__, 'notice_no_store' ), 99 ); }
  /* 9.7.8 (FE-09): My Account carries styles none of its screens use. */
  add_action( 'wp_enqueue_scripts', array( __CLASS__, 'trim_account_assets' ), PHP_INT_MAX );
 }
 /**
  * 9.7.8 (FE-09): every My Account screen loaded select2 (only the address form has a country picker),
  * dashicons (used only by the admin bar and the wallet screen) and the wallet's block-checkout
  * stylesheet. Removed where nothing uses them; WooCommerce's own account styles stay.
  */
 public static function trim_account_assets(): void {
  if ( is_admin() || ! function_exists( 'is_account_page' ) || ! is_account_page() ) { return; }
  $address = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'edit-address' );
  if ( ! $address ) {
   wp_dequeue_style( 'select2' );
   wp_dequeue_script( 'selectWoo' );
   wp_dequeue_script( 'select2' );
  }
  /* The wallet screen (TeraWallet's account endpoint) draws its buttons with dashicons. */
  global $wp;
  $wallet = isset( $wp->query_vars ) && is_array( $wp->query_vars ) && array_key_exists( 'woo-wallet', $wp->query_vars );
  if ( ! is_admin_bar_showing() && ! $wallet ) { wp_dequeue_style( 'dashicons' ); }
  wp_dequeue_style( 'partial-payment-blocks-integration' );
 }
 /**
  * 9.7.8 (BLD-07): "« X » a été ajouté à votre panier" and the other WooCommerce notices are printed into the
  * product or shop page that follows. Such a page belongs to one shopper and is never stored by a cache (page,
  * CDN or the app's offline copy).
  */
 public static function notice_no_store(): void {
  if ( ! function_exists( 'wc_notice_count' ) || ! function_exists( 'WC' ) || ! WC() || ! WC()->session || ! function_exists( 'delicat_builder_v9_no_store' ) ) { return; }
  if ( wc_notice_count() > 0 ) { delicat_builder_v9_no_store( 'woocommerce notice' ); }
 }
 /**
  * 9.7.8 (FE-12): every signed-in page asked gravatar.com for the customer's picture, which hands a third party a
  * hash of their e-mail address on each visit, for a "mystery person" image nearly everyone has. A picture the
  * customer uploaded (any URL that is not Gravatar's) is kept; otherwise there is no picture and the storefront
  * shows its own fallback (the account icon in the header, the initial in the menu).
  */
 public static function local_avatar( $args, $id_or_email ) {
  unset( $id_or_email );
  if ( is_array( $args ) && ! empty( $args['url'] ) && preg_match( '#^https?://([a-z0-9-]+\.)?gravatar\.com/#i', (string) $args['url'] ) ) {
   $args['url']          = '';
   $args['found_avatar'] = false;
  }
  return $args;
 }
 public static function asset_url( $url ) {
  if ( ! is_string( $url ) || '' === $url ) { return $url; }
  /*
   * PRO37: content-addressed VERSIONS, not content-addressed FILENAMES.
   *
   * This filter used to rewrite every plugin asset URL onto a hashed twin
   * (foo.css -> foo.<hash>.css). That required shipping both files: 121 twins,
   * 1.8 MB, more than half of every CSS/JS byte in the plugin. It also drifted
   * — five twins on disk no longer matched the file they were built from, so a
   * browser could be served code the plugin no longer contained.
   *
   * The map now holds the content hash itself and it is applied as ?ver=, which
   * is what WordPress already appends and what LiteSpeed already varies on. The
   * cache-busting guarantee is identical (the URL changes iff the bytes change)
   * with one file per asset instead of two.
   *
   * DELICAT_BUILDER_V9_URL is forced to https at definition time, so a site
   * genuinely served over http (staging, local) enqueues http URLs that would
   * not match it. Compare without the scheme.
   */
  static $base = null;
  $map = self::versions();
  if ( null === $base ) {
   $base = preg_replace( '#^https?://#i', '//', DELICAT_BUILDER_V9_URL );
  }
  $bare = preg_replace( '#^https?://#i', '//', $url );
  if ( ! is_string( $bare ) || strpos( $bare, (string) $base ) !== 0 ) { return $url; }
  $parts = explode( '?', substr( $bare, strlen( (string) $base ) ), 2 );
  if ( ! isset( $map[ $parts[0] ] ) ) { return $url; }
  /* Drop whatever ver the enqueue site supplied (plugin version, which cannot
   * distinguish two builds that changed one file) and substitute the hash. */
  $query = array();
  if ( isset( $parts[1] ) && '' !== $parts[1] ) {
   parse_str( $parts[1], $query );
   unset( $query['ver'] );
  }
  /* 9.14.0 (J-M3): pro.46's minified twins (asset-min-map.php) never shipped; the source file is served. */
  $query['ver'] = $map[ $parts[0] ];
  $path         = substr( $url, 0, strlen( $url ) - ( isset( $parts[1] ) ? strlen( $parts[1] ) + 1 : 0 ) );
  return $path . '?' . http_build_query( $query );
 }
 /** asset path => 12-hex sha256 of its contents, loaded once per request. */
 private static function versions(): array {
  static $map = null;
  if ( null === $map ) {
   $file = DELICAT_BUILDER_V9_DIR . 'asset-versions.php';
   $map  = is_file( $file ) ? require $file : array();
   $map  = is_array( $map ) ? $map : array();
  }
  return $map;
 }
 public static function script_exclusions( $list ): array {
  $list = is_array( $list ) ? $list : array();
  return array_values( array_unique( array_merge( $list, array( 'DelicatSessionConfig', 'DIPIdentityModal', 'DelicaBuilderV9', 'DelicaShellV9', 'DelicatEngine', 'DBPNavConfig', 'DBPStateConfig', 'delicat-builder-v9-global-theme-boot', 'delicat-builder-v9-app-tuning-boot', 'delicat-engine-js', 'dbv9-speculation', '/delicat-builder-v9/', '/delicat-identity-pro/assets/' ) ) ) );
 }
 public static function inline_attributes( $attributes ): array {
  if ( preg_match( '/^(delicat-|delica-|dbp-|dbv9-|dip-)/', (string) ( $attributes['id'] ?? '' ) ) ) {
   $attributes['data-no-optimize'] = '1'; $attributes['data-no-delay'] = '1'; $attributes['data-cfasync'] = 'false';
  }
  return $attributes;
 }
 public static function image_exclusions( $list ): array {
  $list = is_array( $list ) ? $list : array();
  return array_values( array_unique( array_merge( $list, array( 'dsb8-header__logo-image', 'dnp-image', 'delicat-product-card__image' ) ) ) );
 }
 /** 9.14.0 (S-5): Header Studio logo width in CSS px (option dsb8_beta2_header, same 60-320 clamp and default as Header_Runtime::settings()). */
 private static function logo_width(): int {
  $saved = get_option( 'dsb8_beta2_header', array() );
  $raw   = is_array( $saved ) && isset( $saved['logo_width'] ) ? $saved['logo_width'] : 154;
  return max( 60, min( 320, is_numeric( $raw ) ? (int) $raw : 154 ) );
 }
 public static function image_attributes( $attrs ): array {
  if ( false !== strpos( $attrs['class'] ?? '', 'dsb8-header__logo-image' ) ) {
   /* 9.14.0 (S-5): the logo is drawn at most at the Header Studio width (min(width, 38vw), 40vw on phones),
    * 92 px on the live store: ask for that file (204w at DPR 2, 13.7 KB) instead of the 600w one (60 KB), and
    * leave « high » priority to the page's own main picture (the product hero, the first card): WordPress core
    * marked the logo fetchpriority="high" on every page. */
   $attrs['sizes']         = self::logo_width() . 'px';
   $attrs['fetchpriority'] = 'auto';
  }
  if ( 'high' === ( $attrs['fetchpriority'] ?? '' ) || 'eager' === ( $attrs['loading'] ?? '' ) ) { $attrs['data-no-lazy'] = '1'; $attrs['loading'] = 'eager'; }
  /* 9.7.7: product cards keep the browser's own lazy-load; LiteSpeed's swapped their pictures for a blurred
   * stand-in that stayed on screen (shop and category pages, every card after the first). */
  if ( false !== strpos( (string) ( $attrs['class'] ?? '' ), 'delicat-product-card__image' ) ) { $attrs['data-no-lazy'] = '1'; }
  return $attrs;
 }
 public static function empty_cart_assets(): void {
  if ( ! function_exists( 'is_cart' ) || ! is_cart() || ! WC()->cart ) { return; }
  /* wc-cart lists the country and address scripts as dependencies, so
   * dequeuing them alone keeps them on the page; cart.js only reaches them
   * through the shipping calculator's country select. */
  $drop_tables = static function (): void {
   foreach ( array( 'wc-country-select', 'wc-address-i18n' ) as $handle ) { wp_dequeue_script( $handle ); }
   $cart = wp_scripts()->registered['wc-cart'] ?? null;
   if ( $cart && ! empty( $cart->deps ) ) { $cart->deps = array_values( array_diff( (array) $cart->deps, array( 'wc-country-select', 'wc-address-i18n' ) ) ); }
  };
  if ( WC()->cart->is_empty() ) {
   wp_dequeue_script( 'wc-checkout' );
   $drop_tables();
   return;
  }
  /* 9.3: the country and address tables (63 KB of inline JSON on every cart
   * view) only feed the shipping calculator; a cart of digital products that
   * needs no shipping, or a store with the calculator off, never shows it. */
  $calculator = 'yes' === get_option( 'woocommerce_enable_shipping_calc', 'yes' );
  $needs_shipping = is_callable( array( WC()->cart, 'needs_shipping' ) ) ? (bool) WC()->cart->needs_shipping() : true;
  if ( ! $calculator || ! $needs_shipping ) {
   $drop_tables();
  }
 }
 public static function result_count_template( $template, $name, $path ) {
  if ( 'loop/result-count.php' === $name ) { return DELICAT_BUILDER_V9_DIR . 'templates/purchase/result-count.php'; }
  return $template;
 }
 public static function buffer(): void {
  if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['wc-ajax'] ) || isset( $_GET['dbp_fragment'] ) ) { return; }
  /* V10: one pass per page. When LiteSpeed's output buffer runs (Hostinger), it hands the finished document to
   * litespeed_buffer_after once its optimizer is done, and that is the pass that can correct what the optimizer
   * did; this inner buffer walked the same document a second time just before. It now runs only when LiteSpeed's
   * buffer is absent (LiteSpeed off, or bypassed for this request). What the early walk did BEFORE the optimizer
   * now comes from the markup itself: every boot script carries data-no-optimize / data-no-delay where it is
   * printed (wp_inline_script_attributes below, and the two hand-written boots in Header Runtime and App Tuning),
   * and eager / high-priority attachment images carry data-no-lazy (image_attributes()). */
  if ( self::litespeed_buffer_active() ) { return; }
  ob_start( array( __CLASS__, 'document' ) );
 }
 /** Is LiteSpeed Cache's own output buffer (Core::send_headers_force, which applies litespeed_buffer_after) open? */
 private static function litespeed_buffer_active(): bool {
  if ( ! defined( 'LSCWP_V' ) && ! class_exists( 'LiteSpeed\\Core', false ) ) { return false; }
  foreach ( (array) ob_list_handlers() as $handler ) {
   if ( false !== stripos( (string) $handler, 'litespeed' ) ) { return true; }
  }
  return false;
 }
 /** Boot scripts that must run at once: never delayed, combined or retyped by LiteSpeed. */
 const BOOTS = array( 'delicat-builder-v9-session-js-before', 'delicat-builder-v9-global-theme-boot', 'delicat-builder-v9-app-tuning-boot', 'dip-identity-modal-v4-js-extra', 'dbp-nav-js-before', 'dbp-state-js-before', 'delicat-engine-js-before', 'delicat-engine-footer-js-before', 'delicat-engine-js' );
 /** Only known storefront tags are touched; scripts, JSON and user content remain intact. */
 public static function document( $html ) {
  if ( ! is_string( $html ) || false === stripos( $html, '<body' ) || false === stripos( $html, '</html>' ) ) { return $html; }
  $wallet = function_exists( 'is_page' ) && is_page( array( 'my-wallet', 'wallet' ) );
  $wallet = $wallet || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'woo-wallet' ) );
  if ( ! $wallet && false !== strpos( $html, 'delicat-wallet-tour-' ) ) {
   // The audited tour prints directly instead of using a dequeuable script handle.
   $html = preg_replace( '~<(script|style)\b(?=[^>]*\bid=["\']delicat-wallet-tour-(?:js|css)["\'])[^>]*>.*?</\1\s*>~is', '', $html ) ?? $html;
  }
  // Remove only the redundant plain page title directly inside our cart document.
  if ( function_exists( 'is_cart' ) && is_cart() && false !== strpos( $html, 'class="dpn-head ' ) ) {
   $html = preg_replace( '~(<main\b[^>]*\bid=["\']delicat-native-page-main["\'][^>]*>)\s*<h1\b[^>]*>[^<]*</h1>~i', '$1', $html, 1 ) ?? $html;
  }
  if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
  $litespeed = defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core', false );
  $head_end  = stripos( $html, '</head>' );
  if ( ! $litespeed && class_exists( 'Delicat_Builder_V9_Engine', false ) && is_callable( array( 'Delicat_Builder_V9_Engine', 'active' ) ) && Delicat_Builder_V9_Engine::active() ) {
   /* 9.3: no LiteSpeed means no combined artefact, no delayed boot script and
    * no lazy-loader to correct, and the engine's own files are content
    * addressed. Walking 900 tags for nothing cost ~7 ms per page; the head is
    * the only place a plugin URL can still be printed outside the enqueue
    * system (preloads), so that part alone is rewritten. */
   if ( false === $head_end ) { return $html; }
   $head = new WP_HTML_Tag_Processor( substr( $html, 0, $head_end ) );
   while ( $head->next_tag() ) {
    $tag = $head->get_tag();
    if ( 'SCRIPT' !== $tag && 'LINK' !== $tag ) { continue; }
    $attr = 'SCRIPT' === $tag ? 'src' : 'href'; $src = $head->get_attribute( $attr );
    if ( is_string( $src ) ) { $head->set_attribute( $attr, self::asset_url( $src ) ); }
   }
   return $head->get_updated_html() . substr( $html, $head_end );
  }
  if ( false === $head_end ) { return self::walk( $html ); }
  /* V10: the head is walked tag by tag as before (it is small: ~60 tags). In the body, only the tags the walk
   * can change are visited: a <script>/<link> naming a plugin asset, a boot <script> by its id, and an <img>
   * marked fetchpriority="high" or loading="eager" (the LCP picture LiteSpeed must not lazy-load). The body used
   * to cost a full tag walk (~700 tags, ~7 ms by this file's own measure) for a handful of tags. */
  return self::walk( substr( $html, 0, $head_end ) ) . self::patch_body( substr( $html, $head_end ) );
 }
 /** The per-tag corrections, over every tag of $html. */
 private static function walk( string $html ): string {
  $p = new WP_HTML_Tag_Processor( $html );
  while ( $p->next_tag() ) {
   $tag = $p->get_tag();
   if ( 'SCRIPT' === $tag || 'LINK' === $tag ) {
    if ( 'SCRIPT' === $tag && in_array( $p->get_attribute( 'id' ), self::BOOTS, true ) ) {
     if ( 'litespeed/javascript' === $p->get_attribute( 'type' ) ) { $p->remove_attribute( 'type' ); }
     $p->set_attribute( 'data-no-optimize', '1' ); $p->set_attribute( 'data-no-delay', '1' );
    }
    $attr = 'SCRIPT' === $tag ? 'src' : 'href'; $src = $p->get_attribute( $attr );
    if ( is_string( $src ) ) { $p->set_attribute( $attr, self::asset_url( $src ) ); }
   }
   if ( 'IMG' === $tag && ( 'high' === $p->get_attribute( 'fetchpriority' ) || 'eager' === $p->get_attribute( 'loading' ) ) ) {
    foreach ( array( 'src', 'srcset', 'sizes' ) as $attr ) {
     $value = $p->get_attribute( 'data-' . $attr );
     if ( is_string( $value ) && '' !== $value ) { $p->set_attribute( $attr, $value ); $p->remove_attribute( 'data-' . $attr ); }
    }
    $p->remove_attribute( 'data-lazyloaded' ); $p->set_attribute( 'data-no-lazy', '1' );
    if ( false !== strpos( (string) $p->get_attribute( 'class' ), 'dsb8-header__logo-image' ) ) { $p->set_attribute( 'sizes', '(max-width: 640px) 160px, 220px' ); }
   }
  }
  return $p->get_updated_html();
 }
 /**
  * V10: the walk applied to the body's candidate tags only. Each candidate is found from a marker it must carry
  * (the plugin's asset path, a boot id, fetchpriority / eager), cut out as one whole tag (a <script> with its body,
  * so the processor sees a complete element) and walked on its own; every other tag is left byte for byte.
  */
 private static function patch_body( string $html ): string {
  $path    = (string) wp_parse_url( DELICAT_BUILDER_V9_URL, PHP_URL_PATH );
  $markers = array( 'fetchpriority' => array( 'img' ), 'eager' => array( 'img' ) );
  if ( '' !== $path ) { $markers[ $path ] = array( 'script', 'link' ); }
  foreach ( self::BOOTS as $id ) { $markers[ $id ] = array( 'script' ); }
  $length = strlen( $html );
  $found  = array();
  foreach ( $markers as $marker => $names ) {
   $at = 0;
   while ( false !== ( $at = stripos( $html, (string) $marker, $at ) ) ) {
    $start = $at > 0 ? strrpos( $html, '<', $at - $length - 1 ) : false; // the last '<' before the marker
    $at   += strlen( (string) $marker );
    if ( false === $start || isset( $found[ $start ] ) ) { continue; }
    if ( ! preg_match( '~\G<(img|link|script)\b~i', $html, $m, 0, $start ) || ! in_array( strtolower( $m[1] ), $names, true ) ) { continue; }
    $pattern = 'script' === strtolower( $m[1] )
     ? '~\G<script\b(?>[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>.*?</script\s*>~is'
     : '~\G<(?:img|link)\b(?>[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>~i';
    if ( preg_match( $pattern, $html, $tag, 0, $start ) && $start + strlen( $tag[0] ) >= $at ) {
     $found[ $start ] = $tag[0];
    }
   }
  }
  if ( ! $found ) { return $html; }
  /* What the processor treats as text is never touched here either: a marker inside a comment, a <script>,
   * <style>, <textarea>, <title>, <noscript>, <iframe>, <noembed>, <noframes> or <xmp> body (an <img> written in a
   * JavaScript string, LiteSpeed's <noscript> fallback picture) is not a tag. */
  $raw = array();
  if ( preg_match_all( '~<!--.*?-->|<(script|style|textarea|title|noscript|iframe|noembed|noframes|xmp)\b(?>[^>"\']++|"[^"]*+"|\'[^\']*+\')*+>.*?</\1\s*>~is', $html, $ranges, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
   foreach ( $ranges as $range ) { $raw[] = array( (int) $range[0][1], (int) $range[0][1] + strlen( $range[0][0] ) ); }
  }
  ksort( $found );
  $keep = array();
  $end  = -1;
  foreach ( $found as $start => $tag ) {
   if ( $start < $end ) { continue; } // inside a candidate already kept
   foreach ( $raw as $range ) {
    if ( $range[0] < $start && $start < $range[1] ) { continue 2; }
   }
   $keep[ $start ] = $tag;
   $end            = $start + strlen( $tag );
  }
  krsort( $keep );
  foreach ( $keep as $start => $tag ) {
   $fixed = self::walk( $tag );
   if ( $fixed !== $tag ) { $html = substr_replace( $html, $fixed, $start, strlen( $tag ) ); }
  }
  return $html;
 }
}
Delicat_Builder_V9_Audit_Fixes::boot();
