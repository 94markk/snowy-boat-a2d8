<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC1 compatibility, migration and rollback coordinator.
 *
 * It does not replace WooCommerce/Elementor/WordPress behavior. It declares
 * supported Woo block compatibility, registers small per-block styles, detects
 * conflicts, validates non-secret Builder snapshots and keeps a reversible
 * settings migration path.
 */
final class Delicat_Builder_V9_Release {
	public const SCHEMA_VERSION = '1.0.39-rc47';
	public const SCHEMA_OPTION = 'delicat_builder_v9_schema_version';
	public const PRE_RC_BACKUP_OPTION = 'delicat_builder_v9_pre_rc1_backup';
	public const LAST_RESTORE_BACKUP_OPTION = 'delicat_builder_v9_last_restore_backup';
	public const VALIDATION_TRANSIENT_PREFIX = 'delicat_builder_v9_snapshot_validation_';
	public const MANUAL_GATE_OPTION = 'delicat_builder_v9_rc2_manual_gate';
	public const SELF_TEST_TRANSIENT = 'delicat_builder_v9_rc2_self_test';

	public static function boot(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate' ), 3 );
		add_action( 'init', array( __CLASS__, 'register_woo_block_styles' ), 30 );
		add_filter( 'site_status_tests', array( __CLASS__, 'site_health_tests' ) );
	}

	public static function schema_version(): string {
		return sanitize_text_field( (string) get_option( self::SCHEMA_OPTION, '' ) );
	}

	public static function maybe_migrate(): void {
		$current = self::schema_version();
		if ( self::SCHEMA_VERSION === $current ) {
			return;
		}

		// Schema migrations can update Builder layout metadata. Only an
		// administrator should trigger those writes.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// RC1 does not rewrite Builder page layouts. The automatic backup contains
		// only non-secret Builder configuration, while page layouts retain their
		// own revision history in post meta.
		if ( false === get_option( self::PRE_RC_BACKUP_OPTION, false ) ) {
			add_option(
				self::PRE_RC_BACKUP_OPTION,
				array(
					'created_at' => gmdate( 'c' ),
					'from'       => $current ?: 'pre-schema',
					'snapshot'   => Delicat_Builder_V9_Production::config_snapshot(),
				),
				'',
				false
			);
		}

		$normalized_pages = self::migrate_rc20_mobile_info_defaults();

		/*
		 * RC47 secure-performance ships a new bootstrap graph and hardened runtime.
		 * A one-time Builder cache version bump plus LiteSpeed purge prevents
		 * guest devices from retaining stale RC46 HTML/assets after the update.
		 */
		if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		do_action( 'litespeed_purge_all' );

		update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false );
		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_rc1_schema_migrated',
			'notice',
			array(
				'from'             => $current ?: 'pre-schema',
				'to'               => self::SCHEMA_VERSION,
				'normalized_pages' => $normalized_pages,
			)
		);
	}

private static function migrate_rc20_mobile_info_defaults(): int {
	if ( ! class_exists( 'Delicat_Builder_V9_Pages' ) ) {
		return 0;
	}

	$page_ids = get_posts(
		array(
			'post_type'              => 'page',
			'post_status'            => array( 'publish', 'draft', 'private', 'pending' ),
			'posts_per_page'         => 250,
			'fields'                 => 'ids',
			'meta_key'               => Delicat_Builder_V9_Pages::META_LAYOUT,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$changed_pages = 0;

	foreach ( (array) $page_ids as $page_id ) {
		$page_id = absint( $page_id );
		if ( $page_id <= 0 ) {
			continue;
		}

		$layout = get_post_meta( $page_id, Delicat_Builder_V9_Pages::META_LAYOUT, true );
		if ( ! is_array( $layout ) ) {
			continue;
		}

		$changed = false;
		foreach ( $layout as &$section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
			if ( ! in_array( $type, array( 'how_it_works', 'testimonials', 'faq' ), true ) ) {
				continue;
			}

			if ( ! isset( $section['content'] ) || ! is_array( $section['content'] ) ) {
				$section['content'] = array();
			}

			// Only exact legacy defaults are normalized. User-customized
			// values remain unchanged.
			if ( isset( $section['content']['title_size_m'] ) && 34 === absint( $section['content']['title_size_m'] ) ) {
				$section['content']['title_size_m'] = 28;
				$changed = true;
			}

			if (
				'testimonials' === $type
				&& isset( $section['content']['card_width_m'] )
				&& 82 === absint( $section['content']['card_width_m'] )
			) {
				$section['content']['card_width_m'] = 76;
				$changed = true;
			}
		}
		unset( $section );

		if ( ! $changed ) {
			continue;
		}

		$layout = Delicat_Builder_V9_Schema::sanitize_layout( $layout );
		update_post_meta( $page_id, Delicat_Builder_V9_Pages::META_LAYOUT, $layout );
		Delicat_Builder_V9_Compiler::ensure_manifest( $page_id, $layout );
		Delicat_Builder_V9_Cache::purge_page( $page_id );
		$changed_pages++;
	}

	return $changed_pages;
}

	public static function register_woo_block_styles(): void {
		if (
			! Delicat_Builder_V9_Core::is_enabled()
			|| Delicat_Builder_V9_Core::is_safe_mode()
			|| ! function_exists( 'wp_enqueue_block_style' )
			|| ! class_exists( 'WooCommerce' )
		) {
			return;
		}

		$woo = Delicat_Builder_V9_Woo_UI::settings();
		$purchase = Delicat_Builder_V9_Purchase_UI::settings();

		if ( ! empty( $woo['enabled'] ) && ! empty( $woo['archive_enabled'] ) ) {
			self::enqueue_block_style(
				'woocommerce/product-collection',
				'delicat-builder-v9-rc-product-collection',
				'assets/css/blocks/product-collection.css'
			);
		}

		if ( ! empty( $purchase['enabled'] ) && empty( $purchase['native_cart'] ) && ! empty( $purchase['cart_style'] ) ) {
			self::enqueue_block_style(
				'woocommerce/cart',
				'delicat-builder-v9-rc-cart-block',
				'assets/css/blocks/cart.css'
			);
		}

		if ( ! empty( $purchase['enabled'] ) && empty( $purchase['native_checkout'] ) && ! empty( $purchase['checkout_style'] ) ) {
			self::enqueue_block_style(
				'woocommerce/checkout',
				'delicat-builder-v9-rc-checkout-block',
				'assets/css/blocks/checkout.css'
			);
		}
	}

	private static function enqueue_block_style( string $block, string $handle, string $relative ): void {
		$path = DELICAT_BUILDER_V9_DIR . ltrim( $relative, '/' );
		if ( ! is_file( $path ) ) {
			return;
		}

		wp_enqueue_block_style(
			$block,
			array(
				'handle' => $handle,
				'src'    => DELICAT_BUILDER_V9_URL . ltrim( $relative, '/' ),
				'path'   => $path,
				'ver'    => (string) filemtime( $path ),
			)
		);
	}

	public static function active_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all = get_plugins();
		$active = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$enabled = array_fill_keys( array_merge( $active, $network ), true );

		$result = array();
		foreach ( $all as $file => $data ) {
			if ( isset( $enabled[ $file ] ) ) {
				$result[ $file ] = $data;
			}
		}
		return $result;
	}

	private static function plugin_match( string $needle ): array {
		$needle = strtolower( $needle );
		foreach ( self::active_plugins() as $file => $data ) {
			$haystack = strtolower( $file . ' ' . ( $data['Name'] ?? '' ) );
			if ( false !== strpos( $haystack, $needle ) ) {
				return array(
					'active'  => true,
					'file'    => $file,
					'name'    => sanitize_text_field( (string) ( $data['Name'] ?? $file ) ),
					'version' => sanitize_text_field( (string) ( $data['Version'] ?? '' ) ),
				);
			}
		}
		return array( 'active' => false, 'file' => '', 'name' => '', 'version' => '' );
	}

	public static function cart_checkout_modes(): array {
		$modes = array(
			'cart'     => 'unassigned',
			'checkout' => 'unassigned',
			'account'  => 'unassigned',
		);

		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return $modes;
		}

		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $type ) {
			$key = 'myaccount' === $type ? 'account' : $type;
			$page_id = wc_get_page_id( $type );
			if ( $page_id <= 0 ) {
				continue;
			}
			$post = get_post( $page_id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$content = (string) $post->post_content;
			if ( 'cart' === $type && function_exists( 'has_block' ) && has_block( 'woocommerce/cart', $post ) ) {
				$modes[ $key ] = 'block';
			} elseif ( 'checkout' === $type && function_exists( 'has_block' ) && has_block( 'woocommerce/checkout', $post ) ) {
				$modes[ $key ] = 'block';
			} elseif (
				( 'cart' === $type && has_shortcode( $content, 'woocommerce_cart' ) )
				|| ( 'checkout' === $type && has_shortcode( $content, 'woocommerce_checkout' ) )
				|| ( 'myaccount' === $type && has_shortcode( $content, 'woocommerce_my_account' ) )
			) {
				$modes[ $key ] = 'classic';
			} else {
				$modes[ $key ] = 'theme/custom';
			}
		}
		return $modes;
	}

	public static function product_collection_detected(): bool {
		if ( ! function_exists( 'has_block' ) ) {
			return false;
		}

		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id = wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$shop = get_post( $shop_id );
				if ( $shop instanceof WP_Post && has_block( 'woocommerce/product-collection', $shop ) ) {
					return true;
				}
			}
		}

		if ( function_exists( 'get_block_templates' ) ) {
			foreach ( (array) get_block_templates( array(), 'wp_template' ) as $template ) {
				$content = is_object( $template ) ? (string) ( $template->content ?? '' ) : '';
				if ( '' !== $content && false !== strpos( $content, '<!-- wp:woocommerce/product-collection' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	public static function duplicate_builder_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$current = plugin_basename( DELICAT_BUILDER_V9_FILE );
		$matches = array();
		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $current ) {
				continue;
			}
			$name = strtolower( (string) ( $data['Name'] ?? '' ) );
			if ( false !== strpos( $name, 'delicat builder v9' ) ) {
				$matches[] = array(
					'file'    => $file,
					'name'    => sanitize_text_field( (string) ( $data['Name'] ?? $file ) ),
					'version' => sanitize_text_field( (string) ( $data['Version'] ?? '' ) ),
				);
			}
		}
		return $matches;
	}

	public static function compatibility_matrix(): array {
		$modes = self::cart_checkout_modes();
		$elementor = self::plugin_match( 'elementor/elementor.php' );
		$elementor_pro = self::plugin_match( 'elementor-pro' );
		$litespeed = self::plugin_match( 'litespeed-cache' );
		$hostinger = self::plugin_match( 'hostinger' );
		$shell = Delicat_Builder_V9_Shell::settings();
		$woo = Delicat_Builder_V9_Woo_UI::settings();
		$duplicates = self::duplicate_builder_plugins();
		$integrity = Delicat_Builder_V9_Production::integrity_scan();

		$theme = wp_get_theme();
		$block_theme = function_exists( 'wp_is_block_theme' ) && wp_is_block_theme();
		$auto_shell = ! empty( $shell['enabled'] );
		$theme_builder_risk = $auto_shell && ( $block_theme || $elementor_pro['active'] );

		$rows = array(
			'wordpress' => array(
				'label' => 'WordPress',
				'status'=> version_compare( get_bloginfo( 'version' ), '6.4', '>=' ) ? 'pass' : 'blocker',
				'text'  => get_bloginfo( 'version' ),
			),
			'php' => array(
				'label' => 'PHP',
				'status'=> version_compare( PHP_VERSION, '8.0', '>=' ) ? 'pass' : 'blocker',
				'text'  => PHP_VERSION,
			),
			'https' => array(
				'label' => 'HTTPS',
				'status'=> is_ssl() ? 'pass' : 'blocker',
				'text'  => is_ssl() ? 'Active' : 'Not detected',
			),
			'woocommerce' => array(
				'label' => 'WooCommerce',
				'status'=> class_exists( 'WooCommerce' ) ? 'pass' : 'blocker',
				'text'  => defined( 'WC_VERSION' ) ? WC_VERSION : 'Not detected',
			),
			'identity' => array(
				'label' => 'Identity Pro',
				'status'=> Delicat_Builder_V9_Identity_Bridge::compatible() ? 'pass' : 'blocker',
				'text'  => Delicat_Builder_V9_Identity_Bridge::compatible()
					? 'Synchronized v' . Delicat_Builder_V9_Identity_Bridge::identity_version()
					: 'Compatibility required',
			),
			'integrity' => array(
				'label' => 'Builder code integrity',
				'status'=> ! empty( $integrity['ok'] ) ? 'pass' : 'blocker',
				'text'  => ! empty( $integrity['ok'] )
					? absint( $integrity['checked'] ?? 0 ) . ' files verified'
					: 'Changed/missing files detected',
			),
			'schema' => array(
				'label' => 'RC schema',
				'status'=> self::SCHEMA_VERSION === self::schema_version() ? 'pass' : 'blocker',
				'text'  => self::schema_version() ?: 'Not migrated',
			),
			'duplicates' => array(
				'label' => 'Duplicate Builder copies',
				'status'=> empty( $duplicates ) ? 'pass' : 'blocker',
				'text'  => empty( $duplicates ) ? 'None detected' : count( $duplicates ) . ' other copy/copies',
			),
			'app_navigation' => array(
				'label' => 'App Navigation',
				'status'=> empty( Delicat_Builder_V9_Core::settings()['app_navigation'] ) ? 'pass' : 'warning',
				'text'  => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] ) ? 'OFF — RC default' : 'ON — stage carefully',
			),
			'safe_mode' => array(
				'label' => 'Safe Mode',
				'status'=> Delicat_Builder_V9_Core::is_safe_mode() ? 'warning' : 'pass',
				'text'  => Delicat_Builder_V9_Core::is_safe_mode() ? 'ACTIVE' : 'Off',
			),
			'theme' => array(
				'label' => 'Theme',
				'status'=> $theme_builder_risk ? 'warning' : 'pass',
				'text'  => sprintf(
					'%s · %s',
					$theme->get( 'Name' ) ?: 'Unknown',
					$block_theme ? 'block theme' : 'classic/hybrid'
				),
			),
			'elementor' => array(
				'label' => 'Elementor',
				'status'=> $theme_builder_risk && $elementor_pro['active'] ? 'warning' : 'pass',
				'text'  => $elementor['active']
					? trim( 'Active ' . $elementor['version'] . ( $elementor_pro['active'] ? ' + Pro ' . $elementor_pro['version'] : '' ) )
					: 'Not active',
			),
			'product_collection' => array(
				'label' => 'Woo Product Collection',
				'status'=> self::product_collection_detected() ? 'pass' : 'pass',
				'text'  => self::product_collection_detected()
					? 'Detected · RC per-block CSS available'
					: 'Not detected in Shop/block templates',
			),
			'cart_mode' => array(
				'label' => 'Cart',
				'status'=> 'unassigned' === $modes['cart'] ? 'blocker' : 'pass',
				'text'  => ucfirst( $modes['cart'] ),
			),
			'checkout_mode' => array(
				'label' => 'Checkout',
				'status'=> 'unassigned' === $modes['checkout'] ? 'blocker' : 'pass',
				'text'  => ucfirst( $modes['checkout'] ),
			),
			'account_mode' => array(
				'label' => 'My Account',
				'status'=> 'unassigned' === $modes['account'] ? 'blocker' : 'pass',
				'text'  => ucfirst( $modes['account'] ),
			),
			'litespeed' => array(
				'label' => 'LiteSpeed Cache',
				'status'=> 'pass',
				'text'  => $litespeed['active'] ? 'Active ' . $litespeed['version'] : 'Not active',
			),
			'hostinger' => array(
				'label' => 'Hostinger plugin',
				'status'=> 'pass',
				'text'  => $hostinger['active'] ? 'Active ' . $hostinger['version'] : 'Not detected',
			),
		);

		return $rows;
	}

	public static function gate_summary(): array {
		$matrix = self::compatibility_matrix();
		$blockers = 0;
		$warnings = 0;
		foreach ( $matrix as $row ) {
			if ( 'blocker' === $row['status'] ) {
				$blockers++;
			} elseif ( 'warning' === $row['status'] ) {
				$warnings++;
			}
		}
		return array(
			'ready'    => 0 === $blockers,
			'blockers' => $blockers,
			'warnings' => $warnings,
			'rows'     => $matrix,
		);
	}

	public static function sanitize_snapshot( array $snapshot, bool $allow_foreign_host = false ) {
		if ( 'delicat-builder-v9-config' !== ( $snapshot['format'] ?? '' ) ) {
			return new WP_Error( 'invalid_format', __( 'This is not a Delicat Builder V9 configuration snapshot.', 'delicat-builder-v9' ) );
		}

		$options = is_array( $snapshot['options'] ?? null ) ? $snapshot['options'] : array();
		if ( empty( $options ) ) {
			return new WP_Error( 'empty_snapshot', __( 'The snapshot does not contain Builder configuration options.', 'delicat-builder-v9' ) );
		}

		$current_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$snapshot_host = strtolower( sanitize_text_field( (string) ( $snapshot['site_host'] ?? '' ) ) );
		if ( ! $allow_foreign_host && $snapshot_host && $current_host && ! hash_equals( $current_host, $snapshot_host ) ) {
			return new WP_Error(
				'host_mismatch',
				sprintf(
					/* translators: 1: snapshot host, 2: current host. */
					__( 'Snapshot host %1$s does not match this site (%2$s).', 'delicat-builder-v9' ),
					$snapshot_host,
					$current_host
				)
			);
		}

		$clean                 = array();
		$legacy_footer_menu_id = 0;

		if ( is_array( $options['delicat_builder_v9_settings'] ?? null ) ) {
			$v = $options['delicat_builder_v9_settings'];
			$clean['delicat_builder_v9_settings'] = array(
				'enabled'              => empty( $v['enabled'] ) ? 0 : 1,
				'app_navigation'       => empty( $v['app_navigation'] ) ? 0 : 1,
				'navigation_scope'     => in_array( $v['navigation_scope'] ?? 'marked', array( 'marked', 'site' ), true ) ? $v['navigation_scope'] : 'marked',
				'content_selector'     => Delicat_Builder_V9_Security::sanitize_selector( (string) ( $v['content_selector'] ?? 'main' ) ),
				'prefetch'             => empty( $v['prefetch'] ) ? 0 : 1,
				'cache_ttl'            => min( 3600, max( 60, absint( $v['cache_ttl'] ?? 600 ) ) ),
				'safe_headers'         => empty( $v['safe_headers'] ) ? 0 : 1,
				'low_power_mode'       => empty( $v['low_power_mode'] ) ? 0 : 1,
				'compiled_assets'      => empty( $v['compiled_assets'] ) ? 0 : 1,
				'hero_preload'         => empty( $v['hero_preload'] ) ? 0 : 1,
				'carousel_progressive' => empty( $v['carousel_progressive'] ) ? 0 : 1,
				'carousel_initial'     => min( 12, max( 3, absint( $v['carousel_initial'] ?? 8 ) ) ),
			);
		}


		if ( is_array( $options['delicat_builder_v9_motion'] ?? null ) ) {
			$clean['delicat_builder_v9_motion'] = Delicat_Builder_V9_Core::sanitize_motion_settings( $options['delicat_builder_v9_motion'] );
		}

		if ( is_array( $options[ Delicat_Builder_V9_Shell::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Shell::OPTION ];
			$scope = in_array( $v['scope'] ?? 'builder_pages', array( 'builder_pages', 'all_frontend' ), true ) ? $v['scope'] : 'builder_pages';
			$theme = in_array( $v['theme_default'] ?? 'light', array( 'light', 'dark', 'system' ), true ) ? $v['theme_default'] : 'light';
			$max = in_array( absint( $v['max_width'] ?? 1320 ), array( 1200, 1320, 1440 ), true ) ? absint( $v['max_width'] ) : 1320;
			$accent = sanitize_hex_color( (string) ( $v['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc';
			$logo_id = absint( $v['logo_id'] ?? 0 );
			$menu_id = absint( $v['menu_id'] ?? 0 );
			$legacy_footer_menu_id = absint( $v['footer_menu_id'] ?? 0 );

			if ( $logo_id && ! Delicat_Builder_V9_Media::is_image_attachment( $logo_id ) ) {
				$logo_id = 0;
			}
			if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
				$menu_id = 0;
			}
			if ( $legacy_footer_menu_id && ! wp_get_nav_menu_object( $legacy_footer_menu_id ) ) {
				$legacy_footer_menu_id = 0;
			}

			$clean[ Delicat_Builder_V9_Shell::OPTION ] = array(
				'enabled'           => empty( $v['enabled'] ) ? 0 : 1,
				'scope'             => $scope,
				'header_enabled'    => empty( $v['header_enabled'] ) ? 0 : 1,
				'logo_id'           => $logo_id,
				'menu_id'           => $menu_id,
				'sticky'            => empty( $v['sticky'] ) ? 0 : 1,
				'show_search'       => empty( $v['show_search'] ) ? 0 : 1,
				'show_cart'         => empty( $v['show_cart'] ) ? 0 : 1,
				'show_account'      => empty( $v['show_account'] ) ? 0 : 1,
				'show_theme_toggle' => empty( $v['show_theme_toggle'] ) ? 0 : 1,
				'mobile_bottom_nav' => empty( $v['mobile_bottom_nav'] ) ? 0 : 1,
				'theme_default'     => $theme,
				'accent_color'      => $accent,
				'max_width'         => $max,
				'announcement_text' => sanitize_text_field( (string) ( $v['announcement_text'] ?? '' ) ),
				'announcement_url'  => esc_url_raw( (string) ( $v['announcement_url'] ?? '' ) ),
				'mobile_menu_label' => sanitize_text_field( (string) ( $v['mobile_menu_label'] ?? __( 'Menu', 'delicat-builder-v9' ) ) ),
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Footer::OPTION ] ?? null ) ) {
			$v        = $options[ Delicat_Builder_V9_Footer::OPTION ];
			$defaults = Delicat_Builder_V9_Footer::defaults();
			$tagline  = sanitize_text_field( (string) ( $v['tagline'] ?? '' ) );
			$menu_id  = absint( $v['menu_id'] ?? 0 );
			if ( '' === $tagline ) {
				$tagline = (string) $defaults['tagline'];
			}
			if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
				$menu_id = 0;
			}

			$clean[ Delicat_Builder_V9_Footer::OPTION ] = array(
				'enabled'       => empty( $v['enabled'] ) ? 0 : 1,
				'tagline'       => $tagline,
				'whatsapp'      => preg_replace( '/[^0-9]/', '', (string) ( $v['whatsapp'] ?? '' ) ),
				'menu_id'       => $menu_id,
				'show_payments' => empty( $v['show_payments'] ) ? 0 : 1,
				'show_trust'    => empty( $v['show_trust'] ) ? 0 : 1,
			);
		} elseif ( $legacy_footer_menu_id > 0 ) {
			/* RC13 snapshots stored the extra footer menu under Shell and did not
			 * export the footer option. Promote that one field to the new owner. */
			$clean[ Delicat_Builder_V9_Footer::OPTION ]            = Delicat_Builder_V9_Footer::defaults();
			$clean[ Delicat_Builder_V9_Footer::OPTION ]['menu_id'] = $legacy_footer_menu_id;
		}

		if ( is_array( $options[ Delicat_Builder_V9_Woo_UI::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Woo_UI::OPTION ];
			$clean[ Delicat_Builder_V9_Woo_UI::OPTION ] = array(
				'enabled'              => empty( $v['enabled'] ) ? 0 : 1,
				'archive_enabled'      => empty( $v['archive_enabled'] ) ? 0 : 1,
				'single_enabled'       => empty( $v['single_enabled'] ) ? 0 : 1,
				'card_style'           => Delicat_Builder_V9_Woo_UI::sanitize_card_style( $v['card_style'] ?? 'premium' ),
				'grid_desktop'         => Delicat_Builder_V9_Woo_UI::grid_value( $v['grid_desktop'] ?? 4, 2, 6 ),
				'grid_tablet'          => Delicat_Builder_V9_Woo_UI::grid_value( $v['grid_tablet'] ?? 3, 2, 4 ),
				'grid_mobile'          => Delicat_Builder_V9_Woo_UI::grid_value( $v['grid_mobile'] ?? 2, 1, 2 ),
				'image_ratio'          => Delicat_Builder_V9_Woo_UI::sanitize_image_ratio( $v['image_ratio'] ?? 'square' ),
				'show_stock_badge'     => empty( $v['show_stock_badge'] ) ? 0 : 1,
				'sticky_summary'       => empty( $v['sticky_summary'] ) ? 0 : 1,
				'single_image_preload' => empty( $v['single_image_preload'] ) ? 0 : 1,
				'max_width'            => Delicat_Builder_V9_Woo_UI::max_width( $v['max_width'] ?? 1320 ),
				'accent_color'         => sanitize_hex_color( (string) ( $v['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc',
				'compact_mobile'       => empty( $v['compact_mobile'] ) ? 0 : 1,
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Purchase_UI::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Purchase_UI::OPTION ];
			$label = sanitize_text_field( (string) ( $v['mobile_dock_label'] ?? __( 'Acheter maintenant', 'delicat-builder-v9' ) ) );
			$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 48 ) : substr( $label, 0, 48 );
			$clean[ Delicat_Builder_V9_Purchase_UI::OPTION ] = array(
				'enabled'              => empty( $v['enabled'] ) ? 0 : 1,
				'quantity_buttons'     => empty( $v['quantity_buttons'] ) ? 0 : 1,
				'cart_auto_update'     => empty( $v['cart_auto_update'] ) ? 0 : 1,
				'mobile_purchase_dock' => empty( $v['mobile_purchase_dock'] ) ? 0 : 1,
				'notice_toasts'        => empty( $v['notice_toasts'] ) ? 0 : 1,
				'cart_style'           => empty( $v['cart_style'] ) ? 0 : 1,
				'checkout_style'       => empty( $v['checkout_style'] ) ? 0 : 1,
				'compact_checkout'     => empty( $v['compact_checkout'] ) ? 0 : 1,
				'accent_color'         => sanitize_hex_color( (string) ( $v['accent_color'] ?? '#6d5dfc' ) ) ?: '#6d5dfc',
				'max_width'            => Delicat_Builder_V9_Purchase_UI::max_width( $v['max_width'] ?? 1200 ),
				'mobile_dock_label'    => $label ?: __( 'Acheter maintenant', 'delicat-builder-v9' ),
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Performance::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Performance::OPTION ];
			$clean[ Delicat_Builder_V9_Performance::OPTION ] = array(
				'enabled'                  => empty( $v['enabled'] ) ? 0 : 1,
				'critical_css'             => empty( $v['critical_css'] ) ? 0 : 1,
				'critical_max_bytes'       => min( 20000, max( 4000, absint( $v['critical_max_bytes'] ?? 12000 ) ) ),
				'high_confidence_preloads' => empty( $v['high_confidence_preloads'] ) ? 0 : 1,
				'predictive_navigation'    => empty( $v['predictive_navigation'] ) ? 0 : 1,
				'intent_delay_ms'          => min( 350, max( 40, absint( $v['intent_delay_ms'] ?? 90 ) ) ),
				'prefetch_budget'          => min( 12, max( 1, absint( $v['prefetch_budget'] ?? 6 ) ) ),
				'prefetch_max_bytes'       => min( 1048576, max( 131072, absint( $v['prefetch_max_bytes'] ?? 524288 ) ) ),
				'network_aware'            => empty( $v['network_aware'] ) ? 0 : 1,
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Design::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Design::OPTION ];
			$clean[ Delicat_Builder_V9_Design::OPTION ] = array(
				'enabled'             => empty( $v['enabled'] ) ? 0 : 1,
				'scope'               => in_array( $v['scope'] ?? '', array( 'site', 'selected_page' ), true ) ? $v['scope'] : 'site',
				'selected_page_id'    => absint( $v['selected_page_id'] ?? 0 ),
				'preset'              => in_array( $v['preset'] ?? '', array( 'modern_store', 'clean_luxe', 'gaming_glow', 'neon_luxe', 'neo_glass', 'lovable_reference' ), true ) ? $v['preset'] : 'modern_store',
				'carousel_style'      => in_array( $v['carousel_style'] ?? '', array( 'default', 'neon_luxe', 'neo_glass' ), true ) ? $v['carousel_style'] : 'default',
				'primary_color'       => sanitize_hex_color( (string) ( $v['primary_color'] ?? '#7c3aed' ) ) ?: '#7c3aed',
				'secondary_color'     => sanitize_hex_color( (string) ( $v['secondary_color'] ?? '#ec4899' ) ) ?: '#ec4899',
				'accent_color'        => sanitize_hex_color( (string) ( $v['accent_color'] ?? '#2563eb' ) ) ?: '#2563eb',
				'surface_color'       => sanitize_hex_color( (string) ( $v['surface_color'] ?? '#ffffff' ) ) ?: '#ffffff',
				'page_tint'           => sanitize_hex_color( (string) ( $v['page_tint'] ?? '#f3f5ff' ) ) ?: '#f3f5ff',
				'homepage_mode'       => in_array( $v['homepage_mode'] ?? '', array( 'light', 'dark' ), true ) ? $v['homepage_mode'] : 'light',
				'homepage_background' => sanitize_hex_color( (string) ( $v['homepage_background'] ?? '#ffffff' ) ) ?: '#ffffff',
				'homepage_heading'    => sanitize_hex_color( (string) ( $v['homepage_heading'] ?? '#111827' ) ) ?: '#111827',
				'homepage_subtitle'   => sanitize_hex_color( (string) ( $v['homepage_subtitle'] ?? '#667085' ) ) ?: '#667085',
				'homepage_view_bg'    => sanitize_hex_color( (string) ( $v['homepage_view_bg'] ?? '#151d48' ) ) ?: '#151d48',
				'homepage_view_text'  => sanitize_hex_color( (string) ( $v['homepage_view_text'] ?? '#ffffff' ) ) ?: '#ffffff',
				'homepage_title_d'    => min( 64, max( 18, absint( $v['homepage_title_d'] ?? 32 ) ) ),
				'homepage_title_t'    => min( 56, max( 18, absint( $v['homepage_title_t'] ?? 30 ) ) ),
				'homepage_title_m'    => min( 48, max( 16, absint( $v['homepage_title_m'] ?? 27 ) ) ),
				'carousel_gap_d'      => min( 40, max( 0, absint( $v['carousel_gap_d'] ?? 18 ) ) ),
				'carousel_gap_t'      => min( 36, max( 0, absint( $v['carousel_gap_t'] ?? 16 ) ) ),
				'carousel_gap_m'      => min( 32, max( 0, absint( $v['carousel_gap_m'] ?? 14 ) ) ),
				'product_name_d'      => min( 40, max( 10, absint( $v['product_name_d'] ?? 18 ) ) ),
				'product_name_t'      => min( 36, max( 10, absint( $v['product_name_t'] ?? 17 ) ) ),
				'product_name_m'      => min( 32, max( 10, absint( $v['product_name_m'] ?? 16 ) ) ),
				'badge_bg'            => sanitize_hex_color( (string) ( $v['badge_bg'] ?? '#e9ddff' ) ) ?: '#e9ddff',
				'badge_text'          => sanitize_hex_color( (string) ( $v['badge_text'] ?? '#6540d9' ) ) ?: '#6540d9',
				'heart_bg'            => sanitize_hex_color( (string) ( $v['heart_bg'] ?? '#ffffff' ) ) ?: '#ffffff',
				'heart_color'         => sanitize_hex_color( (string) ( $v['heart_color'] ?? '#ff4d91' ) ) ?: '#ff4d91',
				'heart_icon'          => in_array( $v['heart_icon'] ?? '', array( 'heart', 'heart_outline', 'star', 'bolt' ), true ) ? $v['heart_icon'] : 'heart',
				'radius'              => min( 32, max( 12, absint( $v['radius'] ?? 22 ) ) ),
				'carousel_cta'        => empty( $v['carousel_cta'] ) ? 0 : 1,
				'carousel_tag'        => empty( $v['carousel_tag'] ) ? 0 : 1,
				'carousel_shell'      => empty( $v['carousel_shell'] ) ? 0 : 1,
				'carousel_bubble'     => empty( $v['carousel_bubble'] ) ? 0 : 1,
				'heart_engine'        => empty( $v['heart_engine'] ) ? 0 : 1,
				'heart_guest'         => empty( $v['heart_guest'] ) ? 0 : 1,
				'carousel_pagination' => empty( $v['carousel_pagination'] ) ? 0 : 1,
				'variation_cards'     => empty( $v['variation_cards'] ) ? 0 : 1,
				'sticky_purchase_bar' => empty( $v['sticky_purchase_bar'] ) ? 0 : 1,
				'compact_mobile'      => empty( $v['compact_mobile'] ) ? 0 : 1,
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Identity_Bridge::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Identity_Bridge::OPTION ];
			$clean[ Delicat_Builder_V9_Identity_Bridge::OPTION ] = array(
				'identity_authority'   => empty( $v['identity_authority'] ) ? 0 : 1,
				'shell_login_modal'    => empty( $v['shell_login_modal'] ) ? 0 : 1,
				'audit_bridge'         => empty( $v['audit_bridge'] ) ? 0 : 1,
				'admin_guard_bridge'   => empty( $v['admin_guard_bridge'] ) ? 0 : 1,
				'reauth_security_save' => empty( $v['reauth_security_save'] ) ? 0 : 1,
			);
		}

		if ( is_array( $options[ Delicat_Builder_V9_Production::OPTION ] ?? null ) ) {
			$v = $options[ Delicat_Builder_V9_Production::OPTION ];
			$profile = in_array( $v['csp_profile'] ?? '', array( 'compatibility', 'strict-monitor' ), true )
				? $v['csp_profile']
				: 'compatibility';
			$clean[ Delicat_Builder_V9_Production::OPTION ] = array(
				'enabled'                  => empty( $v['enabled'] ) ? 0 : 1,
				'private_cache_headers'    => empty( $v['private_cache_headers'] ) ? 0 : 1,
				'csp_report_only'          => empty( $v['csp_report_only'] ) ? 0 : 1,
				'csp_profile'              => $profile,
				'integrity_scan_cache_min' => min( 60, max( 5, absint( $v['integrity_scan_cache_min'] ?? 15 ) ) ),
			);
		}

		if ( empty( $clean ) ) {
			return new WP_Error( 'no_known_options', __( 'No supported Builder options were found in the snapshot.', 'delicat-builder-v9' ) );
		}

		return array(
			'format'         => 'delicat-builder-v9-config',
			'source_version' => sanitize_text_field( (string) ( $snapshot['version'] ?? '' ) ),
			'site_host'      => $snapshot_host,
			'options'        => $clean,
		);
	}

	public static function apply_snapshot( array $snapshot, bool $allow_foreign_host = false ) {
		$clean = self::sanitize_snapshot( $snapshot, $allow_foreign_host );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		update_option(
			self::LAST_RESTORE_BACKUP_OPTION,
			array(
				'created_at' => gmdate( 'c' ),
				'snapshot'   => Delicat_Builder_V9_Production::config_snapshot(),
			),
			false
		);

		foreach ( $clean['options'] as $option => $value ) {
			update_option( $option, $value, false );
		}

		Delicat_Builder_V9_Identity_Bridge::reset_settings_cache();
		Delicat_Builder_V9_Production::reset_settings_cache();
		Delicat_Builder_V9_Cache::bump_version();

		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_config_snapshot_restored',
			'warning',
			array(
				'options'        => count( $clean['options'] ),
				'source_version' => $clean['source_version'],
				'foreign_host'   => $clean['site_host'] && strtolower( $clean['site_host'] ) !== strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) ? 1 : 0,
			)
		);

		return array(
			'options' => count( $clean['options'] ),
			'source_version' => $clean['source_version'],
		);
	}

	public static function backup_snapshot( string $option ) {
		$backup = get_option( $option, array() );
		if ( ! is_array( $backup ) || ! is_array( $backup['snapshot'] ?? null ) ) {
			return new WP_Error( 'backup_missing', __( 'The requested Builder backup is unavailable.', 'delicat-builder-v9' ) );
		}
		return $backup['snapshot'];
	}
	public static function site_health_tests( array $tests ): array {
		$tests['direct']['delicat_builder_release_gate'] = array(
			'label' => __( 'Delicat Builder release gate', 'delicat-builder-v9' ),
			'test'  => array( __CLASS__, 'site_health_release_gate' ),
		);

		$tests['direct']['delicat_builder_identity_authority'] = array(
			'label' => __( 'Delicat Builder Identity authority', 'delicat-builder-v9' ),
			'test'  => array( __CLASS__, 'site_health_identity' ),
		);

		return $tests;
	}

	public static function site_health_release_gate(): array {
		$gate = self::gate_summary();
		$manual = self::manual_gate_summary();
		$self_test = self::self_test( false );

		$ready = ! empty( $gate['ready'] )
			&& ! empty( $manual['complete'] )
			&& ! empty( $self_test['ok'] );

		$label = $ready
			? __( 'Delicat Builder RC release gate is satisfied', 'delicat-builder-v9' )
			: __( 'Delicat Builder RC release gate still needs validation', 'delicat-builder-v9' );

		$status = $ready ? 'good' : ( ! empty( $gate['blockers'] ) ? 'critical' : 'recommended' );

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => 'Delicat Builder',
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: blockers, 2: warnings, 3: manual completed, 4: manual total. */
						__( '%1$d blocker(s), %2$d warning(s), manual staging gate %3$d/%4$d.', 'delicat-builder-v9' ),
						absint( $gate['blockers'] ?? 0 ),
						absint( $gate['warnings'] ?? 0 ),
						absint( $manual['completed'] ?? 0 ),
						absint( $manual['total'] ?? 0 )
					)
				)
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=delicat-builder-v9-release' ) ),
				esc_html__( 'Open Delicat Builder Release Center', 'delicat-builder-v9' )
			),
			'test'        => 'delicat_builder_release_gate',
		);
	}

	public static function site_health_identity(): array {
		$ok = Delicat_Builder_V9_Identity_Bridge::compatible();
		return array(
			'label'       => $ok
				? __( 'Delicat Identity Pro is synchronized with Builder', 'delicat-builder-v9' )
				: __( 'Delicat Identity Pro synchronization requires attention', 'delicat-builder-v9' ),
			'status'      => $ok ? 'good' : 'critical',
			'badge'       => array(
				'label' => 'Delicat Identity',
				'color' => 'blue',
			),
			'description' => sprintf(
				'<p>%s</p>',
				esc_html(
					$ok
						? sprintf(
							/* translators: %s: Identity version. */
							__( 'Identity Pro %s remains the login, 2FA, passkey and privileged-access authority.', 'delicat-builder-v9' ),
							Delicat_Builder_V9_Identity_Bridge::identity_version()
						)
						: __( 'Builder will not replace Identity Pro security. Restore a compatible Identity Pro installation before production release.', 'delicat-builder-v9' )
				)
			),
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=delicat-builder-v9-security' ) ),
				esc_html__( 'Open Security Studio', 'delicat-builder-v9' )
			),
			'test'        => 'delicat_builder_identity_authority',
		);
	}

	public static function manual_gate_definitions(): array {
		return array(
			'identity_login' => __( 'Identity email/password + logged-out Shell modal tested', 'delicat-builder-v9' ),
			'social_security' => __( 'Google login and required TOTP/passkey/admin security tested', 'delicat-builder-v9' ),
			'products' => __( 'Simple, variable, sold-out and custom-field products tested', 'delicat-builder-v9' ),
			'cart_checkout' => __( 'Active Cart and Checkout mode tested end-to-end', 'delicat-builder-v9' ),
			'payments' => __( 'Every active payment gateway completed a staging transaction', 'delicat-builder-v9' ),
			'wallet_supplier' => __( 'Wallet, PIN/top-up and supplier fulfillment flows tested', 'delicat-builder-v9' ),
			'mobile' => __( 'Mobile Safari and low-end Android tested', 'delicat-builder-v9' ),
			'cache' => __( 'LiteSpeed/Hostinger/CDN tested with Cart/Checkout/Account uncached', 'delicat-builder-v9' ),
			'rollback' => __( 'Safe Mode, snapshot restore, undo restore and page revision rollback tested', 'delicat-builder-v9' ),
			'performance' => __( 'Repeated cold/warm performance tests completed without regression', 'delicat-builder-v9' ),
			'design_system' => __( 'Design Studio homepage + product option cards + sticky purchase bar tested in light/dark/mobile', 'delicat-builder-v9' ),
			'carousel_style_visual' => __( 'Two approved carousel styles, dots and CTA behavior tested against the homepage reference', 'delicat-builder-v9' ),
			'homepage_test2' => __( 'Homepage Studio starter tested on the staging Test 2 page without changing the live homepage', 'delicat-builder-v9' ),
		);
	}

	public static function manual_gate(): array {
		$saved = get_option( self::MANUAL_GATE_OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		$clean = array();
		foreach ( self::manual_gate_definitions() as $key => $label ) {
			$clean[ $key ] = empty( $saved[ $key ] ) ? 0 : 1;
		}
		return $clean;
	}

	public static function manual_gate_summary(): array {
		$gate = self::manual_gate();
		$total = count( $gate );
		$completed = array_sum( $gate );
		return array(
			'total'     => $total,
			'completed' => $completed,
			'complete'  => $total > 0 && $completed === $total,
			'gate'      => $gate,
		);
	}

	public static function save_manual_gate( array $input ): array {
		$clean = array();
		foreach ( self::manual_gate_definitions() as $key => $label ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		update_option( self::MANUAL_GATE_OPTION, $clean, false );

		Delicat_Builder_V9_Identity_Bridge::audit(
			'builder_rc2_manual_gate_updated',
			'notice',
			array(
				'completed' => array_sum( $clean ),
				'total'     => count( $clean ),
			)
		);

		return $clean;
	}

	public static function self_test( bool $force = false ): array {
		if ( ! $force ) {
			$cached = get_transient( self::SELF_TEST_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$checks = array();

		$required_classes = array(
			'Delicat_Builder_V9_Core',
			'Delicat_Builder_V9_Query_Cache',
			'Delicat_Builder_V9_Turbo_Diagnostics',
			'Delicat_Builder_V9_Pages',
			'Delicat_Builder_V9_Compiler',
			'Delicat_Builder_V9_Shell',
			'Delicat_Builder_V9_Woo_UI',
			'Delicat_Builder_V9_Purchase_UI',
			'Delicat_Builder_V9_Performance',
			'Delicat_Builder_V9_Server_Engine',
			'Delicat_Builder_V9_Identity_Bridge',
			'Delicat_Builder_V9_Production',
			'Delicat_Builder_V9_Release',
		);

		$missing_classes = array_values(
			array_filter(
				$required_classes,
				static function ( $class ) { return ! class_exists( $class ); }
			)
		);
		$checks['classes'] = array(
			'ok'   => empty( $missing_classes ),
			'text' => empty( $missing_classes ) ? 'Core classes loaded' : 'Missing: ' . implode( ', ', $missing_classes ),
		);

		$required_assets = array(
			'assets/js/core.js',
			'assets/js/islands.js',
			'assets/js/carousel.js',
			'assets/js/prefetch.js',
			'assets/js/shell.js',
			'assets/js/purchase.js',
			'assets/css/woo-ui.css',
			'assets/css/purchase-ui.css',
			'assets/css/blocks/product-collection.css',
			'assets/css/blocks/cart.css',
			'assets/css/blocks/checkout.css',
			'templates/server-page.php',
			'templates/server-v9-shell.php',
			'includes/class-delicat-builder-query-cache.php',
			'includes/class-delicat-builder-turbo-diagnostics.php',
			'integrity-manifest.json',
		);
		$missing_assets = array();
		foreach ( $required_assets as $relative ) {
			if ( ! is_file( DELICAT_BUILDER_V9_DIR . $relative ) || ! is_readable( DELICAT_BUILDER_V9_DIR . $relative ) ) {
				$missing_assets[] = $relative;
			}
		}
		$checks['assets'] = array(
			'ok'   => empty( $missing_assets ),
			'text' => empty( $missing_assets ) ? 'Required assets readable' : 'Missing/unreadable: ' . implode( ', ', $missing_assets ),
		);

		$integrity = Delicat_Builder_V9_Production::integrity_scan( true );
		$checks['integrity'] = array(
			'ok'   => ! empty( $integrity['ok'] ),
			'text' => ! empty( $integrity['ok'] )
				? absint( $integrity['checked'] ?? 0 ) . ' packaged files verified'
				: sprintf(
					'%d modified / %d missing',
					count( $integrity['modified'] ?? array() ),
					count( $integrity['missing'] ?? array() )
				),
		);

		$snapshot = Delicat_Builder_V9_Production::config_snapshot();
		$validated = self::sanitize_snapshot( $snapshot, false );
		$checks['snapshot_roundtrip'] = array(
			'ok'   => ! is_wp_error( $validated ) && ! empty( $validated['options'] ),
			'text' => is_wp_error( $validated )
				? $validated->get_error_message()
				: count( $validated['options'] ) . ' snapshot option groups validate without writes',
		);

		$compiler = Delicat_Builder_V9_Compiler::storage_status();
		$checks['compiler_storage'] = array(
			'ok'   => ! empty( $compiler['ok'] ),
			'text' => sanitize_text_field( (string) ( $compiler['text'] ?? 'Unknown' ) ),
		);

		$checks['identity'] = array(
			'ok'   => Delicat_Builder_V9_Identity_Bridge::compatible(),
			'text' => Delicat_Builder_V9_Identity_Bridge::compatible()
				? 'Identity Pro v' . Delicat_Builder_V9_Identity_Bridge::identity_version() . ' synchronized'
				: 'Compatible Identity Pro not detected',
		);

		$checks['woo_pages'] = array(
			'ok'   => ! in_array( 'unassigned', self::cart_checkout_modes(), true ),
			'text' => implode(
				' · ',
				array_map(
					static function ( $key, $value ) { return ucfirst( $key ) . ': ' . $value; },
					array_keys( self::cart_checkout_modes() ),
					array_values( self::cart_checkout_modes() )
				)
			),
		);

		$checks['duplicates'] = array(
			'ok'   => empty( self::duplicate_builder_plugins() ),
			'text' => empty( self::duplicate_builder_plugins() )
				? 'No duplicate Delicat Builder V9 installation'
				: count( self::duplicate_builder_plugins() ) . ' duplicate installation(s) detected',
		);

		$checks['app_navigation'] = array(
			'ok'   => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] ),
			'text' => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] )
				? 'App Navigation OFF'
				: 'App Navigation ON — not recommended for initial stable release',
		);

		$ok = true;
		foreach ( $checks as $check ) {
			if ( empty( $check['ok'] ) ) {
				$ok = false;
				break;
			}
		}

		$result = array(
			'ok'        => $ok,
			'checks'    => $checks,
			'ran_at'    => gmdate( 'c' ),
		);
		set_transient( self::SELF_TEST_TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function stable_eligibility(): array {
		$auto = self::gate_summary();
		$manual = self::manual_gate_summary();
		$self_test = self::self_test( false );

		$eligible = ! empty( $auto['ready'] )
			&& ! empty( $manual['complete'] )
			&& ! empty( $self_test['ok'] )
			&& ! Delicat_Builder_V9_Core::is_safe_mode()
			&& empty( Delicat_Builder_V9_Core::settings()['app_navigation'] );

		return array(
			'eligible'  => $eligible,
			'auto'      => $auto,
			'manual'    => $manual,
			'self_test' => $self_test,
		);
	}

	public static function release_evidence(): array {
		$eligibility = self::stable_eligibility();
		$integrity = Delicat_Builder_V9_Production::integrity_scan( false );

		return array(
			'format'         => 'delicat-builder-v9-release-evidence',
			'version'        => DELICAT_BUILDER_V9_VERSION,
			'schema_version' => self::schema_version(),
			'generated_at'   => gmdate( 'c' ),
			'site_host'      => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'stable_eligible'=> ! empty( $eligibility['eligible'] ),
			'automated_gate' => array(
				'blockers' => absint( $eligibility['auto']['blockers'] ?? 0 ),
				'warnings' => absint( $eligibility['auto']['warnings'] ?? 0 ),
				'rows'     => $eligibility['auto']['rows'] ?? array(),
			),
			'manual_gate'    => array(
				'completed' => absint( $eligibility['manual']['completed'] ?? 0 ),
				'total'     => absint( $eligibility['manual']['total'] ?? 0 ),
				'gate'      => $eligibility['manual']['gate'] ?? array(),
			),
			'self_test'      => $eligibility['self_test'],
			'integrity'      => array(
				'ok'       => ! empty( $integrity['ok'] ),
				'checked'  => absint( $integrity['checked'] ?? 0 ),
				'modified' => count( $integrity['modified'] ?? array() ),
				'missing'  => count( $integrity['missing'] ?? array() ),
			),
			'identity'       => array(
				'compatible' => Delicat_Builder_V9_Identity_Bridge::compatible(),
				'version'    => Delicat_Builder_V9_Identity_Bridge::identity_version(),
			),
			'app_navigation' => empty( Delicat_Builder_V9_Core::settings()['app_navigation'] ) ? 'off' : 'on',
			'safe_mode'      => Delicat_Builder_V9_Core::is_safe_mode(),
		);
	}

}
