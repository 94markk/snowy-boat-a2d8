<?php
// php seo-setup.php <site-dir> <out.json> [restore] — STAGING ONLY. Builder 9.9.0 (SEO-02 to SEO-06).
// Recreates on the test site what the live audit of 6 Nov 2026 found: log-in / test / maintenance / empty pages,
// WordPress's « Hello world! », a page that repeats another (rewards-program → program-bon-kliyan), the supplier's
// « Delicat Digital » category tree with one category per product named like the product, product tags (with an emoji
// copy of « jeux ») and a title suffix equal to the store name. Writes the URLs seo-990.cjs checks to <out.json>.
// With « restore » it puts back the SEO settings and the product-page breadcrumb switch saved by the setup run.
$site = $argv[1] ?? '';
$out  = $argv[2] ?? '';
if ( '' === $site || '' === $out || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php seo-setup.php <site-dir> <out.json> [restore]\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
wp_set_current_user( 1 );
$bump = static function (): void {
	delete_transient( 'dlc_seo_reco_v1' );
	update_option( 'delicat_builder_v9_cache_version', max( 1, (int) get_option( 'delicat_builder_v9_cache_version', 1 ) ) + 1, false );
};

// « override »: the owner saves the screen with no page ticked (the log-in page must come back to Google)
if ( 'override' === ( $argv[3] ?? '' ) ) {
	$seo                 = (array) get_option( 'delicat_builder_v9_seo', array() );
	$seo['hidden_posts'] = array();
	update_option( 'delicat_builder_v9_seo', $seo, false );
	$bump();
	echo "override\n";
	exit;
}
// « admin »: the settings screen renders, with the recommended boxes ticked
if ( 'admin' === ( $argv[3] ?? '' ) ) {
	spl_autoload_register( static function ( $cls ) { if ( 0 === strpos( $cls, 'Delicat_Builder_V9_' ) ) { $f = WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-' . str_replace( '_', '-', strtolower( substr( $cls, 19 ) ) ) . '.php'; if ( is_file( $f ) ) { require_once $f; } } } );
	require_once ABSPATH . 'wp-admin/includes/admin.php';
	Delicat_Builder_V9_SEO::boot();
	$saved = json_decode( (string) file_get_contents( $out ), true );
	ob_start();
	Delicat_Builder_V9_SEO::page();
	$html = (string) ob_get_clean();
	$box  = static function ( string $field, int $id ) use ( $html ): bool {
		return (bool) preg_match( '/name="delicat_builder_v9_seo\[' . $field . '\]\[\]" value="' . $id . '"\s+checked/', $html );
	};
	$login    = (int) $saved['login_id'];
	$supplier = (int) get_term_by( 'slug', 'delicat-topup-c9e2614d39', 'product_cat' )->term_id;
	$tutos    = (int) get_page_by_path( 'tutoriels' )->ID;
	echo wp_json_encode( array( 'section' => false !== strpos( $html, 'Ce que Google voit' ), 'login' => $box( 'hidden_posts', $login ), 'supplier' => $box( 'hidden_terms', $supplier ), 'tutoriels' => $box( 'hidden_posts', $tutos ) ) ), "\n";
	exit;
}
if ( 'restore' === ( $argv[3] ?? '' ) ) {
	$saved = json_decode( (string) file_get_contents( $out ), true );
	update_option( 'delicat_builder_v9_seo', $saved['restore']['seo'] ?? array(), false );
	$np = (array) get_option( 'delicat_builder_v9_native_product', array() );
	$np['show_breadcrumbs'] = (int) ( $saved['restore']['show_breadcrumbs'] ?? 0 );
	update_option( 'delicat_builder_v9_native_product', $np );
	update_option( 'blog_public', (string) ( $saved['restore']['blog_public'] ?? '0' ) );
	$bump();
	echo "restored\n";
	exit;
}

function seo_page( string $slug, string $title, string $content ): int {
	$page = get_page_by_path( $slug );
	$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $slug, 'post_title' => $title ) );
	wp_update_post( array( 'ID' => $id, 'post_status' => 'publish', 'post_content' => $content, 'post_title' => $title ) );
	delete_post_meta( $id, '_dlc_seo_canonical' );
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
);
$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
if ( ! $hello ) {
	$hid   = wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'hello-world', 'post_title' => 'Hello world!', 'post_content' => 'Welcome to WordPress. This is your first post. Edit or delete it, then start writing!' ) );
	$hello = get_post( $hid );
}

// the supplier's tree: Delicat Digital › delicat-topup-c9e2614d39 (« Free Fire (LATAM) »)
function seo_term( string $slug, string $name, string $taxonomy, int $parent = 0 ): int {
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( $term ) { return (int) $term->term_id; }
	$made = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'parent' => $parent ) );
	return is_wp_error( $made ) ? 0 : (int) $made['term_id'];
}
$supplier = seo_term( 'delicat-digital', 'Delicat Digital', 'product_cat' );
$per_prod = seo_term( 'delicat-topup-c9e2614d39', 'Free Fire (LATAM)', 'product_cat', $supplier );
$games    = get_term_by( 'slug', 'jeux-recharges', 'product_cat' );
$games_id = $games ? (int) $games->term_id : seo_term( 'jeux-recharges', 'Jeux & recharges', 'product_cat' );
$tag      = seo_term( 'jeux', 'JEUX', 'product_tag' );
$emoji    = seo_term( '%f0%9f%8e%ae-jeux', '🎮 JEUX', 'product_tag' );

$ids = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids', 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array( $games_id, $per_prod ) ) ) ) );
if ( count( $ids ) < 2 ) { fwrite( STDERR, "need 2 products in jeux-recharges\n" ); exit( 4 ); }
[ $only_supplier, $both ] = $ids;
wp_set_object_terms( $only_supplier, array( $per_prod ), 'product_cat' );            // like Free Fire (LATAM) on the live site
wp_set_object_terms( $both, array( $games_id, $per_prod ), 'product_cat' );          // a real category and a deeper supplier one
wp_set_object_terms( $both, array( $tag, $emoji ), 'product_tag' );
wp_update_post( array( 'ID' => $only_supplier, 'post_excerpt' => '', 'post_content' => '' ) ); // no description, as on the live site

// settings as on the live site: suffix = store name, profile links with tracking codes; the lists not saved yet
$seo_before = get_option( 'delicat_builder_v9_seo', array() );
$seo        = is_array( $seo_before ) ? $seo_before : array();
unset( $seo['hidden_posts'], $seo['hidden_terms'], $seo['index_tags'] );
$seo['enabled']        = 1;
$seo['product_suffix'] = get_bloginfo( 'name' );
$seo['same_as']        = "https://www.facebook.com/share/14vCPGpWgLR/?mibextid=wwXIfr\nhttps://www.tiktok.com/@delicatstoreha.com?_r=1&_t=ZS-9AhHaouoVdM";
update_option( 'delicat_builder_v9_seo', $seo, false );
$np_before              = (array) get_option( 'delicat_builder_v9_native_product', array() );
$np                     = $np_before;
$np['show_breadcrumbs'] = 1; // the visible trail (off on the live site) follows the same rule
update_option( 'delicat_builder_v9_native_product', $np );
$public_before = (string) get_option( 'blog_public', '1' );
update_option( 'blog_public', '1' ); // the test site normally asks search engines to stay away (noindex everywhere, no sitemap)
$bump();

$link  = static fn( int $id ): string => (string) get_permalink( $id );
$term  = static fn( int $id, string $tax ): string => (string) get_term_link( $id, $tax );
$uncat = get_term( (int) get_option( 'default_product_cat' ), 'product_cat' );
$result = array(
	'hidden'   => array(
		'login'             => $link( $pages['login'] ),
		'test'              => $link( $pages['test'] ),
		'maintenance'       => $link( $pages['maintenance'] ),
		'empty page'        => $link( $pages['comment-ca-marche'] ),
		'hello'             => $link( $hello->ID ),
		'supplier parent'   => $term( $supplier, 'product_cat' ),
		'supplier category' => $term( $per_prod, 'product_cat' ),
		'uncategorized'     => $uncat instanceof WP_Term ? $term( (int) $uncat->term_id, 'product_cat' ) : '',
		'tag'               => $term( $tag, 'product_tag' ),
		'emoji tag'         => $term( $emoji, 'product_tag' ),
	),
	'indexed'  => array(
		'tutoriels' => $link( $pages['tutoriels'] ),
		'original'  => $link( $pages['program-bon-kliyan'] ),
		'games'     => $term( $games_id, 'product_cat' ),
		'product'   => $link( $both ),
		'product2'  => $link( $only_supplier ),
	),
	'duplicate'     => $link( $pages['rewards-program'] ),
	'original'      => $link( $pages['program-bon-kliyan'] ),
	'only_supplier' => array( 'url' => $link( $only_supplier ), 'name' => get_the_title( $only_supplier ) ),
	'both'          => array( 'url' => $link( $both ), 'name' => get_the_title( $both ), 'games' => html_entity_decode( get_term( $games_id )->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
	'site_name'     => get_bloginfo( 'name' ),
	'login_id'      => $pages['login'],
	'restore'       => array( 'seo' => $seo_before, 'show_breadcrumbs' => (int) ( $np_before['show_breadcrumbs'] ?? 0 ), 'blog_public' => $public_before ),
);
file_put_contents( $out, wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
echo wp_json_encode( array_diff_key( $result, array( 'restore' => 1 ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
