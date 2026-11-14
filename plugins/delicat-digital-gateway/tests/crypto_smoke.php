<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * DFR_Crypto key ring (4.15).
 *
 *   php tests/crypto_smoke.php
 *
 * Proves: without DELICAT_DFR_KEYS nothing changes (v2); with it new data is v3 and
 * survives a salt rotation or a domain move; v2 data keeps decrypting and is flagged
 * for upgrade; an unknown key id or a wrong context decrypts to nothing.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
define('ABSPATH', __DIR__ . '/');

$SALT = 'salt-one';
$HOME = 'https://delicastoreha.com/';
$OPTIONS = array();
function wp_salt($scheme = 'auth') { global $SALT; return $SALT . '|' . $scheme; }
function home_url($path = '') { global $HOME; return $HOME; }
function __($text) { return $text; }
function get_option($key, $default = false) { global $OPTIONS; return array_key_exists($key, $OPTIONS) ? $OPTIONS[$key] : $default; }
function update_option($key, $value) { global $OPTIONS; $OPTIONS[$key] = $value; return true; }
class WP_Error { public function __construct($code = '', $message = '') {} }

require __DIR__ . '/../includes/class-dfr-crypto.php';

$fail = 0;
function check($ok, $label) { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) { $fail++; } }
function reset_ring() {
    $prop = new ReflectionProperty('DFR_Crypto', 'ring');
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
    $prop->setValue(null, null);
}

$ctx = 'delivery-code|order:4455|item:9';

// Without a ring: v2 as before.
$v2 = DFR_Crypto::encrypt_context('["1234-5678"]', $ctx);
check(is_string($v2) && (strpos($v2, 'x2:') === 0 || strpos($v2, 'g2:') === 0), 'no key ring: v2 envelope, as before');
check(DFR_Crypto::decrypt_context($v2, $ctx) === '["1234-5678"]', 'v2 decrypts');
check(DFR_Crypto::is_current($v2), 'v2 is current while no ring exists');
check(DFR_Crypto::decrypt_context($v2, 'delivery-code|order:1|item:1') === '', 'another order context reads nothing');
check(DFR_Crypto::salt_health() === 'new', 'health: sentinel written on first check');
check(DFR_Crypto::salt_health() === 'ok', 'health: ok while salts are unchanged');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation') === null, 'keyring_hmac: null without a ring');
check(DFR_Crypto::primary_key_id() === '', 'primary_key_id: empty without a ring');

// A first ring with k1 only (as the store had before rotating to k2).
function set_ring(array $ring) {
    $prop = new ReflectionProperty('DFR_Crypto', 'ring');
    if (PHP_VERSION_ID < 80100) { $prop->setAccessible(true); }
    $prop->setValue(null, $ring);
}
function ring_entry($raw) { return hash('sha256', $raw . '|delicat-dfr-keyring', true); }
set_ring(array('k1' => ring_entry(str_repeat('A', 32))));
check(DFR_Crypto::primary_key_id() === 'k1', 'primary_key_id: the first key');
$k1 = DFR_Crypto::encrypt_context('["GIFT-K1"]', $ctx);
check(is_string($k1) && (strpos($k1, 'x3:k1:') === 0 || strpos($k1, 'g3:k1:') === 0), 'sealed with k1 while k1 is primary');
check(DFR_Crypto::is_current($k1), 'k1 envelope is current while k1 is primary');
$mac1 = DFR_Crypto::keyring_hmac('payload', 'wallet-attestation');
check(is_array($mac1) && $mac1['kid'] === 'k1' && preg_match('/^[a-f0-9]{64}$/', $mac1['mac']) === 1, 'keyring_hmac: primary kid and 64 lowercase hex');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation') === $mac1, 'keyring_hmac: deterministic');
check(DFR_Crypto::keyring_hmac('payload', 'reseller-api-key')['mac'] !== $mac1['mac'], 'keyring_hmac: another context, another key');
check(DFR_Crypto::keyring_hmac('payload2', 'wallet-attestation')['mac'] !== $mac1['mac'], 'keyring_hmac: another message, another mac');
reset_ring();

// With a ring.
define('DELICAT_DFR_KEYS', 'k2:' . base64_encode(str_repeat('B', 32)) . ',k1:' . base64_encode(str_repeat('A', 32)));
reset_ring();
check(DFR_Crypto::has_keyring(), 'key ring read from wp-config');
check(DFR_Crypto::primary_key_id() === 'k2', 'primary_key_id follows the new first key');
check(!DFR_Crypto::is_current($v2), 'v2 now flagged for upgrade');
check(DFR_Crypto::decrypt_context($v2, $ctx) === '["1234-5678"]', 'v2 still decrypts with a ring');
check(DFR_Crypto::decrypt_context($k1, $ctx) === '["GIFT-K1"]', 'rotation: a k1 envelope still decrypts under k2,k1');
check(!DFR_Crypto::is_current($k1), 'rotation: a k1 envelope is flagged for re-sealing');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation')['kid'] === 'k2', 'keyring_hmac: signs with the new primary');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation', 'k1') === $mac1, 'keyring_hmac: an old key id still verifies its signatures');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation', 'k9') === null, 'keyring_hmac: unknown key id gives null');
$v3 = DFR_Crypto::encrypt_context('["1234-5678"]', $ctx);
check(is_string($v3) && (strpos($v3, 'x3:k2:') === 0 || strpos($v3, 'g3:k2:') === 0), 'new data: v3 with the primary key id');
check(DFR_Crypto::is_current($v3), 'v3 with the primary key is current');
check(DFR_Crypto::salt_health() === 'keyring', 'health: protected by the ring');
$mac2 = DFR_Crypto::keyring_hmac('payload', 'wallet-attestation');

// Salts rotated and site moved: v3 survives, v2 does not.
$SALT = 'salt-two';
$HOME = 'https://www.delicastoreha.com/';
check(DFR_Crypto::decrypt_context($v3, $ctx) === '["1234-5678"]', 'v3 survives a salt rotation and a domain change');
check(DFR_Crypto::decrypt_context($k1, $ctx) === '["GIFT-K1"]', 'a k1 envelope survives it too');
check(DFR_Crypto::keyring_hmac('payload', 'wallet-attestation') === $mac2, 'keyring_hmac does not depend on the salts or the domain');
check(DFR_Crypto::decrypt_context($v2, $ctx) === '', 'v2 written before the rotation is lost (why the ring exists)');
check(DFR_Crypto::salt_health() === 'changed', 'health: rotation detected and reported');

// Rotation of the ring itself: old key second.
$tampered = preg_replace('/^(x3|g3):k2:/', '$1:k9:', $v3);
check(DFR_Crypto::decrypt_context($tampered, $ctx) === '', 'unknown key id reads nothing');
check(DFR_Crypto::decrypt_context($v3, 'other-context') === '', 'wrong context reads nothing');

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASS\n";
exit($fail ? 1 : 0);
