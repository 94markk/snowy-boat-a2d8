<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_PWA {
	public const OPTION = 'delicat_builder_v9_pwa';
	private static bool $booted = false;
	private static bool $shortcode_css_printed = false;

	public static function defaults(): array {
		return array(
			'enabled'       => 1,
			'install_ui'    => 1,
			'static_cache'  => 1,
			'document_cache'=> 1,
			'document_ttl'  => 600,
			'max_entries'   => 64,
			'theme_color'   => '#11104a',
			'background'    => '#ffffff',
			'short_name'    => 'Delicat',
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function is_booted(): bool {
		$s = self::settings();
		return self::$booted && ! empty( $s['enabled'] );
	}

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }

		add_action( 'init', array( __CLASS__, 'rewrites' ), 8 );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 9 );
		/* RC77: serve the two app endpoints straight from the request path too.
		 * On sites whose permalink rules were never re-saved after activation the
		 * rewrite rules are absent and both files answered 404, which broke the
		 * installable app and offline caching. */
		add_action( 'parse_request', array( __CLASS__, 'direct_endpoint' ), 0 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'endpoint' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'head' ), 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_runtime' ), 20 );
		add_shortcode( 'delicat_install_app', array( __CLASS__, 'shortcode' ) );
	}

	public static function activate(): void {
		self::rewrites();
		flush_rewrite_rules( false );
		update_option( 'delicat_builder_v9_pwa_rules', DELICAT_BUILDER_V9_VERSION, false );
	}

	public static function rewrites(): void {
		add_rewrite_rule( '^delicat-v9-manifest\.webmanifest$', 'index.php?delicat_v9_manifest=1', 'top' );
		add_rewrite_rule( '^delicat-v9-sw\.js$', 'index.php?delicat_v9_sw=1', 'top' );
	}

	/* Flush once per plugin version so a plugin update repairs missing rules. */
	public static function maybe_flush(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) { return; }
		if ( get_option( 'delicat_builder_v9_pwa_rules' ) === DELICAT_BUILDER_V9_VERSION ) { return; }
		update_option( 'delicat_builder_v9_pwa_rules', DELICAT_BUILDER_V9_VERSION, false );
		flush_rewrite_rules( false );
	}

	/* Path-based fallback: independent of rewrite rules and permalink state. */
	public static function direct_endpoint(): void {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $uri ) { return; }
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$file = strtolower( ltrim( (string) strrchr( '/' . ltrim( $path, '/' ), '/' ), '/' ) );
		if ( 'delicat-v9-sw.js' === $file ) { self::service_worker_response(); }
		if ( 'delicat-v9-manifest.webmanifest' === $file ) { self::manifest_response(); }
	}



	public static function query_vars( $vars ): array {
		$vars = is_array( $vars ) ? $vars : array();
		$vars[] = 'delicat_v9_manifest';
		$vars[] = 'delicat_v9_sw';
		return $vars;
	}

	private static function icon_url( int $size ): string {
		$url = get_site_icon_url( $size );
		if ( ! $url && 512 !== $size ) { $url = get_site_icon_url( 512 ); }
		return $url ? esc_url_raw( $url ) : '';
	}

	public static function endpoint(): void {
		if ( get_query_var( 'delicat_v9_manifest' ) ) {
			self::manifest_response();
		}
		if ( get_query_var( 'delicat_v9_sw' ) ) {
			self::service_worker_response();
		}
	}

	private static function manifest_response(): void {
		$s = self::settings();
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		$icons = array();
		foreach ( array( 192, 512 ) as $size ) {
			$url = self::icon_url( $size );
			if ( $url ) {
				$icons[] = array(
					'src'     => $url,
					'sizes'   => $size . 'x' . $size,
					'type'    => 'image/png',
					'purpose' => 'any maskable',
				);
			}
		}
		$payload = array(
			'name'             => get_bloginfo( 'name' ) ?: 'Delicat Store Haiti',
			'short_name'       => substr( sanitize_text_field( $s['short_name'] ), 0, 32 ) ?: 'Delicat',
			'description'      => 'Delicat Store Haiti — top up, gift cards et services numériques.',
			'start_url'        => home_url( '/?utm_source=pwa' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => sanitize_hex_color( $s['background'] ) ?: '#ffffff',
			'theme_color'      => sanitize_hex_color( $s['theme_color'] ) ?: '#11104a',
			'orientation'      => 'portrait-primary',
			'lang'             => 'fr',
			'icons'            => $icons,
			'categories'       => array( 'shopping', 'games', 'finance' ),
		);
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	private static function service_worker_response(): void {
		$s = self::settings();
		nocache_headers();
		$scope = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$scope = is_string( $scope ) && '' !== $scope ? trailingslashit( $scope ) : '/';
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . $scope );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'X-Content-Type-Options: nosniff' );
		$cache_name = 'delicat-v9-static-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', DELICAT_BUILDER_V9_VERSION );
		$max = min( 120, max( 20, absint( $s['max_entries'] ?? 64 ) ) );
		$cache_enabled = empty( $s['static_cache'] ) ? 'false' : 'true';
		$doc_enabled   = empty( $s['document_cache'] ) ? 'false' : 'true';
		/* RC71.2: Identity's public modal contains browser-bound auth state/nonces.
		 * Static CSS/JS/image caching remains enabled, but HTML shell caching is
		 * disabled while Identity is the authentication authority so login/logout
		 * can never revive stale signed-in/signed-out markup. */
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && Delicat_Builder_V9_Identity_Bridge::authority_enabled() ) {
			$doc_enabled = 'false';
		}
		/* RC40: a fresh cached document is returned before the network is even
		 * consulted, so an installed app could keep showing the open storefront
		 * while maintenance is on. Documents go network-only until it is lifted. */
		if ( class_exists( 'Delicat_Builder_V9_Maintenance', false ) && Delicat_Builder_V9_Maintenance::is_enabled() ) {
			$doc_enabled = 'false';
		}
		$doc_ttl       = min( 3600, max( 60, absint( $s['document_ttl'] ?? 600 ) ) );
		$doc_cache     = 'delicat-v9-docs-' . preg_replace( '/[^A-Za-z0-9_.-]/', '-', DELICAT_BUILDER_V9_VERSION );
		/* RC36: the storefront shell is precached at install so repeat visits and the first offline open already have it. */
		$precache = array();
		$chrome = 'assets/css/storefront-chrome.min.css';
		$chrome_css = is_file( DELICAT_BUILDER_V9_DIR . $chrome )
			? array( $chrome )
			: array( 'assets/css/theme-system.css', 'assets/dsb8-beta2-header.css', 'assets/css/drawer.css', 'assets/css/bottom-nav.css' );
		$precache_files = array_merge( $chrome_css, array( 'assets/dsb8-beta2-header.js', 'assets/js/drawer.js', 'assets/js/session.js', 'assets/js/theme.js', 'assets/js/pwa-runtime.js' ) );
		/*
		 * pro.17: every one of these files has a content-addressed twin, and the
		 * page requests the twin — style_loader_src/script_loader_src are rewritten
		 * to `<name>.<hash>.<ext>` with the query string dropped. The precache was
		 * building `<name>.<ext>?ver=<plugin version>` instead, so the service worker
		 * downloaded six files at install that no page would ever ask for, and every
		 * request still went to the network. Resolve through the same map the page
		 * uses; a file with no twin keeps its ?ver URL, which is what WordPress emits
		 * for it.
		 */
		foreach ( $precache_files as $rel ) {
			if ( ! is_file( DELICAT_BUILDER_V9_DIR . $rel ) ) {
				continue;
			}
			$url = add_query_arg( 'ver', DELICAT_BUILDER_V9_VERSION, DELICAT_BUILDER_V9_URL . $rel );
			if ( is_callable( array( 'Delicat_Builder_V9_Audit_Fixes', 'asset_url' ) ) ) {
				$url = (string) Delicat_Builder_V9_Audit_Fixes::asset_url( $url );
			}
			$precache[] = $url;
		}
		$precache = (array) apply_filters( 'delicat_builder_v9_pwa_precache', array_values( array_unique( array_map( 'esc_url_raw', $precache ) ) ) );
		?>
'use strict';
const DBV9_CACHE=<?php echo wp_json_encode( $cache_name ); ?>;
const DBV9_DOCS=<?php echo wp_json_encode( $doc_cache ); ?>;
const DBV9_MAX=<?php echo (int) $max; ?>;
const DBV9_STATIC_CACHE=<?php echo $cache_enabled; ?>;
const DBV9_DOC_CACHE=<?php echo $doc_enabled; ?>;
const DBV9_DOC_TTL=<?php echo (int) $doc_ttl; ?>*1000;
const DBV9_PRECACHE=<?php echo wp_json_encode( $precache ); ?>;
const DBV9_OFFLINE='<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Hors ligne</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7fb;color:#15172a;text-align:center;padding:24px}h1{font-size:22px;margin:0 0 8px}p{color:#6b7285;margin:0 0 18px}a{display:inline-block;padding:12px 20px;border-radius:14px;background:#6d5dfc;color:#fff;text-decoration:none;font-weight:700}</style></head><body><div><h1>Vous êtes hors ligne</h1><p>Reconnectez-vous pour continuer vos achats.</p><a href="/">Réessayer</a></div></body></html>';
const DBV9_PRIVATE=/(?:\/wp-admin(?:\/|$)|\/wp-login\.php(?:\/|$)|\/cart(?:\/|$)|\/panier(?:\/|$)|\/checkout(?:\/|$)|\/commande(?:\/|$)|\/my-account(?:\/|$)|\/mon-compte(?:\/|$)|\/my-wallet(?:\/|$)|\/wp-json(?:\/|$)|admin-ajax\.php)/i;
const DBV9_SENSITIVE_Q=/(?:^|&)(?:wc-ajax|add-to-cart|remove_item|undo_item|_wpnonce|nonce|action|dip_action|logout|customer-logout)=/i;
async function dbv9Trim(){const c=await caches.open(DBV9_CACHE);const keys=await c.keys();if(keys.length>DBV9_MAX){await Promise.all(keys.slice(0,keys.length-DBV9_MAX).map(k=>c.delete(k)));}}
self.addEventListener('install',e=>e.waitUntil((async()=>{try{if(DBV9_STATIC_CACHE&&DBV9_PRECACHE.length){const c=await caches.open(DBV9_CACHE);await Promise.all(DBV9_PRECACHE.map(u=>c.add(new Request(u,{cache:'reload'})).catch(()=>{})));}}catch(_){}await self.skipWaiting();})()));
self.addEventListener('activate',e=>e.waitUntil((async()=>{try{if(self.registration.navigationPreload)await self.registration.navigationPreload.enable();}catch(_){}const keys=await caches.keys();await Promise.all(keys.filter(k=>(k.startsWith('delicat-v9-static-')&&k!==DBV9_CACHE)||(k.startsWith('delicat-v9-docs-')&&k!==DBV9_DOCS)).map(k=>caches.delete(k)));await self.clients.claim();})()));
self.addEventListener('message',e=>{const d=e.data||{};if(d.type==='dbv9-clear-docs'){e.waitUntil(caches.delete(DBV9_DOCS));}});
self.addEventListener('push',e=>{
  let d={};try{d=e.data?e.data.json():{};}catch(_){try{d={body:e.data?e.data.text():''};}catch(__){d={};}}
  const title=String(d.title||'Delicat Store').slice(0,120),body=String(d.body||'').slice(0,360);
  let url=self.location.origin+'/';try{const u=new URL(String(d.url||'/'),self.location.origin);if(u.origin===self.location.origin)url=u.href;}catch(_){}
  const opts={body,tag:String(d.tag||d.notification_id||'delicat-update').slice(0,120),data:{url,notification_id:String(d.notification_id||'')},icon:d.icon||undefined,badge:d.icon||undefined,renotify:!!d.urgent,timestamp:Number(d.timestamp)||Date.now()};
  e.waitUntil(self.registration.showNotification(title,opts).then(()=>self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{list.forEach(c=>{try{c.postMessage({type:'dbv9-push',item:{...d,url}});}catch(_){}});})));
});
self.addEventListener('notificationclick',e=>{
  e.notification.close();let target=self.location.origin+'/';try{const u=new URL(String((e.notification.data&&e.notification.data.url)||'/'),self.location.origin);if(u.origin===self.location.origin)target=u.href;}catch(_){}
  e.waitUntil(self.clients.matchAll({type:'window',includeUncontrolled:true}).then(list=>{for(const c of list){try{if(new URL(c.url).origin===self.location.origin){c.navigate(target).catch(()=>{});return c.focus();}}catch(_){}}return self.clients.openWindow?self.clients.openWindow(target):undefined;}));
});
function dbv9Stamp(resp){const h=new Headers(resp.headers);h.set('x-dbv9-cached-at',String(Date.now()));return new Response(resp.body,{status:resp.status,statusText:resp.statusText,headers:h});}
function dbv9Fresh(hit){const at=Number(hit&&hit.headers.get('x-dbv9-cached-at'))||0;return at&&(Date.now()-at)<DBV9_DOC_TTL;}
/* RC60: one network fetch per document URL at a time. A touchstart warm-up
   (shell-header fetch from shell-nav.js) and the tap's own navigation arrive
   ~100-300 ms apart for the same URL; the navigation now joins the warm-up's
   in-flight request instead of opening a second one, so the head start is
   real on the very first visit, not only once the copy is cached. */
const dbv9DocInflight=new Map();
const DBV9_DOCS_MAX=40;
async function dbv9TrimDocs(c){try{const keys=await c.keys();if(keys.length>DBV9_DOCS_MAX){await Promise.all(keys.slice(0,keys.length-DBV9_DOCS_MAX).map(k=>c.delete(k)));}}catch(_){}}
function dbv9DocFetch(e,r,c,key,keyUrl){
  let p=dbv9DocInflight.get(keyUrl);
  if(p)return p;
  p=(async()=>{
    let resp=null;try{resp=(e.preloadResponse&&await e.preloadResponse)||await fetch(r);}catch(_){resp=null;}
    if(resp&&resp.ok&&resp.type!=='opaque'&&(resp.headers.get('content-type')||'').includes('text/html')){
      const cc=(resp.headers.get('cache-control')||'').toLowerCase();
      if(!/(?:no-store|no-cache|private)/.test(cc)){c.put(key,dbv9Stamp(resp.clone())).then(()=>dbv9TrimDocs(c)).catch(()=>{});}
    }
    return resp;
  })();
  dbv9DocInflight.set(keyUrl,p);
  p.then(()=>dbv9DocInflight.delete(keyUrl),()=>dbv9DocInflight.delete(keyUrl));
  return p;
}
async function dbv9Document(e,r,u){
  const c=await caches.open(DBV9_DOCS);
  const keyUrl=u.origin+u.pathname+u.search;
  const key=new Request(keyUrl,{headers:{'Accept':'text/html'}});
  const hit=await c.match(key);
  if(hit&&dbv9Fresh(hit)){if(!dbv9DocInflight.has(keyUrl))e.waitUntil(dbv9DocFetch(e,r,c,key,keyUrl).catch(()=>{}));return hit;}
  const fresh=await dbv9DocFetch(e,r,c,key,keyUrl);
  /* Every consumer gets its own copy; the shared response is never read directly. */
  if(fresh)return fresh.clone();
  if(hit)return hit;
  return new Response(DBV9_OFFLINE,{status:503,headers:{'Content-Type':'text/html; charset=utf-8','Cache-Control':'no-store'}});
}
self.addEventListener('fetch',e=>{
  const r=e.request;if(r.method!=='GET')return;
  let u;try{u=new URL(r.url);}catch(_){return;}
  if(u.origin!==self.location.origin||DBV9_PRIVATE.test(u.pathname)||DBV9_SENSITIVE_Q.test(u.search.replace(/^\?/,'')))return;
  const isDoc=r.mode==='navigate'||r.headers.get('X-Delicat-Shell')==='1';
  if(isDoc){if(DBV9_DOC_CACHE&&!/\.(?:xml|txt|json)$/i.test(u.pathname)&&!/[?&](?:s|preview|delicat_builder_preview)=/i.test(u.search))e.respondWith(dbv9Document(e,r,u));return;}
  if(!DBV9_STATIC_CACHE)return;
  const d=r.destination||'';
  const isStatic=['style','script','image','font'].includes(d)||/\.(?:css|js|png|jpe?g|gif|webp|avif|svg|woff2?)(?:$|\?)/i.test(u.pathname);
  if(!isStatic)return;
  /* RC55: a cached hit used to be returned AND re-fetched from the network every
     time (stale-while-revalidate) — for `?ver=`-stamped plugin/WordPress/Woo files
     and media-library images that is pure data waste on a metered phone. A
     versioned URL never changes; an upload is revalidated at most once per 6 h.
     Everything else keeps the previous stale-while-revalidate behaviour. */
  /* pro.17: every Builder asset is served as `<name>.<12 hex>.<ext>` with no
     query string, so the ?ver test alone called the plugin's own CSS and JS
     unversioned and re-fetched each one on every page view behind its own cache
     hit. A content-addressed name is immutable by construction — a changed file
     gets a different name — so treat it exactly like a ?ver stamp. */
  const versioned=/[?&]ver=/.test(u.search)||/\.[0-9a-f]{12}\.(?:css|js)$/i.test(u.pathname);
  const upload=/\/wp-content\/uploads\//.test(u.pathname);
  e.respondWith(caches.open(DBV9_CACHE).then(async c=>{
    const hit=await c.match(r);
    if(hit&&versioned)return hit;
    if(hit&&upload){const at=Number(hit.headers.get('x-dbv9-cached-at'))||0;if(at&&(Date.now()-at)<21600000)return hit;}
    const net=fetch(r).then(resp=>{
      const cc=(resp.headers.get('cache-control')||'').toLowerCase();
      if(resp.ok&&resp.type!=='opaque'&&!/(?:no-store|private)/.test(cc)){c.put(r,dbv9Stamp(resp.clone())).then(dbv9Trim).catch(()=>{});}
      return resp;
    }).catch(()=>hit);
    return hit||net;
  }));
});
		<?php
		exit;
	}

	public static function head(): void {
		if ( is_admin() ) { return; }
		$s = self::settings();
		$theme = sanitize_hex_color( $s['theme_color'] ) ?: '#11104a';
		echo '<link rel="manifest" href="' . esc_url( home_url( '/delicat-v9-manifest.webmanifest' ) ) . '">' . "\n";
		echo '<meta name="theme-color" content="' . esc_attr( $theme ) . '">' . "\n";
		echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
		echo '<meta name="apple-mobile-web-app-title" content="Delicat Store">' . "\n";
		$icon = self::icon_url( 192 );
		if ( $icon ) { echo '<link rel="apple-touch-icon" href="' . esc_url( $icon ) . '">' . "\n"; }
	}

	public static function enqueue_runtime(): void {
		if ( is_admin() ) { return; }
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) { return; }
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/pwa-runtime.css';
		$js = DELICAT_BUILDER_V9_DIR . 'assets/js/pwa-runtime.js';
		if ( ! empty( $s['install_ui'] ) && is_file( $css ) ) {
			wp_enqueue_style( 'delicat-builder-v9-pwa-runtime', DELICAT_BUILDER_V9_URL . 'assets/css/pwa-runtime.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		if ( ! is_file( $js ) ) { return; }
		wp_enqueue_script( 'delicat-builder-v9-pwa-runtime', DELICAT_BUILDER_V9_URL . 'assets/js/pwa-runtime.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-pwa-runtime', 'strategy', 'defer' );
		}
		$sw = wp_make_link_relative( home_url( '/delicat-v9-sw.js' ) );
		$scope = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$scope = is_string( $scope ) && '' !== $scope ? trailingslashit( $scope ) : '/';
		$config = array( 'serviceWorker' => $sw, 'scope' => $scope, 'installUI' => empty( $s['install_ui'] ) ? 0 : 1 );
		wp_add_inline_script( 'delicat-builder-v9-pwa-runtime', 'window.DelicaPWARuntime=' . wp_json_encode( $config ) . ';', 'before' );
	}

	public static function shortcode( $atts = array() ): string {
		$s = self::settings();
		if ( empty( $s['enabled'] ) || empty( $s['install_ui'] ) ) { return ''; }
		$atts = shortcode_atts( array( 'text' => 'Installer Delicat Store', 'class' => '' ), $atts, 'delicat_install_app' );
		$class = 'delicat-builder-v9-install-app';
		if ( '' !== $atts['class'] ) { $class .= ' ' . sanitize_html_class( $atts['class'] ); }
		return '<button type="button" class="' . esc_attr( $class ) . '" data-db-v9-pwa-open>' . esc_html( sanitize_text_field( $atts['text'] ) ) . '</button>';
	}
}
