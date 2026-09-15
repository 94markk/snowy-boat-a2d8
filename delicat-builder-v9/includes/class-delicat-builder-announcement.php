<?php
/**
 * Module: Delicat Announcement Studio
 *
 * A cache-safe launch announcement popup. The dialog markup is identical for
 * every visitor on a given page, so LiteSpeed / edge caches keep working; the
 * decision to show it is taken client-side from a cookie, never from PHP.
 *
 * Constraints honoured here:
 *  - PHP 7.4 (no match, no nullsafe, no str_contains)
 *  - no backdrop-filter, no localStorage / sessionStorage
 *  - vanilla ES5 runtime
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Delicat_Builder_V9_Announcement', false ) ) :

final class Delicat_Builder_V9_Announcement {

	public const OPTION = 'delicat_builder_v9_announcement';

	/** @var bool */
	private static $booted = false;

	/** @var array|null */
	private static $cache = null;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 34 );
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
	}

	/* ---------------------------------------------------------------- data */

	public static function defaults(): array {
		return array(
			'enabled'          => 0,
			'style'            => 'aurora',
			'title'            => 'Bienvenue sur Délicat Store',
			'message'          => 'Découvrez nos recharges de jeux, cartes-cadeaux et abonnements — livraison rapide selon le produit.',
			'emoji'            => '🎉', // Rendered as deterministic SVG on the storefront.
			'image'            => '',
			'primary_label'    => 'Voir l’offre',
			'primary_url'      => '',
			'primary_new_tab'  => 0,
			'secondary_label'  => 'Plus tard',
			'show_secondary'   => 1,
			'accent'           => '#ff5a1f',
			'delay'            => 1200,
			'frequency'        => 'days',
			'days'             => 7,
			'scope'            => 'all',
			'audience'         => 'all',
			'skip_checkout'    => 1,
			'start'            => '',
			'end'              => '',
		);
	}

	public static function settings(): array {
		if ( null === self::$cache ) {
			$value       = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args( is_array( $value ) ? $value : array(), self::defaults() );
		}
		return self::$cache;
	}

	public static function reset_cache(): void {
		self::$cache = null;
	}

	/**
	 * Content signature. Editing the announcement changes this hash, so every
	 * visitor who dismissed the previous campaign is shown the new one.
	 */
	private static function signature( array $s ): string {
		$parts = array(
			(string) $s['style'],
			(string) $s['accent'],
			(string) $s['title'],
			(string) $s['message'],
			(string) $s['emoji'],
			(string) $s['image'],
			(string) $s['primary_label'],
			(string) $s['primary_url'],
			(string) $s['secondary_label'],
			(string) $s['frequency'],
			(string) $s['days'],
		);
		return substr( md5( implode( '|', $parts ) ), 0, 12 );
	}


	private static function style_keys(): array {
		return array( 'flash', 'aurora', 'midnight', 'minimal', 'boutique', 'spotlight' );
	}

	private static function normalize_style( string $style ): string {
		$style = sanitize_key( $style );
		return in_array( $style, self::style_keys(), true ) ? $style : 'aurora';
	}

	/* ------------------------------------------------------------- runtime */

	/**
	 * Server-side eligibility. Only whole-campaign facts are decided here
	 * (enabled, schedule window, page scope) — never per-visitor facts, so the
	 * rendered HTML stays identical for all guests on the same URL.
	 */
	private static function should_render(): bool {
		$s = self::settings();

		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( '' === trim( (string) $s['title'] ) && '' === trim( (string) $s['message'] ) ) {
			return false;
		}
		if ( is_admin() || is_feed() || is_embed() || is_404() ) {
			return false;
		}
		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		/*
		 * Compare dates as strings, not timestamps. `current_time('timestamp')`
		 * returns site-local time while `strtotime()` resolves against PHP's
		 * default timezone (UTC on most WordPress hosts), so mixing them shifts
		 * the whole window by the site's UTC offset — five hours here. Two ISO
		 * dates compare correctly lexicographically and both sides are the
		 * site's own local date.
		 */
		$today = current_time( 'Y-m-d' );
		if ( '' !== (string) $s['start'] && $today < (string) $s['start'] ) {
			return false;
		}
		if ( '' !== (string) $s['end'] && $today > (string) $s['end'] ) {
			return false;
		}

		if ( ! empty( $s['skip_checkout'] ) && self::is_purchase_flow() ) {
			return false;
		}

		$scope = (string) $s['scope'];
		if ( 'home' === $scope ) {
			return ( is_front_page() || is_home() );
		}
		if ( 'shop' === $scope ) {
			if ( is_front_page() || is_home() ) {
				return true;
			}
			if ( function_exists( 'is_shop' ) && ( is_shop() || is_product_category() || is_product_tag() || is_product() ) ) {
				return true;
			}
			return false;
		}

		return true;
	}

	private static function is_purchase_flow(): bool {
		if ( ! function_exists( 'is_cart' ) ) {
			return false;
		}
		return ( is_cart() || is_checkout() || is_account_page() );
	}

	public static function enqueue(): void {
		if ( ! self::should_render() ) {
			return;
		}

		/*
		 * The announcement opens after a configurable delay and stays [hidden]
		 * until its runtime starts. For the normal delayed case, load its CSS as
		 * print media first so it cannot block the storefront's first paint. The
		 * deferred announcement runtime promotes it to screen media as soon as the
		 * DOM is interactive. Near-instant announcements keep normal blocking CSS
		 * to avoid any chance of an unstyled first frame.
		 */
		$s     = self::settings();
		$media = absint( $s['delay'] ) >= 700 ? 'print' : 'all';

		wp_enqueue_style(
			'delicat-builder-v9-announcement',
			DELICAT_BUILDER_V9_URL . 'assets/css/announcement.css',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			$media
		);
		wp_enqueue_script(
			'delicat-builder-v9-announcement',
			DELICAT_BUILDER_V9_URL . 'assets/js/announcement.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			true
		);
	}

	private static function decorative_icon( string $value ): string {
		$key = str_replace( array( "\xEF\xB8\x8E", "\xEF\xB8\x8F" ), '', trim( $value ) );
		$paths = array(
			'🎉' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><path d="m7.8 7.8 2 2M14.2 14.2l2 2M7.8 16.2l2-2M14.2 9.8l2-2"/><circle cx="12" cy="12" r="2.2"/>',
			'✨' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4"/><path d="m7.8 7.8 2 2M14.2 14.2l2 2M7.8 16.2l2-2M14.2 9.8l2-2"/><circle cx="12" cy="12" r="2.2"/>',
			'🎮' => '<path d="M6.5 7h11a4.5 4.5 0 0 1 4.4 5.4l-.9 4.4a2.6 2.6 0 0 1-4.7 1L15 16H9l-1.3 1.8a2.6 2.6 0 0 1-4.7-1l-.9-4.4A4.5 4.5 0 0 1 6.5 7Z"/><path d="M8 10v3M6.5 11.5h3M15.5 11h.01M17.5 12.5h.01"/>',
			'🎁' => '<path d="M3 9h18v3H3zM5 12v9h14v-9"/><path d="M12 9v12"/><path d="M12 9c-1.5-3.5-5-4.5-5-2 0 2 5 2 5 2Zm0 0c1.5-3.5 5-4.5 5-2 0 2-5 2-5 2Z"/>',
			'💎' => '<path d="M7 4h10l4 5.5L12 20 3 9.5z"/><path d="M3 9.5h18M9 4l3 5.5L15 4M9.5 9.5 12 20l2.5-10.5"/>',
			'⚡' => '<path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/>',
			'🛡' => '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5zm-3 9 2 2 4-4 2 2-6 6-4-4z"/>',
		);
		if ( ! isset( $paths[ $key ] ) ) {
			return '';
		}
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" style="display:block;width:1em;height:1em;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round">' . $paths[ $key ] . '</svg>';
	}

	public static function render(): void {
		if ( ! self::should_render() ) {
			return;
		}

		$s     = self::settings();
		$style = self::normalize_style( (string) $s['style'] );
		$sig   = self::signature( $s );

		$accent = sanitize_hex_color( (string) $s['accent'] );
		if ( ! $accent ) {
			$accent = '#ff5a1f';
		}

		$frequency = (string) $s['frequency'];
		if ( ! in_array( $frequency, array( 'always', 'session', 'days' ), true ) ) {
			$frequency = 'days';
		}

		$audience = (string) $s['audience'];
		if ( ! in_array( $audience, array( 'all', 'guests', 'members' ), true ) ) {
			$audience = 'all';
		}

		$primary_url   = (string) $s['primary_url'];
		$primary_label = trim( (string) $s['primary_label'] );
		$has_primary   = ( '' !== $primary_url && '' !== $primary_label );

		$secondary_label = trim( (string) $s['secondary_label'] );
		$has_secondary   = ( ! empty( $s['show_secondary'] ) && '' !== $secondary_label );

		$title_id = 'dbv9-annc-title';
		$desc_id  = 'dbv9-annc-text';

		?>
		<div
			class="dbv9-annc dbv9-annc--<?php echo esc_attr( $style ); ?>"
			id="dbv9-annc"
			data-dbv9-announcement
			data-sig="<?php echo esc_attr( $sig ); ?>"
			data-frequency="<?php echo esc_attr( $frequency ); ?>"
			data-days="<?php echo esc_attr( (string) absint( $s['days'] ) ); ?>"
			data-delay="<?php echo esc_attr( (string) absint( $s['delay'] ) ); ?>"
			data-audience="<?php echo esc_attr( $audience ); ?>"
			data-style="<?php echo esc_attr( $style ); ?>"
			style="--dbv9-annc-accent:<?php echo esc_attr( $accent ); ?>"
			hidden
		>
			<div class="dbv9-annc__overlay" data-dbv9-annc-dismiss="overlay"></div>

			<div
				class="dbv9-annc__dialog"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $title_id ); ?>"
				aria-describedby="<?php echo esc_attr( $desc_id ); ?>"
			>
				<button
					type="button"
					class="dbv9-annc__close"
					data-dbv9-annc-dismiss="close"
					aria-label="<?php esc_attr_e( 'Fermer', 'delicat-builder-v9' ); ?>"
				>&times;</button>

				<?php if ( '' !== trim( (string) $s['image'] ) ) : ?>
					<div class="dbv9-annc__media">
						<img
							src="<?php echo esc_url( (string) $s['image'] ); ?>"
							alt=""
							loading="lazy"
							decoding="async"
						>
					</div>
				<?php else : $decorative_icon = self::decorative_icon( (string) $s['emoji'] ); ?>
					<?php if ( '' !== $decorative_icon ) : ?>
						<div class="dbv9-annc__emoji dbv9-annc__emoji--svg" aria-hidden="true"><?php echo $decorative_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed local SVG map. ?></div>
					<?php endif; ?>
				<?php endif; ?>

				<?php if ( '' !== trim( (string) $s['title'] ) ) : ?>
					<h2 class="dbv9-annc__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( (string) $s['title'] ); ?></h2>
				<?php endif; ?>

				<?php if ( '' !== trim( (string) $s['message'] ) ) : ?>
					<p class="dbv9-annc__text" id="<?php echo esc_attr( $desc_id ); ?>"><?php echo esc_html( (string) $s['message'] ); ?></p>
				<?php endif; ?>

				<div class="dbv9-annc__actions">
					<?php if ( $has_primary ) : ?>
						<a
							class="dbv9-annc__btn dbv9-annc__btn--primary"
							href="<?php echo esc_url( $primary_url ); ?>"
							data-dbv9-annc-go
							<?php if ( ! empty( $s['primary_new_tab'] ) ) : ?>target="_blank" rel="noopener"<?php endif; ?>
						><?php echo esc_html( $primary_label ); ?></a>
					<?php endif; ?>

					<?php if ( $has_secondary ) : ?>
						<button
							type="button"
							class="dbv9-annc__btn dbv9-annc__btn--ghost"
							data-dbv9-annc-dismiss="secondary"
						><?php echo esc_html( $secondary_label ); ?></button>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/* --------------------------------------------------------------- admin */

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Announcement Studio', 'delicat-builder-v9' ),
			__( 'Announcement', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-announcement',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_announcement_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$old   = self::settings();
		$input = is_array( $input ) ? $input : array();

		$frequency = isset( $input['frequency'] ) ? sanitize_key( (string) $input['frequency'] ) : 'days';
		if ( ! in_array( $frequency, array( 'always', 'session', 'days' ), true ) ) {
			$frequency = 'days';
		}

		$scope = isset( $input['scope'] ) ? sanitize_key( (string) $input['scope'] ) : 'all';
		if ( ! in_array( $scope, array( 'all', 'home', 'shop' ), true ) ) {
			$scope = 'all';
		}

		$audience = isset( $input['audience'] ) ? sanitize_key( (string) $input['audience'] ) : 'all';
		if ( ! in_array( $audience, array( 'all', 'guests', 'members' ), true ) ) {
			$audience = 'all';
		}

		$accent = sanitize_hex_color( (string) ( isset( $input['accent'] ) ? $input['accent'] : '' ) );
		if ( ! $accent ) {
			$accent = '#ff5a1f';
		}

		$style = self::normalize_style( (string) ( isset( $input['style'] ) ? $input['style'] : 'aurora' ) );

		$clean = array(
			'enabled'         => empty( $input['enabled'] ) ? 0 : 1,
			'style'           => $style,
			'title'           => substr( sanitize_text_field( (string) ( isset( $input['title'] ) ? $input['title'] : '' ) ), 0, 120 ),
			'message'         => substr( sanitize_textarea_field( (string) ( isset( $input['message'] ) ? $input['message'] : '' ) ), 0, 400 ),
			'emoji'           => substr( sanitize_text_field( (string) ( isset( $input['emoji'] ) ? $input['emoji'] : '' ) ), 0, 8 ),
			'image'           => esc_url_raw( (string) ( isset( $input['image'] ) ? $input['image'] : '' ) ),
			'primary_label'   => substr( sanitize_text_field( (string) ( isset( $input['primary_label'] ) ? $input['primary_label'] : '' ) ), 0, 48 ),
			'primary_url'     => esc_url_raw( (string) ( isset( $input['primary_url'] ) ? $input['primary_url'] : '' ) ),
			'primary_new_tab' => empty( $input['primary_new_tab'] ) ? 0 : 1,
			'secondary_label' => substr( sanitize_text_field( (string) ( isset( $input['secondary_label'] ) ? $input['secondary_label'] : '' ) ), 0, 48 ),
			'show_secondary'  => empty( $input['show_secondary'] ) ? 0 : 1,
			'accent'          => $accent,
			'delay'           => min( 15000, max( 0, absint( isset( $input['delay'] ) ? $input['delay'] : 1200 ) ) ),
			'frequency'       => $frequency,
			'days'            => min( 365, max( 1, absint( isset( $input['days'] ) ? $input['days'] : 7 ) ) ),
			'scope'           => $scope,
			'audience'        => $audience,
			'skip_checkout'   => empty( $input['skip_checkout'] ) ? 0 : 1,
			'start'           => self::sanitize_date( (string) ( isset( $input['start'] ) ? $input['start'] : '' ) ),
			'end'             => self::sanitize_date( (string) ( isset( $input['end'] ) ? $input['end'] : '' ) ),
		);

		self::reset_cache();

		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) && class_exists( 'Delicat_Builder_V9_Cache', false ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}

		return $clean;
	}

	private static function sanitize_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}
		return $value;
	}

	public static function admin_assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-announcement' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_style(
			'delicat-builder-v9-announcement',
			DELICAT_BUILDER_V9_URL . 'assets/css/announcement.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_style(
			'delicat-builder-v9-announcement-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/announcement-admin.css',
			array( 'delicat-builder-v9-announcement' ),
			DELICAT_BUILDER_V9_VERSION
		);
		wp_enqueue_media();
		wp_enqueue_script(
			'delicat-builder-v9-announcement-admin',
			DELICAT_BUILDER_V9_URL . 'assets/js/announcement-admin.js',
			array(),
			DELICAT_BUILDER_V9_VERSION,
			true
		);
	}

	private static function field_name( string $key ): string {
		return self::OPTION . '[' . $key . ']';
	}

	private static function select( string $key, string $current, array $options ): void {
		echo '<select name="' . esc_attr( self::field_name( $key ) ) . '">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $current, false ) . '>' . esc_html( (string) $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this page.', 'delicat-builder-v9' ) );
		}

		$s = self::settings();
		?>
		<div class="wrap dbv9-admin">
			<h1><?php esc_html_e( 'Announcement Studio', 'delicat-builder-v9' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A smooth launch popup shown when a client opens the store. The dialog closes on the X button, the secondary button, a click on the overlay, or the Escape key.', 'delicat-builder-v9' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_announcement_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Status', 'delicat-builder-v9' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable popup', 'delicat-builder-v9' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'enabled' ) ); ?>" value="1" <?php checked( 1, (int) $s['enabled'] ); ?>>
								<?php esc_html_e( 'Show the announcement popup on the storefront', 'delicat-builder-v9' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Modèle', 'delicat-builder-v9' ); ?></h2>
				<p class="description dbv9-annc-model-help"><?php esc_html_e( 'Choisissez le style visuel de l’annonce. Tous les modèles restent centrés et responsifs sur mobile et ordinateur.', 'delicat-builder-v9' ); ?></p>
				<div class="dbv9-annc-model-grid" role="radiogroup" aria-label="<?php esc_attr_e( 'Modèle de l’annonce', 'delicat-builder-v9' ); ?>">
					<?php
					$models = array(
						'flash' => array(
							'label' => __( 'Flash', 'delicat-builder-v9' ),
							'desc'  => __( 'Scène sombre, contour accentué et impact immédiat. Idéal pour une annonce courte.', 'delicat-builder-v9' ),
						),
						'aurora' => array(
							'label' => __( 'Aurora', 'delicat-builder-v9' ),
							'desc'  => __( 'Dégradé coloré, carte claire et douce. Le choix par défaut pour mobile.', 'delicat-builder-v9' ),
						),
						'midnight' => array(
							'label' => __( 'Midnight', 'delicat-builder-v9' ),
							'desc'  => __( 'Fond marine profond aux couleurs Délicat, contraste élevé et look premium.', 'delicat-builder-v9' ),
						),
						'minimal' => array(
							'label' => __( 'Minimal', 'delicat-builder-v9' ),
							'desc'  => __( 'Blanc éditorial, typographie d’abord, presque aucune décoration.', 'delicat-builder-v9' ),
						),
						'boutique' => array(
							'label' => __( 'Boutique', 'delicat-builder-v9' ),
							'desc'  => __( 'Carte e-commerce chaleureuse, accent commercial et bouton très visible.', 'delicat-builder-v9' ),
						),
						'spotlight' => array(
							'label' => __( 'Spotlight', 'delicat-builder-v9' ),
							'desc'  => __( 'Scène sombre avec halo lumineux centré, élégante et calme.', 'delicat-builder-v9' ),
						),
					);
					foreach ( $models as $model_key => $model ) :
						$selected = ( (string) $s['style'] === (string) $model_key );
						?>
						<div class="dbv9-annc-model-card<?php echo $selected ? ' is-selected' : ''; ?>" data-dbv9-annc-model-card="<?php echo esc_attr( (string) $model_key ); ?>">
							<label class="dbv9-annc-model-card__head">
								<input type="radio" name="<?php echo esc_attr( self::field_name( 'style' ) ); ?>" value="<?php echo esc_attr( (string) $model_key ); ?>" <?php checked( $selected ); ?>>
								<strong><?php echo esc_html( (string) $model['label'] ); ?></strong>
							</label>
							<span class="dbv9-annc-model-card__sample dbv9-annc-model-card__sample--<?php echo esc_attr( (string) $model_key ); ?>" aria-hidden="true"><i></i><b></b><em></em></span>
							<span class="dbv9-annc-model-card__desc"><?php echo esc_html( (string) $model['desc'] ); ?></span>
							<button type="button" class="button-link dbv9-annc-model-preview" data-dbv9-annc-preview="<?php echo esc_attr( (string) $model_key ); ?>"><?php esc_html_e( 'Aperçu', 'delicat-builder-v9' ); ?></button>
						</div>
					<?php endforeach; ?>
				</div>

				<h2 class="title"><?php esc_html_e( 'Content', 'delicat-builder-v9' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dbv9-annc-title-field"><?php esc_html_e( 'Title', 'delicat-builder-v9' ); ?></label></th>
						<td><input type="text" id="dbv9-annc-title-field" class="regular-text" maxlength="120" name="<?php echo esc_attr( self::field_name( 'title' ) ); ?>" value="<?php echo esc_attr( (string) $s['title'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-message-field"><?php esc_html_e( 'Message', 'delicat-builder-v9' ); ?></label></th>
						<td><textarea id="dbv9-annc-message-field" class="large-text" rows="3" maxlength="400" name="<?php echo esc_attr( self::field_name( 'message' ) ); ?>"><?php echo esc_textarea( (string) $s['message'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-emoji-field"><?php esc_html_e( 'Icon (emoji)', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="text" id="dbv9-annc-emoji-field" class="small-text" maxlength="8" name="<?php echo esc_attr( self::field_name( 'emoji' ) ); ?>" value="<?php echo esc_attr( (string) $s['emoji'] ); ?>">
							<p class="description"><?php esc_html_e( 'Used only when no image is selected.', 'delicat-builder-v9' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-image-field"><?php esc_html_e( 'Image', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="url" id="dbv9-annc-image-field" class="regular-text" name="<?php echo esc_attr( self::field_name( 'image' ) ); ?>" value="<?php echo esc_attr( (string) $s['image'] ); ?>" placeholder="https://">
							<button type="button" class="button" id="dbv9-annc-image-pick"><?php esc_html_e( 'Choose image', 'delicat-builder-v9' ); ?></button>
							<button type="button" class="button" id="dbv9-annc-image-clear"><?php esc_html_e( 'Clear', 'delicat-builder-v9' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-accent-field"><?php esc_html_e( 'Accent colour', 'delicat-builder-v9' ); ?></label></th>
						<td><input type="color" id="dbv9-annc-accent-field" name="<?php echo esc_attr( self::field_name( 'accent' ) ); ?>" value="<?php echo esc_attr( (string) $s['accent'] ); ?>"></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Buttons', 'delicat-builder-v9' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="dbv9-annc-plabel"><?php esc_html_e( 'Action button label', 'delicat-builder-v9' ); ?></label></th>
						<td><input type="text" id="dbv9-annc-plabel" class="regular-text" maxlength="48" name="<?php echo esc_attr( self::field_name( 'primary_label' ) ); ?>" value="<?php echo esc_attr( (string) $s['primary_label'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-purl"><?php esc_html_e( 'Action button page', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="url" id="dbv9-annc-purl" class="regular-text" name="<?php echo esc_attr( self::field_name( 'primary_url' ) ); ?>" value="<?php echo esc_attr( (string) $s['primary_url'] ); ?>" placeholder="https://">
							<p class="description"><?php esc_html_e( 'Leave the label or the URL empty to hide the action button.', 'delicat-builder-v9' ); ?></p>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'primary_new_tab' ) ); ?>" value="1" <?php checked( 1, (int) $s['primary_new_tab'] ); ?>>
								<?php esc_html_e( 'Open in a new tab', 'delicat-builder-v9' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="dbv9-annc-slabel"><?php esc_html_e( 'Close button label', 'delicat-builder-v9' ); ?></label></th>
						<td>
							<input type="text" id="dbv9-annc-slabel" class="regular-text" maxlength="48" name="<?php echo esc_attr( self::field_name( 'secondary_label' ) ); ?>" value="<?php echo esc_attr( (string) $s['secondary_label'] ); ?>">
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'show_secondary' ) ); ?>" value="1" <?php checked( 1, (int) $s['show_secondary'] ); ?>>
									<?php esc_html_e( 'Show the close button', 'delicat-builder-v9' ); ?>
								</label>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Behaviour', 'delicat-builder-v9' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Open delay', 'delicat-builder-v9' ); ?></th>
						<td>
							<input type="number" min="0" max="15000" step="100" class="small-text" name="<?php echo esc_attr( self::field_name( 'delay' ) ); ?>" value="<?php echo esc_attr( (string) absint( $s['delay'] ) ); ?>">
							<?php esc_html_e( 'milliseconds after the page becomes interactive', 'delicat-builder-v9' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'How often', 'delicat-builder-v9' ); ?></th>
						<td>
							<?php
							self::select(
								'frequency',
								(string) $s['frequency'],
								array(
									'always'  => __( 'Every page load', 'delicat-builder-v9' ),
									'session' => __( 'Once per browser session', 'delicat-builder-v9' ),
									'days'    => __( 'Once every N days', 'delicat-builder-v9' ),
								)
							);
							?>
							<input type="number" min="1" max="365" class="small-text" name="<?php echo esc_attr( self::field_name( 'days' ) ); ?>" value="<?php echo esc_attr( (string) absint( $s['days'] ) ); ?>">
							<?php esc_html_e( 'days', 'delicat-builder-v9' ); ?>
							<p class="description"><?php esc_html_e( 'Editing the title, message or buttons starts a new campaign, so clients who already dismissed the previous one will see this one.', 'delicat-builder-v9' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Where', 'delicat-builder-v9' ); ?></th>
						<td>
							<?php
							self::select(
								'scope',
								(string) $s['scope'],
								array(
									'all'  => __( 'Everywhere', 'delicat-builder-v9' ),
									'home' => __( 'Homepage only', 'delicat-builder-v9' ),
									'shop' => __( 'Homepage and shop pages', 'delicat-builder-v9' ),
								)
							);
							?>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::field_name( 'skip_checkout' ) ); ?>" value="1" <?php checked( 1, (int) $s['skip_checkout'] ); ?>>
									<?php esc_html_e( 'Never show on cart, checkout or account pages', 'delicat-builder-v9' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Who', 'delicat-builder-v9' ); ?></th>
						<td>
							<?php
							self::select(
								'audience',
								(string) $s['audience'],
								array(
									'all'     => __( 'Everyone', 'delicat-builder-v9' ),
									'guests'  => __( 'Signed-out visitors only', 'delicat-builder-v9' ),
									'members' => __( 'Signed-in clients only', 'delicat-builder-v9' ),
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Schedule', 'delicat-builder-v9' ); ?></th>
						<td>
							<label><?php esc_html_e( 'From', 'delicat-builder-v9' ); ?>
								<input type="date" name="<?php echo esc_attr( self::field_name( 'start' ) ); ?>" value="<?php echo esc_attr( (string) $s['start'] ); ?>">
							</label>
							&nbsp;
							<label><?php esc_html_e( 'Until', 'delicat-builder-v9' ); ?>
								<input type="date" name="<?php echo esc_attr( self::field_name( 'end' ) ); ?>" value="<?php echo esc_attr( (string) $s['end'] ); ?>">
							</label>
							<p class="description"><?php esc_html_e( 'Leave empty for no limit.', 'delicat-builder-v9' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="dbv9-annc dbv9-annc--aurora dbv9-annc--admin-preview" id="dbv9-annc-admin-preview" hidden>
				<div class="dbv9-annc__overlay" data-dbv9-annc-admin-close></div>
				<div class="dbv9-annc__dialog" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Aperçu de l’annonce', 'delicat-builder-v9' ); ?>">
					<div class="dbv9-annc__decor" aria-hidden="true">
						<span class="dbv9-annc__float dbv9-annc__float--game">🎮</span>
						<span class="dbv9-annc__float dbv9-annc__float--gift">🎁</span>
						<span class="dbv9-annc__float dbv9-annc__float--bolt">⚡</span>
						<span class="dbv9-annc__float dbv9-annc__float--diamond">💎</span>
						<span class="dbv9-annc__float dbv9-annc__float--shield">🛡️</span>
					</div>
					<button type="button" class="dbv9-annc__close" data-dbv9-annc-admin-close aria-label="<?php esc_attr_e( 'Fermer', 'delicat-builder-v9' ); ?>">&times;</button>
					<div class="dbv9-annc__media" data-dbv9-annc-preview-media hidden><img src="" alt=""></div>
					<div class="dbv9-annc__emoji" data-dbv9-annc-preview-emoji aria-hidden="true">🎉</div>
					<h2 class="dbv9-annc__title" data-dbv9-annc-preview-title>Bienvenue sur Délicat Store</h2>
					<p class="dbv9-annc__text" data-dbv9-annc-preview-text>Découvrez nos recharges de jeux, cartes-cadeaux et abonnements — livraison rapide selon le produit.</p>
					<div class="dbv9-annc__actions">
						<span class="dbv9-annc__btn dbv9-annc__btn--primary" data-dbv9-annc-preview-primary>Voir l’offre</span>
						<span class="dbv9-annc__btn dbv9-annc__btn--ghost" data-dbv9-annc-preview-secondary>Plus tard</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}

endif;
