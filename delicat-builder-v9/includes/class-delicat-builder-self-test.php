<?php
/**
 * Delicat Builder V9 — Self-Test v2 (9.1 RC6, TLS-verified per RC7 audit).
 *
 * Lessons from the first real report: frontend-only modules must not read as
 * failures in admin; failure logs never expire and must distinguish a
 * historical resolved incident from an active one (and be clearable); and the
 * checkout surface must be fetched WITH a seeded cart, because an empty-cart
 * checkout legitimately has no dock. Read-only except the explicit
 * "Clear failure logs" action.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_Self_Test {

	private const FRONTEND_ONLY = array( 'Header Runtime', 'Purchase Native', 'Footer' );

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Self-Test', 'delicat-builder-v9' ),
			__( 'Self-Test', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-self-test',
			array( __CLASS__, 'render' )
		);
	}

	private static function modules(): array {
		return array(
			'Core'            => 'Delicat_Builder_V9_Core',
			'Header Runtime'  => 'Delicat_Builder_V9_Header_Runtime',
			'Menu Runtime'    => 'delicat_builder_v9_menu_render_menu_items',
			'Storefront Fix'  => 'Delicat_Builder_V9_Storefront_Fix',
			'Native Product'  => 'Delicat_Builder_V9_Native_Product',
			'Purchase Native' => 'Delicat_Builder_V9_Purchase_Native',
			'Woo UI'          => 'Delicat_Builder_V9_Woo_UI',
			'Footer'          => 'Delicat_Builder_V9_Footer',
			'Security'        => 'Delicat_Builder_V9_Security',
			'Reviews'         => 'Delicat_Builder_V9_Reviews',
			'Shell'           => 'Delicat_Builder_V9_Shell',
		);
	}

	private static function failure_row( string $option, string $label ): array {
		$val  = get_option( $option, array() );
		$list = array_values( array_filter( is_array( $val ) ? $val : array( $val ) ) );
		if ( empty( $list ) ) {
			return array( $label, true, 'none' );
		}
		$newest = 0;
		foreach ( $list as $entry ) {
			$t = is_array( $entry ) ? strtotime( (string) ( $entry['time'] ?? '' ) ) : 0;
			$newest = max( $newest, (int) $t );
		}
		$safe_off = class_exists( 'Delicat_Builder_V9_Core', false )
			&& is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) )
			&& ! Delicat_Builder_V9_Core::is_safe_mode();
		$detail = wp_json_encode( array_slice( $list, -2 ) );
		if ( $newest && $safe_off && ( time() - $newest ) > 2 * DAY_IN_SECONDS ) {
			return array(
				$label,
				true,
				sprintf( 'historical, resolved — newest %s, Safe Mode off. Entries kept for forensics; use "Clear failure logs" to reset. %s', gmdate( 'Y-m-d H:i', $newest ), $detail ),
			);
		}
		return array( $label, false, $detail );
	}

	private static function internal_checks(): array {
		$rows = array();

		$safe = class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) )
			? Delicat_Builder_V9_Core::is_safe_mode() : null;
		$rows[] = array( 'Safe Mode', false === $safe, null === $safe ? 'Core absent' : ( $safe ? 'ON — degraded' : 'off (normal)' ) );

		$rows[] = self::failure_row( 'delicat_builder_v9_boot_failures', 'Boot failures' );
		$rows[] = self::failure_row( 'delicat_builder_v9_module_load_failures', 'Module load failures' );
		$rows[] = self::failure_row( 'delicat_builder_v9_shell_failures', 'Shell failures' );

		$schema = absint( get_option( 'delicat_builder_v9_schema', 0 ) );
		$rows[] = array( 'Migration schema', 6 === $schema, 'schema=' . $schema . ( 6 === $schema ? ' (current)' : ' (expected 6)' ) );

		global $wpdb;
		$auto = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", 'delicat_builder_v9_settings' ) );
		$rows[] = array( 'Hot-path autoload', in_array( (string) $auto, array( 'yes', 'on', 'auto-on', 'auto' ), true ), 'delicat_builder_v9_settings autoload=' . ( $auto ? $auto : 'n/a' ) );

		foreach ( self::modules() as $label => $symbol ) {
			$present = class_exists( $symbol, false ) || function_exists( $symbol );
			if ( ! $present && in_array( $label, self::FRONTEND_ONLY, true ) ) {
				$rows[] = array( 'Module: ' . $label, true, 'frontend-only — verified by the surface fetches below' );
				continue;
			}
			$rows[] = array( 'Module: ' . $label, $present, $present ? 'loaded' : 'NOT loaded' );
		}

		$front_id = (int) get_option( 'page_on_front', 0 );
		if ( $front_id > 0 && class_exists( 'Delicat_Builder_V9_Compiler', false ) ) {
			$mf = get_post_meta( $front_id, '_delicat_builder_v9_manifest', true );
			$mv = is_array( $mf ) ? (string) ( $mf['version'] ?? '' ) : '';
			if ( '' === $mv ) {
				$rows[] = array( 'Homepage compiled bundle', true, 'no bundle — serving live component CSS (valid fallback)' );
			} else {
				$rows[] = array( 'Homepage compiled bundle', DELICAT_BUILDER_V9_VERSION === $mv, 'manifest version ' . $mv . ' vs plugin ' . DELICAT_BUILDER_V9_VERSION . ( DELICAT_BUILDER_V9_VERSION === $mv ? ' (current)' : ' — STALE; open any admin page to auto-recompile' ) );
			}
		}

		/*
		 * 9.1 RC13: the page-cache bridge. Every purge this plugin performs
		 * fires `litespeed_purge_all`; if the LiteSpeed Cache plugin is not
		 * active, that action has no listeners — purges are silent no-ops AND
		 * nothing is full-page cached, so every visitor pays full dynamic PHP
		 * (~400 ms) instead of a cache hit (~5 ms). This is the single largest
		 * speed factor on the site, so it is asserted explicitly.
		 */
		$ls_active  = defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\\Core', false ) || class_exists( 'LiteSpeed_Cache', false );
		$has_bridge = has_action( 'litespeed_purge_all' );
		$rows[]     = array(
			'Page cache engine (LiteSpeed)',
			$ls_active,
			$ls_active ? 'LiteSpeed Cache plugin active — full-page caching available' : 'NOT ACTIVE — every visitor pays full dynamic PHP and all plugin purges are no-ops. Install/activate LiteSpeed Cache (Hostinger ships it), enable cache, exclude Cart/Checkout/My Account.',
		);
		$rows[]     = array(
			'Purge bridge listeners',
			(bool) $has_bridge,
			$has_bridge ? 'litespeed_purge_all has listeners — purges propagate' : 'no listeners on litespeed_purge_all — purge calls do nothing',
		);

		$rows[] = array( 'Elementor absent', ! defined( 'ELEMENTOR_VERSION' ), defined( 'ELEMENTOR_VERSION' ) ? 'Elementor is active!' : 'confirmed removed' );
		$rows[] = array( 'WooCommerce', defined( 'WC_VERSION' ), defined( 'WC_VERSION' ) ? 'v' . WC_VERSION : 'missing' );
		$customer_location = sanitize_key( (string) get_option( 'woocommerce_default_customer_address', 'base' ) );
		$rows[] = array(
			'Woo customer-location cache mode',
			'geolocation' !== $customer_location,
			'geolocation' === $customer_location
				? 'BLOCKED — plain IP geolocation varies one public URL. Use Shop country/region when output is fixed, or Geolocate (with page caching support).'
				: 'mode=' . $customer_location . ( 'geolocation_ajax' === $customer_location ? ' (page-cache-compatible Woo mode)' : '' ),
		);
		return $rows;
	}

	/** Fetch a URL, returning [code, body, ms, cookies, headers]. */
	private static function fetch( string $url, array $cookies = array() ): array {
		$start    = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 12,
				'redirection' => 0,
				'sslverify'   => true,
				'user-agent'  => 'DelicatSelfTest/9.1',
				'cookies'     => $cookies,
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $response ) ) {
			return array( 0, $response->get_error_message(), $ms, array(), array() );
		}
		$jar = array();
		foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
			if ( $cookie instanceof WP_Http_Cookie ) {
				$jar[] = $cookie;
			}
		}
		$headers = array();
		foreach ( array( 'cf-cache-status', 'x-litespeed-cache', 'x-litespeed-cache-control', 'age', 'cache-control', 'server', 'content-encoding', 'x-qc-cache' ) as $header_name ) {
			$value = wp_remote_retrieve_header( $response, $header_name );
			if ( '' !== $value && null !== $value ) {
				$headers[ $header_name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}
		return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ), $ms, $jar, $headers );
	}

	private static function surface_checks(): array {
		$rows = array();

		$targets = array(
			array( 'Accueil', home_url( '/' ), 'data-delicat-universal-footer' ),
			array( 'Boutique', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/shop/' ), 'data-delicat-universal-footer' ),
			array( 'Panier', function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ), 'data-delicat-universal-footer' ),
		);
		$product = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( ! empty( $product ) ) {
			$targets[] = array( 'Produit', get_permalink( (int) $product[0] ), 'dnp-' );
		}
		$edge_done = false;
		foreach ( $targets as $t ) {
			list( $label, $url, $marker ) = $t;
			list( $code, $body, $ms, $cookies, $hdrs ) = self::fetch( $url );

			/*
			 * 9.1 RC12: edge-cache forensics on the first (home) fetch. The
			 * signed-out audit found guests receiving a weeks-old snapshot —
			 * legacy /categorie-produit/ menu links, no native footer — while
			 * origin served fresh pages: the edge layer was never purged
			 * (Cloudflare Turbo unconfigured). These rows make cache-layer
			 * state and guest freshness visible on every run.
			 */
			if ( ! $edge_done && 200 === $code ) {
				$edge_done   = true;
				$cf          = strtoupper( (string) ( $hdrs['cf-cache-status'] ?? '' ) );
				$hdr_note    = array();
				foreach ( $hdrs as $hk => $hv ) { $hdr_note[] = $hk . '=' . $hv; }
				$fresh       = ( 1 === substr_count( $body, 'data-delicat-universal-footer' ) ) && ( false === strpos( $body, '/categorie-produit/' ) );
				$rows[]      = array(
					'Guest HTML freshness (edge)',
					$fresh,
					$fresh
						? 'current build markers present (footer yes, legacy category links none)'
						: 'STALE SNAPSHOT to guests — purge everything at the edge (Cloudflare) now; ' . implode( ' · ', $hdr_note ),
				);
				$cf_ready = class_exists( 'Delicat_Builder_V9_Cloudflare', false )
					&& is_callable( array( 'Delicat_Builder_V9_Cloudflare', 'enabled' ) )
					&& Delicat_Builder_V9_Cloudflare::enabled();
				if ( 'HIT' === $cf && ! $cf_ready ) {
					$rows[] = array( 'Cloudflare purge wiring', false, 'edge is caching HTML (cf-cache-status=HIT) but Cloudflare Turbo has no zone/token — every content purge misses guests. Configure Cloudflare Turbo, then press its Purge All once.' );
				} else {
					$rows[] = array( 'Cloudflare purge wiring', true, $cf_ready ? 'configured — purges propagate to the edge automatically' : ( '' === $cf ? 'edge not observed caching HTML on this fetch' : 'cf-cache-status=' . $cf ) );
				}
				$rows[] = array( 'Cache headers (home)', true, empty( $hdr_note ) ? 'none exposed' : implode( ' · ', $hdr_note ) );

				$cookie_names = array();
				foreach ( $cookies as $cookie ) {
					if ( $cookie instanceof WP_Http_Cookie && is_string( $cookie->name ?? null ) ) {
						$cookie_names[] = strtolower( (string) $cookie->name );
					}
				}
				$cookie_names = array_values( array_unique( $cookie_names ) );
				$cache_control = strtolower( (string) ( $hdrs['cache-control'] ?? '' ) );
				$ls_control = strtolower( (string) ( $hdrs['x-litespeed-cache-control'] ?? '' ) );
				$public_headers = false === strpos( $cache_control, 'private' )
					&& false === strpos( $cache_control, 'no-store' )
					&& false === strpos( $ls_control, 'no-cache' );
				$rows[] = array(
					'Anonymous document cache policy',
					$public_headers,
					$public_headers ? 'public-cache candidate' : 'BLOCKED — normal GET is private/no-store or LiteSpeed no-cache; fix Identity/location policy before edge caching',
				);
				$rows[] = array(
					'Default currency is cookie-free',
					! in_array( 'dmc_currency', $cookie_names, true ),
					in_array( 'dmc_currency', $cookie_names, true ) ? 'BLOCKED — anonymous default response still emits dmc_currency' : 'no default dmc_currency cookie',
				);
				$rows[] = array(
					'Identity return cookie is intent-only',
					! in_array( 'dip_return_url', $cookie_names, true ),
					in_array( 'dip_return_url', $cookie_names, true ) ? 'BLOCKED — Delicat Identity sets dip_return_url on a normal page view; set it only when login begins' : 'normal page view emits no dip_return_url',
				);

				/* Second identical fetch: a working full-page cache answers the
				 * repeat far faster than the first. This measures the single
				 * biggest speed lever on the site instead of guessing at it. */
				list( $code2, , $ms2, , $hdrs2 ) = self::fetch( $url );
				$ls2   = strtoupper( (string) ( $hdrs2['x-litespeed-cache'] ?? '' ) );
				$hit   = ( false !== strpos( $ls2, 'HIT' ) ) || ( 'HIT' === strtoupper( (string) ( $hdrs2['cf-cache-status'] ?? '' ) ) );
				$fast  = $ms2 > 0 && $ms2 <= 120;
				$rows[] = array(
					'Full-page cache effectiveness',
					( 200 === $code2 ) && ( $hit || $fast ),
					sprintf(
						'first %d ms → repeat %d ms%s%s. %s',
						$ms,
						$ms2,
						'' !== $ls2 ? ' · x-litespeed-cache=' . $ls2 : '',
						isset( $hdrs2['content-encoding'] ) ? ' · encoding=' . $hdrs2['content-encoding'] : ' · NO COMPRESSION on HTML',
						( $hit || $fast ) ? 'guests are served from cache — this is where the 80%+ lives' : 'repeat request still dynamic: enable LiteSpeed full-page cache (excluding Cart/Checkout/My Account) for a ~10-50x drop'
					),
				);
			}
			$footer_count = substr_count( $body, 'data-delicat-universal-footer' );
			$legacy_footer = (bool) preg_match( '/<footer[^>]+(?:dnp-footer|delicat-shell--footer|delicat-v9-native-footer)/i', $body );
			$ok   = 200 === $code
				&& false !== strpos( $body, $marker )
				&& 1 === $footer_count
				&& ! $legacy_footer;
			$note = $code
				? sprintf( 'HTTP %d · %d ms · %d KB · marker "%s" %s · universal footers %d · legacy footer %s', $code, $ms, (int) round( strlen( $body ) / 1024 ), $marker, false !== strpos( $body, $marker ) ? 'found' : 'MISSING', $footer_count, $legacy_footer ? 'FOUND' : 'none' )
				: 'fetch error: ' . $body;
			$rows[] = array( $label . ' — ' . esc_url_raw( $url ), $ok, $note );
		}

		/*
		 * Checkout is fetched with a SEEDED session: an empty-cart checkout
		 * legitimately renders no place-order button and no dock, so the old
		 * marker assertion produced a false FAIL. Seed via add-to-cart on the
		 * first simple product, carry the session cookies, then judge: form
		 * present -> dock required; form absent -> state noted, not failed.
		 */
		$checkout = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
		$jar      = array();
		$seeded   = false;
		if ( function_exists( 'wc_get_products' ) ) {
			$simple = wc_get_products( array( 'type' => 'simple', 'status' => 'publish', 'limit' => 1, 'return' => 'ids' ) );
			if ( ! empty( $simple ) ) {
				list( , , , $jar ) = self::fetch( add_query_arg( 'add-to-cart', (int) $simple[0], home_url( '/' ) ) );
				$seeded = ! empty( $jar );
			}
		}
		list( $code, $body, $ms ) = self::fetch( $checkout, $jar );
		$kb       = (int) round( strlen( $body ) / 1024 );
		$has_form = false !== strpos( $body, 'place_order' );
		$has_dock = false !== strpos( $body, 'dpn-checkout-dock' );
		if ( 200 !== $code ) {
			$rows[] = array( 'Paiement — ' . esc_url_raw( $checkout ), false, 'HTTP ' . $code . ' · ' . $ms . ' ms' );
		} elseif ( $has_form ) {
			$rows[] = array(
				'Paiement — ' . esc_url_raw( $checkout ),
				$has_dock,
				sprintf( 'HTTP 200 · %d ms · %d KB · %scheckout form present · dock %s', $ms, $kb, $seeded ? 'seeded cart · ' : '', $has_dock ? 'found' : 'MISSING' ),
			);
		} else {
			$rows[] = array(
				'Paiement — ' . esc_url_raw( $checkout ),
				true,
				sprintf( 'HTTP 200 · %d ms · %d KB · empty-cart render (no place-order form)%s — dock not applicable; confirm once manually with items', $ms, $kb, $seeded ? ' despite seed attempt' : '' ),
			);
		}
		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$nonce_ok = isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'dbv9_self_test' );
		$run      = isset( $_GET['run'] ) && $nonce_ok;

		if ( isset( $_GET['clear_logs'] ) && $nonce_ok ) {
			delete_option( 'delicat_builder_v9_boot_failures' );
			delete_option( 'delicat_builder_v9_module_load_failures' );
			delete_option( 'delicat_builder_v9_shell_failures' );
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Failure logs cleared.', 'delicat-builder-v9' ) . '</p></div>';
		}

		echo '<div class="wrap"><h1>Delicat Builder — Self-Test</h1><p>';
		echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'run' => '1', 'clear_logs' => false ) ), 'dbv9_self_test' ) ) . '">' . esc_html__( 'Run self-test', 'delicat-builder-v9' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'clear_logs' => '1', 'run' => false ) ), 'dbv9_self_test' ) ) . '" onclick="return confirm(\'Effacer les journaux de pannes ?\');">' . esc_html__( 'Clear failure logs', 'delicat-builder-v9' ) . '</a></p>';
		if ( ! $run ) { echo '</div>'; return; }

		$report = array();
		echo '<table class="widefat striped"><thead><tr><th>Check</th><th>State</th><th>Detail</th></tr></thead><tbody>';
		foreach ( array_merge( self::internal_checks(), self::surface_checks() ) as $row ) {
			list( $label, $pass, $detail ) = $row;
			$report[] = ( $pass ? '[PASS] ' : '[FAIL] ' ) . $label . ' — ' . $detail;
			printf(
				'<tr><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( $label ),
				$pass ? '<span style="color:#12915b;font-weight:800">PASS</span>' : '<span style="color:#d92d20;font-weight:800">FAIL</span>',
				esc_html( (string) $detail )
			);
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Copy-ready report', 'delicat-builder-v9' ) . '</h2>';
		echo '<textarea readonly rows="14" style="width:100%;font-family:monospace" onclick="this.select()">'
			. esc_textarea( 'Delicat Builder ' . DELICAT_BUILDER_V9_VERSION . ' self-test — ' . gmdate( 'c' ) . "\n" . implode( "\n", $report ) )
			. '</textarea></div>';
	}
}

if ( is_admin() ) {
	Delicat_Builder_V9_Self_Test::boot();
}
