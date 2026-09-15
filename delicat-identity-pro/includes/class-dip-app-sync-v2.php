<?php
defined('ABSPATH') || exit;

/**
 * Delicat App Sync 2.0
 * One-time web/app pairing layered on top of DIP_Mobile_API.
 */
final class DIP_App_Sync_V2 {
    const TABLE_SUFFIX = 'dip_app_pairings';
    const OPTION = 'dip_app_sync_v2_settings';
    const PAIR_TTL = 300;

    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 40);
        add_action('admin_post_dip_app_sync_v2_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_dip_app_sync_v2_cleanup', [__CLASS__, 'cleanup_action']);
        add_shortcode('delicat_app_pairing', [__CLASS__, 'shortcode']);
        add_action('dip_app_pairing_cleanup', [__CLASS__, 'cleanup']);
        if (!wp_next_scheduled('dip_app_pairing_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dip_app_pairing_cleanup');
        }
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            pairing_id char(36) NOT NULL,
            secret_hash char(64) NOT NULL,
            browser_hash char(64) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            requested_ip_hash char(64) NOT NULL DEFAULT '',
            requested_ua varchar(255) NOT NULL DEFAULT '',
            approved_device_id bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            approved_at datetime DEFAULT NULL,
            consumed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY pairing_id (pairing_id),
            KEY status_expires (status, expires_at),
            KEY user_id (user_id)
        ) {$charset};");
        if (false === get_option(self::OPTION, false)) {
            add_option(self::OPTION, self::defaults(), '', false);
        }
    }

    public static function defaults() {
        return [
            'enabled' => 'no',
            'allow_web_pairing' => 'yes',
            'redirect_after_pairing' => '/my-account/',
            'button_text' => 'Ouvrir l’app Delicat',
            'deep_link_scheme' => 'delicat',
        ];
    }

    private static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function enabled() {
        $settings = self::settings();
        return $settings['enabled'] === 'yes' && $settings['allow_web_pairing'] === 'yes' && DIP_Request::is_secure();
    }

    private static function hash_value($value) {
        return hash_hmac('sha256', (string) $value, wp_salt('secure_auth'));
    }

    private static function token($bytes = 32) {
        try {
            return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        } catch (Exception $e) {
            return wp_generate_password($bytes * 2, false, false);
        }
    }

    private static function browser_hash(WP_REST_Request $request) {
        $value = (string) $request->get_param('browser_token');
        if (!preg_match('/^[A-Za-z0-9\-_]{24,255}$/', $value)) return '';
        return self::hash_value($value);
    }

    private static function ip_hash() {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : (isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '');
        return $ip ? self::hash_value($ip) : '';
    }

    private static function rate_limit($bucket, $max = 12, $window = 600) {
        $key = 'dip_pair_' . md5($bucket . '|' . self::ip_hash());
        $count = (int) get_transient($key);
        if ($count >= $max) return false;
        set_transient($key, $count + 1, $window);
        return true;
    }

    public static function routes() {
        register_rest_route('delicat-identity/v1', '/pairing/create', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'create_pairing'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/status', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'pairing_status'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/approve', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'approve_pairing'],
            'permission_callback' => [__CLASS__, 'logged_in'],
        ]);
        register_rest_route('delicat-identity/v1', '/pairing/exchange', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'exchange_pairing'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function logged_in($request = null) {
        return class_exists('DIP_Mobile_API') && DIP_Mobile_API::logged_in($request instanceof WP_REST_Request ? $request : null);
    }

    public static function create_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'La connexion avec l’app est désactivée ou HTTPS est indisponible.', ['status' => 403]);
        if (!self::rate_limit('create')) return new WP_Error('rate_limited', 'Trop de demandes. Réessayez plus tard.', ['status' => 429]);
        $browser_hash = self::browser_hash($request);
        if (!$browser_hash) return new WP_Error('invalid_browser', 'Session navigateur invalide.', ['status' => 400]);

        global $wpdb;
        $pairing_id = wp_generate_uuid4();
        $secret = self::token(32);
        $now = current_time('mysql', true);
        $expires = gmdate('Y-m-d H:i:s', time() + self::PAIR_TTL);
        $inserted = $wpdb->insert(self::table(), [
            'pairing_id' => $pairing_id,
            'secret_hash' => self::hash_value($secret),
            'browser_hash' => $browser_hash,
            'status' => 'pending',
            'requested_ip_hash' => self::ip_hash(),
            'requested_ua' => substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255),
            'created_at' => $now,
            'expires_at' => $expires,
        ]);
        if (!$inserted) return new WP_Error('pairing_create_failed', 'Impossible de créer la demande.', ['status' => 500]);

        $settings = self::settings();
        $scheme = strtolower(trim((string) $settings['deep_link_scheme']));
        if (!preg_match('/^[a-z][a-z0-9+.-]{1,31}$/', $scheme)) $scheme = 'delicat';
        $deep_link = $scheme . '://identity/pair?pairing_id=' . rawurlencode($pairing_id) . '&secret=' . rawurlencode($secret);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_created', 'info', 0);
        return self::response([
            'pairing_id' => $pairing_id,
            'secret' => $secret,
            'deep_link' => $deep_link,
            'expires_in' => self::PAIR_TTL,
            'display_code' => strtoupper(substr(str_replace('-', '', $pairing_id), 0, 8)),
        ]);
    }

    private static function locate(WP_REST_Request $request, $require_browser = false) {
        global $wpdb;
        $pairing_id = sanitize_text_field((string) $request->get_param('pairing_id'));
        $secret = (string) $request->get_param('secret');
        if (!wp_is_uuid($pairing_id) || !preg_match('/^[A-Za-z0-9\-_]{24,255}$/', $secret)) return null;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE pairing_id=%s LIMIT 1', $pairing_id));
        if (!$row || !hash_equals((string) $row->secret_hash, self::hash_value($secret))) return null;
        if ($require_browser) {
            $browser_hash = self::browser_hash($request);
            if (!$browser_hash || !hash_equals((string) $row->browser_hash, $browser_hash)) return null;
        }
        return $row;
    }

    public static function pairing_status(WP_REST_Request $request) {
        if (!self::rate_limit('status', 220, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, true);
        if (!$row) return new WP_Error('pairing_not_found', 'Demande introuvable.', ['status' => 404]);
        if (strtotime($row->expires_at . ' UTC') < time() && $row->status === 'pending') {
            global $wpdb;
            $wpdb->update(self::table(), ['status' => 'expired'], ['id' => (int) $row->id]);
            $row->status = 'expired';
        }
        return self::response(['status' => sanitize_key($row->status), 'expires_at' => mysql_to_rfc3339($row->expires_at)]);
    }

    public static function approve_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::rate_limit('approve', 20, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, false);
        if (!$row || $row->status !== 'pending') return new WP_Error('pairing_invalid', 'Demande invalide ou déjà utilisée.', ['status' => 409]);
        if (strtotime($row->expires_at . ' UTC') < time()) return new WP_Error('pairing_expired', 'La demande a expiré.', ['status' => 410]);

        $session = class_exists('DIP_Mobile_API') ? DIP_Mobile_API::current_session_for_request($request) : null;
        if (!$session || (int) $session->user_id !== get_current_user_id()) return new WP_Error('mobile_session_required', 'Une session mobile Delicat valide est obligatoire.', ['status' => 401]);
        $session_id = (int) $session->id;
        if (class_exists('DIP_Account_Sync')) {
            if (DIP_Account_Sync::privileged_mobile_blocked(get_current_user_id())) return new WP_Error('pairing_role_blocked', 'Ce type de compte ne peut pas approuver un appairage mobile.', ['status' => 403]);
            $guard = DIP_Account_Sync::login_guard(get_current_user_id());
            if (is_wp_error($guard)) return new WP_Error($guard->get_error_code(), $guard->get_error_message(), ['status'=>403]);
        }
        global $wpdb;
        $updated = $wpdb->update(self::table(), [
            'user_id' => get_current_user_id(),
            'status' => 'approved',
            'approved_device_id' => $session_id,
            'approved_at' => current_time('mysql', true),
        ], ['id' => (int) $row->id, 'status' => 'pending']);
        if (!$updated) return new WP_Error('pairing_approve_failed', 'La demande n’a pas pu être approuvée.', ['status' => 409]);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_approved', 'info', get_current_user_id());
        do_action('dip_app_pairing_approved', get_current_user_id(), $row->pairing_id);
        return self::response(['approved' => true]);
    }

    public static function exchange_pairing(WP_REST_Request $request) {
        if (!self::enabled()) return new WP_Error('pairing_disabled', 'Connexion avec l’app désactivée.', ['status' => 403]);
        if (!self::rate_limit('exchange', 30, 600)) return new WP_Error('rate_limited', 'Trop de demandes.', ['status' => 429]);
        $row = self::locate($request, true);
        if (!$row || $row->status !== 'approved' || !$row->user_id) return new WP_Error('pairing_not_ready', 'La demande n’est pas encore approuvée.', ['status' => 409]);
        if (strtotime($row->expires_at . ' UTC') < time()) return new WP_Error('pairing_expired', 'La demande a expiré.', ['status' => 410]);

        $user = get_userdata((int) $row->user_id);
        if (!$user) return new WP_Error('user_not_found', 'Compte introuvable.', ['status' => 404]);
        if (class_exists('DIP_Account_Sync')) {
            if (DIP_Account_Sync::privileged_mobile_blocked($user->ID)) return new WP_Error('pairing_role_blocked', 'Ce type de compte ne peut pas être connecté par appairage.', ['status'=>403]);
            $guard = DIP_Account_Sync::login_guard($user->ID);
            if (is_wp_error($guard)) return new WP_Error($guard->get_error_code(), $guard->get_error_message(), ['status'=>403]);
        }
        // If the account is protected by 2FA, the mobile session that approved
        // the pairing must still be active at exchange time. Enabling/disabling
        // 2FA revokes mobile sessions, so an approval created before that
        // security transition cannot silently mint a browser cookie afterward.
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user->ID)) {
            $approved_session_id = absint($row->approved_device_id ?? 0);
            if (!class_exists('DIP_Mobile_API') || !DIP_Mobile_API::session_is_active($approved_session_id, $user->ID)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_blocked_stale_2fa_session', 'warning', $user->ID);
                return new WP_Error('two_factor_mobile_session_required', 'La session mobile ayant approuvé cette connexion doit être réauthentifiée.', ['status'=>401]);
            }
        }

        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status='consumed', consumed_at=%s WHERE id=%d AND status='approved'",
            current_time('mysql', true), (int) $row->id
        ));
        if (!$updated) return new WP_Error('pairing_consumed', 'Cette demande a déjà été utilisée.', ['status' => 409]);

        wp_set_current_user($user->ID);
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user->ID)) {
            DIP_Two_Factor::authorize_cookie_once($user->ID);
        }
        wp_set_auth_cookie($user->ID, true, DIP_Request::is_secure());
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::fire_wp_login($user, 'delicat_app_pairing');
        else do_action('wp_login', $user->user_login, $user);
        if (class_exists('DIP_Account_Sync')) DIP_Account_Sync::after_login($user->ID);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_pairing_consumed', 'info', $user->ID);
        do_action('dip_login_success', $user->ID, ['provider' => 'delicat_app_pairing']);

        $settings = self::settings();
        $target = trim((string) $settings['redirect_after_pairing']);
        if ($target !== '' && strpos($target, '/') === 0 && strpos($target, '//') !== 0) $target = home_url($target);
        $redirect = wp_validate_redirect($target, home_url('/my-account/'));
        return self::response(['authenticated' => true, 'redirect' => $redirect]);
    }

    private static function response(array $data, $status = 200) {
        $response = new WP_REST_Response($data, $status);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->header('Pragma', 'no-cache');
        $response->header('X-Content-Type-Options', 'nosniff');
        return $response;
    }

    public static function shortcode($atts = []) {
        if (is_user_logged_in()) return '<div class="dip-pairing-notice">Vous êtes déjà connecté.</div>';
        $settings = self::settings();
        if ($settings['enabled'] !== 'yes') return current_user_can('manage_options') ? '<div class="dip-pairing-notice">Activez App Sync 2.0 dans Réglages → Identity App Sync.</div>' : '';
        $id = 'dip-pairing-' . wp_generate_password(8, false, false);
        $rest = esc_url_raw(rest_url('delicat-identity/v1/pairing/'));
        ob_start();
        ?>
        <div id="<?php echo esc_attr($id); ?>" class="dip-app-pairing" data-rest="<?php echo esc_attr($rest); ?>">
            <div class="dip-app-pairing__icon" aria-hidden="true">↗</div>
            <h3>Connexion avec l’app Delicat</h3>
            <p class="dip-app-pairing__lead">Ouvrez l’app Delicat sur votre téléphone et approuvez cette connexion sécurisée.</p>
            <button type="button" class="dip-app-pairing__start"><?php echo esc_html($settings['button_text']); ?></button>
            <div class="dip-app-pairing__session" hidden>
                <p>Code de vérification</p><strong class="dip-app-pairing__code">—</strong>
                <a class="dip-app-pairing__open" href="#">Ouvrir dans l’app</a>
                <span class="dip-app-pairing__status" role="status">En attente d’approbation…</span>
            </div>
        </div>
        <style>
        #<?php echo esc_html($id); ?>{max-width:460px;padding:28px;border:1px solid rgba(15,23,42,.12);border-radius:22px;background:#fff;box-shadow:0 18px 55px rgba(15,23,42,.10);text-align:center;color:#0f172a}#<?php echo esc_html($id); ?> .dip-app-pairing__icon{width:54px;height:54px;margin:0 auto 14px;border-radius:17px;display:grid;place-items:center;background:#0f2b5b;color:#fff;font-size:25px}#<?php echo esc_html($id); ?> h3{margin:0 0 8px;font-size:22px}#<?php echo esc_html($id); ?> .dip-app-pairing__lead{margin:0 0 20px;color:#64748b}#<?php echo esc_html($id); ?> button,#<?php echo esc_html($id); ?> .dip-app-pairing__open{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border:0;border-radius:14px;background:#0f2b5b;color:#fff;font-weight:700;text-decoration:none;cursor:pointer}#<?php echo esc_html($id); ?> .dip-app-pairing__session{margin-top:20px;padding:18px;border-radius:16px;background:#f8fafc}#<?php echo esc_html($id); ?> .dip-app-pairing__session p{margin:0 0 5px;color:#64748b}#<?php echo esc_html($id); ?> .dip-app-pairing__code{display:block;font-size:30px;letter-spacing:.16em;margin:6px 0 14px}#<?php echo esc_html($id); ?> .dip-app-pairing__status{display:block;margin-top:12px;color:#64748b;font-size:14px}#<?php echo esc_html($id); ?> button:focus-visible,#<?php echo esc_html($id); ?> a:focus-visible{outline:3px solid rgba(37,99,235,.28);outline-offset:3px}@media(max-width:520px){#<?php echo esc_html($id); ?>{padding:20px 16px;border-radius:18px}#<?php echo esc_html($id); ?> .dip-app-pairing__code{font-size:25px;letter-spacing:.11em;overflow-wrap:anywhere}#<?php echo esc_html($id); ?> button,#<?php echo esc_html($id); ?> .dip-app-pairing__open{width:100%}}
        </style>
        <script>
        (()=>{const root=document.getElementById(<?php echo wp_json_encode($id); ?>);if(!root)return;const start=root.querySelector('.dip-app-pairing__start'),session=root.querySelector('.dip-app-pairing__session'),code=root.querySelector('.dip-app-pairing__code'),open=root.querySelector('.dip-app-pairing__open'),status=root.querySelector('.dip-app-pairing__status'),base=root.dataset.rest;let pair=null,timer=null,failures=0,startedAt=0;
        const randomBrowser=()=>{try{const bytes=new Uint8Array(32);crypto.getRandomValues(bytes);return Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('')}catch(e){return (Date.now().toString(36)+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2)+'-'+Math.random().toString(36).slice(2))}};
        const browser=(()=>{let v=sessionStorage.getItem('dip_pair_browser');if(!v||v.length<24){v=randomBrowser();sessionStorage.setItem('dip_pair_browser',v)}return v})();
        const post=async(path,data)=>{const r=await fetch(base+path,{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify(data)});let j={};try{j=await r.json()}catch(e){}if(!r.ok)throw new Error(j.message||'Erreur de connexion');return j};
        const stop=()=>{if(timer){clearTimeout(timer);timer=null}};
        start.addEventListener('click',async()=>{stop();start.disabled=true;status.textContent='Création de la connexion…';try{pair=await post('create',{browser_token:browser});startedAt=Date.now();failures=0;code.textContent=pair.display_code;open.href=pair.deep_link;session.hidden=false;start.hidden=true;poll()}catch(e){status.textContent=e.message;start.disabled=false}});
        async function poll(){if(!pair)return;if(Date.now()-startedAt>((pair.expires_in||300)+15)*1000){status.textContent='Code expiré. Recommencez la connexion.';return}try{const s=await post('status',{pairing_id:pair.pairing_id,secret:pair.secret,browser_token:browser});failures=0;if(s.status==='approved'){status.textContent='Approuvé. Connexion en cours…';const x=await post('exchange',{pairing_id:pair.pairing_id,secret:pair.secret,browser_token:browser});window.location.assign(x.redirect);return}if(['expired','consumed'].includes(s.status)){status.textContent=s.status==='expired'?'Code expiré. Recommencez la connexion.':'Code déjà utilisé.';return}status.textContent='En attente d’approbation…';timer=setTimeout(poll,2500)}catch(e){failures++;status.textContent=failures<4?'Connexion momentanément indisponible, nouvelle tentative…':e.message;timer=setTimeout(poll,Math.min(12000,2500+(failures*1500)))}}
        window.addEventListener('pagehide',stop,{once:true});})();
        </script>
        <?php
        return ob_get_clean();
    }

    public static function admin_menu() {
        add_options_page('Identity App Sync', 'Identity App Sync', 'manage_options', 'dip-app-sync-v2', [__CLASS__, 'admin_page']);
    }

    public static function save_settings() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_app_sync_v2_save');
        $old = self::settings();
        $input = isset($_POST['settings']) ? (array) wp_unslash($_POST['settings']) : [];
        $new = $old;
        $new['enabled'] = !empty($input['enabled']) ? 'yes' : 'no';
        $new['allow_web_pairing'] = !empty($input['allow_web_pairing']) ? 'yes' : 'no';
        $new['redirect_after_pairing'] = esc_url_raw($input['redirect_after_pairing'] ?? '/my-account/');
        $new['button_text'] = sanitize_text_field($input['button_text'] ?? self::defaults()['button_text']);
        $scheme = strtolower(trim((string) ($input['deep_link_scheme'] ?? 'delicat')));
        $new['deep_link_scheme'] = preg_match('/^[a-z][a-z0-9+.-]{1,31}$/', $scheme) ? $scheme : 'delicat';
        update_option(self::OPTION, $new, false);
        if (class_exists('DIP_Audit')) DIP_Audit::record('app_sync_v2_settings_updated', 'info', get_current_user_id());
        wp_safe_redirect(add_query_arg('updated', '1', admin_url('options-general.php?page=dip-app-sync-v2')));
        exit;
    }

    public static function cleanup_action() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_app_sync_v2_cleanup');
        self::cleanup();
        wp_safe_redirect(add_query_arg('cleaned', '1', admin_url('options-general.php?page=dip-app-sync-v2')));
        exit;
    }

    public static function admin_page() {
        if (!current_user_can('manage_options')) return;
        global $wpdb;
        $settings = self::settings();
        $table = self::table();
        $stats = [
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending' AND expires_at > UTC_TIMESTAMP()"),
            'approved' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='approved' AND expires_at > UTC_TIMESTAMP()"),
            'consumed' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='consumed' AND consumed_at > UTC_TIMESTAMP() - INTERVAL 30 DAY"),
        ];
        $mobile = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        ?>
        <div class="wrap"><h1>Delicat Identity — App Sync 2.0</h1>
        <?php if (isset($_GET['updated'])) echo '<div class="notice notice-success"><p>Réglages enregistrés.</p></div>'; ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;max-width:920px;margin:20px 0">
          <div class="card"><h2>API mobile</h2><p><strong><?php echo $mobile['mobile_api_enabled']==='yes'?'Active':'Inactive'; ?></strong></p></div>
          <div class="card"><h2>En attente</h2><p><strong><?php echo esc_html($stats['pending']); ?></strong></p></div>
          <div class="card"><h2>Approuvées</h2><p><strong><?php echo esc_html($stats['approved']); ?></strong></p></div>
          <div class="card"><h2>Connexions 30 j</h2><p><strong><?php echo esc_html($stats['consumed']); ?></strong></p></div>
        </div>
        <?php if (!DIP_Request::is_secure()): ?><div class="notice notice-error"><p>HTTPS est obligatoire pour App Sync 2.0.</p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:820px;background:#fff;padding:24px;border:1px solid #dcdcde;border-radius:12px">
          <input type="hidden" name="action" value="dip_app_sync_v2_save"><?php wp_nonce_field('dip_app_sync_v2_save'); ?>
          <table class="form-table"><tbody>
            <tr><th>App Sync 2.0</th><td><label><input type="checkbox" name="settings[enabled]" value="1" <?php checked($settings['enabled'],'yes'); ?>> Activer le système</label></td></tr>
            <tr><th>Connexion web/app</th><td><label><input type="checkbox" name="settings[allow_web_pairing]" value="1" <?php checked($settings['allow_web_pairing'],'yes'); ?>> Autoriser les codes d’appairage à usage unique</label></td></tr>
            <tr><th>Schéma de lien app</th><td><input class="regular-text" name="settings[deep_link_scheme]" value="<?php echo esc_attr($settings['deep_link_scheme']); ?>"><p class="description">Exemple : delicat://identity/pair</p></td></tr>
            <tr><th>Texte du bouton</th><td><input class="regular-text" name="settings[button_text]" value="<?php echo esc_attr($settings['button_text']); ?>"></td></tr>
            <tr><th>Redirection</th><td><input class="regular-text" name="settings[redirect_after_pairing]" value="<?php echo esc_attr($settings['redirect_after_pairing']); ?>"></td></tr>
          </tbody></table><?php submit_button('Enregistrer'); ?>
        </form>
        <div class="card" style="max-width:820px;margin-top:18px"><h2>Intégration</h2><p>Ajoutez le shortcode <code>[delicat_app_pairing]</code> sur votre page de connexion.</p><p>L’app approuve la demande avec <code>POST /wp-json/delicat-identity/v1/pairing/approve</code> en utilisant son jeton Bearer mobile.</p></div>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php wp_nonce_field('dip_app_sync_v2_cleanup'); ?><input type="hidden" name="action" value="dip_app_sync_v2_cleanup"><?php submit_button('Nettoyer les demandes expirées','secondary'); ?></form>
        </div><?php
    }

    public static function cleanup() {
        global $wpdb;
        $wpdb->query('DELETE FROM ' . self::table() . " WHERE (status IN ('expired','consumed') AND created_at < UTC_TIMESTAMP() - INTERVAL 30 DAY) OR expires_at < UTC_TIMESTAMP() - INTERVAL 7 DAY");
    }
}
