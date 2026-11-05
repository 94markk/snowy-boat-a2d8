<?php
// php reviews-setup.php <site-dir> <out.json> — STAGING ONLY. Builder 9.8.0 (TR-01) review figures.
// Creates a buyer and a customer without orders, store reviews and product reviews with mixed ratings (plus the
// cases that must NOT count: pending, unrated, draft product, reply), pages whose testimonials section still holds
// the old hand-typed rating (4.8 / 219 « AVIS VÉRIFIÉS ») and the two sample lines the plugin used to ship.
// Writes the expected figures to <out.json>, computed here with plain WordPress calls (not the plugin's SQL), so
// reviews-980.cjs checks the page against an independent count.
$site = $argv[1] ?? '';
$out  = $argv[2] ?? '';
if ( '' === $site || '' === $out || ! is_file( $site . '/wp-load.php' ) ) { fwrite( STDERR, "usage: php reviews-setup.php <site-dir> <out.json>\n" ); exit( 2 ); }
$_SERVER['HTTP_HOST'] = 'localhost:8980'; $_SERVER['REQUEST_URI'] = '/wp-admin/'; $_SERVER['SERVER_NAME'] = 'localhost';
require $site . '/wp-load.php';
if ( false === strpos( home_url(), 'localhost' ) ) { fwrite( STDERR, "refusing: not a staging site\n" ); exit( 3 ); }
wp_set_current_user( 1 );
foreach ( array( 'schema', 'pages', 'reviews', 'legal' ) as $c ) {
	if ( ! class_exists( 'Delicat_Builder_V9_' . ucfirst( $c ), false ) ) { require_once WP_PLUGIN_DIR . '/delicat-builder-v9/includes/class-delicat-builder-' . $c . '.php'; }
}
Delicat_Builder_V9_Reviews::register_cpt();
const PASS = 'ReviewPass!2026'; // staging fixture account, not a real credential

function rv_user( string $login ): int {
	global $wpdb;
	$u  = get_user_by( 'login', $login );
	$id = $u ? $u->ID : wp_insert_user( array( 'user_login' => $login, 'user_pass' => PASS, 'user_email' => $login . '@example.test', 'role' => 'customer', 'display_name' => strtoupper( $login ) ) );
	wp_set_password( PASS, $id );
	update_user_meta( $id, 'dip_email_verified', 'yes' ); // Delicat Identity refuses sign-in before the email is confirmed
	update_user_meta( $id, 'dip_age_declaration', 'adult' ); // and asks the age once, in a dialog over the page
	update_user_meta( $id, 'dip_age_declared_at', time() );
	delete_user_meta( $id, Delicat_Builder_V9_Reviews::UMETA_DONE );
	// clear the 12-hour submit slot and the 5-an-hour review limit so a re-run can post again
	delete_transient( 'dlc_rev_rl_' . $id );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '%' . $wpdb->esc_like( 'dlc_rev_rl_' . $id . '_' ) . '%' ) ); // this user's per-product slots only
	delete_transient( 'dbv9rl_' . substr( hash_hmac( 'sha256', 'review_submit|u:' . $id, wp_salt( 'nonce' ) ), 0, 32 ) );
	return (int) $id;
}
$buyer  = rv_user( 'rv_buyer' );
$buyer2 = rv_user( 'rv_buyer2' ); // a buyer with no review yet: posts one through the popup in reviews-980.cjs
$nobuy  = rv_user( 'rv_nobuy' );

$products = get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 3, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
if ( count( $products ) < 2 ) { fwrite( STDERR, "need 2 published products\n" ); exit( 4 ); }

// both buyers have one completed order; the other customer has none
foreach ( array( $buyer, $buyer2 ) as $uid ) {
	if ( ! wc_get_orders( array( 'customer_id' => $uid, 'limit' => 1, 'return' => 'ids', 'status' => wc_get_is_paid_statuses() ) ) ) {
		$order = wc_create_order( array( 'customer_id' => $uid ) );
		$order->add_product( wc_get_product( $products[0] ), 1 );
		$order->calculate_totals();
		$order->update_status( 'completed' );
	}
}
delete_user_meta( $buyer2, Delicat_Builder_V9_Reviews::UMETA_DUE_PRODUCT );
foreach ( wc_get_orders( array( 'customer_id' => $nobuy, 'limit' => -1, 'return' => 'ids' ) ) as $oid ) { wc_get_order( $oid )->delete( true ); }

// remove this script's earlier fixtures so a re-run starts clean
foreach ( get_posts( array( 'post_type' => Delicat_Builder_V9_Reviews::CPT, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_rv_fixture', 'fields' => 'ids' ) ) as $pid ) { wp_delete_post( $pid, true ); }
foreach ( get_comments( array( 'meta_key' => '_rv_fixture', 'status' => 'all', 'type' => 'review' ) ) as $c ) { wp_delete_comment( $c->comment_ID, true ); }
foreach ( get_posts( array( 'post_type' => Delicat_Builder_V9_Reviews::CPT, 'post_status' => 'any', 'numberposts' => -1, 'title' => 'RV_BUYER2', 'fields' => 'ids' ) ) as $pid ) { wp_delete_post( $pid, true ); }

function rv_store( string $name, int $rating, int $user, string $status, string $quote ): void {
	$id = wp_insert_post( array( 'post_type' => Delicat_Builder_V9_Reviews::CPT, 'post_status' => $status, 'post_title' => $name, 'post_content' => $quote ) );
	update_post_meta( $id, Delicat_Builder_V9_Reviews::META_RATING, $rating );
	update_post_meta( $id, Delicat_Builder_V9_Reviews::META_USER, $user );
	update_post_meta( $id, Delicat_Builder_V9_Reviews::META_CITY, 'Jacmel' );
	update_post_meta( $id, '_rv_fixture', 1 );
}
rv_store( 'RV STORE BUYER FIVE', 5, $buyer, 'publish', 'Commande reçue, tout est en ordre, merci beaucoup.' );
rv_store( 'RV STORE NOBUY FOUR', 4, $nobuy, 'publish', 'Bon accueil sur WhatsApp, réponse rapide.' );
rv_store( 'RV STORE BUYER TWO', 2, $buyer, 'publish', 'Livraison plus lente que prévu cette fois.' );
rv_store( 'RV STORE PENDING ONE', 1, $nobuy, 'pending', 'En attente de modération : ne compte pas.' );
rv_store( 'RV STORE NO RATING', 0, $buyer, 'publish', 'Avis tapé dans l’administration, sans note.' ); // neither shown nor counted
$pending = get_posts( array( 'post_type' => Delicat_Builder_V9_Reviews::CPT, 'post_status' => 'pending', 'title' => 'RV STORE PENDING ONE', 'fields' => 'ids', 'numberposts' => 1 ) )[0];

function rv_review( int $product, string $name, int $rating, int $verified, string $approved, int $parent = 0, int $user = 0 ): int {
	$id = wp_insert_comment( array( 'comment_post_ID' => $product, 'comment_author' => $name, 'comment_author_email' => 'rv@example.test', 'comment_content' => 'Avis de test ' . $name . ', texte assez long.', 'comment_type' => 'review', 'comment_approved' => $approved, 'comment_parent' => $parent, 'user_id' => $user ) );
	if ( $rating > 0 ) { add_comment_meta( $id, 'rating', $rating, true ); }
	add_comment_meta( $id, 'verified', $verified, true );
	add_comment_meta( $id, '_rv_fixture', 1, true );
	return (int) $id;
}
$top = rv_review( $products[0], 'RV PRODUCT VERIFIED FIVE', 5, 1, '1', 0, $buyer );
rv_review( $products[1], 'RV PRODUCT UNVERIFIED THREE', 3, 0, '1' );
$dup = rv_review( $products[1], 'RV PRODUCT VERIFIED FOUR', 4, 1, '1' );
add_comment_meta( $dup, 'rating', 4 );   // the same values stored twice, as an import can leave them:
add_comment_meta( $dup, 'verified', 1 ); // still one review, one verified buyer
rv_review( $products[0], 'RV PRODUCT UNVERIFIED FOUR', 4, 0, '1' );
rv_review( $products[0], 'RV PRODUCT HELD ONE', 1, 1, '0' );          // not approved
rv_review( $products[1], 'RV PRODUCT UNRATED', 0, 0, '1' );           // no rating: not counted, not on the rail
rv_review( $products[0], 'RV PRODUCT REPLY', 0, 0, '1', $top );       // admin reply: not a review
$draft    = get_page_by_path( 'rv-draft-product', OBJECT, 'product' );
$draft_id = $draft ? $draft->ID : wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'draft', 'post_title' => 'RV draft product', 'post_name' => 'rv-draft-product' ) );
rv_review( $draft_id, 'RV PRODUCT ON DRAFT', 1, 1, '1' );             // product not public: not counted

// expected figures, counted with plain WordPress calls
$store = array();
foreach ( get_posts( array( 'post_type' => Delicat_Builder_V9_Reviews::CPT, 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
	$r = absint( get_post_meta( $p->ID, Delicat_Builder_V9_Reviews::META_RATING, true ) );
	if ( $r >= 1 && $r <= 5 ) { $store[] = $r; }
}
$prod     = array();
$verified = 0;
foreach ( get_comments( array( 'type' => 'review', 'status' => 'approve', 'parent' => 0 ) ) as $c ) {
	if ( 'publish' !== get_post_status( $c->comment_post_ID ) || 'product' !== get_post_type( $c->comment_post_ID ) ) { continue; }
	$r = absint( get_comment_meta( $c->comment_ID, 'rating', true ) );
	if ( $r < 1 || $r > 5 ) { continue; }
	$prod[]    = $r;
	$verified += '1' === (string) get_comment_meta( $c->comment_ID, 'verified', true ) ? 1 : 0;
}
$avg  = static fn( array $a ): float => $a ? array_sum( $a ) / count( $a ) : 0.0;
$down = static fn( float $x ): string => number_format_i18n( floor( $x * 10 + 1e-9 ) / 10, 1 ); // shown rounded down, never up

// pages: the old hand-typed figures and the shipped sample lines are still saved, as on the live site
$samples = "Recharge Free Fire reçue en moins de 2 minutes. Service rapide et sérieux, je commande chaque semaine.|JEAN-MARC|PORT-AU-PRINCE|5\nMon abonnement a été livré très rapidement et le support m’a répondu sur WhatsApp.|MIKAËLL|CAP-HAÏTIEN|5\nService au top, je recommande à tous mes amis.|RV TYPED LINE|JACMEL|5";
$pages   = array(
	'reviews-home'   => array( 'rating_value' => '4.8', 'rating_count' => 219, 'rating_label' => 'AVIS VÉRIFIÉS', 'items' => $samples ),
	'reviews-hidden' => array( 'rating_value' => '', 'rating_count' => 219, 'items' => $samples ),
	'reviews-off'    => array( 'show_rating' => 0, 'items' => $samples ),
);
foreach ( $pages as $slug => $over ) {
	$page    = get_page_by_path( $slug );
	$id      = $page ? $page->ID : wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug, 'post_name' => $slug ) );
	$section = Delicat_Builder_V9_Schema::default_section( 'testimonials' );
	unset( $section['content']['show_rating'] ); // a layout saved before 9.8.0 has no show_rating key
	$section['content'] = array_merge( $section['content'], $over );
	update_post_meta( $id, Delicat_Builder_V9_Pages::META_ENABLED, 1 );
	update_post_meta( $id, Delicat_Builder_V9_Pages::META_LAYOUT, array( $section ) ); // stored raw, as an old site has it
}

// the review policy page from the 9.8.0 template
$legal = Delicat_Builder_V9_Legal::install( 'reviews', true );

Delicat_Builder_V9_Reviews::flush_rail_cache();
update_option( 'delicat_builder_v9_cache_version', max( 1, (int) get_option( 'delicat_builder_v9_cache_version', 1 ) ) + 1, false );

file_put_contents(
	$out,
	wp_json_encode(
		array(
			'store'    => array( 'count' => count( $store ), 'average' => $down( $avg( $store ) ), 'raw' => $avg( $store ) ),
			'products' => array( 'count' => count( $prod ), 'average' => $down( $avg( $prod ) ), 'raw' => $avg( $prod ), 'verified' => $verified ),
			'ids'      => array( 'pending' => (int) $pending, 'product' => (int) $products[1] ),
			'product_url' => get_permalink( $products[1] ),
			'policy'   => Delicat_Builder_V9_Legal::url( 'reviews' ),
			'legal'    => $legal,
			'users'    => array( 'buyer' => 'rv_buyer2', 'nobuy' => 'rv_nobuy', 'pass' => PASS ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
	)
);
echo file_get_contents( $out ), "\n";
