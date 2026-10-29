<?php
defined('ABSPATH') || exit;

final class DIP_Identity {
    const META_SUB = '_dglp_google_sub';
    const META_AVATAR = '_dglp_google_avatar';
    const META_LINKED_AT = '_dglp_google_linked_at';
    const META_PASSWORD_MANAGED = '_dglp_password_managed';
    // A provider identity met at sign-up for an address nobody has proven yet.
    // It becomes a real link only when that mailbox is confirmed from the
    // browser that started the sign-up (promote_pending_provider_link()).
    const META_PENDING_LINK = '_dip_pending_provider_link';
    const PENDING_COOKIE_PREFIX = 'dip_ppl_';
    // Keyed hashes of provider subjects the user disconnected, so the Nextend
    // just-in-time continuity never silently re-links them.
    const META_DISCONNECTED = '_dip_disconnected_provider_subjects';

    private $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public static function meta_sub_key($provider) {
        $provider = sanitize_key((string) $provider);
        return $provider === 'google' ? self::META_SUB : '_dip_' . $provider . '_sub';
    }

    public static function meta_linked_key($provider) {
        $provider = sanitize_key((string) $provider);
        return $provider === 'google' ? self::META_LINKED_AT : '_dip_' . $provider . '_linked_at';
    }

    public function resolve(array &$profile, $link_user = 0) {
        // The provider id must be set by the provider that verified the
        // profile. A profile without one is never assumed to be Google (whose
        // Gmail/Workspace addresses are trusted for automatic e-mail linking).
        $provider = is_string($profile['provider'] ?? null) ? sanitize_key($profile['provider']) : '';
        $sub = is_scalar($profile['sub'] ?? null) ? sanitize_text_field((string) $profile['sub']) : '';
        $email = is_string($profile['email'] ?? null) ? sanitize_email($profile['email']) : '';
        if (!$provider || !$sub || strlen($sub) > 255 || !$email || !is_email($email)) return new WP_Error('identity_invalid', 'Identité du fournisseur incomplète.');

        // A link flow belongs to the account that started it. Refuse before
        // anything is written when that account no longer owns this session
        // (signed out, or another customer signed in on a shared browser).
        $link_user = absint($link_user);
        if ($link_user && (!is_user_logged_in() || get_current_user_id() !== $link_user)) {
            return new WP_Error('link_session_mismatch', 'La session utilisée pour relier ce fournisseur n’est plus valide.');
        }

        $lock = 'dip_identity_' . substr(hash('sha256', $provider . '|' . $sub), 0, 40);
        if (get_transient($lock)) return new WP_Error('identity_busy', 'Une connexion pour ce compte est déjà en cours.');
        set_transient($lock, 1, 30);
        try {
            return $this->resolve_locked($profile, $provider, $sub, $email, $link_user);
        } finally {
            delete_transient($lock);
        }
    }

    private function resolve_locked(array &$profile, $provider, $sub, $email, $link_user) {
        $google_authoritative = $provider === 'google' && class_exists('DIP_Google') && DIP_Google::email_is_authoritative($profile);
        $explicit_google_link = false;
        // 'oidc' when this request adopts or creates the account from the
        // provider's own proof of the mailbox (see after_verified_registration).
        $proof_source = '';
        $meta_sub = self::meta_sub_key($provider);
        $meta_linked = self::meta_linked_key($provider);
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
            // The Nextend row is history, not a new authorization: once the
            // user has disconnected this subject it stays disconnected (for
            // staff it would also re-issue TOTP-free continuity).
            if ($legacy_nextend_owner && self::subject_disconnected($legacy_nextend_owner, $provider, $sub)) {
                if (class_exists('DIP_Audit')) DIP_Audit::record('nextend_continuity_skipped_disconnected', 'notice', $legacy_nextend_owner, ['provider'=>$provider]);
                $legacy_nextend_owner = 0;
            }
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
            if ($owner && (int) $owner->ID !== $link_user) return new WP_Error('email_in_use', 'Cette adresse e-mail appartient déjà à un autre compte.');
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
                    if (self::email_unproven($user_id)) {
                        $adopted = $this->adopt_unproven_account($user_id, $provider, $sub);
                        if (is_wp_error($adopted)) return $adopted;
                    }
                    $proof_source = 'oidc';
                } elseif ($owner) {
                    $pending = $this->pending_signin($owner->ID, $provider, $sub, $email);
                    if (is_wp_error($pending)) return $pending;
                    return new WP_Error('email_exists', 'Un compte existe déjà avec cette adresse. Connectez-vous normalement puis reliez ce fournisseur.');
                } else {
                    if (!$this->registration_allowed()) return new WP_Error('registration_closed', 'La création de compte est désactivée.');
                    $user_id = $this->create_user($profile, $email, $provider);
                    if (is_wp_error($user_id)) return $user_id;
                    $profile['_new_user'] = true;

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
                        // Whoever proves the mailbox owns the account, and that may
                        // not be whoever controls this provider identity (Microsoft
                        // e-mail claims are tenant-controlled). The identity stays
                        // pending until the mailbox is confirmed from this browser.
                        $profile['_provider_link_pending'] = self::store_pending_link($user_id, $provider, $sub, $email);
                        return $user_id;
                    }
                    $proof_source = 'oidc';
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
            // Fill the names the account does not have yet. A name the customer
            // edited in My Account is never overwritten by the next sign-in.
            foreach (['given_name' => 'first_name', 'family_name' => 'last_name'] as $claim => $field) {
                $value = is_string($profile[$claim] ?? null) ? sanitize_text_field($profile[$claim]) : '';
                if ($value !== '' && (string) get_user_meta($user_id, $field, true) === '') update_user_meta($user_id, $field, $value);
            }
        }
        // An authenticated, explicit link re-authorizes a subject the user
        // had disconnected earlier.
        if ($link_user) self::forget_disconnected_subject($user_id, $provider, $sub);
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
                // An account adopted or created from Google's proof of the
                // mailbox drops whatever credentials predate that proof. An
                // already linked subject keeps today's behaviour: its owner
                // linked it while signed in or through a proven mailbox.
                if ($proof_source !== '') DIP_Account_Sync::after_verified_registration($user_id, $email, $proof_source);
                else DIP_Account_Sync::after_verified_registration($user_id, $email);
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
        // The provider has just proven this identity for this account (used
        // by the storefront hand-off to bind its flow to a real provider step).
        do_action('dip_identity_resolved', $user_id, $profile, $link_user);
        return $user_id;
    }

    /**
     * Current address never proven: still waiting for its verification link,
     * changed since the last proof (login_guard refuses both), or registered
     * while verification was switched off ('unproven': usable for sign-in,
     * but no proof marker was ever recorded). Accounts older than these
     * markers (no dip_email_verified at all) keep today's behaviour.
     */
    private static function email_unproven($user_id) {
        $state = (string) get_user_meta($user_id, 'dip_email_verified', true);
        if ($state === 'no') return true;
        $fingerprint = (string) get_user_meta($user_id, '_dip_verified_email_fingerprint', true);
        if ($fingerprint === '') return $state === 'yes' && (string) get_user_meta($user_id, '_dip_verified_email_proof_v2', true) === '';
        $user = get_userdata($user_id);
        $current = $user ? hash('sha256', strtolower(sanitize_email($user->user_email))) : '';
        return $current === '' || !hash_equals($fingerprint, $current);
    }

    /** Provider id => subject meta key, for the built-in and registered providers. */
    private static function provider_sub_keys() {
        $keys = ['google' => self::META_SUB, 'microsoft' => self::meta_sub_key('microsoft')];
        if (class_exists('DIP_Connected_Accounts')) {
            foreach ((array) DIP_Connected_Accounts::providers() as $id => $item) {
                if (is_array($item) && !empty($item['meta_sub']) && is_string($item['meta_sub'])) $keys[sanitize_key($id)] = $item['meta_sub'];
            }
        }
        return $keys;
    }

    /**
     * Google has proven the mailbox of an existing account whose current
     * address was never proven. after_verified_registration(…, 'oidc')
     * replaces the password and sessions created before that proof.
     * Credentials that would survive it (passkeys, TOTP, another provider
     * identity) belong to whoever set the account up, so the merge is refused.
     */
    private function adopt_unproven_account($user_id, $provider, $sub) {
        $foreign = [];
        if (class_exists('DIP_Passkeys') && method_exists('DIP_Passkeys', 'has_passkeys') && DIP_Passkeys::has_passkeys($user_id)) $foreign[] = 'passkey';
        if (class_exists('DIP_Two_Factor') && DIP_Two_Factor::is_enabled($user_id)) $foreign[] = 'totp';
        foreach (self::provider_sub_keys() as $id => $key) {
            $current = (string) get_user_meta($user_id, $key, true);
            if ($current === '' || ($id === $provider && hash_equals($current, $sub))) continue;
            $foreign[] = $id;
        }
        if ($foreign) {
            if (class_exists('DIP_Audit')) DIP_Audit::record('unverified_account_merge_refused', 'warning', $user_id, ['provider'=>$provider, 'credentials'=>implode(',', array_unique($foreign))]);
            return new WP_Error('email_exists', 'Un compte dont l’adresse n’est pas encore confirmée utilise déjà cette adresse. Confirmez-la avec le lien reçu par e-mail ou connectez-vous à ce compte, puis reliez ce fournisseur.');
        }
        // A stale proof (or an 'unproven' address) is recorded as unverified
        // so the credential reset of after_verified_registration() applies.
        update_user_meta($user_id, 'dip_email_verified', 'no');
        self::clear_pending_provider_link($user_id);
        delete_user_meta($user_id, '_dip_email_verify_hash');
        delete_user_meta($user_id, '_dip_email_verify_email_hash');
        delete_user_meta($user_id, '_dip_email_verify_expires');
        if (class_exists('DIP_Audit')) DIP_Audit::record('unverified_account_adopted_by_provider', 'warning', $user_id, ['provider'=>$provider]);
        return true;
    }

    private static function random_secret() {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function hash_email($email) {
        return hash_hmac('sha256', strtolower(sanitize_email((string) $email)), wp_salt('auth'));
    }

    private static function hash_browser($secret) {
        return hash_hmac('sha256', 'pending-link|' . (string) $secret, wp_salt('secure_auth'));
    }

    private static function pending_cookie_name($user_id) {
        return self::PENDING_COOKIE_PREFIX . substr(hash_hmac('sha256', 'pending-link|' . absint($user_id), wp_salt('nonce')), 0, 16);
    }

    private static function set_pending_cookie($user_id, $value, $expires) {
        if (headers_sent()) return false;
        $name = self::pending_cookie_name($user_id);
        $ok = setcookie($name, (string) $value, [
            'expires' => (int) $expires,
            'path' => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($ok) {
            if ((string) $value === '') unset($_COOKIE[$name]);
            else $_COOKIE[$name] = (string) $value;
        }
        return $ok;
    }

    /** The pending-link secret this browser carries ('' when none). */
    private static function pending_cookie_secret($user_id) {
        $cookie = $_COOKIE[self::pending_cookie_name($user_id)] ?? '';
        $cookie = is_string($cookie) ? sanitize_text_field(wp_unslash($cookie)) : '';
        return preg_match('/^[a-f0-9]{64}$/D', $cookie) ? $cookie : '';
    }

    private static function browser_known(array $pending, $secret) {
        if ($secret === '') return false;
        $hash = self::hash_browser($secret);
        foreach ((array) ($pending['browsers'] ?? []) as $known) {
            if (is_string($known) && hash_equals($known, $hash)) return true;
        }
        return false;
    }

    /** Remember this browser for the pending link (at most three browsers). */
    private static function bind_pending_browser($user_id, array $pending, $expires) {
        // A browser that is already bound keeps its secret and its slot, so
        // repeated sign-ins never push the other bound browsers out.
        $current = self::pending_cookie_secret($user_id);
        if (self::browser_known($pending, $current)) {
            self::set_pending_cookie($user_id, $current, $expires);
            return $pending;
        }
        $secret = self::random_secret();
        if ($secret === '' || !self::set_pending_cookie($user_id, $secret, $expires)) return null;
        $browsers = array_values(array_filter((array) ($pending['browsers'] ?? []), 'is_string'));
        $browsers[] = self::hash_browser($secret);
        $pending['browsers'] = array_slice($browsers, -3);
        return $pending;
    }

    /**
     * Keep the provider identity of a sign-up whose address is not proven yet.
     * Only a browser can carry the binding; app (REST) sign-ups link the
     * provider later from the signed-in account instead.
     */
    private static function store_pending_link($user_id, $provider, $sub, $email) {
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        $expires = absint(get_user_meta($user_id, '_dip_email_verify_expires', true)) ?: time() + DAY_IN_SECONDS;
        $pending = self::bind_pending_browser($user_id, [
            'provider' => sanitize_key($provider),
            'sub' => (string) $sub,
            'email' => self::hash_email($email),
            'browsers' => [],
            'created' => time(),
            'expires' => $expires,
        ], $expires);
        if (!$pending) return false;
        update_user_meta($user_id, self::META_PENDING_LINK, $pending);
        if (class_exists('DIP_Audit')) DIP_Audit::record('provider_link_pending_email_proof', 'notice', $user_id, ['provider'=>sanitize_key($provider)]);
        return true;
    }

    /**
     * The same provider identity signs in again before the mailbox is
     * confirmed: this browser may confirm it too, and an expired verification
     * link is sent again. Returns null when no such pending link exists.
     */
    private function pending_signin($user_id, $provider, $sub, $email) {
        $user_id = absint($user_id);
        $pending = get_user_meta($user_id, self::META_PENDING_LINK, true);
        if (!is_array($pending)
            || sanitize_key((string) ($pending['provider'] ?? '')) !== $provider
            || !hash_equals((string) ($pending['sub'] ?? ''), (string) $sub)
            || time() > (int) ($pending['expires'] ?? 0)
            || get_user_meta($user_id, 'dip_email_verified', true) !== 'no'
            || !hash_equals((string) ($pending['email'] ?? ''), self::hash_email($email))
        ) return null;
        $verify_expires = absint(get_user_meta($user_id, '_dip_email_verify_expires', true));
        if ($verify_expires <= time() + MINUTE_IN_SECONDS && class_exists('DIP_Passwordless_Registration')) {
            $user = get_userdata($user_id);
            if ($user && DIP_Passwordless_Registration::send_email_verification($user_id, $user->user_email)) {
                $verify_expires = absint(get_user_meta($user_id, '_dip_email_verify_expires', true));
            }
        }
        $expires = max((int) $pending['expires'], $verify_expires);
        $pending['expires'] = $expires;
        // An app (REST) sign-in has no browser to bind; it must not take one
        // of the three slots of the browsers that can confirm the address.
        $bound = defined('REST_REQUEST') && REST_REQUEST ? null : self::bind_pending_browser($user_id, $pending, $expires);
        update_user_meta($user_id, self::META_PENDING_LINK, $bound ?: $pending);
        return new WP_Error('dip_email_not_verified', 'Confirmez d’abord votre adresse e-mail avec le lien que nous vous avons envoyé.');
    }

    /** Why a pending link cannot be promoted ('' when it can). */
    private static function pending_link_rejection($user_id, array $pending) {
        if (time() > (int) ($pending['expires'] ?? 0)) return 'expired';
        $user = get_userdata($user_id);
        if (!$user) return 'user_missing';
        if (class_exists('DIP_Account_Sync') && DIP_Account_Sync::privileged_mobile_blocked($user_id)) return 'privileged';
        if (empty($pending['email']) || !hash_equals((string) $pending['email'], self::hash_email($user->user_email))) return 'email_changed';
        if (!self::browser_known($pending, self::pending_cookie_secret($user_id))) return 'other_browser';
        $provider = sanitize_key((string) ($pending['provider'] ?? ''));
        $sub = (string) ($pending['sub'] ?? '');
        if (!$provider || $sub === '' || strlen($sub) > 255) return 'invalid';
        $meta_sub = self::meta_sub_key($provider);
        $current = (string) get_user_meta($user_id, $meta_sub, true);
        if ($current !== '' && !hash_equals($current, $sub)) return 'already_linked';
        foreach ((array) get_users(['meta_key'=>$meta_sub, 'meta_value'=>$sub, 'number'=>2, 'fields'=>'ID']) as $owner) {
            if ((int) $owner !== (int) $user_id) return 'linked_elsewhere';
        }
        return '';
    }

    /**
     * Contract C3: called once the account's own verification link has been
     * confirmed. Binds the provider identity kept at sign-up only when the
     * confirmation comes from the browser that signed up, before expiry;
     * otherwise drops it. Returns true when the link was created.
     */
    public static function promote_pending_provider_link($user_id) {
        $user_id = absint($user_id);
        $pending = $user_id ? get_user_meta($user_id, self::META_PENDING_LINK, true) : '';
        if (!is_array($pending) || empty($pending['provider']) || empty($pending['sub'])) return false;
        $reason = self::pending_link_rejection($user_id, $pending);
        self::clear_pending_provider_link($user_id);
        $provider = sanitize_key((string) $pending['provider']);
        if ($reason !== '') {
            if (class_exists('DIP_Audit')) DIP_Audit::record('pending_provider_link_dropped', 'warning', $user_id, ['provider'=>$provider, 'reason'=>$reason]);
            return false;
        }
        $sub = (string) $pending['sub'];
        update_user_meta($user_id, self::meta_sub_key($provider), $sub);
        update_user_meta($user_id, self::meta_linked_key($provider), time());
        self::forget_disconnected_subject($user_id, $provider, $sub);
        if (class_exists('DIP_Audit')) DIP_Audit::record('pending_provider_link_promoted', 'notice', $user_id, ['provider'=>$provider]);
        $user = get_userdata($user_id);
        $profile = ['provider'=>$provider, 'sub'=>$sub, 'email'=>$user ? $user->user_email : '', '_pending_link_promoted'=>true];
        do_action('dip_account_linked', $user_id, $profile);
        return true;
    }

    /**
     * One-time repair (admin_init): earlier versions let a Microsoft sign-up bind
     * its subject immediately, so accounts still waiting for their
     * verification link may carry an identity that never proved the mailbox.
     * Those links are dropped; the owner links Microsoft again once signed in.
     */
    public static function repair_unproven_signup_links() {
        if (get_option('dip_identity_unproven_links_v1')) return;
        update_option('dip_identity_unproven_links_v1', time(), true);
        global $wpdb;
        $meta_sub = self::meta_sub_key('microsoft');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT s.user_id FROM {$wpdb->usermeta} s INNER JOIN {$wpdb->usermeta} v ON v.user_id = s.user_id AND v.meta_key = 'dip_email_verified' AND v.meta_value = 'no' WHERE s.meta_key = %s AND s.meta_value <> '' LIMIT 500",
            $meta_sub
        ));
        foreach (is_array($ids) ? $ids : [] as $user_id) {
            $user_id = absint($user_id);
            delete_user_meta($user_id, $meta_sub);
            delete_user_meta($user_id, self::meta_linked_key('microsoft'));
            if (class_exists('DIP_Audit')) DIP_Audit::record('unproven_provider_link_removed', 'warning', $user_id, ['provider'=>'microsoft']);
        }
    }

    /** Contract C3: forget a pending sign-up link (password set or reset, merge). */
    public static function clear_pending_provider_link($user_id) {
        $user_id = absint($user_id);
        if (!$user_id) return false;
        $had = is_array(get_user_meta($user_id, self::META_PENDING_LINK, true));
        if ($had) delete_user_meta($user_id, self::META_PENDING_LINK);
        if (isset($_COOKIE[self::pending_cookie_name($user_id)])) self::set_pending_cookie($user_id, '', time() - HOUR_IN_SECONDS);
        return $had;
    }

    private static function subject_hash($provider, $sub) {
        return hash_hmac('sha256', sanitize_key((string) $provider) . '|' . (string) $sub, wp_salt('auth'));
    }

    /**
     * Contract C3: record that the user disconnected this provider subject
     * (call before the link meta is deleted). The Nextend just-in-time path
     * then never re-links it; an explicit authenticated link clears it.
     */
    public static function remember_disconnected_subject($user_id, $provider, $sub) {
        $user_id = absint($user_id);
        $provider = sanitize_key((string) $provider);
        $sub = is_scalar($sub) ? (string) $sub : '';
        if (!$user_id || !$provider || $sub === '' || strlen($sub) > 255) return false;
        $all = get_user_meta($user_id, self::META_DISCONNECTED, true);
        $all = is_array($all) ? $all : [];
        $all[self::subject_hash($provider, $sub)] = time();
        arsort($all, SORT_NUMERIC);
        update_user_meta($user_id, self::META_DISCONNECTED, array_slice($all, 0, 20, true));
        return true;
    }

    private static function subject_disconnected($user_id, $provider, $sub) {
        $all = get_user_meta(absint($user_id), self::META_DISCONNECTED, true);
        return is_array($all) && isset($all[self::subject_hash($provider, $sub)]);
    }

    private static function forget_disconnected_subject($user_id, $provider, $sub) {
        $all = get_user_meta(absint($user_id), self::META_DISCONNECTED, true);
        $key = self::subject_hash($provider, $sub);
        if (!is_array($all) || !isset($all[$key])) return;
        unset($all[$key]);
        if ($all) update_user_meta(absint($user_id), self::META_DISCONNECTED, $all);
        else delete_user_meta(absint($user_id), self::META_DISCONNECTED);
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

    private function create_user(array $profile, $email, $provider) {
        $base = sanitize_user(strstr($email, '@', true), true) ?: 'google_user';
        $login = $base;
        for ($i = 1; username_exists($login); $i++) $login = $base . '_' . $i;
        $provider = sanitize_key($provider);
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
