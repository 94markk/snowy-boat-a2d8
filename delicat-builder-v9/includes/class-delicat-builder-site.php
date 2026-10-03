<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full-site coverage layer — RC40.
 *
 * Closes the remaining storefront surfaces so the Builder can style the entire
 * site: Mon compte (WooCommerce account endpoints), the order-received "Merci"
 * page, the 404 page and the search-results page.
 *
 * Same authority contract as every other V9 module: WooCommerce remains
 * imperative for sessions, endpoints, orders and account logic. This module
 * adds presentation hooks and CSS only — no new mutation endpoint, no query
 * on non-covered pages, and every optional sibling (Carousel) is guarded so
 * the module stays individually quarantine-able.
 */
final class Delicat_Builder_V9_Site {
	public const OPTION = 'delicat_builder_v9_site';

	private static ?array $settings_cache = null;
	private static bool $account_active  = false;
	private static bool $thankyou_active = false;
	private static bool $search_active   = false;
	private static bool $fourofour_active = false;

	public static function boot(): void {
		add_action( 'wp', array( __CLASS__, 'detect_context' ), 26 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 42 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 42 );

		// Official WooCommerce presentation hooks only.
		add_action( 'woocommerce_before_account_navigation', array( __CLASS__, 'account_hero' ), 5 );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'identity_login_card' ), 1 );
		add_action( 'woocommerce_before_thankyou', array( __CLASS__, 'thankyou_hero' ), 5 );

		add_filter( 'template_include', array( __CLASS__, 'maybe_404_template' ), 60 );
	}

	public static function defaults(): array {
		return array(
			'enabled'            => 1,
			'account_style'      => 1,
			'account_hero'       => 1,
			'thankyou_style'     => 1,
			'thankyou_message'   => __( 'Commande confirmée — suivez son état ci-dessous. Le délai de livraison dépend du produit.', 'delicat-builder-v9' ),
			'fourofour_enabled'  => 1,
			'fourofour_title'    => __( 'Page introuvable', 'delicat-builder-v9' ),
			'fourofour_text'     => __( 'Le lien a peut-être changé. Recherchez votre produit ou découvrez nos meilleures offres.', 'delicat-builder-v9' ),
			'fourofour_limit'    => 8,
			'fourofour_category' => '',
			'search_style'       => 1,
			'accent_color'       => '',
		);
	}

	public static function settings(): array {
		if ( null !== self::$settings_cache ) {
			return self::$settings_cache;
		}

		$saved = get_option( self::OPTION, array() );
		self::$settings_cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		return self::$settings_cache;
	}

	public static function reset_settings_cache(): void {
		self::$settings_cache = null;
	}

	public static function enabled(): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Core', false )
			|| ! Delicat_Builder_V9_Core::is_enabled()
			|| Delicat_Builder_V9_Core::is_safe_mode()
		) {
			return false;
		}
		return ! empty( self::settings()['enabled'] );
	}

	public static function detect_context(): void {
		if ( ! self::enabled() || is_admin() ) {
			return;
		}

		$s = self::settings();

		self::$account_active = ! empty( $s['account_style'] )
			&& function_exists( 'is_account_page' ) && is_account_page();

		$is_received = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );
		self::$thankyou_active = ! empty( $s['thankyou_style'] ) && $is_received;

		self::$search_active = ! empty( $s['search_style'] ) && is_search();

		self::$fourofour_active = ! empty( $s['fourofour_enabled'] ) && is_404();
	}

	private static function any_surface_active(): bool {
		return self::$account_active || self::$thankyou_active || self::$search_active || self::$fourofour_active;
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( self::$account_active ) {
			$classes[] = 'delicat-site-account';
			if ( ! is_user_logged_in() && class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && Delicat_Builder_V9_Identity_Bridge::frontend_login_available() ) {
				$classes[] = 'delicat-site-identity-login';
			}
		}
		if ( self::$thankyou_active ) {
			$classes[] = 'delicat-site-thankyou';
		}
		if ( self::$search_active ) {
			$classes[] = 'delicat-site-search';
		}
		if ( self::$fourofour_active ) {
			$classes[] = 'delicat-site-404';
		}
		return $classes;
	}

	public static function enqueue_assets(): void {
		if ( ! self::any_surface_active() ) {
			return;
		}

		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/site.css';
		if ( ! is_file( $css ) ) {
			return;
		}

		/* RC51.59: single owner. On native documents the base sheet must win
		 * first, so declare the dependency whenever that handle exists. */
		$deps = wp_style_is( 'delicat-builder-v9-native-document', 'registered' ) || wp_style_is( 'delicat-builder-v9-native-document', 'enqueued' )
			? array( 'delicat-builder-v9-native-document' )
			: array();

		wp_enqueue_style(
			'delicat-builder-v9-site',
			DELICAT_BUILDER_V9_URL . 'assets/css/site.css',
			$deps,
			DELICAT_BUILDER_V9_VERSION
		);

		$accent = sanitize_hex_color( (string) ( self::settings()['accent_color'] ?? '' ) );
		if ( $accent ) {
			wp_add_inline_style(
				'delicat-builder-v9-site',
				'.delicat-site{--delicat-site-accent:' . $accent . '}'
			);
		}
	}


	/** One login surface on guest account pages when Identity Pro owns auth. */
	public static function identity_login_card(): void {
		if ( ! self::$account_active || is_user_logged_in() || ! class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) || ! Delicat_Builder_V9_Identity_Bridge::frontend_login_available() ) {
			return;
		}
		?>
		<section class="delicat-site delicat-site-account__identity-card" aria-labelledby="delicat-account-login-title">
			<h1 id="delicat-account-login-title"><?php esc_html_e( 'Connexion', 'delicat-builder-v9' ); ?></h1>
			<p><?php esc_html_e( 'Connectez-vous avec Delicat Identity pour accéder à vos commandes, votre portefeuille et votre compte.', 'delicat-builder-v9' ); ?></p>
			<button type="button" class="delicat-site-account__identity-button" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal"><?php esc_html_e( 'Se connecter en toute sécurité', 'delicat-builder-v9' ); ?></button>
		</section>
		<?php
	}

	/**
	 * Mon compte greeting hero. Zero extra queries — only the current user
	 * object WordPress has already loaded.
	 */
	public static function account_hero(): void {
		if ( ! self::$account_active || empty( self::settings()['account_hero'] ) || ! is_user_logged_in() ) {
			return;
		}

		$user = wp_get_current_user();
		$name = $user->first_name ?: $user->display_name;
		$initial = function_exists( 'mb_substr' ) ? mb_substr( (string) $name, 0, 1, 'UTF-8' ) : substr( (string) $name, 0, 1 );
		$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial, 'UTF-8' ) : strtoupper( $initial );
		?>
		<div class="delicat-site delicat-site-account__hero">
			<div class="delicat-site-account__avatar" aria-hidden="true"><?php echo esc_html( $initial ); ?></div>
			<div class="delicat-site-account__identity">
				<p class="delicat-site-account__greeting"><?php echo esc_html( sprintf( /* translators: %s: first name */ __( 'Bonjour, %s', 'delicat-builder-v9' ), $name ) ); ?></p>
				<p class="delicat-site-account__email"><?php echo esc_html( (string) $user->user_email ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Order-received confirmation hero. Uses the order WooCommerce already
	 * resolved for the thankyou template; never mutates it.
	 */
	public static function thankyou_hero( $order_id ): void {
		if ( ! self::$thankyou_active || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}

		$message = sanitize_textarea_field( (string) ( self::settings()['thankyou_message'] ?? '' ) );
		$name    = $order->get_billing_first_name();
		?>
		<div class="delicat-site delicat-site-thankyou__hero">
			<div class="delicat-site-thankyou__badge" aria-hidden="true">✓</div>
			<h2 class="delicat-site-thankyou__title">
				<?php
				echo esc_html(
					$name
						? sprintf( /* translators: %s: first name */ __( 'Merci, %s !', 'delicat-builder-v9' ), $name )
						: __( 'Merci pour votre commande !', 'delicat-builder-v9' )
				);
				?>
			</h2>
			<?php if ( '' !== $message ) : ?>
				<p class="delicat-site-thankyou__message"><?php echo esc_html( $message ); ?></p>
			<?php endif; ?>
			<p class="delicat-site-thankyou__meta">
				<span><?php echo esc_html( sprintf( /* translators: %s: order number */ __( 'Commande #%s', 'delicat-builder-v9' ), $order->get_order_number() ) ); ?></span>
				<span class="delicat-site-thankyou__status delicat-site-thankyou__status--<?php echo esc_attr( sanitize_html_class( $order->get_status() ) ); ?>">
					<?php echo esc_html( function_exists( 'wc_get_order_status_name' ) ? wc_get_order_status_name( $order->get_status() ) : $order->get_status() ); ?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Smart 404 takeover. Only replaces the template when the surface is
	 * active and the plugin template file exists; otherwise the theme's own
	 * 404 template is returned untouched.
	 */
	public static function maybe_404_template( $template ) {
		if ( ! self::$fourofour_active ) {
			return $template;
		}

		$plugin_template = DELICAT_BUILDER_V9_DIR . 'templates/site-404.php';
		if ( ! is_file( $plugin_template ) ) {
			return $template;
		}

		return $plugin_template;
	}

	/**
	 * Data for the 404 template — kept here so the template stays markup-only.
	 */
	public static function fourofour_context(): array {
		$s = self::settings();

		$shop_url = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : '';

		$products_html = '';
		if ( class_exists( 'Delicat_Builder_V9_Carousel', false ) && is_callable( array( 'Delicat_Builder_V9_Carousel', 'shortcode' ) ) ) {
			$products_html = Delicat_Builder_V9_Carousel::shortcode(
				array(
					'title'    => __( 'Nos meilleures offres', 'delicat-builder-v9' ),
					'limit'    => min( 12, max( 4, absint( $s['fourofour_limit'] ?? 8 ) ) ),
					'orderby'  => 'popularity',
					'category' => sanitize_title( (string) ( $s['fourofour_category'] ?? '' ) ),
				)
			);
		}

		return array(
			'title'         => sanitize_text_field( (string) ( $s['fourofour_title'] ?? '' ) ),
			'text'          => sanitize_textarea_field( (string) ( $s['fourofour_text'] ?? '' ) ),
			'shop_url'      => $shop_url,
			'home_url'      => home_url( '/' ),
			'products_html' => $products_html,
		);
	}
}
