<?php
/**
 * Activation: create the pages the storefront links to, the menus, and
 * optionally the sample catalogue. Everything is idempotent — running it
 * twice creates nothing twice — and nothing here touches an existing page's
 * content.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

/**
 * Non-legal pages the theme ships.
 *
 * @return array<string,array{title:string,template:string,content:string}>
 */
function ds_install_page_specs(): array {
	return array(
		'accueil'           => array(
			'title'    => 'Accueil',
			'template' => '',
			'content'  => '<!-- wp:paragraph --><p>Cette page utilise le modèle d\'accueil du thème Delicat Store. Son contenu se règle dans Apparence › Personnaliser › Delicat Store.</p><!-- /wp:paragraph -->',
		),
		'support'           => array(
			'title'    => 'Support',
			'template' => 'page-templates/support.php',
			'content'  => '',
		),
		'comment-ca-marche' => array(
			'title'    => 'Comment ça marche',
			'template' => 'page-templates/how-it-works.php',
			'content'  => '',
		),
		'bon-kliyan'        => array(
			'title'    => 'Bon Kliyan',
			'template' => 'page-templates/bon-kliyan.php',
			'content'  => '',
		),
	);
}

/**
 * Create a page if no page has this slug. Returns the page id.
 */
function ds_install_page( string $slug, string $title, string $content, string $template = '', array $meta = array() ): int {
	$existing = get_page_by_path( $slug );
	if ( $existing instanceof WP_Post ) {
		if ( 'trash' === $existing->post_status ) {
			wp_untrash_post( $existing->ID );
			wp_publish_post( $existing->ID );
		}
		foreach ( $meta as $k => $v ) {
			update_post_meta( $existing->ID, $k, $v );
		}
		if ( '' !== $template && '' === (string) get_post_meta( $existing->ID, '_wp_page_template', true ) ) {
			update_post_meta( $existing->ID, '_wp_page_template', $template );
		}
		return (int) $existing->ID;
	}
	$id = wp_insert_post(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'post_title'     => $title,
			'post_name'      => $slug,
			'post_content'   => $content,
			'comment_status' => 'closed',
			'ping_status'    => 'closed',
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	if ( '' !== $template ) {
		update_post_meta( $id, '_wp_page_template', $template );
	}
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return (int) $id;
}

/**
 * Create every page and set the static front page.
 *
 * @return array{created:string[],existing:string[]}
 */
function ds_install_pages(): array {
	$report = array(
		'created'  => array(),
		'existing' => array(),
	);
	foreach ( ds_install_page_specs() as $slug => $spec ) {
		$before = get_page_by_path( $slug ) instanceof WP_Post;
		$id     = ds_install_page( $slug, $spec['title'], $spec['content'], $spec['template'] );
		if ( $id > 0 ) {
			$report[ $before ? 'existing' : 'created' ][] = $spec['title'];
		}
		if ( 'accueil' === $slug && $id > 0 ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $id );
		}
	}
	foreach ( ds_legal_documents() as $key => $doc ) {
		$before = ds_legal_page_id( $key ) > 0 || get_page_by_path( $doc['slug'] ) instanceof WP_Post;
		$id     = ds_install_page(
			$doc['slug'],
			$doc['title'],
			'[delicat_legal doc="' . $key . '"]',
			'page-templates/legal.php',
			array( DS_LEGAL_META => $key )
		);
		if ( $id > 0 ) {
			$report[ $before ? 'existing' : 'created' ][] = $doc['title'];
		}
	}
	/* WooCommerce's privacy/terms settings point at our pages when unset. */
	if ( ds_is_woo() ) {
		$terms = ds_legal_page_id( 'conditions' );
		if ( $terms > 0 && (int) get_option( 'woocommerce_terms_page_id', 0 ) === 0 ) {
			update_option( 'woocommerce_terms_page_id', $terms );
		}
	}
	$privacy = ds_legal_page_id( 'privacy' );
	if ( $privacy > 0 && (int) get_option( 'wp_page_for_privacy_policy', 0 ) === 0 ) {
		update_option( 'wp_page_for_privacy_policy', $privacy );
	}
	flush_rewrite_rules();
	return $report;
}

/**
 * Build the two menus when their locations are empty.
 *
 * @return string[] What was done.
 */
function ds_install_menus(): array {
	$done      = array();
	$locations = (array) get_theme_mod( 'nav_menu_locations', array() );

	$build = static function ( string $name, array $items ): int {
		$existing = wp_get_nav_menu_object( $name );
		if ( $existing instanceof WP_Term ) {
			return (int) $existing->term_id;
		}
		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		foreach ( $items as $item ) {
			$args = array(
				'menu-item-title'  => $item['title'],
				'menu-item-status' => 'publish',
			);
			if ( ! empty( $item['page'] ) ) {
				$args['menu-item-type']      = 'post_type';
				$args['menu-item-object']    = 'page';
				$args['menu-item-object-id'] = (int) $item['page'];
			} else {
				$args['menu-item-type'] = 'custom';
				$args['menu-item-url']  = $item['url'];
			}
			wp_update_nav_menu_item( $id, 0, $args );
		}
		return (int) $id;
	};

	$page = static function ( string $slug ): int {
		$p = get_page_by_path( $slug );
		return $p instanceof WP_Post ? (int) $p->ID : 0;
	};

	if ( empty( $locations['primary'] ) ) {
		$items = array( array( 'title' => 'Accueil', 'url' => home_url( '/' ) ) );
		if ( ds_is_woo() ) {
			$items[] = array( 'title' => 'Boutique', 'url' => ds_shop_url() );
		}
		foreach ( array( 'bon-kliyan' => 'Bon Kliyan', 'comment-ca-marche' => 'Comment ça marche', 'support' => 'Support' ) as $slug => $title ) {
			$pid = $page( $slug );
			if ( $pid > 0 ) {
				$items[] = array( 'title' => $title, 'page' => $pid );
			}
		}
		$id = $build( 'Menu principal', $items );
		if ( $id > 0 ) {
			$locations['primary'] = $id;
			$done[]               = 'Menu principal';
		}
	}
	if ( empty( $locations['footer'] ) ) {
		$items = array();
		foreach ( array( 'comment-ca-marche' => 'Comment ça marche', 'bon-kliyan' => 'Programme Bon Kliyan', 'support' => 'Support & contact' ) as $slug => $title ) {
			$pid = $page( $slug );
			if ( $pid > 0 ) {
				$items[] = array( 'title' => $title, 'page' => $pid );
			}
		}
		if ( ds_is_woo() ) {
			$items[] = array( 'title' => 'Mon compte', 'url' => ds_account_url() );
			$items[] = array( 'title' => 'Suivre ma commande', 'url' => wc_get_account_endpoint_url( 'orders' ) );
		}
		$id = $build( 'Menu pied de page', $items );
		if ( $id > 0 ) {
			$locations['footer'] = $id;
			$done[]              = 'Menu pied de page';
		}
	}
	set_theme_mod( 'nav_menu_locations', $locations );
	return $done;
}

/**
 * Import sample-data/products.csv with WooCommerce's own importer.
 *
 * @return array{imported:int,updated:int,failed:int,skipped:int}|WP_Error
 */
function ds_install_sample_products() {
	if ( ! ds_is_woo() ) {
		return new WP_Error( 'ds_no_woo', 'WooCommerce n\'est pas actif.' );
	}
	$file = DS_DIR . 'sample-data/products.csv';
	if ( ! is_readable( $file ) ) {
		return new WP_Error( 'ds_no_file', 'Fichier d\'exemple introuvable.' );
	}
	if ( ! class_exists( 'WC_Product_CSV_Importer' ) ) {
		$path = WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'ds_no_importer', 'Importateur WooCommerce introuvable.' );
		}
		include_once WC_ABSPATH . 'includes/import/abstract-wc-product-importer.php';
		include_once $path;
	}
	/* Column → field mapping (the admin importer builds this from its UI). */
	$mapping = array(
		'ID'                    => 'id',
		'Type'                  => 'type',
		'SKU'                   => 'sku',
		'Name'                  => 'name',
		'Published'             => 'published',
		'Is featured?'          => 'featured',
		'Visibility in catalog' => 'catalog_visibility',
		'Short description'     => 'short_description',
		'Description'           => 'description',
		'Tax status'            => 'tax_status',
		'In stock?'             => 'stock_status',
		'Sold individually?'    => 'sold_individually',
		'Virtual'               => 'virtual',
		'Downloadable'          => 'downloadable',
		'Regular price'         => 'regular_price',
		'Sale price'            => 'sale_price',
		'Categories'            => 'category_ids',
		'Tags'                  => 'tag_ids',
		'Position'              => 'menu_order',
		'Meta: _dmc_calc'       => 'meta:_dmc_calc',
	);
	$importer = new WC_Product_CSV_Importer(
		$file,
		array(
			'parse'            => true,
			'mapping'          => $mapping,
			'update_existing'  => true,
			'prevent_timeouts' => false,
		)
	);
	$result = $importer->import();
	ds_flush_catalog_caches();
	return array(
		'imported' => count( $result['imported'] ?? array() ),
		'updated'  => count( $result['updated'] ?? array() ),
		'failed'   => count( $result['failed'] ?? array() ),
		'skipped'  => count( $result['skipped'] ?? array() ),
	);
}

/**
 * Cart, checkout and account as classic shortcode pages, which the theme
 * styles fully and which every payment plugin supports.
 *
 * @return string[]
 */
function ds_install_classic_pages(): array {
	if ( ! ds_is_woo() ) {
		return array();
	}
	$done = array();
	$map  = array(
		'cart'      => '[woocommerce_cart]',
		'checkout'  => '[woocommerce_checkout]',
		'myaccount' => '[woocommerce_my_account]',
	);
	foreach ( $map as $key => $shortcode ) {
		$id = (int) wc_get_page_id( $key );
		if ( $id <= 0 ) {
			continue;
		}
		$post = get_post( $id );
		if ( ! $post instanceof WP_Post || false !== strpos( $post->post_content, $shortcode ) ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->',
			)
		);
		$done[] = $post->post_title;
	}
	return $done;
}

/**
 * WooCommerce defaults for a Haitian digital store. Only applied on request
 * from the setup screen.
 *
 * @return string[]
 */
function ds_install_haiti_defaults(): array {
	if ( ! ds_is_woo() ) {
		return array();
	}
	update_option( 'woocommerce_currency', 'HTG' );
	update_option( 'woocommerce_currency_pos', 'left_space' );
	update_option( 'woocommerce_price_num_decimals', '0' );
	update_option( 'woocommerce_default_country', 'HT' );
	update_option( 'woocommerce_allowed_countries', 'all' );
	update_option( 'woocommerce_ship_to_countries', 'disabled' );
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_enable_guest_checkout', 'yes' );
	update_option( 'woocommerce_enable_checkout_login_reminder', 'yes' );
	update_option( 'woocommerce_enable_signup_and_login_from_checkout', 'yes' );
	update_option( 'woocommerce_registration_generate_password', 'yes' );
	update_option( 'woocommerce_enable_myaccount_registration', 'yes' );
	update_option( 'woocommerce_cart_redirect_after_add', 'no' );
	update_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' );
	update_option( 'woocommerce_enable_reviews', 'yes' );
	update_option( 'woocommerce_review_rating_verification_required', 'yes' );
	$renamed = ds_install_french_pages();
	if ( array() === $renamed ) {
		flush_rewrite_rules();
	}
	return array_merge( array( 'Devise HTG (G)', 'Pays Haïti', 'Livraison désactivée (produits numériques)', 'Commande sans compte autorisée', 'Avis vérifiés' ), $renamed );
}

/**
 * French titles and slugs for WooCommerce's pages (/boutique/, /panier/,
 * /commander/, /mon-compte/). Only pages still carrying WooCommerce's
 * default English slug are touched.
 *
 * @return string[]
 */
function ds_install_french_pages(): array {
	$map  = array(
		'shop'      => array( 'Boutique', 'boutique', 'shop' ),
		'cart'      => array( 'Panier', 'panier', 'cart' ),
		'checkout'  => array( 'Commander', 'commander', 'checkout' ),
		'myaccount' => array( 'Mon compte', 'mon-compte', 'my-account' ),
	);
	$done = array();
	foreach ( $map as $key => $spec ) {
		$id   = (int) wc_get_page_id( $key );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || $post->post_name !== $spec[2] ) {
			continue;
		}
		if ( get_page_by_path( $spec[1] ) instanceof WP_Post ) {
			continue;
		}
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => $spec[0],
				'post_name'  => $spec[1],
			)
		);
		$done[] = 'Page « ' . $spec[0] . ' » (/' . $spec[1] . '/)';
	}
	if ( array() !== $done ) {
		/* The product archive's rewrite slug is the shop page's slug, read
		   when WooCommerce registers the post type at init — before this
		   rename. Flushing now would store rules built from the old slug, so
		   the rules are dropped and WordPress rebuilds them on the next
		   request, with the new slug. */
		delete_option( 'rewrite_rules' );
	}
	return $done;
}

/**
 * On activation: pages + menus, then send the admin to the setup screen.
 */
add_action(
	'after_switch_theme',
	static function (): void {
		ds_install_pages();
		ds_install_menus();
		set_transient( 'ds_activation_redirect', 1, 60 );
	}
);

add_action(
	'admin_init',
	static function (): void {
		if ( ! get_transient( 'ds_activation_redirect' ) || wp_doing_ajax() || ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		delete_transient( 'ds_activation_redirect' );
		if ( isset( $_GET['activated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_safe_redirect( admin_url( 'themes.php?page=delicat-store' ) );
			exit;
		}
	}
);
