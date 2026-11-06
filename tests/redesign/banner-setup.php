<?php
// php banner-setup.php <site-dir> — STAGING ONLY. Pages with the « Avec Délicat vous pouvez ! » banner rail in each
// card style (9.7.9), the owner's two cards as they are on the live site, plus custom-colour, icon, picture and
// card-link cases. banner-979.cjs reads them.
$site = $argv[1] ?? '';
if ( '' === $site || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php banner-setup.php <site-dir>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
wp_set_current_user( 1 );
foreach ( array( 'schema', 'pages' ) as $c ) { if ( ! class_exists( 'Delicat_Builder_V9_' . ucfirst( $c ) ) ) { require_once WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-' . $c . '.php'; } }
$img = (int) get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 1, 'fields' => 'ids' ) )[0];
$base = array(
	'heading'     => 'Avec Délicat *vous pouvez !*',
	'title'       => 'Application Android',
	'text'        => 'Une meilleure Experience vous attend avec Délicat Store. Notre Application Android est disponible sur notre Site et Bientôt sur Google Play Store.',
	'button_text' => 'Telecharger ici!',
	'button_url'  => home_url( '/boutique/' ),
	'items'       => "Channel WhatsApp|Nous vous invitons à rejoindre notre Notre channel WhatsApp|button|https://whatsapp.com/channel/0029VaTEST",
	'show_dots'   => 1,
);
$extra = "Cartes cadeaux|Offrez une carte en quelques secondes.|Voir|" . home_url( '/boutique/' ) . "|#7C3AED||redeem\n"
	. "Promo jaune|Une couleur claire garde un texte lisible.|button|" . home_url( '/boutique/' ) . "|#FFD400\n"
	. "Avec photo|Une carte avec son image.|Ouvrir|" . home_url( '/boutique/' ) . "||$img|none\n"
	. "Sans bouton|Toute la carte est un lien.||" . home_url( '/boutique/' );
$pages = array(
	'banner-nuit'    => array( 'card_style' => 'dark' ),
	'banner-epure'   => array( 'card_style' => 'light' ),
	'banner-vitrine' => array( 'card_style' => 'vitrine' ),
	'banner-tint'    => array( 'card_style' => 'tint' ),
	'banner-extra-light'   => array( 'card_style' => 'light', 'items' => $base['items'] . "\n" . $extra ),
	'banner-extra-dark'    => array( 'card_style' => 'dark', 'items' => $base['items'] . "\n" . $extra ),
	'banner-extra-vitrine' => array( 'card_style' => 'vitrine', 'items' => $base['items'] . "\n" . $extra ),
);
foreach ( $pages as $slug => $over ) {
	$page = get_page_by_path( $slug );
	$id   = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug ) );
	$section            = Delicat_Builder_V9_Schema::default_section( 'banner' );
	$section['content'] = array_merge( $section['content'], $base, $over );
	update_post_meta( $id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
	if ( 'banner-tint' === $slug ) {
		// a layout saved before 9.7.9 keeps 'tint' in the database: store it raw, as an old site would have it
		update_post_meta( $id, Delicat_Builder_V9_Pages::META_LAYOUT, array( array_merge( $section, array( 'content' => array_merge( $section['content'], array( 'card_style' => 'tint' ) ) ) ) ) );
	} else {
		update_post_meta( $id, Delicat_Builder_V9_Pages::META_LAYOUT, Delicat_Builder_V9_Schema::sanitize_layout( array( $section ) ) );
	}
	$saved = get_post_meta( $id, Delicat_Builder_V9_Pages::META_LAYOUT, true );
	echo $slug, ' #', $id, ' style=', $saved[0]['content']['card_style'] ?? '?', "\n";
}
// rendered pages are cached under the Builder cache version: bump it so these pages show the current renderer
update_option( 'delicat_builder_v9_cache_version', max( 1, (int) get_option( 'delicat_builder_v9_cache_version', 1 ) ) + 1, false );
