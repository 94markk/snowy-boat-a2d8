<?php
/**
 * Delicat Identity Pro — passkeys E2E fixtures (TEST SITES ONLY).
 *
 * Usage: php fixtures.php <siteDir> <baseUrl> <command> [args...]
 * Prints one JSON document on stdout.
 *
 * Commands:
 *   setup                         create/refresh users, pages, Woo stub, clear passkeys/2FA/rate state
 *   reset-user <login>            remove passkeys, 2FA, reauth markers for one user
 *   add-passkey <login> <credIdB64u> <pemFile> <label> [signCount]
 *   passkeys <login>              stored credentials (id,label,sign_count,last_used)
 *   clear-passkeys <login>
 *   enable-2fa <login>            enable TOTP (returns base32 secret + recovery codes)
 *   disable-2fa <login>
 *   expire-ceremonies             force every pending passkey ceremony past its expiry
 *   ceremony-count                number of stored passkey ceremonies
 *   expire-reauth                 delete every recent-auth marker and age every session's sign-in time
 *                                 (simulates the recent-authentication window passing, C1 included)
 *   set-auth-method <key> <value> update one e-mail sign-in setting (otp_enabled, magic_enabled…)
 *   last-mail [toSubstring]       newest captured mail (to, subject, message)
 *   reset-rate                    clear passkey rate buckets and counters
 *   set-setting <key> <value>     update one Delicat Identity setting
 *   audit <login> [limit]         recent audit events for the user
 *   mail-count                    number of captured mails
 *   user-meta <login> <metaKey>
 */
if (PHP_SAPI !== 'cli') exit(1);
$argv = $_SERVER['argv'];
if (count($argv) < 4) { fwrite(STDERR, "usage: php fixtures.php <siteDir> <baseUrl> <command> [args]\n"); exit(2); }
list(, $site, $base, $cmd) = $argv;
$args = array_slice($argv, 4);
$parts = parse_url($base);
$_SERVER['HTTP_HOST'] = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = $parts['host'];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define('WP_USE_THEMES', false);
require rtrim($site, '/') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

function out($data) { echo wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n"; exit(0); }
function fail($msg) { echo wp_json_encode(['error' => $msg]), "\n"; exit(1); }
function user_or_fail($login) { $u = get_user_by('login', $login); if (!$u) fail("user $login not found"); return $u; }

const FX_USERS = [
    // login => [email, password, role, passwordless]
    'pk_user'   => ['pk_user@example.test',   'PkUser!2026-pass',   'subscriber', false],
    'pk_tfa'    => ['pk_tfa@example.test',    'PkTfa!2026-pass',    'subscriber', false],
    'pk_pwless' => ['pk_pwless@example.test', 'PkPwless!2026-pass', 'subscriber', true],
    'pk_other'  => ['pk_other@example.test',  'PkOther!2026-pass',  'subscriber', false],
    // Google-only customer (no usable password; Google link simulated because OAuth cannot run offline).
    'pk_google' => ['pk_google@example.test', 'PkGoogle!2026-pass', 'subscriber', true],
];

function fx_reset_user($uid) {
    delete_user_meta($uid, DIP_Passkeys::META_CREDENTIALS);
    foreach (['_dip_totp_enabled','_dip_totp_secret_v1','_dip_totp_pending_v1','_dip_totp_pending_expires','_dip_totp_recovery_hashes_v1','_dip_totp_last_counter'] as $k) delete_user_meta($uid, $k);
    if (class_exists('WP_Session_Tokens')) WP_Session_Tokens::get_instance($uid)->destroy_all();
}

function fx_delete_options_like($like) {
    global $wpdb;
    $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
    foreach ($names as $n) delete_option($n);
    return count($names);
}

function fx_ensure_page($slug, $title, $content) {
    $page = get_page_by_path($slug, OBJECT, 'page');
    $data = ['post_type'=>'page','post_status'=>'publish','post_name'=>$slug,'post_title'=>$title,'post_content'=>$content];
    if ($page) { $data['ID'] = $page->ID; wp_update_post($data); return (int) $page->ID; }
    return (int) wp_insert_post($data);
}

function fx_woo_stub($site) {
    // Minimal WooCommerce "my account" emulation for sites without WooCommerce:
    // the plugin only loads passkeys.js on is_account_page(), wp-login.php or
    // the logged-out modal. Never installed when WooCommerce is really active.
    $file = rtrim($site, '/') . '/wp-content/mu-plugins/dip-e2e-woo-stub.php';
    $code = <<<'PHP'
<?php
// Test-only stub written by passkeys-e2e fixtures.php. Emulates the WooCommerce
// helpers Delicat Identity Pro uses to detect the customer account page.
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) return;
    if (!function_exists('is_account_page')) {
        function is_account_page() { return is_page('my-account'); }
    }
    if (!function_exists('wc_get_account_endpoint_url')) {
        function wc_get_account_endpoint_url($endpoint) { return home_url('/my-account/'); }
    }
    if (!function_exists('wc_get_page_permalink')) {
        function wc_get_page_permalink($page) { return $page === 'myaccount' ? home_url('/my-account/') : home_url('/'); }
    }
}, 0);
PHP;
    if (!is_file($file) || file_get_contents($file) !== $code) file_put_contents($file, $code);
    return $file;
}

switch ($cmd) {
case 'setup':
    global $wp_rewrite;
    if (get_option('permalink_structure') !== '/%postname%/') $wp_rewrite->set_permalink_structure('/%postname%/');
    $wp_rewrite->flush_rules(false);
    $users = [];
    foreach (FX_USERS as $login => list($email, $pass, $role, $pwless)) {
        $u = get_user_by('login', $login);
        if (!$u) {
            $id = wp_insert_user(['user_login'=>$login,'user_email'=>$email,'user_pass'=>$pass,'role'=>$role,'display_name'=>ucfirst(str_replace('_',' ',$login))]);
            if (is_wp_error($id)) fail($id->get_error_message());
        } else {
            $id = $u->ID;
            wp_set_password($pass, $id);
            wp_update_user(['ID'=>$id,'user_email'=>$email,'role'=>$role]);
        }
        fx_reset_user($id);
        // Verified customer email (DIP_Account_Sync::login_guard blocks unverified accounts).
        update_user_meta($id, 'dip_email_verified', 'yes');
        update_user_meta($id, '_dip_verified_email_fingerprint', hash('sha256', strtolower($email)));
        if ($pwless) update_user_meta($id, DIP_Identity::META_PASSWORD_MANAGED, 'yes');
        else delete_user_meta($id, DIP_Identity::META_PASSWORD_MANAGED);
        if ($login === 'pk_google') { update_user_meta($id, DIP_Identity::META_SUB, 'e2e-google-sub-' . $id); update_user_meta($id, DIP_Identity::META_LINKED_AT, time()); }
        else { delete_user_meta($id, DIP_Identity::META_SUB); delete_user_meta($id, DIP_Identity::META_LINKED_AT); }
        $users[$login] = ['id'=>(int)$id,'login'=>$login,'email'=>$email,'password'=>$pass,'handle'=>DIP_Passkeys::user_handle($id)];
    }
    $admin = get_user_by('login', 'admin');
    if ($admin) { delete_user_meta($admin->ID, DIP_Passkeys::META_CREDENTIALS); $users['admin'] = ['id'=>(int)$admin->ID,'login'=>'admin','email'=>$admin->user_email,'password'=>'AdminPass!2026','handle'=>DIP_Passkeys::user_handle($admin->ID)]; }
    $pages = [
        'account'  => fx_ensure_page('my-account', 'Mon compte', '[delicat_identity_dashboard]'),
        'wallet'   => fx_ensure_page('my-wallet', 'Mon portefeuille', 'Portefeuille E2E'),
        'security' => fx_ensure_page('centre-securite', 'Centre de sécurité', '[delicat_security_center]'),
        'landing'  => fx_ensure_page('e2e-landing', 'E2E Landing', 'E2E landing page'),
        'authpanel'=> fx_ensure_page('e2e-auth-panel', 'E2E Auth panel', '[delicat_auth_panel]'),
    ];
    $urls = [];
    foreach ($pages as $k => $id) $urls[$k] = get_permalink($id);
    $stub = class_exists('WooCommerce') ? '' : fx_woo_stub($site);
    // Default settings the suite relies on.
    $s = (array) get_option(DIP_Plugin::OPTION, []);
    $s['passkeys_available'] = 'yes';
    $s['passkeys_max_per_user'] = 8;
    $s['native_modal_enabled'] = 'yes';
    update_option(DIP_Plugin::OPTION, $s);
    if (class_exists('DIP_Passwordless_Registration')) {
        $o = (array) get_option(DIP_Passwordless_Registration::OPTION, []);
        $o['otp_enabled'] = 'yes'; $o['magic_enabled'] = 'yes';
        update_option(DIP_Passwordless_Registration::OPTION, $o, false);
    }
    $cleared = fx_delete_options_like('_transient_dip_pk_%') + fx_delete_options_like('_transient_timeout_dip_pk_%') + fx_delete_options_like('dip_pk_lock_%') + fx_delete_options_like('_transient_dip_reauth_%');
    global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}dip_rate_counters");
    out(['users'=>$users,'pages'=>$urls,'woo_stub'=>$stub,'rp_id'=>DIP_Passkeys::rp_id(),'available'=>DIP_Passkeys::available(),'cleared'=>$cleared,'woocommerce'=>class_exists('WooCommerce')]);

case 'reset-user':
    $u = user_or_fail($args[0] ?? ''); fx_reset_user($u->ID); out(['ok'=>true]);

case 'add-passkey':
    $u = user_or_fail($args[0] ?? '');
    $id = (string) ($args[1] ?? ''); $pem = file_get_contents((string) ($args[2] ?? '')); $label = (string) ($args[3] ?? 'E2E key');
    if (DIP_Passkeys::b64u_decode($id) === false || !$pem || !openssl_pkey_get_public($pem)) fail('bad credential');
    $raw = get_user_meta($u->ID, DIP_Passkeys::META_CREDENTIALS, true); if (!is_array($raw)) $raw = [];
    $raw[] = ['id'=>$id,'public_key'=>$pem,'alg'=>-7,'sign_count'=>absint($args[4] ?? 0),'transports'=>['internal'],'label'=>$label,'created'=>time(),'last_used'=>0];
    update_user_meta($u->ID, DIP_Passkeys::META_CREDENTIALS, $raw);
    out(['ok'=>true,'count'=>count($raw),'handle'=>DIP_Passkeys::user_handle($u->ID)]);

case 'passkeys':
    $u = user_or_fail($args[0] ?? '');
    $raw = get_user_meta($u->ID, DIP_Passkeys::META_CREDENTIALS, true);
    $list = [];
    foreach ((array) $raw as $e) if (is_array($e)) $list[] = ['id'=>$e['id'] ?? '','label'=>$e['label'] ?? '','sign_count'=>$e['sign_count'] ?? 0,'last_used'=>$e['last_used'] ?? 0,'transports'=>$e['transports'] ?? []];
    out(['count'=>count($list),'passkeys'=>$list,'handle'=>(string) get_user_meta($u->ID, DIP_Passkeys::META_USER_HANDLE, true)]);

case 'clear-passkeys':
    $u = user_or_fail($args[0] ?? ''); delete_user_meta($u->ID, DIP_Passkeys::META_CREDENTIALS); out(['ok'=>true]);

case 'enable-2fa':
    $u = user_or_fail($args[0] ?? '');
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $secret = '';
    for ($i = 0; $i < 32; $i++) $secret .= $alphabet[random_int(0, 31)];
    update_user_meta($u->ID, '_dip_totp_secret_v1', DIP_Crypto::encrypt($secret));
    update_user_meta($u->ID, '_dip_totp_enabled', 'yes');
    $codes = ['E2EA-AAAA-BBBB-CCCC', 'E2EB-AAAA-BBBB-CCCC'];
    update_user_meta($u->ID, '_dip_totp_recovery_hashes_v1', array_map(function ($c) { return wp_hash_password(strtoupper(preg_replace('/[^A-Z0-9]/', '', $c))); }, $codes));
    delete_user_meta($u->ID, '_dip_totp_last_counter');
    out(['ok'=>true,'secret'=>$secret,'recovery'=>$codes,'enabled'=>DIP_Two_Factor::is_enabled($u->ID)]);

case 'disable-2fa':
    $u = user_or_fail($args[0] ?? '');
    foreach (['_dip_totp_enabled','_dip_totp_secret_v1','_dip_totp_recovery_hashes_v1','_dip_totp_last_counter'] as $k) delete_user_meta($u->ID, $k);
    out(['ok'=>true]);

case 'expire-ceremonies':
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_dip_pk_ceremony_%'));
    $n = 0;
    foreach ($rows as $r) {
        $v = maybe_unserialize($r->option_value);
        if (!is_array($v)) continue;
        $v['expires'] = time() - 5;
        update_option($r->option_name, $v, false); $n++;
    }
    out(['expired'=>$n]);

case 'ceremony-count':
    global $wpdb;
    out(['count'=>(int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_dip_pk_ceremony_%'))]);

case 'expire-reauth':
    // Markers (DIP_Reauth::mark_recent) and, since a fresh sign-in counts as
    // recent authentication (contract C1), the sessions' 'login' timestamps.
    // Sessions carrying 'dip_auth_at' (re-issued on a password change, app
    // pairing) are judged on that value instead: age it too when present.
    global $wpdb;
    $aged = 0;
    foreach ($wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", 'session_tokens')) as $uid) {
        $tokens = get_user_meta((int) $uid, 'session_tokens', true);
        if (!is_array($tokens)) continue;
        foreach ($tokens as $verifier => $session) {
            if (!is_array($session)) continue;
            $tokens[$verifier]['login'] = time() - 2 * HOUR_IN_SECONDS;
            if (array_key_exists('dip_auth_at', $session)) $tokens[$verifier]['dip_auth_at'] = time() - 2 * HOUR_IN_SECONDS;
            $aged++;
        }
        update_user_meta((int) $uid, 'session_tokens', $tokens);
    }
    out(['deleted'=>fx_delete_options_like('_transient_dip_reauth_%') + fx_delete_options_like('_transient_timeout_dip_reauth_%'), 'aged_sessions'=>$aged]);

case 'set-auth-method':
    if (!class_exists('DIP_Passwordless_Registration')) fail('DIP_Passwordless_Registration missing');
    $o = (array) get_option(DIP_Passwordless_Registration::OPTION, []);
    $key = (string) ($args[0] ?? ''); if ($key === '') fail('key required');
    $o[$key] = (string) ($args[1] ?? '');
    update_option(DIP_Passwordless_Registration::OPTION, $o, false);
    out(['ok'=>true, $key=>$o[$key]]);

case 'last-mail':
    $dir = WP_CONTENT_DIR . '/test-mail';
    $files = is_dir($dir) ? glob($dir . '/*.json') : [];
    sort($files);
    $needle = (string) ($args[0] ?? '');
    for ($i = count($files) - 1; $i >= 0; $i--) {
        $j = json_decode((string) file_get_contents($files[$i]), true);
        $to = is_array($j['to'] ?? '') ? implode(',', $j['to']) : (string) ($j['to'] ?? '');
        if ($needle !== '' && stripos($to, $needle) === false) continue;
        out(['to'=>$to, 'subject'=>(string) ($j['subject'] ?? ''), 'message'=>(string) ($j['message'] ?? ''), 'file'=>basename($files[$i])]);
    }
    out(['to'=>'', 'subject'=>'', 'message'=>'']);

case 'reset-rate':
    global $wpdb;
    $n = fx_delete_options_like('_transient_dip_pk_rate_%') + fx_delete_options_like('_transient_timeout_dip_pk_rate_%') + fx_delete_options_like('_transient_dip_reauth_rate_%');
    $wpdb->query("DELETE FROM {$wpdb->prefix}dip_rate_counters");
    out(['cleared'=>$n]);

case 'set-setting':
    $s = (array) get_option(DIP_Plugin::OPTION, []);
    $key = (string) ($args[0] ?? ''); $val = (string) ($args[1] ?? '');
    if ($key === '') fail('key required');
    $s[$key] = is_numeric($val) ? (int) $val : $val;
    update_option(DIP_Plugin::OPTION, $s);
    out(['ok'=>true,$key=>$s[$key]]);

case 'audit':
    $u = user_or_fail($args[0] ?? '');
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare('SELECT event_type, severity, created_at FROM ' . DIP_Audit::table() . ' WHERE user_id=%d ORDER BY id DESC LIMIT %d', $u->ID, absint($args[1] ?? 20)), ARRAY_A);
    out(['events'=>$rows]);

case 'mail-count':
    $dir = WP_CONTENT_DIR . '/test-mail';
    $files = is_dir($dir) ? glob($dir . '/*.json') : [];
    $subjects = [];
    foreach ($files as $f) { $j = json_decode((string) file_get_contents($f), true); $subjects[] = ['to'=>$j['to'] ?? '', 'subject'=>$j['subject'] ?? '', 'file'=>basename($f)]; }
    out(['count'=>count($files),'mails'=>$subjects]);

case 'user-meta':
    $u = user_or_fail($args[0] ?? '');
    out(['value'=>get_user_meta($u->ID, (string) ($args[1] ?? ''), true)]);

default:
    fail("unknown command $cmd");
}
