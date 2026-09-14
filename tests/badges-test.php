<?php
/**
 * The badge engine.
 *
 * The part that decides whether a badge appears at all is the tag match, and it
 * has to survive how tags are really written on a live store: accents typed or
 * not, hyphens or underscores, French or English, the merchant's own
 * misspelling. Every case below is one of those.
 *
 * Run: php tests/badges-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );

/* ---- Just enough WordPress and WooCommerce ---- */

function __( $t, $d = null ) { return $t; }
function sanitize_key( $k ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) ); }
function sanitize_html_class( $c ) { return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $c ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' ); }
function absint( $n ) { return abs( (int) $n ); }
function apply_filters( $h, $v ) { return $v; }
function add_action( ...$a ) {}
function wp_json_encode( $d, $f = 0 ) { return json_encode( $d, $f ); }
function wp_script_is( $h, $l = '' ) { return false; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $e = 0 ) { $GLOBALS['t'][ $k ] = $v; }
function delete_transient( $k ) { unset( $GLOBALS['t'][ $k ] ); }
function get_post_meta( $id, $k, $s = false ) { return $GLOBALS['meta'][ $id ][ $k ] ?? ''; }
function remove_accents( $s ) {
	return strtr( (string) $s, array( 'é'=>'e','è'=>'e','ê'=>'e','à'=>'a','â'=>'a','ç'=>'c','ô'=>'o','û'=>'u','î'=>'i','ï'=>'i','É'=>'E','È'=>'E','À'=>'A','Ç'=>'C' ) );
}
const HOUR_IN_SECONDS = 3600;

class WP_Term {
	public $slug;
	public $name;
	public function __construct( $slug, $name ) { $this->slug = $slug; $this->name = $name; }
}

class WC_Product {
	public $id; public $tags; public $on_sale; public $sales;
	public function __construct( $id, array $tags = array(), $on_sale = false, $sales = 0 ) {
		$this->id = $id; $this->tags = $tags; $this->on_sale = $on_sale; $this->sales = $sales;
	}
	public function get_id() { return $this->id; }
	public function is_on_sale() { return $this->on_sale; }
	public function get_total_sales() { return $this->sales; }
}

function get_the_terms( $id, $tax ) {
	return $GLOBALS['terms'][ $id ] ?? false;
}

require_once dirname( __DIR__ ) . '/delicat-builder-v9/includes/class-delicat-builder-badges.php';

$pass = 0; $fail = 0;
function group( $n ) { echo "\n$n\n" . str_repeat( '-', strlen( $n ) ) . "\n"; }
function ok( $what, $cond, $detail = '' ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; printf( "  ok    %s\n", $what ); }
	else { $fail++; printf( "  FAIL  %s%s\n", $what, $detail ? '  -- ' . $detail : '' ); }
}

/* =============================================================================
   Tag matching — how tags are actually written
   ============================================================================= */

group( 'Topic badges, from the merchant\'s own tags' );

$cases = array(
	/* slug,                name,                  expected key,        why */
	array( 'jeux',            'Jeux',                'jeux',            'the plain French tag' ),
	array( 'gaming',          'Gaming',              'jeux',            'the English equivalent' ),
	array( 'free-fire',       'Free Fire',           'jeux',            'a specific game' ),
	array( 'finance',         'Finance',             'finance',         'finance' ),
	array( 'moncash',         'MonCash',             'finance',         'the local payment brand' ),
	array( 'gift-card',       'Gift Card',           'gift-card',       'hyphenated' ),
	array( 'giftcard',        'Giftcard',            'gift-card',       'no hyphen' ),
	array( 'carte-cadeau',    'Carte cadeau',        'gift-card',       'French' ),
	array( 'reseaux-sociaux', 'Réseaux Sociaux',     'reseaux-sociaux', 'accents in the name, stripped in the slug' ),
	array( 'resaux-sociaux',  'Resaux Sociaux',      'reseaux-sociaux', 'the misspelling this store actually uses' ),
	array( 'tiktok',          'TikTok',              'reseaux-sociaux', 'a specific network' ),
	array( 'streaming',       'Streaming',           'streaming',       'streaming' ),
	array( 'netflix',         'Netflix',             'streaming',       'a specific service' ),
	array( 'mobile',          'Mobile',              'mobile',          'mobile' ),
	array( 'digicel',         'Digicel',             'mobile',          'the local carrier' ),
	array( 'quelque-chose',   'Quelque chose',       '',                'a tag that is none of the six gets no badge' ),
);

foreach ( $cases as [$slug, $name, $want, $why] ) {
	$GLOBALS['terms'] = array( 1 => array( new WP_Term( $slug, $name ) ) );
	$badge = Delicat_Builder_V9_Badges::topic( new WC_Product( 1 ) );
	$got   = (string) ( $badge['key'] ?? '' );
	ok( sprintf( '%-18s %s', $slug, $why ), $got === $want, "got '$got', want '$want'" );
}

/* A merchant who typed the name with accents but whose slug was auto-generated
   differently: the NAME must match too, not only the slug. */
$GLOBALS['terms'] = array( 1 => array( new WP_Term( 'tag-42', 'Réseaux Sociaux' ) ) );
ok( 'an unhelpful slug still matches on the tag name',
	'reseaux-sociaux' === ( Delicat_Builder_V9_Badges::topic( new WC_Product( 1 ) )['key'] ?? '' ) );

/* Tag order decides, so the merchant controls which badge shows. */
$GLOBALS['terms'] = array( 1 => array( new WP_Term( 'mobile', 'Mobile' ), new WP_Term( 'jeux', 'Jeux' ) ) );
ok( 'the first matching tag wins, so the merchant chooses',
	'mobile' === ( Delicat_Builder_V9_Badges::topic( new WC_Product( 1 ) )['key'] ?? '' ) );

$GLOBALS['terms'] = array();
ok( 'a product with no tags gets no topic badge',
	array() === Delicat_Builder_V9_Badges::topic( new WC_Product( 9 ) ) );

/* =============================================================================
   Status
   ============================================================================= */

group( 'Status badges' );

$GLOBALS['t'] = array( 'delicat_builder_v9_best_seller' => 0 );
Delicat_Builder_V9_Badges::flush_best_seller();
$GLOBALS['t'] = array( 'delicat_builder_v9_best_seller' => 7 );
$GLOBALS['meta'] = array( 7 => array( 'total_sales' => 120 ) );

ok( 'the best seller gets #1',
	'rank-1' === ( Delicat_Builder_V9_Badges::status( new WC_Product( 7, array(), true, 120 ) )['key'] ?? '' ),
	'and it beats Promo, because only one product is number one' );

ok( 'a product on sale gets Promo',
	'promo' === ( Delicat_Builder_V9_Badges::status( new WC_Product( 8, array(), true, 0 ) )['key'] ?? '' ) );

ok( 'a product over the sales threshold gets Top Vente',
	'top-vente' === ( Delicat_Builder_V9_Badges::status( new WC_Product( 8, array(), false, 40 ), 10 )['key'] ?? '' ) );

ok( 'a product under the threshold gets nothing',
	array() === Delicat_Builder_V9_Badges::status( new WC_Product( 8, array(), false, 2 ), 10 ) );

ok( 'never two status badges at once',
	1 >= count( array_filter( array( Delicat_Builder_V9_Badges::status( new WC_Product( 8, array(), true, 999 ), 10 ) ) ) ) );

Delicat_Builder_V9_Badges::flush_best_seller();
$GLOBALS['t'] = array( 'delicat_builder_v9_best_seller' => 0 );
ok( 'a store with no sales has no number one, and product 0 does not exist',
	false === Delicat_Builder_V9_Badges::is_best_seller( new WC_Product( 0 ) ),
	'a zero best-seller id must not match every product' );

/* =============================================================================
   Rendering
   ============================================================================= */

group( 'Rendering' );

$html = Delicat_Builder_V9_Badges::render(
	array( 'key' => 'jeux', 'label' => 'Jeux', 'icon' => 'gamepad', 'tone' => 'topic' ),
	'topic'
);

ok( 'a badge renders its drawn icon', false !== strpos( $html, '<svg' ) );
ok( 'the icon inherits the badge colour', false !== strpos( $html, 'stroke="currentColor"' ) );
ok( 'the icon is hidden from assistive technology', false !== strpos( $html, 'aria-hidden="true"' ) );
ok( 'no emoji reaches the markup', ! preg_match( '/[\x{1F000}-\x{1FAFF}\x{2190}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}]/u', $html ) );

ok( 'a label is escaped', (function () {
	$out = Delicat_Builder_V9_Badges::render(
		array( 'key' => 'x', 'label' => '<img src=x onerror=alert(1)>', 'icon' => 'tag', 'tone' => 'promo' )
	);
	return false === strpos( $out, '<img' ) && false !== strpos( $out, '&lt;img' );
})(), 'a badge label is printed into markup, so an unescaped one is an injection' );

ok( 'an unknown icon name renders no icon rather than broken markup',
	'' === Delicat_Builder_V9_Badges::icon( 'not-an-icon' ) );

ok( 'an empty badge renders nothing at all',
	'' === Delicat_Builder_V9_Badges::render( array() ) );

ok( 'every icon is one drawn set', (function () {
	foreach ( Delicat_Builder_V9_Badges::icon_names() as $n ) {
		$svg = Delicat_Builder_V9_Badges::icon( $n );
		if ( false === strpos( $svg, 'stroke-width="1.75"' ) || false === strpos( $svg, 'viewBox="0 0 24 24"' ) ) {
			return false;
		}
	}
	return true;
})(), 'the old set mixed three sources at three stroke weights' );

ok( 'every topic names an icon that exists', (function () {
	$icons = Delicat_Builder_V9_Badges::icon_names();
	foreach ( Delicat_Builder_V9_Badges::topics() as $t ) {
		if ( ! in_array( $t['icon'], $icons, true ) ) {
			return false;
		}
	}
	return true;
})() );

/* =============================================================================
   Settings written by the old engine
   ============================================================================= */

group( 'Existing Builder pages' );

foreach ( array( 'auto', 'category', 'tag', 'sale', 'stock', 'custom' ) as $legacy ) {
	ok( sprintf( 'a page saved with badge_mode "%s" still renders badges', $legacy ),
		'auto' === Delicat_Builder_V9_Badges::sanitize_mode( $legacy ) );
}
ok( '"off" still means off', 'off' === Delicat_Builder_V9_Badges::sanitize_mode( 'off' ) );
ok( 'an unknown mode falls back to auto', 'auto' === Delicat_Builder_V9_Badges::sanitize_mode( 'nonsense' ) );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
