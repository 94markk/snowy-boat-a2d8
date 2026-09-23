<?php
defined('ABSPATH') || exit;

final class DIP_Identity {
    const META_SUB = '_dglp_google_sub';
    const META_AVATAR = '_dglp_google_avatar';
    const META_LINKED_AT = '_dglp_google_linked_at';
    const META_PASSWORD_MANAGED = '_dglp_password_managed';

    private $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function resolve(array &$profile, $link_user = 0) {
        $provider = sanitize_key($profile['provider'] ?? 'google');
        $sub = sanitize_text_field($profile['sub'] ?? '');
        $email = sanitize_email($profile['email'] ?? '');
        if (!$provider || !$sub || strlen($sub) > 255 || !$email || !is_email($email)) return new WP_Error('identity_invalid', 'Identité du fournisseur incomplète.');

        $lock = 'dip_identity_' . substr(hash('sha256', $provider . '|' . $sub), 0, 40);
        if (get_transient($lock)) return new WP_Error('identity_busy', 'Une connexion pour ce compte est déjà en cours.');
        set_transient($lock, 1, 30);
        try {
            return $this->resolve_locked($profile, $provider, $sub, $email, absint($link_user));
        } finally {
            delete_transient($lock);
        }
    }

    private function resolve_locked(array &$profile, $provider, $sub, $email, $link_user) {
        $google_authoritative = $provider === 'google' && class_exists('DIP_Google') && DIP_Google::email_is_authoritative($profile);
        $explicit_google_link = false;
        $meta_sub = $provider === 'google' ? self::META_SUB : '_dip_' . $provider . '_sub';
        $meta_linked = $provider === 'google' ? self::META_LINKED_AT : '_dip_' . $provider . '_linked_at';
        $linked = get_users([
            'meta_key' => $meta_sub,
            'meta_value' => $sub,
            'number' => 2,
            'fields' => 'ID',
        ]);
        if (count($linked) > 1) return new WP_Error('duplicate_provider_identity', 'Cette identité est associée à plusieurs comptes.');

        // During the Nextend -> Identity migration, an existing Google account
        // may already have an immutable provider link in Nextend while Identity
        // has not copied _dglp_google_sub yet. Resolve that exact provider link
        // before considering any e-mail based ownership. This preserves existing
        // customers/admins without weakening privileged e-mail auto-link rules.
        $legacy_nextend_owner = 0;
        if (!$linked && $provider === 'google' && class_exists('DIP_Migration')) {
            $legacy_owner = DIP_Migration::nextend_google_owner_by_subject($sub);
            if (is_wp_error($legacy_owner)) return $legacy_owner;
            $legacy_nextend_owner = absint($legacy_owner);
        }

        if ($linked) {
            $user_id = (int) $linked[0];
            // A link flow is scoped to the currently authenticated local user.
            // Never let an already-linked external identity select a different
            // WordPress account and then update its provider/profile metadata.
            if ($link_user && $user_id !== $link_user) {
                return new WP_Error('provider_already_linked_elsewhere', 'Cette identité de fournisseur est déjà liée à un autre compte.');
            }
            if ($link_user && $this->is_privileged($user_id)) {
                if (!class_exists('DIP_Privileged_Social') || !DIP_Privileged_Social::can_link_current($user_id, $provider)) {
                    return new WP_Error('privileged_link_requires_secure_mode', 'Confirmez votre identité et activez TOTP 2FA avant de relier Google à ce compte Administrateur.');
                }
                if ($provider === 'google') $explicit_google_link = true;
            } elseif (!$link_user && $this->is_privileged($user_id)) {
                // Existing privileged Nextend Google links may continue only
                // through the signed continuity marker created from an exact
                // immutable provider-ID match. A plain e-mail match is never enough.
                if (!class_exists('DIP_Privileged_Social')) return new WP_Error('privileged_social_login_blocked', 'Connexion sociale privilégiée non autorisée.');
                if (!DIP_Privileged_Social::is_admin($user_id)) {
                    if ($provider !== 'google' || !DIP_Privileged_Social::legacy_continuity_approved($user_id, $sub)) {
                        return new WP_Error('privileged_social_login_blocked', 'Ce rôle privilégié ne peut pas utiliser une connexion sociale.');
                    }
                } else {
                    $privileged_policy = DIP_Privileged_Social::validate_admin_google_login($user_id, $provider, $profile);
                    if (is_wp_error($privileged_policy)) return $privileged_policy;
                }
            }
            if (class_exists('DIP_Policy') && DIP_Policy::is_pending($user_id)) return new WP_Error('account_pending_approval', 'Votre compte attend l’approbation de l’administrateur.');
        } elseif ($link_user) {
            if ($this->is_privileged($link_user)) {
                if (!class_exists('DIP_Privileged_Social') || !DIP_Privileged_Social::can_link_current($link_user, $provider)) {
                    return new WP_Error('privileged_link_requires_secure_mode', 'Confirmez votre identité et activez TOTP 2FA avant de relier Google à ce compte Administrateur.');
                }
            }
            if (get_user_meta($link_user, $meta_sub, true)) return new WP_Error('already_linked', 'Ce compte est déjà relié à ce fournisseur.');
            $owner = get_user_by('email', $email);
            if ($owner && (int) $owner->ID !== $link_user) return new WP_Error('email_in_use', 'Cette adresse Google appartient déjà à un autre compte.');
            $user_id = $link_user;
            if ($provider === 'google') $explicit_google_link = true;
        } else {
            $owner = get_user_by('email', $email);

            // Just-in-time Nextend continuity. This path is stronger than e-mail
            // auto-linking because the verified Google `sub` must already map to
            // the exact same WordPress user in Nextend. For privileged accounts
            // we additionally require an authoritative Google mailbox matching
            // the local account before issuing a signed continuity marker.
            if ($legacy_nextend_owner) {
                $legacy_user = get_userdata($legacy_nextend_owner);
                if (!$legacy_user) return new WP_Error('legacy_google_user_missing', 'Le compte Google historique ne correspond plus à un utilisateur valide.');
                $legacy_email = strtolower(sanitize_email($legacy_user->user_email));
                if (!$google_authoritative || $legacy_email === '' || !hash_equals($legacy_email, strtolower($email))) {
                    return new WP_Error('legacy_google_email_mismatch', 'Le compte Google historique doit correspondre exactement à l’adresse du compte Delicat.');
                }
                if ($owner && (int) $owner->ID !== $legacy_nextend_owner) {
                    return new WP_Error('legacy_google_owner_conflict', 'Cette adresse et cette identité Google appartiennent à deux comptes différents.');
                }
                $current = (string) get_user_meta($legacy_nextend_owner, $meta_sub, true);
                if ($current !== '' && !hash_equals($current, $sub)) {
                    return new WP_Error('conflicting_link', 'Ce compte possède déjà une autre identité Google.');
                }
                $user_id = $legacy_nextend_owner;
                $explicit_google_link = true;
                $profile['_legacy_nextend_continuity'] = true;
                if ($this->is_privileged($user_id)) {
                    if (!class_exists('DIP_Privileged_Social') || !DIP_Privileged_Social::grant_legacy_continuity($user_id, $sub, $profile)) {
                        return new WP_Error('legacy_privileged_continuity_failed', 'La liaison Google historique n’a pas pu être validée de manière sécurisée.');
                    }
                }
            } else {
                // Automatic ownership by email is only safe when the provider is
                // authoritative for the current mailbox. Google documents Gmail and
                // Workspace (verified + hd) as authoritative; third-party Google
                // account emails require local proof. Microsoft email claims are
                // mutable and are never used for automatic ownership.
                $allow_email_link = $provider === 'google' && $google_authoritative
                    ? (($this->settings['link_existing_email'] ?? 'yes') === 'yes')
                    : false;
                if ($owner && $allow_email_link) {
                    if ($this->is_privileged($owner->ID)) return new WP_Error('privileged_auto_link_blocked', 'Connectez-vous normalement pour ce compte sensible.');
                    $user_id = (int) $owner->ID;
                } elseif ($owner) {
                return new WP_Error('email_exists', 'Un compte existe déjà avec cette adresse. Connectez-vous normalement puis reliez ce fournisseur.');
                } else {
                    if (!$this->registration_allowed()) return new WP_Error('registration_closed', 'La création de compte est désactivée.');
                    $user_id = $this->create_user($profile, $email);
                    if (is_wp_error($user_id)) return $user_id;

                    // Microsoft does not provide a stable mailbox-ownership claim.
                // Google is also non-authoritative for a verified third-party
                // mailbox without Gmail/Workspace hd. New accounts from either
                // case must prove the exact local email before login/order sync.
                $needs_local_email_proof = $provider === 'microsoft' || ($provider === 'google' && !$google_authoritative);
                if ($needs_local_email_proof) {
                    update_user_meta($user_id, 'dip_email_verified', 'no');
                    delete_user_meta($user_id, '_dip_verified_email_fingerprint');
                    delete_user_meta($user_id, '_dip_verified_email_proof_v2');
                    $sent = class_exists('DIP_Passwordless_Registration')
                        && DIP_Passwordless_Registration::send_email_verification($user_id, $email);
                    if (!$sent) {
                        require_once ABSPATH . 'wp-admin/includes/user.php';
                        wp_delete_user($user_id);
                        return new WP_Error('provider_email_verification_unavailable', 'Impossible d’envoyer la vérification de cette adresse e-mail. Réessayez plus tard.');
                    }
                    $profile['_local_email_verification_required'] = true;
                }
                    $profile['_new_user'] = true;
                }
            }
        }

        $previous = (string) get_user_meta($user_id, $meta_sub, true);
        update_user_meta($user_id, $meta_sub, $sub);
        update_user_meta($user_id, $meta_linked, time());
        if ($provider === 'google' && ($this->settings['use_google_avatar'] ?? 'yes') === 'yes' && !empty($profile['picture']) && wp_http_validate_url($profile['picture'])) {
            update_user_meta($user_id, self::META_AVATAR, esc_url_raw($profile['picture']));
        }
        if (($this->settings['sync_profile_name'] ?? 'yes') === 'yes') {
            if (!empty($profile['given_name'])) update_user_meta($user_id, 'first_name', sanitize_text_field($profile['given_name']));
            if (!empty($profile['family_name'])) update_user_meta($user_id, 'last_name', sanitize_text_field($profile['family_name']));
        }
        if ($provider === 'google' && $explicit_google_link) {
            update_user_meta($user_id, '_dip_google_explicit_link_v2', hash_hmac('sha256', $sub, wp_salt('auth')));
            if ($link_user && $this->is_privileged($user_id) && class_exists('DIP_Privileged_Social')) {
                if (!DIP_Privileged_Social::approve_link($user_id, $sub, 'explicit_oauth_link')) {
                    return new WP_Error('admin_google_link_authorization_failed', 'Impossible d’autoriser cette identité Google pour le compte Administrateur.');
                }
            }
        }

        // Gmail/Workspace can prove the exact local mailbox when addresses
        // match. A non-authoritative Google email is safe only after either an
        // explicit authenticated link or a hardened local email proof. This also
        // remediates legacy auto-links created from email_verified alone.
        if ($provider === 'google' && class_exists('DIP_Account_Sync')) {
            $local = get_userdata($user_id);
            $local_email = $local ? strtolower(sanitize_email($local->user_email)) : '';
            $proof = (string) get_user_meta($user_id, '_dip_verified_email_proof_v2', true);
            $expected_proof = $local_email ? hash('sha256', $local_email) : '';
            $explicit_proof = (string) get_user_meta($user_id, '_dip_google_explicit_link_v2', true);
            $explicit_ok = $explicit_proof !== '' && hash_equals($explicit_proof, hash_hmac('sha256', $sub, wp_salt('auth')));
            $local_proof_ok = $proof !== '' && $expected_proof !== '' && hash_equals($proof, $expected_proof);

            if ($google_authoritative && $local_email !== '' && hash_equals($local_email, strtolower($email))) {
                DIP_Account_Sync::after_verified_registration($user_id, $email);
            } elseif (!$google_authoritative && !$explicit_ok && !$local_proof_ok) {
                update_user_meta($user_id, 'dip_email_verified', 'no');
                delete_user_meta($user_id, '_dip_verified_email_fingerprint');
                $expires = absint(get_user_meta($user_id, '_dip_email_verify_expires', true));
                $has_pending = $expires > time() + MINUTE_IN_SECONDS
                    && get_user_meta($user_id, '_dip_email_verify_hash', true)
                    && get_user_meta($user_id, '_dip_email_verify_email_hash', true);
                if (!$has_pending && class_exists('DIP_Passwordless_Registration')) {
                    DIP_Passwordless_Registration::send_email_verification($user_id, $local_email ?: $email);
                }
                $profile['_local_email_verification_required'] = true;
                if (class_exists('DIP_Audit')) DIP_Audit::record('google_non_authoritative_email_requires_local_proof', 'warning', $user_id);
            } elseif ($google_authoritative && $local_email !== '' && !hash_equals($local_email, strtolower($email))) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('google_provider_email_changed_not_synced', 'notice', $user_id, [
                    'provider_email_hash'=>hash('sha256', strtolower($email)),
                    'local_email_hash'=>hash('sha256', $local_email),
                ]);
            }
        }
        if ($previous !== $sub) do_action('dip_account_linked', $user_id, $profile);
        return $user_id;
    }

    /**
     * New social/mobile identities follow the same registration switch as the
     * native modal and WooCommerce My Account. Delicat's own setting may only
     * further restrict registration; it does not silently reopen WordPress/Woo.
     */
    private function registration_allowed() {
        $delicat = ($this->settings['allow_registration'] ?? 'yes') === 'yes';
        $platform = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::storefront_registration_enabled()
            : ((bool) get_option('users_can_register')
                || 'yes' === get_option('woocommerce_enable_myaccount_registration')
                || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout'));
        return (bool) apply_filters('dip_identity_registration_allowed', $delicat && $platform, $this->settings);
    }

    private function create_user(array $profile, $email) {
        $base = sanitize_user(strstr($email, '@', true), true) ?: 'google_user';
        $login = $base;
        for ($i = 1; username_exists($login); $i++) $login = $base . '_' . $i;
        $provider = sanitize_key($profile['provider'] ?? 'google');
        $role = sanitize_key($this->settings[$provider . '_default_role'] ?? ($this->settings['default_role'] ?? 'customer'));
        if (!get_role($role) || in_array($role, ['administrator', 'editor', 'shop_manager'], true)) {
            $role = get_role('customer') ? 'customer' : 'subscriber';
        }
        do_action('dip_before_user_create', $email, $profile);
        $generated_password = wp_generate_password(48, true, true);
        $account_args = [
            'display_name' => sanitize_text_field($profile['name'] ?? $login),
            'first_name' => sanitize_text_field($profile['given_name'] ?? ''),
            'last_name' => sanitize_text_field($profile['family_name'] ?? ''),
        ];
        $user_id = class_exists('DIP_Account_Sync')
            ? DIP_Account_Sync::create_customer($email, $login, $generated_password, $account_args, $role)
            : wp_insert_user(array_merge($account_args, [
                'user_login'=>$login,'user_pass'=>$generated_password,'user_email'=>$email,'role'=>$role,
            ]));
        if (!is_wp_error($user_id)) {
            update_user_meta($user_id, self::META_PASSWORD_MANAGED, 'yes');
            // Verification/Woo synchronization is centralized after the provider
            // identity has been linked, so it runs exactly once per resolved flow.
            if (($this->settings['require_new_user_approval'] ?? 'no') === 'yes' && class_exists('DIP_Policy')) DIP_Policy::mark_pending($user_id, $profile);
            do_action('dip_user_created', $user_id, $profile);
        }
        return $user_id;
    }

    private function is_privileged($user_id) {
        $user = get_userdata($user_id);
        if (!$user) return false;
        if (class_exists('DIP_Account_Sync') && !DIP_Account_Sync::is_customer_account($user_id)) return true;
        $configured = preg_split('/[\s,]+/', (string) ($this->settings['blocked_social_roles'] ?? 'administrator,editor,shop_manager'));
        $blocked = array_values(array_filter(array_map('sanitize_key', (array) $configured)));
        return user_can($user, 'manage_options')
            || user_can($user, 'manage_woocommerce')
            || (bool) array_intersect((array) $user->roles, $blocked);
    }
}
