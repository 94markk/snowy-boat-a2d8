<?php
/**
 * Delicat V9 Native Product Engine.
 *
 * A lightweight server-rendered WooCommerce single-product document. No
 * Elementor/template-builder dependency; WooCommerce remains authoritative for
 * variation, price, stock, cart and checkout. Product Fields/Calculator and
 * Swatches keep their native Woo hooks.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_Native_Product {
	public const OPTION = 'delicat_builder_v9_native_product';
	public const META   = '_delicat_builder_v9_native_product';
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) return;
		self::$booted = true;
		add_action( 'admin_post_delicat_builder_v9_native_product_save', array( __CLASS__, 'save_settings' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_product' ), 15, 3 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 30 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'slim_assets' ), 999 );
		add_filter( 'template_include', array( __CLASS__, 'template' ), PHP_INT_MAX );
		add_action( 'template_redirect', array( __CLASS__, 'fragment_response' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'cache_policy' ), 3 );
		add_filter( 'woocommerce_add_to_cart_redirect', array( __CLASS__, 'buy_now_redirect' ), 999 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		/* RC58: digital products carry a fixed quantity of one, server-side (see hide_quantity()). */
		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'hide_quantity' ), 50, 2 );
		add_action( 'admin_init', array( __CLASS__, 'migrate_v74' ), 3 );
		/* RC20: express checkout sheet for products that opt in (signed-in clients). */
		if ( did_action( 'wp' ) ) { self::prepare_express(); } else { add_action( 'wp', array( __CLASS__, 'prepare_express' ), 30 ); }
	}

	/** RC20: per-product "Commande express" — signed-in clients pay in a sheet instead of the checkout page. */
	public static function express_enabled_for( int $id ): bool {
		if ( $id <= 0 ) return false;
		$mode = (string) ( self::product_settings( $id )['express_checkout'] ?? 'inherit' );
		if ( '1' === $mode ) return true;
		if ( 'off' === $mode ) return false;
		return ! empty( self::settings()['express_checkout'] ); /* 'inherit' (and RC20's unticked '0') follow the global switch */
	}

	/**
	 * RC22: every condition the express sheet needs, as a readable list. Used by
	 * the admin diagnostics panel and by the front-end recorder, so a silent
	 * no-op can always be explained instead of guessed at.
	 */
	public static function express_status( int $id = 0 ): array {
		$checks = array();
		$checks[] = array( 'WooCommerce actif', class_exists( 'WooCommerce' ), 'WooCommerce est requis.' );
		$studio = class_exists( 'Delicat_Builder_V9_Purchase_Native', false ) && is_callable( array( 'Delicat_Builder_V9_Purchase_Native', 'express_supported' ) )
			? Delicat_Builder_V9_Purchase_Native::express_supported()
			: false;
		if ( ! class_exists( 'Delicat_Builder_V9_Purchase_Native', false ) ) {
			$saved  = get_option( 'delicat_builder_v9_purchase_ui', array() );
			$saved  = is_array( $saved ) ? $saved : array();
			$studio = ! empty( $saved['enabled'] ) && ! empty( $saved['native_checkout'] );
		}
		$checks[] = array( 'Purchase Studio + checkout natif', (bool) $studio, 'Delicat Builder → Purchase Studio : « Enable Purchase Studio » et « Woo-native checkout shell ».' );
		$checks[] = array( 'Moteur produit natif', ! empty( self::settings()['enabled'] ), 'Cochez « Activer le moteur produit natif » ci-dessus.' );
		$global = ! empty( self::settings()['express_checkout'] );
		$checks[] = array( 'Commande express (global)', $global, 'Cochez « Commande express » ci-dessus, ou forcez « Activée » sur la fiche produit.' );
		if ( $id > 0 ) {
			$mode = (string) ( self::product_settings( $id )['express_checkout'] ?? 'inherit' );
			$checks[] = array( 'Produit #' . $id . ' : rendu natif', self::is_active_product( $id ), 'Panneau « Native Product Builder » de la fiche produit : Rendu natif = Activé ou Hériter.' );
			$checks[] = array( 'Produit #' . $id . ' : express', self::express_enabled_for( $id ), 'off' === $mode ? 'Ce produit force « Désactivée ».' : 'Réglage global désactivé et produit sur « Hériter ».' );
		}
		$ok = true;
		foreach ( $checks as $check ) { if ( empty( $check[1] ) ) { $ok = false; break; } }
		return array( 'ok' => $ok, 'checks' => $checks );
	}

	/** Last front-end attempt, so the admin panel can show what actually happened on the storefront. */
	public static function record_express( int $id, string $state, string $detail = '' ): void {
		/*
		 * PRO15: this is a diagnostic for the Express panel, not storefront
		 * state. Without a persistent object cache a transient is two option
		 * writes, and the three states the happy path produces were written on
		 * every single product view — 'guest' on every uncached guest view,
		 * 'rendered' on every signed-in one. They are now recorded only for a
		 * user who can open the panel that reads them. A real failure ('off'
		 * aside, the states that mean express did not run) is always recorded,
		 * because that is the case the panel exists for.
		 */
		$routine = in_array( $state, array( 'off', 'guest', 'rendered' ), true );
		if ( $routine && ! ( function_exists( 'current_user_can' ) && current_user_can( 'manage_woocommerce' ) ) ) {
			return;
		}
		set_transient(
			'delicat_builder_v9_express_last',
			array( 'product' => $id, 'state' => $state, 'detail' => $detail, 'time' => time(), 'version' => DELICAT_BUILDER_V9_VERSION ),
			DAY_IN_SECONDS
		);
	}

	public static function prepare_express(): void {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'is_product' ) || ! is_product() ) return;
		$id = absint( get_queried_object_id() );
		if ( $id <= 0 || ! self::is_active_product( $id ) ) return;
		if ( ! self::express_enabled_for( $id ) ) { self::record_express( $id, 'off', 'Commande express désactivée pour ce produit.' ); return; }
		if ( ! is_user_logged_in() ) { self::record_express( $id, 'guest', 'Visiteur non connecté : la page checkout normale est utilisée.' ); return; }
		if ( ! class_exists( 'Delicat_Builder_V9_Express_Checkout', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-express-checkout.php' );
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Express_Checkout', false ) ) {
			self::record_express( $id, 'missing', 'Le module express n’a pas pu être chargé.' );
			return;
		}
		Delicat_Builder_V9_Express_Checkout::boot_for_product( $id );
	}

	/**
	 * RC58: remove the quantity control for digital products.
	 *
	 * The previous approach kept WooCommerce's visible quantity input and hid
	 * it with CSS only once JavaScript had flagged the floating dock as ready
	 * (`body.dnp-floating-ready`). On the Netflix page the input sat below the
	 * plan summary and was still visible after load — it is now removed at the
	 * source: pinning min = max = 1 makes WooCommerce's own quantity template
	 * print a hidden `quantity=1` field (its `.quantity.hidden` branch), so the
	 * form still submits a genuine quantity, nothing depends on JavaScript, and
	 * the cached HTML is identical for every visitor. Physical products
	 * (needs_shipping) keep the control. Off switch: Native Product Builder →
	 * "Masquer la quantité (produits numériques)".
	 */
	/**
	 * RC60: "digital" is decided per product family, not by the parent's shipping
	 * flag alone. A variable product's parent is rarely marked virtual — its
	 * variations are — so `needs_shipping()` on the parent said "physical" for
	 * Netflix and every other subscription, which is why the quantity box was
	 * still on the live page after RC58. Virtual or downloadable products, and
	 * variable products whose variations are all virtual/downloadable (or that
	 * have none), are digital. Everything else is physical only when WooCommerce
	 * says it needs shipping.
	 */
	public static function is_digital_product( $product ): bool {
		if ( ! $product instanceof WC_Product ) { return false; }
		if ( $product->is_virtual() || $product->is_downloadable() ) { return true; }
		if ( $product->is_type( 'variable' ) ) {
			$children = (array) $product->get_children();
			if ( ! $children ) { return true; }
			foreach ( array_slice( $children, 0, 40 ) as $child_id ) {
				$child = wc_get_product( absint( $child_id ) );
				if ( ! $child instanceof WC_Product ) { continue; }
				if ( ! $child->is_virtual() && ! $child->is_downloadable() && $child->needs_shipping() ) { return false; }
			}
			return true;
		}
		return ! $product->needs_shipping();
	}

	public static function hide_quantity( $args, $product = null ) {
		if ( ! is_array( $args ) || is_admin() ) { return $args; }
		if ( empty( self::settings()['hide_quantity'] ) ) { return $args; }
		if ( ! $product instanceof WC_Product ) { $product = function_exists( 'wc_get_product' ) ? wc_get_product( get_the_ID() ) : null; }
		if ( ! $product instanceof WC_Product || ! self::is_digital_product( $product ) ) { return $args; }
		if ( ! ( function_exists( 'is_product' ) && is_product() ) ) { return $args; }
		$args['min_value']   = 1;
		$args['max_value']   = 1;
		$args['input_value'] = 1;
		return $args;
	}

	public static function defaults(): array {
		return array(
			'enabled' => 1, 'force_native_template' => 1, 'max_width' => 1120,
			'page_bg' => '#f6f7fb', 'card_bg' => '#ffffff', 'text' => '#111827', 'muted' => '#667085',
			'primary' => '#6846ff', 'accent' => '#ff365d', 'line' => '#e7e9f2', 'radius' => 24,
			'show_breadcrumbs' => 0, 'show_short_desc' => 1, 'show_price' => 1, 'show_stock' => 1,
			'show_details' => 1, 'show_related' => 1, 'show_trust' => 1, 'show_steps' => 1, 'show_mobile_dock' => 1, 'mobile_floating_only' => 1, 'hide_quantity' => 1, 'express_checkout' => 0, 'show_reviews' => 1,
			'section_title' => 'Choisissez votre option', 'details_title' => 'Détails du produit',
			'related_title' => 'Vous aimerez aussi', 'steps_title' => 'Comment ça marche ?', 'reviews_title' => 'Avis clients', 'add_text' => 'Ajouter au panier', 'buy_text' => 'Acheter maintenant',
			'trust_1' => 'Livraison rapide', 'trust_2' => 'Paiement sécurisé',
			'trust_3' => 'Vérification sécurisée', 'trust_4' => 'Assistance disponible',
		);
	}

	/** Remove legacy decorative emoji from saved UI labels without touching customer content. */
	private static function clean_ui_label( string $text ): string {
		$clean = preg_replace( '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0E}\x{FE0F}\x{20E3}]/u', '', $text );
		$clean = is_string( $clean ) ? preg_replace( '/\s{2,}/u', ' ', $clean ) : $text;
		return trim( (string) $clean );
	}

	public static function settings(): array {
		$raw = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $raw ) ? $raw : array(), self::defaults() );
	}

	public static function product_settings( int $id ): array {
		$raw = get_post_meta( $id, self::META, true );
		$from_legacy = false;
		if ( ! is_array( $raw ) || ! $raw ) {
			$legacy = get_post_meta( $id, '_dsb_spb_v7411', true );
			if ( ! is_array( $legacy ) || ! $legacy ) $legacy = get_post_meta( $id, '_delicat_builder_v9_spb_v7411', true );
			if ( is_array( $legacy ) && $legacy ) {
				$from_legacy = true;
				$raw = array_intersect_key( $legacy, array_flip( array('enabled','hero_image','title','subtitle','primary','accent','page_bg','card_bg') ) );
			}
		}
		/* RC87: legacy V7 metadata used 0 as a local engine state. Treat that as
		 * `inherit` after migration so old products do not unexpectedly fall back
		 * to a different document shell. A current V9 explicit enabled=0 remains
		 * respected because it never enters this legacy branch. */
		if ( $from_legacy && isset( $raw['enabled'] ) && '1' !== (string) $raw['enabled'] ) {
			$raw['enabled'] = 'inherit';
		}
		return wp_parse_args( is_array( $raw ) ? $raw : array(), array(
			'enabled' => 'inherit', 'hero_image' => '', 'title' => '', 'subtitle' => '', 'express_checkout' => 'inherit',
			'primary' => '', 'accent' => '', 'page_bg' => '', 'card_bg' => '',
			'important_enabled' => '0', 'important_icon' => 'ⓘ', 'important_title' => 'À savoir',
			'important_content' => '', 'important_accent' => '#f59e0b',
			'important_bg' => '#fff8eb', 'important_text' => '#8a4b08',
		) );
	}

	public static function effective( int $id ): array {
		$s = self::settings(); $m = self::product_settings( $id );
		foreach ( array( 'primary','accent','page_bg','card_bg' ) as $key ) if ( '' !== $m[$key] ) $s[$key] = $m[$key];
		return array( $s, $m );
	}

	public static function is_active_product( int $id = 0 ): bool {
		if ( $id <= 0 && function_exists( 'is_product' ) && is_product() ) $id = absint( get_queried_object_id() );
		if ( $id <= 0 ) return false;
		$s = self::settings(); $m = self::product_settings( $id );
		if ( '1' === $m['enabled'] ) return true;
		if ( '0' === $m['enabled'] ) return false;
		return ! empty( $s['enabled'] );
	}

	public static function template( $template ) {
		if ( ! is_string( $template ) || is_admin() || wp_doing_ajax() || ! function_exists( 'is_product' ) || ! is_product() ) return $template;
		$id = absint( get_queried_object_id() ); $s = self::settings();
		if ( ! self::is_active_product( $id ) || empty( $s['force_native_template'] ) ) return $template;
		$native = DELICAT_BUILDER_V9_DIR . 'templates/native-single-product.php';
		return is_file( $native ) ? $native : $template;
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( function_exists('is_product') && is_product() && self::is_active_product( absint(get_queried_object_id()) ) ) {
			$classes[] = 'delicat-native-product-document';
		}
		return array_values( array_unique( $classes ) );
	}

	public static function style_vars( array $s ): string {
		$vars = array(
			'--dnp-bg' => sanitize_hex_color($s['page_bg']) ?: '#f6f7fb', '--dnp-card' => sanitize_hex_color($s['card_bg']) ?: '#fff',
			'--dnp-text' => sanitize_hex_color($s['text']) ?: '#111827', '--dnp-muted' => sanitize_hex_color($s['muted']) ?: '#667085',
			'--dnp-primary' => sanitize_hex_color($s['primary']) ?: '#6846ff', '--dnp-accent' => sanitize_hex_color($s['accent']) ?: '#ff365d',
			'--dnp-line' => sanitize_hex_color($s['line']) ?: '#e7e9f2', '--dnp-radius' => min(40,max(10,absint($s['radius']))).'px',
			'--dnp-max' => min(1440,max(720,absint($s['max_width']))).'px',
		);
		$out=''; foreach($vars as $k=>$v) $out .= $k.':'.$v.';'; return $out;
	}

	public static function render( int $id = 0 ): string {
		$id = $id ?: absint( get_queried_object_id() ); $product = wc_get_product( $id );
		if ( ! $product ) return '';
		global $post; $old_post = $post ?? null; $old_product = $GLOBALS['product'] ?? null;
		$product_post = get_post($id); if($product_post instanceof WP_Post){$post=$product_post;setup_postdata($post);} $GLOBALS['product']=$product;
		list($s,$m)=self::effective($id);
		self::queue_structured_data( $product );
		$title = '' !== trim($m['title']) ? $m['title'] : $product->get_name();
		$subtitle = '' !== trim($m['subtitle']) ? $m['subtitle'] : wp_strip_all_tags($product->get_short_description());
		$image_id = $product->get_image_id();
		if ( ! empty($m['hero_image']) ) $image_id = attachment_url_to_postid($m['hero_image']) ?: $image_id;
		$variable = $product->is_type('variable');
		/*
		 * Digital products still submit WooCommerce's genuine quantity input. On
		 * small screens the fixed native dock is the visible purchase control, so
		 * the default quantity of one may be visually compacted after JavaScript has
		 * proved that the dock is ready. Physical products keep the native quantity
		 * control visible for fulfilment-safe multi-item ordering.
		 */
		$compact_quantity = self::is_digital_product( $product );
		$compact_quantity = (bool) apply_filters(
			'delicat_builder_v9_native_product_compact_quantity',
			$compact_quantity,
			$product
		);
		ob_start();
		?>
		<article class="delicat-native-product<?php echo !empty($s['mobile_floating_only']) ? ' dnp-mobile-floating-only' : ''; ?><?php echo $compact_quantity ? ' dnp-compact-quantity' : ''; ?>" style="<?php echo esc_attr(self::style_vars($s)); ?>" data-product-id="<?php echo esc_attr($id); ?>" data-express="<?php echo self::express_enabled_for($id)?'1':'0'; ?>" data-product-type="<?php echo esc_attr($product->get_type()); ?>" data-variable="<?php echo $variable?'1':'0'; ?>" data-base-stock="<?php echo $product->is_in_stock()?'1':'0'; ?>" data-base-purchasable="<?php echo $product->is_purchasable()?'1':'0'; ?>" data-base-price="<?php echo esc_attr(wp_strip_all_tags($product->get_price_html())); ?>" data-document-title="<?php echo esc_attr($product->get_name().' – '.get_bloginfo('name')); ?>">
			<?php if ( ! empty($s['show_breadcrumbs']) ) : ?><nav class="dnp-breadcrumb"><?php woocommerce_breadcrumb(array('delimiter'=>'<span>›</span>')); ?></nav><?php endif; ?>
			<section class="dnp-hero">
				<div class="dnp-media">
					<?php if($image_id) echo wp_get_attachment_image($image_id,'large',false,array('class'=>'dnp-image','loading'=>'eager','fetchpriority'=>'high','decoding'=>'async','sizes'=>'(max-width:767px) 100vw, 520px','alt'=>$title)); else echo $product->get_image('large',array('class'=>'dnp-image','loading'=>'eager','fetchpriority'=>'high','decoding'=>'async')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
				<div class="dnp-summary">
					<h1><?php echo esc_html($title); ?></h1>
					<?php if(!empty($s['show_short_desc']) && $subtitle): ?><p class="dnp-subtitle"><?php echo esc_html($subtitle); ?></p><?php endif; ?>
					<div class="dnp-meta">
						<?php if(!empty($s['show_price'])): ?><div class="dnp-price" data-dnp-price><?php echo wp_kses_post($product->get_price_html()); ?></div><?php endif; ?>
						<?php if(!empty($s['show_stock'])): $initial_stock_class=$variable?'is-neutral':($product->is_in_stock()?'is-in':'is-out'); $initial_stock_text=$variable?'Sélectionnez une option':($product->is_in_stock()?'Disponible':'Rupture de stock'); ?><span class="dnp-stock <?php echo esc_attr($initial_stock_class); ?>" data-dnp-stock><i aria-hidden="true"></i><span><?php echo esc_html($initial_stock_text); ?></span></span><?php endif; ?>
					</div>
					<?php if(!empty($s['show_trust'])): ?><div class="dnp-trust"><span><?php echo esc_html(self::clean_ui_label((string) $s['trust_1'])); ?></span><span><?php echo esc_html(self::clean_ui_label((string) $s['trust_2'])); ?></span><span><?php echo esc_html(self::clean_ui_label((string) $s['trust_3'])); ?></span><span><?php echo esc_html(self::clean_ui_label((string) $s['trust_4'])); ?></span></div><?php endif; ?>
				</div>
			</section>
			<?php self::render_important($m); ?>
			<section class="dnp-purchase" aria-label="<?php echo esc_attr($s['section_title']); ?>">
				<?php if(trim($s['section_title'])): ?><h2><?php echo esc_html($s['section_title']); ?></h2><?php endif; ?>
				<?php
				$GLOBALS['delicat_native_product_settings']=$s;
				add_filter('woocommerce_product_single_add_to_cart_text',array(__CLASS__,'add_to_cart_text'),999);
				add_action('woocommerce_after_add_to_cart_button',array(__CLASS__,'buy_button'),999);
				woocommerce_template_single_add_to_cart();
				remove_action('woocommerce_after_add_to_cart_button',array(__CLASS__,'buy_button'),999);
				remove_filter('woocommerce_product_single_add_to_cart_text',array(__CLASS__,'add_to_cart_text'),999);
				unset($GLOBALS['delicat_native_product_settings']);
				?>
			</section>
			<?php if(!empty($s['show_steps'])): ?><section class="dnp-section dnp-steps"><h2><?php echo esc_html($s['steps_title']); ?></h2><div class="dnp-steps-grid"><span><b>1</b>Choisissez votre package</span><span><b>2</b>Entrez vos informations</span><span><b>3</b>Vérifiez votre compte</span><span><b>4</b>Payez votre commande</span><span><b>5</b>Recevez votre recharge</span></div></section><?php endif; ?>
			<?php if(!empty($s['show_details']) && ($product->get_description() || $product->get_short_description())): ?><section class="dnp-section dnp-details"><h2><?php echo esc_html($s['details_title']); ?></h2><div class="dnp-copy"><?php echo wp_kses_post(force_balance_tags(wpautop($product->get_description() ?: $product->get_short_description()))); ?></div></section><?php endif; ?>
			<?php if(!empty($s['show_reviews'])) self::render_reviews($product,$s); ?>
			<?php if(!empty($s['show_related'])) self::render_related($product,$s); ?>
		</article>
		<?php if(!empty($s['show_mobile_dock'])): ?><div class="dnp-mobile-dock" data-dnp-dock data-product-id="<?php echo esc_attr($id); ?>" hidden><div class="dnp-dock-price"><small>Total</small><strong data-dnp-dock-price><?php echo $variable?'Choisissez une option':wp_kses_post(wp_strip_all_tags($product->get_price_html())); ?></strong></div><button type="button" data-dnp-proxy="add"><?php echo esc_html($s['add_text']); ?></button><button type="button" class="is-buy" data-dnp-proxy="buy"><?php echo esc_html($s['buy_text']); ?></button></div><?php endif; ?>
		<?php
		$html=(string)ob_get_clean(); wp_reset_postdata(); $post=$old_post; $GLOBALS['product']=$old_product; return $html;
	}

	private static function render_important( array $m ): void {
		if ( empty( $m['important_enabled'] ) ) return;
		$title = trim( (string) ( $m['important_title'] ?? '' ) );
		$content = trim( (string) ( $m['important_content'] ?? '' ) );
		if ( '' === $title && '' === $content ) return;
		$accent = sanitize_hex_color( $m['important_accent'] ?? '' ) ?: '#f59e0b';
		$bg = sanitize_hex_color( $m['important_bg'] ?? '' ) ?: '#fff8eb';
		$text = sanitize_hex_color( $m['important_text'] ?? '' ) ?: '#8a4b08';
		$icon = trim( (string) ( $m['important_icon'] ?? '' ) );
		$style = '--dnp-important-accent:' . $accent . ';--dnp-important-bg:' . $bg . ';--dnp-important-text:' . $text . ';';
		echo '<aside class="dnp-important" style="' . esc_attr( $style ) . '" aria-label="' . esc_attr( $title ?: 'Important' ) . '">';
		echo '<div class="dnp-important-head">';
		if ( '' !== $icon ) echo '<span class="dnp-important-icon" aria-hidden="true">' . esc_html( $icon ) . '</span>';
		if ( '' !== $title ) echo '<strong>' . esc_html( $title ) . '</strong>';
		echo '</div>';
		if ( '' !== $content ) echo '<div class="dnp-important-copy">' . wp_kses_post( force_balance_tags( wpautop( $content ) ) ) . '</div>';
		echo '</aside>';
	}

	/**
	 * RC63: Product structured data. The native template never fired
	 * `woocommerce_single_product_summary`, so WooCommerce's JSON-LD generator
	 * (name, image, offers, aggregateRating, latest reviews) never ran and no
	 * product page carried any structured data — Google had nothing to show
	 * stars from. Queue it here; WooCommerce prints it at wp_footer. Skipped on
	 * fragment/AJAX renders, which have no footer.
	 */
	private static function queue_structured_data( WC_Product $product ): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) return;
		if ( ! function_exists( 'is_product' ) || ! is_product() ) return;
		if ( ! function_exists( 'WC' ) || ! isset( WC()->structured_data ) || ! is_callable( array( WC()->structured_data, 'generate_product_data' ) ) ) return;
		try {
			WC()->structured_data->generate_product_data( $product );
		} catch ( Throwable $error ) {
			unset( $error );
		}
	}

	/**
	 * RC63: approved WooCommerce reviews of this product, visibly on the page —
	 * a condition of Google's review snippets — with the average, the count, a
	 * verified-buyer badge and the popup's "Laisser un avis" button targeting
	 * this product. Identical markup for every visitor (cache-safe).
	 */
	private static function render_reviews( WC_Product $product, array $s ): void {
		$id      = $product->get_id();
		$limit   = max( 1, min( 12, (int) apply_filters( 'delicat_builder_v9_native_reviews_limit', 6 ) ) );
		$reviews = array();
		try {
			$reviews = get_comments( array( 'post_id' => $id, 'type' => 'review', 'status' => 'approve', 'number' => $limit, 'orderby' => 'comment_date_gmt', 'order' => 'DESC' ) );
		} catch ( Throwable $error ) { $reviews = array(); }
		$count   = (int) $product->get_review_count();
		$average = (float) $product->get_average_rating();
		if ( $count <= 0 && $average <= 0 ) { $count = 0; $average = 0.0; }
		$title = trim( (string) ( $s['reviews_title'] ?? '' ) );
		if ( '' === $title ) $title = 'Avis clients';
		$stars_pct = max( 0, min( 100, (int) round( $average / 5 * 100 ) ) );
		$name = $product->get_name();
		?>
		<section class="dnp-section dnp-reviews" id="dnp-reviews" aria-label="<?php echo esc_attr( $title ); ?>">
			<div class="dnp-reviews-head">
				<h2><?php echo esc_html( $title ); ?></h2>
				<?php if ( $count > 0 ) : ?>
				<div class="dnp-reviews-summary">
					<strong class="dnp-reviews-avg"><?php echo esc_html( number_format_i18n( $average, 1 ) ); ?></strong>
					<span class="dnp-reviews-stars" role="img" aria-label="<?php echo esc_attr( sprintf( '%s sur 5', number_format_i18n( $average, 1 ) ) ); ?>"><i aria-hidden="true">★★★★★</i><b aria-hidden="true" style="width:<?php echo esc_attr( $stars_pct ); ?>%">★★★★★</b></span>
					<span class="dnp-reviews-count"><?php echo esc_html( sprintf( _n( '%d avis', '%d avis', $count, 'delicat-builder-v9' ), $count ) ); ?></span>
				</div>
				<?php else : ?>
				<p class="dnp-reviews-empty">Aucun avis pour l’instant — sois le premier à noter ce produit.</p>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $reviews ) ) : ?>
			<ul class="dnp-reviews-list">
				<?php foreach ( (array) $reviews as $review ) : if ( ! $review instanceof WP_Comment ) continue;
					$text = trim( wp_strip_all_tags( (string) $review->comment_content ) );
					if ( '' === $text ) continue;
					$rating   = max( 1, min( 5, absint( get_comment_meta( (int) $review->comment_ID, 'rating', true ) ) ?: 5 ) );
					$verified = '1' === (string) get_comment_meta( (int) $review->comment_ID, 'verified', true );
					$author   = trim( (string) $review->comment_author );
					if ( '' === $author ) $author = 'Client Delicat';
					$initials = '';
					foreach ( array_slice( (array) preg_split( '/\s+/', $author ), 0, 2 ) as $word ) { if ( '' !== $word ) $initials .= function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1 ) : substr( $word, 0, 1 ); }
					$initials = strtoupper( '' !== $initials ? $initials : 'DS' );
					$city = trim( (string) get_comment_meta( (int) $review->comment_ID, '_dlc_review_city', true ) );
				?>
				<li class="dnp-review">
					<div class="dnp-review-top">
						<span class="dnp-review-avatar" aria-hidden="true"><?php echo esc_html( $initials ); ?></span>
						<div class="dnp-review-id"><strong><?php echo esc_html( $author ); ?></strong><span class="dnp-review-meta"><?php if ( $verified ) : ?><em class="dnp-review-verified">✓ Achat vérifié</em> · <?php endif; ?><?php if ( '' !== $city ) : ?><?php echo esc_html( $city ); ?> · <?php endif; ?><time datetime="<?php echo esc_attr( get_comment_date( 'c', $review ) ); ?>"><?php echo esc_html( get_comment_date( 'j M Y', $review ) ); ?></time></span></div>
						<span class="dnp-review-stars" aria-label="<?php echo esc_attr( $rating . ' sur 5' ); ?>"><?php echo esc_html( str_repeat( '★', $rating ) ); ?></span>
					</div>
					<p><?php echo esc_html( $text ); ?></p>
				</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
			<div class="dnp-reviews-cta">
				<button type="button" class="dnp-reviews-btn" data-dlc-review-open data-dlc-review-product="<?php echo esc_attr( $id ); ?>" data-dlc-review-product-name="<?php echo esc_attr( $name ); ?>">Laisser un avis</button>
				<span>Ton avis apparaît ici et sur la page d’accueil après validation.</span>
			</div>
		</section>
		<?php
	}

	private static function render_related( WC_Product $product, array $s ): void {
		$ids = wc_get_related_products($product->get_id(),4,array($product->get_id())); if(!$ids)return;
		echo '<section class="dnp-section dnp-related"><h2>'.esc_html($s['related_title']).'</h2><div class="dnp-related-grid">';
		foreach($ids as $rid){$rp=wc_get_product($rid);if(!$rp)continue;echo '<a class="dnp-related-card" href="'.esc_url($rp->get_permalink()).'">'.$rp->get_image('woocommerce_thumbnail',array('loading'=>'lazy','decoding'=>'async')).'<strong>'.esc_html($rp->get_name()).'</strong><span>'.wp_kses_post($rp->get_price_html()).'</span></a>';}
		echo '</div></section>';
	}

	public static function add_to_cart_text( $text ) { if(!is_string($text))return $text; $s=$GLOBALS['delicat_native_product_settings']??self::settings(); return !empty($s['add_text'])?$s['add_text']:$text; }
	public static function buy_button(): void { $s=$GLOBALS['delicat_native_product_settings']??self::settings(); echo '<button type="submit" class="button alt dnp-buy-now" name="delicat_native_buy_now" value="1">'.esc_html($s['buy_text']).'</button>'; }
	/* RC18: Woo passes `false` as the default redirect; a string type hint was a guaranteed PHP 8 TypeError. */
	public static function buy_now_redirect( $url ) { if(!empty($_REQUEST['dpn_express']))return $url; if(isset($_POST['delicat_native_buy_now']) && '1' === sanitize_text_field(wp_unslash($_POST['delicat_native_buy_now']))) return wc_get_checkout_url(); return $url; } // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Woo add-to-cart owns validation; this flag only selects the post-add redirect.

	public static function assets(): void {
		if(!function_exists('is_product')||!is_product())return; $id=absint(get_queried_object_id()); if(!self::is_active_product($id))return;
		$css=DELICAT_BUILDER_V9_DIR.'assets/css/native-product.css'; $js=DELICAT_BUILDER_V9_DIR.'assets/js/native-product.js';
		wp_enqueue_style('delicat-v9-native-product',DELICAT_BUILDER_V9_URL.'assets/css/native-product.css',array(),DELICAT_BUILDER_V9_VERSION);
		/* 9.1 RC1: layout-fix layer merged into native-product.css (identical cascade, one fewer request). */
		wp_enqueue_script('delicat-v9-native-product',DELICAT_BUILDER_V9_URL.'assets/js/native-product.js',array('jquery'),DELICAT_BUILDER_V9_VERSION,true);
		if(function_exists('wp_script_add_data'))wp_script_add_data('delicat-v9-native-product','strategy','defer');
	}

	public static function slim_assets(): void {
		if(!function_exists('is_product')||!is_product()||!self::is_active_product(absint(get_queried_object_id())))return;
		// Native document does not render Elementor, a Woo gallery slider, zoom or Photoswipe.
		foreach(array('elementor-frontend','elementor-pro','elementor-icons','eicons','photoswipe','photoswipe-default-skin','woocommerce_prettyPhoto_css','flexslider') as $h)wp_dequeue_style($h);
		foreach(array('elementor-frontend','elementor-pro','imagesloaded','zoom','flexslider','photoswipe','photoswipe-ui-default','wc-single-product') as $h)wp_dequeue_script($h);
		// The native header/cart drawer has its own lightweight refresh path; avoid Woo's eager fragments poll on this document.
		if ( ! is_user_logged_in() && empty($_COOKIE['woocommerce_items_in_cart']) && empty($_COOKIE['wp_woocommerce_session_'.COOKIEHASH]) ) wp_dequeue_script('wc-cart-fragments');
	}

	public static function fragment_response(): void {
		if(empty($_GET['delicat_product_fragment'])||'1'!==sanitize_text_field(wp_unslash($_GET['delicat_product_fragment'])))return; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only public fragment.
		if(!function_exists('is_product')||!is_product())return; $id=absint(get_queried_object_id()); if(!self::is_active_product($id))return;
		if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true); nocache_headers();
		header('Content-Type: text/html; charset='.get_option('blog_charset'));
		echo self::render($id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	public static function cache_policy(): void {
		if(!function_exists('is_product')||!is_product()||!self::is_active_product(absint(get_queried_object_id())))return;
		$clean=class_exists('Delicat_Builder_V9_Security',false)&&is_callable(array('Delicat_Builder_V9_Security','public_cache_allowed'))&&Delicat_Builder_V9_Security::public_cache_allowed();
		if($clean){do_action('litespeed_control_set_cacheable','Delicat V9 native product');if(!headers_sent())header('X-Delicat-V9-Product-Cache: public');return;}
		if(class_exists('Delicat_Builder_V9_Security',false)&&is_callable(array('Delicat_Builder_V9_Security','private_cache_allowed'))&&Delicat_Builder_V9_Security::private_cache_allowed()){Delicat_Builder_V9_Security::hint_private_cache('Delicat V9 native product (signed-in)');return;} /* RC32 */
		if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true); do_action('litespeed_control_set_nocache','Delicat V9 private product session');
	}

	public static function admin_page(): void {
		if(!current_user_can('manage_woocommerce')&&!current_user_can('manage_options'))return; $s=self::settings();
		?>
		<div class="wrap"><h1>Native Product Builder</h1><p><strong>V9 native single-product engine.</strong> No Elementor template is used. WooCommerce owns price, stock, variations, cart and checkout; Swatches and Product Fields keep their WooCommerce hooks.</p><?php if(!empty($_GET['saved']))echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>'; ?>
		<?php self::express_diagnostics(); ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="delicat_builder_v9_native_product_save"><?php wp_nonce_field('delicat_builder_v9_native_product_save'); ?>
		<table class="form-table"><tbody>
		<tr><th>Activation</th><td><label><input type="checkbox" name="dnp[enabled]" value="1" <?php checked($s['enabled']); ?>> Activer le moteur produit natif</label><br><label><input type="checkbox" name="dnp[force_native_template]" value="1" <?php checked($s['force_native_template']); ?>> Remplacer totalement le template produit WordPress/Elementor</label></td></tr>
		<tr><th>Sections</th><td><?php foreach(array('show_breadcrumbs'=>'Fil d’Ariane','show_short_desc'=>'Description courte','show_price'=>'Prix inline (desktop)','show_stock'=>'Stock','show_details'=>'Détails','show_related'=>'Produits associés','show_trust'=>'Confiance','show_steps'=>'Comment ça marche','show_mobile_dock'=>'Barre achat mobile','mobile_floating_only'=>'Mobile : boutons flottants uniquement','hide_quantity'=>'Masquer la quantité (produits numériques)','show_reviews'=>'Avis clients (WooCommerce) + bouton « Laisser un avis »') as $k=>$l): ?><label style="display:inline-block;margin:0 18px 8px 0"><input type="checkbox" name="dnp[<?php echo esc_attr($k); ?>]" value="1" <?php checked($s[$k]); ?>> <?php echo esc_html($l); ?></label><?php endforeach; ?></td></tr>
		<tr><th>Commande express</th><td><label><input type="checkbox" name="dnp[express_checkout]" value="1" <?php checked($s['express_checkout']); ?>> Activer sur tous les produits : « Acheter maintenant » ouvre une feuille de confirmation WooCommerce (clients connectés)</label><br><span style="color:#646970">Chaque fiche produit peut forcer Activée / Désactivée dans son panneau « Delicat native ».</span></td></tr>
		<tr><th>Largeur / rayon</th><td><input type="number" min="720" max="1440" name="dnp[max_width]" value="<?php echo esc_attr($s['max_width']); ?>"> px &nbsp; <input type="number" min="10" max="40" name="dnp[radius]" value="<?php echo esc_attr($s['radius']); ?>"> px</td></tr>
		<tr><th>Couleurs</th><td><?php foreach(array('page_bg'=>'Fond','card_bg'=>'Carte','text'=>'Texte','muted'=>'Secondaire','primary'=>'Primaire','accent'=>'Achat','line'=>'Bordure') as $k=>$l): ?><label style="display:inline-flex;gap:6px;align-items:center;margin:0 14px 10px 0"><?php echo esc_html($l); ?><input type="color" name="dnp[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($s[$k]); ?>"></label><?php endforeach; ?></td></tr>
		<tr><th>Textes</th><td><?php foreach(array('section_title'=>'Titre options','details_title'=>'Titre détails','related_title'=>'Titre associés','steps_title'=>'Titre étapes','reviews_title'=>'Titre avis','add_text'=>'Ajouter','buy_text'=>'Acheter','trust_1'=>'Confiance 1','trust_2'=>'Confiance 2','trust_3'=>'Confiance 3','trust_4'=>'Confiance 4') as $k=>$l): ?><label style="display:block;max-width:620px;margin:8px 0"><?php echo esc_html($l); ?><input class="regular-text" type="text" name="dnp[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($s[$k]); ?>"></label><?php endforeach; ?></td></tr>
		</tbody></table><?php submit_button('Enregistrer'); ?></form></div><?php
	}

	/** RC22: shows exactly which condition blocks the express sheet, plus the last storefront attempt. */
	public static function express_diagnostics(): void {
		$status = self::express_status();
		$last   = get_transient( 'delicat_builder_v9_express_last' );
		$last   = is_array( $last ) ? $last : array();
		$states = array(
			'rendered' => array( 'ok', 'Feuille express prête sur la fiche produit consultée.' ),
			'no_form'  => array( 'bad', 'WooCommerce n’a pas renvoyé son formulaire de commande (extension de checkout tierce ?).' ),
			'blocked'  => array( 'bad', 'Purchase Studio ou le checkout natif est désactivé.' ),
			'off'      => array( 'warn', 'Commande express désactivée pour ce produit.' ),
			'guest'    => array( 'warn', 'Dernière visite non connectée : page checkout normale.' ),
			'missing'  => array( 'bad', 'Module express introuvable dans cette installation.' ),
		);
		?>
		<div class="notice <?php echo $status['ok'] ? 'notice-success' : 'notice-warning'; ?>" style="padding:12px 14px;margin:16px 0">
			<h2 style="margin:0 0 8px;font-size:15px">Commande express — diagnostic</h2>
			<ul style="margin:0 0 6px;padding:0;list-style:none">
				<?php foreach ( $status['checks'] as $check ) : ?>
					<li style="margin:0 0 4px"><span style="color:<?php echo ! empty( $check[1] ) ? '#00844a' : '#b32d2e'; ?>;font-weight:700"><?php echo ! empty( $check[1] ) ? '✓' : '✕'; ?></span>
						<strong><?php echo esc_html( $check[0] ); ?></strong><?php if ( empty( $check[1] ) ) : ?> — <span style="color:#646970"><?php echo esc_html( $check[2] ); ?></span><?php endif; ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( ! empty( $last['state'] ) ) :
				$info = $states[ $last['state'] ] ?? array( 'warn', $last['state'] ); ?>
				<p style="margin:8px 0 0;color:#646970">Dernière fiche produit consultée : #<?php echo absint( $last['product'] ?? 0 ); ?>,
					<?php echo esc_html( human_time_diff( absint( $last['time'] ?? time() ) ) ); ?> — <?php echo esc_html( $info[1] ); ?>
					<?php if ( ! empty( $last['detail'] ) && 'rendered' !== $last['state'] ) : ?><br><em><?php echo esc_html( $last['detail'] ); ?></em><?php endif; ?>
					<?php if ( ! empty( $last['version'] ) && DELICAT_BUILDER_V9_VERSION !== $last['version'] ) : ?><br><em>Enregistré par la version <?php echo esc_html( $last['version'] ); ?> — rechargez la fiche produit après la mise à jour.</em><?php endif; ?>
				</p>
			<?php else : ?>
				<p style="margin:8px 0 0;color:#646970">Aucune visite enregistrée. Ouvrez une fiche produit sur le site (connecté), puis rechargez cette page.</p>
			<?php endif; ?>
			<p style="margin:8px 0 0;color:#646970">La feuille express n’est proposée qu’aux clients connectés ; les visiteurs gardent la page checkout. Pensez à purger LiteSpeed après un changement.</p>
		</div>
		<?php
	}

	public static function save_settings(): void {
		if(!current_user_can('manage_woocommerce')&&!current_user_can('manage_options'))wp_die('Permission refusée.'); check_admin_referer('delicat_builder_v9_native_product_save');
		$in=isset($_POST['dnp'])&&is_array($_POST['dnp'])?wp_unslash($_POST['dnp']):array(); $d=self::defaults(); $o=$d;
		foreach(array('enabled','force_native_template','show_breadcrumbs','show_short_desc','show_price','show_stock','show_details','show_related','show_trust','show_steps','show_mobile_dock','mobile_floating_only','hide_quantity','express_checkout','show_reviews') as $k)$o[$k]=empty($in[$k])?0:1;
		$o['max_width']=min(1440,max(720,absint($in['max_width']??$d['max_width']))); $o['radius']=min(40,max(10,absint($in['radius']??$d['radius'])));
		foreach(array('page_bg','card_bg','text','muted','primary','accent','line') as $k)$o[$k]=sanitize_hex_color($in[$k]??'')?:$d[$k];
		foreach(array('section_title','details_title','related_title','steps_title','reviews_title','add_text','buy_text','trust_1','trust_2','trust_3','trust_4') as $k)$o[$k]=sanitize_text_field($in[$k]??$d[$k]);
		update_option(self::OPTION,$o,false); if(class_exists('Delicat_Builder_V9_Cache',false)&&is_callable(array('Delicat_Builder_V9_Cache','bump_version')))Delicat_Builder_V9_Cache::bump_version();
		wp_safe_redirect(admin_url('admin.php?page=delicat-builder-v9-native-product&saved=1'));exit;
	}


	public static function add_meta_box(): void {
		add_meta_box('delicat-native-product','Native Product Builder',array(__CLASS__,'meta_box'),'product','side','high');
		add_meta_box('delicat-native-product-important','Delicat — Important / À savoir',array(__CLASS__,'important_meta_box'),'product','normal','high');
	}
	public static function meta_box(WP_Post $post): void { $m=self::product_settings($post->ID);wp_nonce_field('delicat_native_product_save','delicat_native_product_nonce'); ?><p><label>Rendu natif<select name="dnp_product[enabled]" style="width:100%"><option value="inherit" <?php selected($m['enabled'],'inherit'); ?>>Hériter du global</option><option value="1" <?php selected($m['enabled'],'1'); ?>>Activé</option><option value="0" <?php selected($m['enabled'],'0'); ?>>Désactivé</option></select></label></p><p><label>Titre personnalisé<input style="width:100%" type="text" name="dnp_product[title]" value="<?php echo esc_attr($m['title']); ?>"></label></p><p><label>Sous-titre<textarea style="width:100%" rows="3" name="dnp_product[subtitle]"><?php echo esc_textarea($m['subtitle']); ?></textarea></label></p><p><label>Commande express<select name="dnp_product[express_checkout]" style="width:100%"><option value="inherit" <?php selected(in_array($m['express_checkout'],array('inherit','','0'),true)); ?>>Hériter du réglage global</option><option value="1" <?php selected($m['express_checkout'],'1'); ?>>Activée</option><option value="off" <?php selected($m['express_checkout'],'off'); ?>>Désactivée</option></select></label><span style="color:#646970">« Acheter maintenant » ouvre la feuille de confirmation WooCommerce (clients connectés) au lieu de la page checkout.</span></p><?php $st=self::express_status($post->ID); ?><p style="padding:8px 10px;border-radius:6px;background:<?php echo $st['ok']?'#edfaef':'#fcf0f1'; ?>"><strong><?php echo $st['ok']?'Express actif sur ce produit':'Express inactif'; ?></strong><?php if(!$st['ok']): foreach($st['checks'] as $c){ if(empty($c[1])){ echo '<br><span style="color:#646970">'.esc_html($c[2]).'</span>'; break; } } endif; ?></p><?php }
	public static function important_meta_box(WP_Post $post): void {
		$m=self::product_settings($post->ID);
		?>
		<p><label><input type="checkbox" name="dnp_product[important_enabled]" value="1" <?php checked($m['important_enabled'],'1'); ?>> <strong>Afficher la section Important sur ce produit</strong></label></p>
		<p style="color:#646970;margin-top:-4px">Rendue directement en PHP juste après le hero : aucun JavaScript supplémentaire.</p>
		<div style="display:grid;grid-template-columns:minmax(100px,.35fr) minmax(220px,1fr);gap:12px;max-width:900px">
			<label>Icône<input class="widefat" type="text" maxlength="12" name="dnp_product[important_icon]" value="<?php echo esc_attr($m['important_icon']); ?>" placeholder="ⓘ"></label>
			<label>Titre<input class="widefat" type="text" maxlength="80" name="dnp_product[important_title]" value="<?php echo esc_attr($m['important_title']); ?>" placeholder="À savoir"></label>
		</div>
		<p><label>Contenu<textarea class="widefat" rows="5" name="dnp_product[important_content]" placeholder="Ex. Recharge Free Fire LATAM via Player ID. Vérifiez votre région avant de commander."><?php echo esc_textarea($m['important_content']); ?></textarea></label></p>
		<p style="color:#646970">HTML simple autorisé : <code>&lt;strong&gt;</code>, <code>&lt;em&gt;</code>, liens, listes et retours à la ligne.</p>
		<div style="display:flex;gap:18px;flex-wrap:wrap;align-items:end">
			<label>Accent<br><input type="color" name="dnp_product[important_accent]" value="<?php echo esc_attr($m['important_accent']); ?>"></label>
			<label>Fond<br><input type="color" name="dnp_product[important_bg]" value="<?php echo esc_attr($m['important_bg']); ?>"></label>
			<label>Texte<br><input type="color" name="dnp_product[important_text]" value="<?php echo esc_attr($m['important_text']); ?>"></label>
		</div>
		<?php
	}
	public static function save_product(int $id,WP_Post $post,bool $update): void {
		unset($post,$update);
		if(empty($_POST['delicat_native_product_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['delicat_native_product_nonce'])),'delicat_native_product_save')||!current_user_can('edit_post',$id)||wp_is_post_revision($id))return;
		$in=isset($_POST['dnp_product'])&&is_array($_POST['dnp_product'])?wp_unslash($_POST['dnp_product']):array();
		$m=self::product_settings($id);
		$m['enabled']=in_array($in['enabled']??'inherit',array('inherit','0','1'),true)?$in['enabled']:'inherit';
		$m['title']=sanitize_text_field($in['title']??'');
		$m['subtitle']=sanitize_textarea_field($in['subtitle']??'');
		$m['express_checkout']=in_array($in['express_checkout']??'inherit',array('inherit','1','off'),true)?$in['express_checkout']:'inherit';
		$m['important_enabled']=empty($in['important_enabled'])?'0':'1';
		$m['important_icon']=sanitize_text_field($in['important_icon']??'ⓘ');
		$m['important_title']=sanitize_text_field($in['important_title']??'À savoir');
		$m['important_content']=wp_kses_post($in['important_content']??'');
		foreach(array('important_accent'=>'#f59e0b','important_bg'=>'#fff8eb','important_text'=>'#8a4b08') as $key=>$fallback)$m[$key]=sanitize_hex_color($in[$key]??'')?:$fallback;
		update_post_meta($id,self::META,$m);
	}


	/** Read-only one-time migration from deleted Product Builder 7.4 settings. */
	public static function migrate_v74(): void {
		$current=get_option(self::OPTION,null); if(is_array($current)&&$current)return; $old=get_option('dsb_spb_v7411_settings',null); if(!is_array($old))$old=get_option('delicat_builder_v9_spb_v7411_settings',array()); if(!is_array($old)||!$old)return;
		$d=self::defaults(); $map=array_intersect_key($old,$d); if(isset($old['enabled']))$map['enabled']=!empty($old['enabled'])?1:0; update_option(self::OPTION,wp_parse_args($map,$d),false);
	}
}
