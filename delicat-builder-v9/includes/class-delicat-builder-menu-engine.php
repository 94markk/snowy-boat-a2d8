<?php
/**
 * Delicat Menu Engine 3.0 — the storefront's one navigation surface.
 *
 * Replaces the three engines this release deletes: the legacy modern-menu
 * runtime (42 KB CSS + 13 KB JS), its duplicated copy inside the Menu Builder
 * admin file, and the RC29 drawer that had been layered on top of both.
 *
 * What made the old stack slow on a 4-core Android was not any one of them —
 * it was that every page paid for all of it:
 *
 *   - the whole panel (account, wallet, tiles, every link, social, footer) was
 *     live DOM in every document, styled and laid out during the load the
 *     shopper was waiting on, for a menu most visits never open;
 *   - 42 KB of preset CSS with backdrop-filter glass, and a second sheet for
 *     the skins, were render-blocking on those same requests;
 *   - a signed-in visitor also got a wallet poller that woke every 45 seconds
 *     whether or not the menu had ever been opened.
 *
 * This engine ships the panel body inside a <template>. Template content is
 * parsed but inert — no style resolution, no layout, no paint — so the load
 * pays for about twenty nodes of chrome. The runtime hydrates and parks it
 * during the first idle slice, which leaves the first tap a transform and
 * nothing else. The balance refreshes when the menu opens, never on a timer.
 *
 * Settings, items and quick actions are read from the same three options the
 * Menu Builder screen has always written, so nothing has to be migrated.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Delicat_Builder_V9_Menu_Engine', false ) ) :

final class Delicat_Builder_V9_Menu_Engine {

	public const SETTINGS_OPTION = 'dsb_menu_builder_settings';
	public const ITEMS_OPTION    = 'dsb_menu_builder_items';
	public const QUICK_OPTION    = 'dsb_menu_quick_items';
	public const WALLET_NONCE    = 'delicat_builder_v9_wallet_live';

	/** A menu shorter than this needs no search field. */
	private const SEARCH_THRESHOLD = 7;

	private static bool $booted   = false;
	private static bool $rendered = false;

	private static ?array $settings = null;
	private static ?array $items    = null;
	private static ?array $quick    = null;
	private static ?string $balance = null;

	/* ------------------------------------------------------------------ */
	/* boot                                                                */
	/* ------------------------------------------------------------------ */

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'wp_ajax_delicat_builder_v9_wallet_balance', array( __CLASS__, 'ajax_balance' ) );

		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}

		/* Priority 9, not 8: assets() skips menu.css when the release-built
		 * storefront chrome bundle (which contains it) is already enqueued, and
		 * chrome goes in at 8 from Header Studio. Bottom Nav sits at 9 for the
		 * same reason. */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
		/* The menu is printed at the end of the document, not beside the
		 * header. Nothing above the fold waits on it. */
		add_action( 'wp_footer', array( __CLASS__, 'footer' ), 8 );
		add_action( 'init', array( __CLASS__, 'shortcodes' ), 99 );
	}

	/** True when this engine owns the storefront menu for the current request. */
	public static function owns(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() ) {
			return false;
		}
		/* A legacy standalone Builder plugin still on the site keeps its own
		 * menu until it is deactivated. */
		if ( function_exists( 'dsb_render_modern_menu' ) ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return false;
		}
		return 'yes' === ( self::settings()['enabled'] ?? 'yes' ) && (bool) apply_filters( 'delicat_builder_v9_menu_engine', true );
	}

	public static function assets(): void {
		if ( ! self::owns() ) {
			return;
		}
		if ( ! wp_style_is( 'delicat-builder-v9-storefront-chrome', 'enqueued' ) ) {
			wp_enqueue_style( 'delicat-builder-v9-menu', DELICAT_BUILDER_V9_URL . 'assets/css/menu.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		wp_enqueue_script( 'delicat-builder-v9-menu', DELICAT_BUILDER_V9_URL . 'assets/js/menu.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-menu', 'strategy', 'defer' );
		}
	}

	public static function footer(): void {
		echo self::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped at every field below.
	}

	/* ------------------------------------------------------------------ */
	/* options                                                             */
	/* ------------------------------------------------------------------ */

	public static function defaults(): array {
		return array(
			'enabled'               => 'yes',
			'layout'                => 'drawer',
			'position'              => 'left',
			'open_speed'            => 220,
			'overlay_opacity'       => 55,
			'close_on_overlay'      => 'yes',
			'body_scroll_lock'      => 'yes',
			'swipe_close'           => 'yes',
			'performance_mode'      => 'auto',
			'reduced_motion'        => 'respect',
			'primary'               => '#8b5cf6',
			'secondary'             => '#ec4899',
			'accent'                => '#2f75ff',
			'radius'                => 22,
			'item_radius'           => 14,
			'desktop_width'         => 378,
			'tablet_width'          => 342,
			'mobile_width'          => 86,
			'mobile_unit'           => 'vw',
			'custom_header_enabled' => 'yes',
			'custom_header_title'   => 'Delicat Store Haiti',
			'custom_header_subtitle'=> 'Recharge rapide, simple et sécurisée',
			'custom_header_image'   => '',
			'custom_header_url'     => '/',
			'section_order'         => 'profile,wallet,quick,main,bonus,promo,social,footer',
			'show_profile'          => 'yes',
			'profile_badge'         => 'Premium',
			'show_wallet'           => 'yes',
			'wallet_label'          => 'Mon portefeuille',
			'wallet_item_balance'   => 'yes',
			'wallet_url'            => '/my-wallet/',
			'recharge_url'          => '/my-wallet/',
			'loyalty_points'        => '0',
			'promo_enabled'         => 'yes',
			'promo_title'           => 'Promos exclusives ! 🔥',
			'promo_text'            => 'Profitez de réductions incroyables sur vos top-ups préférés.',
			'promo_button'          => 'Voir les promos',
			'promo_url'             => '/promos/',
			'promo_countdown'       => '',
			'quick_actions'         => 'yes',
			'quick_wallet'          => 'yes',
			'quick_orders'          => 'yes',
			'quick_support'         => 'yes',
			'quick_app'             => 'yes',
			'bonus_enabled'         => 'yes',
			'bonus_title'           => 'Bonus Free Fire',
			'social_enabled'        => 'yes',
			'whatsapp_url'          => 'https://wa.me/message/BXQXUCKDDI3GO1',
			'telegram_url'          => '',
			'instagram_url'         => '',
			'facebook_url'          => '',
			'tiktok_url'            => '',
			'youtube_url'           => '',
			'whatsapp_label'        => 'WhatsApp',
			'telegram_label'        => 'Telegram',
			'instagram_label'       => 'Instagram',
			'facebook_label'        => 'Facebook',
			'tiktok_label'          => 'TikTok',
			'youtube_label'         => 'YouTube',
			'whatsapp_enabled'      => 'yes',
			'telegram_enabled'      => 'yes',
			'instagram_enabled'     => 'yes',
			'facebook_enabled'      => 'yes',
			'tiktok_enabled'        => 'yes',
			'youtube_enabled'       => 'yes',
			'footer_toggle'         => 'yes',
			'bottom_nav'            => 'yes',
			'center_button'         => 'yes',
		);
	}

	public static function default_items(): array {
		return array(
			array( 'title' => 'Accueil', 'subtitle' => 'Page principale', 'url' => '/', 'icon' => 'dashicons-admin-home', 'image' => '', 'color1' => '#8b5cf6', 'color2' => '#ec4899', 'badge' => '', 'group' => 'main', 'visibility' => 'all' ),
			array( 'title' => 'Mon Portefeuille', 'subtitle' => 'Solde et recharge', 'url' => '/my-wallet/', 'icon' => 'dashicons-portfolio', 'image' => '', 'color1' => '#4f46e5', 'color2' => '#06b6d4', 'badge' => '', 'group' => 'main', 'visibility' => 'logged_in' ),
			array( 'title' => 'Recharger mon compte', 'subtitle' => 'Moncash / Natcash', 'url' => '/my-wallet/', 'icon' => 'dashicons-money-alt', 'image' => '', 'color1' => '#06b6d4', 'color2' => '#22c55e', 'badge' => '', 'group' => 'main', 'visibility' => 'all' ),
			array( 'title' => 'Réclamer votre PIN Free Fire', 'subtitle' => 'Copier et réclamer', 'url' => '/free-fire-redeem/', 'icon' => 'dashicons-admin-network', 'image' => '', 'color1' => '#f59e0b', 'color2' => '#fb7185', 'badge' => 'HOT', 'group' => 'main', 'visibility' => 'all' ),
			array( 'title' => 'FREE FIRE', 'subtitle' => 'Diamants instantanés', 'url' => '/product-category/free-fire/', 'icon' => 'dashicons-games', 'image' => '', 'color1' => '#6366f1', 'color2' => '#8b5cf6', 'badge' => 'GAME', 'group' => 'main', 'visibility' => 'all' ),
			array( 'title' => 'Rewards Program', 'subtitle' => 'Points et cadeaux', 'url' => '/rewards/', 'icon' => 'dashicons-awards', 'image' => '', 'color1' => '#a855f7', 'color2' => '#f97316', 'badge' => 'NEW', 'group' => 'bonus', 'visibility' => 'all' ),
			array( 'title' => 'Meilleur client', 'subtitle' => 'Classement clients', 'url' => '/meilleur-client/', 'icon' => 'dashicons-star-filled', 'image' => '', 'color1' => '#f59e0b', 'color2' => '#ef4444', 'badge' => 'HOT', 'group' => 'bonus', 'visibility' => 'all' ),
			array( 'title' => 'Exchange USDT - USD', 'subtitle' => 'Services financiers', 'url' => '/product-category/exchange/', 'icon' => 'dashicons-money-alt', 'image' => '', 'color1' => '#10b981', 'color2' => '#06b6d4', 'badge' => '', 'group' => 'bonus', 'visibility' => 'all' ),
			array( 'title' => 'Jeux Disponibles', 'subtitle' => 'Tous les jeux', 'url' => '/product-category/jeux/', 'icon' => 'dashicons-games', 'image' => '', 'color1' => '#2563eb', 'color2' => '#8b5cf6', 'badge' => '', 'group' => 'bonus', 'visibility' => 'all' ),
			array( 'title' => 'Gift Card', 'subtitle' => 'Apple, Google, PSN', 'url' => '/product-category/gift-card/', 'icon' => 'dashicons-awards', 'image' => '', 'color1' => '#ec4899', 'color2' => '#f43f5e', 'badge' => '', 'group' => 'bonus', 'visibility' => 'all' ),
		);
	}

	private static function scalar( mixed $value, string $fallback = '' ): string {
		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	private static function bounded( mixed $value, int $min, int $max, int $fallback ): int {
		$value = is_numeric( $value ) ? (int) $value : $fallback;
		return max( $min, min( $max, $value ) );
	}

	/**
	 * Normalize a saved option.
	 *
	 * The admin screen sanitizes on save, but the front end must not trust a
	 * stored array: a hand-edited or half-migrated option would otherwise reach
	 * the renderer as the wrong type.
	 */
	public static function normalize_settings( mixed $saved ): array {
		$defaults = self::defaults();
		/* Only keys this engine knows about survive a save. The old option
		 * carried a dozen dead keys (presets, blur, glass models) and merging a
		 * raw array would also have let an unknown POST key through the
		 * sanitizer untouched. */
		$settings = is_array( $saved ) ? array_merge( $defaults, array_intersect_key( $saved, $defaults ) ) : $defaults;

		$flags = array(
			'enabled', 'close_on_overlay', 'body_scroll_lock', 'swipe_close', 'custom_header_enabled',
			'show_profile', 'show_wallet', 'wallet_item_balance', 'promo_enabled', 'quick_actions',
			'quick_wallet', 'quick_orders', 'quick_support', 'quick_app', 'bonus_enabled', 'social_enabled',
			'whatsapp_enabled', 'telegram_enabled', 'instagram_enabled', 'facebook_enabled',
			'tiktok_enabled', 'youtube_enabled', 'footer_toggle', 'bottom_nav', 'center_button',
		);
		foreach ( $flags as $key ) {
			$value = strtolower( trim( self::scalar( $settings[ $key ] ?? '', (string) $defaults[ $key ] ) ) );
			$settings[ $key ] = match ( true ) {
				in_array( $value, array( 'yes', '1', 'true', 'on' ), true )        => 'yes',
				in_array( $value, array( 'no', '0', 'false', 'off', '' ), true )   => 'no',
				default                                                            => (string) $defaults[ $key ],
			};
		}

		$enums = array(
			'layout'           => array( 'drawer', 'inline' ),
			'position'         => array( 'left', 'right' ),
			'performance_mode' => array( 'auto', 'balanced', 'ultra-lite' ),
			'reduced_motion'   => array( 'respect', 'ignore' ),
			'mobile_unit'      => array( 'vw', '%' ),
		);
		foreach ( $enums as $key => $allowed ) {
			$value = self::scalar( $settings[ $key ] ?? '', (string) $defaults[ $key ] );
			/* "fullscreen" and "compact" were layouts the old engine offered and
			 * never rendered differently on a phone; they land on the drawer. */
			$settings[ $key ] = in_array( $value, $allowed, true ) ? $value : (string) $defaults[ $key ];
		}

		$ranges = array(
			'open_speed'      => array( 120, 420 ),
			'overlay_opacity' => array( 0, 85 ),
			'radius'          => array( 0, 32 ),
			'item_radius'     => array( 0, 24 ),
			'desktop_width'   => array( 280, 560 ),
			'tablet_width'    => array( 280, 520 ),
			'mobile_width'    => array( 55, 96 ),
		);
		foreach ( $ranges as $key => $range ) {
			$settings[ $key ] = self::bounded( $settings[ $key ] ?? null, $range[0], $range[1], (int) $defaults[ $key ] );
		}

		foreach ( array( 'primary', 'secondary', 'accent' ) as $key ) {
			$settings[ $key ] = sanitize_hex_color( self::scalar( $settings[ $key ] ?? '', (string) $defaults[ $key ] ) ) ?: (string) $defaults[ $key ];
		}

		$texts = array(
			'custom_header_title', 'custom_header_subtitle', 'profile_badge', 'wallet_label', 'loyalty_points',
			'promo_title', 'promo_text', 'promo_button', 'promo_countdown', 'bonus_title',
			'whatsapp_label', 'telegram_label', 'instagram_label', 'facebook_label', 'tiktok_label', 'youtube_label',
		);
		foreach ( $texts as $key ) {
			$settings[ $key ] = sanitize_text_field( self::scalar( $settings[ $key ] ?? '', (string) $defaults[ $key ] ) );
		}

		$urls = array(
			'custom_header_image', 'custom_header_url', 'wallet_url', 'recharge_url', 'promo_url',
			'whatsapp_url', 'telegram_url', 'instagram_url', 'facebook_url', 'tiktok_url', 'youtube_url',
		);
		foreach ( $urls as $key ) {
			$raw = trim( self::scalar( $settings[ $key ] ?? '', '' ) );
			$settings[ $key ] = ( '' === $raw || '#' === $raw ) ? '' : esc_url_raw( $raw );
		}

		$allowed_sections = array( 'profile', 'wallet', 'quick', 'main', 'bonus', 'promo', 'social', 'footer' );
		$requested = array_values(
			array_unique(
				array_intersect(
					array_filter( array_map( 'sanitize_key', explode( ',', self::scalar( $settings['section_order'] ?? '', (string) $defaults['section_order'] ) ) ) ),
					$allowed_sections
				)
			)
		);
		foreach ( $allowed_sections as $section ) {
			if ( ! in_array( $section, $requested, true ) ) {
				$requested[] = $section;
			}
		}
		$settings['section_order'] = implode( ',', $requested );

		return $settings;
	}

	public static function normalize_items( mixed $items ): array {
		$out = array();
		foreach ( (array) $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$title = sanitize_text_field( self::scalar( $item['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$group      = self::scalar( $item['group'] ?? 'main', 'main' );
			$visibility = self::scalar( $item['visibility'] ?? 'all', 'all' );
			$out[]      = array(
				'title'         => $title,
				'subtitle'      => sanitize_text_field( self::scalar( $item['subtitle'] ?? '' ) ),
				'url'           => esc_url_raw( self::heal_url( self::scalar( $item['url'] ?? '#', '#' ) ) ),
				/* Kept as text, not a class: an emoji icon survives, and icon()
				 * only ever resolves it through its own fixed table. */
				'icon'          => sanitize_text_field( self::scalar( $item['icon'] ?? '', 'arrow-right-alt2' ) ),
				'image'         => esc_url_raw( self::scalar( $item['image'] ?? '' ) ),
				'attachment_id' => absint( is_numeric( $item['attachment_id'] ?? 0 ) ? ( $item['attachment_id'] ?? 0 ) : 0 ),
				'color1'        => sanitize_hex_color( self::scalar( $item['color1'] ?? '', '#8b5cf6' ) ) ?: '#8b5cf6',
				'color2'        => sanitize_hex_color( self::scalar( $item['color2'] ?? '', '#ec4899' ) ) ?: '#ec4899',
				'badge'         => sanitize_text_field( self::scalar( $item['badge'] ?? '' ) ),
				'group'         => in_array( $group, array( 'main', 'bonus', 'footer', 'quick' ), true ) ? $group : 'main',
				'visibility'    => in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ? $visibility : 'all',
			);
		}
		return $out;
	}

	public static function normalize_quick( mixed $raw ): array {
		$out = array();
		foreach ( (array) $raw as $tile ) {
			if ( ! is_array( $tile ) ) {
				continue;
			}
			$label = sanitize_text_field( self::scalar( $tile['label'] ?? '' ) );
			$icon  = sanitize_text_field( self::scalar( $tile['icon'] ?? '' ) );
			$image = esc_url_raw( self::scalar( $tile['image'] ?? '' ) );
			if ( '' === $label && '' === $icon && '' === $image ) {
				continue;
			}
			$visibility = self::scalar( $tile['visibility'] ?? 'all', 'all' );
			$out[]      = array(
				'icon'          => $icon,
				'image'         => $image,
				'attachment_id' => absint( is_numeric( $tile['attachment_id'] ?? 0 ) ? ( $tile['attachment_id'] ?? 0 ) : 0 ),
				'label'         => $label,
				'url'           => esc_url_raw( self::scalar( $tile['url'] ?? '#', '#' ) ),
				'visibility'    => in_array( $visibility, array( 'all', 'logged_in', 'logged_out' ), true ) ? $visibility : 'all',
			);
		}
		return $out;
	}

	/**
	 * Heal routes saved by older Builder releases.
	 *
	 * These exact paths are storefront-owned legacy aliases, never arbitrary
	 * external URLs. The 301 would land them correctly anyway — at the cost of
	 * a full redirect round trip on a Haitian mobile connection, on every tap.
	 */
	private static function heal_url( string $url ): string {
		if ( '' === $url ) {
			return $url;
		}
		$url  = str_replace( '/categorie-produit/', '/product-category/', $url );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( in_array( $path, array( '/exchange', '/exchange/' ), true ) ) {
			return '/product-category/exchange/';
		}
		if ( in_array( $path, array( '/product-category/streaming', '/product-category/streaming/' ), true ) ) {
			return '/product-category/abonnement/';
		}
		return $url;
	}

	public static function settings(): array {
		if ( null === self::$settings ) {
			self::$settings = self::normalize_settings( get_option( self::SETTINGS_OPTION, array() ) );
		}
		return self::$settings;
	}

	public static function items(): array {
		if ( null === self::$items ) {
			$saved = get_option( self::ITEMS_OPTION, array() );
			$items = self::normalize_items( is_array( $saved ) && $saved ? $saved : self::default_items() );
			self::$items = $items ?: self::normalize_items( self::default_items() );
		}
		return self::$items;
	}

	/**
	 * Saved rows win. get_option() returns null only when the table has never
	 * been saved, which is what separates "not migrated yet" from "the shop
	 * owner deliberately emptied the row".
	 */
	public static function quick_items(): array {
		if ( null !== self::$quick ) {
			return self::$quick;
		}
		$saved = get_option( self::QUICK_OPTION, null );
		if ( is_array( $saved ) ) {
			self::$quick = self::normalize_quick( $saved );
			return self::$quick;
		}

		$settings = self::settings();
		$legacy   = array();
		if ( 'yes' === $settings['quick_wallet'] ) {
			$legacy[] = array( 'icon' => 'wallet', 'image' => '', 'label' => 'Wallet', 'url' => $settings['wallet_url'], 'visibility' => 'all' );
		}
		if ( 'yes' === $settings['quick_orders'] ) {
			$legacy[] = array( 'icon' => 'orders', 'image' => '', 'label' => 'Commandes', 'url' => self::account_url( 'orders' ), 'visibility' => 'all' );
		}
		if ( 'yes' === $settings['quick_support'] ) {
			$legacy[] = array( 'icon' => 'support', 'image' => '', 'label' => 'Support', 'url' => $settings['whatsapp_url'], 'visibility' => 'all' );
		}
		if ( 'yes' === $settings['quick_app'] ) {
			$legacy[] = array( 'icon' => 'app', 'image' => '', 'label' => 'App', 'url' => '#install-app', 'visibility' => 'all' );
		}
		self::$quick = self::normalize_quick( $legacy );
		return self::$quick;
	}

	/* ------------------------------------------------------------------ */
	/* small helpers                                                       */
	/* ------------------------------------------------------------------ */

	public static function visible( array $item ): bool {
		return match ( $item['visibility'] ?? 'all' ) {
			'logged_in'  => is_user_logged_in(),
			'logged_out' => ! is_user_logged_in(),
			default      => true,
		};
	}

	public static function account_url( string $endpoint = '' ): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			return (string) ( '' !== $endpoint ? wc_get_account_endpoint_url( $endpoint ) : wc_get_page_permalink( 'myaccount' ) );
		}
		return site_url( '' !== $endpoint ? '/my-account/' . $endpoint . '/' : '/my-account/' );
	}

	public static function url( string $value, string $fallback = '' ): string {
		$value = trim( $value );
		return ( '' === $value || '#' === $value ) ? $fallback : self::heal_url( $value );
	}

	public static function is_current( string $url ): bool {
		if ( '' === $url || '#' === $url ) {
			return false;
		}
		$target = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! $target ) {
			return false;
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- path only, compared not printed.
		return '/' . trim( (string) $target, '/' ) . '/' === '/' . trim( (string) $request, '/' ) . '/';
	}

	private static function same_page( string $a, string $b ): bool {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		$pa = trim( (string) wp_parse_url( $a, PHP_URL_PATH ), '/' );
		$pb = trim( (string) wp_parse_url( $b, PHP_URL_PATH ), '/' );
		return '' !== $pa && $pa === $pb;
	}

	/** Does this item lead to the wallet? Then it may carry the balance. */
	public static function is_wallet_item( array $item ): bool {
		$settings = self::settings();
		if ( 'yes' !== $settings['wallet_item_balance'] ) {
			return false;
		}
		$url = self::scalar( $item['url'] ?? '' );
		return self::same_page( $url, $settings['wallet_url'] ) || self::same_page( $url, $settings['recharge_url'] );
	}

	public static function balance_text(): string {
		if ( ! function_exists( 'woo_wallet' ) || ! is_user_logged_in() ) {
			return '';
		}
		try {
			return wp_kses_post( woo_wallet()->wallet->get_wallet_balance( get_current_user_id() ) );
		} catch ( Throwable ) {
			return '';
		}
	}

	/** One wallet read per request, wrapped for the live-refresh hook. */
	public static function balance_html(): string {
		if ( null !== self::$balance ) {
			return self::$balance;
		}
		if ( ! is_user_logged_in() || ! function_exists( 'woo_wallet' ) ) {
			self::$balance = '';
			return self::$balance;
		}
		self::mark_private();
		$text = self::balance_text();
		self::$balance = '' === $text ? '' : '<span class="dsb-live-balance" data-dsb-wallet="1">' . $text . '</span>';
		return self::$balance;
	}

	/**
	 * Swap {balance} / {points} inside text that has ALREADY been escaped.
	 * esc_html() leaves braces untouched, so the tokens survive it intact and
	 * the surrounding copy stays escaped.
	 */
	public static function tokens( string $escaped ): string {
		if ( ! str_contains( $escaped, '{balance}' ) && ! str_contains( $escaped, '{points}' ) ) {
			return $escaped;
		}
		$balance = self::balance_html();
		if ( '' === $balance ) {
			/* Guest, or TeraWallet inactive: drop the token and tidy the
			 * separator it leaves behind. */
			$escaped = trim( (string) preg_replace( '/[\s·•|:–-]+$/u', '', str_replace( '{balance}', '', $escaped ) ) );
		} else {
			$escaped = str_replace( '{balance}', $balance, $escaped );
		}
		return str_replace( '{points}', esc_html( self::settings()['loyalty_points'] ), $escaped );
	}

	/** A signed-in customer's page must not land in a shared cache. */
	public static function mark_private(): void {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		if ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) && Delicat_Builder_V9_Security::private_cache_allowed() ) {
			Delicat_Builder_V9_Security::hint_private_cache( 'Delicat V9 menu account (signed-in)' );
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! defined( 'DONOTCACHEOBJECT' ) ) {
			define( 'DONOTCACHEOBJECT', true );
		}
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
		}
	}

	public static function user_code( int $user_id ): string {
		$code = get_user_meta( $user_id, 'delicat_user_code', true );
		if ( ! $code && function_exists( 'delicat_cs_get_code' ) ) {
			$code = delicat_cs_get_code( $user_id );
		}
		return is_string( $code ) ? $code : '';
	}

	/* ------------------------------------------------------------------ */
	/* icons                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * One stroked 24×24 sprite table, drawn inline.
	 *
	 * Inline paths and not an icon font: a font is a blocking download that
	 * renders a different glyph on every Android build, and the storefront is
	 * mostly Android.
	 */
	public static function icon( string $name ): string {
		static $paths = array(
			'close'     => '<path d="M6 6l12 12M18 6L6 18"/>',
			'chevron'   => '<path d="m9 5 7 7-7 7"/>',
			'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'wallet'    => '<rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18M15.5 14.5h2.5"/>',
			'orders'    => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
			'support'   => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/><path d="M8 9.5h8M8 13h5"/>',
			'app'       => '<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M10 18h4"/>',
			'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>',
			'logout'    => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l5 4-5 4M20 12H9"/>',
			'login'     => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M9 8l5 4-5 4M14 12H3"/>',
			'moon'      => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
			'points'    => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
			'admin-home'=> '<path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/>',
			'portfolio' => '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M8 6V4h8v2M3 11h18"/>',
			'money-alt' => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5c-1-.8-2.1-1.1-3.4-1.1-1.8 0-3.1.8-3.1 2.1 0 3.3 6.5 1.4 6.5 4.9 0 1.4-1.4 2.3-3.4 2.3-1.5 0-2.8-.4-3.8-1.3M12 5.5v13"/>',
			'admin-network' => '<circle cx="6" cy="7" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="17" r="2"/><path d="M8 8.2l3 6.3M16 8.2l-3 6.3M8 7h8"/>',
			'games'     => '<path d="M7 8h10c2 0 3.5 1.5 4 4.2l.7 3.6c.4 2.2-2.2 3.4-3.6 1.8L16 15H8l-2.1 2.6c-1.4 1.6-4 .4-3.6-1.8l.7-3.6C3.5 9.5 5 8 7 8z"/><path d="M7 11v4M5 13h4"/>',
			'awards'    => '<path d="m12 3 2.1 4.3 4.8.7-3.5 3.4.8 4.8L12 14l-4.2 2.2.8-4.8L5.1 8l4.8-.7z"/><path d="M9 15.5 8 22l4-2 4 2-1-6.5"/>',
			'star-filled' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
			'admin-users' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-5 3.3-7.5 7.5-7.5s6.8 2.5 7.5 7.5V21"/>',
			'card'      => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
			'package'   => '<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7M12 11v10"/>',
			'chat'      => '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 10h8M8 13h5"/>',
			'phone'     => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/>',
			'bell'      => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
			'cart'      => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l3 12h11l2-8H7"/>',
			'gift'      => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M8 8a2.5 2.5 0 0 1 0-5c2 0 4 5 4 5s2-5 4-5a2.5 2.5 0 0 1 0 5"/>',
			'bolt'      => '<path d="M13 2 5 13h6l-1 9 9-12h-6V2Z"/>',
			'lock'      => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
			'shield'    => '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>',
			'shop'      => '<path d="M4 9.5 5.2 5h13.6L20 9.5v9a1.5 1.5 0 0 1-1.5 1.5h-13A1.5 1.5 0 0 1 4 18.5z"/><path d="M9.5 20v-5h5v5"/>',
			'whatsapp'  => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3z"/><path d="M9.2 8.8c.2-.4.5-.5.8-.5h.5c.2 0 .4.1.5.4l.6 1.5c.1.2 0 .4-.1.6l-.5.6c.6 1 1.5 1.8 2.5 2.4l.6-.5c.2-.2.4-.2.6-.1l1.5.7c.2.1.4.3.3.6-.1.9-.7 1.5-1.6 1.6-2.9.1-6.2-3.2-6.1-6.1 0-.4.2-.8.4-1.2z"/>',
			'telegram'  => '<path d="m3.5 11.2 16-6.7c.6-.2 1.1.2 1 .9L18 19.8c-.1.6-.6.8-1.1.5l-4.3-3.2-2.1 2c-.2.2-.6.1-.6-.2l.3-3.4 7-6.3-8.6 5.3-3.7-1.2c-.7-.2-.7-.9 0-1.1z"/>',
			'instagram' => '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r=".8"/>',
			'facebook'  => '<path d="M14 8.5V6.8c0-.8.5-1.3 1.3-1.3H17V2.5h-2.4C11.9 2.5 11 4.2 11 6.5v2H8.5V12H11v9.5h3V12h2.6l.4-3.5z"/>',
			'tiktok'    => '<path d="M14 3c.3 2.3 1.8 3.8 4 4v3c-1.5 0-2.9-.5-4-1.3V15a5 5 0 1 1-5-5v3a2 2 0 1 0 2 2V3z"/>',
			'youtube'   => '<path d="M3.5 8.2c.2-1.6 1.3-2.5 2.9-2.6C8.3 5.4 10 5.3 12 5.3s3.7.1 5.6.3c1.6.1 2.7 1 2.9 2.6.2 1.3.3 2.5.3 3.8s-.1 2.5-.3 3.8c-.2 1.6-1.3 2.5-2.9 2.6-1.9.2-3.6.3-5.6.3s-3.7-.1-5.6-.3c-1.6-.1-2.7-1-2.9-2.6A24 24 0 0 1 3.2 12c0-1.3.1-2.5.3-3.8z"/><path d="m10.3 9.4 4.3 2.6-4.3 2.6z"/>',
		);

		static $emoji = array(
			'🎮' => 'games', '🕹' => 'games', '🎁' => 'gift', '💳' => 'card', '📦' => 'package',
			'💬' => 'chat', '📲' => 'phone', '📱' => 'phone', '🌙' => 'moon', '🔔' => 'bell',
			'🛒' => 'cart', '💰' => 'money-alt', '💵' => 'money-alt', '⭐' => 'star-filled',
			'🌟' => 'star-filled', '🏆' => 'awards', '👤' => 'admin-users', '🏠' => 'admin-home',
			'⚡' => 'bolt', '🔒' => 'lock', '🔐' => 'lock', '🛡' => 'shield',
		);

		/* The quick-action tiles name themselves in French; map the labels the
		 * shop actually ships before falling through to the sprite keys. */
		static $aliases = array(
			'commandes' => 'orders', 'commande' => 'orders', 'boutique' => 'shop',
			'accueil' => 'admin-home', 'compte' => 'admin-users', 'panier' => 'cart',
			'portefeuille' => 'wallet', 'recharge' => 'money-alt', 'aide' => 'support',
		);

		$raw   = trim( $name );
		$clean = (string) preg_replace( '/[\x{FE0F}\x{FE0E}\x{20E3}]/u', '', $raw );
		$key   = $emoji[ $clean ] ?? sanitize_key( str_replace( 'dashicons-', '', $raw ) );
		$key   = $aliases[ $key ] ?? $key;
		$path  = $paths[ $key ] ?? $paths['chevron'];

		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
	}

	private static function item_icon( array $item ): string {
		$image = trim( self::scalar( $item['image'] ?? '' ) );
		if ( '' !== $image ) {
			return '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async" width="20" height="20">';
		}
		return self::icon( self::scalar( $item['icon'] ?? '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* sections                                                            */
	/* ------------------------------------------------------------------ */

	public static function group( string $group ): array {
		$out = array();
		foreach ( self::items() as $item ) {
			if ( ( $item['group'] ?? 'main' ) !== $group || ! self::visible( $item ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	public static function render_items( string $group = '' ): string {
		$html = '';
		foreach ( self::items() as $item ) {
			if ( ( '' !== $group && ( $item['group'] ?? 'main' ) !== $group ) || ! self::visible( $item ) ) {
				continue;
			}
			$html .= self::render_item( $item );
		}
		return $html;
	}

	private static function render_item( array $item ): string {
		$url     = self::url( self::scalar( $item['url'] ?? '' ), home_url( '/' ) );
		$current = self::is_current( $url );
		$title   = self::scalar( $item['title'] ?? '' );
		$sub     = self::scalar( $item['subtitle'] ?? '' );
		$badge   = trim( self::scalar( $item['badge'] ?? '' ) );

		if ( ! str_contains( $sub, '{balance}' ) && self::is_wallet_item( $item ) && '' !== self::balance_html() ) {
			$sub = '' !== $sub ? $sub . ' · {balance}' : '{balance}';
		}

		$sub_html = self::tokens( esc_html( $sub ) );

		return '<li><a class="dmenu__item' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"'
			. ( $current ? ' aria-current="page"' : '' )
			. ' data-dmenu-filter="' . esc_attr( strtolower( $title . ' ' . wp_strip_all_tags( $sub ) ) ) . '"'
			. ' style="--i1:' . esc_attr( $item['color1'] ) . ';--i2:' . esc_attr( $item['color2'] ) . '">'
			. '<span class="dmenu__icon" aria-hidden="true">' . self::item_icon( $item ) . '</span>'
			. '<span class="dmenu__copy"><strong>' . self::tokens( esc_html( $title ) ) . '</strong>'
			. ( '' !== $sub_html ? '<small>' . $sub_html . '</small>' : '' ) . '</span>'
			. ( '' !== $badge ? '<em class="dmenu__badge">' . self::tokens( esc_html( $badge ) ) . '</em>' : '' )
			. '<span class="dmenu__chevron" aria-hidden="true">' . self::icon( 'chevron' ) . '</span></a></li>';
	}

	public static function render_account(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['show_profile'] ) {
			return '';
		}

		if ( ! is_user_logged_in() ) {
			$modal = class_exists( 'Delicat_Builder_V9_Identity_Bridge', false )
				&& is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'frontend_login_available' ) )
				&& Delicat_Builder_V9_Identity_Bridge::frontend_login_available();
			$login = $modal
				? '<button type="button" class="dmenu__signin" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal">' . esc_html__( 'Se connecter', 'delicat-builder-v9' ) . '</button>'
				: '<a class="dmenu__signin" href="' . esc_url( self::account_url() ) . '">' . esc_html__( 'Se connecter', 'delicat-builder-v9' ) . '</a>';

			return '<div class="dmenu__account"><div class="dmenu__accountcard">'
				. '<span class="dmenu__avatar" aria-hidden="true">' . self::icon( 'login' ) . '</span>'
				. '<span class="dmenu__copy"><strong>' . esc_html__( 'Bienvenue chez Delicat', 'delicat-builder-v9' ) . '</strong>'
				. '<small>' . esc_html__( 'Connectez-vous pour votre wallet et vos commandes.', 'delicat-builder-v9' ) . '</small></span>'
				. $login . '</div></div>';
		}

		self::mark_private();
		$user = wp_get_current_user();
		$name = trim( $user->first_name . ' ' . $user->last_name );
		if ( '' === $name ) {
			$name = $user->display_name ?: $user->user_login;
		}
		$initial = mb_strtoupper( mb_substr( trim( $name ), 0, 1, 'UTF-8' ), 'UTF-8' );
		$badge   = trim( $settings['profile_badge'] );
		$code    = self::user_code( (int) $user->ID );
		$logout  = class_exists( 'Delicat_Builder_V9_Identity_Bridge', false )
			? Delicat_Builder_V9_Identity_Bridge::logout_url()
			: wp_logout_url( home_url( '/' ) );

		$html = '<div class="dmenu__account"><a class="dmenu__accountcard" href="' . esc_url( self::account_url() ) . '">'
			. '<span class="dmenu__avatar" aria-hidden="true">' . esc_html( $initial ) . '</span>'
			. '<span class="dmenu__copy"><strong><span>' . esc_html( $name ) . '</span>'
			. ( '' !== $badge ? '<em>' . esc_html( $badge ) . '</em>' : '' ) . '</strong>'
			. '<small>' . esc_html( (string) $user->user_email ) . '</small></span>'
			. '<span class="dmenu__chevron" aria-hidden="true">' . self::icon( 'chevron' ) . '</span></a>';

		$html .= '<div class="dmenu__tools">';
		if ( '' !== $code ) {
			$html .= '<button type="button" class="dmenu__code" data-dmenu-copy="' . esc_attr( $code ) . '" aria-label="' . esc_attr__( 'Copier mon code client', 'delicat-builder-v9' ) . '">'
				. '<code>' . esc_html( $code ) . '</code>' . self::icon( 'copy' )
				. '<span data-dmenu-copy-label>' . esc_html__( 'Copier', 'delicat-builder-v9' ) . '</span></button>';
		}
		$html .= '<a class="dmenu__logout" href="' . esc_url( $logout ) . '">' . self::icon( 'logout' ) . esc_html__( 'Déconnexion', 'delicat-builder-v9' ) . '</a>';

		return $html . '</div></div>';
	}

	public static function render_wallet(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['show_wallet'] || ! is_user_logged_in() ) {
			return '';
		}
		$balance  = self::balance_text();
		$wallet   = self::url( $settings['wallet_url'], home_url( '/my-wallet/' ) );
		$recharge = self::url( $settings['recharge_url'], $wallet );
		$points   = trim( $settings['loyalty_points'] );

		$html = '<section class="dmenu__wallet"><a class="dmenu__walletmain" href="' . esc_url( $wallet ) . '">'
			. '<span class="dmenu__walleticon" aria-hidden="true">' . self::icon( 'wallet' ) . '</span>'
			. '<span class="dmenu__copy"><small>' . esc_html( $settings['wallet_label'] ) . '</small>'
			. '<strong data-dmenu-balance>' . ( '' !== $balance ? $balance : '—' ) . '</strong>';
		if ( '' !== $points && '0' !== $points ) {
			$html .= '<span class="dmenu__points">' . self::icon( 'points' ) . esc_html( $points ) . ' ' . esc_html__( 'points', 'delicat-builder-v9' ) . '</span>';
		}
		return $html . '</span></a><a class="dmenu__recharge" href="' . esc_url( $recharge ) . '">' . esc_html__( 'Recharger', 'delicat-builder-v9' ) . '</a></section>';
	}

	public static function render_quick(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['quick_actions'] ) {
			return '';
		}
		$html = '';
		foreach ( self::quick_items() as $tile ) {
			if ( ! self::visible( $tile ) || '' === $tile['label'] ) {
				continue;
			}
			$icon = '' !== $tile['image']
				? '<img src="' . esc_url( $tile['image'] ) . '" alt="" loading="lazy" decoding="async" width="20" height="20">'
				: self::icon( '' !== $tile['icon'] ? $tile['icon'] : strtolower( remove_accents( $tile['label'] ) ) );
			$url   = self::url( $tile['url'], home_url( '/' ) );
			$html .= '<a class="dmenu__tile" href="' . esc_url( $url ) . '"' . ( '#install-app' === $tile['url'] ? ' data-dmenu-install' : '' ) . '>'
				. '<span class="dmenu__tileicon" aria-hidden="true">' . $icon . '</span><span>' . esc_html( $tile['label'] ) . '</span></a>';
		}
		return '' !== $html ? '<nav class="dmenu__quick" aria-label="' . esc_attr__( 'Raccourcis', 'delicat-builder-v9' ) . '">' . $html . '</nav>' : '';
	}

	public static function render_promo(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['promo_enabled'] || '' === $settings['promo_title'] ) {
			return '';
		}
		return '<section class="dmenu__promo"><strong>' . esc_html( $settings['promo_title'] ) . '</strong>'
			. '<p>' . esc_html( $settings['promo_text'] ) . '</p>'
			. '<a href="' . esc_url( self::url( $settings['promo_url'], home_url( '/' ) ) ) . '">' . esc_html( $settings['promo_button'] ) . '</a></section>';
	}

	public static function render_social(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['social_enabled'] ) {
			return '';
		}
		$html = '';
		foreach ( array( 'whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'youtube' ) as $network ) {
			if ( 'yes' !== $settings[ $network . '_enabled' ] ) {
				continue;
			}
			$url = self::url( $settings[ $network . '_url' ], '' );
			if ( '' === $url || ! self::is_social_url( $network, $url ) ) {
				/* A placeholder such as "/my-wallet/" saved in a social field is
				 * not a social link. */
				continue;
			}
			$label = $settings[ $network . '_label' ] ?: ucfirst( $network );
			$html .= '<a class="is-' . esc_attr( $network ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . self::icon( $network ) . '</a>';
		}
		return '' !== $html ? '<div class="dmenu__social">' . $html . '</div>' : '';
	}

	private static function is_social_url( string $network, string $url ): bool {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$hosts = array(
			'whatsapp'  => array( 'wa.me', 'whatsapp.com', 'api.whatsapp.com' ),
			'telegram'  => array( 't.me', 'telegram.me', 'telegram.org' ),
			'instagram' => array( 'instagram.com', 'instagr.am' ),
			'facebook'  => array( 'facebook.com', 'fb.com', 'fb.me', 'm.me' ),
			'tiktok'    => array( 'tiktok.com' ),
			'youtube'   => array( 'youtube.com', 'youtu.be' ),
		);
		foreach ( $hosts[ $network ] ?? array() as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	public static function render_foot(): string {
		$settings = self::settings();
		$html     = '';
		if ( 'yes' === $settings['footer_toggle'] ) {
			$html .= '<button type="button" class="dmenu__theme" data-delicat-theme-toggle aria-pressed="false">'
				. self::icon( 'moon' ) . '<span>' . esc_html__( 'Mode sombre', 'delicat-builder-v9' ) . '</span>'
				. '<i class="dmenu__switch" aria-hidden="true"></i></button>';
		}

		$links = '';
		$terms = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
		if ( $terms > 0 && 'publish' === get_post_status( $terms ) ) {
			$links .= '<a href="' . esc_url( (string) get_permalink( $terms ) ) . '">' . esc_html__( 'Conditions', 'delicat-builder-v9' ) . '</a>';
		}
		$privacy = self::privacy_page_id();
		if ( $privacy > 0 ) {
			$links .= '<a href="' . esc_url( (string) get_permalink( $privacy ) ) . '">' . esc_html__( 'Confidentialité', 'delicat-builder-v9' ) . '</a>';
		}
		$shop = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$links .= '<a href="' . esc_url( $shop ) . '">' . esc_html__( 'Boutique', 'delicat-builder-v9' ) . '</a>';

		$html .= '<p class="dmenu__links">' . $links . '</p>';
		return '' !== $html ? '<div class="dmenu__foot">' . $html . '</div>' : '';
	}

	private static function privacy_page_id(): int {
		$french = get_page_by_path( 'politique-confidentialite', OBJECT, 'page' );
		if ( $french instanceof WP_Post && 'publish' === $french->post_status ) {
			/* The published French page wins over an unpublished WP setting. */
			return (int) $french->ID;
		}
		$privacy = (int) get_option( 'wp_page_for_privacy_policy' );
		return ( $privacy > 0 && 'publish' === get_post_status( $privacy ) ) ? $privacy : 0;
	}

	/* ------------------------------------------------------------------ */
	/* document                                                            */
	/* ------------------------------------------------------------------ */

	/** The panel body: everything that lives inside the <template>. */
	private static function body( bool $inline = false ): string {
		$settings = self::settings();
		$html     = '';
		$main     = self::group( 'main' );
		$bonus    = 'yes' === $settings['bonus_enabled'] ? self::group( 'bonus' ) : array();

		/* The field sits directly above the first list of links, where the
		 * shopper is already looking, and only appears once the menu is long
		 * enough to be worth filtering. */
		$search = '';
		if ( ! $inline && ( count( $main ) + count( $bonus ) ) >= self::SEARCH_THRESHOLD ) {
			$search = '<form class="dmenu__search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '">'
				. self::icon( 'search' )
				. '<label class="screen-reader-text" for="dmenu-search">' . esc_html__( 'Rechercher', 'delicat-builder-v9' ) . '</label>'
				. '<input id="dmenu-search" type="search" name="s" autocomplete="off" enterkeyhint="search" data-dmenu-search placeholder="' . esc_attr__( 'Rechercher un jeu, une carte…', 'delicat-builder-v9' ) . '">'
				. '<input type="hidden" name="post_type" value="product"></form>'
				. '<p class="dmenu__empty" data-dmenu-empty hidden>' . esc_html__( 'Aucun résultat dans le menu — appuyez sur Entrée pour chercher dans la boutique.', 'delicat-builder-v9' ) . '</p>';
		}

		foreach ( explode( ',', $settings['section_order'] ) as $section ) {
			if ( 'main' === $section ) {
				$html   .= $search;
				$search  = '';
			}
			$html .= match ( $section ) {
				'profile' => self::render_account(),
				'wallet'  => self::render_wallet(),
				'quick'   => self::render_quick(),
				'promo'   => self::render_promo(),
				'social'  => self::render_social(),
				'footer'  => self::render_foot(),
				'main'    => $main
					? '<section class="dmenu__card dmenu__section" data-dmenu-section><h3 class="dmenu__title">' . esc_html__( 'Menu', 'delicat-builder-v9' ) . '</h3><ul class="dmenu__list">'
						. implode( '', array_map( self::render_item( ... ), $main ) ) . '</ul></section>'
					: '',
				'bonus'   => $bonus
					? '<section class="dmenu__card dmenu__section" data-dmenu-section><button type="button" class="dmenu__toggle" aria-expanded="true" data-dmenu-toggle>'
						. esc_html( $settings['bonus_title'] ) . self::icon( 'chevron' ) . '</button><ul class="dmenu__list">'
						. implode( '', array_map( self::render_item( ... ), $bonus ) ) . '</ul></section>'
					: '',
				default   => '',
			};
		}

		return $html . $search;
	}

	/**
	 * The overlay, plus the panel body in an inert <template>.
	 *
	 * Keeping the body in a template is the whole point of this engine: the
	 * document carries the markup so there is no second request and no layout
	 * shift, while the browser skips styling and laying it out until the
	 * runtime hydrates it during idle time.
	 */
	public static function render(): string {
		if ( self::$rendered || ! self::owns() ) {
			return '';
		}
		self::$rendered = true;

		$settings = self::settings();
		$title    = 'yes' === $settings['custom_header_enabled'] && '' !== $settings['custom_header_title']
			? $settings['custom_header_title']
			: (string) get_bloginfo( 'name' );
		$subtitle = 'yes' === $settings['custom_header_enabled'] ? $settings['custom_header_subtitle'] : '';
		$logo     = 'yes' === $settings['custom_header_enabled'] ? $settings['custom_header_image'] : '';
		$home     = self::url( $settings['custom_header_url'], home_url( '/' ) );

		$vars = '--dmenu-primary:' . $settings['primary']
			. ';--dmenu-secondary:' . $settings['secondary']
			. ';--dmenu-accent:' . $settings['accent']
			. ';--dmenu-radius:' . (int) $settings['radius'] . 'px'
			. ';--dmenu-item-radius:' . (int) $settings['item_radius'] . 'px'
			. ';--dmenu-width:' . (int) $settings['desktop_width'] . 'px'
			. ';--dmenu-tablet-width:' . (int) $settings['tablet_width'] . 'px'
			. ';--dmenu-mobile-width:' . (int) $settings['mobile_width'] . ( '%' === $settings['mobile_unit'] ? '%' : 'vw' )
			. ';--dmenu-scrim:' . round( (int) $settings['overlay_opacity'] / 100, 2 )
			. ';--dmenu-speed:' . (int) $settings['open_speed'] . 'ms';

		$brand = '<a class="dmenu__brand" href="' . esc_url( $home ) . '">';
		if ( '' !== $logo ) {
			$brand .= '<img class="dmenu__logo" src="' . esc_url( $logo ) . '" alt="" width="38" height="38" decoding="async">';
		} else {
			$brand .= '<span class="dmenu__mark" aria-hidden="true">' . esc_html( mb_strtoupper( mb_substr( '' !== $title ? $title : 'D', 0, 1, 'UTF-8' ), 'UTF-8' ) ) . '</span>';
		}
		if ( '' !== $title || '' !== $subtitle ) {
			$brand .= '<span class="dmenu__brandcopy"><strong>' . esc_html( $title ) . '</strong>'
				. ( '' !== $subtitle ? '<small>' . esc_html( $subtitle ) . '</small>' : '' ) . '</span>';
		}
		$brand .= '</a>';

		$html = '<div class="dmenu' . ( 'right' === $settings['position'] ? ' is-right' : '' ) . '" id="delicat-menu" data-dmenu hidden aria-hidden="true"'
			. ' data-speed="' . (int) $settings['open_speed'] . '"'
			. ' data-performance="' . esc_attr( $settings['performance_mode'] ) . '"'
			. ' data-scroll-lock="' . esc_attr( $settings['body_scroll_lock'] ) . '"'
			. ' data-swipe="' . esc_attr( $settings['swipe_close'] ) . '"'
			. ' data-ajax="' . esc_url( is_user_logged_in() ? admin_url( 'admin-ajax.php' ) : '' ) . '"'
			. ' data-wallet-nonce="' . esc_attr( is_user_logged_in() ? wp_create_nonce( self::WALLET_NONCE ) : '' ) . '"'
			. ' style="' . esc_attr( $vars ) . '">'
			. '<div class="dmenu__scrim"' . ( 'no' === $settings['close_on_overlay'] ? '' : ' data-dmenu-close' ) . '></div>'
			. '<aside class="dmenu__panel" role="dialog" aria-modal="true" aria-label="' . esc_attr( $title ) . '" tabindex="-1" data-dmenu-panel>'
			. '<header class="dmenu__top">' . $brand
			. '<button type="button" class="dmenu__close" data-dmenu-close aria-label="' . esc_attr__( 'Fermer le menu', 'delicat-builder-v9' ) . '">' . self::icon( 'close' ) . '</button>'
			. '</header>'
			. '<div class="dmenu__body" data-dmenu-body></div>'
			. '</aside></div>'
			. '<template data-dmenu-template>' . self::body() . '</template>';

		return $html;
	}

	/** The inline variant: the same sections, rendered straight into a page. */
	public static function render_inline(): string {
		if ( ! self::owns() ) {
			return '';
		}
		$settings = self::settings();
		$vars     = '--dmenu-primary:' . $settings['primary'] . ';--dmenu-secondary:' . $settings['secondary']
			. ';--dmenu-item-radius:' . (int) $settings['item_radius'] . 'px';
		return '<nav class="dmenu-inline" aria-label="' . esc_attr__( 'Menu', 'delicat-builder-v9' ) . '" style="' . esc_attr( $vars ) . '">'
			. '<div class="dmenu__body">' . self::body( true ) . '</div></nav>';
	}

	/* ------------------------------------------------------------------ */
	/* shortcodes                                                          */
	/* ------------------------------------------------------------------ */

	public static function shortcodes(): void {
		if ( function_exists( 'dsb_render_modern_menu' ) ) {
			return;
		}

		add_shortcode(
			'delicat_modern_menu',
			static function ( $atts ): string {
				$atts = shortcode_atts( array( 'layout' => '' ), $atts, 'delicat_modern_menu' );
				return 'inline' === $atts['layout'] ? self::render_inline() : '';
			}
		);

		add_shortcode(
			'delicat_menu_toggle',
			static function ( $atts ): string {
				$atts = shortcode_atts( array( 'label' => 'no' ), $atts, 'delicat_menu_toggle' );
				return '<button type="button" class="dsb-menu-toggle" data-dmenu-open aria-expanded="false" aria-label="' . esc_attr__( 'Ouvrir le menu', 'delicat-builder-v9' ) . '">'
					. '<span></span><span></span><span></span>'
					. ( 'yes' === $atts['label'] ? '<em>' . esc_html__( 'Menu', 'delicat-builder-v9' ) . '</em>' : '' )
					. '</button>';
			}
		);

		add_shortcode( 'delicat_profile_card', static fn(): string => self::render_account() );
		add_shortcode( 'delicat_wallet_card', static fn(): string => self::render_wallet() );
		add_shortcode( 'delicat_quick_actions', static fn(): string => self::render_quick() );
		add_shortcode( 'delicat_menu_promo', static fn(): string => self::render_promo() );
		add_shortcode( 'delicat_social_row', static fn(): string => self::render_social() );
		add_shortcode(
			'delicat_bonus_menu',
			static fn(): string => '<ul class="dmenu__list">' . self::render_items( 'bonus' ) . '</ul>'
		);
		add_shortcode( 'delicat_bottom_nav', static fn(): string => self::render_tabs() );

		if ( ! shortcode_exists( 'delicat_theme_toggle' ) ) {
			add_shortcode(
				'delicat_theme_toggle',
				static fn(): string => '<button type="button" class="dmenu__theme" data-delicat-theme-toggle aria-pressed="false">'
					. self::icon( 'moon' ) . '<span>' . esc_html__( 'Mode sombre', 'delicat-builder-v9' ) . '</span><i class="dmenu__switch" aria-hidden="true"></i></button>'
			);
		}
	}

	/**
	 * [delicat_bottom_nav]. The storefront's own floating bar (Bottom Nav,
	 * rendered in the footer) owns the bottom edge whenever it is present, so
	 * this only draws a strip on a site that has turned that module off.
	 */
	public static function render_tabs(): string {
		$settings = self::settings();
		if ( 'yes' !== $settings['bottom_nav'] ) {
			return '';
		}
		if ( class_exists( 'Delicat_Builder_V9_Bottom_Nav', false ) && is_callable( array( 'Delicat_Builder_V9_Bottom_Nav', 'expected' ) ) && Delicat_Builder_V9_Bottom_Nav::expected() ) {
			return '';
		}
		if ( class_exists( 'Delicat_Builder_V9_Shell', false ) && is_callable( array( 'Delicat_Builder_V9_Shell', 'mobile_nav_expected' ) ) && Delicat_Builder_V9_Shell::mobile_nav_expected() ) {
			return '';
		}

		$html = '';
		foreach ( array_slice( self::group( 'main' ), 0, 5 ) as $item ) {
			$url   = self::url( self::scalar( $item['url'] ?? '' ), home_url( '/' ) );
			$html .= '<a class="' . ( self::is_current( $url ) ? 'is-current' : '' ) . '" href="' . esc_url( $url ) . '">'
				. self::item_icon( $item ) . '<span>' . self::tokens( esc_html( self::scalar( $item['title'] ?? '' ) ) ) . '</span></a>';
		}
		return '' !== $html
			? '<nav class="dmenu-tabs" aria-label="' . esc_attr__( 'Navigation mobile', 'delicat-builder-v9' ) . '" style="--dmenu-primary:' . esc_attr( $settings['primary'] ) . '">' . $html . '</nav>'
			: '';
	}

	/* ------------------------------------------------------------------ */
	/* wallet endpoint                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Read-only balance endpoint used by the panel when it opens.
	 * Reads TeraWallet and nothing else — it never credits, debits or writes.
	 */
	public static function ajax_balance(): void {
		nocache_headers();

		if ( ! check_ajax_referer( self::WALLET_NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Requête non autorisée.', 'delicat-builder-v9' ) ), 403 );
		}
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Non connecté.', 'delicat-builder-v9' ) ), 403 );
		}

		$user_id = get_current_user_id();
		/* A fixed one-minute bucket, not a sliding expiry: refreshing the TTL on
		 * every hit never actually resets for a client that keeps asking, so an
		 * open menu would creep to the ceiling and lock out a real customer. */
		$bucket = 'dlcv9_wbl_' . $user_id . '_' . (int) floor( time() / 60 );
		$hits   = (int) get_transient( $bucket );
		if ( $hits >= 60 ) {
			wp_send_json_error( array( 'message' => __( 'Trop de requêtes.', 'delicat-builder-v9' ) ), 429 );
		}
		set_transient( $bucket, $hits + 1, 120 );

		$raw  = 0.0;
		$html = '';
		if ( function_exists( 'woo_wallet' ) ) {
			try {
				$raw  = (float) woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
				$html = wp_kses_post( woo_wallet()->wallet->get_wallet_balance( $user_id ) );
			} catch ( Throwable ) {
				$raw = 0.0;
			}
		}

		wp_send_json_success(
			array(
				'raw'      => $raw,
				'html'     => $html,
				'currency' => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG',
			)
		);
	}
}

/* ---------------------------------------------------------------------- */
/* Compatibility shims.                                                    */
/*                                                                         */
/* Other modules, the self-test screen and third-party snippets call these  */
/* by name. They are thin forwarders to the engine above, kept so deleting  */
/* two 900-line runtime files breaks nothing that used to work.             */
/* ---------------------------------------------------------------------- */

function delicat_builder_v9_menu_menu_builder_defaults(): array {
	return Delicat_Builder_V9_Menu_Engine::defaults();
}

function delicat_builder_v9_menu_menu_builder_default_items(): array {
	return Delicat_Builder_V9_Menu_Engine::default_items();
}

function delicat_builder_v9_menu_get_menu_builder_settings(): array {
	return Delicat_Builder_V9_Menu_Engine::settings();
}

function delicat_builder_v9_menu_get_menu_builder_items(): array {
	return Delicat_Builder_V9_Menu_Engine::items();
}

function delicat_builder_v9_menu_get_menu_quick_items(): array {
	return Delicat_Builder_V9_Menu_Engine::quick_items();
}

function delicat_builder_v9_menu_sanitize_menu_builder_settings( $raw ): array {
	return Delicat_Builder_V9_Menu_Engine::normalize_settings( $raw );
}

function delicat_builder_v9_menu_sanitize_menu_builder_items( $raw ): array {
	return Delicat_Builder_V9_Menu_Engine::normalize_items( $raw );
}

function delicat_builder_v9_menu_sanitize_menu_quick_items( $raw ): array {
	return Delicat_Builder_V9_Menu_Engine::normalize_quick( $raw );
}

function delicat_builder_v9_menu_menu_item_visible( $item ): bool {
	return is_array( $item ) && Delicat_Builder_V9_Menu_Engine::visible( $item );
}

function delicat_builder_v9_menu_menu_apply_tokens( $escaped_text ): string {
	return Delicat_Builder_V9_Menu_Engine::tokens( (string) $escaped_text );
}

function delicat_builder_v9_menu_menu_balance_html(): string {
	return Delicat_Builder_V9_Menu_Engine::balance_html();
}

function delicat_builder_v9_menu_wallet_balance_text(): string {
	$text = Delicat_Builder_V9_Menu_Engine::balance_text();
	return '' !== $text ? $text : '—';
}

function delicat_builder_v9_menu_menu_account_url( $endpoint = '' ): string {
	return Delicat_Builder_V9_Menu_Engine::account_url( (string) $endpoint );
}

function delicat_builder_v9_menu_get_delicat_user_code( $user_id ): string {
	$code = Delicat_Builder_V9_Menu_Engine::user_code( (int) $user_id );
	return '' !== $code ? $code : '—';
}

function delicat_builder_v9_menu_mark_page_private(): void {
	Delicat_Builder_V9_Menu_Engine::mark_private();
}

function delicat_builder_v9_menu_native_icon_svg( $icon ): string {
	return Delicat_Builder_V9_Menu_Engine::icon( (string) $icon );
}

function delicat_builder_v9_menu_menu_item_is_current( $url ): bool {
	return Delicat_Builder_V9_Menu_Engine::is_current( (string) $url );
}

function delicat_builder_v9_menu_menu_item_is_wallet( $item ): bool {
	return is_array( $item ) && Delicat_Builder_V9_Menu_Engine::is_wallet_item( $item );
}

function delicat_builder_v9_menu_normalize_item_url( $url ): string {
	return Delicat_Builder_V9_Menu_Engine::url( (string) $url, (string) $url );
}

function delicat_builder_v9_menu_render_menu_items( $group = '' ): string {
	return Delicat_Builder_V9_Menu_Engine::render_items( (string) $group );
}

function delicat_builder_v9_menu_render_profile_card(): string {
	return Delicat_Builder_V9_Menu_Engine::render_account();
}

function delicat_builder_v9_menu_render_wallet_card(): string {
	return Delicat_Builder_V9_Menu_Engine::render_wallet();
}

function delicat_builder_v9_menu_render_quick_actions(): string {
	return Delicat_Builder_V9_Menu_Engine::render_quick();
}

function delicat_builder_v9_menu_render_modern_menu( $mode = 'drawer' ): string {
	return 'inline' === $mode ? Delicat_Builder_V9_Menu_Engine::render_inline() : Delicat_Builder_V9_Menu_Engine::render();
}

endif; /* class_exists guard */
