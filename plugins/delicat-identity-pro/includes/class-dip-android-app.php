<?php
defined('ABSPATH') || exit;

/**
 * 6.11.0 — The Android app and the site's passkeys.
 *
 * Android's Credential Manager only lets com.delicatstore.app use the site's
 * passkeys (RP ID = the site's host) when the site publishes a Digital Asset
 * Links statement for the app's signing certificates:
 *   https://<site>/.well-known/assetlinks.json
 * and the app's WebAuthn answers then carry the origin
 *   android:apk-key-hash:<base64url, no padding, of the certificate SHA-256>.
 *
 * ONE setting (one fingerprint per line) produces both the published file and
 * the mobile origins accepted by DIP_Mobile_Passkeys, so the two can never
 * disagree. Certificate fingerprints and the package name are public values,
 * not secrets.
 */
final class DIP_Android_App {
    const DEFAULT_PACKAGE = 'com.delicatstore.app';
    /** Upload key (website APK + local builds), then the Google Play app signing key. */
    const DEFAULT_FINGERPRINTS = "82:13:05:70:CC:86:31:A5:DF:86:C6:A3:A1:87:41:7F:E5:97:78:E2:D2:10:38:7F:47:22:3A:4A:BE:6F:00:0A\n1C:C9:58:7B:1D:EB:7E:26:E3:DF:F3:41:EE:5E:8F:36:7E:E5:40:4C:2E:56:13:B8:9B:14:3A:79:36:28:37:10";
    const RELATION = 'delegate_permission/common.get_login_creds';
    const PATH = '/.well-known/assetlinks.json';
    const TEST_ACTION = 'dip_assetlinks_test';
    const TEST_OPTION = 'dip_assetlinks_test_v1';
    const MAX_FINGERPRINTS = 10;

    private static $initialized = false;

    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        // Before redirects, maintenance pages and page caches that run later.
        add_action('init', [__CLASS__, 'maybe_serve'], 0);
        add_action('admin_post_' . self::TEST_ACTION, [__CLASS__, 'handle_test']);
        add_action('admin_notices', [__CLASS__, 'admin_notices']);
    }

    private static function settings() {
        return wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
    }

    // -------------------------------------------------------------------------
    // Fingerprints and origins (one source for the file and the WebAuthn check)
    // -------------------------------------------------------------------------

    /**
     * Upper-case hex, 32 bytes separated by colons, or '' when the line is not
     * a SHA-256 fingerprint. Accepted input: AA:BB:… or 64 hex digits, any
     * case, optionally followed by a note after a space (« … (clé d’envoi) »).
     * 6.12.0: a « SHA256: » / « SHA-256: » prefix (keytool, Play Console) is
     * accepted too.
     */
    public static function normalize_fingerprint($line) {
        $line = trim(is_scalar($line) ? (string) $line : '');
        $line = (string) preg_replace('/^SHA-?256\s*:\s*/i', '', $line);
        if (!preg_match('/^((?:[0-9A-Fa-f]{2}:){31}[0-9A-Fa-f]{2}|[0-9A-Fa-f]{64})(?:\s.*)?$/sD', $line, $m)) return '';
        $hex = strtoupper(str_replace(':', '', $m[1]));
        return implode(':', str_split($hex, 2));
    }

    /** ['valid' => normalized unique fingerprints, 'invalid' => the refused lines]. */
    public static function parse_fingerprints($text) {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim((string) $line);
            if ($line === '') continue;
            $normalized = self::normalize_fingerprint($line);
            if ($normalized === '') {
                $invalid[] = function_exists('mb_substr') ? mb_substr($line, 0, 120) : substr($line, 0, 120);
                continue;
            }
            if (!in_array($normalized, $valid, true)) $valid[] = $normalized;
        }
        return ['valid' => $valid, 'invalid' => $invalid];
    }

    /** The test-build fingerprint counts only while its box is ticked. */
    private static function test_fingerprints_enabled(array $s) {
        return ($s['mobile_android_test_cert_enabled'] ?? 'no') === 'yes';
    }

    /** Every fingerprint the app may be signed with: production lines, then the test one when enabled. */
    public static function fingerprints() {
        $s = self::settings();
        $list = self::parse_fingerprints($s['mobile_android_cert_sha256'] ?? '')['valid'];
        if (self::test_fingerprints_enabled($s)) {
            foreach (self::parse_fingerprints($s['mobile_android_test_cert_sha256'] ?? '')['valid'] as $fingerprint) {
                if (!in_array($fingerprint, $list, true)) $list[] = $fingerprint;
            }
        }
        return array_slice($list, 0, self::MAX_FINGERPRINTS);
    }

    /** Lines of the two settings that were ignored, for the admin notice. */
    public static function invalid_lines() {
        $s = self::settings();
        $invalid = self::parse_fingerprints($s['mobile_android_cert_sha256'] ?? '')['invalid'];
        if (self::test_fingerprints_enabled($s)) {
            $invalid = array_merge($invalid, self::parse_fingerprints($s['mobile_android_test_cert_sha256'] ?? '')['invalid']);
        }
        return $invalid;
    }

    public static function valid_package($value) {
        $value = trim((string) $value);
        return strlen($value) <= 255 && preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/D', $value) ? $value : '';
    }

    public static function package() {
        $package = self::valid_package(self::settings()['mobile_android_package'] ?? '');
        return $package !== '' ? $package : self::DEFAULT_PACKAGE;
    }

    /** android:apk-key-hash:<base64url without padding of the 32 certificate bytes>. */
    public static function origin_for($fingerprint) {
        $normalized = self::normalize_fingerprint($fingerprint);
        if ($normalized === '') return '';
        $raw = hex2bin(str_replace(':', '', $normalized));
        return 'android:apk-key-hash:' . rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** The only origins a mobile WebAuthn ceremony accepts (DIP_Mobile_Passkeys). */
    public static function origins() {
        $origins = [];
        foreach (self::fingerprints() as $fingerprint) {
            $origin = self::origin_for($fingerprint);
            if ($origin !== '') $origins[] = $origin;
        }
        return $origins;
    }

    // -------------------------------------------------------------------------
    // /.well-known/assetlinks.json
    // -------------------------------------------------------------------------

    public static function statements() {
        return [[
            'relation' => [self::RELATION],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => self::package(),
                'sha256_cert_fingerprints' => self::fingerprints(),
            ],
        ]];
    }

    public static function body() {
        return (string) wp_json_encode(self::statements(), JSON_UNESCAPED_SLASHES);
    }

    /** Path the file is served at: exactly the site's own /.well-known/assetlinks.json. */
    public static function served_path() {
        return (string) wp_parse_url(home_url(self::PATH), PHP_URL_PATH);
    }

    public static function enabled() {
        return (self::settings()['assetlinks_enabled'] ?? 'yes') === 'yes';
    }

    /**
     * 6.11.0: what Android reads at /.well-known/assetlinks.json grants every
     * app fingerprint: a physical file on the hosting (the web server serves
     * it before WordPress, whatever « Publier assetlinks.json » says) must
     * list them all for the app's package, otherwise the plugin's own answer
     * must be on (publication ticked, at least one valid fingerprint). The
     * capability passkeys_enabled follows it (DIP_Mobile_Passkeys::enabled()).
     */
    public static function published() {
        $expected = self::fingerprints();
        if (!$expected) return false;
        $file = self::physical_file();
        if (@is_file($file)) {
            $raw = @file_get_contents($file, false, null, 0, 65536);
            $found = self::fingerprints_in_file(is_string($raw) ? json_decode($raw, true) : null, self::package());
            return !array_diff($expected, $found);
        }
        // 6.12.0: Android only reads https://<host>/.well-known/assetlinks.json.
        // With the site's address in a sub-folder, WordPress cannot answer there.
        if (!self::served_at_host_root()) return false;
        return self::enabled();
    }

    /** The plugin's answer is at the host's own /.well-known/assetlinks.json (the site's address is not in a sub-folder). */
    public static function served_at_host_root() {
        return self::served_path() === self::PATH;
    }

    /**
     * The answer for one request, or null when this request is not ours (wrong
     * path or method, publication off, no valid fingerprint): WordPress then
     * carries on as if the plugin were not there.
     */
    public static function response_for($method, $request_uri) {
        $method = strtoupper(trim((string) $method));
        if ($method !== 'GET' && $method !== 'HEAD') return null;
        if (!self::enabled()) return null;
        $path = wp_parse_url((string) $request_uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path !== self::served_path()) return null;
        if (!self::fingerprints()) return null;
        return [
            'status' => 200,
            'headers' => [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'public, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
            ],
            'body' => $method === 'HEAD' ? '' : self::body(),
        ];
    }

    /** init, priority 0: answer and stop, before any redirect or maintenance page. */
    public static function maybe_serve() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($uri === '' || strpos($uri, 'assetlinks') === false) return;
        $response = self::response_for((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), $uri);
        if (!$response) return;
        if (!headers_sent()) {
            status_header((int) $response['status']);
            foreach ($response['headers'] as $name => $value) header($name . ': ' . $value, true);
            // 6.12.0: LiteSpeed (server and LSCWP) keeps it one hour, not its default public TTL.
            header('X-LiteSpeed-Cache-Control: public,max-age=3600', true);
        }
        do_action('litespeed_control_set_ttl', 3600);
        if ($response['body'] !== '') echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON document.
        exit;
    }

    // -------------------------------------------------------------------------
    // Administration
    // -------------------------------------------------------------------------

    /** scheme://host[:port] of the site, as Digital Asset Links names it. */
    public static function site_origin() {
        $parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || empty($parts['host'])) return '';
        $origin = strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) $origin .= ':' . absint($parts['port']);
        return $origin;
    }

    /**
     * 6.12.0: the directory the web server serves https://<host>/ from. With
     * WordPress in its own directory (site address …/wp, ABSPATH …/wp/) that
     * is the parent of ABSPATH, not ABSPATH: the site URL's path is taken off
     * ABSPATH; when ABSPATH does not end with it, DOCUMENT_ROOT, else ABSPATH.
     */
    public static function web_root() {
        $abspath = rtrim(wp_normalize_path((string) ABSPATH), '/');
        $site_path = trim((string) wp_parse_url(site_url('/'), PHP_URL_PATH), '/');
        if ($site_path === '') return $abspath;
        $suffix = '/' . $site_path;
        if (strlen($abspath) > strlen($suffix) && substr($abspath, -strlen($suffix)) === $suffix) return substr($abspath, 0, -strlen($suffix));
        $document_root = isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? rtrim(wp_normalize_path(wp_unslash($_SERVER['DOCUMENT_ROOT'])), '/') : '';
        return ($document_root !== '' && @is_dir($document_root)) ? $document_root : $abspath;
    }

    /** A file the web server would serve at https://<host>/.well-known/assetlinks.json before WordPress. */
    public static function physical_file() {
        return self::web_root() . '/.well-known/assetlinks.json';
    }

    private static function on_settings_screen() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        return $screen && substr((string) $screen->id, -strlen('page_delicat-identity')) === 'page_delicat-identity';
    }

    public static function admin_notices() {
        if (!current_user_can('manage_options') || !self::on_settings_screen()) return;
        $s = self::settings();
        if (file_exists(self::physical_file()) && (self::enabled() || !self::published())) {
            echo '<div class="notice notice-warning"><p><strong>Delicat Identity — assetlinks.json :</strong> un fichier <code>' . esc_html(self::physical_file()) . '</code> existe sur l’hébergement. Le serveur web le sert avant WordPress : supprimez-le, ou mettez-y les mêmes empreintes que la carte « Application Android »' . (self::published() ? '.' : ' : tant qu’il ne les contient pas toutes, les clés d’accès de l’application sont désactivées.') . '</p></div>';
        }
        $invalid = self::invalid_lines();
        if ($invalid) {
            echo '<div class="notice notice-error"><p><strong>Delicat Identity — Application Android :</strong> ces lignes ne sont pas des empreintes SHA-256 et sont ignorées : ';
            echo esc_html(implode(' ; ', $invalid));
            echo '. Format attendu : 32 octets hexadécimaux séparés par « : ».</p></div>';
        }
        if (trim((string) ($s['mobile_android_package'] ?? '')) !== '' && self::valid_package($s['mobile_android_package']) === '') {
            echo '<div class="notice notice-error"><p><strong>Delicat Identity — Application Android :</strong> nom de paquet invalide, <code>' . esc_html(self::DEFAULT_PACKAGE) . '</code> est utilisé.</p></div>';
        }
        if (!self::enabled() && self::fingerprints() && !file_exists(self::physical_file())) {
            echo '<div class="notice notice-warning"><p><strong>Delicat Identity — assetlinks.json :</strong> « Publier assetlinks.json » est décoché. Android ne trouve pas les empreintes de l’application : les clés d’accès de l’application sont désactivées tant que le fichier n’est pas publié.</p></div>';
        }
        if (self::fingerprints() && !self::served_at_host_root() && !file_exists(self::physical_file())) {
            echo '<div class="notice notice-warning"><p><strong>Delicat Identity — assetlinks.json :</strong> l’adresse du site est dans un sous-dossier. Android lit seulement <code>' . esc_html(self::site_origin() . self::PATH) . '</code>, que WordPress ne peut pas servir d’ici : placez ce fichier à la racine du domaine (<code>' . esc_html(self::physical_file()) . '</code>) avec les empreintes de la carte « Application Android ». En attendant, les clés d’accès de l’application sont désactivées.</p></div>';
        }
        if (self::enabled() && !self::fingerprints()) {
            echo '<div class="notice notice-warning"><p><strong>Delicat Identity — Application Android :</strong> aucune empreinte valide. <code>/.well-known/assetlinks.json</code> n’est pas publié et les clés d’accès de l’application sont désactivées.</p></div>';
        }
    }

    /** « Tester »: what the site serves, and what Google's Digital Asset Links API reads. */
    public static function handle_test() {
        if (!current_user_can('manage_options')) wp_die(esc_html__('Accès refusé.', 'delicat-google-login'), '', ['response' => 403]);
        check_admin_referer(self::TEST_ACTION);
        $site = self::site_origin();
        $expected = self::fingerprints();
        $package = self::package();
        $result = ['time' => time(), 'site' => $site, 'package' => $package, 'expected' => $expected, 'own' => [], 'google' => []];

        $own = wp_safe_remote_get(home_url(self::PATH), [
            'timeout' => 12, 'redirection' => 0, 'limit_response_size' => 65536,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'Delicat-Identity-Pro/' . DIP_VERSION,
        ]);
        if (is_wp_error($own)) {
            $result['own'] = ['ok' => false, 'error' => sanitize_text_field($own->get_error_message())];
        } else {
            $code = (int) wp_remote_retrieve_response_code($own);
            $type = sanitize_text_field((string) wp_remote_retrieve_header($own, 'content-type'));
            $found = self::fingerprints_in_file(json_decode((string) wp_remote_retrieve_body($own), true), $package);
            $result['own'] = ['ok' => $code === 200 && stripos($type, 'application/json') === 0 && !array_diff($expected, $found), 'code' => $code, 'type' => $type, 'found' => $found];
        }

        $url = 'https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=' . rawurlencode($site) . '&relation=' . rawurlencode(self::RELATION);
        $google = wp_safe_remote_get($url, [
            'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 262144,
            'headers' => ['Accept' => 'application/json'],
            'user-agent' => 'Delicat-Identity-Pro/' . DIP_VERSION,
        ]);
        if (is_wp_error($google)) {
            $result['google'] = ['ok' => false, 'error' => sanitize_text_field($google->get_error_message())];
        } else {
            $code = (int) wp_remote_retrieve_response_code($google);
            $body = json_decode((string) wp_remote_retrieve_body($google), true);
            $found = self::fingerprints_in_google_answer($body, $package);
            $error = '';
            if ($code !== 200) $error = is_array($body) && !empty($body['error']['message']) ? sanitize_text_field((string) $body['error']['message']) : 'HTTP ' . $code;
            elseif (is_array($body) && !empty($body['debugString']) && !$found) $error = substr(sanitize_text_field((string) $body['debugString']), 0, 400);
            $result['google'] = ['ok' => $code === 200 && $expected && !array_diff($expected, $found), 'code' => $code, 'found' => $found, 'error' => $error];
        }
        update_option(self::TEST_OPTION, $result, false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('assetlinks_tested', 'info', get_current_user_id(), ['own' => !empty($result['own']['ok']) ? 1 : 0, 'google' => !empty($result['google']['ok']) ? 1 : 0]);
        wp_safe_redirect(admin_url('options-general.php?page=delicat-identity#dip-android-app'));
        exit;
    }

    /** Fingerprints a published file grants to $package for get_login_creds. */
    public static function fingerprints_in_file($statements, $package) {
        $found = [];
        if (!is_array($statements)) return $found;
        foreach ($statements as $statement) {
            if (!is_array($statement) || !in_array(self::RELATION, (array) ($statement['relation'] ?? []), true)) continue;
            $target = is_array($statement['target'] ?? null) ? $statement['target'] : [];
            if (($target['namespace'] ?? '') !== 'android_app' || ($target['package_name'] ?? '') !== $package) continue;
            foreach ((array) ($target['sha256_cert_fingerprints'] ?? []) as $fingerprint) {
                $normalized = self::normalize_fingerprint($fingerprint);
                if ($normalized !== '' && !in_array($normalized, $found, true)) $found[] = $normalized;
            }
        }
        return $found;
    }

    /** Fingerprints in a statements:list answer for $package. */
    public static function fingerprints_in_google_answer($body, $package) {
        $found = [];
        if (!is_array($body) || !is_array($body['statements'] ?? null)) return $found;
        foreach ($body['statements'] as $statement) {
            $app = is_array($statement['target']['androidApp'] ?? null) ? $statement['target']['androidApp'] : [];
            if (($app['packageName'] ?? '') !== $package) continue;
            $normalized = self::normalize_fingerprint((string) ($app['certificate']['sha256Fingerprint'] ?? ''));
            if ($normalized !== '' && !in_array($normalized, $found, true)) $found[] = $normalized;
        }
        return $found;
    }

    /** Rows of the « Application Android » card, inside the settings form table. */
    public static function render_settings_rows(array $s) {
        $name = esc_attr(DIP_Plugin::OPTION);
        $served = home_url(self::PATH);
        $test = get_option(self::TEST_OPTION, []);
        $fingerprints = self::fingerprints();
        echo '<tr id="dip-android-app"><th colspan="2"><h2>Application Android</h2><p class="description">Clés d’accès de l’application : le site publie <code>' . esc_html($served) . '</code> pour <code>' . esc_html(self::package()) . '</code>, et l’application n’est acceptée que signée par une de ces empreintes. Ce sont des valeurs publiques, pas des secrets.</p></th></tr>';
        echo '<tr><th>Paquet Android</th><td><input class="regular-text" name="' . $name . '[mobile_android_package]" value="' . esc_attr($s['mobile_android_package'] ?? self::DEFAULT_PACKAGE) . '" placeholder="' . esc_attr(self::DEFAULT_PACKAGE) . '"></td></tr>';
        echo '<tr><th>Empreintes SHA-256 des certificats</th><td><textarea class="large-text code" rows="3" name="' . $name . '[mobile_android_cert_sha256]">' . esc_textarea((string) ($s['mobile_android_cert_sha256'] ?? '')) . '</textarea><p class="description">Une empreinte par ligne (32 octets hexadécimaux séparés par « : »). Par défaut : la clé d’envoi (APK du site, builds locaux) et la clé de signature Google Play. La même liste produit le fichier et les origines <code>android:apk-key-hash:…</code> acceptées.</p></td></tr>';
        echo '<tr><th>Empreinte d’un build de test</th><td><textarea class="large-text code" rows="1" name="' . $name . '[mobile_android_test_cert_sha256]">' . esc_textarea((string) ($s['mobile_android_test_cert_sha256'] ?? '')) . '</textarea><p><label><input type="checkbox" name="' . $name . '[mobile_android_test_cert_enabled]" value="1" ' . checked($s['mobile_android_test_cert_enabled'] ?? 'no', 'yes', false) . '> Autoriser temporairement cette empreinte de test</label></p></td></tr>';
        echo '<tr><th>Publier assetlinks.json</th><td><label><input type="checkbox" name="' . $name . '[assetlinks_enabled]" value="1" ' . checked($s['assetlinks_enabled'] ?? 'yes', 'yes', false) . '> Oui</label><p class="description">Cloudflare : une règle WAF « Skip » pour ce chemin (Bot Fight Mode, défis, Under Attack), aucune redirection, puis purger ce chemin dans LiteSpeed et Cloudflare.</p></td></tr>';
        echo '<tr><th>Origines acceptées</th><td>';
        if ($fingerprints) {
            echo '<ul style="margin:0">';
            foreach ($fingerprints as $fingerprint) echo '<li><code>' . esc_html(self::origin_for($fingerprint)) . '</code></li>';
            echo '</ul>';
        } else {
            echo '<strong>Aucune empreinte valide : fichier non publié, clés d’accès de l’application désactivées.</strong>';
        }
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=' . self::TEST_ACTION), self::TEST_ACTION)) . '">Tester</a> <a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url($served) . '">Ouvrir le fichier</a></p>';
        if (is_array($test) && !empty($test['time'])) {
            $own = is_array($test['own'] ?? null) ? $test['own'] : [];
            $google = is_array($test['google'] ?? null) ? $test['google'] : [];
            echo '<p class="description">Dernier test : ' . esc_html(wp_date('d/m/Y H:i', (int) $test['time'])) . '</p><ul style="margin:0">';
            echo '<li>' . (!empty($own['ok']) ? 'OK' : 'À vérifier') . ' — site : ' . esc_html(isset($own['code']) ? 'HTTP ' . (int) $own['code'] . ' · ' . (string) ($own['type'] ?? '') : (string) ($own['error'] ?? '')) . (!empty($own['found']) ? ' · ' . esc_html(implode(', ', (array) $own['found'])) : '') . '</li>';
            echo '<li>' . (!empty($google['ok']) ? 'OK' : 'À vérifier') . ' — Google Digital Asset Links : ' . esc_html(!empty($google['found']) ? implode(', ', (array) $google['found']) : 'aucune empreinte trouvée') . (!empty($google['error']) ? ' — ' . esc_html((string) $google['error']) : '') . '</li>';
            echo '</ul><p class="description">Google garde sa réponse en cache : après un changement, comptez quelques heures avant l’essai sur téléphone.</p>';
        }
        echo '</td></tr>';
    }

    /** Settings form values for the card (called by DIP_Plugin::sanitize_settings()). */
    public static function sanitize_settings(array $input, array $out) {
        // A form that does not carry a field (an older admin page) keeps the stored value.
        if (array_key_exists('mobile_android_package', $input)) {
            $raw = trim(sanitize_text_field(is_scalar($input['mobile_android_package']) ? (string) $input['mobile_android_package'] : ''));
            $package = self::valid_package($raw);
            if ($raw === '') {
                $out['mobile_android_package'] = self::DEFAULT_PACKAGE;
            } elseif ($package !== '') {
                $out['mobile_android_package'] = $package;
            } else {
                // 6.12.0: a typo no longer silently becomes the default package:
                // the previous value stays and the administrator is told.
                $previous = self::valid_package($out['mobile_android_package'] ?? '');
                $out['mobile_android_package'] = $previous !== '' ? $previous : self::DEFAULT_PACKAGE;
                if (function_exists('add_settings_error')) {
                    add_settings_error(DIP_Plugin::OPTION, 'dip_android_package', esc_html(sprintf('Nom de paquet Android invalide (« %s ») : %s est conservé.', function_exists('mb_substr') ? mb_substr($raw, 0, 80) : substr($raw, 0, 80), $out['mobile_android_package'])), 'error');
                }
            }
        }
        foreach (['mobile_android_cert_sha256', 'mobile_android_test_cert_sha256'] as $key) {
            if (!array_key_exists($key, $input)) continue;
            $lines = preg_split('/\r\n|\r|\n/', (string) $input[$key]);
            $kept = [];
            foreach ((array) $lines as $line) {
                $line = trim(sanitize_text_field((string) $line));
                if ($line !== '') $kept[] = function_exists('mb_substr') ? mb_substr($line, 0, 200) : substr($line, 0, 200);
            }
            // Raw lines are kept (invalid ones too) so the notice can show them.
            $out[$key] = implode("\n", array_slice($kept, 0, self::MAX_FINGERPRINTS));
        }
        self::maybe_purge_cache($out);
        return $out;
    }

    /**
     * 6.12.0: when what /.well-known/assetlinks.json says changes (package,
     * fingerprints, test build, publication), page caches drop the old copy
     * (LiteSpeed Cache; a no-op without it). Google keeps its own answer
     * cached for a while (see « Tester »).
     */
    private static function maybe_purge_cache(array $out) {
        $stored = self::settings();
        foreach (['mobile_android_package', 'mobile_android_cert_sha256', 'mobile_android_test_cert_sha256', 'mobile_android_test_cert_enabled', 'assetlinks_enabled'] as $key) {
            if ((string) ($stored[$key] ?? '') !== (string) ($out[$key] ?? '')) {
                do_action('litespeed_purge_url', home_url(self::PATH));
                return;
            }
        }
    }
}
