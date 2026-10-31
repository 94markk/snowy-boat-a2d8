<?php
/**
 * Delicat Builder V9 — Universal Footer.
 *
 * This is the only footer markup owner in Builder V9. Native documents,
 * product templates, the optional application shell and legacy shortcodes all
 * delegate here. Rendering is idempotent, server-side and JavaScript-free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Footer {

	public const OPTION = 'delicat_builder_v9_footer';

	private static bool $booted = false;
	private static bool $rendered = false;
	private static array $page_url_cache = array();

	public static function defaults(): array {
		return array(
			'enabled'       => 1,
			'tagline'       => 'Jeux, cartes cadeaux et services digitaux livrés simplement, rapidement et en toute sécurité.',
			'whatsapp'      => '50933111283',
			'menu_id'       => 0,
			'show_payments' => 1,
			'show_trust'    => 1,
		);
	}

	public static function settings(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		/* Migrate the former Shell-owned footer menu without writing during a
		 * public request. The next settings save stores it under this owner. */
		if ( empty( $saved['menu_id'] ) ) {
			$legacy_shell = get_option( 'delicat_builder_v9_shell', array() );
			if ( is_array( $legacy_shell ) && ! empty( $legacy_shell['footer_menu_id'] ) ) {
				$saved['menu_id'] = absint( $legacy_shell['footer_menu_id'] );
			}
		}
		$raw   = wp_parse_args( $saved, self::defaults() );
		$raw   = apply_filters( 'delicat_builder_v9_footer_settings', $raw );
		$raw   = is_array( $raw ) ? $raw : self::defaults();

		return array(
			'enabled'       => empty( $raw['enabled'] ) ? 0 : 1,
			'tagline'       => sanitize_text_field( (string) ( $raw['tagline'] ?? self::defaults()['tagline'] ) ),
			'whatsapp'      => preg_replace( '/[^0-9]/', '', (string) ( $raw['whatsapp'] ?? '' ) ),
			'menu_id'       => absint( $raw['menu_id'] ?? 0 ),
			'show_payments' => empty( $raw['show_payments'] ) ? 0 : 1,
			'show_trust'    => empty( $raw['show_trust'] ) ? 0 : 1,
		);
	}

	public static function is_enabled(): bool {
		if ( is_admin() || wp_doing_ajax() || empty( self::settings()['enabled'] ) ) {
			return false;
		}
		if (
			class_exists( 'Delicat_Builder_V9_Core', false )
			&& is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) )
			&& ! Delicat_Builder_V9_Core::is_enabled()
		) {
			return false;
		}
		return true;
	}

	public static function boot(): void {
		if ( self::$booted || ! self::is_enabled() ) {
			return;
		}
		self::$booted = true;

		add_filter( 'body_class', array( __CLASS__, 'body_class' ), 90 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
		/* Keep the footer in document flow before fixed commerce/navigation docks. */
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 8 );
	}

	public static function body_class( $classes ): array {
		$classes   = is_array( $classes ) ? $classes : array();
		$classes[] = 'dbv9-universal-footer-active';
		return array_values( array_unique( $classes ) );
	}

	/**
	 * RC27: the footer stylesheet is printed inline with the footer markup, the
	 * way the header prints its critical CSS. Signed-out visitors were served the
	 * footer unstyled when a guest-only CSS optimizer (combine/unique-CSS) dropped
	 * or stripped the external file; inline CSS travels with the cached HTML and
	 * cannot be separated from it. The external file remains the fallback for an
	 * oversized or unreadable stylesheet.
	 */
	public static function assets(): void {
		$css = DELICAT_BUILDER_V9_DIR . 'assets/css/native-footer.css';
		if ( ! is_file( $css ) ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-universal-footer',
			DELICAT_BUILDER_V9_URL . 'assets/css/native-footer.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function page_url( string $type, array $slugs, string $fallback ): string {
		$key = $type . '|' . implode( '|', $slugs ) . '|' . $fallback;
		if ( isset( self::$page_url_cache[ $key ] ) ) {
			return self::$page_url_cache[ $key ];
		}

		$native_pages = get_option( 'delicat_builder_v9_native_pages', array() );
		$native_pages = is_array( $native_pages ) ? $native_pages : array();
		$page_id      = absint( $native_pages[ $type ] ?? 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			$url = get_permalink( $page_id );
			if ( is_string( $url ) && '' !== $url ) {
				return self::$page_url_cache[ $key ] = $url;
			}
		}

		/* Native page IDs are installed once. A direct fallback avoids slug
		 * lookup queries on the public hot path when those pages are absent. */
		return self::$page_url_cache[ $key ] = home_url( $fallback );
	}

	private static function woo_page_url( string $page, string $fallback ): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( $page );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( $fallback );
	}

	private static function account_endpoint_url( string $endpoint, string $fallback ): string {
		if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
			$url = wc_get_account_endpoint_url( $endpoint );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return home_url( $fallback );
	}

	private static function configured_menu_links( int $menu_id ): array {
		$menu_id = absint( $menu_id );
		if ( $menu_id <= 0 || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return array();
		}

		$items = wp_get_nav_menu_items( $menu_id );
		if ( ! is_array( $items ) ) {
			return array();
		}

		$links = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $item->title ?? '' ) );
			$url   = esc_url_raw( (string) ( $item->url ?? '' ) );
			if ( '' === $label || '' === $url ) {
				continue;
			}
			$links[] = array( 'label' => $label, 'url' => $url );
			if ( count( $links ) >= 6 ) {
				break;
			}
		}
		return $links;
	}

	private static function columns( array $settings ): array {
		$shop    = self::woo_page_url( 'shop', '/shop/' );
		$account = self::woo_page_url( 'myaccount', '/my-account/' );
		$cart    = function_exists( 'wc_get_cart_url' ) ? (string) wc_get_cart_url() : home_url( '/cart/' );
		$support = self::page_url( 'support', array( 'support', 'assistance' ), '/support/' );

		$tutorials = '';
		if ( shortcode_exists( 'delica_tutorials' ) ) {
			$tutorial_page = get_page_by_path( 'tutoriels', OBJECT, 'page' );
			if ( $tutorial_page instanceof WP_Post && 'publish' === $tutorial_page->post_status ) {
				$tutorials = get_permalink( $tutorial_page );
			}
		}

		$columns = array(
			array(
				'title' => __( 'Boutique', 'delicat-builder-v9' ),
				'links' => array(
					array( 'label' => __( 'Tous les produits', 'delicat-builder-v9' ), 'url' => $shop ),
					array( 'label' => __( 'Jeux et recharges', 'delicat-builder-v9' ), 'url' => home_url( '/product-category/jeux/' ) ),
					array( 'label' => __( 'Cartes cadeaux', 'delicat-builder-v9' ), 'url' => home_url( '/product-category/gift-card/' ) ),
					array( 'label' => __( 'Abonnements', 'delicat-builder-v9' ), 'url' => home_url( '/product-category/abonnement/' ) ),
					array( 'label' => __( 'Services financiers', 'delicat-builder-v9' ), 'url' => home_url( '/product-category/exchange/' ) ),
				),
			),
			array(
				'title' => __( 'Mon espace', 'delicat-builder-v9' ),
				'links' => array(
					array( 'label' => __( 'Mon compte', 'delicat-builder-v9' ), 'url' => $account ),
					array( 'label' => __( 'Mes commandes', 'delicat-builder-v9' ), 'url' => self::account_endpoint_url( 'orders', '/my-account/orders/' ) ),
					array( 'label' => __( 'Mon portefeuille', 'delicat-builder-v9' ), 'url' => home_url( '/my-wallet/' ) ),
					array( 'label' => __( 'Mon panier', 'delicat-builder-v9' ), 'url' => $cart ),
				),
			),
			array(
				'title' => __( 'Aide', 'delicat-builder-v9' ),
				'links' => array_values( array_filter( array(
					array( 'label' => __( 'Centre d’assistance', 'delicat-builder-v9' ), 'url' => $support ),
					$tutorials ? array( 'label' => __( 'Tutoriels', 'delicat-builder-v9' ), 'url' => $tutorials ) : null,
					array( 'label' => __( 'Utiliser un code PIN', 'delicat-builder-v9' ), 'url' => home_url( '/free-fire-redeem/' ) ),
				) ) ),
			),
		);

		$configured = self::configured_menu_links( absint( $settings['menu_id'] ?? 0 ) );
		if ( ! empty( $configured ) ) {
			$columns[] = array(
				'title' => __( 'Explorer', 'delicat-builder-v9' ),
				'links' => $configured,
			);
		}

		$columns = apply_filters( 'delicat_builder_v9_footer_columns', $columns );
		/* 9.3.7: seen from the app, links to hidden categories or naming a finance subject
		 * (« Services financiers »), and the credit pages while gifts are hidden, go; so does
		 * a column left without links. */
		if ( class_exists( 'Delicat_Builder_V9_App_View', false ) ) {
			$columns = Delicat_Builder_V9_App_View::filter_footer_columns( $columns );
		}
		return is_array( $columns ) ? $columns : array();
	}

	private static function logo_html(): string {
		$shell   = get_option( 'delicat_builder_v9_shell', array() );
		$logo_id = is_array( $shell ) ? absint( $shell['logo_id'] ?? 0 ) : 0;
		if ( $logo_id <= 0 ) {
			$logo_id = absint( get_theme_mod( 'custom_logo', 0 ) );
		}

		$image = '';
		if ( $logo_id > 0 ) {
			$image = wp_get_attachment_image(
				$logo_id,
				'medium',
				false,
				array(
					'class'    => 'dbv9-footer-logo-image',
					'loading'  => 'lazy',
					'decoding' => 'async',
					'alt'      => get_bloginfo( 'name' ),
				)
			);
		}

		if ( is_string( $image ) && '' !== $image ) {
			/* RC55: the dark-mode logo chosen in Header Studio v8 is reused here so
			 * the footer matches the header when the client is in dark mode. Both
			 * images are in the HTML (cache-identical); CSS picks one. */
			$header    = get_option( 'dsb8_beta2_header', array() );
			$dark_id   = is_array( $header ) ? absint( $header['dark_logo_id'] ?? 0 ) : 0;
			$dark_img  = '';
			if ( $dark_id > 0 && $dark_id !== $logo_id ) {
				$dark_img = wp_get_attachment_image(
					$dark_id,
					'medium',
					false,
					array(
						'class'    => 'dbv9-footer-logo-image dbv9-footer-logo-image--dark',
						'loading'  => 'lazy',
						'decoding' => 'async',
						'alt'      => '',
					)
				);
			}
			/* 9.3.15: the Header Studio text logo replaces the image in dark mode. */
			$wordmark = self::dark_wordmark( is_array( $header ) ? $header : array() );
			$wm_class = '' !== $wordmark ? ' has-wordmark' : '';
			if ( is_string( $dark_img ) && '' !== $dark_img ) {
				$image = str_replace( 'class="dbv9-footer-logo-image"', 'class="dbv9-footer-logo-image dbv9-footer-logo-image--light"', $image ) . $dark_img;
				return '<a class="dbv9-footer-logo has-dark-logo' . $wm_class . '" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( get_bloginfo( 'name' ) ) . '" data-delicat-prefetch>' . $image . $wordmark . '</a>';
			}
			return '<a class="dbv9-footer-logo' . $wm_class . '" href="' . esc_url( home_url( '/' ) ) . '" aria-label="' . esc_attr( get_bloginfo( 'name' ) ) . '" data-delicat-prefetch>' . $image . $wordmark . '</a>';
		}

		$name    = sanitize_text_field( get_bloginfo( 'name' ) );
		$initial = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 1 ) : substr( $name, 0, 1 );
		return '<a class="dbv9-footer-logo dbv9-footer-logo--text" href="' . esc_url( home_url( '/' ) ) . '" data-delicat-prefetch><span aria-hidden="true">' . esc_html( strtoupper( $initial ) ) . '</span><strong>' . esc_html( $name ) . '</strong></a>';
	}

	/** Text logo for dark mode, from the Header Studio settings (on by default). */
	private static function dark_wordmark( array $header ): string {
		if ( ( isset( $header['dark_wordmark'] ) && empty( $header['dark_wordmark'] ) ) || absint( $header['dark_logo_id'] ?? 0 ) > 0 ) {
			return '';
		}
		$top = trim( sanitize_text_field( (string) ( $header['dark_wordmark_top'] ?? '' ) ) );
		$sub = trim( sanitize_text_field( (string) ( $header['dark_wordmark_sub'] ?? '' ) ) );
		if ( '' === $top && '' === $sub ) {
			if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) && is_callable( array( 'Delicat_Builder_V9_Header_Studio_8', 'auto_wordmark' ) ) ) {
				list( $top, $sub ) = Delicat_Builder_V9_Header_Studio_8::auto_wordmark();
			} else {
				$top = 'DELICAT';
				$sub = 'STORE HAITI';
			}
		}
		$lines = apply_filters( 'delicat_builder_v9_dark_wordmark', array( $top, $sub ) );
		if ( ! is_array( $lines ) ) {
			return '';
		}
		$top = trim( (string) ( $lines[0] ?? '' ) );
		$sub = trim( (string) ( $lines[1] ?? '' ) );
		if ( '' === $top && '' === $sub ) {
			return '';
		}
		if ( class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) && is_callable( array( 'Delicat_Builder_V9_Header_Studio_8', 'wordmark_svg' ) ) ) {
			return '<span class="dbv9-footer-wordmark" aria-hidden="true">' . Delicat_Builder_V9_Header_Studio_8::wordmark_svg( $top, $sub ) . '</span>';
		}
		return '<span class="dbv9-footer-wordmark" aria-hidden="true">' . ( '' !== $top ? '<b>' . esc_html( $top ) . '</b>' : '' ) . ( '' !== $sub ? '<small>' . esc_html( $sub ) . '</small>' : '' ) . '</span>';
	}

	private static function icon( string $name ): string {
		$icons = array(
			'bolt'     => '<svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M13 2 4.5 13.2h6.2L10 22l9-12h-6.3L13 2Z"/></svg>',
			'shield'   => '<svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3 5 6v5.2c0 4.5 2.8 8.2 7 9.8 4.2-1.6 7-5.3 7-9.8V6l-7-3Z"/><path d="m8.8 12 2.1 2.1 4.5-4.7"/></svg>',
			'chat'     => '<svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M20 11.6a7.5 7.5 0 0 1-8 7.4 8.7 8.7 0 0 1-3.1-.7L4 20l1.7-4.3A7.4 7.4 0 1 1 20 11.6Z"/><path d="M8.5 11.8h.01M12 11.8h.01M15.5 11.8h.01"/></svg>',
			'whatsapp' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.4 5 5.1-1.3A9.9 9.9 0 1 0 12.04 2Zm0 18.1c-1.5 0-3-.4-4.3-1.2l-.3-.2-3 .8.8-2.9-.2-.3a8.2 8.2 0 1 1 7 3.8Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.7.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.2-1.2-.1-.1-.3-.2-.5-.3Z"/></svg>',
			'headset'  => '<svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 14v-2a8 8 0 0 1 16 0v2"/><path d="M4 14a2 2 0 0 1 2-2h1v6H6a2 2 0 0 1-2-2v-2ZM20 14a2 2 0 0 0-2-2h-1v6h1a2 2 0 0 0 2-2v-2Z"/><path d="M17 18c0 1.7-2 3-5 3"/></svg>',
			'chevron'  => '<svg class="dbv9-ft__chevron" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>',
			'lock'     => '<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>',
		);
		return $icons[ $name ] ?? '';
	}

	/**
	 * 9.5.0: one link group. A <details> element: a tap-to-open accordion on
	 * phones (the footer shrinks to a few rows, the way an app's settings list
	 * reads) and an always-open column on wider screens, where the footer's
	 * tiny inline script keeps it open. Without JavaScript every group still
	 * opens with a tap; nothing depends on it.
	 */
	private static function render_column( array $column, array &$seen_urls, int $index ): string {
		$title = sanitize_text_field( (string) ( $column['title'] ?? '' ) );
		$links = is_array( $column['links'] ?? null ) ? $column['links'] : array();
		if ( '' === $title || empty( $links ) ) {
			return '';
		}

		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$items     = '';
		foreach ( $links as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $link['label'] ?? '' ) );
			$url   = esc_url_raw( (string) ( $link['url'] ?? '' ) );
			$key   = strtolower( untrailingslashit( $url ) );
			if ( '' === $label || '' === $url || isset( $seen_urls[ $key ] ) ) {
				continue;
			}
			$seen_urls[ $key ] = true;
			$link_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$internal  = '' === $link_host || $home_host === $link_host;
			$items    .= '<li><a href="' . esc_url( $url ) . '"' . ( $internal ? ' data-delicat-prefetch' : ' rel="noopener noreferrer" target="_blank"' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		if ( '' === $items ) {
			return '';
		}

		$id = 'dbv9-ft-group-' . $index;
		return '<details class="dbv9-ft__group" data-dbv9-ft-group>'
			. '<summary class="dbv9-ft__summary" aria-controls="' . esc_attr( $id ) . '"><span>' . esc_html( $title ) . '</span>' . self::icon( 'chevron' ) . '</summary>'
			. '<ul class="dbv9-ft__links" id="' . esc_attr( $id ) . '" role="list">' . $items . '</ul>'
			. '</details>';
	}

	/** The legal documents (filled by the Legal module), filtered for the app view and de-duplicated. */
	private static function legal_links( string $terms, string $privacy ): array {
		/* RC81.2: the footer listed two documents. The store now publishes
		 * nine, and a refund policy nobody can find protects nobody.
		 * The Legal module fills this through the filter; the two original
		 * links remain the fallback if that module is disabled. */
		$legal_links = apply_filters(
			'delicat_builder_v9_footer_legal_links',
			array(
				'terms'   => array( 'label' => __( 'Conditions d’utilisation', 'delicat-builder-v9' ), 'url' => $terms ),
				'privacy' => array( 'label' => __( 'Confidentialité', 'delicat-builder-v9' ), 'url' => $privacy ),
			)
		);
		/* 9.3.7: seen from the app, « Conditions Exchange et Wallet » is left out. */
		if ( class_exists( 'Delicat_Builder_V9_App_View', false ) ) {
			$legal_links = Delicat_Builder_V9_App_View::filter_items( $legal_links, 'url', array( 'label' ), true );
		}
		$out  = array();
		$seen = array();
		foreach ( (array) $legal_links as $legal_link ) {
			if ( ! is_array( $legal_link ) || empty( $legal_link['url'] ) || empty( $legal_link['label'] ) ) {
				continue;
			}
			$legal_url = (string) $legal_link['url'];
			$legal_key = untrailingslashit( strtolower( (string) wp_parse_url( $legal_url, PHP_URL_PATH ) ) );
			if ( '' !== $legal_key && isset( $seen[ $legal_key ] ) ) {
				continue;
			}
			if ( '' !== $legal_key ) {
				$seen[ $legal_key ] = true;
			}
			$out[] = array( 'label' => (string) $legal_link['label'], 'url' => $legal_url );
		}
		return $out;
	}

	public static function render_html(): string {
		if ( self::$rendered || ! self::is_enabled() || is_feed() || is_embed() ) {
			return '';
		}
		self::$rendered = true;

		try {
			$settings    = self::settings();
			$support     = self::page_url( 'support', array( 'support', 'assistance' ), '/support/' );
			$terms       = self::page_url( 'terms', array( 'conditions-utilisation', 'conditions-dutilisation' ), '/conditions-utilisation/' );
			$privacy     = self::page_url( 'privacy', array( 'politique-confidentialite', 'confidentialite' ), '/politique-confidentialite/' );
			$whatsapp    = (string) $settings['whatsapp'];
			$seen_urls   = array();
			$column_html = '';
			$index       = 0;
			foreach ( self::columns( $settings ) as $column ) {
				if ( is_array( $column ) ) {
					$column_html .= self::render_column( $column, $seen_urls, ++$index );
				}
			}
			$legal      = self::legal_links( $terms, $privacy );
			$wallet_lbl = ( class_exists( 'Delicat_Builder_V9_App_View', false ) && Delicat_Builder_V9_App_View::hides_gifts() ) ? __( 'Crédit boutique Délicat', 'delicat-builder-v9' ) : 'Delicat Wallet'; /* 9.3.7: seen from the Google Play build, the app's own name for it */
			$name       = get_bloginfo( 'name' );

			ob_start();
			?>
			<footer id="dbv9-universal-footer" class="dbv9-footer dbv9-ft" aria-labelledby="dbv9-footer-title" data-delicat-universal-footer data-delicat-footer-version="<?php echo esc_attr( DELICAT_BUILDER_V9_VERSION ); ?>">
				<div class="dbv9-ft__inner">
					<h2 id="dbv9-footer-title" class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Pied de page de %s', 'delicat-builder-v9' ), $name ) ); ?></h2>

					<div class="dbv9-ft__top">
						<div class="dbv9-ft__brand">
							<?php echo self::logo_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- generated and escaped above. ?>
							<p class="dbv9-ft__tagline"><?php echo esc_html( (string) $settings['tagline'] ); ?></p>
						</div>
						<div class="dbv9-ft__help" role="group" aria-label="<?php esc_attr_e( 'Assistance Delicat', 'delicat-builder-v9' ); ?>">
							<p class="dbv9-ft__help-copy"><strong><?php esc_html_e( 'Besoin d’aide ?', 'delicat-builder-v9' ); ?></strong> <span><?php esc_html_e( 'Une vraie personne vous répond.', 'delicat-builder-v9' ); ?></span></p>
							<div class="dbv9-ft__actions">
								<a class="dbv9-ft__btn dbv9-ft__btn--primary" href="<?php echo esc_url( $support ); ?>" data-delicat-prefetch><?php echo self::icon( 'headset' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span><?php esc_html_e( 'Support', 'delicat-builder-v9' ); ?></span></a>
								<?php if ( '' !== $whatsapp ) : ?>
									<a class="dbv9-ft__btn dbv9-ft__btn--whatsapp dbv9-footer-whatsapp" href="<?php echo esc_url( 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode( __( 'Bonjour Delicat Store, j’ai besoin d’aide.', 'delicat-builder-v9' ) ) ); /* 9.4.4: the chat opens with a first line */ ?>" rel="noopener noreferrer" target="_blank" aria-label="<?php esc_attr_e( 'Écrire au support sur WhatsApp', 'delicat-builder-v9' ); ?>"><?php echo self::icon( 'whatsapp' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><span>WhatsApp</span></a>
								<?php endif; ?>
							</div>
						</div>
					</div>

					<?php if ( '' !== $column_html ) : ?>
						<nav class="dbv9-ft__nav" aria-label="<?php esc_attr_e( 'Navigation du pied de page', 'delicat-builder-v9' ); ?>">
							<?php echo $column_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- normalized and escaped above. ?>
						</nav>
					<?php endif; ?>

					<?php if ( ! empty( $settings['show_trust'] ) ) : ?>
						<ul class="dbv9-ft__trust" role="list" aria-label="<?php esc_attr_e( 'Engagements Delicat', 'delicat-builder-v9' ); ?>">
							<li><span class="dbv9-ft__trust-icon"><?php echo self::icon( 'bolt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span><span><strong><?php esc_html_e( 'Livraison rapide', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Traitement optimisé', 'delicat-builder-v9' ); ?></small></span></li>
							<li><span class="dbv9-ft__trust-icon"><?php echo self::icon( 'shield' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span><span><strong><?php esc_html_e( 'Paiement sécurisé', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Transactions protégées', 'delicat-builder-v9' ); ?></small></span></li>
							<li><span class="dbv9-ft__trust-icon"><?php echo self::icon( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?></span><span><strong><?php esc_html_e( 'Support humain', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Assistance disponible', 'delicat-builder-v9' ); ?></small></span></li>
						</ul>
					<?php endif; ?>

					<div class="dbv9-ft__bottom">
						<?php if ( ! empty( $settings['show_payments'] ) ) : ?>
							<div class="dbv9-ft__payments" role="group" aria-label="<?php esc_attr_e( 'Moyens de paiement acceptés', 'delicat-builder-v9' ); ?>">
								<span class="dbv9-ft__label"><?php echo self::icon( 'lock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?><?php esc_html_e( 'Paiements acceptés', 'delicat-builder-v9' ); ?></span>
								<ul role="list">
									<li class="dbv9-ft__pay dbv9-ft__pay--moncash">MonCash</li>
									<li class="dbv9-ft__pay dbv9-ft__pay--natcash">NatCash</li>
									<li class="dbv9-ft__pay dbv9-ft__pay--wallet"><?php echo esc_html( $wallet_lbl ); ?></li>
								</ul>
							</div>
						<?php endif; ?>
						<?php if ( ! empty( $legal ) ) : ?>
							<nav class="dbv9-ft__legal" aria-label="<?php esc_attr_e( 'Informations légales', 'delicat-builder-v9' ); ?>">
								<ul role="list">
									<?php foreach ( $legal as $legal_link ) : ?>
										<li><a href="<?php echo esc_url( $legal_link['url'] ); ?>" data-delicat-prefetch><?php echo esc_html( $legal_link['label'] ); ?></a></li>
									<?php endforeach; ?>
								</ul>
							</nav>
						<?php endif; ?>
						<p class="dbv9-ft__copy"><?php echo esc_html( sprintf( __( '© %1$s %2$s. Tous droits réservés.', 'delicat-builder-v9' ), wp_date( 'Y' ), $name ) ); ?></p>
					</div>
				</div>
			</footer>
			<script id="dbv9-footer-groups" data-no-optimize="1" data-no-defer="1" data-cfasync="false">(function(){var f=document.getElementById('dbv9-universal-footer');if(!f||!window.matchMedia)return;var q=window.matchMedia('(min-width: 821px)'),g=f.querySelectorAll('[data-dbv9-ft-group]');function s(){for(var i=0;i<g.length;i++){g[i].open=q.matches;}}s();if(q.addEventListener){q.addEventListener('change',s);}else if(q.addListener){q.addListener(s);}f.addEventListener('click',function(e){var t=e.target&&e.target.closest?e.target.closest('.dbv9-ft__summary'):null;if(t&&q.matches){e.preventDefault();}});}());</script>
			<?php
			return (string) ob_get_clean();
		} catch ( Throwable $error ) {
			unset( $error );
			return '';
		}
	}

	public static function render(): void {
		$html = self::render_html();
		if ( '' === $html ) {
			return;
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- complete escaped footer document.
	}
}

if ( ! is_admin() ) {
	add_action( 'init', array( 'Delicat_Builder_V9_Footer', 'boot' ), 20 );
}
