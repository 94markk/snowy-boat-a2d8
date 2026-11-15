<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Admin-only live diagnostics. Never writes or exposes private storefront data. */
final class Delicat_Builder_V9_Turbo_Diagnostics {
	public static function boot(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render_overlay' ), 99999 );
	}

	public static function requested(): bool {
		$settings = get_option( 'delicat_builder_v9_performance', array() );
		if ( is_array( $settings ) && array_key_exists( 'turbo_diagnostics', $settings ) && empty( $settings['turbo_diagnostics'] ) ) {
			return false;
		}
		return ! is_admin()
			&& is_user_logged_in()
			&& current_user_can( 'manage_options' )
			&& isset( $_GET['dbv9_turbo_diag'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostics switch.
			&& '1' === sanitize_text_field( wp_unslash( $_GET['dbv9_turbo_diag'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	public static function environment(): array {
		$query_stats = class_exists( 'Delicat_Builder_V9_Query_Cache', false ) ? Delicat_Builder_V9_Query_Cache::stats() : array();
		return array(
			'object_cache' => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'opcache'      => function_exists( 'opcache_get_status' ) ? (bool) @opcache_get_status( false ) : false, // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			'cache_version'=> class_exists( 'Delicat_Builder_V9_Cache', false ) ? Delicat_Builder_V9_Cache::version() : 0,
			'query_cache'  => $query_stats,
			'safe_mode'    => class_exists( 'Delicat_Builder_V9_Core', false ) ? Delicat_Builder_V9_Core::is_safe_mode() : false,
			'last_failure' => get_option( 'delicat_builder_v9_last_server_engine_failure', array() ),
		);
	}

	private static function local_asset_bytes( string $handle, string $type ): int {
		$registry = 'script' === $type ? wp_scripts() : wp_styles();
		if ( ! $registry || empty( $registry->registered[ $handle ] ) ) {
			return 0;
		}
		$src = (string) $registry->registered[ $handle ]->src;
		if ( '' === $src || 0 !== strpos( $src, DELICAT_BUILDER_V9_URL ) ) {
			return 0;
		}
		$relative = ltrim( (string) wp_parse_url( str_replace( DELICAT_BUILDER_V9_URL, '', $src ), PHP_URL_PATH ), '/' );
		$path = DELICAT_BUILDER_V9_DIR . $relative;
		return is_file( $path ) ? absint( filesize( $path ) ) : 0;
	}

	public static function render_overlay(): void {
		if ( ! self::requested() ) {
			return;
		}

		$scripts = wp_scripts();
		$styles = wp_styles();
		$script_handles = array_values( array_filter( (array) ( $scripts->queue ?? array() ), static fn( $h ) => 0 === strpos( (string) $h, 'delicat-builder-v9-' ) ) );
		$style_handles = array_values( array_filter( (array) ( $styles->queue ?? array() ), static fn( $h ) => 0 === strpos( (string) $h, 'delicat-builder-v9-' ) ) );
		$js_bytes = array_sum( array_map( static fn( $h ) => self::local_asset_bytes( (string) $h, 'script' ), $script_handles ) );
		$css_bytes = array_sum( array_map( static fn( $h ) => self::local_asset_bytes( (string) $h, 'style' ), $style_handles ) );
		$query_stats = class_exists( 'Delicat_Builder_V9_Query_Cache', false ) ? Delicat_Builder_V9_Query_Cache::stats() : array();
		$cache_state = class_exists( 'Delicat_Builder_V9_Server_Engine', false ) ? Delicat_Builder_V9_Server_Engine::cache_state() : 'N/A';
		$elapsed = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? max( 0, microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) : 0;
		?>
		<aside id="dbv9-turbo-diagnostics" style="position:fixed;z-index:2147483000;right:12px;bottom:12px;max-width:min(430px,calc(100vw - 24px));padding:14px 16px;border:1px solid rgba(255,255,255,.18);border-radius:16px;background:rgba(9,13,32,.96);box-shadow:0 16px 45px rgba(0,0,0,.28);color:#fff;font:500 12px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" aria-label="Delicat Builder V10 Pro diagnostics">
			<strong style="display:block;font-size:14px;margin-bottom:7px">Builder V10 Pro diagnostics</strong>
			<div>SSR body cache: <b><?php echo esc_html( $cache_state ); ?></b> · Request: <b><?php echo esc_html( number_format_i18n( $elapsed * 1000, 1 ) ); ?> ms</b></div>
			<div>DB queries: <b><?php echo esc_html( (string) get_num_queries() ); ?></b> · Peak memory: <b><?php echo esc_html( size_format( memory_get_peak_usage( true ) ) ); ?></b></div>
			<div>V9 JS: <b><?php echo esc_html( size_format( $js_bytes ) ); ?></b> (<?php echo esc_html( (string) count( $script_handles ) ); ?>) · CSS: <b><?php echo esc_html( size_format( $css_bytes ) ); ?></b> (<?php echo esc_html( (string) count( $style_handles ) ); ?>)</div>
			<div>Query cache: <b><?php echo ! empty( $query_stats['persistent'] ) ? 'Persistent object cache' : 'Transient fallback'; ?></b> · H/M <?php echo esc_html( (string) ( $query_stats['hits'] ?? 0 ) ); ?>/<?php echo esc_html( (string) ( $query_stats['misses'] ?? 0 ) ); ?></div>
			<div id="dbv9-turbo-overflow" style="margin-top:7px;word-break:break-word">Page width: …</div>
			<div style="margin-top:7px;opacity:.72">Visible only to administrators with <code style="color:inherit">?dbv9_turbo_diag=1</code>.</div>
		</aside>
		<script>
		/* 9.14.3–9.14.4: what makes the page wider than the screen (iPhone Safari then zooms the whole
		   page out). Read-only, administrators with the switch only. The page-width guard is lifted for
		   a moment to measure the raw width, then blocks are hidden one at a time (display:none, put
		   back at once) to follow the width down to the element that causes it, whatever it is: a
		   box, a ::before/::after, a long unbroken text, a widget of another plugin. */
		(function () {
			var de = document.documentElement;
			function label(el) {
				var s = el.tagName.toLowerCase();
				if (el.id) { s += '#' + el.id; }
				var c = typeof el.className === 'string' ? el.className : (el.getAttribute('class') || '');
				c = c.trim().split(/\s+/).slice(0, 2).join('.');
				return c ? s + '.' + c : s;
			}
			function width() { return de.scrollWidth; }
			function hide(el) {
				var v = el.style.getPropertyValue('display'), p = el.style.getPropertyPriority('display');
				el.style.setProperty('display', 'none', 'important');
				return function () { if (v) { el.style.setProperty('display', v, p); } else { el.style.removeProperty('display'); } };
			}
			function pseudo(el, which) {
				var cs = window.getComputedStyle(el, which);
				if (!cs || cs.content === 'none' || cs.content === 'normal' || cs.display === 'none') { return ''; }
				return which + ' ' + cs.position + ' w=' + cs.width + ' l=' + cs.left + ' r=' + cs.right + (cs.transform !== 'none' ? ' t=' + cs.transform : '');
			}
			function describe(el) {
				var cs = window.getComputedStyle(el), r = el.getBoundingClientRect();
				var bits = [cs.position, 'x ' + Math.round(r.left) + '–' + Math.round(r.right), 'w=' + cs.width];
				if (cs.transform !== 'none') { bits.push('t=' + cs.transform); }
				if (cs.whiteSpace !== 'normal') { bits.push('ws=' + cs.whiteSpace); }
				var b = pseudo(el, '::before'), a = pseudo(el, '::after');
				if (b) { bits.push(b); }
				if (a) { bits.push(a); }
				if (!el.children.length) { bits.push('text "' + (el.textContent || '').trim().slice(0, 40) + '"'); }
				return label(el) + ' [' + bits.join(', ') + ']';
			}
			function lift() {
				var body = document.body, undo = [];
				[['position', 'static'], ['overflow-x', 'visible']].forEach(function (pair) {
					var v = body.style.getPropertyValue(pair[0]), p = body.style.getPropertyPriority(pair[0]);
					body.style.setProperty(pair[0], pair[1], 'important');
					undo.push(function () { if (v) { body.style.setProperty(pair[0], v, p); } else { body.style.removeProperty(pair[0]); } });
				});
				return function () { undo.forEach(function (f) { f(); }); };
			}
			function trace(vw) {
				var node = document.body, path = [], current = width(), steps = 0;
				while (node && steps++ < 40 && current > vw + 1) {
					var best = null, bestW = current, kids = node.children;
					for (var i = 0; i < kids.length; i++) {
						var k = kids[i];
						if (k.id === 'dbv9-turbo-diagnostics' || k.tagName === 'SCRIPT' || k.tagName === 'STYLE') { continue; }
						var put = hide(k), w = width();
						put();
						if (w < bestW - 1) { best = k; bestW = w; }
					}
					if (!best) { break; }
					path.push(describe(best) + ' (−' + (current - bestW) + ' px)');
					path.leaf = best;
					node = best;
				}
				var bb = pseudo(document.body, '::before') || pseudo(document.body, '::after');
				if (!path.length && bb) { path.push('body ' + bb); }
				return path;
			}
			function run() {
				var box = document.getElementById('dbv9-turbo-overflow');
				if (!box) { return; }
				var vw = de.clientWidth, guarded = width(), undo = lift(), raw = width(), causes = [], parked = [];
				try {
					/* Up to three separate causes: each one found is kept hidden while the next is traced. */
					for (var round = 0; round < 3 && width() > vw + 1; round++) {
						var path = trace(vw);
						if (!path.length) { break; }
						causes.push(path[path.length - 1] + (path.length > 1 ? ' in ' + path.slice(0, -1).map(function (step) { return step.split(' [')[0]; }).join(' › ') : ''));
						var leaf = path.leaf;
						if (!leaf) { break; }
						parked.push(hide(leaf));
					}
				} finally {
					parked.reverse().forEach(function (put) { put(); });
					undo();
				}
				box.textContent = 'Page width: ' + guarded + ' / screen ' + vw + ' px · without the guard: ' + raw + ' px'
					+ (raw > vw + 1 ? ' · Cause: ' + (causes.length ? causes.join(' | ') : 'not found (send this screen)') : ' · nothing past the edge');
			}
			if (document.readyState === 'complete') { setTimeout(run, 1500); } else { window.addEventListener('load', function () { setTimeout(run, 1500); }); }
		}());
		</script>
		<?php
	}
}
