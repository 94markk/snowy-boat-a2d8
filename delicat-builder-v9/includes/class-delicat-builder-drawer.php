<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Delicat Drawer (RC29) — the storefront's mobile/desktop menu, rebuilt.
 *
 * Reads the same Menu Builder settings, items, quick actions and social links
 * as before (admin screens unchanged), and renders a new document: one panel,
 * one scroll region, account + wallet + quick actions + searchable sections +
 * social + theme toggle. ~9 KB CSS, ~6 KB ES5 JS, no blur filters, no jQuery.
 * Sign-in for guests is Delicat Identity's modal. Wallet balance refreshes on
 * open through the existing nonce-protected `delicat_builder_v9_wallet_balance`
 * AJAX action.
 */
final class Delicat_Builder_V9_Drawer {
	private static bool $booted   = false;
	private static bool $rendered = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		/* Priority 9, not 8. assets() skips drawer.css when the storefront-chrome
		 * bundle (which contains it) is already enqueued, and chrome is enqueued by
		 * Header Studio at priority 8. Sharing that priority made the check depend
		 * on registration order -- header-runtime self-boots on include, Drawer boots
		 * later via Core -- so it happened to work, and any reordering of the
		 * bootstrap would have silently shipped 18 KB of duplicate CSS. Bottom Nav
		 * already sits at 9 for the same reason. */
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 9 );
	}

	/** The rebuilt drawer owns the menu whenever the Menu Builder is enabled on the storefront. */
	public static function owns(): bool {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'delicat_builder_v9_menu_get_menu_builder_settings' ) ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
			return false;
		}
		$s = delicat_builder_v9_menu_get_menu_builder_settings();
		return ( ( $s['enabled'] ?? 'yes' ) === 'yes' ) && (bool) apply_filters( 'delicat_builder_v9_drawer_v2', true );
	}

	public static function assets(): void {
		if ( ! self::owns() ) {
			return;
		}
		/* The release-built storefront chrome already contains drawer.css. */
		if ( ! wp_style_is( 'delicat-builder-v9-storefront-chrome', 'enqueued' ) ) {
			wp_enqueue_style( 'delicat-builder-v9-drawer', DELICAT_BUILDER_V9_URL . 'assets/css/drawer.css', array(), DELICAT_BUILDER_V9_VERSION );
		}
		wp_enqueue_script( 'delicat-builder-v9-drawer', DELICAT_BUILDER_V9_URL . 'assets/js/drawer.js', array(), DELICAT_BUILDER_V9_VERSION, true );
		if ( function_exists( 'wp_script_add_data' ) ) {
			wp_script_add_data( 'delicat-builder-v9-drawer', 'strategy', 'defer' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* helpers                                                             */
	/* ------------------------------------------------------------------ */

	private static function settings(): array {
		$s = function_exists( 'delicat_builder_v9_menu_get_menu_builder_settings' ) ? delicat_builder_v9_menu_get_menu_builder_settings() : array();
		return is_array( $s ) ? $s : array();
	}

	private static function hex( $value, string $fallback ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', $value ) ? $value : $fallback;
	}

	private static function text( $value, string $fallback = '' ): string {
		return is_string( $value ) && '' !== trim( $value ) ? trim( $value ) : $fallback;
	}

	private static function url( $value, string $fallback = '' ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || '#' === $value ) {
			return $fallback;
		}
		if ( function_exists( 'delicat_builder_v9_menu_normalize_item_url' ) ) {
			$value = (string) delicat_builder_v9_menu_normalize_item_url( $value );
		}
		return $value;
	}

	private static function icon( string $name ): string {
		$paths = array(
			'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
			'chevron'  => '<path d="m9 5 7 7-7 7"/>',
			'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
			'wallet'   => '<rect x="3" y="6" width="18" height="13" rx="3"/><path d="M3 10h18M15.5 14.5h2.5"/>',
			'orders'   => '<path d="M4 7.5 12 3l8 4.5v9L12 21l-8-4.5z"/><path d="M4 7.5 12 12l8-4.5M12 12v9"/>',
			'support'  => '<path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v8a2.5 2.5 0 0 1-2.5 2.5H9l-5 4z"/><path d="M8 9.5h8M8 13h5"/>',
			'app'      => '<rect x="6" y="2.5" width="12" height="19" rx="3"/><path d="M10 18h4"/>',
			'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M6 15H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v1"/>',
			'logout'   => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4M15 8l5 4-5 4M20 12H9"/>',
			'login'    => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M9 8l5 4-5 4M14 12H3"/>',
			'moon'     => '<path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5z"/>',
			'points'   => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
			'whatsapp' => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.7-1.2A9 9 0 1 0 12 3z"/><path d="M9.2 8.8c.2-.4.5-.5.8-.5h.5c.2 0 .4.1.5.4l.6 1.5c.1.2 0 .4-.1.6l-.5.6c.6 1 1.5 1.8 2.5 2.4l.6-.5c.2-.2.4-.2.6-.1l1.5.7c.2.1.4.3.3.6-.1.9-.7 1.5-1.6 1.6-2.9.1-6.2-3.2-6.1-6.1 0-.4.2-.8.4-1.2z"/>',
			'telegram' => '<path d="m3.5 11.2 16-6.7c.6-.2 1.1.2 1 .9L18 19.8c-.1.6-.6.8-1.1.5l-4.3-3.2-2.1 2c-.2.2-.6.1-.6-.2l.3-3.4 7-6.3-8.6 5.3-3.7-1.2c-.7-.2-.7-.9 0-1.1z"/>',
			'instagram'=> '<rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.3" cy="6.7" r=".8"/>',
			'facebook' => '<path d="M14 8.5V6.8c0-.8.5-1.3 1.3-1.3H17V2.5h-2.4C11.9 2.5 11 4.2 11 6.5v2H8.5V12H11v9.5h3V12h2.6l.4-3.5z"/>',
			'tiktok'   => '<path d="M14 3c.3 2.3 1.8 3.8 4 4v3c-1.5 0-2.9-.5-4-1.3V15a5 5 0 1 1-5-5v3a2 2 0 1 0 2 2V3z"/>',
			'youtube'  => '<path d="M3.5 8.2c.2-1.6 1.3-2.5 2.9-2.6C8.3 5.4 10 5.3 12 5.3s3.7.1 5.6.3c1.6.1 2.7 1 2.9 2.6.2 1.3.3 2.5.3 3.8s-.1 2.5-.3 3.8c-.2 1.6-1.3 2.5-2.9 2.6-1.9.2-3.6.3-5.6.3s-3.7-.1-5.6-.3c-1.6-.1-2.7-1-2.9-2.6A24 24 0 0 1 3.2 12c0-1.3.1-2.5.3-3.8z"/><path d="m10.3 9.4 4.3 2.6-4.3 2.6z"/>',
		);
		return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ( $paths[ $name ] ?? '' ) . '</svg>';
	}

	private static function item_icon( array $it ): string {
		$image = is_string( $it['image'] ?? '' ) ? trim( (string) $it['image'] ) : '';
		if ( '' !== $image ) {
			return '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async">';
		}
		return function_exists( 'delicat_builder_v9_menu_native_icon_svg' ) ? (string) delicat_builder_v9_menu_native_icon_svg( (string) ( $it['icon'] ?? '' ) ) : self::icon( 'chevron' );
	}

	private static function items( string $group ): array {
		$items = function_exists( 'delicat_builder_v9_menu_get_menu_builder_items' ) ? delicat_builder_v9_menu_get_menu_builder_items() : array();
		$out   = array();
		foreach ( (array) $items as $it ) {
			if ( ! is_array( $it ) || ( $it['group'] ?? 'main' ) !== $group ) {
				continue;
			}
			if ( function_exists( 'delicat_builder_v9_menu_menu_item_visible' ) && ! delicat_builder_v9_menu_menu_item_visible( $it ) ) {
				continue;
			}
			$out[] = $it;
		}
		return $out;
	}

	private static function tokens( string $escaped ): string {
		return function_exists( 'delicat_builder_v9_menu_menu_apply_tokens' ) ? (string) delicat_builder_v9_menu_menu_apply_tokens( $escaped ) : $escaped;
	}

	private static function render_list( array $items ): string {
		$html = '';
		foreach ( $items as $it ) {
			$url     = self::url( $it['url'] ?? '', home_url( '/' ) );
			$current = function_exists( 'delicat_builder_v9_menu_menu_item_is_current' ) && delicat_builder_v9_menu_menu_item_is_current( $url );
			$sub     = (string) ( $it['subtitle'] ?? '' );
			if ( false === strpos( $sub, '{balance}' ) && function_exists( 'delicat_builder_v9_menu_menu_item_is_wallet' ) && delicat_builder_v9_menu_menu_item_is_wallet( $it ) && function_exists( 'delicat_builder_v9_menu_menu_balance_html' ) ) {
				$bal = (string) delicat_builder_v9_menu_menu_balance_html();
				if ( '' !== $bal ) {
					$sub = '' !== $sub ? $sub . ' · {balance}' : '{balance}';
				}
			}
			$badge = trim( (string) ( $it['badge'] ?? '' ) );
			$html .= '<li><a class="dlx-item' . ( $current ? ' is-current' : '' ) . '" href="' . esc_url( $url ) . '"' . ( $current ? ' aria-current="page"' : '' )
				. ' data-dlx-filter="' . esc_attr( strtolower( wp_strip_all_tags( (string) ( $it['title'] ?? '' ) . ' ' . $sub ) ) ) . '"'
				. ' style="--i1:' . esc_attr( self::hex( $it['color1'] ?? '', '#6d5dfc' ) ) . ';--i2:' . esc_attr( self::hex( $it['color2'] ?? '', '#ec4899' ) ) . '">'
				. '<span class="dlx-item__icon" aria-hidden="true">' . self::item_icon( $it ) . '</span>'
				. '<span class="dlx-item__copy"><strong>' . self::tokens( esc_html( (string) ( $it['title'] ?? '' ) ) ) . '</strong>'
				. ( '' !== $sub ? '<small>' . self::tokens( esc_html( $sub ) ) . '</small>' : '' ) . '</span>'
				. ( '' !== $badge ? '<em class="dlx-item__badge">' . self::tokens( esc_html( $badge ) ) . '</em>' : '' )
				. '<span class="dlx-item__chevron" aria-hidden="true">' . self::icon( 'chevron' ) . '</span></a></li>';
		}
		return $html;
	}

	private static function quick_actions(): string {
		$s = self::settings();
		if ( ( $s['quick_actions'] ?? 'yes' ) !== 'yes' || ! function_exists( 'delicat_builder_v9_menu_get_menu_quick_items' ) ) {
			return '';
		}
		$map  = array( 'wallet' => 'wallet', 'commandes' => 'orders', 'orders' => 'orders', 'support' => 'support', 'app' => 'app' );
		$html = '';
		foreach ( (array) delicat_builder_v9_menu_get_menu_quick_items() as $q ) {
			if ( ! is_array( $q ) ) {
				continue;
			}
			if ( function_exists( 'delicat_builder_v9_menu_menu_item_visible' ) && ! delicat_builder_v9_menu_menu_item_visible( $q ) ) {
				continue;
			}
			$label = self::text( $q['label'] ?? '', '' );
			if ( '' === $label ) {
				continue;
			}
			$key   = strtolower( remove_accents( $label ) );
			$icon  = '';
			$image = is_string( $q['image'] ?? '' ) ? trim( (string) $q['image'] ) : '';
			if ( '' !== $image ) {
				$icon = '<img src="' . esc_url( $image ) . '" alt="" loading="lazy" decoding="async">';
			} elseif ( isset( $map[ $key ] ) ) {
				$icon = self::icon( $map[ $key ] );
			} else {
				$icon = self::icon( 'chevron' );
			}
			$url  = self::url( $q['url'] ?? '', '#' );
			$html .= '<a class="dlx-quick__tile" href="' . esc_url( $url ) . '"' . ( '#install-app' === $url ? ' data-dlx-install' : '' ) . '><span class="dlx-quick__icon" aria-hidden="true">' . $icon . '</span><span>' . esc_html( $label ) . '</span></a>';
		}
		return '' !== $html ? '<nav class="dlx-quick" aria-label="' . esc_attr__( 'Raccourcis', 'delicat-builder-v9' ) . '">' . $html . '</nav>' : '';
	}

	private static function social(): string {
		$s = self::settings();
		if ( ( $s['social_enabled'] ?? 'yes' ) !== 'yes' ) {
			return '';
		}
		$html = '';
		foreach ( array( 'whatsapp', 'telegram', 'instagram', 'facebook', 'tiktok', 'youtube' ) as $net ) {
			if ( ( $s[ $net . '_enabled' ] ?? 'yes' ) !== 'yes' ) {
				continue;
			}
			$url = self::url( $s[ $net . '_url' ] ?? '', '' );
			if ( '' === $url || ! self::is_social_url( $net, $url ) ) {
				continue; /* RC32: a placeholder such as "/my-wallet/" saved in a social field is not a social link */
			}
			$label = self::text( $s[ $net . '_label' ] ?? '', ucfirst( $net ) );
			$html .= '<a class="dlx-social__link is-' . esc_attr( $net ) . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">' . self::icon( $net ) . '</a>';
		}
		return '' !== $html ? '<div class="dlx-social">' . $html . '</div>' : '';
	}

	/** A social link must point at that network (or WhatsApp's wa.me); anything else is a saved placeholder. */
	private static function is_social_url( string $net, string $url ): bool {
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
		foreach ( $hosts[ $net ] ?? array() as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}
		return false;
	}

	private static function account(): string {
		$s = self::settings();
		if ( ( $s['show_profile'] ?? 'yes' ) !== 'yes' ) {
			return '';
		}
		$badge = self::text( $s['profile_badge'] ?? '', '' );
		if ( is_user_logged_in() ) {
			if ( function_exists( 'delicat_builder_v9_menu_mark_page_private' ) ) {
				delicat_builder_v9_menu_mark_page_private();
			}
			$u    = wp_get_current_user();
			$full = trim( $u->first_name . ' ' . $u->last_name );
			if ( '' === $full ) {
				$full = $u->display_name ? $u->display_name : $u->user_login;
			}
			$initial = function_exists( 'mb_substr' ) ? mb_substr( trim( $full ), 0, 1, 'UTF-8' ) : substr( trim( $full ), 0, 1 );
			$initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $initial, 'UTF-8' ) : strtoupper( $initial );
			$code    = function_exists( 'delicat_builder_v9_menu_get_delicat_user_code' ) ? (string) delicat_builder_v9_menu_get_delicat_user_code( $u->ID ) : '';
			$account = function_exists( 'delicat_builder_v9_menu_menu_account_url' ) ? (string) delicat_builder_v9_menu_menu_account_url() : home_url( '/my-account/' );
			ob_start();
			?>
			<section class="dlx-account is-user">
				<a class="dlx-account__row" href="<?php echo esc_url( $account ); ?>">
					<span class="dlx-account__avatar" aria-hidden="true" data-dlx-initial><?php echo esc_html( $initial ); ?></span>
					<span class="dlx-account__copy">
						<strong><span data-dlx-name><?php echo esc_html( $full ); ?></span><?php if ( '' !== $badge ) : ?> <em><?php echo esc_html( $badge ); ?></em><?php endif; ?></strong>
						<small data-dlx-email><?php echo esc_html( (string) $u->user_email ); ?></small>
					</span>
					<span class="dlx-account__chevron" aria-hidden="true"><?php echo self::icon( 'chevron' ); ?></span>
				</a>
				<div class="dlx-account__tools">
					<?php if ( '' !== $code && '—' !== $code ) : ?>
						<button type="button" class="dlx-account__code" data-dlx-copy="<?php echo esc_attr( $code ); ?>" aria-label="<?php esc_attr_e( 'Copier mon code client', 'delicat-builder-v9' ); ?>"><code data-dlx-code><?php echo esc_html( $code ); ?></code><?php echo self::icon( 'copy' ); ?><span data-dlx-copy-label><?php esc_html_e( 'Copier', 'delicat-builder-v9' ); ?></span></button>
					<?php endif; ?>
					<a class="dlx-account__logout" href="<?php echo esc_url( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) ? Delicat_Builder_V9_Identity_Bridge::logout_url() : wp_logout_url( home_url( '/' ) ) ); ?>"><?php echo self::icon( 'logout' ); ?><?php esc_html_e( 'Déconnexion', 'delicat-builder-v9' ); ?></a>
				</div>
			</section>
			<?php
			return (string) ob_get_clean();
		}
		$modal   = class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'frontend_login_available' ) ) && Delicat_Builder_V9_Identity_Bridge::frontend_login_available();
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : wp_login_url();
		ob_start();
		?>
		<section class="dlx-account is-guest">
			<span class="dlx-account__avatar is-guest" aria-hidden="true"><?php echo self::icon( 'login' ); ?></span>
			<span class="dlx-account__copy"><strong><?php esc_html_e( 'Bienvenue chez Delicat', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Connectez-vous pour votre wallet, vos commandes et vos bonus.', 'delicat-builder-v9' ); ?></small></span>
			<?php if ( $modal ) : ?>
				<button type="button" class="dlx-account__login" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal"><?php esc_html_e( 'Se connecter', 'delicat-builder-v9' ); ?></button>
			<?php else : ?>
				<a class="dlx-account__login" href="<?php echo esc_url( $account ); ?>"><?php esc_html_e( 'Se connecter', 'delicat-builder-v9' ); ?></a>
			<?php endif; ?>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	private static function wallet(): string {
		$s = self::settings();
		if ( ( $s['show_wallet'] ?? 'yes' ) !== 'yes' || ! is_user_logged_in() ) {
			return '';
		}
		$balance  = function_exists( 'delicat_builder_v9_menu_wallet_balance_text' ) ? (string) delicat_builder_v9_menu_wallet_balance_text() : '';
		$label    = self::text( $s['wallet_label'] ?? '', __( 'Mon portefeuille', 'delicat-builder-v9' ) );
		$recharge = self::url( $s['recharge_url'] ?? '', self::url( $s['wallet_url'] ?? '', home_url( '/my-wallet/' ) ) );
		$wallet   = self::url( $s['wallet_url'] ?? '', home_url( '/my-wallet/' ) );
		$points   = self::text( $s['loyalty_points'] ?? '', '' );
		ob_start();
		?>
		<section class="dlx-wallet">
			<a class="dlx-wallet__main" href="<?php echo esc_url( $wallet ); ?>">
				<span class="dlx-wallet__icon" aria-hidden="true"><?php echo self::icon( 'wallet' ); ?></span>
				<span class="dlx-wallet__copy"><small><?php echo esc_html( $label ); ?></small><strong data-dlx-wallet-balance><?php echo '' !== $balance ? wp_kses_post( $balance ) : '—'; ?></strong><?php if ( '' !== $points && '0' !== $points ) : ?><span class="dlx-wallet__points"><?php echo self::icon( 'points' ); ?><?php echo esc_html( $points ); ?> points</span><?php endif; ?></span>
			</a>
			<a class="dlx-wallet__cta" href="<?php echo esc_url( $recharge ); ?>"><?php esc_html_e( 'Recharger', 'delicat-builder-v9' ); ?></a>
		</section>
		<?php
		return (string) ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* document                                                            */
	/* ------------------------------------------------------------------ */

	public static function render(): string {
		if ( self::$rendered || ! self::owns() ) {
			return '';
		}
		self::$rendered = true;
		$s        = self::settings();
		$primary  = self::hex( $s['primary'] ?? '', '#8b5cf6' );
		$second   = self::hex( $s['secondary'] ?? '', '#ec4899' );
		$accent   = self::hex( $s['accent'] ?? '', '#2f75ff' );
		$radius   = max( 12, min( 32, (int) ( $s['radius'] ?? 24 ) ) );
		$item_r   = max( 10, min( 24, (int) ( $s['item_radius'] ?? 16 ) ) );
		$position = ( $s['position'] ?? 'left' ) === 'right' ? 'right' : 'left';
		$title    = self::text( $s['custom_header_title'] ?? '', get_bloginfo( 'name' ) );
		$subtitle = self::text( $s['custom_header_subtitle'] ?? '', '' );
		$logo     = is_string( $s['custom_header_image'] ?? '' ) ? trim( (string) $s['custom_header_image'] ) : '';
		$home     = self::url( $s['custom_header_url'] ?? '', home_url( '/' ) );
		$main     = self::items( 'main' );
		$bonus    = ( $s['bonus_enabled'] ?? 'yes' ) === 'yes' ? self::items( 'bonus' ) : array();
		$bonus_t  = self::text( $s['bonus_title'] ?? '', __( 'Bonus', 'delicat-builder-v9' ) );
		$search   = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'shop' ) : home_url( '/' );
		$ajax     = is_user_logged_in() ? admin_url( 'admin-ajax.php' ) : '';
		$nonce    = is_user_logged_in() ? wp_create_nonce( 'delicat_builder_v9_wallet_live' ) : '';
		$terms    = function_exists( 'wc_terms_and_conditions_page_id' ) ? (int) wc_terms_and_conditions_page_id() : 0;
		$privacy  = (int) get_option( 'wp_page_for_privacy_policy' );
		$privacy_page = get_page_by_path( 'politique-confidentialite', OBJECT, 'page' );
		if ( $privacy_page instanceof WP_Post && 'publish' === $privacy_page->post_status ) {
			$privacy = (int) $privacy_page->ID; /* RC32: the published French page wins over an unpublished WP privacy setting */
		} elseif ( $privacy > 0 && 'publish' !== get_post_status( $privacy ) ) {
			$privacy = 0;
		}
		ob_start();
		?>
		<div class="dlx-drawer is-<?php echo esc_attr( $position ); ?>" id="delicat-drawer" data-dlx-drawer hidden aria-hidden="true"
			data-ajax="<?php echo esc_url( $ajax ); ?>" data-wallet-nonce="<?php echo esc_attr( $nonce ); ?>"
			style="--dlx-primary:<?php echo esc_attr( $primary ); ?>;--dlx-secondary:<?php echo esc_attr( $second ); ?>;--dlx-accent:<?php echo esc_attr( $accent ); ?>;--dlx-radius:<?php echo (int) $radius; ?>px;--dlx-item-radius:<?php echo (int) $item_r; ?>px">
			<div class="dlx-drawer__backdrop" data-dlx-close></div>
			<aside class="dlx-drawer__panel" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $title ); ?>" tabindex="-1" data-dlx-panel>
				<header class="dlx-drawer__top">
					<a class="dlx-brand" href="<?php echo esc_url( $home ); ?>">
						<?php if ( '' !== $logo ) : ?>
							<img class="dlx-brand__logo" src="<?php echo esc_url( $logo ); ?>" alt="" width="40" height="40" loading="eager" decoding="async">
						<?php else : ?>
							<?php
							$brand_initial = '' !== $title ? $title : ( '' !== $subtitle ? $subtitle : 'D' );
							$brand_initial = function_exists( 'mb_substr' ) ? mb_substr( $brand_initial, 0, 1, 'UTF-8' ) : substr( $brand_initial, 0, 1 );
							?>
							<span class="dlx-brand__mark" aria-hidden="true"><?php echo esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $brand_initial, 'UTF-8' ) : strtoupper( $brand_initial ) ); ?></span>
						<?php endif; ?>
						<?php
						/*
						 * pro.17: never print an empty line. $title falls back to the WordPress
						 * site name, and when that is blank too the old markup emitted an empty
						 * <strong> — which is the unbalanced gap beside the logo the merchant
						 * reported. With no title the subtitle becomes the primary line instead
						 * of being left orphaned and grey halfway down the bar.
						 */
						$brand_primary   = '' !== $title ? $title : $subtitle;
						$brand_secondary = '' !== $title ? $subtitle : '';
						?>
						<?php if ( '' !== $brand_primary ) : ?>
							<span class="dlx-brand__copy"><strong><?php echo esc_html( $brand_primary ); ?></strong><?php if ( '' !== $brand_secondary ) : ?><small><?php echo esc_html( $brand_secondary ); ?></small><?php endif; ?></span>
						<?php endif; ?>
					</a>
					<button type="button" class="dlx-drawer__close" data-dlx-close aria-label="<?php esc_attr_e( 'Fermer le menu', 'delicat-builder-v9' ); ?>"><?php echo self::icon( 'close' ); ?></button>
				</header>
				<div class="dlx-drawer__scroll" data-dlx-scroll>
					<?php echo self::account(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo self::wallet(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo self::quick_actions(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<form class="dlx-search" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
						<span class="dlx-search__icon" aria-hidden="true"><?php echo self::icon( 'search' ); ?></span>
						<label class="screen-reader-text" for="dlx-search-input"><?php esc_html_e( 'Rechercher', 'delicat-builder-v9' ); ?></label>
						<input id="dlx-search-input" type="search" name="s" autocomplete="off" enterkeyhint="search" placeholder="<?php esc_attr_e( 'Rechercher un jeu, une carte…', 'delicat-builder-v9' ); ?>" data-dlx-search>
						<input type="hidden" name="post_type" value="product">
					</form>
					<p class="dlx-empty" data-dlx-empty hidden><?php esc_html_e( 'Aucun résultat dans le menu — appuyez sur Entrée pour chercher dans la boutique.', 'delicat-builder-v9' ); ?></p>
					<?php if ( $main ) : ?>
						<section class="dlx-section" data-dlx-section>
							<h3 class="dlx-section__title"><?php esc_html_e( 'Menu', 'delicat-builder-v9' ); ?></h3>
							<ul class="dlx-list"><?php echo self::render_list( $main ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></ul>
						</section>
					<?php endif; ?>
					<?php if ( $bonus ) : ?>
						<section class="dlx-section" data-dlx-section>
							<button type="button" class="dlx-section__toggle" aria-expanded="true" data-dlx-toggle><?php echo esc_html( $bonus_t ); ?><?php echo self::icon( 'chevron' ); ?></button>
							<ul class="dlx-list"><?php echo self::render_list( $bonus ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></ul>
						</section>
					<?php endif; ?>
					<?php echo self::social(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<footer class="dlx-foot">
						<?php if ( ( $s['footer_toggle'] ?? 'yes' ) === 'yes' ) : ?>
							<button type="button" class="dlx-theme" data-delicat-theme-toggle aria-pressed="false"><?php echo self::icon( 'moon' ); ?><span><?php esc_html_e( 'Mode sombre', 'delicat-builder-v9' ); ?></span><i class="dlx-theme__switch" aria-hidden="true"></i></button>
						<?php endif; ?>
						<p class="dlx-foot__links">
							<?php if ( $terms > 0 ) : ?><a href="<?php echo esc_url( (string) get_permalink( $terms ) ); ?>"><?php esc_html_e( 'Conditions', 'delicat-builder-v9' ); ?></a><?php endif; ?>
							<?php if ( $privacy > 0 ) : ?><a href="<?php echo esc_url( (string) get_permalink( $privacy ) ); ?>"><?php esc_html_e( 'Confidentialité', 'delicat-builder-v9' ); ?></a><?php endif; ?>
							<a href="<?php echo esc_url( $search ); ?>"><?php esc_html_e( 'Boutique', 'delicat-builder-v9' ); ?></a>
						</p>
					</footer>
				</div>
			</aside>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
