<?php
/**
 * Delicat Store theme — unit tests for the pure functions.
 *
 * Run: php tests/theme/run.php
 * No WordPress needed: the handful of WordPress functions the files call at
 * load time are stubbed below, and only functions that take plain values and
 * return plain values are exercised.
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'DS_DIR', dirname( __DIR__, 2 ) . '/delicat-store/' );
define( 'DS_URI', 'https://example.test/wp-content/themes/delicat-store/' );
define( 'DS_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

foreach ( array( 'add_action', 'add_filter', 'remove_action', 'add_shortcode' ) as $fn ) {
	eval( "function {$fn}() { return true; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return (string) $s; }
function wp_kses_post( $s ) { return (string) $s; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_theme_mod( $k, $d = null ) { return $d; }

require DS_DIR . 'inc/helpers.php';
require DS_DIR . 'inc/icons.php';
require DS_DIR . 'inc/badges.php';
require DS_DIR . 'inc/product-fields.php';
require DS_DIR . 'inc/bon-kliyan.php';
require DS_DIR . 'inc/legal.php';

$pass = 0;
$fail = 0;
function ok( bool $cond, string $name ): void {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		return;
	}
	++$fail;
	echo "FAIL: {$name}\n";
}
function same( $expected, $actual, string $name ): void {
	ok( $expected === $actual, $name . ' — expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

/* ---- helpers ---------------------------------------------------------- */
same( 'reseaux-sociaux', ds_normalize_term( 'Réseaux Sociaux' ), 'normalize accents' );
same( 'reseaux-sociaux', ds_normalize_term( 'reseaux_sociaux' ), 'normalize underscore' );
same( 'resaux-sociaux', ds_normalize_term( '  Resaux-Sociaux ' ), 'normalize trims' );
same( 'gift-card', ds_normalize_term( 'GIFT   CARD' ), 'normalize collapses spaces' );
same( '', ds_normalize_term( '---' ), 'normalize empty' );
same( 'Jean D.', ds_mask_name( 'jean DUPONT' ), 'mask two names' );
same( 'Stanley', ds_mask_name( 'stanley' ), 'mask single name' );
same( 'Client', ds_mask_name( '   ' ), 'mask empty' );
same( 'Marie-Ange P.', ds_mask_name( 'Marie-Ange  Louis Pierre' ), 'mask keeps hyphenated first name, uses last word' );
same( '50933111283', ds_digits( '+509 33 11 12 83' ), 'digits' );
same( '#ffffff', ds_ink_for( '#6d5dfc' ), 'ink on brand is white' );
same( '#111827', ds_ink_for( '#f2b01e' ), 'ink on gold is dark' );
same( '#ffffff', ds_shade( '#000000', 1.0 ), 'shade to white' );
same( '#000000', ds_shade( '#ffffff', -1.0 ), 'shade to black' );
same( '#6d5dfc', ds_hex( '#6D5DFC', '#000' ), 'hex lowercases' );
same( '#000', ds_hex( 'red', '#000' ), 'hex fallback' );
ok( abs( ds_luminance( '#ffffff' ) - 1.0 ) < 0.001, 'luminance white' );
ok( ds_luminance( '#000000' ) < 0.001, 'luminance black' );
same( 'G 1 250', ds_format_amount( 1250.4 ), 'amount format' );

/* ---- icons ------------------------------------------------------------ */
ok( str_contains( ds_icon( 'bolt' ), '<svg' ) && str_contains( ds_icon( 'bolt' ), 'aria-hidden' ), 'icon renders decorative' );
ok( str_contains( ds_icon( 'bolt', '', 'Rapide' ), 'aria-label="Rapide"' ), 'icon renders labelled' );
same( '', ds_icon( 'does-not-exist' ), 'unknown icon is empty' );
foreach ( ds_icons() as $name => $svg ) {
	ok( ! preg_match( '/[\x{1F300}-\x{1FAFF}]/u', $svg ), "icon {$name} has no emoji" );
}

/* ---- badges ----------------------------------------------------------- */
same( 'reseaux-sociaux', ds_badge_topic_for( array( 'Resaux-Sociaux' ) ), 'topic: store misspelling' );
same( 'reseaux-sociaux', ds_badge_topic_for( array( 'Réseaux Sociaux' ) ), 'topic: accents' );
same( 'finance', ds_badge_topic_for( array( 'MonCash' ) ), 'topic: brand alias' );
same( 'finance', ds_badge_topic_for( array( 'usdt' ) ), 'topic: usdt' );
same( 'streaming', ds_badge_topic_for( array( 'Netflix' ) ), 'topic: netflix' );
same( 'mobile', ds_badge_topic_for( array( 'Digicel' ) ), 'topic: digicel' );
same( 'jeux', ds_badge_topic_for( array( 'Free Fire' ) ), 'topic: free fire' );
same( 'gift-card', ds_badge_topic_for( array( 'Cartes cadeaux' ) ), 'topic: cartes cadeaux' );
same( 'streaming', ds_badge_topic_for( array( 'promo', 'Abonnement', 'Jeux' ) ), 'topic: first matching tag wins' );
same( 'jeux', ds_badge_topic_for( array( 'jeux', 'streaming' ) ), 'topic: order decides' );
same( null, ds_badge_topic_for( array( 'nouveau', '' ) ), 'topic: none' );
same( 'rank-1', ds_badge_status_for( true, true, 100, 10 )['key'], 'status: #1 beats promo' );
same( 'promo', ds_badge_status_for( false, true, 100, 10 )['key'], 'status: promo beats top' );
same( 'top-vente', ds_badge_status_for( false, false, 10, 10 )['key'], 'status: top at threshold' );
same( null, ds_badge_status_for( false, false, 9, 10 ), 'status: under threshold' );
same( null, ds_badge_status_for( false, false, 900, 0 ), 'status: threshold 0 disables top' );
ok( str_contains( ds_badge_html( array( 'key' => 'promo', 'label' => 'Promo', 'icon' => 'tag', 'tone' => 'promo' ) ), 'ds-badge--promo' ), 'badge html' );

/* ---- product fields --------------------------------------------------- */
$player = ds_fields_sanitize_field( array( 'label' => 'Player ID', 'type' => 'text', 'required' => '1', 'pattern' => '[0-9]{5,20}', 'min_length' => '5' ) );
same( 'player_id', $player['id'], 'field id derived from label' );
same( 1, $player['required'], 'field required normalised' );
same( 5, $player['min_length'], 'field min_length int' );
same( '', $player['max_length'], 'field max_length empty' );
same( 'number', ds_fields_sanitize_field( array( 'id' => 'q', 'type' => 'range' ) )['type'], 'range becomes number' );
same( 'text', ds_fields_sanitize_field( array( 'id' => 'q', 'type' => 'unknown' ) )['type'], 'unknown type becomes text' );
same( null, ds_fields_sanitize_field( array( 'type' => 'text' ) ), 'field without id/label dropped' );
same( null, ds_fields_sanitize_field( 'nope' ), 'non-array dropped' );
$sel = ds_fields_sanitize_field( array( 'id' => 'server', 'label' => 'Serveur', 'type' => 'select', 'options' => array( 'Asie', array( 'value' => 'eu', 'label' => 'Europe', 'price' => '50' ), array( 'label' => '' ) ) ) );
same( 2, count( $sel['options'] ), 'options: strings and arrays, empties dropped' );
same( 'Asie', $sel['options'][0]['value'], 'options: string option value' );
same( 50.0, $sel['options'][1]['price'], 'options: price float' );

$cfg = array( 'enabled' => 1, 'fields' => array( $player, $sel, ds_fields_sanitize_field( array( 'id' => 'vip', 'label' => 'VIP', 'type' => 'toggle', 'price' => 100 ) ), ds_fields_sanitize_field( array( 'id' => 'note', 'label' => 'Note', 'type' => 'heading' ) ) ) );
$r = ds_fields_validate( $cfg, array( 'player_id' => ' 123456789 ', 'server' => 'eu', 'vip' => 'yes' ) );
same( array(), $r['errors'], 'validate: clean input' );
same( '123456789', $r['values']['player_id'], 'validate: trimmed' );
same( 'yes', $r['values']['vip'], 'validate: toggle yes' );
$r = ds_fields_validate( $cfg, array( 'player_id' => '12ab', 'server' => 'mars' ) );
same( 2, count( $r['errors'] ), 'validate: pattern and unknown option fail' );
$r = ds_fields_validate( $cfg, array() );
ok( count( $r['errors'] ) === 1 && str_contains( $r['errors'][0], 'Player ID' ), 'validate: only required field reported' );
$r = ds_fields_validate( $cfg, array( 'player_id' => array( 'x' ), 'server' => '' ) );
ok( count( $r['errors'] ) >= 1, 'validate: array for scalar rejected' );
same( '', ds_fields_validate( $cfg, array( 'player_id' => "1234567\n89" ) )['values']['player_id'] === '1234567 89' ? '' : 'x', 'validate: newlines collapsed' );
$email = array( 'enabled' => 1, 'fields' => array( ds_fields_sanitize_field( array( 'id' => 'e', 'label' => 'E-mail', 'type' => 'email', 'required' => 1 ) ) ) );
same( 1, count( ds_fields_validate( $email, array( 'e' => 'not-an-email' ) )['errors'] ), 'validate: email' );
same( array(), ds_fields_validate( $email, array( 'e' => 'a@b.co' ) )['errors'], 'validate: email ok' );
$num = array( 'enabled' => 1, 'fields' => array( ds_fields_sanitize_field( array( 'id' => 'n', 'label' => 'N', 'type' => 'number', 'min' => 1, 'max' => 5, 'price' => 10 ) ) ) );
same( 1, count( ds_fields_validate( $num, array( 'n' => '9' ) )['errors'] ), 'validate: number max' );
same( 30.0, ds_fields_extra( $num, array( 'n' => 3.0 ), 100.0 ), 'extra: number × price' );
same( 50.0, ds_fields_extra( $num, array( 'n' => 9.0 ), 100.0 ), 'extra: number clamped to max' );
same( 150.0, ds_fields_extra( $cfg, array( 'server' => 'eu', 'vip' => 'yes' ), 100.0 ), 'extra: option + toggle' );
same( 0.0, ds_fields_extra( $cfg, array( 'server' => 'Asie' ), 100.0 ), 'extra: free option' );
$pct = array( 'enabled' => 1, 'fields' => array( ds_fields_sanitize_field( array( 'id' => 'x', 'label' => 'X', 'type' => 'toggle', 'price' => 10, 'price_type' => 'percent' ) ) ) );
same( 20.0, ds_fields_extra( $pct, array( 'x' => 'yes' ), 200.0 ), 'extra: percent' );
ok( ds_fields_priced( $cfg ) && ! ds_fields_priced( $email ), 'priced detection' );
ok( ds_fields_has_required( $cfg ) && ! ds_fields_has_required( null ), 'required detection' );
$labels = ds_fields_labels( $cfg, array( 'player_id' => '123456789', 'server' => 'eu', 'vip' => 'yes' ) );
same( array( 'Player ID' => '123456789', 'Serveur' => 'Europe', 'VIP' => 'Oui' ), $labels['labels'], 'labels for display' );
$secret = array( 'enabled' => 1, 'fields' => array( ds_fields_sanitize_field( array( 'id' => 's', 'label' => '_Code', 'type' => 'password' ) ) ) );
$labels = ds_fields_labels( $secret, array( 's' => 'hunter2' ) );
same( 'hunter2', $labels['secrets']['Code'], 'secret kept aside, underscore stripped' );
same( '••••••', $labels['labels']['Code'], 'secret masked in labels' );

/* ---- Bon Kliyan ------------------------------------------------------- */
$zone = new DateTimeZone( 'America/Port-au-Prince' );
list( $start, $end ) = ds_kliyan_window( new DateTimeImmutable( '2026-09-16 15:00:00', $zone ) ); // a Wednesday
same( '2026-09-14 00:00:00', $start->format( 'Y-m-d H:i:s' ), 'window starts Monday' );
same( '2026-09-20 23:59:59', $end->format( 'Y-m-d H:i:s' ), 'window ends Sunday' );
list( $start ) = ds_kliyan_window( new DateTimeImmutable( '2026-09-14 00:00:01', $zone ) );
same( '2026-09-14', $start->format( 'Y-m-d' ), 'window on Monday itself' );
list( $start ) = ds_kliyan_window( new DateTimeImmutable( '2026-09-20 23:59:00', $zone ) );
same( '2026-09-14', $start->format( 'Y-m-d' ), 'window on Sunday night' );
list( $start ) = ds_kliyan_window( new DateTimeImmutable( '2026-09-21 03:00:00', new DateTimeZone( 'UTC' ) ) ); // still Sunday 23:00 in Haiti
same( '2026-09-14', $start->format( 'Y-m-d' ), 'window converts from UTC' );
$rows  = array(
	array( 'customer' => 'u1', 'name' => 'Jean Dupont', 'total' => 500.0, 'created' => 100 ),
	array( 'customer' => 'u2', 'name' => 'Marie Louis', 'total' => 900.0, 'created' => 200 ),
	array( 'customer' => 'u1', 'name' => 'Jean Dupont', 'total' => 500.0, 'created' => 300 ),
	array( 'customer' => 'u3', 'name' => 'Paul', 'total' => 900.0, 'created' => 50 ),
	array( 'customer' => '', 'name' => 'Ghost', 'total' => 9999.0, 'created' => 1 ),
);
$board = ds_kliyan_rank( $rows, 5 );
same( 'u1', $board[0]['customer'], 'rank: totals aggregated (900 from two orders)' );
same( 2, $board[0]['orders'], 'rank: order count' );
same( 'u3', $board[1]['customer'], 'rank: tie broken by earlier first order' );
same( 'u2', $board[2]['customer'], 'rank: tie loser third' );
same( 3, count( $board ), 'rank: empty customer skipped' );
same( 2, count( ds_kliyan_rank( $rows, 2 ) ), 'rank: size cap' );

/* ---- legal ------------------------------------------------------------ */
same( 'Hello Delicat at delicastoreha.com', ds_legal_replace( 'Hello {{COMPANY}} at {{SITE_HOST}}', array( '{{COMPANY}}' => 'Delicat', '{{SITE_HOST}}' => 'delicastoreha.com' ) ), 'legal token replacement' );
same( 10, count( ds_legal_documents() ), 'ten legal documents' );
foreach ( ds_legal_documents() as $key => $doc ) {
	ok( is_readable( DS_DIR . 'legal/' . $doc['file'] ), "legal file exists: {$doc['file']}" );
	$body = (string) file_get_contents( DS_DIR . 'legal/' . $doc['file'] );
	preg_match_all( '/\{\{[A-Z_]+\}\}/', $body, $m );
	$known = array( 'COMPANY', 'SITE_URL', 'SITE_HOST', 'UPDATED', 'EMAIL_SUPPORT', 'EMAIL_PRIVACY', 'EMAIL_SECURITY', 'WHATSAPP_URL', 'WHATSAPP_LABEL', 'LEGAL_FORM', 'ADDRESS', 'TAX_ID', 'PUBLISHER', 'HOST_NAME', 'HOST_ADDRESS', 'HOST_URL', 'URL_CONDITIONS', 'URL_PRIVACY', 'URL_REFUND', 'URL_SHIPPING', 'URL_COOKIES', 'URL_EXCHANGE', 'URL_MINORS', 'URL_LEGAL', 'URL_REVIEWS', 'URL_CONTEST' );
	foreach ( array_unique( $m[0] ) as $token ) {
		ok( in_array( trim( $token, '{}' ), $known, true ), "legal {$doc['file']}: token {$token} is known" );
	}
}

/* ---- sample data ------------------------------------------------------ */
$csv = fopen( DS_DIR . 'sample-data/products.csv', 'r' );
$head = fgetcsv( $csv );
$rows = 0;
$meta_col = array_search( 'Meta: _dmc_calc', $head, true );
while ( ( $row = fgetcsv( $csv ) ) !== false ) {
	++$rows;
	same( count( $head ), count( $row ), "csv row {$rows} column count" );
	if ( '' !== $row[ $meta_col ] ) {
		$decoded = json_decode( $row[ $meta_col ], true );
		ok( is_array( $decoded ) && ! empty( $decoded['fields'] ), "csv row {$rows} field JSON decodes" );
		foreach ( $decoded['fields'] as $f ) {
			ok( null !== ds_fields_sanitize_field( $f ), "csv row {$rows} field sanitises" );
		}
	}
}
ok( $rows >= 16, 'csv has at least 16 products' );

echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
