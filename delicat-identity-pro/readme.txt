=== Delicat Identity Pro ===
Contributors: delicatstore
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 6.9.19
License: GPLv2 or later

Secure Google and Microsoft identity platform for WordPress and WooCommerce.

== 6.9.17 Storefront Session Recovery ==
* Preserves the safe storefront return URL and modal tracker before validating a Google callback, so expired or Android custom-tab failures return to Delicat instead of exposing wp-login.php.
* Consumes the one-time OAuth state only after browser binding succeeds; a callback opened before an Android browser exposes its binding cookie no longer destroys the still-valid flow.
* Marks successful password, registration, Google and Passkey transitions for an immediate live session refresh and clears cached Builder documents before navigation.
* Marks authentication transition pages no-store for signed-in as well as signed-out responses.
* Keeps OAuth state, PKCE, nonce, Google signature checks, browser HMAC binding, lockouts, account policy and 2FA unchanged.

== 6.9.16 Google Maintenance Recovery ==
* Fixes the live storefront failure where every Google OAuth start redirected to `dip_error=maintenance` and the modal omitted both the divider and Google button.
* Adds a versioned update migration that clears a legacy, untracked social-maintenance value once; explicit 6.9.16+ emergency-maintenance choices remain preserved.
* Adds a one-click administrator “Réactiver Google” repair action without touching Client IDs, encrypted secrets, users, sessions, orders or wallet data.
* Keeps Builder visual maintenance independent from Identity OAuth maintenance and documents the distinction in the settings screen.
* Purges LiteSpeed page HTML after recovery; Delicat Builder’s existing Cloudflare bridge receives the same purge action when configured.
* Returns cached modal Google links to their originating page with a safe local error instead of unexpectedly redirecting customers to wp-login.php.

== 6.9.15 Google Button Diagnostics ==
* Fixes the silent disappearance of the Google button from the Delicat login modal, wp-login.php, My Account and checkout: every gate now reports a precise, non-secret reason instead of rendering nothing.
* Detects the previously invisible failure where an encrypted Google client secret is still stored but WordPress AUTH_SALT / SECURE_AUTH_SALT changed, so the secret can no longer be decrypted; this state now reads "secret illisible" instead of "non configuré".
* Adds an administrator-only notice on the Dashboard, Plugins and Delicat Identity screens naming the exact cause (maintenance, désactivé, HTTPS, Client ID vide, secret vide, secret illisible).
* Adds an administrator-only diagnostic line inside the login modal itself so the cause is visible on the storefront without opening the admin.
* Detects Cloudflare/reverse-proxy HTTPS termination and tells the administrator to set HTTPS in wp-config.php; the OAuth gate still requires a genuinely encrypted request.
* Stops wp_kses_post() from stripping the inline provider SVG icon from the modal button markup.
* No change to OAuth state, PKCE, nonce, Google signature verification, privileged-account blocking, or secret storage.

== 6.9.14 Mobile Google Identity Authority ==
* Identity Pro is the only WordPress component that exchanges a Google ID token for a Delicat mobile session.
* Publishes public mobile client identifiers through the capabilities endpoint while the Google client secret never leaves the server.
* Issues device-bound access/refresh sessions only after verifying the Google signature and claims.

== 6.9.13 Safari Google Browser Recovery ==
* Fixes the storefront Google message "Cette connexion Google a expiré ou ne correspond pas à ce navigateur" on iPhone Safari, embedded browsers, and WordPress installations with mismatched COOKIE_DOMAIN/COOKIEPATH settings.
* Adds the same independent host-only, public-Home-URL browser cookie fallback already used by the existing hardened Google OAuth engine.
* Adds a single-use, HMAC-verified 256-bit continuation recovery secret that works only after the original Google OAuth flow has established an authenticated WordPress customer session.
* Preserves the secure return destination for newly registered Google customers and for two-factor-authenticated storefront sign-ins.
* Prevents LiteSpeed/page-cache reuse of Google storefront start and completion requests and returns expired attempts safely to the storefront instead of a raw WordPress error page.
* Keeps Google OAuth, Nextend continuity, PKCE, nonce verification, exact callback allowlisting, privileged-account blocking, and the existing single integrated Identity Pro plugin unchanged.

== 6.9.12 Integrated Google Storefront Identity ==
* Integrates the existing Delicat Store storefront Google session bridge directly into Delicat Identity Pro; no additional authentication plugin is required.
* Reuses the existing Identity Pro Google OAuth callback, verified Google subject, Nextend continuity migration, customer account, WooCommerce ownership, and WordPress authentication policies.
* Registers the missing server-side `delicat_app_exchange_identity_credential` verifier so the existing Delicat App API identity exchange can issue a customer-only storefront session.
* Synchronizes `_dglp_google_sub` and `_delicat_google_sub` only for one unambiguous, verified customer identity; conflicting owners, administrators, editors, shop managers, pending accounts, and unverified accounts remain blocked.
* Protects each storefront handoff with exact HTTPS origin allowlisting, browser-bound HttpOnly state cookies, a ten-minute OAuth flow, and an atomic single-use two-minute exchange credential.
* Preserves existing Google/Nextend callbacks, PKCE, nonce, Google signature verification, login throttling, TOTP 2FA, and the deny-by-default Identity REST firewall.
* Exposes only one exact, public read-only Identity readiness route without Google client secrets, provider tokens, customer data, or session credentials.

== 6.9.11 Instant Auth + Nextend Continuity ==
* Removes the interaction-time admin-ajax fragment waterfall: the secure Delicat login modal is pre-mounted with the storefront response and opens immediately while browser-bound form nonces refresh after it is already visible.
* Adds just-in-time migration of exact Nextend Google provider identities using the immutable Google subject (`sub`) instead of privileged e-mail auto-linking.
* Existing privileged Nextend Google links receive a signed, role-bound continuity marker only when the same Google subject maps uniquely to the same WordPress user and the authoritative Google mailbox exactly matches the local account.
* Preserves stronger Delicat Administrator mode for new links; existing Nextend Administrator links can continue during migration and are prompted to enable TOTP 2FA for the reinforced mode.
* Adds specific diagnostics for privileged auto-link, legacy Google conflicts, and duplicate Nextend identities.
* Marks OAuth/error/modal-state return pages no-store/no-cache so transient authentication state cannot be persisted by page caches.

== 6.9.10 Google OAuth Recovery ==
* Fixes the native modal OAuth tracker mismatch introduced by the lazy-modal path so Google failures return to the Delicat modal instead of WordPress wp-login.php.
* Canonicalizes new modal Google flows to `native_modal` while accepting the previous `identity_modal_v3` / `identity_modal_v4` tracker values for safe upgrade compatibility.
* Adds a host-only browser-binding cookie fallback for OAuth state validation without weakening the existing HMAC-bound one-time state check.
* Adds safe, specific Google OAuth error messages to the Delicat modal so configuration/network/state failures are diagnosable without exposing tokens or provider secrets.
* Keeps OAuth callbacks no-store/no-cache and preserves PKCE, state, nonce, local JWT signature validation, lockouts, 2FA and WordPress/WooCommerce session authority.


== 6.9.9 Secure Performance ==
* Replaces unconditional class parsing with a compatibility-safe DIP autoloader to reduce storefront PHP bootstrap work.
* Keeps Foundation, Provider Manager and UI Stability control-plane code out of normal public/AJAX requests.
* Returns legacy Builder login triggers to the secure lazy Identity modal path instead of embedding the full hidden auth interface on every catalog/home page.
* Splits Email Studio runtime delivery from its admin preview/editor files so storefront requests do not parse admin-only renderers.
* Preserves browser-bound nonces, progressive lockouts, rate limits, encrypted OAuth secrets, passkey/2FA protections and deny-by-default Identity REST policy.






== 6.9.2 Admin client identity + profile access ==
* Admin new-user notifications show the new client name and e-mail address only.
* The primary CTA opens that exact WordPress user profile; WordPress authentication and `edit_user` capability checks still protect access.
* Username, phone, role, numeric user ID and registration timestamp remain excluded from the admin e-mail body.
* WooCommerce billing first/last name are used as a fallback when the WordPress first/last name fields are empty.
* Pending-approval admin alerts now use the same name/e-mail identity and profile CTA instead of exposing a raw user ID.
* Migrates v6.9.1 privacy-mode defaults safely to the new behavior.

== 6.9.1 Privacy-safe new-user admin email ==
* Admin new-user notifications contain no username, name, email, phone, role, user ID, registration timestamp, or user-specific profile URL.
* Admin CTA now opens the generic WordPress Users screen.
* Client welcome/new-account emails retain their own customer-specific content and secure password setup flow.
* Email Studio schema upgraded to v6 with safe migration of the previous admin new-user defaults.

== 6.9.0 Unified Identity + Email Studio ==
* Merges Delicat Email Studio directly into Delicat Identity Pro as one WordPress/WooCommerce identity and notification plugin.
* Removes the former `class-dip-email-designer.php` duplicate renderer and its `user_register` welcome email to prevent duplicate new-account mail.
* Migrates existing standalone Email Studio settings when available; otherwise imports compatible Identity Email Designer branding settings.
* Deactivates the old standalone Delicat Email Studio plugin during activation/upgrade to prevent double hooks while preserving its stored configuration.
* Uses unique embedded `dipes_*` function names so an old standalone Email Studio can never cause PHP function redeclaration during migration.
* Synchronizes WordPress new-user admin/client notifications, WooCommerce transactional emails, WooCommerce account emails, WordPress password-reset emails and Delicat Identity security/access notifications through one design system.
* Routes 2FA, Passkey, privileged-social, new-device, provider-link, password-change, verification-email, OTP, magic-link and pending-account alerts through the unified email hub.
* Preserves WordPress/WooCommerce recipients and triggers; the unified layer changes rendering and centralizes delivery styling rather than changing account/order ownership.
* Email Studio configuration is administrator-only (`manage_options`) and its preview/test/export actions remain nonce protected.
* Adds WooCommerce HPOS compatibility declaration and keeps order access through WooCommerce APIs/hooks.
* Does not log OTPs, magic-link tokens, verification tokens or password-reset keys in the Identity audit trail.

== 6.8.1 Admin Google Secure Mode ==
* Allows Google login for WordPress Administrators only when the immutable Google identity was explicitly approved from an already authenticated Administrator session.
* Mandatory TOTP second factor before any Administrator WordPress auth cookie is issued through Google.
* Email matching alone can never grant Administrator Google access.
* Generic privileged social-login blocking remains active for editors, shop managers and non-approved privileged accounts.
* Provider linking no longer issues a fresh WordPress auth cookie and cross-account linked identities are rejected before profile metadata synchronization.
* Existing Administrator Google links require explicit Secure Mode approval; no silent migration is performed.

== 6.8.0 Passkeys / WebAuthn ==
* Passkeys WebAuthn liées au domaine Delicat avec vérification utilisateur obligatoire.
* Connexion passwordless via Face ID, Touch ID, biométrie Android, Windows Hello ou clé de sécurité.
* Enrôlement et suppression limités au compte connecté avec ré-authentification récente.
* Ré-authentification des actions sensibles par Passkey.
* Clé privée jamais stockée sur WordPress : seuls identifiant de credential, clé publique, compteur et métadonnées minimales sont conservés.
* Ceremonies one-time liées au navigateur, challenge cryptographique, validation RP ID/origine, UP/UV et signature.
* Support ES256 et RS256, protection de replay du compteur quand l’authenticator fournit un compteur non nul.
* Recommandations clients/admin pour l’adoption des Passkeys, sans obligation globale automatique des administrateurs.
* Enregistrement à attestation `none` suivi d’une assertion immédiate de preuve de possession avant sauvegarde de la credential.
* Limites de débit atomiques et verrous par compte pour réduire les courses concurrentes sur les cérémonies, compteurs et modifications de Passkeys.
* AAGUID non persisté afin de réduire les métadonnées d’authenticator stockées inutilement.
* Les identifiants `id`/`rawId` contradictoires sont refusés au lieu d’être tolérés.

== 6.7.0 Identity Security Phase 2 ==
* Adds TOTP two-factor authentication compatible with standard Authenticator apps using RFC 6238-style 30-second codes.
* Encrypts TOTP secrets with the existing Delicat authenticated-encryption layer and fails closed if an enabled secret cannot be decrypted.
* Generates 10 high-entropy recovery codes, stores only WordPress password hashes, displays the plaintext codes only temporarily in the current session, and consumes each code once.
* Adds replay protection so the same accepted TOTP time-step cannot be reused across web, mobile, or sensitive-action verification.
* Serializes TOTP/recovery consumption and rate-limit updates with short atomic option locks to reduce concurrent replay/race windows.
* Adds pair + account-wide 2FA rate limits and applies them to password login, mobile Google login, browser challenges, enrollment confirmation, recovery regeneration, disable operations, and reauthentication.
* Adds session-token-bound recent authentication for sensitive self-service operations and WooCommerce email/password changes.
* Protects social OAuth and passwordless browser login with a browser-bound second-factor challenge before any WordPress auth cookie is issued.
* Adds a final authenticate boundary and a send_auth_cookies boundary so later authenticators/direct cookie calls cannot silently bypass enabled 2FA.
* Blocks WordPress Application Password authentication for 2FA-enabled accounts by default while leaving credentials visible for review/removal; developers may explicitly opt in with a filter.
* Adds mobile 2FA enforcement: /mobile/google returns two_factor_required until the client retries with two_factor_code. Enabling/disabling 2FA revokes mobile sessions.
* Revalidates the exact mobile session that approved App Sync pairing before allowing a 2FA-protected browser pairing exchange.
* Requires HTTPS for 2FA enrollment/challenges and hardens the standalone challenge with no-store, noindex, DENY framing, no-referrer, nosniff, and a nonce-based CSP style policy.
* Adds customer and administrator security recommendations, including administrator 2FA coverage, recovery-code health, Application Password review, private-page protection, DISALLOW_FILE_EDIT and FORCE_SSL_ADMIN guidance.
* Adds a server-console-only WP-CLI recovery command: `wp delicat identity-2fa-reset <user>`; it removes Delicat 2FA and revokes all WordPress/mobile sessions.
* Does not force administrator 2FA automatically. Administrators should enroll and store recovery codes first, then enforcement can be considered in a later controlled phase.


== 6.5.0 ==
* Integrates the modern WooCommerce My Account UI directly into Delicat Identity Pro.
* Removes duplicate presentation hooks from the former Account UI companion snippet without touching wallet/order backend logic.
* Consolidates connected-accounts and identity-center into one Security navigation destination; legacy links redirect safely.
* Adds a modern responsive account profile header, wallet dashboard, order summary, recent orders and Identity security card.
* Uses only current-user WooCommerce orders, TeraWallet balance and current-user Identity device/provider data.
* Prevents the previous WooCommerce Identity dashboard widget from rendering twice.
* Adds responsive My Account CSS/JS assets loaded only for authenticated WooCommerce account pages.
* Preserves the 6.4 access-control architecture, private-page guards, WordPress sessions, OAuth, mobile sessions and WooCommerce ownership checks.

== 6.4.0 ==
* Adds a deny-by-default REST firewall for the Delicat Identity namespace.
* Classifies all plugin admin-post actions as administrator, customer self-service, or public authentication actions.
* Keeps all global Identity security/configuration tools restricted to WordPress administrators (manage_options).
* Adds administrator-only private-page controls directly to the WordPress Page editor.
* Protects Elementor pages containing Identity/security shortcodes before content rendering.
* Adds ownership helpers for WooCommerce orders and user-scoped records.
* Adds no-store/private headers across Identity REST responses.
* Adds an administrator-only architecture health score in My Account and the Security Center.
* Fixes passwordless nonce refresh to use the isolated native-auth AJAX action.
* Preserves WordPress/WooCommerce users, orders, linked accounts, mobile sessions, and the rebuilt 6.3 login popup.

== 6.3.1 ==
* Adds centralized admin/customer capability boundaries.
* Makes WooCommerce Identity configuration administrator-only.
* Protects My Account endpoints, Identity shortcodes, and configurable private page slugs.
* Adds no-store/noindex/private response headers for protected account pages.
* Revalidates customer account policy on protected pages and revokes stale suspended sessions.
* Adds an administrator-only Administration tab inside the Identity My Account dashboard.
* Keeps customer self-service limited to the authenticated customer’s own devices, sessions and linked accounts.


== Phase 11 ==
* Unified operations dashboard.
* Provider, security, device and system-health summaries.
* Social-login maintenance mode.
* Privacy-safe CSV security export.
* Compatibility center for WooCommerce, Elementor, LiteSpeed, Cloudflare, TeraWallet and Nextend.
* Cron repair tool and database table checks.
* Existing users, orders, wallets and provider links are preserved.

= 4.1.0-phase12 =
* Adds installation-bound refresh-token rotation.
* Adds mobile capabilities and device metadata endpoints.
* Adds encrypted FCM/APNs push-token registration.
* Adds device-bound biometric public-key registration and signed challenge login.
* Adds configurable mobile access/refresh lifetimes and cleanup.


== Phase 13 ==
Adds a local, privacy-safe diagnostics assistant, explainable recommendations, recent-event explanations, and a sanitized support bundle exporter. No external AI service is called.


== 4.3.0-phase14 ==
* Conditional front-end asset loading.
* Object-cache aware diagnostics with safe transient fallback.
* Daily plugin-scoped maintenance and cache invalidation.
* Database ANALYZE tool for Delicat Identity tables only.
* Performance and cache health center.


== 4.4.0-phase15 ==
* Adds the versioned Delicat Identity extension SDK.
* Adds privacy-safe self-tests and support-report export.
* Adds optional WP-CLI status, self-test and schedule-repair commands.
* Adds developer documentation and WordPress PHPUnit scaffolding.
* Preserves existing users, providers, WooCommerce data and mobile sessions.

== 4.4.1 Security Hardening ==
* Added endpoint-specific throttling for mobile login, refresh, and biometric endpoints.
* Required strong device and installation identifiers for mobile sessions.
* Bound biometric challenges and verification to the original installation.
* Added no-store security headers to mobile REST responses.
* Prevented expired access tokens from being accepted by session helper methods.
* Fixed security audit calls in the mobile module.
* Made secret and push-token encryption fail closed when secure cryptography is unavailable.
* Preserved all existing users, provider links, WooCommerce orders, wallet balances, and settings.


= 4.6.0 =
* Added staged Nextend migration wizard with dry run, test-account mode, 25-account batches, progress tracking, and rollback of wizard-created mappings.


== 4.7.0 Automatic Migration ==
* Adds resumable background migration for all safe Nextend Google accounts.
* Automatically performs pre-flight scan, backup, dry run, and batched mapping.
* Pauses on security failures and never force-links conflicts or privileged accounts.
* Adds progress, pause, resume, and review counters to the migration dashboard.


= 4.8.0 =
* Added final migration integrity verification.
* Added skipped-account review with masked customer details.
* Added seven-day post-migration monitoring state.
* Added safe finalization gate after customer testing.


== Unified account integration ==
The secure login/register modal is part of Delicat Identity Pro and shares WordPress/WooCommerce sessions, lockouts, devices and audit events.
* [delicat_login_button] — opens the Delicat modal for visitors and logs out connected users.
* <a href="#delicat-login">Connexion</a> — opens the same modal from Elementor/HTML links.
* [delicat_login_panel] — social providers plus the native account path.
* [delicat_google_login] — Google button only.
* [delicat_social_login_buttons providers="google" layout="row" align="center" google_text="Continuer avec Google"] — advanced Google button used by the modal.
Disable the old Code Snippets copy of the login modal before enabling this release to avoid duplicate hooks.

= 4.9.0 =
* Added Nextend-style feature parity for supported Google and Microsoft providers.
* Added comment and lost-password placements, role restrictions, registration notifications, tracker data shortcodes, icon/wide layouts, cache-bypass redirects and profile summary.
* Unsupported providers are not faked; each new provider still requires a complete secure OAuth/OpenID implementation.

= 4.9.1 =
* Fixed tracker handling without output-time cookies.
* Enforced configured blocked roles consistently for social linking and login.
* Corrected provider-neutral callback errors.
* Hardened social button URLs and rendering.


= 4.9.3 =
* Added visible redirect settings and presets.
* Added smart checkout/origin redirect priority.
* Prevents successful login from returning to WordPress authentication/admin screens.


= 4.10.0 =
* Added provider ordering, provider-specific redirects, role-based redirects, logged-in visibility controls, and corrected avatar sync preference.

== 4.10.1 ==
* Modern responsive admin dashboard refresh.
* Fixed repeated social dividers with multiple providers.
* Fixed duplicate lost-password provider output on WooCommerce pages.
* Added ordered provider-group rendering for integrations.
* Added sticky save state and clearer settings navigation.
* Improved button accessibility attributes.

== Changelog ==

= 6.9.19 =
* Self-contained modal CSS, strict style readiness and versioned asset recovery.
* Builder login trigger/menu handoff and removal of page-wide legacy observer.
* See FIX-6.9.19.md.


= 6.9.18 =
* Storefront logout/session routing, safe post-login destinations and OAuth one-time consumption.
* Responsive compact modal, request concurrency controls, explicit timeouts and POST-only form fallback.
* See AUDIT-6.9.18.md for validation, installation and remaining production checks.


= 6.9.2 =
* Admin new-user email now shows only the client name and email.
* Secure admin button opens the exact WordPress user profile after normal WordPress authentication/capability checks.
* Preserves privacy for username, phone, role, user ID, and registration timestamp in the email body.
* Migrates v6.9.1 privacy-mode email defaults safely.



= 6.7.0 Identity Security Phase 2 =
* Adds encrypted TOTP 2FA, one-time hashed recovery codes, replay protection and concurrency locks.
* Adds session-bound sensitive-action reauthentication and protects WooCommerce email/password changes.
* Adds web/social/passwordless/mobile second-factor enforcement and a final authentication-cookie boundary.
* Blocks Application Password authentication by default for 2FA-enabled accounts.
* Adds administrator/customer security recommendations and WP-CLI-only emergency 2FA reset.
* Preserves the 6.6 premium My Account UI and existing WordPress/WooCommerce/TeraWallet data.

= 6.2.4 Secure Session / Legacy Conflict Fix =
* Isolates native modal AJAX actions from the former Code Snippets action names.
* Detects and suppresses the duplicate legacy login modal without weakening Identity security.
* Uses a same-origin relative admin-ajax URL to avoid www/non-www/proxy CORS failures on Safari.
* Makes nonce bootstrap registration-aware and surfaces server-side bootstrap errors safely.
* Keeps the native modal above floating assistant/cart widgets and resets scroll position on reopen.
* Preserves the 6.2.2 database schema and all WordPress/WooCommerce account synchronization rules.

= 6.2.3 Login Modal Trigger Fix =
* Fixed Connexion button appearing but doing nothing because the footer JavaScript could execute before #delicat-login was rendered.
* Native auth modal now renders at wp_body_open when supported, with an early wp_footer fallback before WordPress prints footer scripts.
* Added a single-render guard so themes that expose both hooks never duplicate the modal.
* Added a DOM-ready/retry bootstrap in native-auth-modal.js so LiteSpeed, Elementor and script optimizers can reorder assets without breaking the popup.
* Security, WooCommerce account synchronization, lockouts, OAuth, mobile sessions and registration policies from 6.2.2 remain unchanged.

= 6.2.2 Hardened WordPress/WooCommerce Sync =
* Brute-force account buckets now canonicalize existing email/user_login aliases to the same WordPress user ID, closing an alias-rotation gap in distributed attacks.
* Visual Builder registration links now use the same Delicat/WordPress/WooCommerce registration state and open the native Identity registration tab when available.
* Fixed single-tab modal keyboard/ARIA state when public registration is disabled.
* Centralized the public registration switch across WordPress users_can_register, WooCommerce My Account registration and WooCommerce checkout account creation; native, social/mobile, OTP, magic-link and custom registration now honor the same platform state.
* Native modal now hides the Inscription tab when public account creation is closed instead of presenting a form the backend will reject.
* Fixed social-only password fallback detection: profile_update now confirms the WordPress password hash actually changed before considering a local password available.
* Trusted-device IP fingerprints now use the same explicitly trusted client-IP resolver as rate limiting/audit, and new-device email wording is provider-neutral for Google, password, OTP, magic-link and pairing logins.
* WooCommerce return-cookie deletion now mirrors Secure/HttpOnly/SameSite attributes and removes the in-request cookie value after consumption.
* Social OAuth lockouts are now keyed to the trusted resolved client IP instead of IP+User-Agent, preventing User-Agent rotation from creating fresh lockout buckets; audit fingerprints still retain browser differentiation.
* Cloudflare client-IP resolution now honors either explicit trusted-headers constant consistently across policy, audit and rate limiting.
* Added a last-priority WooCommerce customer-data guard during Delicat-managed registration: user ID, login, email, password, safe role and privilege-bearing meta cannot be replaced by third-party registration filters before wp_insert_user().
* Fixed social profile state propagation so new-user and local-verification flags reach the OAuth callback; new Google customers now receive the correct registration redirect.
* Native modal Google login now uses the same Identity engine directly, shares native success destinations, and returns OAuth errors to the originating modal instead of dumping customers onto wp-login.php.
* OAuth cancellation callbacks now validate/consume their one-time state and no longer increment lockout counters from unsolicited or user-cancelled callback URLs.
* Mobile refresh-token rotation now requires both the original Device ID and Installation ID; a mismatch revokes the mobile session.
* Reduced GPU-heavy backdrop blur on small screens for smoother low-end Android/iPhone modal animation.
* Fixed a schema-version collision where the runtime installer and Foundation both wrote incompatible values to dip_db_version; runtime schema now has its own version key and no longer causes repeated dbDelta/install work on frontend requests.
* Added explicit Google/Microsoft secret removal controls while preserving masked secrets when fields are left blank.
* Country-policy health now requires explicit trusted-Cloudflare configuration instead of treating the mere presence of a Cloudflare header as sufficient trust.
* Replaced production Google tokeninfo validation with local RS256 JWT verification against Google's cached JWK set; validates issuer, audience/azp, expiry, issued-at, nonce and subject.
* Uses Google sub as the durable provider identity. Gmail/Google Workspace addresses may prove local email ownership; third-party Google addresses require Delicat local email verification or an explicit authenticated link.
* Microsoft identity linking no longer trusts mutable email/preferred_username as account ownership; signed immutable provider identity remains the link key.
* Unified WordPress, WooCommerce My Account, classic checkout, Woo Store API/Blocks, native modal, social, OTP, magic link and app-pairing account policy.
* Added a final send_auth_cookies boundary for WooCommerce direct-login paths, including removal of the unused WordPress session token and in-request current-user reset when policy blocks a login.
* Bound email verification to the exact current user_email and invalidates proof, WordPress sessions and Delicat mobile sessions after customer email changes.
* Revokes Delicat mobile sessions after password changes/resets and pending-account enforcement.
* Disabled WordPress Application Passwords for storefront customer accounts by default while leaving staff/admin behavior unchanged; an explicit compatibility filter can opt a customer back in.
* Hardened anonymous login/register/passwordless nonces with a first-party HttpOnly browser binding to reduce login CSRF and cached-nonce failures.
* Magic-link and email-verification links now require an explicit browser-bound POST confirmation; email scanners/prefetchers cannot silently log a customer in or consume the link.
* OTP and magic-link requests are device/browser bound, replay limited and rate limited; OTP email state is no longer placed in the URL.
* Public registration roles are constrained to safe customer roles and checked for dangerous WordPress/WooCommerce capabilities.
* Brute-force protection separates account+IP, global IP and distributed account buckets while using a higher account-wide threshold to reduce attacker-induced customer lockouts.
* Destructive connected-account/device/session actions require POST plus nonce; customer provider unlink and password-setup actions no longer accept GET side effects.
* OAuth state, callback code, browser binding and authorization hosts are strictly validated; provider network responses are bounded and redirects disabled where appropriate.
* OAuth client secrets are upgraded from plaintext/legacy v1 storage to authenticated v2 encryption during activation when cryptography is available.
* Trusted browser devices use a random first-party HttpOnly identifier instead of User-Agent-only identity.
* Fixed Delicat account-button shortcode metadata, Google account-selection setting, social shortcode alignment/text parameters and added the row layout to the dashboard selector.
* Improved modal focus trapping, safe-area handling, short-screen scrolling, reduced motion, iOS input sizing, timeout/cancellation behavior and theme isolation.
* Preserves existing WordPress users, WooCommerce customers/orders, wallet data, provider subject links, migration state and Delicat mobile tables.

= 6.2.1 Hardened Sync =
* Unified final authentication policy before WordPress creates a session.
* Added account-wide distributed brute-force backstop in addition to IP and IP+account buckets.
* Suspension now immediately revokes WordPress and Delicat mobile sessions.
* Bound protected mobile access to the issuing device + installation and scoped automatic Bearer authentication to Delicat Identity REST routes.
* Cross-plugin Delicat App bridge now validates mobile device/installation binding.
* Unified login-method context so Google, OTP, magic link, app pairing and password logins are recorded correctly without duplicate device events.
* Hardened modal nonces, cancellation/timeouts, focus trap, iOS input sizing, short-screen layout and shortcode output escaping.
* Preserved WooCommerce customer creation, verified-email past-order linking, session initialization and registration policy integration.


= 5.7.0 =
* Added unified customer identity dashboard and WooCommerce endpoint.
* Added profile, connected accounts, security, app pairing, order and wallet summaries.
* Added responsive, reduced-motion-friendly customer dashboard UI.

= 5.5.0 =
* Phase 5 WooCommerce Pro integration.
* Modern checkout login panel and customer identity dashboard card.
* Role-based redirects and safe return URL preservation.
* Optional login protection for selected products and categories.
* Lightweight responsive assets loaded only on checkout/account pages.


= 5.0.0-phase1-foundation =
* Added non-destructive Phase 1 schema for sessions, tokens, and security events.
* Added automatic settings backup before schema upgrades and repairs.
* Added Foundation Health dashboard with WordPress, PHP, HTTPS, REST, WooCommerce, crypto, cron, settings, and table checks.
* Added one-click non-destructive schema repair.
* Preserved existing Google, Microsoft, WooCommerce, Nextend migration, device, mobile API, and Delicat App Sync behavior.
* Unified plugin header and runtime version identifiers.


== Phase 2: Connected Accounts ==
* Added secure Google and Microsoft account connection management.
* Added [delicat_connected_accounts] customer shortcode.
* Added protected provider disconnection with nonce validation.
* Prevents removing the final social provider when the account has no customer-managed password.
* Added extension filter for future provider modules.

= 5.2.0 =
* Added Provider Control Center under Settings → Identity Providers.
* Added Google and Microsoft OpenID discovery diagnostics.
* Added centralized provider activation and ordering controls.
* Added customer feedback after provider disconnect actions.
* Preserved existing OAuth callbacks, WooCommerce login, app sync, and connected-account data.


== 5.3.0 Phase 3 ==
* Added Visual Authentication Builder with presets, responsive preview and reusable [delicat_auth_panel] shortcode.
* Added JSON import/export for designs.
* Preserved existing OAuth, provider manager, security, WooCommerce and app sync layers.

= 5.4.0 Phase 4 Security Center =
* Added customer Security Center shortcode [delicat_security_center].
* Added security score and actionable recommendations.
* Added trusted-device controls and recent account-event history.
* Added secure logout of all other WordPress sessions.
* Added admin security overview for recent events and devices.

== 5.6.0 Phase 6 — App Sync 2.0 ==
* Added secure one-time web/app pairing.
* Added authenticated mobile approval endpoint.
* Added browser-bound pairing exchange and replay protection.
* Added App Sync admin control center and activity metrics.
* Added [delicat_app_pairing] shortcode with app deep links.
* Preserved the existing refresh-token, push, biometric and session APIs.


== 5.8.0 Phase 8 ==
* Added privacy-conscious Identity Analytics dashboard.
* Added cached daily event aggregation and maintenance cron.
* Added CSV reporting and user identity timelines.
* Added registration, profile-update and password-change audit events.


== 6.0.0 ==
* Added configurable registration builder.
* Added secure email OTP login.
* Added one-time magic-link authentication.
* Added throttling, expiration, replay protection and audit events.
* Added passwordless and registration shortcodes.


== 6.0.1 UI Stability ==
* Added unified modern admin control center.
* Added global UI design system for all Identity modules.
* Added safe repair tool for tables, schedules, routes and caches.
* Added responsive frontend polish for login, OTP, registration, security and connected accounts.
* Fixed inconsistent asset loading across module pages.

= 6.0.8 =
* Synchronized Identity mobile access tokens with Delicat App API protected routes.
* Added bridge health/capability reporting without sharing raw credentials.


= 6.1.0 Unified Auth =
* Merged the secure email/password login and registration modal into Delicat Identity Pro.
* Added progressive 15/30/60/120 minute native lockouts with IP + login/IP-pair protection.
* Added fresh AJAX nonce rotation, honeypot validation, registration throttling and password rules.
* Added full support for google_text, align and row layout in [delicat_social_login_buttons].
* Added the official multicolor Google mark to Identity buttons.
* Applied web identity policy, privileged-role blocking, pending approval and adaptive risk checks to mobile Google login.
* Fixed passwordless audit calls that referenced a missing DIP_Audit::log method.
* Preserved existing users, WooCommerce orders, wallets, provider links, app sessions and migration data.


= 6.6.0 Premium My Account Redesign =
* Rebuilt the integrated My Account visual layer with a premium Identity profile, application-style navigation, wallet hero, security summary and responsive profile form.
* Suppresses duplicate WooCommerce dashboard prose when the Delicat dashboard is active.
* Keeps WordPress/WooCommerce/TeraWallet/Identity security and ownership checks unchanged.

= 6.5.1 Premium Account + Security UI =
* Rebuilt the unified WooCommerce My Account dashboard with a premium responsive layout.
* Added a customer-safe Identity protection panel using the same security score as the Security Center.
* Added explicit email, linked-login, trusted-device and HTTPS status indicators without exposing admin/global security configuration.
* Redesigned wallet, account statistics, recent orders, navigation and profile presentation for mobile/tablet/desktop.
* Kept TeraWallet, WooCommerce and Delicat Identity as the sources of truth; no duplicate balance/order/security data stores were introduced.
* Modernized the customer Security Center and removed inline frontend CSS/JavaScript dependencies from device/session controls.
* Kept device trust/removal scoped to the current user and protected by WordPress nonces.
* Preserved centralized private-page protection, no-store/noindex headers and administrator-only Identity controls.


Legacy cleanup: after migration, administrators with delete_plugins capability get a nonce-protected “Supprimer l’ancien Email Studio” action. Deletion is never performed silently.

== 6.9.5 — Builder V9 synchronization + lazy public auth ==
* Keeps Identity Pro as the only authentication/security authority for Builder V9.
* Ordinary logged-out public pages no longer render the complete login/register/passkey modal in the initial document.
* Adds a tiny interaction loader that requests a fresh browser-bound nonce, fetches the modal presentation fragment, then loads the existing Identity modal/passkey assets only when needed.
* Cart, checkout, My Account, wp-login.php and other auth-critical flows keep the eager path.
* The modal fragment cannot authenticate or mutate credentials; existing login/register/resend/2FA/passkey endpoints remain authoritative.
* Browser-bound anonymous nonces are generated after interaction so full-page caches cannot share one visitor's nonce with another browser.
* Same-origin redirect validation is enforced for lazy modal fragments.
* Identity modal/loader scripts remain excluded from common async/delay optimizers.


6.9.8 POPUP RELIABILITY
-----------------------
- Fixes the 6.9.6 lazy modal API timing regression. DIPIdentityModalAPI is now
  published immediately when the modal script initializes, before the lazy
  loader attempts to open it.
- Adds dip:identity-open compatibility handling for Builder/legacy drawers.
- If lazy fragment loading fails, Identity automatically reloads the same page
  once in secure eager-modal mode and opens the popup instead of abandoning the
  popup flow.
- Browser-bound nonces, lockouts, registration verification, Google, 2FA and
  Passkeys/WebAuthn remain server-authoritative and unchanged.


6.9.8 POPUP RELIABILITY
- Legacy Delicat account drawer automatically uses eager hidden modal compatibility mode.
- Lazy loader never falls through to wp-login.php; failures recover on the same page in eager mode.
- Passkey asset failure no longer blocks password/Google modal opening.
- Added direct support for .dsb-account-login triggers and API readiness wait.
