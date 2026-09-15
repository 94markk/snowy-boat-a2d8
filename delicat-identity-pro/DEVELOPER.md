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

- `dip_before_authentication`
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
