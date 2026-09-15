<?php
/**
 * The three decisions that put a Delicat customer on a bare WordPress page.
 *
 *  1. Is the customer's connection encrypted? Every gate in the plugin asked
 *     is_ssl(), which reports the hop between the edge and PHP. Behind
 *     Cloudflare or LiteSpeed that hop is plain http, and the answers that
 *     follow are all wrong in different directions: Google login reports itself
 *     unconfigured, the OAuth return address is built as http:// and refused,
 *     two-factor verification will not run at all, and the session cookie is
 *     issued without the Secure flag.
 *
 *  2. Where does signing out land? Nothing filtered WordPress' default, so it
 *     landed on wp-login.php - and a logout link that a cache outlived produced
 *     WordPress' "Voulez-vous réellement vous déconnecter ?" dead end instead.
 *
 *  3. Where does a failed sign-in land? On wp-login.php, with a WordPress
 *     notice, shown to someone who was in the storefront a second earlier.
 *
 * The classes are loaded and called against stubbed WordPress functions, so
 * these assertions move when the code moves.
 *
 * Run: php tests/identity-auth-test.php
 */

$root = __DIR__ . '/../delicat-identity-pro';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok    $what\n"; }
    else { $fail++; echo "  FAIL  $what" . ($detail ? "  -- $detail" : '') . "\n"; }
}
function group(string $n): void { echo "\n$n\n" . str_repeat('-', strlen($n)) . "\n"; }

/* ------------------------------------------------------------------ */
/* Just enough WordPress                                              */
/* ------------------------------------------------------------------ */

define('ABSPATH', __DIR__ . '/');

$GLOBALS['stub_is_ssl']    = false;
$GLOBALS['stub_home']      = 'https://delicastoreha.com';
$GLOBALS['stub_logged_in'] = true;
$GLOBALS['stub_user']      = null;
$GLOBALS['stub_nonce_ok']  = false;
$GLOBALS['stub_filters']   = [];
$GLOBALS['stub_logged_out'] = false;
$GLOBALS['stub_audit']     = [];

class Redirected extends RuntimeException {
    public $url;
    public function __construct($url) { $this->url = $url; parent::__construct('redirect'); }
}

class WP_User {
    public $ID = 0;
    public $caps = [];
    public function __construct($id = 0, array $caps = []) { $this->ID = $id; $this->caps = $caps; }
    public function exists() { return $this->ID > 0; }
}

function is_ssl() { return (bool) $GLOBALS['stub_is_ssl']; }
function home_url($p = '/') { return rtrim($GLOBALS['stub_home'], '/') . $p; }
function wp_parse_url($url, $component = -1) { return parse_url((string) $url, $component); }
function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }
function sanitize_key($k) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', (string) $k)); }
function sanitize_text_field($t) { return trim(strip_tags((string) $t)); }
function esc_url_raw($u) { return (string) $u; }
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function is_admin() { return false; }
function add_action() {}
function add_filter() {}
function nocache_headers() {}
function did_action($hook) { return 0; }
function wc_get_page_permalink($page) { return $GLOBALS['stub_home'] . '/mon-compte/'; }
$GLOBALS['stub_options'] = [];
function get_option($k, $d = false) { return $GLOBALS['stub_options'][$k] ?? $d; }
function current_user_can($cap) { return false; }
function get_user_meta() { return ''; }
function get_current_screen() { return null; }
function wp_json_encode($v) { return json_encode($v); }
function wp_create_nonce($a = '') { return 'n'; }
function check_ajax_referer() { return true; }
function update_user_meta() {}
function wp_send_json_error() {}
function wp_send_json_success() {}
function esc_html__($t, $d = null) { return $t; }
function absint($n) { return abs((int) $n); }
function apply_filters($tag, $value) {
    return isset($GLOBALS['stub_filters'][$tag]) ? call_user_func($GLOBALS['stub_filters'][$tag], $value) : $value;
}
function is_user_logged_in() { return (bool) $GLOBALS['stub_logged_in']; }
function wp_get_current_user() { return $GLOBALS['stub_user'] ?: new WP_User(0); }
function user_can($user, $cap) { return $user instanceof WP_User && in_array($cap, $user->caps, true); }
function wp_verify_nonce($nonce, $action) { return $GLOBALS['stub_nonce_ok'] && $nonce !== '' ? 1 : false; }
function wp_logout() { $GLOBALS['stub_logged_out'] = true; $GLOBALS['stub_logged_in'] = false; }
function wp_safe_redirect($url) { throw new Redirected($url); }
function hash_equals_shim($a, $b) { return hash_equals($a, $b); }

/** Only same-host URLs survive, like the real thing. */
function wp_validate_redirect($location, $default = '') {
    $location = trim((string) $location);
    if ($location === '') return $default;
    if (strpos($location, '/') === 0 && strpos($location, '//') !== 0) return rtrim($GLOBALS['stub_home'], '/') . $location;
    $host = strtolower((string) parse_url($location, PHP_URL_HOST));
    $home = strtolower((string) parse_url($GLOBALS['stub_home'], PHP_URL_HOST));
    return ($host !== '' && $host === $home) ? $location : $default;
}

function add_query_arg($key, $value = null, $url = null) {
    if (is_array($key)) { $url = $value; $pairs = $key; } else { $pairs = [$key => $value]; }
    $url = (string) $url;
    $frag = '';
    if (false !== ($h = strpos($url, '#'))) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query);
    foreach ($pairs as $k => $v) { $query[$k] = $v; }
    $qs = http_build_query($query);
    return $parts[0] . ($qs ? '?' . $qs : '') . $frag;
}
function remove_query_arg($keys, $url = null) {
    $keys = (array) $keys;
    $url = (string) $url;
    $frag = '';
    if (false !== ($h = strpos($url, '#'))) { $frag = substr($url, $h); $url = substr($url, 0, $h); }
    $parts = explode('?', $url, 2);
    parse_str($parts[1] ?? '', $query);
    foreach ($keys as $k) unset($query[$k]);
    $qs = http_build_query($query);
    return $parts[0] . ($qs ? '?' . $qs : '') . $frag;
}

class DIP_Audit {
    public static function record($event, $severity = 'info', $user = 0, $context = []) {
        $GLOBALS['stub_audit'][] = $event;
    }
}

require_once "$root/includes/class-dip-request.php";
require_once "$root/includes/class-dip-logout.php";

/** Re-run the helper against one request's worth of headers. */
function secure_with(array $server, bool $php_tls = false, string $home = 'https://delicastoreha.com'): bool {
    $GLOBALS['stub_is_ssl'] = $php_tls;
    $GLOBALS['stub_home']   = $home;
    foreach (['HTTP_X_FORWARDED_PROTO','HTTP_X_FORWARDED_SCHEME','HTTP_X_FORWARDED_SSL','HTTP_CF_VISITOR'] as $k) unset($_SERVER[$k]);
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
    /* is_secure() memoises per request; a test IS a new request each time. */
    $reset = new ReflectionProperty('DIP_Request', 'secure');
    $reset->setAccessible(true);
    $reset->setValue(null, null);
    return DIP_Request::is_secure();
}

/* ------------------------------------------------------------------ */

group('An https site behind a proxy is recognised as https');

ok('PHP itself on TLS, no headers', secure_with([], true));
ok('X-Forwarded-Proto: https', secure_with(['HTTP_X_FORWARDED_PROTO' => 'https']));
ok('X-Forwarded-Scheme: https', secure_with(['HTTP_X_FORWARDED_SCHEME' => 'https']));
ok('X-Forwarded-SSL: on', secure_with(['HTTP_X_FORWARDED_SSL' => 'on']));
ok('CF-Visitor says https', secure_with(['HTTP_CF_VISITOR' => '{"scheme":"https"}']));
ok('uppercase HTTPS counts', secure_with(['HTTP_X_FORWARDED_PROTO' => 'HTTPS']));
ok('a chain is read from the client end', secure_with(['HTTP_X_FORWARDED_PROTO' => 'https, http']));

group('and everything else is not');

ok('no headers, no TLS', !secure_with([]));
ok('X-Forwarded-Proto: http', !secure_with(['HTTP_X_FORWARDED_PROTO' => 'http']));
ok('CF-Visitor says http', !secure_with(['HTTP_CF_VISITOR' => '{"scheme":"http"}']));
ok('X-Forwarded-SSL: off', !secure_with(['HTTP_X_FORWARDED_SSL' => 'off']));
ok('a chain whose client hop was http', !secure_with(['HTTP_X_FORWARDED_PROTO' => 'http, https']),
   'https on an inner hop says nothing about the customer');

group('A plain-http install cannot be talked into claiming encryption');

foreach ([
    'HTTP_X_FORWARDED_PROTO'  => 'https',
    'HTTP_X_FORWARDED_SCHEME' => 'https',
    'HTTP_X_FORWARDED_SSL'    => 'on',
    'HTTP_CF_VISITOR'         => '{"scheme":"https"}',
] as $header => $value) {
    ok("$header ignored when the site's own home URL is http",
        !secure_with([$header => $value], false, 'http://delicastoreha.com'),
        'otherwise a forged header is a free pass through every gate, including the Secure cookie flag');
}
ok('but real TLS still counts there', secure_with([], true, 'http://delicastoreha.com'));

group('The operator has the last word');

$GLOBALS['stub_filters']['dip_request_is_secure'] = static function () { return true; };
ok('a filter can vouch for an unusual front end', secure_with([]));
$GLOBALS['stub_filters']['dip_request_is_secure'] = static function () { return false; };
ok('and can withdraw the proxy headers', !secure_with(['HTTP_X_FORWARDED_PROTO' => 'https']));
$GLOBALS['stub_filters'] = [];

group('WordPress itself is told what this class knows');

/*
 * Fixing every gate inside the plugin fixes nothing outside it, and outside it
 * is where the damage is: WordPress core decides the Secure flag on its own
 * auth cookies with is_ssl(), and so does WooCommerce, and so does the theme.
 */
function repair(array $server, bool $php_tls, string $home, array $options = [], $constant = null): array {
    unset($_SERVER['HTTPS']);
    $GLOBALS['stub_options'] = $options;
    secure_with($server, $php_tls, $home);            // primes the same request state
    $applied = DIP_Request::share_with_wordpress();
    return [$applied, $_SERVER['HTTPS'] ?? null];
}

$proxy = ['HTTP_X_FORWARDED_PROTO' => 'https'];

list($applied, $flag) = repair($proxy, false, 'https://delicastoreha.com');
ok('an https site behind a proxy gets is_ssl() repaired', $applied === true && $flag === 'on',
   'without this, core issues the session cookie without the Secure flag');

list($applied, $flag) = repair([], true, 'https://delicastoreha.com');
ok('a site PHP already sees as TLS is left alone', $applied === false && $flag === null,
   'there is nothing to repair');

list($applied, $flag) = repair($proxy, false, 'http://delicastoreha.com');
ok('a plain-http install is NEVER touched', $applied === false && $flag === null,
   'marking every cookie Secure there would lock out every customer');

list($applied, $flag) = repair([], false, 'https://delicastoreha.com');
ok('nor is a customer who genuinely arrived over http', $applied === false && $flag === null);

list($applied) = repair(['HTTP_CF_VISITOR' => '{"scheme":"http"}'], false, 'https://delicastoreha.com');
ok('Cloudflare saying the customer used http is believed', $applied === false);

list($applied) = repair($proxy, false, 'https://delicastoreha.com', ['dglp_settings' => ['trust_proxy_https' => 'no']]);
ok('and an administrator can switch it off', $applied === false);

$GLOBALS['stub_filters']['dip_trust_proxy_https'] = static function () { return false; };
list($applied) = repair($proxy, false, 'https://delicastoreha.com');
ok('so can a filter', $applied === false);
$GLOBALS['stub_filters'] = [];

/*
 * An operator with an unusual front end can filter is_secure() to true. That
 * is their call for the plugin's own gates. It must NOT become permission to
 * mark every cookie on the site Secure while WordPress is still publishing
 * http:// URLs, because the browser would then drop them and nobody could sign
 * in at all. The home URL is the thing that decides this one.
 */
$GLOBALS['stub_filters']['dip_request_is_secure'] = static function () { return true; };
list($applied, $flag) = repair([], false, 'http://delicastoreha.com');
ok('and a filter cannot force it onto an http:// site', $applied === false && $flag === null,
   'home_url() is what decides whether Secure cookies can work at all');
$GLOBALS['stub_filters'] = [];

group('The OAuth return address uses the scheme the SITE is published under');

$GLOBALS['stub_home'] = 'https://delicastoreha.com';
$GLOBALS['stub_is_ssl'] = false;
$_SERVER['REQUEST_URI'] = '/produit/free-fire/?x=1';
ok('https even when PHP was reached over http',
   DIP_Request::current_url() === 'https://delicastoreha.com/produit/free-fire/?x=1',
   'Google refuses a redirect_uri that does not match the registered one: ' . DIP_Request::current_url());

$_SERVER['HTTP_HOST'] = 'evil.example';
ok('the host comes from home_url(), never from the Host header',
   false === strpos(DIP_Request::current_url(), 'evil.example'));
unset($_SERVER['HTTP_HOST']);

$_SERVER['REQUEST_URI'] = '//evil.example/steal';
ok('a protocol-relative REQUEST_URI cannot escape the site',
   DIP_Request::current_url() === 'https://delicastoreha.com/');
$_SERVER['REQUEST_URI'] = "/ok\r\n/split";
ok('a header-splitting REQUEST_URI is dropped',
   DIP_Request::current_url() === 'https://delicastoreha.com/');
$_SERVER['REQUEST_URI'] = '/panier/';

group('Rescuing a logout is limited to real, same-origin navigation');

function nav_with(array $server): bool {
    foreach (['HTTP_SEC_FETCH_SITE','HTTP_SEC_FETCH_DEST','HTTP_SEC_FETCH_MODE','HTTP_REFERER'] as $k) unset($_SERVER[$k]);
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
    return DIP_Request::same_origin_navigation();
}

ok('a link clicked on our own page', nav_with([
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_MODE' => 'navigate']));
ok('a typed URL or a bookmark', nav_with(['HTTP_SEC_FETCH_SITE' => 'none']));
ok('another site\'s page is refused', !nav_with([
    'HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_MODE' => 'navigate']));
ok('a sibling subdomain is refused', !nav_with(['HTTP_SEC_FETCH_SITE' => 'same-site', 'HTTP_SEC_FETCH_DEST' => 'document']));
ok('an <img> on our own page is refused', !nav_with([
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'image', 'HTTP_SEC_FETCH_MODE' => 'no-cors']),
   'a logout hidden in an image tag is exactly what the nonce was defending against');
ok('an <iframe> is refused', !nav_with([
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'iframe', 'HTTP_SEC_FETCH_MODE' => 'navigate']));
ok('a fetch() is refused', !nav_with([
    'HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'empty', 'HTTP_SEC_FETCH_MODE' => 'cors']));

ok('an older browser falls back to a same-origin Referer',
   nav_with(['HTTP_REFERER' => 'https://delicastoreha.com/mon-compte/']));
ok('and a foreign Referer is refused',
   !nav_with(['HTTP_REFERER' => 'https://evil.example/trap']));
ok('no Sec-Fetch and no Referer is refused', !nav_with([]),
   'this only ever rescues; it never replaces a nonce');

/* ------------------------------------------------------------------ */

group('Signing out returns to the storefront, never to wp-login.php');

$GLOBALS['stub_home'] = 'https://delicastoreha.com';
$customer = new WP_User(42, []);
$staff    = new WP_User(1, ['edit_posts', 'manage_woocommerce']);

$dest = DIP_Logout::destination();
ok('the destination is the shop front', 0 === strpos($dest, 'https://delicastoreha.com/'), $dest);
ok('and it tells the storefront to refresh its session', false !== strpos($dest, 'dip_auth_sync=1'),
   'Builder V9 session.js reads this to drop a cached signed-in shell');

$out = DIP_Logout::logout_redirect('https://delicastoreha.com/wp-login.php?loggedout=true', '', $customer);
ok('WordPress\' own default is replaced for a customer', false === strpos($out, 'wp-login.php'), $out);

$out = DIP_Logout::logout_redirect('https://delicastoreha.com/wp-login.php?loggedout=true', '', $staff);
ok('an administrator keeps the WordPress form', false !== strpos($out, 'wp-login.php'),
   'signing out of the dashboard should land on the dashboard login');

$out = DIP_Logout::logout_redirect('x', 'https://delicastoreha.com/produit/free-fire/', $customer);
ok('a link that asked for a real page is honoured', 0 === strpos($out, 'https://delicastoreha.com/produit/free-fire/'), $out);

$out = DIP_Logout::logout_redirect('x', 'https://delicastoreha.com/wp-admin/', $customer);
ok('but it may not point a customer into wp-admin', false === strpos($out, 'wp-admin'), $out);

$out = DIP_Logout::logout_redirect('x', 'https://evil.example/', $customer);
ok('nor off-site', false === strpos($out, 'evil.example'), $out);

$out = DIP_Logout::logout_redirect('x', 'https://delicastoreha.com/mon-compte/', $customer);
ok('and not at WooCommerce\'s account page either', false === strpos($out, '/mon-compte/'),
   'to someone who has just signed out that page is another login form: ' . $out);

group('Every logout link carries a destination, whoever built it');

$url = DIP_Logout::logout_url('https://delicastoreha.com/wp-login.php?action=logout&_wpnonce=abc', '');
ok('a bare logout link gains one', false !== strpos($url, 'redirect_to='), $url);
ok('and keeps its nonce', false !== strpos($url, '_wpnonce=abc'),
   'the nonce is still the primary check; nothing here replaces it');

$url = DIP_Logout::logout_url('https://delicastoreha.com/wp-login.php?action=logout&redirect_to=%2Fpanier%2F', '/panier/');
parse_str((string) wp_parse_url($url, PHP_URL_QUERY), $q);
ok('an explicit caller keeps its own destination', ($q['redirect_to'] ?? '') === '/panier/',
   'add_query_arg REPLACES a key, so overwriting here silently loses the page the caller asked for: ' . ($q['redirect_to'] ?? '(none)'));

group('A logout nonce a cache outlived is completed, not turned into a dead end');

/** Run rescue_stale_logout() and report where it sent the browser, or ''. */
function rescue(array $server, array $request, $user, bool $nonce_ok, bool $logged_in = true): string {
    $GLOBALS['stub_logged_in'] = $logged_in;
    $GLOBALS['stub_user'] = $user;
    $GLOBALS['stub_nonce_ok'] = $nonce_ok;
    $GLOBALS['stub_logged_out'] = false;
    $_REQUEST = $request;
    foreach (['HTTP_SEC_FETCH_SITE','HTTP_SEC_FETCH_DEST','HTTP_SEC_FETCH_MODE','HTTP_REFERER'] as $k) unset($_SERVER[$k]);
    foreach ($server as $k => $v) $_SERVER[$k] = $v;
    try { DIP_Logout::rescue_stale_logout(); return ''; }
    catch (Redirected $r) { return (string) $r->url; }
}

$same = ['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'document', 'HTTP_SEC_FETCH_MODE' => 'navigate'];

$where = rescue($same, ['_wpnonce' => 'stale'], $customer, false);
ok('a stale nonce on our own page still signs the customer out', $where !== '', 'this is the screen the customer reported');
ok('and it actually logs them out', $GLOBALS['stub_logged_out'] === true);
ok('landing on the storefront', $where !== '' && false === strpos($where, 'wp-login.php'), $where);
ok('and the recovery is recorded', in_array('logout_nonce_recovered', $GLOBALS['stub_audit'], true),
   'an administrator should be able to see this happening');

ok('a missing nonce on our own page is the same case',
   rescue($same, [], $customer, false) !== '');

ok('a VALID nonce is left entirely to WordPress',
   rescue($same, ['_wpnonce' => 'good'], $customer, true) === '',
   'the nonce is checked first and still wins');

ok('a cross-site request still gets WordPress\' confirmation',
   rescue(['HTTP_SEC_FETCH_SITE' => 'cross-site', 'HTTP_SEC_FETCH_DEST' => 'document'], ['_wpnonce' => 'x'], $customer, false) === '',
   'a logout CSRF must still be stopped');

ok('and so does a hidden <img> logout',
   rescue(['HTTP_SEC_FETCH_SITE' => 'same-origin', 'HTTP_SEC_FETCH_DEST' => 'image'], [], $customer, false) === '');

ok('an administrator keeps the WordPress flow', rescue($same, [], $staff, false) === '');
ok('nobody signed in, nothing to rescue', rescue($same, [], $customer, false, false) === '');

group('A signed-out customer is not left standing on wp-login.php');

/** Run leave_login_page() and report where it sent the browser, or ''. */
function leave(array $get): string {
    $GLOBALS['stub_logged_in'] = false;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $get;
    $_REQUEST = $get;
    try { DIP_Logout::leave_login_page(); return ''; }
    catch (Redirected $r) { return (string) $r->url; }
}

$where = leave(['loggedout' => 'true']);
ok('the "?loggedout=true" landing goes to the shop', $where !== '' && false === strpos($where, 'wp-login'), $where);

$where = leave(['dip_error' => 'invalid_state']);
ok('a social-login error goes to the storefront modal', false !== strpos($where, 'dip_auth_error=invalid_state'), $where);
ok('with the modal open', false !== strpos($where, '#delicat-login'), $where);

ok('the real login form is untouched', leave([]) === '', 'wp-login.php must stay reachable');
ok('a password reset is untouched', leave(['action' => 'lostpassword', 'loggedout' => 'true']) === '');
ok('a registration is untouched', leave(['action' => 'register']) === '');
ok('an admin sent here for wp-admin keeps the form',
   leave(['loggedout' => 'true', 'redirect_to' => 'https://delicastoreha.com/wp-admin/']) === '');

$GLOBALS['stub_filters']['dip_logout_leave_login_page'] = static function () { return false; };
ok('and a site can switch the whole behaviour off', leave(['loggedout' => 'true']) === '');
$GLOBALS['stub_filters'] = [];

/* ------------------------------------------------------------------ */

group('No gate is left asking is_ssl()');

$offenders = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/includes")) as $file) {
    if ($file->getExtension() !== 'php') continue;
    if ($file->getFilename() === 'class-dip-request.php') continue;   // where the evidence is read
    /* Code only. A docblock is free to name the function it warns against, and
       so is a sentence shown to an administrator explaining what was repaired. */
    $src = '';
    $strings = '';
    foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token)) { $src .= $token; continue; }
        if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) continue;
        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) { $strings .= $token[1]; continue; }
        $src .= $token[1];
    }
    /* ...but a string is still not allowed to BE the call. */
    if (preg_match('/[\'\"]is_ssl[\'\"]/', $strings)) $offenders[] = $file->getFilename() . ' (callable string)';
    /* Every call is a gate here: a condition, a ternary, a cookie's Secure
       flag, or a value handed to something that decides one. */
    if (preg_match('/(?<![a-z_])is_ssl\s*\(\s*\)/', $src)) $offenders[] = $file->getFilename();
}
ok('every module asks DIP_Request::is_secure() instead', $offenders === [], implode(', ', $offenders));

$plugin = (string) file_get_contents("$root/includes/class-dip-plugin.php");
/** The body of one block, by brace matching, so this pins structure not text. */
function block_after(string $src, string $opener): string {
    $at = strpos($src, $opener);
    if ($at === false) return '';
    $depth = 0;
    for ($i = strpos($src, '{', $at); $i !== false && $i < strlen($src); $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) return substr($src, $at, $i - $at + 1); }
    }
    return '';
}

$fail_body = block_after($plugin, 'private function fail($message, $code, $count_failure = true)');
ok('DIP_Plugin::fail() was found to read', $fail_body !== '');
$guarded = block_after($fail_body, 'if ($started_on_wp_screen)');
ok('the wp-login.php branch exists and is guarded', $guarded !== '');
ok('wp-login.php is reachable ONLY from inside that guard',
   substr_count($fail_body, 'wp_login_url()') === 1 && false !== strpos($guarded, 'wp_login_url()'),
   'every other failure must return the customer to the storefront');
ok('and the storefront branch carries the code the modal can explain',
   false !== strpos($fail_body, "add_query_arg('dip_auth_error', \$code, \$storefront)"));
ok('with the popup opened on arrival',
   (bool) preg_match("/\\\$storefront\) \. '#delicat-login'/", $fail_body));

$auth = (string) file_get_contents("$root/includes/class-dip-native-auth.php");
ok('security nonces are no longer written into the page',
   false === strpos($auth, 'class="dipx-nonce-login"') && false === strpos($auth, 'class="dipx-nonce-register"'),
   'a hidden input is readable by every script on the page');

group('The dialog\'s stylesheets are off the critical path, except when they cannot be');

/*
 * Roughly 32KB of render-blocking CSS sat in <head> of every page a signed-out
 * customer opened, for a dialog nothing had asked for yet. Deferring it is only
 * safe while the dialog cannot appear before the stylesheet does, so the pages
 * where it opens by itself keep the blocking load.
 */
require_once "$root/includes/class-dip-native-auth.php";

$link = "<link rel='stylesheet' id='dip-identity-modal-v3-css' href='/modal.css' media='all' />\n";

function defer_on(array $get, string $tag, string $handle = 'dip-identity-modal-v3'): string {
    $_GET = $get;
    return DIP_Native_Auth::defer_modal_style_tag($tag, $handle);
}

$out = defer_on([], $link);
ok('an ordinary shop page loads it without blocking', false !== strpos($out, "media=\"print\""), $out);
ok('and applies it the moment it arrives', false !== strpos($out, "this.media='all'"));
ok('with a plain fallback for a browser without JavaScript', false !== strpos($out, '<noscript>'));
ok('the stylesheet is still actually requested', 2 === substr_count($out, '/modal.css'),
   'deferring must never mean dropping');

foreach (['dip_auth_error' => 'invalid_state', 'dip_verified' => '1', 'dip_identity_open' => '1'] as $flag => $value) {
    ok(
        "a page that opens the dialog by itself ($flag) keeps the blocking load",
        defer_on([$flag => $value], $link) === $link,
        'otherwise the customer sees the dialog before its stylesheet and it flashes unstyled'
    );
}

ok('an unrelated stylesheet is never touched',
   defer_on([], $link, 'theme-main') === $link);
ok('and a stylesheet already deferred by someone else is left alone',
   defer_on([], "<link rel='stylesheet' href='/m.css' media='print' onload=\"this.media='all'\" />") !== ''
   && false === strpos(defer_on([], "<link rel='stylesheet' href='/m.css' media='print' onload=\"x\" />"), '<noscript>'));

group('The popup markup still carries every hook its script binds to');

/*
 * The script and the markup live in different files and different languages.
 * When they drift the popup does not throw - it silently stops working, which
 * is how this module has failed before. Each selector below is one the rebuilt
 * script queries; if PHP stops emitting it, this says so.
 */
$js = (string) file_get_contents("$root/assets/identity-modal-v5.js");
foreach ([
    '.dipx-card'            => 'the dialog itself',
    '.dipx-form-login'      => 'the sign-in form',
    '.dipx-form-register'   => 'the registration form',
    '.dipx-message'         => 'where every message is shown',
    '.dipx-submit'          => 'the button',
    '.dipx-submit-label'    => 'its label',
    '.dipx-spinner'         => 'its spinner',
    '.dipx-header'          => 'the drag handle',
    '.dipx-subtitle'        => 'the line under the brand',
] as $selector => $what) {
    $class = ltrim($selector, '.');
    $in_markup = false !== strpos($auth, 'class="' . $class . '"')
        || false !== strpos($auth, 'class="' . $class . ' ')
        || false !== strpos($auth, ' ' . $class . '"')
        || false !== strpos($auth, ' ' . $class . ' ');
    ok("$selector — $what", false !== strpos($js, $selector) && $in_markup,
        'the script looks for it; PHP must still render it');
}
ok(
    '.dipx-backdrop — the surface that closes on a tap',
    false !== strpos($auth, 'class="dipx-backdrop"') && false !== strpos($auth, 'dipx-backdrop" data-dip-auth-close'),
    'the stylesheet draws it and the close hook lives on it'
);
foreach ([
    'data-dipx-tab'      => 'the Connexion / Inscription tabs',
    'data-dipx-eye'      => 'the show-password button',
    'data-dipx-2fa'      => 'the two-factor step',
    'data-dipx-resend'   => 'the resend-verification button',
    'data-dip-auth-close' => 'everything that closes the dialog',
] as $hook => $what) {
    ok("[$hook] — $what", false !== strpos($js, $hook) && false !== strpos($auth, $hook));
}
ok(
    'and the honeypot is still submitted with the form',
    false !== strpos($auth, 'name="dl_hp"'),
    'the server records a filled honeypot as a failed attempt'
);

echo "\n$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
