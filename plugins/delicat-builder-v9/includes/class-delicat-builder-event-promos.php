<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.12.2 — « Promotions événement »: the owner creates an event promotion himself.
 *
 * WooCommerce stays the coupon engine: each event IS a WooCommerce coupon (WC_Coupon API: amount, type,
 * expiry, usage limits, product / category restrictions, minimum amount, individual use). This class only
 * adds what WooCommerce lacks:
 *  - a START date (meta `_dlc_event_start`): before it the coupon exists but `woocommerce_coupon_is_valid`
 *    refuses it with a clear French message;
 *  - « réservé aux clients connectés » (meta `_dlc_event_members`);
 *  - an optional slim banner and the one-tap offer in the express pop-up and the checkout coupon box, both
 *    drawn in the browser from a small public JSON that lists ONLY the events running now (so LiteSpeed's
 *    page cache never freezes a banner, and no endpoint reveals a future, hidden or disabled code).
 * Discounts are computed by WooCommerce only. No usage throttle is added (owner rule): the limits are the
 * WooCommerce usage limits the owner sets on the event.
 */
final class Delicat_Builder_V9_Event_Promos {

	public const OPTION = 'delicat_builder_v9_event_promos';
	public const PAGE   = 'delicat-builder-v9-promos';
	/** V10: LiteSpeed tag of the public /promos JSON, purged whenever the owner changes an event. */
	public const CACHE_TAG = 'dbv9_promos';

	public static function boot(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_is_valid' ), 20, 3 );
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'wp_footer', array( __CLASS__, 'storefront_script' ), 98 );
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
			add_action( 'admin_post_delicat_event_promo_save', array( __CLASS__, 'save' ) );
			add_action( 'admin_post_delicat_event_promo_action', array( __CLASS__, 'action' ) );
		}
	}

	/* ------------------------------------------------------------------------------------------- data */

	/** @return array<string,array> */
	public static function events(): array {
		$events = get_option( self::OPTION, array() );
		return is_array( $events ) ? $events : array();
	}

	private static function store( array $events ): void {
		$before = self::storefront_needed();
		update_option( self::OPTION, $events, false );
		update_option( self::UNTIL, self::until( $events ), true );
		/* V10 (security review #3): an event saved, disabled or re-enabled is in the public JSON at once. */
		do_action( 'litespeed_purge', self::CACHE_TAG );
		/* 9.14.0 (J-M4): the first scheduled event puts the promo files on every page: pages cached without them
		 * are purged once. Disabling or ending events purges nothing (the JSON simply lists nothing). */
		if ( ! $before && self::storefront_needed() ) {
			do_action( 'litespeed_purge_all' );
		}
	}

	/** à venir / en cours / terminé / désactivé, from the event and its WooCommerce coupon. */
	public static function status( array $event ): string {
		if ( ! empty( $event['disabled'] ) ) {
			return 'disabled';
		}
		$now = time();
		if ( ! empty( $event['start'] ) && $now < (int) $event['start'] ) {
			return 'upcoming';
		}
		if ( ! empty( $event['end'] ) && $now >= (int) $event['end'] ) {
			return 'ended';
		}
		$coupon = self::coupon( $event );
		if ( $coupon && $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			return 'ended';
		}
		return $coupon ? 'running' : 'ended';
	}

	private static function coupon( array $event ) {
		if ( empty( $event['coupon_id'] ) || ! class_exists( 'WC_Coupon' ) ) {
			return null;
		}
		$coupon = new WC_Coupon( (int) $event['coupon_id'] );
		return $coupon->get_id() ? $coupon : null;
	}

	/* --------------------------------------------------------------------------------- WooCommerce rule */

	/** Before the start, or for a guest on a members-only event, WooCommerce refuses the code. */
	public static function coupon_is_valid( $valid, $coupon, $discounts = null ) {
		unset( $discounts );
		if ( ! $valid || ! is_object( $coupon ) || ! method_exists( $coupon, 'get_id' ) ) {
			return $valid;
		}
		$start = (int) get_post_meta( $coupon->get_id(), '_dlc_event_start', true );
		if ( $start > 0 && time() < $start ) {
			throw new Exception(
				sprintf(
					/* translators: %s: date and time the promotion starts */
					esc_html__( 'Ce code promo sera valable à partir du %s.', 'delicat-builder-v9' ),
					esc_html( wp_date( 'j F Y \à H\hi', $start ) )
				),
				100
			);
		}
		if ( '1' === (string) get_post_meta( $coupon->get_id(), '_dlc_event_members', true ) && ! is_user_logged_in() ) {
			throw new Exception( esc_html__( 'Ce code promo est réservé aux clients connectés : connectez-vous pour l’utiliser.', 'delicat-builder-v9' ), 100 );
		}
		return $valid;
	}

	/* ---------------------------------------------------------------------- public JSON (running only) */

	public static function rest(): void {
		register_rest_route(
			'delicat-builder-v9/v1',
			'/promos',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_promos' ),
			)
		);
	}

	/**
	 * Only events running NOW whose banner is on; never a future, ended, disabled, unpublished or discreet
	 * (no banner) code: a code becomes public only while it is shown.
	 */
	public static function running(): array {
		$out = array();
		foreach ( self::events() as $event ) {
			if ( 'running' !== self::status( $event ) || empty( $event['show_banner'] ) ) {
				continue;
			}
			$coupon = self::coupon( $event );
			if ( ! $coupon || 'publish' !== get_post_status( $coupon->get_id() ) ) {
				continue;
			}
			$amount = (float) $coupon->get_amount();
			$label  = 'percent' === $coupon->get_discount_type()
				? '-' . rtrim( rtrim( number_format( $amount, 2, ',', '' ), '0' ), ',' ) . ' %'
				: '-' . html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
			$out[] = array(
				'name'    => (string) $event['name'],
				'code'    => strtoupper( (string) $coupon->get_code() ),
				'label'   => $label,
				'text'    => (string) ( $event['banner_text'] ?? '' ),
				'banner'  => ! empty( $event['show_banner'] ) ? 1 : 0,
				'members' => ! empty( $event['members_only'] ) ? 1 : 0,
				'ends'    => (int) ( $event['end'] ?? 0 ),
			);
		}
		return $out;
	}

	public static function rest_promos() {
		$response = new WP_REST_Response( array( 'promos' => self::running() ) );
		/* a short public cache: a banner appears or goes within a minute of its dates */
		$response->header( 'Cache-Control', 'public, max-age=60' );
		/* V10 (security review #3): LiteSpeed keeps REST answers for its own REST TTL (7 days by
		 * default) unless told, so an ended or disabled code stayed advertised and a starting
		 * event's banner never came. Held to the same minute, and tagged for the purge in store():
		 * a start, an end or a used-up code shows within 60 s without any scheduled purge. */
		do_action( 'litespeed_control_set_ttl', 60 );
		do_action( 'litespeed_tag_add', self::CACHE_TAG );
		return $response;
	}

	/* ------------------------------------------------------------------------------------- storefront */

	/**
	 * Banner « [Nom] : -X % avec le code CODE » + « Copier », the offer row in the express pop-up and in the
	 * checkout coupon box. Drawn from the JSON above (cached 5 min in the tab), never from the cached page.
	 * 9.14.0 (S-10): the script and its style are plugin files (cached by the browser, the CDN and the service
	 * worker; the ?ver content hash through the loader filters, as for an enqueued file). The JSON address stays
	 * in a data attribute. 9.14.0 (J-M4): printed only while an event is scheduled or running, so a store with
	 * no promotion sends neither the files nor one REST request per visitor session.
	 */
	public static function storefront_script(): void {
		if ( is_admin() || ! function_exists( 'wc_coupons_enabled' ) || ! wc_coupons_enabled() || ! self::storefront_needed() ) {
			return;
		}
		$url = esc_url_raw( rest_url( 'delicat-builder-v9/v1/promos' ) );
		echo '<link rel="stylesheet" id="delicat-event-promos-css" href="' . esc_url( self::asset( 'assets/css/dlc-914-promos.css', 'style_loader_src' ) ) . '" media="all" data-no-optimize="1">' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- printed in the footer only while an event exists.
		echo '<script id="delicat-event-promos-js" src="' . esc_url( self::asset( 'assets/js/dlc-914-promos.js', 'script_loader_src' ) ) . '" data-url="' . esc_attr( $url ) . '" data-no-optimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- same.
	}

	/** Plugin asset address with ?ver, passed through the loader filter like an enqueued file (Audit_Fixes hash). */
	private static function asset( string $relative, string $filter ): string {
		$base = defined( 'DELICAT_BUILDER_V9_URL' ) ? DELICAT_BUILDER_V9_URL : plugin_dir_url( dirname( __FILE__ ) );
		$ver  = defined( 'DELICAT_BUILDER_V9_VERSION' ) ? (string) DELICAT_BUILDER_V9_VERSION : '1';
		return (string) apply_filters( $filter, $base . $relative . '?ver=' . rawurlencode( $ver ), 'delicat-event-promos' );
	}

	/** 9.14.0 (J-M4): autoloaded « print the promo files until » time: 0 none, -1 no end, else a timestamp. */
	public const UNTIL = 'delicat_builder_v9_event_promos_until';

	/** An event that is neither disabled nor over (scheduled or running): pages cached before its start carry it. */
	public static function storefront_needed(): bool {
		$until = get_option( self::UNTIL, false );
		if ( false === $until ) {
			$until = self::until( self::events() );
			update_option( self::UNTIL, $until, true );
		}
		$until = (int) $until;
		return -1 === $until || ( $until > 0 && time() < $until );
	}

	private static function until( array $events ): int {
		$until = 0;
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! empty( $event['disabled'] ) ) {
				continue;
			}
			$end = (int) ( $event['end'] ?? 0 );
			if ( $end <= 0 ) {
				return -1;
			}
			$until = max( $until, $end );
		}
		return $until;
	}

	/* ------------------------------------------------------------------------------------------ admin */

	public static function menu(): void {
		add_submenu_page( 'delicat-builder-v9', 'Promotions événement', 'Promotions événement', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
	}

	private static function local( int $ts ): string {
		return $ts > 0 ? wp_date( 'Y-m-d\TH:i', $ts ) : '';
	}

	/** « 2026-12-24T18:00 » in the site timezone -> UTC timestamp. */
	private static function parse_local( string $value ): int {
		$value = trim( $value );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $value ) ) {
			return 0;
		}
		$date = date_create_immutable_from_format( 'Y-m-d\TH:i', $value, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$events  = self::events();
		$edit_id = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector
		$e       = $edit_id && isset( $events[ $edit_id ] ) ? $events[ $edit_id ] : array();
		$coupon  = $e ? self::coupon( $e ) : null;
		$labels  = array( 'upcoming' => 'À venir', 'running' => 'En cours', 'ended' => 'Terminé', 'disabled' => 'Désactivé' );
		$cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$sel     = $coupon ? $coupon->get_product_categories() : array();
		?>
		<div class="wrap">
			<h1>Promotions événement</h1>
			<p>Chaque promotion est un vrai code promo WooCommerce. Avant la date de début, le code existe mais il est refusé ; après la date de fin, il expire.</p>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?><div class="notice notice-success"><p>Promotion enregistrée.</p></div><?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div><?php endif; ?>
			<h2><?php echo $e ? 'Modifier la promotion' : 'Nouvelle promotion'; ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="delicat_event_promo_save">
				<input type="hidden" name="id" value="<?php echo esc_attr( $edit_id ); ?>">
				<?php wp_nonce_field( 'delicat_event_promo_save' ); ?>
				<table class="form-table"><tbody>
					<tr><th><label for="dlc-ep-name">Nom de l’événement</label></th><td><input class="regular-text" id="dlc-ep-name" name="name" required maxlength="80" value="<?php echo esc_attr( $e['name'] ?? '' ); ?>" placeholder="Fête des mères"></td></tr>
					<tr><th><label for="dlc-ep-code">Code</label></th><td><input class="regular-text" id="dlc-ep-code" name="code" required maxlength="30" pattern="[A-Za-z0-9]{3,30}" style="text-transform:uppercase" value="<?php echo esc_attr( $coupon ? strtoupper( $coupon->get_code() ) : '' ); ?>"> <button type="button" class="button" id="dlc-ep-gen">Générer</button><p class="description">Lettres A–Z et chiffres seulement, unique.</p></td></tr>
					<tr><th>Réduction</th><td><select name="type"><option value="percent" <?php selected( $coupon ? $coupon->get_discount_type() : 'percent', 'percent' ); ?>>Pourcentage (%)</option><option value="fixed_cart" <?php selected( $coupon ? $coupon->get_discount_type() : '', 'fixed_cart' ); ?>>Montant fixe (G)</option></select> <input type="number" name="amount" min="0" step="0.01" required value="<?php echo esc_attr( $coupon ? $coupon->get_amount() : '' ); ?>" style="width:120px"></td></tr>
					<tr><th>Dates (heure du site)</th><td><label>Début <input type="datetime-local" name="start" required value="<?php echo esc_attr( self::local( (int) ( $e['start'] ?? 0 ) ) ); ?>"></label> &nbsp; <label>Fin <input type="datetime-local" name="end" required value="<?php echo esc_attr( self::local( (int) ( $e['end'] ?? 0 ) ) ); ?>"></label><p class="description">Fuseau : <?php echo esc_html( wp_timezone_string() ); ?></p></td></tr>
					<tr><th>Produits (facultatif)</th><td><input class="regular-text" name="products" value="<?php echo esc_attr( $coupon ? implode( ',', $coupon->get_product_ids() ) : '' ); ?>" placeholder="IDs séparés par des virgules"></td></tr>
					<tr><th>Catégories (facultatif)</th><td><select name="cats[]" multiple size="5" style="min-width:260px"><?php foreach ( is_array( $cats ) ? $cats : array() as $cat ) : ?><option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, array_map( 'intval', $sel ), true ) ); ?>><?php echo esc_html( $cat->name ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th>Panier minimum (facultatif)</th><td><input type="number" name="minimum" min="0" step="0.01" value="<?php echo esc_attr( $coupon && $coupon->get_minimum_amount() ? $coupon->get_minimum_amount() : '' ); ?>" style="width:140px"></td></tr>
					<tr><th>Limites d’utilisation</th><td><label>Total <input type="number" name="limit" min="0" step="1" value="<?php echo esc_attr( $coupon && $coupon->get_usage_limit() ? $coupon->get_usage_limit() : '' ); ?>" style="width:100px"></label> &nbsp; <label>Par client <input type="number" name="limit_user" min="0" step="1" value="<?php echo esc_attr( $coupon && $coupon->get_usage_limit_per_user() ? $coupon->get_usage_limit_per_user() : '' ); ?>" style="width:100px"></label><p class="description">Vide = sans limite.</p></td></tr>
					<tr><th>Options</th><td><label><input type="checkbox" name="members_only" value="1" <?php checked( ! empty( $e['members_only'] ) ); ?>> Réservé aux clients connectés</label><br><label><input type="checkbox" name="individual" value="1" <?php checked( $coupon && $coupon->get_individual_use() ); ?>> Non cumulable avec un autre code</label></td></tr>
					<tr><th>Bandeau (facultatif)</th><td><label><input type="checkbox" name="show_banner" value="1" <?php checked( ! empty( $e['show_banner'] ) ); ?>> Afficher un bandeau pendant l’événement</label><br><input class="regular-text" name="banner_text" maxlength="120" value="<?php echo esc_attr( $e['banner_text'] ?? '' ); ?>" placeholder="Par défaut : -X % avec le code CODE"></td></tr>
				</tbody></table>
				<?php submit_button( $e ? 'Enregistrer la promotion' : 'Créer la promotion' ); ?>
			</form>
			<script>document.getElementById('dlc-ep-gen').addEventListener('click',function(){var a='ABCDEFGHJKLMNPQRSTUVWXYZ23456789',s='',r=new Uint32Array(8);(window.crypto||{getRandomValues:function(x){for(var i=0;i<x.length;i++)x[i]=Math.floor(Math.random()*4294967295);return x}}).getRandomValues(r);for(var i=0;i<8;i++)s+=a[r[i]%a.length];document.getElementById('dlc-ep-code').value=s;});</script>
			<h2>Événements</h2>
			<table class="widefat striped"><thead><tr><th>Événement</th><th>Code</th><th>Dates</th><th>Statut</th><th>Utilisations</th><th></th></tr></thead><tbody>
			<?php if ( ! $events ) : ?><tr><td colspan="6">Aucune promotion pour l’instant.</td></tr><?php endif; ?>
			<?php foreach ( $events as $id => $ev ) : $c = self::coupon( $ev ); $st = self::status( $ev ); ?>
				<tr>
					<td><strong><?php echo esc_html( (string) $ev['name'] ); ?></strong></td>
					<td><code><?php echo esc_html( $c ? strtoupper( $c->get_code() ) : '—' ); ?></code></td>
					<td><?php echo esc_html( wp_date( 'j M Y H:i', (int) $ev['start'] ) . ' → ' . wp_date( 'j M Y H:i', (int) $ev['end'] ) ); ?></td>
					<td><?php echo esc_html( $labels[ $st ] ?? $st ); ?></td>
					<td><?php echo esc_html( $c ? (string) $c->get_usage_count() . ( $c->get_usage_limit() ? ' / ' . $c->get_usage_limit() : '' ) : '0' ); ?></td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => $id ), admin_url( 'admin.php' ) ) ); ?>">Modifier</a>
						<?php foreach ( array( 'duplicate' => 'Dupliquer', ( empty( $ev['disabled'] ) ? 'disable' : 'enable' ) => ( empty( $ev['disabled'] ) ? 'Désactiver' : 'Réactiver' ) ) as $do => $label ) : ?>
							<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'delicat_event_promo_action', 'do' => $do, 'id' => $id ), admin_url( 'admin-post.php' ) ), 'delicat_event_promo_' . $do . '_' . $id ) ); ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	private static function back( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission refusée.' );
		}
		check_admin_referer( 'delicat_event_promo_save' );
		if ( ! class_exists( 'WC_Coupon' ) ) {
			self::back( array( 'error' => 'WooCommerce est requis.' ) );
		}
		$in     = wp_unslash( $_POST );
		$events = self::events();
		$id     = sanitize_key( (string) ( $in['id'] ?? '' ) );
		$event  = $id && isset( $events[ $id ] ) ? $events[ $id ] : array();
		$name   = substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 80 );
		$code   = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['code'] ?? '' ) ) );
		$start  = self::parse_local( (string) ( $in['start'] ?? '' ) );
		$end    = self::parse_local( (string) ( $in['end'] ?? '' ) );
		$type   = 'fixed_cart' === ( $in['type'] ?? '' ) ? 'fixed_cart' : 'percent';
		$amount = max( 0.0, (float) ( $in['amount'] ?? 0 ) );
		if ( '' === $name || strlen( $code ) < 3 || ! $start || ! $end || $end <= $start || $amount <= 0 || ( 'percent' === $type && $amount > 100 ) ) {
			self::back( array( 'error' => 'Vérifiez le nom, le code (3 caractères au moins), la réduction et des dates de début et de fin valides.', 'edit' => $id ) );
		}
		$existing = function_exists( 'wc_get_coupon_id_by_code' ) ? (int) wc_get_coupon_id_by_code( $code ) : 0;
		$coupon_id = (int) ( $event['coupon_id'] ?? 0 );
		if ( $existing && $existing !== $coupon_id ) {
			self::back( array( 'error' => 'Ce code existe déjà : choisissez-en un autre.', 'edit' => $id ) );
		}
		$coupon = new WC_Coupon( $coupon_id ?: 0 );
		$coupon->set_code( $code );
		$coupon->set_discount_type( $type );
		$coupon->set_amount( $amount );
		$coupon->set_date_expires( $end );
		$coupon->set_description( 'Promotion événement : ' . $name );
		$coupon->set_usage_limit( absint( $in['limit'] ?? 0 ) );
		$coupon->set_usage_limit_per_user( absint( $in['limit_user'] ?? 0 ) );
		$coupon->set_product_ids( array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $in['products'] ?? '' ) ) ) ) ) );
		$coupon->set_product_categories( array_values( array_filter( array_map( 'absint', (array) ( $in['cats'] ?? array() ) ) ) ) );
		$minimum = (float) ( $in['minimum'] ?? 0 );
		$coupon->set_minimum_amount( $minimum > 0 ? (string) $minimum : '' );
		$coupon->set_individual_use( ! empty( $in['individual'] ) );
		$coupon_id = (int) $coupon->save();
		if ( ! $coupon_id ) {
			self::back( array( 'error' => 'WooCommerce n’a pas pu enregistrer le code.', 'edit' => $id ) );
		}
		update_post_meta( $coupon_id, '_dlc_event_start', $start );
		update_post_meta( $coupon_id, '_dlc_event_members', empty( $in['members_only'] ) ? '0' : '1' );
		$id = $id ?: 'ev' . substr( md5( $code . microtime() ), 0, 10 );
		update_post_meta( $coupon_id, '_dlc_event_id', $id );
		$events[ $id ] = array(
			'name'         => $name,
			'coupon_id'    => $coupon_id,
			'start'        => $start,
			'end'          => $end,
			'members_only' => empty( $in['members_only'] ) ? 0 : 1,
			'show_banner'  => empty( $in['show_banner'] ) ? 0 : 1,
			'banner_text'  => substr( sanitize_text_field( (string) ( $in['banner_text'] ?? '' ) ), 0, 120 ),
			'disabled'     => ! empty( $event['disabled'] ) ? 1 : 0,
		);
		self::store( $events );
		self::back( array( 'saved' => 1 ) );
	}

	public static function action(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Permission refusée.' );
		}
		$do = sanitize_key( (string) ( $_GET['do'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below
		$id = sanitize_key( (string) ( $_GET['id'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked below
		check_admin_referer( 'delicat_event_promo_' . $do . '_' . $id );
		$events = self::events();
		if ( ! isset( $events[ $id ] ) ) {
			self::back( array( 'error' => 'Promotion introuvable.' ) );
		}
		$event = $events[ $id ];
		if ( 'disable' === $do || 'enable' === $do ) {
			$events[ $id ]['disabled'] = 'disable' === $do ? 1 : 0;
			if ( ! empty( $event['coupon_id'] ) ) {
				/* a disabled event's code is unpublished, so WooCommerce refuses it */
				wp_update_post( array( 'ID' => (int) $event['coupon_id'], 'post_status' => 'disable' === $do ? 'draft' : 'publish' ) );
			}
			self::store( $events );
			self::back( array( 'saved' => 1 ) );
		}
		if ( 'duplicate' === $do && class_exists( 'WC_Coupon' ) ) {
			$src  = self::coupon( $event );
			$code = '';
			for ( $i = 0; $i < 20 && '' === $code; $i++ ) {
				$try = strtoupper( wp_generate_password( 8, false, false ) );
				$try = preg_replace( '/[^A-Z0-9]/', '', $try );
				if ( strlen( $try ) >= 6 && ! wc_get_coupon_id_by_code( $try ) ) {
					$code = $try;
				}
			}
			$copy = new WC_Coupon();
			if ( $src ) {
				$copy->set_props( array_diff_key( $src->get_data(), array_flip( array( 'id', 'code', 'usage_count', 'used_by', 'date_created', 'date_modified', 'meta_data' ) ) ) );
			}
			$copy->set_code( $code );
			$copy_id = (int) $copy->save();
			$new_id  = 'ev' . substr( md5( $code . microtime() ), 0, 10 );
			update_post_meta( $copy_id, '_dlc_event_start', (int) $event['start'] );
			update_post_meta( $copy_id, '_dlc_event_members', ! empty( $event['members_only'] ) ? '1' : '0' );
			update_post_meta( $copy_id, '_dlc_event_id', $new_id );
			$events[ $new_id ]              = $event;
			$events[ $new_id ]['name']      = $event['name'] . ' (copie)';
			$events[ $new_id ]['coupon_id'] = $copy_id;
			$events[ $new_id ]['disabled']  = 1;
			wp_update_post( array( 'ID' => $copy_id, 'post_status' => 'draft' ) );
			self::store( $events );
			self::back( array( 'edit' => $new_id ) );
		}
		self::back( array() );
	}
}

Delicat_Builder_V9_Event_Promos::boot();
