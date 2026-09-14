<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maintenance Studio runtime — RC40.
 *
 * One job, done completely: while maintenance is enabled the public site is
 * closed to everyone except administrators, and every other visitor receives a
 * modern, self-contained 503 page.
 *
 * Design rules, all deliberate:
 *
 *  - The gate is booted directly by the plugin bootstrap, NOT through
 *    Delicat_Builder_V9_Core. Safe Mode, the dependency gate and foreign AJAX
 *    all skip the Core graph; a lock that disappears in those states is not a
 *    lock. This module is therefore self-sufficient and references optional
 *    siblings only behind class_exists().
 *  - wp-admin, wp-login.php and WP-Cron are never gated, so an administrator
 *    can always sign in and switch maintenance back off.
 *  - The response is a real 503 with Retry-After: search engines hold the
 *    rankings instead of deindexing the store.
 *  - The document is standalone (no wp_head, no theme, no plugin assets, no
 *    external font or image request). Its CSS and JS are inlined from the two
 *    plugin-owned asset files, so the page renders in one request even on a
 *    slow mobile connection, and a broken theme cannot break it.
 *  - Nothing is cached: DONOTCACHEPAGE, LiteSpeed no-cache and no-store, plus
 *    an instruction to the service worker to drop its document cache so a
 *    returning visitor cannot be served yesterday's storefront.
 */
final class Delicat_Builder_V9_Maintenance {
	public const OPTION = 'delicat_builder_v9_maintenance';
	public const COOKIE = 'delicat_v9_maintenance_pass';

	/** Templates shipped by this release. */
	public const TEMPLATES = array( 'flash', 'aurora', 'midnight', 'minimal', 'boutique', 'spotlight' );

	private static bool $booted = false;
	private static ?array $settings_cache = null;
	private static ?bool $locked_cache = null;
	private static bool $rendering = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		/* Bypass-link exchange, AJAX and XML-RPC run before any template. */
		add_action( 'init', array( __CLASS__, 'guard_early' ), 1 );

		/* Front-end documents, feeds and wc-ajax. Registered at file-load time,
		 * so within priority 0 this runs before WooCommerce's own wc-ajax
		 * dispatcher and before the PWA endpoint. */
		add_action( 'template_redirect', array( __CLASS__, 'guard_frontend' ), 0 );

		/* REST: rest_pre_dispatch exposes the resolved route, so a payment or
		 * authentication namespace can stay open while everything else closes. */
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_rest' ), 0, 3 );

		/* An administrator browsing the store sees it open. The bar is the one
		 * place that can tell them customers do not — so it lives here, in the
		 * runtime, not in the admin module that never loads on the front end. */
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 92 );
	}

	public static function admin_bar( $bar ): void {
		if ( ! is_object( $bar ) || ! is_callable( array( $bar, 'add_node' ) ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) || ! self::is_enabled() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'delicat-maintenance',
				'title' => '⚠ ' . __( 'Maintenance', 'delicat-builder-v9' ),
				'href'  => admin_url( 'admin.php?page=delicat-builder-v9-maintenance' ),
				'meta'  => array( 'title' => __( 'La boutique est fermée aux visiteurs', 'delicat-builder-v9' ) ),
			)
		);
	}

	public static function defaults(): array {
		return array(
			'enabled'                  => 0,
			'template'                 => 'aurora',
			'eyebrow'                  => __( 'Maintenance en cours', 'delicat-builder-v9' ),
			'title'                    => __( 'Nous revenons très vite', 'delicat-builder-v9' ),
			'message'                  => __( 'Délicat Store est en cours de mise à jour. Vos recharges, cartes-cadeaux et abonnements seront de nouveau disponibles dans quelques instants. Merci de votre patience.', 'delicat-builder-v9' ),
			'show_logo'                => 1,
			'logo_url'                 => '',
			'show_brand'               => 1,
			'brand_text'               => '',
			'notices'                  => '',
			'footer_text'              => '',
			'countdown'                => 1,
			'progress'                 => 1,
			'start_time'               => '',
			'end_time'                 => '',
			'auto_end'                 => 1,
			'accent'                   => '#ff5a1f',
			'bg_start'                 => '#0d1b4d',
			'bg_end'                   => '#284696',
			'whatsapp'                 => '',
			'email'                    => '',
			'phone'                    => '',
			'facebook'                 => '',
			'instagram'                => '',
			'tiktok'                   => '',
			'show_admin_link'          => 1,
			'retry_after'              => 3600,
			'allowed_roles'            => array( 'administrator' ),
			'allow_ips'                => '',
			'bypass_key'               => '',
			'lock_api'                 => 1,
			'allow_payment_callbacks'  => 1,
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$merged = wp_parse_args( $saved, self::defaults() );

		$roles = isset( $merged['allowed_roles'] ) ? $merged['allowed_roles'] : array();
		$roles = is_array( $roles ) ? array_values( array_filter( array_map( 'sanitize_key', $roles ) ) ) : array();
		if ( ! in_array( 'administrator', $roles, true ) ) {
			/* An administrator can never be locked out of their own store. */
			$roles[] = 'administrator';
		}
		$merged['allowed_roles'] = $roles;

		self::$settings_cache = $merged;
		return self::$settings_cache;
	}

	public static function reset_settings_cache(): void {
		self::$settings_cache = null;
		self::$locked_cache   = null;
	}

	/** Enabled by the administrator, regardless of who is looking. */
	public static function is_enabled(): bool {
		$s = self::settings();
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( self::window_has_closed( $s ) ) {
			self::auto_disable();
			return false;
		}
		return true;
	}

	/** A finished scheduled window switches itself off exactly once. */
	private static function window_has_closed( array $s ): bool {
		if ( empty( $s['auto_end'] ) || empty( $s['end_time'] ) ) {
			return false;
		}
		$end = self::timestamp( (string) $s['end_time'] );
		return $end > 0 && time() >= $end;
	}

	private static function auto_disable(): void {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( empty( $saved['enabled'] ) ) {
			return;
		}
		$saved['enabled'] = 0;
		update_option( self::OPTION, $saved, true );
		self::reset_settings_cache();
		self::purge_caches();
	}

	/**
	 * The lock as it applies to THIS visitor: enabled, and the visitor is not
	 * an administrator (or otherwise explicitly allowed).
	 */
	public static function is_locked(): bool {
		if ( null !== self::$locked_cache ) {
			return self::$locked_cache;
		}
		if ( ! self::is_enabled() ) {
			self::$locked_cache = false;
			return false;
		}
		self::$locked_cache = ! self::visitor_allowed();
		return self::$locked_cache;
	}

	/**
	 * Who still sees the live site while maintenance is on.
	 *
	 * Administrators always. Extra roles, allow-listed IPs and holders of the
	 * secret preview link are opt-in additions chosen by the administrator.
	 */
	public static function visitor_allowed(): bool {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) {
			return true;
		}
		if ( self::has_bypass_cookie() ) {
			return true;
		}
		if ( self::ip_allowed() ) {
			return true;
		}
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$allowed = self::settings()['allowed_roles'];
		$user    = wp_get_current_user();
		$roles   = ( $user && isset( $user->roles ) && is_array( $user->roles ) ) ? $user->roles : array();
		foreach ( $roles as $role ) {
			if ( in_array( sanitize_key( (string) $role ), $allowed, true ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Secret preview link                                                 */
	/* ------------------------------------------------------------------ */

	public static function bypass_key(): string {
		$s   = self::settings();
		$key = isset( $s['bypass_key'] ) ? preg_replace( '/[^a-f0-9]/i', '', (string) $s['bypass_key'] ) : '';
		if ( strlen( $key ) >= 20 ) {
			return $key;
		}

		$key   = wp_generate_password( 32, false, false );
		$key   = strtolower( preg_replace( '/[^a-zA-Z0-9]/', '', $key ) );
		$key   = substr( hash( 'sha256', $key . wp_salt( 'auth' ) . microtime( true ) ), 0, 32 );
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved['bypass_key'] = $key;
		update_option( self::OPTION, $saved, true );
		self::reset_settings_cache();
		return $key;
	}

	public static function bypass_url(): string {
		return add_query_arg( 'delicat_preview', self::bypass_key(), home_url( '/' ) );
	}

	private static function has_bypass_cookie(): bool {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return false;
		}
		$sent = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) );
		$s    = self::settings();
		$key  = isset( $s['bypass_key'] ) ? (string) $s['bypass_key'] : '';
		return '' !== $key && hash_equals( $key, $sent );
	}

	/** `?delicat_preview=<key>` exchanges the link for a session cookie. */
	private static function maybe_accept_bypass_link(): void {
		if ( empty( $_GET['delicat_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- secret-key comparison below is the check.
			return;
		}
		$sent = sanitize_text_field( wp_unslash( (string) $_GET['delicat_preview'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$s    = self::settings();
		$key  = isset( $s['bypass_key'] ) ? (string) $s['bypass_key'] : '';
		if ( '' === $key || ! hash_equals( $key, $sent ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE,
				$key,
				array(
					'expires'  => time() + ( 12 * HOUR_IN_SECONDS ),
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}
		$_COOKIE[ self::COOKIE ] = $key;

		$clean = remove_query_arg( 'delicat_preview' );
		if ( is_string( $clean ) && '' !== $clean && ! headers_sent() ) {
			wp_safe_redirect( $clean, 302 );
			exit;
		}
	}

	private static function ip_allowed(): bool {
		$list = trim( (string) self::settings()['allow_ips'] );
		if ( '' === $list ) {
			return false;
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $remote ) {
			return false;
		}

		foreach ( preg_split( '/[\s,]+/', $list ) as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}
			if ( $entry === $remote ) {
				return true;
			}
			/* Simple trailing wildcard, e.g. 190.115.24.* */
			if ( false !== strpos( $entry, '*' ) ) {
				$prefix = rtrim( substr( $entry, 0, strpos( $entry, '*' ) ), '' );
				if ( '' !== $prefix && 0 === strpos( $remote, $prefix ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Gates                                                               */
	/* ------------------------------------------------------------------ */

	public static function guard_early(): void {
		self::maybe_accept_bypass_link();

		if ( is_admin() || ! self::is_locked() ) {
			return;
		}
		if ( empty( self::settings()['lock_api'] ) ) {
			return;
		}

		if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			if ( self::ajax_action_allowed( $action ) ) {
				return;
			}
			self::send_json_lock();
		}

		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			self::send_json_lock();
		}
	}

	/**
	 * Authentication traffic stays open even with the API locked: a custom
	 * sign-in screen (Identity Pro) must keep working, or the administrator
	 * cannot reach wp-admin to lift maintenance.
	 */
	private static function ajax_action_allowed( string $action ): bool {
		if ( '' === $action ) {
			return false;
		}
		$allowed = apply_filters(
			'delicat_builder_v9_maintenance_allowed_ajax',
			array( 'heartbeat', 'rp-ajax-login', 'delicat_maintenance_ping' ),
			$action
		);
		if ( is_array( $allowed ) && in_array( $action, array_map( 'sanitize_key', $allowed ), true ) ) {
			return true;
		}
		foreach ( array( 'login', 'logout', 'signin', 'auth', 'oauth', 'identity', 'otp', 'password', 'register' ) as $fragment ) {
			if ( false !== strpos( $action, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	public static function guard_rest( $result, $server, $request ) {
		if ( null !== $result || ! self::is_locked() || empty( self::settings()['lock_api'] ) ) {
			return $result;
		}

		$route = is_object( $request ) && is_callable( array( $request, 'get_route' ) ) ? (string) $request->get_route() : '';
		$open  = apply_filters( 'delicat_builder_v9_maintenance_open_rest_routes', array(), $route );
		if ( is_array( $open ) ) {
			foreach ( $open as $prefix ) {
				$prefix = (string) $prefix;
				if ( '' !== $prefix && 0 === strpos( $route, $prefix ) ) {
					return $result;
				}
			}
		}

		return new WP_Error(
			'delicat_maintenance',
			esc_html__( 'Le site est temporairement en maintenance.', 'delicat-builder-v9' ),
			array( 'status' => 503 )
		);
	}

	public static function guard_frontend(): void {
		if ( is_admin() || ! self::is_locked() || self::$rendering ) {
			return;
		}
		if ( self::request_is_exempt() ) {
			return;
		}

		/* wc-ajax and fetch()-driven surfaces want JSON, not a document. */
		if ( ! empty( $_GET['wc-ajax'] ) || self::wants_json() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
			self::send_json_lock();
		}

		self::render();
	}

	/**
	 * Surfaces that must survive maintenance:
	 *
	 *  - the service worker and web manifest, so an installed app updates its
	 *    worker and stops serving cached storefront documents;
	 *  - robots.txt;
	 *  - WooCommerce gateway callbacks (`wc-api`), so a MonCash/NatCash
	 *    notification for an order placed before the window is not lost.
	 */
	private static function request_is_exempt(): bool {
		if ( function_exists( 'is_robots' ) && is_robots() ) {
			return true;
		}
		if ( get_query_var( 'delicat_v9_sw' ) || get_query_var( 'delicat_v9_manifest' ) ) {
			return true;
		}
		if ( ! empty( self::settings()['allow_payment_callbacks'] ) ) {
			if ( ! empty( $_GET['wc-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing only.
				return true;
			}
			$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
			if ( '' !== $path && false !== stripos( $path, '/wc-api/' ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'delicat_builder_v9_maintenance_exempt_request', false );
	}

	private static function wants_json(): bool {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT'] ) : '';
		if ( '' !== $accept && false !== strpos( $accept, 'application/json' ) && false === strpos( $accept, 'text/html' ) ) {
			return true;
		}
		$requested_with = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ? strtolower( (string) $_SERVER['HTTP_X_REQUESTED_WITH'] ) : '';
		return 'xmlhttprequest' === $requested_with;
	}

	/* ------------------------------------------------------------------ */
	/* Response                                                            */
	/* ------------------------------------------------------------------ */

	public static function retry_after(): int {
		$s   = self::settings();
		$end = self::timestamp( (string) $s['end_time'] );
		if ( $end > time() ) {
			return min( DAY_IN_SECONDS, max( 60, $end - time() ) );
		}
		return min( DAY_IN_SECONDS, max( 60, absint( $s['retry_after'] ) ) );
	}

	/** Nothing about a maintenance response may ever be stored anywhere. */
	private static function send_lock_headers(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Delicat V9 maintenance' );
		nocache_headers();
		status_header( 503 );
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Retry-After: ' . self::retry_after() );
			header( 'X-Delicat-Maintenance: 1' );
		}
	}

	private static function send_json_lock(): void {
		self::send_lock_headers();
		if ( ! headers_sent() ) {
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode(
			array(
				'success'     => false,
				'maintenance' => true,
				'message'     => esc_html__( 'Le site est temporairement en maintenance.', 'delicat-builder-v9' ),
				'retry_after' => self::retry_after(),
			)
		);
		exit;
	}

	/**
	 * @param bool   $preview  Administrator preview: no 503, no exit contract change.
	 * @param string $template Optional template override for the preview.
	 */
	public static function render( bool $preview = false, string $template = '' ): void {
		self::$rendering = true;

		if ( $preview ) {
			nocache_headers();
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=utf-8' );
				header( 'X-Robots-Tag: noindex, nofollow', true );
			}
		} else {
			self::send_lock_headers();
			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=utf-8' );
			}
		}

		$delicat_maintenance = self::context( $preview, $template );

		$file = DELICAT_BUILDER_V9_DIR . 'templates/maintenance.php';
		if ( is_file( $file ) ) {
			include $file;
		} else {
			/* The template file is the only thing that can be missing here, and
			 * a closed site must still say so. */
			echo '<!doctype html><meta charset="utf-8"><title>' . esc_html__( 'Maintenance', 'delicat-builder-v9' ) . '</title>';
			echo '<p>' . esc_html( (string) $delicat_maintenance['title'] ) . '</p>';
		}
		exit;
	}

	/** Everything the template needs, already sanitized. Markup-only template. */
	public static function context( bool $preview = false, string $template = '' ): array {
		$s = self::settings();

		$template = sanitize_key( '' !== $template ? $template : (string) $s['template'] );
		if ( ! in_array( $template, self::TEMPLATES, true ) ) {
			$template = 'aurora';
		}

		$start = self::timestamp( (string) $s['start_time'] );
		$end   = self::timestamp( (string) $s['end_time'] );

		$logo = esc_url_raw( (string) $s['logo_url'] );
		if ( '' === $logo && ! empty( $s['show_logo'] ) ) {
			$logo = self::site_logo_url();
		}

		/* Wordmark. A single pipe splits it into base + accent, the two-tone
		 * treatment a store name usually wants ("Délicat|Store"). */
		$brand_raw = trim( (string) $s['brand_text'] );
		if ( '' === $brand_raw ) {
			$brand_raw = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		}
		$brand_parts = explode( '|', $brand_raw, 2 );
		$brand = array(
			'lead'   => sanitize_text_field( trim( $brand_parts[0] ) ),
			'accent' => isset( $brand_parts[1] ) ? sanitize_text_field( trim( $brand_parts[1] ) ) : '',
		);

		/* One announcement per line. Several lines rotate behind the dots. */
		$notices = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['notices'] ) as $line ) {
			$line = sanitize_text_field( trim( (string) $line ) );
			if ( '' !== $line ) {
				$notices[] = $line;
			}
		}
		$notices = array_slice( $notices, 0, 6 );

		$links = array();
		$whatsapp = preg_replace( '/[^0-9]/', '', (string) $s['whatsapp'] );
		if ( '' !== $whatsapp ) {
			$links[] = array(
				'type'  => 'whatsapp',
				'label' => __( 'WhatsApp', 'delicat-builder-v9' ),
				'url'   => 'https://wa.me/' . $whatsapp,
			);
		}
		$email = sanitize_email( (string) $s['email'] );
		if ( '' !== $email && is_email( $email ) ) {
			$links[] = array(
				'type'  => 'email',
				'label' => $email,
				'url'   => 'mailto:' . $email,
			);
		}
		$phone = preg_replace( '/[^0-9+]/', '', (string) $s['phone'] );
		if ( '' !== $phone ) {
			$links[] = array(
				'type'  => 'phone',
				'label' => (string) $s['phone'],
				'url'   => 'tel:' . $phone,
			);
		}

		$social = array();
		foreach ( array( 'facebook', 'instagram', 'tiktok' ) as $network ) {
			$url = esc_url_raw( (string) $s[ $network ] );
			if ( '' !== $url ) {
				$social[] = array(
					'type'  => $network,
					'label' => ucfirst( $network ),
					'url'   => $url,
				);
			}
		}

		/* The template appends an alpha pair (#rrggbb + "40"), so a 3-digit
		 * value has to be expanded first or the variable becomes invalid CSS. */
		$accent = self::hex( (string) $s['accent'], '#ff5a1f' );
		$bg1    = self::hex( (string) $s['bg_start'], '#0d1b4d' );
		$bg2    = self::hex( (string) $s['bg_end'], '#284696' );

		$progress = 0;
		if ( ! empty( $s['progress'] ) && $start > 0 && $end > $start ) {
			$progress = (int) round( ( ( min( time(), $end ) - $start ) / ( $end - $start ) ) * 100 );
			$progress = min( 100, max( 0, $progress ) );
		}

		return array(
			'template'    => $template,
			'preview'     => $preview,
			'site_name'   => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'eyebrow'     => sanitize_text_field( (string) $s['eyebrow'] ),
			'title'       => sanitize_text_field( (string) $s['title'] ),
			'message'     => sanitize_textarea_field( (string) $s['message'] ),
			'logo'        => $logo,
			'show_logo'   => ! empty( $s['show_logo'] ) && '' !== $logo,
			'show_brand'  => ! empty( $s['show_brand'] ) && '' !== $brand['lead'],
			'brand'       => $brand,
			'notices'     => $notices,
			'footer_text' => sanitize_text_field( (string) $s['footer_text'] ),
			'countdown'   => ! empty( $s['countdown'] ) && $end > time(),
			'end_ts'      => $end,
			'now_ts'      => time(),
			'end_label'   => $end > 0 ? date_i18n( get_option( 'date_format' ) . ' — ' . get_option( 'time_format' ), $end ) : '',
			'progress'    => $progress,
			'show_progress' => ! empty( $s['progress'] ) && $progress > 0,
			'accent'      => $accent,
			'bg_start'    => $bg1,
			'bg_end'      => $bg2,
			'links'       => $links,
			'social'      => $social,
			'admin_link'  => ! empty( $s['show_admin_link'] ) ? wp_login_url() : '',
			'home_url'    => home_url( '/' ),
			'css'         => self::asset( 'assets/css/maintenance.css' ),
			'js'          => self::asset( 'assets/js/maintenance.js' ),
		);
	}

	/** Six-digit hex, expanding #abc and falling back on anything unusable. */
	public static function hex( string $value, string $fallback ): string {
		$value = sanitize_hex_color( trim( $value ) );
		if ( ! is_string( $value ) || '' === $value ) {
			return $fallback;
		}
		if ( 4 === strlen( $value ) ) {
			return '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
		}
		return $value;
	}

	private static function site_logo_url(): string {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( $id > 0 ) {
			$src = wp_get_attachment_image_url( $id, 'medium' );
			if ( is_string( $src ) && '' !== $src ) {
				return $src;
			}
		}
		return '';
	}

	/** Inline a plugin-owned asset. One request, no dependency on the theme. */
	private static function asset( string $relative ): string {
		$relative = ltrim( str_replace( array( '../', '..\\' ), '', $relative ), '/\\' );
		$path     = DELICAT_BUILDER_V9_DIR . $relative;
		if ( ! is_file( $path ) ) {
			return '';
		}
		$contents = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown -- plugin-owned local file.
		return is_string( $contents ) ? $contents : '';
	}

	/**
	 * A `datetime-local` value is written in the site's timezone; storing and
	 * comparing it as UTC is what keeps a Haiti-scheduled window honest.
	 */
	public static function timestamp( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$value = str_replace( 'T', ' ', $value );
		$time  = strtotime( get_gmt_from_date( $value ) . ' UTC' );
		return $time ? (int) $time : 0;
	}

	/**
	 * The single writer for the on/off state, shared by the studio buttons and
	 * the settings form, so both paths behave identically.
	 *
	 * Returns the state actually stored afterwards — never the intended one.
	 * A caller that reports its own intention can tell the administrator the
	 * store is closed when something reopened it a microsecond later.
	 */
	public static function set_enabled( bool $on ): bool {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : self::defaults();

		if ( $on ) {
			/* A return time already in the past makes the scheduled reopen fire
			 * on the very next request, so the store looks like it refuses to
			 * close. Drop the stale schedule instead of fighting it. */
			$end = self::timestamp( (string) ( $saved['end_time'] ?? '' ) );
			if ( $end > 0 && $end <= time() ) {
				$saved['end_time'] = '';
			}
			$saved['start_time'] = current_time( 'Y-m-d\TH:i' );
		} else {
			$saved['start_time'] = '';
		}

		$saved['enabled'] = $on ? 1 : 0;
		update_option( self::OPTION, $saved, true );

		self::reset_settings_cache();
		self::purge_caches();
		delete_transient( 'delicat_builder_v9_maintenance_probe' );

		return self::is_enabled();
	}

	/**
	 * What an anonymous visitor actually receives right now.
	 *
	 * An administrator always sees the live store, so the studio cannot prove
	 * anything by looking at its own screen. One cookie-free loopback request
	 * answers the only question that matters: is the storefront really closed?
	 * A 200 while maintenance is on means a page cache is still serving copies.
	 */
	public static function visitor_probe( bool $fresh = false ): array {
		if ( ! $fresh ) {
			$cached = get_transient( 'delicat_builder_v9_maintenance_probe' );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_safe_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'cookies'     => array(),
				/* Diagnostics must also authenticate the HTTPS server. */
				'sslverify'   => true,
				'limit_response_size' => 131072,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$result = array(
				'reachable' => false,
				'status'    => 0,
				'flagged'   => false,
				'error'     => sanitize_text_field( (string) $response->get_error_message() ),
			);
		} else {
			$result = array(
				'reachable' => true,
				'status'    => (int) wp_remote_retrieve_response_code( $response ),
				'flagged'   => '' !== (string) wp_remote_retrieve_header( $response, 'x-delicat-maintenance' ),
				'error'     => '',
			);
		}

		set_transient( 'delicat_builder_v9_maintenance_probe', $result, 30 );
		return $result;
	}

	public static function purge_caches(): void {
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		do_action( 'litespeed_purge_all' );
		if (
			class_exists( 'Delicat_Builder_V9_Cloudflare', false )
			&& is_callable( array( 'Delicat_Builder_V9_Cloudflare', 'queue_all' ) )
			&& is_callable( array( 'Delicat_Builder_V9_Cloudflare', 'flush' ) )
		) {
			Delicat_Builder_V9_Cloudflare::queue_all();
			Delicat_Builder_V9_Cloudflare::flush();
		}
	}
}
