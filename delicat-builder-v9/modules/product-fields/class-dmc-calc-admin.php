<?php
/**
 * Cost Calculator / Product Fields — visual builder (product admin).
 *
 * @package DeliMultiCurrency
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Delicat_Builder_V9_Product_Fields_Admin {

	const TYPES       = array( 'number', 'range', 'select', 'radio', 'checkbox', 'toggle', 'text', 'textarea', 'email', 'tel', 'password', 'url', 'date', 'hidden', 'heading', 'content' );
	const APPEARANCES = array( 'default', 'buttons', 'color', 'image', 'cards' );
	const PRICE_TYPES = array( 'fixed', 'percent' );
	const OPS         = array( '==', '!=', '>', '<', '>=', '<=', 'contains', 'not_contains' );

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'metabox' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'dmc-calc-builder', DELICAT_BUILDER_V9_URL . 'modules/product-fields/assets/css/calc.css', array(), DELICAT_BUILDER_V9_VERSION );
		wp_enqueue_script( 'dmc-calc-builder', DELICAT_BUILDER_V9_URL . 'modules/product-fields/assets/js/calc-builder.js', array( 'jquery', 'jquery-ui-sortable' ), DELICAT_BUILDER_V9_VERSION, true );
		wp_localize_script(
			'dmc-calc-builder',
			'DMCBuilder',
			array(
				'types'       => self::TYPES,
				'appearances' => self::APPEARANCES,
				'priceTypes'  => self::PRICE_TYPES,
				'ops'         => self::OPS,
			)
		);
	}

	public function metabox() {
		add_meta_box( 'dmc-calc', __( 'Product Fields & Calculator (Deli)', 'deli-multi-currency' ), array( $this, 'render' ), 'product', 'normal', 'default' );
	}

	public function render( $post ) {
		$cfg = get_post_meta( $post->ID, Delicat_Builder_V9_Product_Fields_Calculator::META, true );
		if ( ! is_array( $cfg ) ) {
			$cfg = json_decode( (string) $cfg, true );
		}
		if ( ! is_array( $cfg ) ) {
			$cfg = array( 'enabled' => false, 'formula' => '', 'fields' => array(), 'design' => array() );
		}
		wp_nonce_field( 'dmc_calc_save', 'dmc_calc_nonce' );
		$no_convert  = get_post_meta( $post->ID, '_dmc_no_convert', true );
		$custom_rate = get_post_meta( $post->ID, '_dmc_custom_rate', true );
		?>
		<div class="dmc-builder">
			<p><label><input type="checkbox" name="dmc_calc_enabled" value="yes" <?php checked( ! empty( $cfg['enabled'] ) ); ?>> <strong><?php esc_html_e( 'Enable fields on this product', 'deli-multi-currency' ); ?></strong></label></p>

			<p class="dmc-perproduct">
				<label><input type="checkbox" name="dmc_no_convert" value="yes" <?php checked( 'yes', $no_convert ); ?>> <?php esc_html_e( 'Disable automatic currency conversion for this product (price stays fixed)', 'deli-multi-currency' ); ?></label><br>
				<label><?php esc_html_e( 'Custom product rate', 'deli-multi-currency' ); ?> <input type="number" step="any" name="dmc_custom_rate" value="<?php echo esc_attr( $custom_rate ); ?>" class="small-text" placeholder="142"></label>
				<span class="description"><?php esc_html_e( 'Optional. Use it in the formula as {rate}, e.g. {quantity} * {rate}. Leave blank to ignore (defaults to 1).', 'deli-multi-currency' ); ?></span>
			</p>
			<p class="description"><?php esc_html_e( 'Add fields, drag the ☰ handle to reorder, set per-field/option pricing (fixed amount or % of base), conditional visibility and display style. Reference any field in the formula as {field_id}; the product price is {base}.', 'deli-multi-currency' ); ?></p>
			<div class="dmc-design-panel">
				<h3>✨ Design premium des champs</h3>
				<p class="description">Personnalisez la carte, les coins, couleurs, ombres, taille, icônes d’aide et popup. Ces réglages sont propres à ce produit.</p>
				<div class="dmc-design-grid">
					<label>Style <select class="dmc-design" data-key="preset"><option value="modern">Modern</option><option value="glass">Glass</option><option value="apple">Apple</option><option value="gaming">Gaming Neon</option><option value="minimal">Minimal</option></select></label>
					<label>Coins carte (px) <input class="dmc-design" data-key="card_radius" type="number" min="0" max="60"></label>
					<label>Coins champs (px) <input class="dmc-design" data-key="field_radius" type="number" min="0" max="60"></label>
					<label>Hauteur champs (px) <input class="dmc-design" data-key="field_height" type="number" min="42" max="90"></label>
					<label>Espace entre champs (px) <input class="dmc-design" data-key="gap" type="number" min="4" max="40"></label>
					<label>Espace entre sections (px) <input class="dmc-design" data-key="section_spacing" type="number" min="4" max="48"></label>
					<label>Espace quantité (px) <input class="dmc-design" data-key="quantity_spacing" type="number" min="4" max="48"></label>
					<label>Padding carte (px) <input class="dmc-design" data-key="card_padding" type="number" min="6" max="40"></label>
					<label>Padding résumé (px) <input class="dmc-design" data-key="summary_padding" type="number" min="8" max="40"></label>
					<label>Fond carte <input class="dmc-design" data-key="card_bg" type="color"></label>
					<label>Fond champ <input class="dmc-design" data-key="field_bg" type="color"></label>
					<label>Couleur texte <input class="dmc-design" data-key="text" type="color"></label>
					<label>Bordure <input class="dmc-design" data-key="border" type="color"></label>
					<label>Accent / focus <input class="dmc-design" data-key="accent" type="color"></label>
					<label>Bouton aide <input class="dmc-design" data-key="help_bg" type="color"></label>
					<label>Libellé du total <input class="dmc-design" data-key="total_label" type="text" maxlength="60" placeholder="Total à payer"></label>
					<label>Style du total <select class="dmc-design" data-key="total_style"><option value="card">Carte intégrée</option><option value="line">Ligne simple</option><option value="highlight">Mise en évidence</option></select></label>
					<label>Coins du total (px) <input class="dmc-design" data-key="total_radius" type="number" min="0" max="50"></label>
					<label>Fond du total <input class="dmc-design" data-key="total_bg" type="color"></label>
					<label><input class="dmc-design" data-key="help_glow" type="checkbox"> Animation lumineuse du bouton aide</label>
					<label><input class="dmc-design" data-key="shadow" type="checkbox"> Ombre premium</label>
					<label><input class="dmc-design" data-key="floating_labels" type="checkbox"> Labels flottants</label>
					<label><input class="dmc-design" data-key="show_total" type="checkbox"> Afficher le total calculé</label>
					<label><input class="dmc-design" data-key="purchase_studio" type="checkbox"> Activer Product Purchase Studio</label>
					<label><input class="dmc-design" data-key="show_product_name" type="checkbox"> Afficher le nom du produit dans le résumé</label>
					<label><input class="dmc-design" data-key="show_trust_chips" type="checkbox"> Intégrer les garanties dans la carte</label>
					<label><input class="dmc-design" data-key="animate_total" type="checkbox"> Animer le plan et le total</label>
				</div>
			</div>
			<div class="dmc-template-panel">
				<h3>⚡ Modèles rapides</h3>
				<p class="description">Ajoutez une structure prête à l'emploi puis personnalisez-la.</p>
				<div class="dmc-template-actions">
					<button type="button" class="button dmc-template" data-template="freefire">Free Fire</button>
					<button type="button" class="button dmc-template" data-template="gaming">Jeu / Player ID</button>
					<button type="button" class="button dmc-template" data-template="giftcard">Gift Card</button>
					<button type="button" class="button dmc-template" data-template="subscription">Abonnement / E-mail</button>
					<button type="button" class="button dmc-template" data-template="netflix">Netflix</button>
					<button type="button" class="button dmc-template" data-template="dls">DLS</button>
					<button type="button" class="button dmc-template" data-template="codm">COD Mobile</button>
					<button type="button" class="button dmc-template" data-template="wallet">Wallet / Paiement</button>
				</div>
			</div>
			<div id="dmc-builder-fields"></div>
			<p><button type="button" class="button button-secondary" id="dmc-builder-add"><?php esc_html_e( '+ Add field', 'deli-multi-currency' ); ?></button></p>
			<p>
				<label for="dmc-formula"><strong><?php esc_html_e( 'Price formula (optional)', 'deli-multi-currency' ); ?></strong></label><br>
				<input type="text" id="dmc-formula" name="dmc_calc_formula" class="widefat code" value="<?php echo esc_attr( isset( $cfg['formula'] ) ? $cfg['formula'] : '' ); ?>" placeholder="base + {width} * {height} * 50">
				<span class="description"><?php esc_html_e( 'Blank = product price plus every field added together. Allowed: + - * / % ^ ( ) and min, max, round, ceil, floor, abs, sqrt, pow.', 'deli-multi-currency' ); ?></span>
			</p>
			<textarea id="dmc-calc-config" name="dmc_calc_config" style="display:none"><?php echo esc_textarea( wp_json_encode( $cfg ) ); ?></textarea>
		</div>
		<?php
	}

	public function save( $product ) {
		if ( ! isset( $_POST['dmc_calc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['dmc_calc_nonce'] ) ), 'dmc_calc_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $product->get_id() ) ) {
			return;
		}

		// Per-product currency controls.
		$product->update_meta_data( '_dmc_no_convert', ! empty( $_POST['dmc_no_convert'] ) ? 'yes' : 'no' );
		if ( isset( $_POST['dmc_custom_rate'] ) && is_numeric( wp_unslash( $_POST['dmc_custom_rate'] ) ) ) {
			$product->update_meta_data( '_dmc_custom_rate', (float) wp_unslash( $_POST['dmc_custom_rate'] ) );
		} else {
			$product->delete_meta_data( '_dmc_custom_rate' );
		}
		$json = isset( $_POST['dmc_calc_config'] ) ? wp_unslash( $_POST['dmc_calc_config'] ) : ''; // phpcs:ignore
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$clean = $this->sanitize_config(
			array(
				'enabled' => ! empty( $_POST['dmc_calc_enabled'] ),
				'formula' => isset( $_POST['dmc_calc_formula'] ) ? wp_unslash( $_POST['dmc_calc_formula'] ) : ( $data['formula'] ?? '' ), // phpcs:ignore
				'fields'  => isset( $data['fields'] ) ? $data['fields'] : array(),
				'design'  => isset( $data['design'] ) ? $data['design'] : array(),
			)
		);
		if ( empty( $clean['fields'] ) && ! $clean['enabled'] ) {
			$product->delete_meta_data( Delicat_Builder_V9_Product_Fields_Calculator::META );
		} else {
			$product->update_meta_data( Delicat_Builder_V9_Product_Fields_Calculator::META, $clean );
		}
	}

	public function sanitize_config( $data ) {
		$out = array(
			'enabled' => ! empty( $data['enabled'] ),
			'formula' => '',
			'fields'  => array(),
			'design'  => array(),
		);

		$d = isset( $data['design'] ) && is_array( $data['design'] ) ? $data['design'] : array();
		$out['design'] = array(
			'preset' => in_array( $d['preset'] ?? 'modern', array( 'modern','glass','apple','gaming','minimal' ), true ) ? $d['preset'] : 'modern',
			'card_radius' => min( 60, max( 0, absint( $d['card_radius'] ?? 24 ) ) ),
			'field_radius' => min( 60, max( 0, absint( $d['field_radius'] ?? 18 ) ) ),
			'field_height' => min( 90, max( 42, absint( $d['field_height'] ?? 58 ) ) ),
			'gap' => min( 40, max( 4, absint( $d['gap'] ?? 14 ) ) ),
			'section_spacing' => min( 48, max( 4, absint( $d['section_spacing'] ?? 18 ) ) ),
			'quantity_spacing' => min( 48, max( 4, absint( $d['quantity_spacing'] ?? 18 ) ) ),
			'card_padding' => min( 40, max( 6, absint( $d['card_padding'] ?? 14 ) ) ),
			'summary_padding' => min( 40, max( 8, absint( $d['summary_padding'] ?? 16 ) ) ),
			'card_bg' => sanitize_hex_color( $d['card_bg'] ?? '#ffffff' ) ?: '#ffffff',
			'field_bg' => sanitize_hex_color( $d['field_bg'] ?? '#ffffff' ) ?: '#ffffff',
			'text' => sanitize_hex_color( $d['text'] ?? '#111827' ) ?: '#111827',
			'border' => sanitize_hex_color( $d['border'] ?? '#dbe2ea' ) ?: '#dbe2ea',
			'accent' => sanitize_hex_color( $d['accent'] ?? '#d9a441' ) ?: '#d9a441',
			'help_bg' => sanitize_hex_color( $d['help_bg'] ?? '#ffd43b' ) ?: '#ffd43b',
			'shadow' => ! empty( $d['shadow'] ),
			'floating_labels' => ! empty( $d['floating_labels'] ),
			'show_total' => ! array_key_exists( 'show_total', $d ) || ! empty( $d['show_total'] ),
			'total_style' => in_array( $d['total_style'] ?? 'card', array( 'card','line','highlight' ), true ) ? $d['total_style'] : 'card',
			'total_radius' => min( 50, max( 0, absint( $d['total_radius'] ?? 18 ) ) ),
			'total_bg' => sanitize_hex_color( $d['total_bg'] ?? '#ffffff' ) ?: '#ffffff',
			'total_label' => isset( $d['total_label'] ) ? substr( sanitize_text_field( $d['total_label'] ), 0, 60 ) : 'Total',
			'help_glow' => ! array_key_exists( 'help_glow', $d ) || ! empty( $d['help_glow'] ),
			'purchase_studio' => ! array_key_exists( 'purchase_studio', $d ) || ! empty( $d['purchase_studio'] ),
			'show_product_name' => ! array_key_exists( 'show_product_name', $d ) || ! empty( $d['show_product_name'] ),
			'show_trust_chips' => false,
			'animate_total' => ! array_key_exists( 'animate_total', $d ) || ! empty( $d['animate_total'] ),
		);

		$formula        = isset( $data['formula'] ) ? substr( (string) $data['formula'], 0, 2000 ) : '';
		$out['formula'] = preg_replace( '/[^0-9A-Za-z_.\+\-\*\/%\^(),{}\s]/', '', $formula );

		$used_ids = array();
		$fields   = ( isset( $data['fields'] ) && is_array( $data['fields'] ) ) ? $data['fields'] : array();

		foreach ( $fields as $f ) {
			if ( ! is_array( $f ) ) {
				continue;
			}
			$type = ( isset( $f['type'] ) && in_array( $f['type'], self::TYPES, true ) ) ? $f['type'] : 'number';

			$id = isset( $f['id'] ) ? sanitize_key( $f['id'] ) : '';
			if ( '' === $id || isset( $used_ids[ $id ] ) ) {
				$id = 'f' . substr( md5( uniqid( '', true ) ), 0, 6 );
			}
			$used_ids[ $id ] = true;

			$field = array(
				'id'              => $id,
				'type'            => $type,
				'label'           => isset( $f['label'] ) ? sanitize_text_field( $f['label'] ) : '',
				'description'     => isset( $f['description'] ) ? sanitize_text_field( $f['description'] ) : '',
				'required'        => ! empty( $f['required'] ),
				'price'           => ( isset( $f['price'] ) && is_numeric( $f['price'] ) ) ? (float) $f['price'] : 0.0,
				'price_type'      => ( isset( $f['price_type'] ) && in_array( $f['price_type'], self::PRICE_TYPES, true ) ) ? $f['price_type'] : 'fixed',
				'appearance'      => ( isset( $f['appearance'] ) && in_array( $f['appearance'], self::APPEARANCES, true ) ) ? $f['appearance'] : 'default',
				'default'         => isset( $f['default'] ) ? sanitize_text_field( (string) $f['default'] ) : '',
				'toggle_text'     => isset( $f['toggle_text'] ) ? sanitize_text_field( (string) $f['toggle_text'] ) : '',
				'suffix'          => isset( $f['suffix'] ) ? sanitize_text_field( (string) $f['suffix'] ) : '',
				'help_enabled'    => ! empty( $f['help_enabled'] ),
				'help_title'      => isset( $f['help_title'] ) ? sanitize_text_field( $f['help_title'] ) : '',
				'help_text'       => isset( $f['help_text'] ) ? sanitize_textarea_field( $f['help_text'] ) : '',
				'help_image'      => isset( $f['help_image'] ) ? esc_url_raw( $f['help_image'] ) : '',
				'help_video'      => isset( $f['help_video'] ) ? esc_url_raw( $f['help_video'] ) : '',
				'help_icon'       => isset( $f['help_icon'] ) ? sanitize_text_field( $f['help_icon'] ) : '?',
				'placeholder'     => isset( $f['placeholder'] ) ? sanitize_text_field( $f['placeholder'] ) : '',
				'icon'            => isset( $f['icon'] ) ? sanitize_text_field( $f['icon'] ) : '',
				'min_length'      => isset( $f['min_length'] ) ? min( 500, absint( $f['min_length'] ) ) : 0,
				'max_length'      => isset( $f['max_length'] ) ? min( 500, absint( $f['max_length'] ) ) : 0,
				'pattern'         => isset( $f['pattern'] ) ? substr( sanitize_text_field( $f['pattern'] ), 0, 250 ) : '',
				'transform'       => in_array( $f['transform'] ?? 'none', array( 'none', 'uppercase', 'lowercase', 'digits' ), true ) ? $f['transform'] : 'none',
				'error_message'   => isset( $f['error_message'] ) ? sanitize_text_field( $f['error_message'] ) : '',
				'condition_logic' => ( isset( $f['condition_logic'] ) && 'any' === $f['condition_logic'] ) ? 'any' : 'all',
			);

			if ( 'content' === $type ) {
				$field['content'] = isset( $f['content'] ) ? wp_kses_post( $f['content'] ) : '';
			}

			foreach ( array( 'min', 'max', 'step' ) as $k ) {
				$field[ $k ] = ( isset( $f[ $k ] ) && is_numeric( $f[ $k ] ) ) ? (float) $f[ $k ] : '';
			}

			$field['options'] = array();
			if ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! empty( $f['options'] ) && is_array( $f['options'] ) ) {
				$used_vals = array();
				foreach ( $f['options'] as $opt ) {
					if ( ! is_array( $opt ) ) {
						continue;
					}
					$val = isset( $opt['value'] ) ? sanitize_text_field( $opt['value'] ) : '';
					if ( '' === $val || isset( $used_vals[ $val ] ) ) {
						$val = 'o' . substr( md5( uniqid( '', true ) ), 0, 6 );
					}
					$used_vals[ $val ]  = true;
					$field['options'][] = array(
						'label' => isset( $opt['label'] ) ? sanitize_text_field( $opt['label'] ) : $val,
						'value' => $val,
						'price' => ( isset( $opt['price'] ) && is_numeric( $opt['price'] ) ) ? (float) $opt['price'] : 0.0,
						'color' => isset( $opt['color'] ) ? sanitize_hex_color( $opt['color'] ) : '',
						'image' => isset( $opt['image'] ) ? esc_url_raw( $opt['image'] ) : '',
						'desc'  => isset( $opt['desc'] ) ? sanitize_text_field( $opt['desc'] ) : '',
					);
				}
			}

			$field['conditions'] = array();
			if ( ! empty( $f['conditions'] ) && is_array( $f['conditions'] ) ) {
				foreach ( $f['conditions'] as $cond ) {
					if ( ! is_array( $cond ) || empty( $cond['field'] ) ) {
						continue;
					}
					$field['conditions'][] = array(
						'field' => sanitize_key( $cond['field'] ),
						'op'    => ( isset( $cond['op'] ) && in_array( $cond['op'], self::OPS, true ) ) ? $cond['op'] : '==',
						'value' => isset( $cond['value'] ) ? sanitize_text_field( (string) $cond['value'] ) : '',
					);
				}
			}

			$out['fields'][] = $field;
		}

		return $out;
	}
}
