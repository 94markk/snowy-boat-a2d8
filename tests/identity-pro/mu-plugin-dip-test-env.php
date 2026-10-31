<?php
// Test-only: allow WebAuthn passkeys on http://localhost:<port> and capture mail.
add_filter('dip_passkeys_allow_insecure_localhost', '__return_true');
add_filter('pre_wp_mail', function ($null, $atts) {
    $dir = WP_CONTENT_DIR . '/test-mail';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($dir . '/' . microtime(true) . '-' . wp_rand() . '.json', wp_json_encode($atts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return true;
}, 10, 2);
