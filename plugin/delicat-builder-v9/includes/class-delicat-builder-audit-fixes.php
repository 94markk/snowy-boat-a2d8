<?php
/** PRO14: scoped compatibility fixes for the audited storefront output. */
defined( 'ABSPATH' ) || exit;
final class Delicat_Builder_V9_Audit_Fixes {
 public static function boot(): void {
  foreach ( array( 'script_loader_src', 'style_loader_src' ) as $hook ) { add_filter( $hook, array( __CLASS__, 'asset_url' ), PHP_INT_MAX ); }
  foreach ( array( 'litespeed_optm_js_defer_exc', 'litespeed_optimize_js_excludes', 'litespeed_optm_gm_js_exc' ) as $hook ) { add_filter( $hook, array( __CLASS__, 'script_exclusions' ), PHP_INT_MAX ); }
  add_filter( 'wp_inline_script_attributes', array( __CLASS__, 'inline_attributes' ), PHP_INT_MAX );
  add_filter( 'litespeed_media_lazy_img_excludes', array( __CLASS__, 'image_exclusions' ), PHP_INT_MAX );
  add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'image_attributes' ), PHP_INT_MAX );
  add_action( 'wp_enqueue_scripts', array( __CLASS__, 'empty_cart_assets' ), PHP_INT_MAX );
  add_action( 'template_redirect', array( __CLASS__, 'buffer' ), -50 );
  // Also run after LiteSpeed's optimizer when it owns the outer buffer. Idempotent.
  add_filter( 'litespeed_buffer_after', array( __CLASS__, 'document' ), PHP_INT_MAX );
  add_filter( 'woocommerce_locate_template', array( __CLASS__, 'result_count_template' ), PHP_INT_MAX, 3 );
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
  static $map = null, $base = null;
  if ( null === $map ) {
   $file = DELICAT_BUILDER_V9_DIR . 'asset-versions.php';
   $map  = is_file( $file ) ? require $file : array();
   $map  = is_array( $map ) ? $map : array();
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
  $query['ver'] = $map[ $parts[0] ];
  $path         = substr( $url, 0, strlen( $url ) - strlen( $parts[0] ) - ( isset( $parts[1] ) ? strlen( $parts[1] ) + 1 : 0 ) ) . $parts[0];
  return $path . '?' . http_build_query( $query );
 }
 public static function script_exclusions( $list ): array {
  $list = is_array( $list ) ? $list : array();
  return array_values( array_unique( array_merge( $list, array( 'DelicatSessionConfig', 'DIPIdentityModal', 'DelicaBuilderV9', 'DelicatShell', 'DBPNavConfig', 'DBPStateConfig', 'delicat-builder-v9-global-theme-boot', 'delicat-builder-v9-app-tuning-boot', 'dbv9-speculation', '/delicat-builder-v9/', '/delicat-identity-pro/assets/' ) ) ) );
 }
 public static function inline_attributes( $attributes ): array {
  if ( preg_match( '/^(delicat-|delica-|dbp-|dbv9-|dip-)/', (string) ( $attributes['id'] ?? '' ) ) ) {
   $attributes['data-no-optimize'] = '1'; $attributes['data-no-delay'] = '1'; $attributes['data-cfasync'] = 'false';
  }
  return $attributes;
 }
 public static function image_exclusions( $list ): array {
  $list = is_array( $list ) ? $list : array();
  return array_values( array_unique( array_merge( $list, array( 'dsb8-header__logo-image', 'dnp-image', 'fetchpriority="high"', 'data-no-lazy="1"' ) ) ) );
 }
 public static function image_attributes( $attrs ): array {
  if ( false !== strpos( $attrs['class'] ?? '', 'dsb8-header__logo-image' ) ) {
   $attrs['sizes'] = '(max-width: 640px) 160px, 220px';
  }
  if ( 'high' === ( $attrs['fetchpriority'] ?? '' ) || 'eager' === ( $attrs['loading'] ?? '' ) ) { $attrs['data-no-lazy'] = '1'; $attrs['loading'] = 'eager'; }
  return $attrs;
 }
 public static function empty_cart_assets(): void {
  if ( function_exists( 'is_cart' ) && is_cart() && WC()->cart && WC()->cart->is_empty() ) {
   foreach ( array( 'wc-checkout', 'wc-country-select', 'wc-address-i18n' ) as $handle ) { wp_dequeue_script( $handle ); }
  }
 }
 public static function result_count_template( $template, $name, $path ) {
  if ( 'loop/result-count.php' === $name ) { return DELICAT_BUILDER_V9_DIR . 'templates/purchase/result-count.php'; }
  return $template;
 }
 public static function buffer(): void {
  if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || isset( $_GET['wc-ajax'] ) || isset( $_GET['dbp_fragment'] ) ) { return; }
  ob_start( array( __CLASS__, 'document' ) );
 }
 /** Only known storefront tags are touched; scripts, JSON and user content remain intact. */
 public static function document( $html ) {
  if ( ! is_string( $html ) || false === stripos( $html, '<body' ) || false === stripos( $html, '</html>' ) ) { return $html; }
  $wallet = function_exists( 'is_page' ) && is_page( array( 'my-wallet', 'wallet' ) );
  $wallet = $wallet || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'woo-wallet' ) );
  if ( ! $wallet ) {
   // The audited tour prints directly instead of using a dequeuable script handle.
   $html = preg_replace( '~<(script|style)\b(?=[^>]*\bid=["\']delicat-wallet-tour-(?:js|css)["\'])[^>]*>.*?</\1\s*>~is', '', $html ) ?? $html;
  }
  // Remove only the redundant plain page title directly inside our cart document.
  if ( function_exists( 'is_cart' ) && is_cart() && false !== strpos( $html, 'class="dpn-head ' ) ) {
   $html = preg_replace( '~(<main\b[^>]*\bid=["\']delicat-native-page-main["\'][^>]*>)\s*<h1\b[^>]*>[^<]*</h1>~i', '$1', $html, 1 ) ?? $html;
  }
  if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) { return $html; }
  $p = new WP_HTML_Tag_Processor( $html );
  $boots = array( 'delicat-builder-v9-session-js-before', 'delicat-builder-v9-global-theme-boot', 'delicat-builder-v9-app-tuning-boot', 'dip-identity-modal-v4-js-extra', 'dbp-nav-js-before', 'dbp-state-js-before' );
  while ( $p->next_tag() ) {
   $tag = $p->get_tag();
   if ( 'SCRIPT' === $tag || 'LINK' === $tag ) {
    if ( 'SCRIPT' === $tag && in_array( $p->get_attribute( 'id' ), $boots, true ) ) {
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
}
Delicat_Builder_V9_Audit_Fixes::boot();
