<?php
/**
 * V10's test suite. Run: php tests/run.php
 *
 * These are not unit tests for their own sake. Each one pins a property that
 * V9 got wrong, so that getting it wrong again fails loudly instead of quietly.
 */

require_once __DIR__ . '/bootstrap.php';

use Delicat\V10\App\ServiceWorker;
use Delicat\V10\Assets;
use Delicat\V10\Context;
use Delicat\V10\Design\Tokens;
use Delicat\V10\Kernel;
use Delicat\V10\Shell\Icons;
use Delicat\V10\Storefront\Product;

$pass = 0;
$fail = 0;

function group( string $name ): void {
	echo "\n" . $name . "\n" . str_repeat( '-', strlen( $name ) ) . "\n";
}

function ok( string $what, bool $condition, string $detail = '' ): void {
	global $pass, $fail;
	if ( $condition ) {
		$pass++;
		printf( "  ok    %s\n", $what );
	} else {
		$fail++;
		printf( "  FAIL  %s%s\n", $what, '' !== $detail ? '  -- ' . $detail : '' );
	}
}

/* =============================================================================
   The module registry
   -----------------------------------------------------------------------------
   V9's bug: its search index was listed only among the modules loaded for admin
   screens, so on the storefront the class did not exist and the search box
   shipped with nothing to search. Nobody noticed because the box looked and
   typed normally.

   The property that prevents it: a module's conditions live in the module, and
   the kernel resolves them. So the test is that resolution actually selects on
   those conditions.
   ============================================================================= */

group( 'Module registry' );

$registry = ( new ReflectionClass( Kernel::class ) )->getConstant( 'MODULES' );

ok( 'every registered module exists and extends Module', (function () use ( $registry ) {
	foreach ( $registry as $class ) {
		if ( ! class_exists( $class ) || ! is_subclass_of( $class, \Delicat\V10\Module::class ) ) {
			return false;
		}
	}
	return true;
})() );

ok( 'no module is registered twice', count( $registry ) === count( array_unique( $registry ) ) );

ok( 'search loads on the storefront, not only in admin', (function () {
	$kinds = \Delicat\V10\Storefront\Search::kinds();
	return in_array( Context::KIND_FRONT, $kinds, true );
})(), 'this is the exact V9 failure' );

ok( 'search is not route-scoped, so the drawer has it everywhere',
	array() === \Delicat\V10\Storefront\Search::routes() );

ok( 'the product dock module only loads on product pages',
	array( Context::ROUTE_PRODUCT ) === \Delicat\V10\Storefront\Product::routes() );

ok( 'no front-end module declares an admin kind it does not need', (function () use ( $registry ) {
	foreach ( $registry as $class ) {
		$kinds = $class::kinds();
		if ( in_array( Context::KIND_ADMIN, $kinds, true ) && 0 !== strpos( $class, 'Delicat\\V10\\Admin' )
			&& \Delicat\V10\Design\Tokens::class !== $class ) {
			return false;
		}
	}
	return true;
})() );

ok( 'every route a module asks for is a route Context can return', (function () use ( $registry ) {
	$valid = array();
	foreach ( ( new ReflectionClass( Context::class ) )->getConstants() as $name => $value ) {
		if ( 0 === strpos( $name, 'ROUTE_' ) ) {
			$valid[] = $value;
		}
	}
	foreach ( $registry as $class ) {
		foreach ( $class::routes() as $route ) {
			if ( ! in_array( $route, $valid, true ) ) {
				return false;
			}
		}
	}
	return true;
})() );

/* =============================================================================
   The token layer
   -----------------------------------------------------------------------------
   V9's bug: fifteen stylesheets each held their own number for the height of
   the bottom chrome, and the homepage used the wrong one, so the bar covered
   the product cards.

   The property that prevents it: there is one declaration of each measurement,
   and the band is derived rather than typed.
   ============================================================================= */

group( 'Design tokens' );

$css = Tokens::css();

ok( 'the token sheet is produced', '' !== $css );

ok( 'the band starts at zero and is raised by chrome, never typed',
	1 === substr_count( $css, '--dlx-band:0px' ) );

ok( 'no stylesheet needs to know the tab bar height, it is a token',
	false !== strpos( $css, '--dlx-tabbar-h:' ) );

ok( 'dark mode restates every light key', (function () {
	$t = Tokens::all();
	return array_keys( $t['color'] ) === array_keys( $t['color-dark'] );
})(), 'a key present in one theme and missing from the other is a colour that breaks in that theme' );

ok( 'light and dark differ for every key', (function () {
	$t = Tokens::all();
	foreach ( $t['color'] as $key => $value ) {
		if ( in_array( $key, array( 'on-media' ), true ) ) {
			continue; /* deliberately identical: it sits over a photograph */
		}
		if ( strtolower( (string) $value ) === strtolower( (string) $t['color-dark'][ $key ] ) ) {
			return false;
		}
	}
	return true;
})() );

ok( 'the three gutter tiers are all stated', (function () {
	$g = Tokens::get( 'gutter', 'phone' );
	$t = Tokens::get( 'gutter', 'tablet' );
	$d = Tokens::get( 'gutter', 'desktop' );
	return is_numeric( $g ) && is_numeric( $t ) && is_numeric( $d ) && $g < $t && $t < $d;
})(), 'V9 left the tablet tier out, so tablets kept the phone gutter' );

ok( 'clamp() keeps the spaces its grammar requires', (function () use ( $css ) {
	preg_match_all( '/clamp\([^)]*\)/', $css, $m );
	foreach ( $m[0] as $fn ) {
		if ( false === strpos( $fn, ' + ' ) ) {
			return false;
		}
	}
	return array() !== $m[0];
})() );

ok( 'an invalid colour cannot reach the stylesheet', (function () {
	add_filter( 'delicat_v10_tokens', function ( $t ) {
		$t['color']['brand'] = 'red; } body { display:none } :root {';
		return $t;
	} );
	Tokens::flush();
	$out = Tokens::css();
	Tokens::flush();
	$GLOBALS['dlx_filters']['delicat_v10_tokens'] = array();
	return false === strpos( $out, 'display:none' );
})(), 'a token is printed into CSS, so an unvalidated one is an injection' );

ok( 'a theme choice is expressible in all three of its states', (function () use ( $css ) {
	return false !== strpos( $css, 'prefers-color-scheme:dark' )
		&& false !== strpos( $css, ':root:not([data-dlx-theme="light"])' )
		&& false !== strpos( $css, ':root[data-dlx-theme="dark"]' );
})() );

/* =============================================================================
   Request classification
   -----------------------------------------------------------------------------
   V9's bug: four modules independently decided whether a response was
   cacheable, and disagreed, so a page marked uncacheable for the page cache was
   still sent to the CDN as public.
   ============================================================================= */

group( 'Request classification' );

function privacy_of( string $uri, array $cookies = array(), bool $logged_in = false ): bool {
	$_SERVER['REQUEST_URI'] = $uri;
	$_COOKIE                = $cookies;
	$GLOBALS['dlx_logged_in'] = $logged_in;
	Context::reset();
	return Context::instance()->is_private();
}

$cases = array(
	array( '/', array(), false, false, 'the homepage is shared' ),
	array( '/boutique/', array(), false, false, 'a category page is shared' ),
	array( '/produit/free-fire/', array(), false, false, 'a product page is shared' ),
	array( '/panier/', array(), false, true, 'the cart is private' ),
	array( '/cart/', array(), false, true, 'the English cart is private' ),
	array( '/checkout/', array(), false, true, 'checkout is private' ),
	array( '/commande/', array(), false, true, 'the French checkout is private' ),
	array( '/mon-compte/', array(), false, true, 'the account is private' ),
	array( '/my-wallet/', array(), false, true, 'the wallet is private' ),
	array( '/order-received/1234/', array(), false, true, 'an order confirmation is private' ),
	array( '/', array( 'wordpress_logged_in_abc' => '1' ), false, true, 'a logged-in cookie makes any page private' ),
	array( '/', array( 'woocommerce_items_in_cart' => '1' ), false, true, 'a non-empty cart makes any page private' ),
	array( '/', array( 'wp_woocommerce_session_x' => '1' ), false, true, 'a Woo session makes any page private' ),
	array( '/', array( '_ga' => '1' ), false, false, 'an analytics cookie does not' ),
);

foreach ( $cases as [$uri, $cookies, $logged, $want, $label] ) {
	$got = privacy_of( $uri, $cookies, $logged );
	ok( $label, $got === $want, 'got ' . ( $got ? 'private' : 'shared' ) );
}

ok( 'anything that is not a front-end page view is private', (function () {
	$_SERVER['REQUEST_URI'] = '/';
	$_COOKIE                = array();
	$_GET['wc-ajax']        = 'get_refreshed_fragments';
	Context::reset();
	$private = Context::instance()->is_private();
	unset( $_GET['wc-ajax'] );
	Context::reset();
	return $private;
})(), 'the safe answer for an unclassified shape is private' );

/* =============================================================================
   The product form
   ============================================================================= */

group( 'Product purchase' );

$form_cases = array(
	array( '<form class="cart" method="post">x</form>', true, 'a simple product form is given an id' ),
	array( '<form action="/p" method="post" class="variations_form cart">x</form>', true, 'a variable product form is given an id' ),
	array( "<form class='cart'>x</form>", true, 'single-quoted attributes are handled' ),
	array( '<form id="mine" class="cart">x</form>', false, 'a form that already has an id keeps it' ),
	array( '<form class="newsletter">x</form>', false, 'a form that is not a cart form is untouched' ),
	array( '', false, 'an empty buffer produces nothing' ),
);

foreach ( $form_cases as [$in, $want, $label] ) {
	$tagged = false !== strpos( Product::tag_form( $in ), 'id="' . Product::form_id() . '"' );
	ok( $label, $tagged === $want );
}

ok( 'V10 declares no payment endpoint of its own', (function () {
	foreach ( glob( DELICAT_V10_DIR . 'src/**/*.php' ) ?: array() as $file ) {
		$body = (string) file_get_contents( $file );
		if ( preg_match( '/register_rest_route|wp_ajax_nopriv_|WC\(\)->checkout\(\)->process_checkout/', $body ) ) {
			return false;
		}
	}
	return true;
})(), 'WooCommerce owns the purchase; anything else here would be a second opinion' );

/* =============================================================================
   Assets and the service worker
   -----------------------------------------------------------------------------
   V9's bug: the worker precached name.css?ver=9.2.0 while every page requested
   name.<hash>.css, so it downloaded six files nothing would ever ask for and
   every request still went to the network.
   ============================================================================= */

group( 'Assets' );

$manifest = Assets::manifest();

ok( 'the build has run and written a manifest', array() !== $manifest,
	'run `node build/build.mjs`' );

if ( array() !== $manifest ) {
	ok( 'every manifest entry points at a file that exists', (function () use ( $manifest ) {
		foreach ( $manifest as $to ) {
			if ( ! is_file( DELICAT_V10_DIR . 'dist/' . $to ) ) {
				return false;
			}
		}
		return true;
	})() );

	ok( 'every built asset is content-addressed', (function () use ( $manifest ) {
		foreach ( $manifest as $to ) {
			if ( ! preg_match( '/\.[0-9a-f]{12}\.(?:css|js)$/', (string) $to ) ) {
				return false;
			}
		}
		return true;
	})() );

	ok( 'the precache asks for exactly the URLs the page requests', (function () use ( $manifest ) {
		$precache = ServiceWorker::precache();
		$pages    = array();
		foreach ( array_keys( $manifest ) as $name ) {
			$pages[] = Assets::url( (string) $name );
		}
		sort( $precache );
		sort( $pages );
		return $precache === $pages;
	})(), 'this is the exact V9 mismatch' );

	ok( 'no precached URL carries a query string', (function () {
		foreach ( ServiceWorker::precache() as $url ) {
			if ( false !== strpos( (string) $url, '?' ) ) {
				return false;
			}
		}
		return true;
	})(), 'the hash is the version; a ?ver on top of it means the manifest was not used' );
}

/* =============================================================================
   Icons
   ============================================================================= */

group( 'Icons' );

ok( 'every icon is one drawn set', (function () {
	foreach ( Icons::names() as $name ) {
		$svg = Icons::svg( $name );
		if ( false === strpos( $svg, 'stroke-width="1.75"' ) || false === strpos( $svg, 'viewBox="0 0 24 24"' ) ) {
			return false;
		}
	}
	return true;
})(), 'V9 mixed three icon sources at three stroke weights' );

ok( 'every icon is hidden from assistive technology', (function () {
	foreach ( Icons::names() as $name ) {
		if ( false === strpos( Icons::svg( $name ), 'aria-hidden="true"' ) ) {
			return false;
		}
	}
	return true;
})(), 'each one sits beside a real label, so announcing it would repeat' );

ok( 'an unknown icon renders nothing rather than a broken glyph', '' === Icons::svg( 'not-an-icon' ) );

/* -------------------------------------------------------------------------- */

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
