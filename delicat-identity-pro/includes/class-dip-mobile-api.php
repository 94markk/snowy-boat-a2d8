<?php
defined('ABSPATH') || exit;

final class DIP_Mobile_API {
    const TABLE_SUFFIX = 'dip_mobile_tokens';
    const DEFAULT_ACCESS_TTL = 900;
    const DEFAULT_REFRESH_TTL = 2592000;
    const CHALLENGE_TTL = 180;
    private static $settings_cb;

    public static function init($settings_cb) {
        self::$settings_cb = $settings_cb;
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_filter('rest_post_dispatch', [__CLASS__, 'secure_rest_response'], 10, 3);
        add_filter('determine_current_user', [__CLASS__, 'authenticate_bearer'], 25);
        add_action('dip_mobile_cleanup', [__CLASS__, 'cleanup']);
        if (!wp_next_scheduled('dip_mobile_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dip_mobile_cleanup');
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . self::TABLE_SUFFIX;
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            token_hash char(64) NOT NULL,
            refresh_hash char(64) DEFAULT NULL,
            device_hash char(64) NOT NULL,
            installation_hash char(64) NOT NULL DEFAULT '',
            device_name varchar(100) NOT NULL DEFAULT '',
            platform varchar(20) NOT NULL DEFAULT 'unknown',
            app_version varchar(32) NOT NULL DEFAULT '',
            locale varchar(16) NOT NULL DEFAULT '',
            push_provider varchar(20) NOT NULL DEFAULT '',
            push_token_enc longtext DEFAULT NULL,
            biometric_public_key longtext DEFAULT NULL,
            biometric_key_hash char(64) NOT NULL DEFAULT '',
            biometric_enabled tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            last_used_at datetime NOT NULL,
            access_expires_at datetime NOT NULL,
            refresh_expires_at datetime NOT NULL,
            revoked_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY refresh_hash (refresh_hash),
            KEY user_id (user_id),
            KEY device_hash (device_hash),
            KEY installation_hash (installation_hash),
            KEY biometric_key_hash (biometric_key_hash)
        ) {$charset};");
    }

    public static function routes() {
        register_rest_route('delicat-identity/v1', '/mobile/google', ['methods'=>'POST','callback'=>[__CLASS__,'google_login'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/refresh', ['methods'=>'POST','callback'=>[__CLASS__,'refresh'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/logout', ['methods'=>'POST','callback'=>[__CLASS__,'logout'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/profile', ['methods'=>'GET','callback'=>[__CLASS__,'profile'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/capabilities', ['methods'=>'GET','callback'=>[__CLASS__,'capabilities'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/sessions', ['methods'=>'GET','callback'=>[__CLASS__,'sessions'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/sessions/(?P<id>\d+)', ['methods'=>'DELETE','callback'=>[__CLASS__,'revoke_session'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/device', ['methods'=>'POST','callback'=>[__CLASS__,'update_device'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/push', ['methods'=>'POST','callback'=>[__CLASS__,'register_push'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/push', ['methods'=>'DELETE','callback'=>[__CLASS__,'remove_push'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/register', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_register'],'permission_callback'=>[__CLASS__,'logged_in']]);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/challenge', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_challenge'],'permission_callback'=>'__return_true']);
        register_rest_route('delicat-identity/v1', '/mobile/biometric/verify', ['methods'=>'POST','callback'=>[__CLASS__,'biometric_verify'],'permission_callback'=>'__return_true']);
    }

    public static function logged_in($request = null) {
        $session = self::current_session_for_request($request instanceof WP_REST_Request ? $request : null);
        if (!$session || !self::mobile_user_allowed((int) $session->user_id)) return false;
        wp_set_current_user((int) $session->user_id);
        return true;
    }
    private static function settings() { return is_callable(self::$settings_cb) ? (array) call_user_func(self::$settings_cb) : []; }
    private static function enabled() { return (self::settings()['mobile_api_enabled'] ?? 'no') === 'yes'; }
    private static function table() { global $wpdb; return $wpdb->prefix . self::TABLE_SUFFIX; }
    private static function hash_token($token) { return hash_hmac('sha256', (string)$token, wp_salt('auth')); }
    private static function random_token($bytes=48) { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
    private static function access_ttl() { return min(3600,max(300,absint(self::settings()['mobile_access_ttl'] ?? self::DEFAULT_ACCESS_TTL))); }
    private static function refresh_ttl() { return min(7776000,max(86400,absint(self::settings()['mobile_refresh_ttl'] ?? self::DEFAULT_REFRESH_TTL))); }
    private static function device_hash($value) { return hash_hmac('sha256', substr((string)$value,0,255), wp_salt('secure_auth')); }
    private static function server_authorization_header() {
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) return trim((string) wp_unslash($_SERVER['HTTP_AUTHORIZATION']));
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) return trim((string) wp_unslash($_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        return '';
    }

    public static function secure_rest_response($response, $server, $request) {
        if ((strpos((string) $request->get_route(), '/delicat-identity/v1/mobile/') === 0 || strpos((string) $request->get_route(), '/delicat-identity/v1/pairing/') === 0) && $response instanceof WP_REST_Response) {
            $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->header('Pragma', 'no-cache');
            $response->header('X-Content-Type-Options', 'nosniff');
            $response->header('Referrer-Policy', 'no-referrer');
        }
        return $response;
    }

    private static function throttle($bucket, $limit, $window) {
        $ip = class_exists('DIP_Native_Auth') ? DIP_Native_Auth::client_ip() : sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $key = 'dip_mob_' . sanitize_key($bucket) . '_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_rate_limited', 'critical', 0, ['bucket' => $bucket]);
            return false;
        }
        set_transient($key, $count + 1, $window);
        return true;
    }

    private static function valid_device_request(WP_REST_Request $r) {
        $device = trim((string) ($r->get_header('X-Device-ID') ?: $r->get_param('device_id')));
        $installation = trim((string) ($r->get_header('X-Installation-ID') ?: $r->get_param('installation_id')));
        return strlen($device) >= 8 && strlen($device) <= 255 && strlen($installation) >= 16 && strlen($installation) <= 255;
    }


    /**
     * Resolve a Delicat Identity mobile access token for trusted sibling plugins.
     * Accepts Authorization: Bearer and X-Delicat-Identity-Token.
     * Returns only the matching user ID and never exposes stored token hashes.
     */
    public static function resolve_request_user($request = null) {
        if (!self::enabled()) return 0;
        // The supported bridge is request-bound: sibling Delicat plugins must
        // pass the WP_REST_Request so token + device + installation are checked.
        if ($request instanceof WP_REST_Request) {
            $session = self::current_session_for_request($request, true);
            return $session ? (int) $session->user_id : 0;
        }
        // Legacy unbound resolution is disabled by default because a copied
        // Bearer token must not be enough to impersonate a customer from an
        // arbitrary WordPress hook. It can be temporarily re-enabled only by a
        // deliberate compatibility filter while an older sibling plugin migrates.
        if (!apply_filters('dip_mobile_allow_legacy_unbound_token_resolution', false)) return 0;
        $token = '';
        $auth = self::server_authorization_header();
        if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
        if ($token === '' && !empty($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN'])) $token = trim((string) wp_unslash($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']));
        if (!preg_match('/^[A-Za-z0-9\-_]{40,512}$/', $token)) return 0;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id,user_id FROM '.self::table().' WHERE token_hash=%s AND revoked_at IS NULL AND access_expires_at > UTC_TIMESTAMP() LIMIT 1',
            self::hash_token($token)
        ));
        if (!$row || !self::mobile_user_allowed((int) $row->user_id)) return 0;
        $wpdb->update(self::table(), ['last_used_at'=>current_time('mysql',true)], ['id'=>(int)$row->id], ['%s'], ['%d']);
        return (int) $row->user_id;
    }

    private static function automatic_bearer_route_allowed() {
        if (!defined('REST_REQUEST') || !REST_REQUEST) return false;
        $route = '';
        if (isset($_GET['rest_route'])) {
            $route = (string) wp_unslash($_GET['rest_route']);
        } elseif (!empty($_SERVER['REQUEST_URI'])) {
            $path = (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
            $marker = '/wp-json/';
            $pos = strpos($path, $marker);
            if ($pos !== false) $route = '/' . ltrim(substr($path, $pos + strlen($marker)), '/');
        }
        $route = '/' . ltrim(sanitize_text_field($route), '/');
        $prefixes = apply_filters('dip_mobile_bearer_auto_auth_prefixes', ['/delicat-identity/v1/']);
        foreach ((array) $prefixes as $prefix) {
            $prefix = '/' . ltrim((string) $prefix, '/');
            if ($prefix !== '/' && strpos($route, $prefix) === 0) return true;
        }
        return false;
    }

    public static function authenticate_bearer($user_id) {
        if ($user_id || !self::enabled()) return $user_id;
        // A Delicat mobile Bearer token must never become a WordPress REST
        // identity by itself. Even on the Identity namespace, automatic auth
        // requires the same device + installation binding as protected routes.
        if (!self::automatic_bearer_route_allowed() || !class_exists('WP_REST_Request')) return $user_id;
        $auth = self::server_authorization_header();
        $identity_token = isset($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN']) ? trim((string) wp_unslash($_SERVER['HTTP_X_DELICAT_IDENTITY_TOKEN'])) : '';
        $device = isset($_SERVER['HTTP_X_DEVICE_ID']) ? trim((string) wp_unslash($_SERVER['HTTP_X_DEVICE_ID'])) : '';
        $installation = isset($_SERVER['HTTP_X_INSTALLATION_ID']) ? trim((string) wp_unslash($_SERVER['HTTP_X_INSTALLATION_ID'])) : '';
        if ($auth === '' && $identity_token === '') return $user_id;
        $request = new WP_REST_Request();
        if ($auth !== '') $request->set_header('Authorization', $auth);
        if ($identity_token !== '') $request->set_header('X-Delicat-Identity-Token', $identity_token);
        if ($device !== '') $request->set_header('X-Device-ID', $device);
        if ($installation !== '') $request->set_header('X-Installation-ID', $installation);
        $session = self::current_session_for_request($request, true);
        return $session ? (int) $session->user_id : $user_id;
    }

    public static function capabilities() {
        $s=self::settings();
        $server_client_id = self::oauth_client_id($s['client_id'] ?? '');
        $android_client_id = self::oauth_client_id($s['android_client_id'] ?? '');
        $ios_client_id = self::oauth_client_id($s['ios_client_id'] ?? '');
        $google_enabled = self::enabled()
            && ($s['enabled'] ?? 'no') === 'yes'
            && $server_client_id !== ''
            && ($android_client_id !== '' || $ios_client_id !== '');
        return new WP_REST_Response([
            'enabled'=>self::enabled(),'https'=>DIP_Request::is_secure(),'version'=>DIP_VERSION,
            'google_android_configured'=>$android_client_id !== '',
            'google_ios_configured'=>$ios_client_id !== '',
            // OAuth client IDs are public identifiers. The client secret is
            // deliberately never returned. Flutter uses the Web/server client
            // ID as the requested ID-token audience, then exchanges that
            // short-lived proof exclusively with Identity Pro.
            'google'=>[
                'enabled'=>$google_enabled,
                'server_client_id'=>$server_client_id,
                'android_client_id'=>$android_client_id,
                'ios_client_id'=>$ios_client_id,
            ],
            'push_enabled'=>($s['mobile_push_enabled']??'no')==='yes',
            'biometric_enabled'=>($s['mobile_biometric_enabled']??'no')==='yes',
            'two_factor_supported'=>class_exists('DIP_Two_Factor'),
            'access_ttl'=>self::access_ttl(),'refresh_ttl'=>self::refresh_ttl(),
        ],200);
    }

    private static function social_role_blocked($user_id, array $settings) {
        $user = get_userdata(absint($user_id));
        if (!$user) return true;
        $configured = preg_split('/[\s,]+/', (string) ($settings['blocked_social_roles'] ?? 'administrator,editor,shop_manager'));
        $blocked = array_values(array_filter(array_map('sanitize_key', (array) $configured)));
        return user_can($user, 'manage_options')
            || user_can($user, 'manage_woocommerce')
            || (bool) array_intersect((array) $user->roles, $blocked);
    }

    private static function mobile_user_allowed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || !get_userdata($user_id)) return false;
        if (class_exists('DIP_Account_Sync')) {
            if (DIP_Account_Sync::privileged_mobile_blocked($user_id)) return false;
            if (is_wp_error(DIP_Account_Sync::login_guard($user_id))) return false;
        } else {
            if (self::social_role_blocked($user_id, self::settings())) return false;
            if (class_exists('DIP_Policy') && DIP_Policy::is_pending($user_id)) return false;
            if (get_user_meta($user_id, 'dip_email_verified', true) === 'no') return false;
        }
        return true;
    }

    public static function google_login(WP_REST_Request $request) {
        if (!self::throttle('google_login', 10, 10 * MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests.',['status'=>429]);
        if (!self::enabled()) return new WP_Error('mobile_api_disabled','Mobile authentication is disabled.',['status'=>403]);
        if (!DIP_Request::is_secure()) return new WP_Error('https_required','HTTPS is required.',['status'=>403]);
        if (!self::valid_device_request($request)) return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]);
        $id_token=trim((string)$request->get_param('id_token'));
        if (strlen($id_token)<100 || strlen($id_token)>65536) return new WP_Error('invalid_token','Invalid Google token.',['status'=>400]);
        $s=self::settings();
        if (($s['enabled']??'no')!=='yes') return self::denied('mobile_google_disabled');
        // Native Google Sign-In is configured with the Web/server client ID.
        // Google therefore signs the backend ID token for this audience. Do
        // not accept arbitrary Firebase/Android audiences here: Identity Pro
        // is the sole service allowed to turn a Google proof into an app
        // session.
        $server_client_id=self::oauth_client_id($s['client_id']??'');
        if ($server_client_id==='') return self::denied('mobile_google_server_audience_not_configured');
        $p = class_exists('DIP_Google') ? DIP_Google::verify_id_token($id_token, [$server_client_id], '') : new WP_Error('google_verifier_missing','Google verifier unavailable.');
        if (is_wp_error($p)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_google_token_rejected','critical',0,['reason'=>sanitize_key($p->get_error_code())]);
            return self::denied('google_token_rejected');
        }
        $platform=sanitize_key((string)($request->get_header('X-Platform')?:$request->get_param('platform')?:'unknown'));
        $platform_client_id = $platform==='android'
            ? self::oauth_client_id($s['android_client_id']??'')
            : ($platform==='ios' ? self::oauth_client_id($s['ios_client_id']??'') : '');
        if ($platform_client_id==='') return self::denied('mobile_google_platform_not_configured');
        // When Google supplies azp (authorized party), it must identify this
        // exact native client. This prevents another native OAuth client in the
        // same project from reusing a token against the Delicat mobile route.
        $azp=trim((string)($p['azp']??''));
        if ($azp!=='' && !hash_equals($platform_client_id,$azp)) return self::denied('mobile_google_authorized_party_mismatch');
        $profile=[
            'provider'=>'google',
            'sub'=>(string)($p['sub']??''),
            'email'=>sanitize_email($p['email']??''),
            'email_verified'=>true,
            'email_authoritative'=>DIP_Google::email_is_authoritative($p),
            'hd'=>sanitize_text_field($p['hd']??''),
            'name'=>sanitize_text_field($p['name']??''),
            'given_name'=>sanitize_text_field($p['given_name']??''),
            'family_name'=>sanitize_text_field($p['family_name']??''),
            'picture'=>esc_url_raw($p['picture']??'')
        ];
        $policy = DIP_Policy::enforce_profile($profile, $s);
        if (is_wp_error($policy)) return self::denied('mobile_' . $policy->get_error_code());
        $resolved=(new DIP_Identity($s))->resolve($profile,0);
        if (is_wp_error($resolved)) return new WP_Error($resolved->get_error_code(),$resolved->get_error_message(),['status'=>403]);
        if (!self::mobile_user_allowed($resolved)) return self::denied('mobile_account_not_allowed');
        $risk = DIP_Policy::assess($resolved, $profile, $s);
        if (is_wp_error($risk)) return self::denied('mobile_' . $risk->get_error_code());
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled((int)$resolved)) {
            $factor = sanitize_text_field((string)$request->get_param('two_factor_code'));
            if ($factor === '') {
                if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_two_factor_required', 'notice', (int)$resolved);
                return new WP_Error('two_factor_required','Two-factor verification is required.',['status'=>428]);
            }
            if (!DIP_Two_Factor::factor_attempt_allowed((int)$resolved)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_two_factor_rate_limited', 'warning', (int)$resolved);
                return new WP_Error('two_factor_rate_limited','Too many two-factor attempts. Try again later.',['status'=>429]);
            }
            if (!DIP_Two_Factor::verify_factor((int)$resolved, $factor, true)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_two_factor_failed', 'warning', (int)$resolved);
                return new WP_Error('two_factor_invalid','Invalid two-factor or recovery code.',['status'=>401]);
            }
            DIP_Two_Factor::clear_factor_rate((int)$resolved);
            $profile['two_factor'] = 1;
            if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_two_factor_success', 'info', (int)$resolved);
        }
        do_action('dip_login_success',(int)$resolved,$profile);
        if (class_exists('DIP_Audit')) DIP_Audit::record('login_success','info',(int)$resolved,['method'=>'mobile_google']);
        return self::issue((int)$resolved,$request);
    }

    private static function denied($event) { if(class_exists('DIP_Audit'))DIP_Audit::record($event,'critical',0); return new WP_Error('authentication_failed','Mobile authentication failed.',['status'=>401]); }

    private static function oauth_client_id($value) {
        $value=trim(sanitize_text_field((string)$value));
        return preg_match('/^[0-9]+-[A-Za-z0-9_-]+\.apps\.googleusercontent\.com$/D',$value)?$value:'';
    }

    private static function request_device(WP_REST_Request $r) {
        $id=sanitize_text_field((string)($r->get_header('X-Device-ID')?:$r->get_param('device_id')));
        $install=sanitize_text_field((string)($r->get_header('X-Installation-ID')?:$r->get_param('installation_id')));
        $platform=sanitize_key((string)($r->get_header('X-Platform')?:$r->get_param('platform')?:'unknown'));
        if(!in_array($platform,['android','ios','web','unknown'],true))$platform='unknown';
        return [
            'device_hash'=>self::device_hash($id?:'anonymous'),
            'installation_hash'=>self::device_hash($install?:($id?:'anonymous')),
            'device_name'=>substr(sanitize_text_field((string)($r->get_header('X-Device-Name')?:$r->get_param('device_name'))),0,100),
            'platform'=>$platform,
            'app_version'=>substr(sanitize_text_field((string)($r->get_header('X-App-Version')?:$r->get_param('app_version'))),0,32),
            'locale'=>substr(sanitize_text_field((string)($r->get_header('X-Locale')?:$r->get_param('locale'))),0,16),
        ];
    }

    private static function issue($user_id,WP_REST_Request $request,$replace_id=0,$expected_refresh_hash='') {
        global $wpdb; $access=self::random_token(); $refresh=self::random_token(); $d=self::request_device($request); $now=current_time('mysql',true);
        $data=array_merge($d,['user_id'=>$user_id,'token_hash'=>self::hash_token($access),'refresh_hash'=>self::hash_token($refresh),'created_at'=>$now,'last_used_at'=>$now,'access_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::access_ttl()),'refresh_expires_at'=>gmdate('Y-m-d H:i:s',time()+self::refresh_ttl()),'revoked_at'=>null]);
        if($replace_id){
            unset($data['created_at']);
            $where=['id'=>$replace_id];
            if($expected_refresh_hash!=='') $where['refresh_hash']=$expected_refresh_hash;
            $ok=$wpdb->update(self::table(),$data,$where);
            if($expected_refresh_hash!=='' && $ok!==1) return new WP_Error('refresh_replayed','Refresh token has already been rotated.',['status'=>401]);
        }
        else { $ok=$wpdb->insert(self::table(),$data); }
        if($ok===false) return new WP_Error('token_issue_failed','Unable to create a mobile session.',['status'=>500]);
        if(class_exists('DIP_Audit'))DIP_Audit::record('mobile_session_issued','info',$user_id);
        return new WP_REST_Response(['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>self::access_ttl(),'refresh_token'=>$refresh,'refresh_expires_in'=>self::refresh_ttl(),'user'=>self::user_payload($user_id),'device'=>$d],200);
    }

    public static function refresh(WP_REST_Request $request) {
        if (!self::throttle('refresh', 20, 10 * MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests.',['status'=>429]);
        if (!self::valid_device_request($request)) return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]);
        if(!self::enabled()||!DIP_Request::is_secure())return new WP_Error('forbidden','Request denied.',['status'=>403]);
        $token=trim((string)$request->get_param('refresh_token'));
        if(!preg_match('/^[A-Za-z0-9\-_]{40,512}$/',$token))return new WP_Error('invalid_refresh','Invalid refresh token.',['status'=>401]);
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE refresh_hash=%s AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() LIMIT 1',self::hash_token($token)));
        if(!$row)return new WP_Error('invalid_refresh','Refresh token expired or revoked.',['status'=>401]);
        $d=self::request_device($request);
        if(!hash_equals((string)$row->installation_hash,(string)$d['installation_hash']) || !hash_equals((string)$row->device_hash,(string)$d['device_hash'])){
            $wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);
            return self::denied('mobile_refresh_device_mismatch');
        }
        if (!self::mobile_user_allowed((int) $row->user_id)) { $wpdb->update(self::table(), ['revoked_at'=>current_time('mysql',true)], ['id'=>(int)$row->id]); return self::denied('mobile_account_not_allowed'); }
        return self::issue((int)$row->user_id,$request,(int)$row->id,self::hash_token($token));
    }

    public static function current_session_for_request($request = null, $require_device_binding = true) {
        if (!self::enabled()) return null;
        $token = '';
        if ($request instanceof WP_REST_Request) {
            $token = trim((string) $request->get_header('x_delicat_identity_token'));
            if ($token === '') {
                $auth = trim((string) $request->get_header('authorization'));
                if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
            }
        }
        if ($token === '') {
            $auth = self::server_authorization_header();
            if (preg_match('/^Bearer\s+([A-Za-z0-9\-_]{40,512})$/i', $auth, $m)) $token = $m[1];
        }
        if (!preg_match('/^[A-Za-z0-9\-_]{40,512}$/', $token)) return null;
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE token_hash=%s AND revoked_at IS NULL AND access_expires_at > UTC_TIMESTAMP() LIMIT 1', self::hash_token($token)));
        if (!$row || !self::mobile_user_allowed((int) $row->user_id)) return null;

        // Protected Identity endpoints are bound to the app installation that
        // received the token. A copied access token alone is therefore not
        // sufficient to use profile/session/push/biometric/pairing endpoints.
        if ($require_device_binding && $request instanceof WP_REST_Request) {
            if (!self::valid_device_request($request)) return null;
            $device = self::request_device($request);
            if (!hash_equals((string) $row->installation_hash, (string) $device['installation_hash'])
                || !hash_equals((string) $row->device_hash, (string) $device['device_hash'])) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_access_device_mismatch', 'critical', (int) $row->user_id, ['session_id'=>(int) $row->id]);
                return null;
            }
        }
        $wpdb->update(self::table(), ['last_used_at'=>current_time('mysql',true)], ['id'=>(int)$row->id], ['%s'], ['%d']);
        return $row;
    }

    /** Check a previously authenticated mobile session by internal ID/user. */
    public static function session_is_active($session_id, $user_id) {
        $session_id = absint($session_id);
        $user_id = absint($user_id);
        if (!$session_id || !$user_id) return false;
        global $wpdb;
        $found = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE id=%d AND user_id=%d AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() LIMIT 1',
            $session_id,
            $user_id
        ));
        return absint($found) === $session_id;
    }

    private static function current_session() { return self::current_session_for_request(null, false); }

    public static function logout(WP_REST_Request $request) { $row=self::current_session_for_request($request); if($row){global $wpdb;$wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>(int)$row->id]);} return new WP_REST_Response(['logged_out'=>true],200); }

    /** Revoke every Delicat mobile refresh/access session for an account. */
    public static function revoke_all_for_user($user_id, $reason = 'security_change') {
        $user_id = absint($user_id);
        if (!$user_id) return 0;
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE '.self::table().' SET revoked_at=UTC_TIMESTAMP() WHERE user_id=%d AND revoked_at IS NULL',
            $user_id
        ));
        if ($updated === false) return 0;
        if (class_exists('DIP_Audit')) DIP_Audit::record('mobile_sessions_revoked', 'warning', $user_id, [
            'reason'=>sanitize_key($reason),
            'count'=>(int) $updated,
        ]);
        return (int) $updated;
    }
    public static function profile(){return new WP_REST_Response(self::user_payload(get_current_user_id()),200);}
    private static function user_payload($id){
        $id=absint($id);
        if(class_exists('Delicat_App_Auth')&&is_callable(['Delicat_App_Auth','user_payload'])){
            $payload=(array)Delicat_App_Auth::user_payload($id);
            if($payload)return $payload;
        }
        $u=get_userdata($id);
        if(!$u)return[];
        $code=sanitize_text_field((string)apply_filters('delicat_app_user_code','',$id));
        return[
            'id'=>$id,
            'email'=>sanitize_email($u->user_email),
            'name'=>sanitize_text_field($u->display_name),
            'display_name'=>sanitize_text_field($u->display_name),
            'username'=>sanitize_user($u->user_login),
            'phone'=>sanitize_text_field((string)get_user_meta($id,'billing_phone',true)),
            'avatar'=>get_avatar_url($id,['size'=>192]),
            'user_code'=>$code,
            'roles'=>array_values((array)$u->roles),
        ];
    }

    public static function sessions(){global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT id,device_name,platform,app_version,locale,biometric_enabled,created_at,last_used_at,access_expires_at,refresh_expires_at FROM '.self::table().' WHERE user_id=%d AND revoked_at IS NULL ORDER BY last_used_at DESC LIMIT 50',get_current_user_id()),ARRAY_A);return new WP_REST_Response(['sessions'=>$rows],200);}
    public static function revoke_session(WP_REST_Request $r){global $wpdb;$updated=$wpdb->update(self::table(),['revoked_at'=>current_time('mysql',true)],['id'=>absint($r['id']),'user_id'=>get_current_user_id()]);return new WP_REST_Response(['revoked'=>(bool)$updated],200);}

    public static function update_device(WP_REST_Request $r){$row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);$d=self::request_device($r);global $wpdb;$wpdb->update(self::table(),['device_name'=>$d['device_name'],'app_version'=>$d['app_version'],'locale'=>$d['locale']],['id'=>(int)$row->id]);return new WP_REST_Response(['updated'=>true],200);}

    public static function register_push(WP_REST_Request $r){
        if((self::settings()['mobile_push_enabled']??'no')!=='yes')return new WP_Error('push_disabled','Push registration is disabled.',['status'=>403]);
        $row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $token=trim((string)$r->get_param('push_token'));$provider=sanitize_key((string)$r->get_param('provider'));
        if(!in_array($provider,['fcm','apns'],true)||strlen($token)<20||strlen($token)>4096)return new WP_Error('invalid_push','Invalid push registration.',['status'=>400]);
        $encrypted = DIP_Crypto::encrypt($token);
        if ($encrypted === '') return new WP_Error('encryption_unavailable','Secure encryption is unavailable.',['status'=>503]);
        global $wpdb;$wpdb->update(self::table(),['push_provider'=>$provider,'push_token_enc'=>$encrypted],['id'=>(int)$row->id]);
        do_action('dip_mobile_push_registered',(int)$row->user_id,(int)$row->id,$provider);
        return new WP_REST_Response(['registered'=>true],200);
    }
    public static function remove_push(WP_REST_Request $r){ $row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);global $wpdb;$wpdb->update(self::table(),['push_provider'=>'','push_token_enc'=>null],['id'=>(int)$row->id]);return new WP_REST_Response(['removed'=>true],200); }

    public static function biometric_register(WP_REST_Request $r){
        if (!function_exists('openssl_pkey_get_public') || !function_exists('openssl_verify')) return new WP_Error('crypto_unavailable','Biometric verification is unavailable.',['status'=>503]);
        if((self::settings()['mobile_biometric_enabled']??'no')!=='yes')return new WP_Error('biometric_disabled','Biometric login is disabled.',['status'=>403]);
        $row=self::current_session_for_request($r);if(!$row)return new WP_Error('session_not_found','Mobile session not found.',['status'=>401]);
        $pem=trim((string)$r->get_param('public_key'));
        if(strlen($pem)<200||strlen($pem)>8192||strpos($pem,'BEGIN PUBLIC KEY')===false||!openssl_pkey_get_public($pem))return new WP_Error('invalid_public_key','Invalid biometric public key.',['status'=>400]);
        global $wpdb;$wpdb->update(self::table(),['biometric_public_key'=>$pem,'biometric_key_hash'=>hash('sha256',$pem),'biometric_enabled'=>1],['id'=>(int)$row->id]);
        if(class_exists('DIP_Audit'))DIP_Audit::record('mobile_biometric_registered','info',(int)$row->user_id);
        return new WP_REST_Response(['registered'=>true,'key_fingerprint'=>substr(hash('sha256',$pem),0,16)],200);
    }

    public static function biometric_challenge(WP_REST_Request $r){
        if (!self::throttle('biometric_challenge', 10, 10 * MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests.',['status'=>429]);
        $raw_installation = trim((string)$r->get_param('installation_id'));
        if (strlen($raw_installation) < 16 || strlen($raw_installation) > 255) return new WP_Error('invalid_installation','Invalid installation.',['status'=>400]);
        if (!function_exists('openssl_verify')) return new WP_Error('crypto_unavailable','Biometric verification is unavailable.',['status'=>503]);
        if(!self::enabled()||(self::settings()['mobile_biometric_enabled']??'no')!=='yes'||!DIP_Request::is_secure())return new WP_Error('forbidden','Request denied.',['status'=>403]);
        $installation=self::device_hash(sanitize_text_field($raw_installation));
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE installation_hash=%s AND biometric_enabled=1 AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() ORDER BY last_used_at DESC LIMIT 1',$installation));
        if(!$row)return new WP_Error('biometric_unavailable','Biometric login is not registered for this installation.',['status'=>404]);
        $challenge=self::random_token(32);$key='dip_bio_'.hash('sha256',$challenge);set_transient($key,['session_id'=>(int)$row->id,'installation_hash'=>$installation],self::CHALLENGE_TTL);
        return new WP_REST_Response(['challenge'=>$challenge,'expires_in'=>self::CHALLENGE_TTL,'algorithm'=>'SHA256withRSA_or_ECDSA'],200);
    }

    public static function biometric_verify(WP_REST_Request $r){
        if (!self::throttle('biometric_verify', 10, 10 * MINUTE_IN_SECONDS)) return new WP_Error('rate_limited','Too many requests.',['status'=>429]);
        if (!self::valid_device_request($r)) return new WP_Error('device_required','A valid device and installation identifier are required.',['status'=>400]);
        if(!self::enabled()||(self::settings()['mobile_biometric_enabled']??'no')!=='yes'||!DIP_Request::is_secure())return new WP_Error('forbidden','Request denied.',['status'=>403]);
        $challenge=trim((string)$r->get_param('challenge'));$signature=base64_decode((string)$r->get_param('signature'),true);
        if(strlen($challenge)<20||strlen($challenge)>512||$signature===false||strlen($signature)>2048)return new WP_Error('invalid_biometric','Invalid biometric proof.',['status'=>400]);
        $key='dip_bio_'.hash('sha256',$challenge);$state=get_transient($key);delete_transient($key);
        if(!is_array($state))return new WP_Error('challenge_expired','Biometric challenge expired.',['status'=>401]);
        $request_device = self::request_device($r);
        if (!hash_equals((string)$state['installation_hash'], (string)$request_device['installation_hash'])) return self::denied('mobile_biometric_installation_mismatch');
        global $wpdb;$row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.self::table().' WHERE id=%d AND biometric_enabled=1 AND revoked_at IS NULL AND refresh_expires_at > UTC_TIMESTAMP() LIMIT 1',absint($state['session_id'])));
        if ($row && !hash_equals((string)$row->installation_hash, (string)$request_device['installation_hash'])) return self::denied('mobile_biometric_installation_mismatch');
        if(!$row||empty($row->biometric_public_key))return new WP_Error('biometric_unavailable','Biometric login unavailable.',['status'=>401]);
        $ok=openssl_verify($challenge,$signature,$row->biometric_public_key,OPENSSL_ALGO_SHA256);
        if($ok!==1)return self::denied('mobile_biometric_failed');
        if (!self::mobile_user_allowed((int) $row->user_id)) { $wpdb->update(self::table(), ['revoked_at'=>current_time('mysql',true)], ['id'=>(int)$row->id]); return self::denied('mobile_account_not_allowed'); }
        if(class_exists('DIP_Audit'))DIP_Audit::record('mobile_biometric_success','info',(int)$row->user_id);
        return self::issue((int)$row->user_id,$r,(int)$row->id);
    }

    public static function cleanup(){global $wpdb;$wpdb->query('DELETE FROM '.self::table().' WHERE (revoked_at IS NOT NULL AND revoked_at < UTC_TIMESTAMP() - INTERVAL 30 DAY) OR refresh_expires_at < UTC_TIMESTAMP() - INTERVAL 30 DAY');}
}
