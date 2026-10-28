# Delicat Identity Pro Developer SDK

## Compatibility

- SDK API: `1.0`
- PHP: 7.4+
- WordPress: 6.2+

## Register an extension

```php
add_filter('dip_identity_extensions', function (array $extensions): array {
    $extensions['my-extension'] = [
        'name' => 'My Identity Extension',
        'version' => '1.0.0',
        'requires_api' => '1.0',
        'health_callback' => function (): array {
            return ['status' => 'healthy', 'message' => 'Ready'];
        },
    ];
    return $extensions;
});
```

## Stable lifecycle hooks

- `dip_login_success`
- `dip_user_created`
- `dip_account_linked`
- `dip_self_test_completed`
- `dip_sdk_ready`

Never log or export OAuth codes, ID tokens, access tokens, refresh tokens, Client Secrets, passwords, raw IP addresses, or session cookies.

## WP-CLI

```bash
wp delicat-identity status
wp delicat-identity self-test
wp delicat-identity repair-schedules
```

## Staging test matrix

Static validation is not a replacement for integration testing. Before production deployment, perform the following in a staging copy of the real site:

1. **Native modal**: incorrect password ×5, lockout message, successful login after expiry, Remember Me, logout, lost-password link, registration, duplicate email and verification resend.
2. **Google web**: new Gmail customer, existing Gmail customer, Google Workspace customer, third-party Google-email customer, changed Google email, canceled OAuth, stale callback, replayed state, explicit connected-account linking/unlinking.
3. **Microsoft**: new customer, existing explicitly linked customer, mutable email mismatch, unlink safety and cancellation.
4. **Woo My Account**: native registration with verification enabled; confirm the account is created but cannot auto-login until verified.
5. **Classic checkout**: create account during checkout; order creation must still succeed according to Woo settings, but an unverified account must not retain an authenticated customer cookie.
6. **Checkout Blocks / Store API**: repeat account creation and confirm the same final session policy.
7. **Email change**: change a customer email in My Account/profile; confirm the old browser session and mobile app session cease to authorize subsequent requests and the new email requires verification.
8. **Password reset/change**: confirm mobile refresh/access credentials are invalidated as intended.
9. **OTP**: correct code, incorrect code ×5, expiry, request from a second browser, unknown email and new-user setting.
10. **Magic link**: allow an email-security scanner/browser GET; confirm it does not consume or log in. Then confirm manually and verify one-time use/replay failure.
11. **Mobile Google**: Android and iOS client IDs, correct/incorrect audience, device/installation mismatch, refresh rotation, refresh replay, logout, revoked session.
12. **Biometric**: register key, issue challenge, valid signature, replay, different installation and disabled feature.
13. **App pairing**: create/status/approve/exchange, wrong browser token, wrong secret, expired request, duplicate exchange and blocked/unverified customer.
14. **WooCommerce order history/wallet**: ensure verified customers keep the same user ID and therefore preserve orders, TeraWallet data and transaction history.
15. **Performance/UI**: iPhone Safari, Android Chrome, low-end phone, 320px width, landscape/short height, dark theme active in site, LiteSpeed cache and Cloudflare cache.

Confirm SMTP/mail delivery before requiring email verification, OTP or magic links.
