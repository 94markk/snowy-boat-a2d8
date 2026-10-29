<?php
defined('ABSPATH') || exit;

/**
 * Shared WordPress/WooCommerce account lifecycle helpers.
 *
 * Every Delicat authentication entry point uses this class for customer
 * creation, verification state and post-auth synchronization. It also bridges
 * accounts created outside Delicat (Woo My Account, Checkout/Store API and
 * WordPress registration) into the same policy.
 */
final class DIP_Account_Sync {
    private static $managed_creation_depth = 0;
    private static $wp_login_context = '';

    private static function dangerous_capabilities() {
        return [
            'manage_options', 'manage_woocommerce', 'view_woocommerce_reports',
            'edit_users', 'create_users', 'promote_users', 'remove_users', 'list_users',
            'edit_posts', 'publish_posts', 'delete_posts', 'edit_others_posts', 'upload_files',
            'edit_pages', 'publish_pages', 'delete_pages', 'edit_others_pages',
            'edit_products', 'publish_products', 'delete_products', 'edit_others_products',
            'edit_private_products', 'read_private_products', 'delete_private_products',
            'edit_shop_orders', 'publish_shop_orders', 'delete_shop_orders', 'edit_others_shop_orders',
            'manage_product_terms', 'edit_product_terms', 'delete_product_terms',
            'install_plugins', 'activate_plugins', 'update_plugins', 'delete_plugins',
            'install_themes', 'switch_themes', 'edit_theme_options', 'update_themes', 'delete_themes',
            'update_core', 'unfiltered_html', 'moderate_comments', 'export', 'import',
        ];
    }

    private static function role_is_safe_for_customer($role_name) {
        $role_name = sanitize_key($role_name);
        $role = $role_name ? get_role($role_name) : false;
        if (!$role || empty($role->capabilities['read'])) return false;
        foreach (self::dangerous_capabilities() as $cap) {
            if (!empty($role->capabilities[$cap])) return false;
        }
        return true;
    }

    private static function allowed_customer_roles($user = null) {
        $roles = ['customer', 'subscriber'];
        if (class_exists('DIP_Plugin')) {
            $settings = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
            foreach (['default_role', 'google_default_role', 'microsoft_default_role'] as $key) {
                if (!empty($settings[$key]) && self::role_is_safe_for_customer($settings[$key])) $roles[] = sanitize_key($settings[$key]);
            }
        }
        $roles = apply_filters('dip_customer_account_roles', array_values(array_unique($roles)), $user);
        $safe = [];
        foreach (array_unique(array_map('sanitize_key', (array) $roles)) as $role_name) {
            if (self::role_is_safe_for_customer($role_name)) $safe[] = $role_name;
        }
        return array_values($safe);
    }

    /** True for customer/subscriber/explicit safe storefront roles, never staff. */
    public static function is_customer_account($user_id) {
        $user = get_userdata(absint($user_id));
        if (!$user) return false;
        foreach (self::dangerous_capabilities() as $cap) {
            if (user_can($user, $cap)) return false;
        }
        return (bool) array_intersect((array) $user->roles, self::allowed_customer_roles($user));
    }

    /**
     * Canonical public-account switch shared by Delicat's native, social,
     * passwordless and mobile signup paths. WooCommerce exposes account
     * creation independently on My Account and during checkout, while plain
     * WordPress uses users_can_register. If none are enabled, Delicat must not
     * silently reopen public registration.
     */
    public static function storefront_registration_enabled() {
        $enabled = (bool) get_option('users_can_register')
            || 'yes' === get_option('woocommerce_enable_myaccount_registration')
            || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout');
        return (bool) apply_filters('dip_storefront_registration_enabled', $enabled);
    }

    /** Sanitize configured/default registration roles at the privilege boundary. */
    public static function safe_registration_role($role) {
        $role = sanitize_key($role);
        if (!self::role_is_safe_for_customer($role)) return get_role('customer') ? 'customer' : 'subscriber';
        return $role;
    }

    private static function finalize_created_account($result, $expected_role) {
        if (is_wp_error($result) || !absint($result)) return $result;
        $user_id = absint($result);
        $user = get_userdata($user_id);
        if (!$user) return new WP_Error('account_creation_failed', __('Impossible de charger le compte créé.', 'delicat-google-login'));
        if (self::is_customer_account($user_id)) return $user_id;

        // A third-party Woo/WordPress filter must not be able to turn a public
        // registration into an author/editor/shop/admin account.
        $expected_role = self::safe_registration_role($expected_role);
        $user->set_role($expected_role);
        clean_user_cache($user_id);
        if (!self::is_customer_account($user_id)) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('unsafe_registration_role_blocked', 'critical', $user_id, ['expected_role'=>$expected_role]);
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($user_id);
            return new WP_Error('unsafe_registration_role', __('Création de compte bloquée par la politique de sécurité.', 'delicat-google-login'));
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('unsafe_registration_role_downgraded', 'warning', $user_id, ['safe_role'=>$expected_role]);
        return $user_id;
    }

    public static function is_managed_creation() {
        return self::$managed_creation_depth > 0;
    }

    /** Mark manually-fired wp_login hooks so security history keeps the real method. */
    public static function wp_login_context() {
        return self::$wp_login_context;
    }

    public static function fire_wp_login($user, $method = 'delicat') {
        if (!($user instanceof WP_User)) return;
        $previous = self::$wp_login_context;
        self::$wp_login_context = sanitize_key($method) ?: 'delicat';
        try {
            do_action('wp_login', $user->user_login, $user);
        } finally {
            self::$wp_login_context = $previous;
        }
    }

    public static function create_customer($email, $username, $password, array $args = [], $role = 'customer') {
        $email = sanitize_email($email);
        $username = sanitize_user($username, true);
        $role = self::safe_registration_role($role);
        if (!is_email($email) || $username === '') return new WP_Error('invalid_account_data', __('Données de compte invalides.', 'delicat-google-login'));

        $safe = [];
        foreach (['display_name', 'first_name', 'last_name'] as $key) {
            if (isset($args[$key])) $safe[$key] = sanitize_text_field($args[$key]);
        }

        self::$managed_creation_depth++;
        try {
            // Canonical Woo creation preserves Woo validation and lifecycle
            // hooks while our depth flag prevents the external-registration
            // bridge from sending duplicate verification messages.
            if ($role === 'customer' && function_exists('wc_create_new_customer')) {
                // WooCommerce intentionally exposes woocommerce_new_customer_data
                // before wp_insert_user(). Preserve compatibility with that hook,
                // but enforce the identity/privilege boundary at the last priority
                // so a buggy extension cannot turn a public Delicat registration
                // into another user, another email, or a privileged account.
                $identity_guard = static function ($data) use ($email, $username, $password, $role) {
                    if (!is_array($data)) $data = [];
                    unset($data['ID'], $data['id']);
                    $data['user_login'] = $username;
                    $data['user_email'] = $email;
                    $data['user_pass'] = (string) $password;
                    $data['role'] = $role;
                    if (!empty($data['meta_input']) && is_array($data['meta_input'])) {
                        global $wpdb;
                        $blocked_meta = [
                            $wpdb->prefix . 'capabilities',
                            $wpdb->prefix . 'user_level',
                            'session_tokens',
                            '_application_passwords',
                        ];
                        foreach (array_keys($data['meta_input']) as $meta_key) {
                            $normalized = (string) $meta_key;
                            if (in_array($normalized, $blocked_meta, true) || stripos($normalized, 'capabilities') !== false || stripos($normalized, 'user_level') !== false) {
                                unset($data['meta_input'][$meta_key]);
                            }
                        }
                    }
                    return $data;
                };
                add_filter('woocommerce_new_customer_data', $identity_guard, PHP_INT_MAX, 1);
                try {
                    $created = wc_create_new_customer($email, $username, (string) $password, $safe);
                } finally {
                    remove_filter('woocommerce_new_customer_data', $identity_guard, PHP_INT_MAX);
                }
                return self::finalize_created_account($created, $role);
            }

            $created = wp_insert_user(array_merge($safe, [
                'user_login' => $username,
                'user_email' => $email,
                'user_pass' => (string) $password,
                'role' => $role,
            ]));
            return self::finalize_created_account($created, $role);
        } finally {
            self::$managed_creation_depth = max(0, self::$managed_creation_depth - 1);
        }
    }

    /** Initialize the Woo customer/session view after a Delicat-authenticated login. */
    public static function after_login($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || !function_exists('WC')) return;
        try {
            $wc = WC();
            if ($wc && isset($wc->session) && is_object($wc->session) && is_callable([$wc->session, 'init_session_cookie'])) {
                $wc->session->init_session_cookie();
            }
            if ($wc && isset($wc->customer) && is_object($wc->customer) && (int) $wc->customer->get_id() !== $user_id && class_exists('WC_Customer')) {
                $wc->customer = new WC_Customer($user_id, true);
            }
        } catch (Throwable $e) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('woocommerce_session_sync_failed', 'warning', $user_id, ['error'=>substr($e->getMessage(), 0, 180)]);
        }
    }

    /** Keyed hash of an e-mail address for audit context (not reversible without the site salt). */
    public static function email_hash($email) {
        return hash_hmac('sha256', strtolower(trim((string) $email)), wp_salt('auth'));
    }

    /**
     * E-mail OTP and magic-link sign-in are a storefront-customer convenience.
     * Staff accounts keep password + two-step verification (and, for Google,
     * Secure Mode): a mailbox alone never opens a privileged session.
     */
    public static function passwordless_login_allowed($user_id) {
        $user_id = absint($user_id);
        return $user_id > 0 && self::is_customer_account($user_id) && !self::privileged_mobile_blocked($user_id);
    }

    /**
     * Run synchronization that is only safe once ownership of the exact current
     * email address is known (OIDC proof, OTP/magic, or verification link).
     *
     * $source: 'email_link' (the account's own verification link, default),
     * 'oidc', 'otp', 'magic', or 'unproven' (verification switched off: the
     * address only becomes usable for sign-in, it is never recorded as proof
     * and never claims guest orders).
     */
    public static function after_verified_registration($user_id, $verified_email = '', $source = 'email_link') {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) return false;
        $source = sanitize_key((string) $source);
        if (!in_array($source, ['email_link', 'oidc', 'otp', 'magic', 'unproven'], true)) $source = 'email_link';

        $local_email = sanitize_email($user->user_email);
        $verified_email = sanitize_email($verified_email ?: $local_email);
        if (!$local_email || !$verified_email || !hash_equals(strtolower($local_email), strtolower($verified_email))) {
            if (self::is_customer_account($user_id)) {
                update_user_meta($user_id, 'dip_email_verified', 'no');
                delete_user_meta($user_id, '_dip_verified_email_fingerprint');
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('verified_email_mismatch', 'warning', $user_id, [
                'local_email_hash'=>self::email_hash($local_email),
                'proof_email_hash'=>self::email_hash($verified_email),
            ]);
            return false;
        }

        if (!self::is_customer_account($user_id)) return true;

        if ($source === 'unproven') {
            update_user_meta($user_id, 'dip_email_verified', 'yes');
            return true;
        }

        // Account pre-hijacking: whoever created this unverified account (and
        // chose its password, or changed its e-mail to this address) never
        // proved the mailbox. When the real owner proves it through Google,
        // an e-mail code or a magic link, nothing set up before that proof may
        // keep working. The account's own verification link is different: it
        // was sent to that mailbox for the registrant who set the password.
        if (get_user_meta($user_id, 'dip_email_verified', true) === 'no' && in_array($source, ['oidc', 'otp', 'magic'], true)) {
            self::reset_pre_verification_credentials($user_id, $source);
        }

        update_user_meta($user_id, 'dip_email_verified', 'yes');
        $email_fingerprint = hash('sha256', strtolower($local_email));
        update_user_meta($user_id, '_dip_verified_email_fingerprint', $email_fingerprint);
        // v2 proof marker distinguishes verification performed by hardened flows
        // from legacy metadata that may have trusted a non-authoritative social email.
        update_user_meta($user_id, '_dip_verified_email_proof_v2', $email_fingerprint);
        $last_synced = (string) get_user_meta($user_id, '_dip_wc_verified_email_sync', true);
        $needs_email_sync = $email_fingerprint !== '' && !hash_equals($email_fingerprint, $last_synced);

        if ($verified_email && !get_user_meta($user_id, 'billing_email', true)) {
            update_user_meta($user_id, 'billing_email', $verified_email);
        }
        $first = (string) get_user_meta($user_id, 'first_name', true);
        $last = (string) get_user_meta($user_id, 'last_name', true);
        if ($first && !get_user_meta($user_id, 'billing_first_name', true)) update_user_meta($user_id, 'billing_first_name', sanitize_text_field($first));
        if ($last && !get_user_meta($user_id, 'billing_last_name', true)) update_user_meta($user_id, 'billing_last_name', sanitize_text_field($last));

        // Link historical guest orders only after email ownership is proven,
        // and only once per verified address to avoid expensive repeat scans.
        if ($needs_email_sync && function_exists('wc_update_new_customer_past_orders')) {
            try {
                wc_update_new_customer_past_orders($user_id);
                update_user_meta($user_id, '_dip_wc_verified_email_sync', $email_fingerprint);
            } catch (Throwable $e) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('woocommerce_past_orders_sync_failed', 'warning', $user_id, ['error'=>substr($e->getMessage(), 0, 180)]);
            }
        } elseif ($needs_email_sync) {
            delete_user_meta($user_id, '_dip_wc_verified_email_sync');
        }
        do_action('dip_account_verified_sync', $user_id);
        return true;
    }

    /**
     * Replace every credential that existed before the mailbox was proven:
     * the password becomes a random managed one, WordPress and app sessions
     * end, quick sign-in keys and application passwords go, and a provider
     * link still pending from sign-up is dropped. The customer is told that a
     * password can be chosen again through « Mot de passe oublié ».
     */
    private static function reset_pre_verification_credentials($user_id, $source) {
        $user_id = absint($user_id);
        $managed_key = class_exists('DIP_Identity') ? DIP_Identity::META_PASSWORD_MANAGED : '_dglp_password_managed';
        $had_own_password = get_user_meta($user_id, $managed_key, true) !== 'yes';

        // Revoke first under this reason; the wp_set_password hook below then
        // finds the per-request guard already set and does not repeat it.
        self::revoke_app_credentials_once($user_id, 'pre_verification_credentials');
        self::$rotating_password = $user_id;
        try {
            wp_set_password(wp_generate_password(64, true, true), $user_id);
        } finally {
            self::$rotating_password = 0;
        }
        update_user_meta($user_id, $managed_key, 'yes');
        if (class_exists('WP_Session_Tokens')) {
            try { WP_Session_Tokens::get_instance($user_id)->destroy_all(); } catch (Throwable $e) {}
        }
        if (class_exists('WP_Application_Passwords') && method_exists('WP_Application_Passwords', 'delete_all_application_passwords')) {
            WP_Application_Passwords::delete_all_application_passwords($user_id);
        }
        if (class_exists('DIP_Identity') && method_exists('DIP_Identity', 'clear_pending_provider_link')) {
            DIP_Identity::clear_pending_provider_link($user_id);
        }
        if (class_exists('DIP_Audit')) DIP_Audit::record('pre_verification_credentials_reset', 'warning', $user_id, [
            'source' => sanitize_key($source),
            'password_replaced' => $had_own_password ? 1 : 0,
        ]);
        if (!$had_own_password) return;

        $subject = __('Votre adresse e-mail est confirmée', 'delicat-google-login');
        $message = __('Votre adresse e-mail vient d’être confirmée. Par sécurité, le mot de passe défini avant cette confirmation ne fonctionne plus et les sessions ouvertes ont été fermées.', 'delicat-google-login');
        $advice = __('Pour utiliser un mot de passe, choisissez « Mot de passe oublié » sur la page de connexion. Vous pouvez aussi continuer avec Google ou un code reçu par e-mail.', 'delicat-google-login');
        if (class_exists('DIP_Email_Hub')) {
            DIP_Email_Hub::send_user_security($user_id, $subject, $message, ['title'=>__('Adresse e-mail confirmée', 'delicat-google-login'), 'badge'=>__('Sécurité du compte', 'delicat-google-login'), 'accent'=>'#2563eb', 'advice'=>$advice]);
        } else {
            $user = get_userdata($user_id);
            if ($user && is_email($user->user_email)) wp_mail($user->user_email, $subject, $message . "\n\n" . $advice);
        }
    }

    /** @var int user whose password Delicat is replacing by a random managed one */
    private static $rotating_password = 0;

    /**
     * True while Delicat itself replaces a password with a random managed one
     * (pre-verification reset): wp_set_password listeners can skip their
     * « mot de passe modifié » alert, the customer gets a dedicated e-mail.
     */
    public static function internal_password_rotation($user_id = 0) {
        return self::$rotating_password > 0 && (!$user_id || absint($user_id) === self::$rotating_password);
    }

    /** @var array<int,string> users whose app credentials were revoked in this request */
    private static $credentials_revoked = [];

    /**
     * One revocation of the customer's app sessions and quick sign-in keys per
     * request, whichever password hook fires first (core reset fires
     * password_reset, wp_set_password and after_password_reset; WooCommerce's
     * reset only the first two).
     */
    private static function revoke_app_credentials_once($user_id, $reason) {
        $user_id = absint($user_id);
        if (!$user_id || isset(self::$credentials_revoked[$user_id])) return false;
        self::$credentials_revoked[$user_id] = sanitize_key($reason);
        if (!self::is_customer_account($user_id)) return false;
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user_id, $reason);
        return true;
    }

    /**
     * wp_set_password (WordPress 6.2+): every password reset or programmatic
     * change, including WooCommerce's « Mot de passe oublié », which never
     * fires after_password_reset. A re-hash of the same password at sign-in
     * (WordPress 6.8+ bcrypt upgrade, legacy MD5 hashes) is not a change.
     */
    public static function password_set($password, $user_id, $old_user_data = null) {
        $user_id = absint($user_id);
        if (!$user_id) return;
        if ($old_user_data instanceof WP_User) {
            $old_hash = (string) $old_user_data->user_pass;
            if ($old_hash !== '' && (string) $password !== '' && wp_check_password((string) $password, $old_hash)) return;
        } elseif (doing_filter('authenticate')) {
            return;
        }
        if (class_exists('DIP_Identity') && method_exists('DIP_Identity', 'clear_pending_provider_link')) {
            DIP_Identity::clear_pending_provider_link($user_id);
        }
        if (self::revoke_app_credentials_once($user_id, 'password_reset') && class_exists('DIP_Audit')) {
            DIP_Audit::record('password_reset_mobile_sessions_revoked', 'warning', $user_id);
        }
    }

    /**
     * Bring accounts created by native WordPress/WooCommerce forms into the
     * same Delicat verification state. Delicat-managed creations are skipped.
     */
    public static function external_user_registered($user_id, $userdata = []) {
        $user_id = absint($user_id);
        if (!$user_id || self::is_managed_creation() || !self::is_customer_account($user_id)) return;
        $user = get_userdata($user_id);
        if (!$user || !is_email($user->user_email)) return;

        $requires = class_exists('DIP_Passwordless_Registration') && DIP_Passwordless_Registration::requires_email_verification();
        if (!$requires) {
            self::after_verified_registration($user_id, $user->user_email, 'unproven');
            return;
        }

        update_user_meta($user_id, 'dip_email_verified', 'no');
        delete_user_meta($user_id, '_dip_verified_email_fingerprint');
        delete_user_meta($user_id, '_dip_verified_email_proof_v2');
        delete_user_meta($user_id, '_dip_wc_verified_email_sync');
        $sent = class_exists('DIP_Passwordless_Registration')
            ? DIP_Passwordless_Registration::send_email_verification($user_id, $user->user_email)
            : false;

        if (class_exists('DIP_Audit')) DIP_Audit::record('external_registration_verification_required', 'notice', $user_id, [
            'source' => (defined('REST_REQUEST') && REST_REQUEST) ? 'rest' : (wp_doing_ajax() ? 'ajax' : 'web'),
            'verification_sent' => $sent ? 1 : 0,
        ]);

        // Classic Woo/WordPress forms benefit from a visible message. REST and
        // AJAX callers receive their normal API response and the email itself.
        if ($sent && function_exists('wc_add_notice') && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            wc_add_notice(__('Compte créé. Vérifiez votre e-mail avant votre prochaine connexion.', 'delicat-google-login'), 'notice');
        }
    }


    /**
     * Delicat mobile clients use dedicated rotating bearer sessions. WordPress
     * Application Passwords are therefore unnecessary for storefront customer
     * accounts and would create a parallel authentication path outside Delicat's
     * email-verification/session policy. Staff/admin behavior is untouched.
     */
    public static function filter_application_passwords($available, $user) {
        if (!$available || !($user instanceof WP_User)) return $available;
        if (!self::is_customer_account($user->ID)) return $available;
        $allow = (bool) apply_filters('dip_allow_customer_application_passwords', false, $user);
        return $allow ? $available : false;
    }

    /** Prevent Woo My Account's direct auto-login when Delicat policy blocks it. */
    public static function woocommerce_registration_auth($auth_new_customer, $customer_id) {
        if (!$auth_new_customer) return false;
        $guard = self::login_guard(absint($customer_id));
        return is_wp_error($guard) ? false : true;
    }

    /**
     * Final cookie boundary. Woo checkout/Store API can call
     * wc_set_customer_auth_cookie() directly without wp_signon(); this filter
     * ensures those paths cannot bypass verification/pending-approval policy.
     */
    public static function filter_auth_cookies($send, $expire = 0, $expiration = 0, $user_id = 0, $scheme = '', $token = '') {
        if (!$send || !absint($user_id)) return $send;
        $guard = self::login_guard(absint($user_id));
        if (is_wp_error($guard)) {
            // wp_set_auth_cookie() creates a WP session token before this final
            // filter runs. Destroy that unused token as well as the in-request
            // current-user assignment made by Woo's wc_set_customer_auth_cookie().
            if ($token !== '' && class_exists('WP_Session_Tokens')) {
                try { WP_Session_Tokens::get_instance(absint($user_id))->destroy((string) $token); } catch (Throwable $e) {}
            }
            if (get_current_user_id() === absint($user_id)) wp_set_current_user(0);
            if (class_exists('DIP_Audit')) DIP_Audit::record('auth_cookie_blocked_by_account_policy', 'notice', absint($user_id), ['code'=>$guard->get_error_code(),'session_token_destroyed'=>$token !== '' ? 1 : 0]);
            return false;
        }
        return $send;
    }

    /** Invalidate exact-email ownership whenever WordPress changes user_email. */
    public static function profile_email_changed($user_id, $old_user_data) {
        $user_id = absint($user_id);
        if (!$user_id || !($old_user_data instanceof WP_User) || !self::is_customer_account($user_id)) return;
        $current = get_userdata($user_id);
        if (!$current) return;

        // wp_update_user() password changes do not automatically know about
        // Delicat's independent mobile token table. Revoke those credentials.
        $old_pass_hash = (string) $old_user_data->user_pass;
        $new_pass_hash = (string) $current->user_pass;
        if ($old_pass_hash !== '' && $new_pass_hash !== '' && !hash_equals($old_pass_hash, $new_pass_hash)) {
            if (self::revoke_app_credentials_once($user_id, 'password_changed') && class_exists('DIP_Audit')) {
                DIP_Audit::record('account_password_changed_mobile_revoked', 'warning', $user_id);
            }
        }

        $old = strtolower(sanitize_email($old_user_data->user_email));
        $new = strtolower(sanitize_email($current->user_email));
        if (!$old || !$new || hash_equals($old, $new)) return;

        $requires = class_exists('DIP_Passwordless_Registration') && DIP_Passwordless_Registration::requires_email_verification();
        delete_user_meta($user_id, '_dip_verified_email_fingerprint');
        delete_user_meta($user_id, '_dip_verified_email_proof_v2');
        delete_user_meta($user_id, '_dip_wc_verified_email_sync');
        delete_user_meta($user_id, '_dip_email_verify_hash');
        delete_user_meta($user_id, '_dip_email_verify_expires');
        delete_user_meta($user_id, '_dip_email_verify_email_hash');

        if (!$requires) {
            self::after_verified_registration($user_id, $new, 'unproven');
            return;
        }

        update_user_meta($user_id, 'dip_email_verified', 'no');

        // Existing browser cookies must not keep access after the identity email
        // changes. Invalidate all WordPress sessions; the current request may
        // finish, but subsequent requests require verification + fresh login.
        if (class_exists('WP_Session_Tokens')) {
            try { WP_Session_Tokens::get_instance($user_id)->destroy_all(); } catch (Throwable $e) {}
        }
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user_id, 'email_changed');

        $sent = class_exists('DIP_Passwordless_Registration')
            ? DIP_Passwordless_Registration::send_email_verification($user_id, $new)
            : false;
        if (class_exists('DIP_Audit')) DIP_Audit::record('account_email_changed_verification_required', 'warning', $user_id, [
            'old_email_hash'=>self::email_hash($old),
            'new_email_hash'=>self::email_hash($new),
            'verification_sent'=>$sent ? 1 : 0,
            'sessions_revoked'=>1,
        ]);

        // The customer's own session ended with the others: say why on the
        // page WooCommerce shows next (its login form) and what to do.
        if ($sent && get_current_user_id() === $user_id && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST)) {
            self::woocommerce_notice(sprintf(__('Adresse e-mail modifiée. Ouvrez le lien envoyé à %s pour confirmer la nouvelle adresse, puis reconnectez-vous.', 'delicat-google-login'), self::masked_email($new)));
        }
    }

    private static function woocommerce_notice($message) {
        if (!function_exists('wc_add_notice') || !function_exists('WC') || !did_action('woocommerce_init')) return;
        $wc = WC();
        if (!$wc || empty($wc->session)) return;
        wc_add_notice($message, 'notice');
    }

    private static function masked_email($email) {
        $email = sanitize_email($email);
        if (!$email || strpos($email, '@') === false) return '';
        list($local, $domain) = explode('@', $email, 2);
        $first = function_exists('mb_substr') ? mb_substr($local, 0, 1) : substr($local, 0, 1);
        return $first . str_repeat('•', min(5, max(3, strlen($local) - 1))) . '@' . $domain;
    }

    /** Password reset endpoint bypasses profile_update; keep mobile sessions synchronized. */
    public static function password_reset($user, $new_pass = '') {
        if (!($user instanceof WP_User) || !self::is_customer_account($user->ID)) return;
        if (self::revoke_app_credentials_once($user->ID, 'password_reset') && class_exists('DIP_Audit')) {
            DIP_Audit::record('password_reset_mobile_sessions_revoked', 'warning', $user->ID);
        }
    }

    public static function login_guard($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return new WP_Error('dip_account_invalid', __('Compte invalide.', 'delicat-google-login'));
        // Never let customer-facing verification policy lock WordPress staff out
        // of wp-admin. Social/mobile role policy separately blocks privileged use.
        if (!self::is_customer_account($user_id)) return true;

        if (get_user_meta($user_id, 'dip_email_verified', true) === 'no') {
            return new WP_Error('dip_email_not_verified', __('Veuillez vérifier votre adresse e-mail avant de vous connecter.', 'delicat-google-login'));
        }
        $fingerprint = (string) get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        if ($fingerprint !== '') {
            $user = get_userdata($user_id);
            $current = $user ? hash('sha256', strtolower(sanitize_email($user->user_email))) : '';
            if (!$current || !hash_equals($fingerprint, $current)) {
                return new WP_Error('dip_email_not_verified', __('Veuillez vérifier votre nouvelle adresse e-mail avant de vous connecter.', 'delicat-google-login'));
            }
        }
        if (class_exists('DIP_Policy') && DIP_Policy::is_pending($user_id)) {
            return new WP_Error('account_pending_approval', __('Votre compte attend l’approbation de l’administrateur.', 'delicat-google-login'));
        }
        return true;
    }

    public static function privileged_mobile_blocked($user_id) {
        $user = get_userdata(absint($user_id));
        if (!$user) return true;
        if (!self::is_customer_account($user_id)) return true;
        $settings = class_exists('DIP_Plugin') ? wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults()) : [];
        $configured = preg_split('/[\s,]+/', (string) ($settings['blocked_social_roles'] ?? 'administrator,editor,shop_manager'));
        $blocked = array_values(array_filter(array_map('sanitize_key', (array) $configured)));
        return user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || (bool) array_intersect((array) $user->roles, $blocked);
    }
}
