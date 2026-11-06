<?php
// php seo-setup.php <site-dir> <out.json> [action] — STAGING ONLY. Builder 9.9.0 (SEO-02 to SEO-06) and 9.10.0 (category fields).
// Recreates on the test site what the live audit of 6 Nov 2026 found, for SEVERAL suppliers:
//  - pages: log-in and maintenance (open, out of Google), « test » and an empty page (fake: redirected home),
//    WordPress's « Hello world! » (redirected), a page repeating another (rewards-program → program-bon-kliyan),
//    and real pages that must stay: tutorials, an Elementor page with no post content, a page made of one dynamic
//    block, « sondaj » (Elementor shortcode widget holding [delicat_v8_hero]);
//  - categories: Delicat Digital › delicat-topup-c9e2614d39 (machine-made, named like the product), a second supplier
//    with no known name (« GamePoint » › two gp-<hex> categories, one holding one product), « Direct Topup » (name),
//    a category repeating its single product, « Réseaux sociaux » / « Reseaux Sociaux », « Uncategorized »;
//  - tags « jeux » and its emoji copy « 🎮 JEUX »; a title suffix equal to the store name; profile links with
//    tracking codes; a drawer entry to « /test/ »; an editor account.
// Actions: (none) set up and write <out.json>; « admin » render the settings screen and report what it shows;
// « save » post the settings form the way WordPress does (args: JSON of choices); « protect-front » the owner
// asks to redirect the home page; « restore » put back every setting the setup changed.
$site = $argv[1] ?? '';
$out  = $argv[2] ?? '';
if ( '' === $site || '' === $out || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php seo-setup.php <site-dir> <out.json> [action]\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
wp_set_current_user( 1 );
$action = $argv[3] ?? '';
$bump   = static function (): void {
	delete_transient( 'dlc_seo_index_v2' );
	update_option( 'delicat_builder_v9_cache_version', max( 1, (int) get_option( 'delicat_builder_v9_cache_version', 1 ) ) + 1, false );
};
$load_admin = static function (): void {
	spl_autoload_register( static function ( $cls ) { if ( 0 === strpos( $cls, 'Delicat_Builder_V9_' ) ) { $f = WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-' . str_replace( '_', '-', strtolower( substr( $cls, 19 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } } );
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	Delicat_Builder_V9_SEO::boot();
	Delicat_Builder_V9_SEO::register_settings();
};

// « save »: the owner saves the Delicat SEO screen; WordPress runs the registered sanitizer (twice when the option is new)
if ( 'save' === $action ) {
	$load_admin();
	$choices = json_decode( (string) ( $argv[4] ?? '{}' ), true );
	$fresh   = ! empty( $choices['fresh'] );
	$current = (array) get_option( 'delicat_builder_v9_seo', array() );
	if ( $fresh ) {
		delete_option( 'delicat_builder_v9_seo' );
	}
	$form = array_merge( $current, array( 'index_ui' => 1, 'choice_post' => (array) ( $choices['post'] ?? array() ), 'choice_term' => (array) ( $choices['term'] ?? array() ) ) );
	unset( $form['seo_overrides'] );
	update_option( 'delicat_builder_v9_seo', $form );
	$saved = (array) get_option( 'delicat_builder_v9_seo', array() );
	$bump();
	echo wp_json_encode( array( 'overrides' => $saved['seo_overrides'] ?? null, 'enabled' => $saved['enabled'] ?? null, 'suffix' => $saved['product_suffix'] ?? null ) ), "\n";
	exit;
}
// « grow-copy » / « shrink-copy »: a supplier sync (no Delicat SEO screen involved) puts a second product into the
// category that repeated one product, then takes it out again; the redirect must follow at once
if ( 'grow-copy' === $action || 'shrink-copy' === $action ) {
	$saved = json_decode( (string) file_get_contents( $out ), true );
	$copy  = get_term_by( 'slug', 'copie-produit', 'product_cat' );
	'grow-copy' === $action
		? wp_set_object_terms( (int) $saved['ids']['both'], array( (int) $copy->term_id ), 'product_cat', true )
		: wp_remove_object_terms( (int) $saved['ids']['both'], array( (int) $copy->term_id ), 'product_cat' );
	echo $action, "\n";
	exit;
}
// « protect-front »: a stored choice to redirect the home page, the shop or a legal page must be ignored
if ( 'protect-front' === $action ) {
	$saved = json_decode( (string) file_get_contents( $out ), true );
	$seo = (array) get_option( 'delicat_builder_v9_seo', array() );
	$seo['seo_overrides'] = array( 'post' => array( (int) get_option( 'page_on_front' ) => 'redirect', (int) wc_get_page_id( 'shop' ) => 'redirect', (int) $saved['ids']['conditions'] => 'redirect' ), 'term' => array() );
	update_option( 'delicat_builder_v9_seo', $seo, false );
	$bump();
	echo "protect-front\n";
	exit;
}
// « admin »: what the settings screen shows
if ( 'admin' === $action ) {
	$load_admin();
	$saved = json_decode( (string) file_get_contents( $out ), true );
	ob_start();
	Delicat_Builder_V9_SEO::page();
	$html = (string) ob_get_clean();
	$recommended = static function ( string $kind, int $id, string $label ) use ( $html ): bool {
		return (bool) preg_match( '/name="delicat_builder_v9_seo\[choice_' . $kind . '\]\[' . $id . '\]"><option value="default" selected=\'selected\'>Recommandé : ' . preg_quote( $label, '/' ) . '/u', $html );
	};
	echo wp_json_encode(
		array(
			'section'   => false !== strpos( $html, 'Ce que Google voit' ),
			'login'     => $recommended( 'post', (int) $saved['ids']['login'], 'cachée de Google' ),
			'test'      => $recommended( 'post', (int) $saved['ids']['test'], 'redirigée' ),
			'tutoriels' => $recommended( 'post', (int) $saved['ids']['tutoriels'], 'proposée à Google' ),
			'copy'      => $recommended( 'term', (int) $saved['ids']['social_copy'], 'redirigée' ) && false !== strpos( $html, 'double de « Réseaux sociaux »' ),
			'summary'   => (bool) preg_match( '/(\d+) catégories créées automatiquement/', $html, $m ) && (int) $m[1] >= 3,
			'machine_summarised' => false === strpos( $html, 'delicat-topup-c9e2614d39</code>' ), // one line, not one row each
			'orphans'   => false !== strpos( $html, 'Produits sans vraie catégorie' ) && false !== strpos( $html, esc_html( $saved['only_supplier']['name'] ) ),
			'front'     => false === strpos( $html, '[choice_post][' . (int) get_option( 'page_on_front' ) . ']' ),
			'legal'     => (int) $saved['ids']['conditions'] > 0 && false === strpos( $html, '[choice_post][' . (int) $saved['ids']['conditions'] . ']' ), // wp-admin does not load the legal module
		)
	), "\n";
	exit;
}
if ( 'restore' === $action ) {
	$saved = json_decode( (string) file_get_contents( $out ), true );
	update_option( 'delicat_builder_v9_seo', $saved['restore']['seo'] ?? array(), false );
	$np = (array) get_option( 'delicat_builder_v9_native_product', array() );
	$np['show_breadcrumbs'] = (int) ( $saved['restore']['show_breadcrumbs'] ?? 0 );
	update_option( 'delicat_builder_v9_native_product', $np );
	update_option( 'blog_public', (string) ( $saved['restore']['blog_public'] ?? '0' ) );
	if ( null === ( $saved['restore']['menu'] ?? null ) ) {
		delete_option( 'dsb_menu_builder_items' );
	} else {
		update_option( 'dsb_menu_builder_items', $saved['restore']['menu'] );
	}
	foreach ( array( '_dlc_seo_title', '_dlc_seo_description', '_dlc_seo_product_title', '_dlc_seo_product_description' ) as $key ) {
		delete_term_meta( (int) ( $saved['ids']['games'] ?? 0 ), $key ); // 9.10.0 category fields set by the setup
	}
	$saved['pending'] = 0;
	file_put_contents( $out, wp_json_encode( $saved, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	$bump();
	echo "restored\n";
	exit;
}
// « term-form »: the category edit screen shows the four fields, and saving it keeps a pattern only with {produit}
if ( 'term-form' === $action ) {
	$load_admin();
	$saved = json_decode( (string) file_get_contents( $out ), true );
	$term  = get_term( (int) $saved['ids']['games'], 'product_cat' );
	if ( ! class_exists( 'Delicat_Builder_V9_SEO_Terms' ) ) { fwrite( STDERR, "no SEO terms module\n" ); exit( 5 ); }
	ob_start();
	Delicat_Builder_V9_SEO_Terms::fields( $term ); // what product_cat_edit_form_fields prints (is_admin() is false here)
	$html = (string) ob_get_clean();
	$keep = array( 'title' => get_term_meta( $term->term_id, '_dlc_seo_product_title', true ), 'desc' => get_term_meta( $term->term_id, '_dlc_seo_product_description', true ) );
	$post = static function ( array $fields ) use ( $term ): void {
		$_POST = array( 'delicat_seo_term_nonce' => wp_create_nonce( 'delicat_seo_term_' . $term->term_id ), 'delicat_seo_term' => wp_slash( $fields ) );
		Delicat_Builder_V9_SEO_Terms::save( $term->term_id ); // what edited_product_cat runs
	};
	$post( array( 'title' => 'Titre test', 'description' => '', 'product_title' => 'Sans le jeton', 'product_description' => 'Achetez {produit} ici' ) );
	$refused = '' === (string) get_term_meta( $term->term_id, '_dlc_seo_product_title', true ) && 'Achetez {produit} ici' === get_term_meta( $term->term_id, '_dlc_seo_product_description', true );
	$post( array( 'title' => (string) $saved['category_seo']['title'], 'description' => (string) $saved['category_seo']['description'], 'product_title' => (string) $keep['title'], 'product_description' => (string) $keep['desc'] ) );
	$back = (string) $saved['category_seo']['title'] === get_term_meta( $term->term_id, '_dlc_seo_title', true ) && (string) $keep['title'] === get_term_meta( $term->term_id, '_dlc_seo_product_title', true );
	echo wp_json_encode( array( 'fields' => 4 === substr_count( $html, 'name="delicat_seo_term[' ) && false !== strpos( $html, 'delicat_seo_term_nonce' ), 'refused_without_token' => $refused, 'saved' => $back ) ), "\n";
	exit;
}

function seo_page( string $slug, string $title, string $content, array $meta = array() ): int {
	$page = get_page_by_path( $slug );
	$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title ) );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_content' => $content, 'post_title' => $title, 'post_parent' => 0 ) );
	delete_post_meta( $id, '_dlc_seo_canonical' );
	foreach ( $meta as $key => $value ) {
		update_post_meta( $id, $key, wp_slash( $value ) );
	}
	return (int) $id;
}
$pages = array(
	'login'              => seo_page( 'login', 'Login', 'Connexion / Inscription' ),
	'test'               => seo_page( 'test', 'test', '' ),
	'maintenance'        => seo_page( 'maintenance', 'MAINTENANCE', 'MAINTENANCE EN COURS... Nous serons de retour très bientôt!' ),
	'comment-ca-marche'  => seo_page( 'comment-ca-marche', 'Comment ça marche', '' ),
	'rewards-program'    => seo_page( 'rewards-program', 'Rewards Program', 'Bonus Free Fire — classement des meilleurs clients.' ),
	'program-bon-kliyan' => seo_page( 'program-bon-kliyan', 'Program Bon Kliyan', 'Bonus Free Fire — classement des meilleurs clients.' ),
	'tutoriels'          => seo_page( 'tutoriels', 'Tutoriels', 'Tutoriels vidéo : comment recharger Free Fire et payer avec MonCash.' ),
	'elementor'          => seo_page( 'promo-elementor', 'Promo', '', array( '_elementor_data' => '[{"elType":"section","elements":[{"elType":"widget","widgetType":"heading","settings":{"title":"Promo du mois"}}]}]', '_elementor_edit_mode' => 'builder' ) ),
	'block'              => seo_page( 'derniers-articles', 'Derniers articles', '<!-- wp:latest-posts /-->' ),
	'group'              => seo_page( 'selection', 'Sélection', '<!-- wp:group --><div class="wp-block-group"><!-- wp:woocommerce/handpicked-products /--></div><!-- /wp:group -->' ),
	'script'             => seo_page( 'formulaire-avis', 'Formulaire', '<div data-tf-widget="abc123"></div><script src="https://embed.example.test/next/embed.js"></script>' ),
	'sondaj'             => seo_page( 'sondaj', 'sondaj', '<p>Sondage de la communauté Free Fire.</p>', array( '_elementor_data' => '[{"elType":"widget","widgetType":"shortcode","settings":{"shortcode":"[delicat_v8_hero]"}}]', '_elementor_edit_mode' => 'builder' ) ),
);
$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
if ( ! $hello ) {
	$hid   = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hello-world', 'post_title' => 'Hello world!', 'post_content' => 'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!' ) );
	$hello = get_post( $hid );
}

function seo_term( string $slug, string $name, string $taxonomy, int $parent = 0 ): int {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) {
		wp_update_term( (int) $term->term_id, $taxonomy, array( 'name' => $name, 'parent' => $parent ) );
		return (int) $term->term_id;
	}
	$made = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}
$games    = get_term_by( 'slug', 'jeux-recharges', 'product_cat' );
$games_id = $games ? (int) $games->term_id : seo_term( 'jeux-recharges', 'Jeux & recharges', 'product_cat' );
$direct   = seo_term( 'direct-topup', 'Direct Topup', 'product_cat' );
$arena    = seo_term( 'arena-breakout', 'Arena Breakout', 'product_cat', $direct ); // a real shelf under a supplier name, as on the live site
$old_cat  = get_term_by( 'slug', 'delicat-topup-c9e2614d39', 'product_cat' ); // earlier runs moved products out of « Jeux & recharges »
$ids      = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 7, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_filter( array( $games_id, $old_cat ? (int) $old_cat->term_id : 0, $arena ) ), 'include_children' => false ) ) ) );
if ( count( $ids ) < 7 ) { fwrite( STDERR, "need 7 products\n" ); exit( 4 ); }
[ $only_supplier, $both, $gp_product, $social_a, $social_b, $arena_product, $shelf_product ] = $ids;

// supplier 1, known name: Delicat Digital › one machine-made category per product
$dd       = seo_term( 'delicat-digital', 'Delicat Digital', 'product_cat' );
$per_prod = seo_term( 'delicat-topup-c9e2614d39', 'Free Fire (LATAM)', 'product_cat', $dd );
// supplier 2, unknown name: « GamePoint » › two machine-made categories (one holds a single product)
$gp       = seo_term( 'gamepoint', 'GamePoint', 'product_cat' );
$gp_one   = seo_term( 'gp-9f3a7c21d0', 'Blood Strike', 'product_cat', $gp );
$gp_two   = seo_term( 'gp-4b8e0d1a77', 'COD Mobile', 'product_cat', $gp );
// supplier 3, by its name only
// a category repeating its single product, two categories with the same name
$copy     = seo_term( 'copie-produit', get_the_title( $only_supplier ), 'product_cat' );
$social   = seo_term( 'reseaux-sociaux', 'Réseaux sociaux', 'product_cat' );
$social2  = seo_term( 'reseaux-sociaux-2', 'Reseaux Sociaux', 'product_cat' );
// same name, different parents: two shelves (« iTunes › USA », « PSN › USA »)
$itunes   = seo_term( 'itunes', 'iTunes', 'product_cat' );
$psn      = seo_term( 'psn', 'PSN', 'product_cat' );
$usa1     = seo_term( 'usa-itunes', 'USA', 'product_cat', $itunes );
$usa2     = seo_term( 'usa-psn', 'USA', 'product_cat', $psn );
// a shelf named like its one direct product but with a sub-shelf full of others
$shelf    = seo_term( 'rayon-parent', get_the_title( $shelf_product ), 'product_cat' );
$subshelf = seo_term( 'rayon-enfant', 'Sous-rayon', 'product_cat', $shelf );
// a real shelf where a supplier left two machine-made categories
$stray1   = seo_term( 'dg-aa11bb22cc', 'Stray one', 'product_cat', $games_id );
$stray2   = seo_term( 'dg-dd33ee44ff', 'Stray two', 'product_cat', $games_id );
// a real shelf holding products directly, each also in its own machine-made category under it
$ffshelf  = seo_term( 'rayon-ff', 'Rayon FF', 'product_cat' );
$ffkid1   = seo_term( 'dg-0a1b2c3d4e', 'FF kid one', 'product_cat', $ffshelf );
$ffkid2   = seo_term( 'dg-5f6a7b8c9d', 'FF kid two', 'product_cat', $ffshelf );
$tag      = seo_term( 'jeux', 'JEUX', 'product_tag' );
$emoji    = seo_term( '%f0%9f%8e%ae-jeux', '🎮 JEUX', 'product_tag' );

wp_set_object_terms( $only_supplier, array( $per_prod, $copy ), 'product_cat' );   // like Free Fire (LATAM) on the live site
wp_set_object_terms( $both, array( $games_id, $per_prod ), 'product_cat' );         // a real category and a deeper supplier one
wp_set_object_terms( $both, array( $tag, $emoji ), 'product_tag' );
wp_set_object_terms( $gp_product, array( $gp_one, $direct ), 'product_cat', true ); // keeps its real category
wp_set_object_terms( $social_a, array( $social ), 'product_cat', true );
wp_set_object_terms( $social_b, array( $social, $social2 ), 'product_cat', true );
wp_set_object_terms( $social_a, array( $gp_two ), 'product_cat', true );
wp_set_object_terms( $social_b, array( $gp_two ), 'product_cat', true );
wp_set_object_terms( $arena_product, array( $arena ), 'product_cat' );               // only in the real shelf under « Direct Topup »
wp_set_object_terms( $social_a, array( $usa1 ), 'product_cat', true );
wp_set_object_terms( $social_b, array( $usa2 ), 'product_cat', true );
wp_set_object_terms( $shelf_product, array( $shelf ), 'product_cat', true );
wp_set_object_terms( $social_a, array( $ffshelf, $ffkid1 ), 'product_cat', true );
wp_set_object_terms( $social_b, array( $ffshelf, $ffkid2 ), 'product_cat', true );
wp_set_object_terms( $social_a, array( $subshelf ), 'product_cat', true );
wp_set_object_terms( $social_b, array( $subshelf ), 'product_cat', true );
wp_remove_object_terms( $both, array( (int) get_term_by( 'slug', 'copie-produit', 'product_cat' )->term_id ), 'product_cat' ); // an interrupted run
wp_update_post( array( 'ID' => $only_supplier, 'post_excerpt' => '', 'post_content' => '' ) ); // no description, as on the live site
// 9.10.0: the owner fills the new fields on « Jeux & recharges » (Produits → Catégories → Modifier)
$category_seo = array(
	'title'               => 'Top Up Jeux Haïti – Recharges rapides | Delicat Store',
	'description'         => 'Recharges de jeux en Haïti : Free Fire, PUBG, Blood Strike. Paiement MonCash, NatCash ou portefeuille.',
	'product_title'       => 'Top Up {produit} Haïti – Diamants FF',
	'product_description' => 'Achetez {produit} en Haïti sur Delicat Store : paiement MonCash, NatCash ou portefeuille.',
);
update_term_meta( $games_id, '_dlc_seo_title', $category_seo['title'] );
update_term_meta( $games_id, '_dlc_seo_description', $category_seo['description'] );
update_term_meta( $games_id, '_dlc_seo_product_title', $category_seo['product_title'] );
update_term_meta( $games_id, '_dlc_seo_product_description', $category_seo['product_description'] );

// settings as on the live site: suffix = store name, profile links with tracking codes, no choices saved yet
$seo_before = get_option( 'delicat_builder_v9_seo', array() );
$prev       = is_file( $out ) ? json_decode( (string) file_get_contents( $out ), true ) : array();
if ( isset( $prev['restore']['seo'] ) && ! empty( $prev['pending'] ) ) {
	$seo_before = $prev['restore']['seo']; // a run that stopped before « restore »: keep the real original
}
$seo = is_array( $seo_before ) ? $seo_before : array();
unset( $seo['hidden_posts'], $seo['hidden_terms'], $seo['seo_overrides'], $seo['index_tags'] );
$seo['enabled']        = 1;
$seo['product_suffix'] = get_bloginfo( 'name' );
$seo['same_as']        = "https://www.facebook.com/share/14vCPGpWgLR/?mibextid=wwXIfr\nhttps://www.tiktok.com/@delicatstoreha.com?_r=1&_t=ZS-9AhHaouoVdM";
update_option( 'delicat_builder_v9_seo', $seo, false );
$np_before              = (array) get_option( 'delicat_builder_v9_native_product', array() );
$np                     = $np_before;
$np['show_breadcrumbs'] = 1; // the visible trail (off on the live site) follows the same rule
update_option( 'delicat_builder_v9_native_product', $np );
$public_before = isset( $prev['restore']['blog_public'] ) && ! empty( $prev['pending'] ) ? $prev['restore']['blog_public'] : (string) get_option( 'blog_public', '1' );
update_option( 'blog_public', '1' ); // the test site normally asks search engines to stay away (noindex everywhere, no sitemap)
// a drawer entry to a fake page and one to a real page
$menu_before = isset( $prev['restore'] ) && ! empty( $prev['pending'] ) ? ( $prev['restore']['menu'] ?? null ) : get_option( 'dsb_menu_builder_items', null );
$menu        = is_array( $menu_before ) && $menu_before ? $menu_before : ( function_exists( 'delicat_builder_v9_menu_menu_builder_default_items' ) ? delicat_builder_v9_menu_menu_builder_default_items() : array() );
$entry       = static fn( string $title, string $url ): array => array( 'title' => $title, 'subtitle' => '', 'url' => $url, 'icon' => 'dashicons-star-filled', 'image' => '', 'color1' => '#2563eb', 'color2' => '#00004f', 'badge' => '', 'group' => 'main', 'visibility' => 'all' );
$menu        = array_values( array_filter( (array) $menu, static fn( $it ) => ! in_array( $it['title'] ?? '', array( 'SEO entree test', 'SEO entree tutos', 'SEO entree fournisseur', 'SEO entree copie' ), true ) ) );
$menu[]      = $entry( 'SEO entree test', '/test/' );
$menu[]      = $entry( 'SEO entree fournisseur', '/product-category/delicat-digital/' );
$menu[]      = $entry( 'SEO entree tutos', '/tutoriels' ); // no final slash, like « /sondaj » on the live drawer
$menu[]      = $entry( 'SEO entree copie', '/product-category/copie-produit/' ); // a category repeating one product: leads to it
update_option( 'dsb_menu_builder_items', $menu );
$bump();

// an editor (opens every address) with a session cookie for the test
$editor = get_user_by( 'login', 'seo_editor' );
$eid    = $editor ? (int) $editor->ID : (int) wp_insert_user( array( 'user_login' => 'seo_editor', 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'seo_editor@example.test', 'role' => 'editor' ) );
update_user_meta( $eid, 'dip_email_verified', 'yes' );
update_user_meta( $eid, 'dip_age_declaration', 'adult' );
update_user_meta( $eid, 'dip_age_declared_at', time() );
$expire = time() + 2 * HOUR_IN_SECONDS;

$link   = static fn( int $id ): string => (string) get_permalink( $id );
$term   = static fn( int $id, string $tax ): string => (string) get_term_link( $id, $tax );
$uncat  = get_term( (int) get_option( 'default_product_cat' ), 'product_cat' );
$shop   = (string) wc_get_page_permalink( 'shop' );
$result = array(
	'hidden'     => array(
		'login'       => $link( $pages['login'] ),
		'maintenance' => $link( $pages['maintenance'] ),
		'tag'         => $term( $tag, 'product_tag' ),
	),
	'redirected' => array(
		'test'                      => array( $link( $pages['test'] ), home_url( '/' ) ),
		'empty page'                => array( $link( $pages['comment-ca-marche'] ), home_url( '/' ) ),
		'hello'                     => array( $link( $hello->ID ), home_url( '/' ) ),
		'Delicat Digital'           => array( $term( $dd, 'product_cat' ), $shop ),
		'Delicat Digital child'     => array( $term( $per_prod, 'product_cat' ), $shop ),
		'GamePoint (unknown name)'  => array( $term( $gp, 'product_cat' ), $shop ),
		'GamePoint child, 1 product' => array( $term( $gp_one, 'product_cat' ), $link( $gp_product ) ),
		'Direct Topup'              => array( $term( $direct, 'product_cat' ), $shop ),
		'single-product copy'       => array( $term( $copy, 'product_cat' ), $link( $only_supplier ) ),
		'same-name copy'            => array( $term( $social2, 'product_cat' ), $term( $social, 'product_cat' ) ),
		'uncategorized'             => array( $uncat instanceof WP_Term ? $term( (int) $uncat->term_id, 'product_cat' ) : '', $shop ),
		'emoji tag'                 => array( $term( $emoji, 'product_tag' ), $term( $tag, 'product_tag' ) ),
	),
	'indexed'    => array(
		'home'            => home_url( '/' ),
		'shop'            => $shop,
		'tutoriels'       => $link( $pages['tutoriels'] ),
		'original'        => $link( $pages['program-bon-kliyan'] ),
		'elementor page'  => $link( $pages['elementor'] ),
		'block page'      => $link( $pages['block'] ),
		'group block page' => $link( $pages['group'] ),
		'script page'     => $link( $pages['script'] ),
		'USA (iTunes)'    => $term( $usa1, 'product_cat' ),
		'USA (PSN)'       => $term( $usa2, 'product_cat' ),
		'shelf named like its product' => $term( $shelf, 'product_cat' ),
		'real shelf under a supplier name' => $term( $arena, 'product_cat' ),
		'shelf whose products also sit in supplier categories' => $term( $ffshelf, 'product_cat' ),
		'sondaj'          => $link( $pages['sondaj'] ),
		'games'           => $term( $games_id, 'product_cat' ),
		'social keeper'   => $term( $social, 'product_cat' ),
		'product'         => $link( $both ),
		'product2'        => $link( $only_supplier ),
	),
	'duplicate'     => $link( $pages['rewards-program'] ),
	'original'      => $link( $pages['program-bon-kliyan'] ),
	'sondaj'        => $link( $pages['sondaj'] ),
	'conditions'    => class_exists( 'Delicat_Builder_V9_Legal' ) ? (string) get_permalink( Delicat_Builder_V9_Legal::page_id( 'conditions' ) ) : '',
	'reseller'      => class_exists( 'Delicat_Builder_V9_Legal' ) ? Delicat_Builder_V9_Legal::reseller_url() : '',
	'only_supplier' => array( 'url' => $link( $only_supplier ), 'name' => get_the_title( $only_supplier ) ),
	'both'          => array( 'url' => $link( $both ), 'name' => get_the_title( $both ), 'games' => html_entity_decode( get_term( $games_id )->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
	'site_name'     => get_bloginfo( 'name' ),
	'chips_out'     => array( $term( $dd, 'product_cat' ), $term( $gp, 'product_cat' ), $term( $direct, 'product_cat' ), $term( $social2, 'product_cat' ) ),
	'arena'         => array( 'url' => $link( $arena_product ), 'name' => get_the_title( $arena_product ), 'shelf' => 'Arena Breakout' ),
	'copy'          => $term( $copy, 'product_cat' ),
	'category_seo'  => $category_seo,
	'usa_itunes'    => array( 'url' => $term( $usa1, 'product_cat' ), 'id' => $usa1, 'product' => $link( $social_a ) ),
	'ids'           => array( 'login' => $pages['login'], 'test' => $pages['test'], 'tutoriels' => $pages['tutoriels'], 'social_copy' => $social2, 'only_supplier' => $only_supplier, 'both' => $both, 'games' => $games_id, 'conditions' => class_exists( 'Delicat_Builder_V9_Legal' ) ? Delicat_Builder_V9_Legal::page_id( 'conditions' ) : 0 ),
	'editor_cookie' => array( 'name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie( $eid, $expire, 'logged_in' ) ),
	'pending'       => 1,
	'restore'       => array( 'seo' => $seo_before, 'show_breadcrumbs' => (int) ( $np_before['show_breadcrumbs'] ?? 0 ), 'blog_public' => $public_before, 'menu' => $menu_before ),
);
file_put_contents( $out, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo wp_json_encode( array_diff_key( $result, array( 'restore' => 1, 'editor_cookie' => 1 ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
