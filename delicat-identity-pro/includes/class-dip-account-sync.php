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

    /**
     * Run synchronization that is only safe once ownership of the exact current
     * email address is known (OIDC proof, OTP/magic, or verification link).
     */
    public static function after_verified_registration($user_id, $verified_email = '') {
        $user_id = absint($user_id);
        $user = $user_id ? get_userdata($user_id) : false;
        if (!$user) return false;

        $local_email = sanitize_email($user->user_email);
        $verified_email = sanitize_email($verified_email ?: $local_email);
        if (!$local_email || !$verified_email || !hash_equals(strtolower($local_email), strtolower($verified_email))) {
            if (self::is_customer_account($user_id)) {
                update_user_meta($user_id, 'dip_email_verified', 'no');
                delete_user_meta($user_id, '_dip_verified_email_fingerprint');
            }
            if (class_exists('DIP_Audit')) DIP_Audit::record('verified_email_mismatch', 'warning', $user_id, [
                'local_email_hash'=>hash('sha256', strtolower($local_email)),
                'proof_email_hash'=>hash('sha256', strtolower($verified_email)),
            ]);
            return false;
        }

        if (!self::is_customer_account($user_id)) return true;

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
            self::after_verified_registration($user_id, $user->user_email);
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
    public static function filter_auth_cookies($send, $expire, $expiration, $user_id, $scheme, $token) {
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
            if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user_id, 'password_changed');
            if (class_exists('DIP_Audit')) DIP_Audit::record('account_password_changed_mobile_revoked', 'warning', $user_id);
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
            self::after_verified_registration($user_id, $new);
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
            'old_email_hash'=>hash('sha256', $old),
            'new_email_hash'=>hash('sha256', $new),
            'verification_sent'=>$sent ? 1 : 0,
            'sessions_revoked'=>1,
        ]);
    }

    /** Password reset endpoint bypasses profile_update; keep mobile sessions synchronized. */
    public static function password_reset($user, $new_pass = '') {
        if (!($user instanceof WP_User) || !self::is_customer_account($user->ID)) return;
        if (class_exists('DIP_Mobile_API')) DIP_Mobile_API::revoke_all_for_user($user->ID, 'password_reset');
        if (class_exists('DIP_Audit')) DIP_Audit::record('password_reset_mobile_sessions_revoked', 'warning', $user->ID);
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
