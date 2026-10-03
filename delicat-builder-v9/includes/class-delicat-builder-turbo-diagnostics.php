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
		<aside id="dbv9-turbo-diagnostics" style="position:fixed;z-index:2147483000;right:12px;bottom:12px;max-width:min(430px,calc(100vw - 24px));padding:14px 16px;border:1px solid rgba(255,255,255,.18);border-radius:16px;background:rgba(9,13,32,.96);box-shadow:0 16px 45px rgba(0,0,0,.28);color:#fff;font:500 12px/1.5 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif" aria-label="Delicat V9 Turbo diagnostics">
			<strong style="display:block;font-size:14px;margin-bottom:7px">V9 Turbo Diagnostics · RC51.34</strong>
			<div>SSR body cache: <b><?php echo esc_html( $cache_state ); ?></b> · Request: <b><?php echo esc_html( number_format_i18n( $elapsed * 1000, 1 ) ); ?> ms</b></div>
			<div>DB queries: <b><?php echo esc_html( (string) get_num_queries() ); ?></b> · Peak memory: <b><?php echo esc_html( size_format( memory_get_peak_usage( true ) ) ); ?></b></div>
			<div>V9 JS: <b><?php echo esc_html( size_format( $js_bytes ) ); ?></b> (<?php echo esc_html( (string) count( $script_handles ) ); ?>) · CSS: <b><?php echo esc_html( size_format( $css_bytes ) ); ?></b> (<?php echo esc_html( (string) count( $style_handles ) ); ?>)</div>
			<div>Query cache: <b><?php echo ! empty( $query_stats['persistent'] ) ? 'Persistent object cache' : 'Transient fallback'; ?></b> · H/M <?php echo esc_html( (string) ( $query_stats['hits'] ?? 0 ) ); ?>/<?php echo esc_html( (string) ( $query_stats['misses'] ?? 0 ) ); ?></div>
			<div style="margin-top:7px;opacity:.72">Visible only to administrators with <code style="color:inherit">?dbv9_turbo_diag=1</code>.</div>
		</aside>
		<?php
	}
}
