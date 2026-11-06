<?php
/**
 * Order confirmation (Builder 9.4.0): settings, and compatibility with the owner's former
 * plugin « Delicat Order Confirmation Pro » 2.3.1, which this module replaces.
 *
 * Loaded on every request type except boot Safe Mode and plugin activation; it only
 * registers hooks (no WooCommerce call at include time).
 *
 *  - plugins_loaded@21: while the old plugin is still active (it boots at @20), its eight
 *    hooks are removed so there is never a second render, a second stylesheet, a duplicate
 *    REST route or a second provider-sync listener. Setting oc_legacy_takeover = 0 (or
 *    oc_enabled = 0) leaves it untouched: the rollback path.
 *  - An admin notice asks to deactivate it, with a nonce-protected « Désactiver maintenant ».
 *  - delicat_topup_provider_update (kept for compatibility, made safe): never sets « failed »,
 *    never changes the status of an order whose lines belong to the Digital Gateway or to
 *    Shop2TopUp (they own completion), and a supplier failure puts a paid order on hold for
 *    manual review (owner decision: échec fournisseur → revue manuelle). A routine « pending »
 *    only writes the note: on this store, on-hold after payment always means manual review.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings, takeover of the old plugin, admin notice and provider sync.
 */
final class Delicat_Builder_V9_Order_Confirmation_Compat {

	public const LEGACY_CLASS = 'Delicat_Order_Confirmation_Pro';
	public const NOTICE_META  = 'dlc_oc_legacy_notice';
	public const SITE_OPTION  = 'delicat_builder_v9_site';

	/** @var bool Whether the old plugin's hooks were removed on this request. */
	private static $neutralized = false;

	/** @var array|null */
	private static $settings = null;

	public static function boot(): void {
		add_action( 'plugins_loaded', array( __CLASS__, 'neutralize_legacy' ), 21 );
		add_action( 'delicat_topup_provider_update', array( __CLASS__, 'provider_update' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'flag_retired_filters' ), 99 );
		if ( is_admin() ) {
			add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
			add_action( 'admin_init', array( __CLASS__, 'maybe_dismiss_notice' ) );
		}
	}

	/** The module's keys in the « Site complet » option (delicat_builder_v9_site). */
	public static function defaults(): array {
		return array(
			'oc_enabled'         => 1,
			'oc_live'            => 1,
			'oc_poll_minutes'    => 30,
			'oc_share'           => 1,
			'oc_motion'          => 'full',
			'oc_celebrate'       => 1,
			'oc_show_customer'   => 1,
			'oc_support_url'     => '',
			/* 9.4.2: WhatsApp Business of the support team (digits, country code first). */
			'oc_whatsapp'        => '50933111283',
			'oc_note'            => '',
			'oc_legacy_takeover' => 1,
		);
	}

	/** Saved module settings over the defaults; oc_note falls back to the old thank-you message. */
	public static function settings(): array {
		if ( null !== self::$settings ) {
			return self::$settings;
		}
		$saved    = get_option( self::SITE_OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$settings = array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
		if ( ! array_key_exists( 'oc_note', $saved ) ) {
			$settings['oc_note'] = isset( $saved['thankyou_message'] ) && is_scalar( $saved['thankyou_message'] )
				? (string) $saved['thankyou_message']
				: __( 'Commande confirmée — suivez son état ci-dessous. Le délai de livraison dépend du produit.', 'delicat-builder-v9' );
		}
		self::$settings = self::sanitize( $settings );
		return self::$settings;
	}

	/** Forget the cached settings (after a save, and in tests). */
	public static function reset(): void {
		self::$settings    = null;
		self::$neutralized = false;
	}

	/**
	 * Clean module keys (used by the « Site complet » form and by settings()).
	 *
	 * @param array $input Raw values.
	 */
	public static function sanitize( array $input ): array {
		$motion  = isset( $input['oc_motion'] ) && is_scalar( $input['oc_motion'] ) ? sanitize_key( (string) $input['oc_motion'] ) : 'full';
		$note    = isset( $input['oc_note'] ) && is_scalar( $input['oc_note'] ) ? sanitize_textarea_field( (string) $input['oc_note'] ) : '';
		$minutes = isset( $input['oc_poll_minutes'] ) ? absint( $input['oc_poll_minutes'] ) : 30;
		return array(
			'oc_enabled'         => empty( $input['oc_enabled'] ) ? 0 : 1,
			'oc_live'            => empty( $input['oc_live'] ) ? 0 : 1,
			'oc_poll_minutes'    => min( 60, max( 5, $minutes ) ),
			'oc_share'           => empty( $input['oc_share'] ) ? 0 : 1,
			'oc_motion'          => in_array( $motion, array( 'full', 'calm', 'off' ), true ) ? $motion : 'full',
			'oc_celebrate'       => empty( $input['oc_celebrate'] ) ? 0 : 1,
			'oc_show_customer'   => empty( $input['oc_show_customer'] ) ? 0 : 1,
			'oc_support_url'     => self::clean_support_url( isset( $input['oc_support_url'] ) && is_scalar( $input['oc_support_url'] ) ? (string) $input['oc_support_url'] : '' ),
			'oc_whatsapp'        => self::clean_whatsapp( isset( $input['oc_whatsapp'] ) && is_scalar( $input['oc_whatsapp'] ) ? (string) $input['oc_whatsapp'] : '' ),
			'oc_note'            => function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 240, 'UTF-8' ) : substr( $note, 0, 240 ),
			'oc_legacy_takeover' => empty( $input['oc_legacy_takeover'] ) ? 0 : 1,
		);
	}

	/** 9.4.2: a WhatsApp number as wa.me expects it: digits only, 8 to 15 of them, else empty. */
	public static function clean_whatsapp( string $number ): string {
		$digits = (string) preg_replace( '/[^0-9]/', '', $number );
		return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? $digits : '';
	}

	/** Support link: this site, or a WhatsApp link; anything else is dropped. */
	public static function clean_support_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$url = (string) esc_url_raw( $url, array( 'https', 'http' ) );
		if ( 0 === strpos( $url, 'https://wa.me/' ) ) {
			return $url;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return '' !== $host && $host === $home ? $url : '';
	}

	/** Module switched on, and the Builder itself running normally. */
	public static function enabled(): bool {
		if ( empty( self::settings()['oc_enabled'] ) ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) ) {
			try {
				if ( ! Delicat_Builder_V9_Core::is_enabled() || Delicat_Builder_V9_Core::is_safe_mode() ) {
					return false;
				}
			} catch ( Throwable $error ) {
				return false;
			}
		}
		return true;
	}

	/** Whether the old plugin should be neutralised. */
	public static function takeover(): bool {
		return self::enabled() && ! empty( self::settings()['oc_legacy_takeover'] );
	}

	public static function legacy_loaded(): bool {
		return class_exists( self::LEGACY_CLASS, false );
	}

	public static function neutralized(): bool {
		return self::$neutralized;
	}

	/** plugins_loaded@21: remove the old plugin's eight hooks (its own list, php:36-44). */
	public static function neutralize_legacy(): void {
		if ( ! self::legacy_loaded() || ! self::takeover() || ! is_callable( array( self::LEGACY_CLASS, 'instance' ) ) ) {
			return;
		}
		try {
			$legacy = call_user_func( array( self::LEGACY_CLASS, 'instance' ) );
		} catch ( Throwable $error ) {
			return;
		}
		if ( ! is_object( $legacy ) ) {
			return;
		}
		remove_action( 'wp', array( $legacy, 'remove_default_order_details' ), 20 );
		remove_action( 'wp_enqueue_scripts', array( $legacy, 'enqueue_assets' ) );
		remove_filter( 'body_class', array( $legacy, 'body_classes' ) );
		remove_filter( 'woocommerce_thankyou_order_received_text', array( $legacy, 'received_text' ), 20 );
		remove_action( 'woocommerce_thankyou', array( $legacy, 'render_confirmation' ), 5 );
		remove_action( 'rest_api_init', array( $legacy, 'register_rest_routes' ) );
		remove_action( 'send_headers', array( $legacy, 'send_security_headers' ) );
		remove_action( 'delicat_topup_provider_update', array( $legacy, 'sync_topup_provider_status' ), 10 );
		self::$neutralized = true;
	}

	/** The old plugin still listens to the provider action (takeover off): leave it alone. */
	private static function legacy_sync_attached(): bool {
		if ( self::$neutralized || ! self::legacy_loaded() || ! is_callable( array( self::LEGACY_CLASS, 'instance' ) ) ) {
			return false;
		}
		try {
			$legacy = call_user_func( array( self::LEGACY_CLASS, 'instance' ) );
		} catch ( Throwable $error ) {
			return false;
		}
		return is_object( $legacy ) && false !== has_action( 'delicat_topup_provider_update', array( $legacy, 'sync_topup_provider_status' ) );
	}

	/** Plugin file of the old plugin, for the deactivate link ('' when unknown). */
	public static function legacy_file(): string {
		if ( ! self::legacy_loaded() || ! function_exists( 'plugin_basename' ) ) {
			return '';
		}
		try {
			$file = ( new ReflectionClass( self::LEGACY_CLASS ) )->getFileName();
		} catch ( Throwable $error ) {
			return '';
		}
		return is_string( $file ) && '' !== $file ? (string) plugin_basename( $file ) : '';
	}

	/** Old filters this module does not honour (the plaintext code card read one of them). */
	public static function flag_retired_filters(): void {
		if ( ! function_exists( '_deprecated_hook' ) || ( self::legacy_loaded() && ! self::$neutralized ) || ! self::enabled() ) {
			return;
		}
		if ( has_filter( 'delicat_order_status_config' ) ) {
			_deprecated_hook( 'delicat_order_status_config', '9.4.0', 'delicat_builder_order_phase / delicat_builder_order_copy', esc_html__( 'Ignoré par le module Confirmation de commande.', 'delicat-builder-v9' ) );
		}
		if ( has_filter( 'delicat_order_delivery_code_meta_keys' ) ) {
			_deprecated_hook( 'delicat_order_delivery_code_meta_keys', '9.4.0', '', esc_html__( 'Les codes ne sont jamais lus depuis des métadonnées en clair.', 'delicat-builder-v9' ) );
		}
	}

	/** Dashboard, Plugins and Builder screens, while the old plugin is active. */
	public static function legacy_notice(): void {
		if ( ! self::legacy_loaded() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = is_object( $screen ) && isset( $screen->id ) ? (string) $screen->id : '';
		if ( ! in_array( $id, array( 'dashboard', 'plugins' ), true ) && false === strpos( $id, 'delicat-builder-v9' ) ) {
			return;
		}
		if ( DELICAT_BUILDER_V9_VERSION === (string) get_user_meta( get_current_user_id(), self::NOTICE_META, true ) ) {
			return;
		}
		$file    = self::legacy_file();
		$dismiss = wp_nonce_url( add_query_arg( 'dlc_oc_dismiss', '1' ), 'dlc_oc_dismiss' );
		$text    = self::$neutralized
			? __( '« Delicat Order Confirmation Pro » est maintenant intégré au Builder (module « Confirmation de commande »). Il a été neutralisé automatiquement : désactivez-le puis supprimez-le.', 'delicat-builder-v9' )
			: __( '« Delicat Order Confirmation Pro » est encore actif et affiche la page Merci à la place du Builder. Le module « Confirmation de commande » le remplace : désactivez-le puis supprimez-le.', 'delicat-builder-v9' );
		echo '<div class="notice notice-warning"><p>' . esc_html( $text ) . '</p><p>';
		if ( '' !== $file ) {
			$deactivate = wp_nonce_url( admin_url( 'plugins.php?action=deactivate&plugin=' . rawurlencode( $file ) ), 'deactivate-plugin_' . $file );
			echo '<a class="button button-primary" href="' . esc_url( $deactivate ) . '">' . esc_html__( 'Désactiver maintenant', 'delicat-builder-v9' ) . '</a> ';
		}
		echo '<a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Masquer', 'delicat-builder-v9' ) . '</a></p></div>';
	}

	/** admin_init: « Masquer » (no admin-ajax, so no Builder allowlist change). */
	public static function maybe_dismiss_notice(): void {
		if ( empty( $_GET['dlc_oc_dismiss'] ) || ! current_user_can( 'activate_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified just below.
			return;
		}
		check_admin_referer( 'dlc_oc_dismiss' );
		update_user_meta( get_current_user_id(), self::NOTICE_META, DELICAT_BUILDER_V9_VERSION );
		wp_safe_redirect( remove_query_arg( array( 'dlc_oc_dismiss', '_wpnonce' ) ) );
		exit;
	}

	/** Any line delivered by the Digital Gateway or Shop2TopUp (they own completion). */
	private static function owned_by_supplier_plugin( $order ): bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( '' !== trim( (string) $item->get_meta( '_dst2t_status', true ) ) ) {
				return true;
			}
		}
		return is_array( apply_filters( 'delicat_app_order_delivery', null, $order, 'summary' ) );
	}

	/**
	 * delicat_topup_provider_update (10, 3 args), kept from the old plugin and made safe.
	 *
	 * @param int|string $order_id           Order ID.
	 * @param string     $provider_status    pending | processing | success | failed (mappable).
	 * @param string     $provider_reference Supplier reference.
	 */
	public static function provider_update( $order_id, $provider_status = '', $provider_reference = '' ): void {
		if ( self::legacy_sync_attached() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order ) {
			return;
		}
		$status = (string) $order->get_status();
		if ( in_array( $status, array( 'cancelled', 'refunded', 'completed' ), true ) ) {
			return;
		}
		$provider_status = sanitize_key( (string) $provider_status );
		$reference       = substr( sanitize_text_field( (string) $provider_reference ), 0, 64 );
		$marker          = $provider_status . '|' . $reference;
		if ( $marker === (string) $order->get_meta( '_delicat_topup_provider_last', true ) ) {
			return;
		}
		/* 'note' = note and reference only. « pending » used to map to on-hold, which the status
		 * model reads as manual review (« Commande en vérification ») on a paid order. */
		$map    = apply_filters(
			'delicat_topup_provider_status_map',
			array(
				'pending'    => 'note',
				'processing' => 'processing',
				'success'    => 'completed',
				'failed'     => 'review',
			),
			$order
		);
		$target = is_array( $map ) && isset( $map[ $provider_status ] ) && is_scalar( $map[ $provider_status ] ) ? sanitize_key( (string) $map[ $provider_status ] ) : '';
		if ( 'failed' === $target || 'on-hold' === $target ) {
			$target = 'review'; // Never mark an order failed from here; on-hold after payment is the manual review hold.
		}
		if ( ! in_array( $target, array( 'note', 'processing', 'completed', 'review' ), true ) ) {
			return;
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Order_Status', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-order-status.php' );
		}
		$paid = class_exists( 'Delicat_Builder_V9_Order_Status', false )
			? Delicat_Builder_V9_Order_Status::payment_received( $order )
			: ( is_callable( array( $order, 'is_paid' ) ) && $order->is_paid() );

		if ( '' !== $reference ) {
			$order->update_meta_data( '_delicat_topup_provider_reference', $reference );
		}
		$order->update_meta_data( '_delicat_topup_provider_last', $marker );
		$note = '' !== $reference
			/* translators: 1: supplier status, 2: supplier reference */
			? sprintf( __( 'Mise à jour fournisseur : %1$s (réf. %2$s)', 'delicat-builder-v9' ), $provider_status, $reference )
			/* translators: %s: supplier status */
			: sprintf( __( 'Mise à jour fournisseur : %s', 'delicat-builder-v9' ), $provider_status );

		$new = '';
		if ( ! self::owned_by_supplier_plugin( $order ) ) {
			if ( 'completed' === $target && ( $paid || (float) $order->get_total() <= 0 ) ) {
				$new = 'completed';
			} elseif ( 'processing' === $target && $paid ) {
				$new = 'processing';
			} elseif ( 'review' === $target && $paid ) {
				$new   = 'on-hold';
				$note .= ' — ' . __( 'Fournisseur : échec signalé — vérification manuelle requise', 'delicat-builder-v9' );
			}
		}
		if ( '' !== $new && $new !== $status ) {
			$order->update_status( $new, $note );
			return;
		}
		$order->add_order_note( $note );
		$order->save();
	}
}

if ( ! function_exists( 'delicat_sync_topup_order_status' ) ) {
	/**
	 * Same body as the old plugin's helper (it fires the provider action).
	 *
	 * @param int|string $order_id           Order ID.
	 * @param string     $provider_status    Supplier status.
	 * @param string     $provider_reference Supplier reference.
	 */
	function delicat_sync_topup_order_status( $order_id, $provider_status, $provider_reference = '' ) {
		do_action( 'delicat_topup_provider_update', absint( $order_id ), sanitize_key( $provider_status ), sanitize_text_field( $provider_reference ) );
	}
}
