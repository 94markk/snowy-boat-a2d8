<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/* RC18: the admin and runtime variants of this controller share one class
 * name; a second unconditional declaration is an uncatchable compile fatal. */
if ( ! class_exists( 'Delicat_Builder_V9_Unified_Modules', false ) ) :

/**
 * Safe migration/coexistence controller for functionality moved out of the
 * historical Delicat Store Builder.
 */
final class Delicat_Builder_V9_Unified_Modules {
	public const OPTION = 'delicat_builder_v9_unified_modules';
	private static bool $booted = false;
	private static array $runtime = array();

	public static function defaults(): array {
		return array(
			'pwa'            => 1,
			'notifications'  => 1,
			'swatches'       => 1,
			'multi_currency' => 1,
			'product_fields' => 1,
		);
	}

	public static function settings(): array {
		$value = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
	}

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		self::load_modules();
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 37 );
			add_action( 'admin_post_delicat_builder_v9_unified_save', array( __CLASS__, 'save_settings' ) );
		}
	}

	public static function legacy_builder_active(): bool {
		return defined( 'DSB_VERSION' ) || function_exists( 'dsb_available_modules' ) || function_exists( 'dsb_asset' );
	}

	public static function legacy_pwa_active(): bool {
		return function_exists( 'dsb_pwa_manifest_payload' )
			|| function_exists( 'dsb_pwa_manifest_response' )
			|| shortcode_exists( 'delicat_install_app' );
	}

	public static function legacy_notifications_active(): bool {
		return function_exists( 'dsbnh_render' ) || function_exists( 'dsbnh_items' );
	}

	public static function legacy_swatches_active(): bool {
		return class_exists( 'Delicat_Direct_Variation_Swatches', false );
	}

	public static function legacy_multi_currency_active(): bool {
		return function_exists( 'dmc_bootstrap' )
			|| class_exists( 'DMC_Plugin', false )
			|| class_exists( 'DMC_Currencies', false )
			|| defined( 'DMC_FILE' );
	}

	public static function legacy_product_fields_active(): bool {
		/*
		 * RC37.2 FATAL RECOVERY
		 * ---------------------
		 * The historical Builder registers dmc_bootstrap() at plugins_loaded:1,
		 * then declares its calculator later at plugins_loaded:20. RC37.1 checked
		 * only whether DMC_Calculator already existed at plugins_loaded:10, so it
		 * could load a second calculator first and the legacy priority-20 include
		 * then fatally redeclared the class.
		 *
		 * Treat the *pending bootstrap* as ownership too. This keeps V9 in Shadow
		 * before the legacy calculator class is declared and prevents the race.
		 */
		return class_exists( 'DMC_Calculator', false )
			|| function_exists( 'dmc_bootstrap' )
			|| defined( 'DMC_VERSION' )
			|| class_exists( 'DMC', false );
	}

	public static function legacy_notification_hidden_guard(): void {
		if ( is_admin() ) { return; }
		// RC37.5: V9 owns the visible notification UI during migration. Hide the
		// complete historical bell/panel so a stale portal/open-state can never
		// appear above the hero. The old module may still write to the shared
		// history option until it is disabled, but it no longer renders UI.
		echo '<style id="dbv9-legacy-notification-takeover">.dsb541-notifications,.dsb541-panel,.dsb541-panel.dsb541-panel-portal,body>.dsb541-panel.dsb541-panel-portal{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}</style>';
	}

	public static function suppress_legacy_notification_assets(): void {
		if ( is_admin() ) { return; }
		wp_dequeue_style( 'dsb541-notifications' );
		wp_dequeue_script( 'dsb541-notifications' );
	}

	private static function record_failure( string $module, Throwable $error ): void {
		$events = get_option( 'delicat_builder_v9_unified_failures', array() );
		$events = is_array( $events ) ? $events : array();
		$events[] = array(
			'time'   => gmdate( 'c' ),
			'module' => sanitize_key( $module ),
			'type'   => sanitize_text_field( get_class( $error ) ),
			'hash'   => hash( 'sha256', $module . '|' . $error->getMessage() . '|' . $error->getFile() . '|' . $error->getLine() ),
		);
		update_option( 'delicat_builder_v9_unified_failures', array_slice( $events, -10 ), false );
	}

	private static function set_runtime( string $module, string $status, string $detail = '' ): void {
		self::$runtime[ $module ] = array( 'status' => $status, 'detail' => $detail );
	}

	private static function load_modules(): void {
		$settings = self::settings();

		self::load_one(
			'pwa',
			! empty( $settings['pwa'] ),
			self::legacy_pwa_active(),
			static function (): void {
				if ( ! class_exists( 'Delicat_Builder_V9_PWA', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
					delicat_builder_v9_safe_require( 'includes/class-delicat-builder-pwa.php' );
				}
				if ( class_exists( 'Delicat_Builder_V9_PWA', false ) ) { Delicat_Builder_V9_PWA::boot(); }
			}
		);

		if ( ! empty( $settings['notifications'] ) ) {
			$legacy_notifications = self::legacy_notifications_active();
			try {
				if ( function_exists( 'delicat_builder_v9_safe_require' ) ) {
					if ( ! class_exists( 'Delicat_Builder_V9_Web_Push', false ) ) {
						delicat_builder_v9_safe_require( 'includes/class-delicat-builder-web-push.php' );
					}
					if ( ! class_exists( 'Delicat_Builder_V9_Notifications', false ) ) {
						delicat_builder_v9_safe_require( 'includes/class-delicat-builder-notifications.php' );
					}
				}
				if ( class_exists( 'Delicat_Builder_V9_Notifications', false ) ) {
					// During coexistence the historical module keeps its existing event
					// writers, while V9 owns REST + bell/panel UI. This prevents duplicate
					// WooCommerce order notifications and removes the broken legacy panel.
					Delicat_Builder_V9_Notifications::boot( ! $legacy_notifications );
				}
				self::set_runtime( 'notifications', $legacy_notifications ? 'active_bridge' : 'active', $legacy_notifications ? 'V9 owns notification UI; legacy writers may continue temporarily.' : 'Builder V9 owns this system.' );
			} catch ( Throwable $error ) {
				self::record_failure( 'notifications', $error );
				self::set_runtime( 'notifications', 'error', 'V9 kept notifications offline after a boot exception.' );
			}
			// RC37.6: suppress the historical notification UI unconditionally while
			// V9 notifications are enabled. Load-order detection is not trusted for
			// presentation ownership because legacy modules may bootstrap later.
			add_action( 'wp_head', array( __CLASS__, 'legacy_notification_hidden_guard' ), 2 );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'suppress_legacy_notification_assets' ), PHP_INT_MAX );
		} else {
			self::set_runtime( 'notifications', 'disabled', 'Disabled in Builder V9.' );
		}

		self::load_one(
			'swatches',
			! empty( $settings['swatches'] ),
			self::legacy_swatches_active(),
			static function (): void {
				// Swatches only affect variation forms. Keep their PHP/CSS/JS out of
				// homepage/archive/cart requests and attach after WP resolves the query.
				if ( is_admin() ) {
					self::boot_swatches_runtime();
				} else {
					add_action( 'wp', array( __CLASS__, 'boot_swatches_frontend' ), 2 );
				}
			}
		);

		self::load_one(
			'multi_currency',
			! empty( $settings['multi_currency'] ),
			self::legacy_multi_currency_active(),
			static function (): void {
				if ( ! class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
					delicat_builder_v9_safe_require( 'includes/class-delicat-builder-multi-currency.php' );
				}
				if ( class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) ) { Delicat_Builder_V9_Multi_Currency::boot(); }
			}
		);

		self::load_one(
			'product_fields',
			! empty( $settings['product_fields'] ),
			self::legacy_product_fields_active(),
			array( __CLASS__, 'load_product_fields' )
		);
	}

	public static function boot_swatches_frontend(): void {
		if ( function_exists( 'is_product' ) && is_product() ) {
			self::boot_swatches_runtime();
		}
	}

	private static function boot_swatches_runtime(): void {
		if ( ! class_exists( 'Delicat_Builder_V9_Swatches', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-swatches.php' );
		}
		if ( class_exists( 'Delicat_Builder_V9_Swatches', false ) ) {
			Delicat_Builder_V9_Swatches::instance();
		}
	}

	private static function load_one( string $module, bool $enabled, bool $legacy_active, callable $loader ): void {
		if ( ! $enabled ) { self::set_runtime( $module, 'disabled', 'Disabled in Builder V9.' ); return; }
		if ( $legacy_active ) { self::set_runtime( $module, 'shadow', 'Legacy implementation detected; V9 did not attach duplicate hooks.' ); return; }
		try {
			$loader();
			self::set_runtime( $module, 'active', 'Builder V9 owns this system.' );
		} catch ( Throwable $error ) {
			self::record_failure( $module, $error );
			self::set_runtime( $module, 'error', 'V9 kept the module offline after a boot exception.' );
		}
	}

	public static function load_product_fields(): void {
		if ( ! class_exists( 'WooCommerce' ) ) { return; }
		// Second guard closes any load-order race between the initial ownership
		// check and the actual module include.
		if ( self::legacy_product_fields_active() ) {
			self::set_runtime( 'product_fields', 'shadow', 'Legacy/pending DMC calculator owner detected; V9 did not attach duplicate hooks.' );
			return;
		}
		$base = DELICAT_BUILDER_V9_DIR . 'modules/product-fields/';
		$files = array( 'shim.php', 'class-dmc-calc-eval.php', 'class-dmc-calculator.php' );
		foreach ( $files as $file ) {
			$path = $base . $file;
			if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Missing product-fields runtime file.' ); }
			require_once $path;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Product_Fields_Calculator', false ) ) { throw new RuntimeException( 'Calculator class unavailable.' ); }
		$calculator = new Delicat_Builder_V9_Product_Fields_Calculator();
		$calculator->init();

		if ( is_admin() ) {
			$path = $base . 'class-dmc-calc-admin.php';
			if ( ! is_readable( $path ) ) { throw new RuntimeException( 'Missing calculator admin file.' ); }
			require_once $path;
			if ( class_exists( 'Delicat_Builder_V9_Product_Fields_Admin', false ) ) {
				$admin = new Delicat_Builder_V9_Product_Fields_Admin();
				$admin->init();
			}
		}
	}

	public static function module_active( string $module ): bool {
		$module = sanitize_key( $module );
		if ( '' === $module ) { return false; }
		$status = self::runtime_status();
		return in_array( (string) ( $status[ $module ]['status'] ?? '' ), array( 'active', 'active_bridge' ), true );
	}

	public static function runtime_status(): array {
		if ( ! self::$runtime ) {
			$settings = self::settings();
			foreach ( array_keys( self::defaults() ) as $module ) {
				if ( empty( $settings[ $module ] ) ) { self::set_runtime( $module, 'disabled' ); }
			}
		}
		return self::$runtime;
	}

	public static function migration_status(): array {
		$runtime = self::runtime_status();
		return array(
			'legacy_builder' => self::legacy_builder_active(),
			'legacy_enabled' => self::legacy_enabled_modules(),
			'legacy_uncovered' => self::uncovered_legacy_modules(),
			'modules'        => $runtime,
			'legacy_data'    => array(
				'swatch_settings'      => false !== get_option( 'delicat_direct_swatches_settings', false ),
				'notification_history' => false !== get_option( 'dsb_header_notifications', false ),
				'notification_devices' => false !== get_option( 'dsb_notification_devices', false ),
				'pwa_settings'          => false !== get_option( 'delicat_builder_v9_pwa', false ),
				'multi_currency_settings'=> false !== get_option( 'dmc_settings', false ),
				'multi_currency_table'   => false !== get_option( 'dmc_currencies', false ),
				'calculator_products'   => self::has_calculator_products(),
			),
			'identity'       => ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'extension_health' ) ) ) ? Delicat_Builder_V9_Identity_Bridge::extension_health() : array(),
		);
	}

	private static function has_calculator_products(): bool {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! isset( $wpdb->postmeta ) ) { return false; }
		$cache_key = 'dbv9_has_calc_products';
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) { return '1' === $cached; }
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- read-only migration diagnostic, bounded to one row.
		$exists = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", '_dmc_calc' ) );
		set_transient( $cache_key, $exists ? '1' : '0', HOUR_IN_SECONDS );
		return $exists;
	}

	public static function requested_systems_ready(): bool {
		foreach ( self::runtime_status() as $module => $status ) {
			if ( 'error' === ( $status['status'] ?? '' ) || 'disabled' === ( $status['status'] ?? '' ) ) { return false; }
		}
		// Shadow is expected while the old Builder is active. Code presence below
		// guarantees V9 can take ownership on the next request after the matching
		// legacy module is disabled.
		$required = array(
			DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-pwa.php',
			DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-notifications.php',
			DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-swatches.php',
			DELICAT_BUILDER_V9_DIR . 'includes/class-delicat-builder-multi-currency.php',
			DELICAT_BUILDER_V9_DIR . 'modules/product-fields/class-dmc-calculator.php',
		);
		foreach ( $required as $file ) { if ( ! is_readable( $file ) ) { return false; } }
		return true;
	}

	/**
	 * Enabled modules reported by the historical Builder.
	 *
	 * This is diagnostic only: it never changes legacy settings automatically.
	 */
	public static function legacy_enabled_modules(): array {
		if ( ! self::legacy_builder_active() ) { return array(); }
		$enabled = array();
		if ( function_exists( 'dsb_get_enabled_modules' ) ) {
			$value = dsb_get_enabled_modules();
			$enabled = is_array( $value ) ? $value : array();
		} else {
			$value = get_option( 'dsb_modules', array() );
			$enabled = is_array( $value ) ? $value : array();
		}
		$out = array();
		foreach ( $enabled as $slug => $on ) {
			$slug = sanitize_key( (string) $slug );
			if ( $slug && ! empty( $on ) ) { $out[] = $slug; }
		}
		return array_values( array_unique( $out ) );
	}

	private static function legacy_module_label( string $slug ): string {
		if ( function_exists( 'dsb_available_modules' ) ) {
			$all = dsb_available_modules();
			if ( is_array( $all ) && isset( $all[ $slug ]['name'] ) ) {
				return sanitize_text_field( (string) $all[ $slug ]['name'] );
			}
		}
		$labels = array(
			'variation-swatches'  => 'Variation Swatches Pro',
			'install-app'         => 'Install App / PWA',
			'notifications-v541'  => 'Notification Studio',
			'multi-currency'      => 'Multi Currency',
			'wallet-v5'           => 'Wallet Studio',
			'advanced-builder'    => 'Advanced Builder Studio',
			'badge-studio'        => 'Badge Studio',
			'dynamic-products'    => 'Produits dynamiques Pro',
			'sections'            => 'Sections',
			'banner'              => 'Bannières',
			'announcement'        => 'Barre d’annonce',
			'live-sales'          => 'Live Sales Studio',
			'import-export'       => 'Import / Export',
			'product-page'        => 'Page Top-Up Premium',
			'home-premium'        => 'Accueil Premium',
			'elementor-widgets'   => 'Widgets Elementor natifs',
			'product-options'     => 'Recherche / Product Options',
			'promo-widgets'       => 'Widgets Promo / Recharge',
			'themes'              => 'Thèmes prédéfinis',
		);
		return $labels[ $slug ] ?? $slug;
	}

	/** Modules explicitly covered by this migration release. */
	private static function covered_legacy_slugs(): array {
		return array( 'variation-swatches', 'install-app', 'notifications-v541', 'multi-currency' );
	}

	public static function uncovered_legacy_modules(): array {
		$out = array();
		foreach ( self::legacy_enabled_modules() as $slug ) {
			if ( in_array( $slug, self::covered_legacy_slugs(), true ) ) { continue; }
			$out[ $slug ] = self::legacy_module_label( $slug );
		}
		return $out;
	}

	/**
	 * Full-plugin removal is stricter than the requested-system takeover.
	 * Similar functionality in V9 is NOT treated as migrated unless this release
	 * has an explicit data/hook ownership path for the legacy module.
	 */
	public static function safe_to_remove_full_legacy(): bool {
		if ( ! self::legacy_builder_active() ) { return true; }
		return self::requested_systems_ready() && array() === self::uncovered_legacy_modules();
	}

	/** Backward-compatible alias: means only the requested systems are ready. */
	public static function safe_to_disable_legacy(): bool {
		return self::requested_systems_ready();
	}

	public static function admin_menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Integrated Systems', 'delicat-builder-v9' ),
			__( 'Integrated Systems', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-integrated',
			array( __CLASS__, 'admin_page' )
		);
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Permission denied.', 'delicat-builder-v9' ) ); }
		check_admin_referer( 'delicat_builder_v9_unified_save' );
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'require_recent_for_security_change' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::require_recent_for_security_change( 'integrated_systems' );
		}
		$input = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? wp_unslash( $_POST['modules'] ) : array();
		$clean = array();
		foreach ( array_keys( self::defaults() ) as $module ) { $clean[ $module ] = empty( $input[ $module ] ) ? 0 : 1; }
		update_option( self::OPTION, $clean, false );

		if ( class_exists( 'Delicat_Builder_V9_PWA', false ) ) {
			$pwa_input = isset( $_POST['pwa'] ) && is_array( $_POST['pwa'] ) ? wp_unslash( $_POST['pwa'] ) : array();
			$pwa = Delicat_Builder_V9_PWA::settings();
			$pwa['enabled']      = empty( $clean['pwa'] ) ? 0 : 1;
			$pwa['install_ui']   = empty( $pwa_input['install_ui'] ) ? 0 : 1;
			$pwa['static_cache'] = empty( $pwa_input['static_cache'] ) ? 0 : 1;
			$pwa['max_entries']  = min( 120, max( 20, absint( $pwa_input['max_entries'] ?? 64 ) ) );
			$pwa['theme_color']  = sanitize_hex_color( (string) ( $pwa_input['theme_color'] ?? '#11104a' ) ) ?: '#11104a';
			$pwa['background']   = sanitize_hex_color( (string) ( $pwa_input['background'] ?? '#ffffff' ) ) ?: '#ffffff';
			$pwa['short_name']   = substr( sanitize_text_field( (string) ( $pwa_input['short_name'] ?? 'Delicat' ) ), 0, 32 ) ?: 'Delicat';
			update_option( Delicat_Builder_V9_PWA::OPTION, $pwa, false );
		}
		if ( class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) ) {
			$dmc_input = isset( $_POST['dmc'] ) && is_array( $_POST['dmc'] ) ? wp_unslash( $_POST['dmc'] ) : array();
			$existing_dmc_settings = get_option( Delicat_Builder_V9_Multi_Currency::SETTINGS_OPTION, array() );
			$existing_dmc_settings = is_array( $existing_dmc_settings ) ? $existing_dmc_settings : array();
			$dmc_settings = isset( $dmc_input['settings'] ) && is_array( $dmc_input['settings'] )
				? Delicat_Builder_V9_Multi_Currency::sanitize_settings( $dmc_input['settings'], $existing_dmc_settings )
				: wp_parse_args( $existing_dmc_settings, Delicat_Builder_V9_Multi_Currency::default_settings() );
			$base = strtoupper( sanitize_key( (string) get_option( 'woocommerce_currency', 'HTG' ) ) );
			$current_currencies = get_option( Delicat_Builder_V9_Multi_Currency::CURRENCIES_OPTION, array() );
			$currency_input = isset( $dmc_input['currencies'] ) && is_array( $dmc_input['currencies'] ) ? $dmc_input['currencies'] : ( is_array( $current_currencies ) ? $current_currencies : array() );
			$dmc_currencies = Delicat_Builder_V9_Multi_Currency::sanitize_currencies( $currency_input, $base );
			if ( $dmc_settings['default_currency'] && ! isset( $dmc_currencies[ $dmc_settings['default_currency'] ] ) ) { $dmc_settings['default_currency'] = ''; }
			update_option( Delicat_Builder_V9_Multi_Currency::SETTINGS_OPTION, $dmc_settings, false );
			update_option( Delicat_Builder_V9_Multi_Currency::CURRENCIES_OPTION, $dmc_currencies, false );
		}
		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) { Delicat_Builder_V9_Identity_Bridge::audit( 'builder_integrated_systems_saved', 'notice' ); }
		wp_safe_redirect( admin_url( 'admin.php?page=delicat-builder-v9-integrated&saved=1' ) );
		exit;
	}

	public static function admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) { return; }
		$status = self::migration_status(); $settings = self::settings();
		$pwa_settings = class_exists( 'Delicat_Builder_V9_PWA', false ) ? Delicat_Builder_V9_PWA::settings() : array();
		$labels = array( 'pwa' => 'PWA / Install App', 'notifications' => 'Notifications + Android push', 'swatches' => 'Variation Swatches', 'multi_currency' => 'Multi Currency', 'product_fields' => 'Product Fields + Calculator' );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'Delicat Builder V9 — Integrated Systems', 'delicat-builder-v9' ); ?></h1>
		<p><?php esc_html_e( 'Migration-safe ownership of systems formerly provided by the older Builder.', 'delicat-builder-v9' ); ?></p>
		<?php if ( $status['legacy_builder'] ) : ?>
			<div class="notice notice-info"><p><strong>Coexistence mode:</strong> l’ancien Builder est actif. V9 garde les modules correspondants en mode Shadow et ne crée pas de hooks WooCommerce/REST en double.</p></div>
		<?php else : ?>
			<div class="notice notice-success"><p><strong>V9 ownership mode:</strong> l’ancien Builder n’est pas détecté. Les modules activés sont gérés par Builder V9.</p></div>
		<?php endif; ?>
		<?php if ( self::requested_systems_ready() ) : ?><div class="notice notice-success"><p><strong>Systèmes demandés prêts :</strong> PWA, notifications, swatches, Multi Currency et Product Fields/Calculator ont un runtime V9 disponible. En coexistence, désactivez uniquement les modules legacy correspondants pour permettre la prise de contrôle V9.</p></div><?php else : ?><div class="notice notice-warning"><p><strong>Systèmes demandés :</strong> au moins un runtime V9 n’est pas prêt. Ne commencez pas la bascule.</p></div><?php endif; ?>
		<?php $uncovered = self::uncovered_legacy_modules(); ?>
		<?php if ( self::safe_to_remove_full_legacy() ) : ?>
			<div class="notice notice-success"><p><strong>Suppression complète de l’ancien Builder :</strong> aucun module legacy actif non couvert n’a été détecté. Faites néanmoins les tests fonctionnels et désactivez l’ancien plugin avant de le supprimer.</p></div>
		<?php elseif ( $status['legacy_builder'] && $uncovered ) : ?>
			<div class="notice notice-warning"><p><strong>Ne désactivez pas encore l’ancien Builder entier.</strong> Modules actifs non explicitement migrés : <?php echo esc_html( implode( ', ', array_values( $uncovered ) ) ); ?>. Gardez l’ancien Builder actif pour ces fonctions, ou migrez-les séparément.</p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="delicat_builder_v9_unified_save"><?php wp_nonce_field( 'delicat_builder_v9_unified_save' ); ?>
		<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Système</th><th>Builder V9</th><th>État runtime</th><th>Données existantes</th></tr></thead><tbody>
		<?php foreach ( $labels as $module => $label ) : $runtime = $status['modules'][ $module ] ?? array( 'status' => 'unknown', 'detail' => '' ); ?>
		<tr><td><strong><?php echo esc_html( $label ); ?></strong></td><td><label><input type="checkbox" name="modules[<?php echo esc_attr( $module ); ?>]" value="1" <?php checked( ! empty( $settings[ $module ] ) ); ?>> Activé</label></td><td><code><?php echo esc_html( strtoupper( $runtime['status'] ?? 'unknown' ) ); ?></code><br><small><?php echo esc_html( $runtime['detail'] ?? '' ); ?></small></td><td><?php echo esc_html( self::data_status_for( $module, $status['legacy_data'] ) ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php if ( class_exists( 'Delicat_Builder_V9_Multi_Currency', false ) ) :
			$dmc_runtime = Delicat_Builder_V9_Multi_Currency::instance();
			$dmc_settings = $dmc_runtime->settings();
			$dmc_currencies = $dmc_runtime->currencies();
			$dmc_base = $dmc_runtime->base_code();
		?>
		<h2>Multi Currency — V9</h2>
		<p>Les options historiques <code>dmc_settings</code>, <code>dmc_currencies</code> et les prix <code>_dmc_price_*</code> sont conservés. Le checkout et le wallet restent en devise WooCommerce de base par défaut.</p>
		<table class="form-table" role="presentation" style="max-width:1000px"><tbody>
		<tr><th>Conversion</th><td><label><input type="checkbox" name="dmc[settings][enabled]" value="yes" <?php checked( 'yes', $dmc_settings['enabled'] ?? 'yes' ); ?>> Activer l’affichage multi-devise</label></td></tr>
		<tr><th>Settlement</th><td><select name="dmc[settings][settlement]"><option value="base" <?php selected( $dmc_settings['settlement'] ?? 'base', 'base' ); ?>>Base WooCommerce — recommandé</option><option value="selected" <?php selected( $dmc_settings['settlement'] ?? 'base', 'selected' ); ?>>Devise sélectionnée</option></select><p class="description">Gardez Base pour MonCash/Natcash, wallet et intégrations fournisseurs qui attendent HTG.</p></td></tr>
		<tr><th>Mode des taux</th><td><select name="dmc[settings][rate_mode]"><option value="manual" <?php selected( $dmc_settings['rate_mode'] ?? 'manual', 'manual' ); ?>>Manuel</option><option value="auto" <?php selected( $dmc_settings['rate_mode'] ?? 'manual', 'auto' ); ?>>Automatique</option></select> <select name="dmc[settings][update_interval]"><option value="dmc_hourly" <?php selected( $dmc_settings['update_interval'] ?? 'dmc_daily', 'dmc_hourly' ); ?>>Chaque heure</option><option value="dmc_daily" <?php selected( $dmc_settings['update_interval'] ?? 'dmc_daily', 'dmc_daily' ); ?>>Chaque jour</option><option value="dmc_weekly" <?php selected( $dmc_settings['update_interval'] ?? 'dmc_daily', 'dmc_weekly' ); ?>>Chaque semaine</option></select><p class="description">Le mode automatique utilise l’endpoint public ExchangeRate-API et V9 affiche l’attribution requise lorsque des taux automatiques convertis sont visibles.</p></td></tr>
		<tr><th>Protection du taux</th><td><input type="number" min="1" max="100" step="1" name="dmc[settings][rate_guard]" value="<?php echo esc_attr( (string) ( $dmc_settings['rate_guard'] ?? 20 ) ); ?>"> % <span class="description">Rejette une variation automatique anormale supérieure à ce seuil.</span></td></tr>
		<tr><th>Frais sur taux auto</th><td><input type="number" min="-50" max="100" step="0.01" name="dmc[settings][handling_fee]" value="<?php echo esc_attr( (string) ( $dmc_settings['handling_fee'] ?? 0 ) ); ?>"> %</td></tr>
		<tr><th>Devise par défaut</th><td><select name="dmc[settings][default_currency]"><option value="">Base WooCommerce (<?php echo esc_html( $dmc_base ); ?>)</option><?php foreach ( $dmc_currencies as $code => $currency ) : if ( 'yes' !== ( $currency['enabled'] ?? 'no' ) ) { continue; } ?><option value="<?php echo esc_attr( $code ); ?>" <?php selected( $dmc_settings['default_currency'] ?? '', $code ); ?>><?php echo esc_html( $code . ' — ' . ( $currency['symbol'] ?? $code ) ); ?></option><?php endforeach; ?></select></td></tr>
		<tr><th>Comportement</th><td>
		<?php foreach ( array( 'wallet_base','geolocate','approx','show_flags','sticky','lock_currency','charge_in_currency' ) as $present_key ) : ?><input type="hidden" name="dmc[settings][_present][<?php echo esc_attr( $present_key ); ?>]" value="1"><?php endforeach; ?>
		<label><input type="checkbox" name="dmc[settings][wallet_base]" value="yes" <?php checked( 'yes', $dmc_settings['wallet_base'] ?? 'yes' ); ?>> Wallet toujours en base</label><br>
		<label><input type="checkbox" name="dmc[settings][geolocate]" value="yes" <?php checked( 'yes', $dmc_settings['geolocate'] ?? 'no' ); ?>> Géolocalisation de devise</label><br>
		<label><input type="checkbox" name="dmc[settings][approx]" value="yes" <?php checked( 'yes', $dmc_settings['approx'] ?? 'no' ); ?>> Afficher ≈ pour conversion</label><br>
		<label><input type="checkbox" name="dmc[settings][show_flags]" value="yes" <?php checked( 'yes', $dmc_settings['show_flags'] ?? 'yes' ); ?>> Drapeaux</label><br>
		<label><input type="checkbox" name="dmc[settings][sticky]" value="yes" <?php checked( 'yes', $dmc_settings['sticky'] ?? 'no' ); ?>> Switcher flottant</label><br>
		<label><input type="checkbox" name="dmc[settings][lock_currency]" value="yes" <?php checked( 'yes', $dmc_settings['lock_currency'] ?? 'no' ); ?>> Verrouiller la boutique sur la devise par défaut</label><br>
		<label><input type="checkbox" name="dmc[settings][charge_in_currency]" value="yes" <?php checked( 'yes', $dmc_settings['charge_in_currency'] ?? 'yes' ); ?>> Conserver la préférence historique « charge in currency »</label>
		<p class="description">Le mode Settlement ci-dessus reste l’autorité de paiement. Base WooCommerce est recommandé pour HTG, MonCash/Natcash, wallet et fournisseurs.</p></td></tr>
		<tr><th>Switcher</th><td><select name="dmc[settings][switcher_style]"><option value="dropdown" <?php selected( $dmc_settings['switcher_style'] ?? 'dropdown', 'dropdown' ); ?>>Dropdown</option><option value="buttons" <?php selected( $dmc_settings['switcher_style'] ?? 'dropdown', 'buttons' ); ?>>Boutons</option><option value="flags" <?php selected( $dmc_settings['switcher_style'] ?? 'dropdown', 'flags' ); ?>>Flags</option></select> <label style="margin-left:12px">Menu <select name="dmc[settings][menu_location]"><option value="">Aucun</option><?php foreach ( get_registered_nav_menus() as $loc => $label ) : ?><option value="<?php echo esc_attr( $loc ); ?>" <?php selected( $dmc_settings['menu_location'] ?? '', $loc ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label> <code>[dmc_switcher]</code></td></tr>
		</tbody></table>
		<h3>Devises et taux</h3>
		<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Devise</th><th>Active</th><th>Symbole</th><th>Taux (1 <?php echo esc_html( $dmc_base ); ?> =)</th><th>Décimales</th><th>Arrondi</th><th>Charm</th></tr></thead><tbody>
		<?php foreach ( $dmc_currencies as $code => $currency ) : ?>
		<tr><td><strong><?php echo esc_html( $code ); ?></strong><?php if ( $code === $dmc_base ) : ?> <small>BASE</small><?php endif; ?></td><td><input type="checkbox" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][enabled]" value="yes" <?php checked( 'yes', $currency['enabled'] ?? 'no' ); ?> <?php disabled( $code, $dmc_base ); ?>></td><td><input type="text" size="7" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][symbol]" value="<?php echo esc_attr( (string) ( $currency['symbol'] ?? $code ) ); ?>"></td><td><input type="number" step="0.00000001" min="0.00000001" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][rate]" value="<?php echo esc_attr( (string) ( $currency['rate'] ?? 1 ) ); ?>" <?php disabled( $code, $dmc_base ); ?>></td><td><input type="number" min="0" max="6" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][decimals]" value="<?php echo esc_attr( (string) ( $currency['decimals'] ?? 2 ) ); ?>"></td><td><input type="number" step="0.01" min="0" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][rounding]" value="<?php echo esc_attr( (string) ( $currency['rounding'] ?? 0 ) ); ?>"></td><td><input type="number" step="0.01" min="0" max="0.99" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][charm]" value="<?php echo esc_attr( (string) ( $currency['charm'] ?? '' ) ); ?>"></td><input type="hidden" name="dmc[currencies][<?php echo esc_attr( $code ); ?>][position]" value="<?php echo esc_attr( (string) ( $currency['position'] ?? 'left' ) ); ?>"></tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php endif; ?>
		<?php if ( class_exists( 'Delicat_Builder_V9_PWA', false ) ) : ?>
		<h2>PWA optimisée</h2>
		<p>Le service worker V9 ne met jamais en cache les pages HTML, panier, checkout, compte, wallet, REST ou admin. Seuls les fichiers statiques same-origin peuvent être mis en cache.</p>
		<table class="form-table" role="presentation" style="max-width:900px"><tbody>
		<tr><th>Nom court</th><td><input type="text" maxlength="32" name="pwa[short_name]" value="<?php echo esc_attr( (string) ( $pwa_settings['short_name'] ?? 'Delicat' ) ); ?>"></td></tr>
		<tr><th>Guide d’installation</th><td><label><input type="checkbox" name="pwa[install_ui]" value="1" <?php checked( ! empty( $pwa_settings['install_ui'] ) ); ?>> Activer l’interface à la demande</label></td></tr>
		<tr><th>Cache statique</th><td><label><input type="checkbox" name="pwa[static_cache]" value="1" <?php checked( ! empty( $pwa_settings['static_cache'] ) ); ?>> CSS/JS/images/fonts uniquement</label></td></tr>
		<tr><th>Limite du cache</th><td><input type="number" min="20" max="120" name="pwa[max_entries]" value="<?php echo esc_attr( (string) absint( $pwa_settings['max_entries'] ?? 64 ) ); ?>"> fichiers</td></tr>
		<tr><th>Couleur du thème</th><td><input type="color" name="pwa[theme_color]" value="<?php echo esc_attr( sanitize_hex_color( (string) ( $pwa_settings['theme_color'] ?? '#11104a' ) ) ?: '#11104a' ); ?>"></td></tr>
		<tr><th>Fond de démarrage</th><td><input type="color" name="pwa[background]" value="<?php echo esc_attr( sanitize_hex_color( (string) ( $pwa_settings['background'] ?? '#ffffff' ) ) ?: '#ffffff' ); ?>"></td></tr>
		</tbody></table>
		<?php endif; ?>
		<p><button class="button button-primary">Enregistrer</button></p></form>
		<h2>Bascule granulaire recommandée</h2>
		<ol>
			<li>Dans l’ancien Builder, désactivez <strong>Install App / PWA</strong> seulement, rechargez, puis vérifiez que V9 affiche PWA = ACTIVE.</li>
			<li>Désactivez <strong>Notification Studio</strong> seulement, rechargez, puis vérifiez la cloche et Android push sous V9.</li>
			<li>Désactivez <strong>Variation Swatches Pro</strong> seulement, rechargez, puis testez variations, stock, prix et panier.</li>
			<li><strong>Multi Currency + Product Fields/Calculator :</strong> après désactivation de l’ancien module Multi Currency, V9 doit afficher Multi Currency = ACTIVE puis Product Fields/Calculator = ACTIVE. Les mêmes options <code>dmc_*</code> et métadonnées <code>_dmc_*</code> sont réutilisées.</li>
		</ol>
		<h2>Avant de désactiver l’ancien Builder entier</h2><ol><li>Tester une variation Swatch, prix, stock et ajout panier.</li><li>Tester un produit avec Product Fields/Calculator jusqu’à la commande WooCommerce.</li><li>Tester la cloche, une commande Processing/Completed et l’application Android.</li><li>Tester l’installation PWA sur Android puis le guide iOS.</li><li>Tester Login, Google, Passkey/2FA et pages Mon compte avec Identity Pro.</li><li>Vérifier panier, checkout, wallet et PIN.</li><li>Confirmer que la liste « modules actifs non explicitement migrés » est vide.</li></ol>
		<p><strong>Important :</strong> ne supprimez l’ancien plugin qu’après avoir d’abord simplement <em>désactivé</em> celui-ci et validé V9. Gardez son ZIP pour rollback.</p>
		</div>
		<?php
	}

	private static function data_status_for( string $module, array $data ): string {
		switch ( $module ) {
			case 'swatches': return ! empty( $data['swatch_settings'] ) ? 'Réglages existants détectés' : 'Aucun réglage historique détecté';
			case 'notifications': return ( ! empty( $data['notification_history'] ) || ! empty( $data['notification_devices'] ) ) ? 'Historique/appareils existants conservés' : 'Nouveau stockage';
			case 'multi_currency': return ( ! empty( $data['multi_currency_settings'] ) || ! empty( $data['multi_currency_table'] ) ) ? 'Réglages/taux dmc_* existants conservés' : 'Nouveau stockage Multi Currency';
			case 'product_fields': return ! empty( $data['calculator_products'] ) ? 'Produits _dmc_calc détectés' : 'Aucun produit calculateur détecté';
			case 'pwa': return ! empty( $data['pwa_settings'] ) ? 'Réglages V9 présents' : 'Réglages V9 par défaut';
			default: return '—';
		}
	}
}

endif; /* RC18 single-declaration guard */
