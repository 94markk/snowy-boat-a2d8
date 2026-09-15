<?php
defined('ABSPATH') || exit;

final class DIP_Operations {
    public static function health(array $settings) {
        global $wp_version, $wpdb;
        $checks = [];
        $checks[] = self::check('https', 'HTTPS', DIP_Request::is_secure(), DIP_Request::is_secure() ? 'Connexion chiffrée active.' : 'HTTPS est obligatoire pour OAuth.');
        $checks[] = self::check('php', 'PHP', version_compare(PHP_VERSION, '7.4', '>='), PHP_VERSION);
        $checks[] = self::check('wordpress', 'WordPress', version_compare($wp_version, '6.2', '>='), $wp_version);
        $checks[] = self::check('woocommerce', 'WooCommerce', class_exists('WooCommerce'), class_exists('WooCommerce') ? (defined('WC_VERSION') ? WC_VERSION : 'Actif') : 'Non détecté');
        $checks[] = self::check('openssl', 'OpenSSL', extension_loaded('openssl'), extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Extension absente');
        $checks[] = self::check('json', 'JSON', extension_loaded('json'), extension_loaded('json') ? 'Disponible' : 'Extension absente');
        $checks[] = self::check('rest', 'REST API', function_exists('rest_get_server'), function_exists('rest_get_server') ? 'Disponible' : 'Indisponible');
        $checks[] = self::check('cron_audit', 'Nettoyage audit', (bool) wp_next_scheduled(DIP_Audit::CRON_HOOK), wp_next_scheduled(DIP_Audit::CRON_HOOK) ? 'Planifié' : 'Non planifié');
        $checks[] = self::check('cron_devices', 'Nettoyage appareils', (bool) wp_next_scheduled(DIP_Devices::CRON_HOOK), wp_next_scheduled(DIP_Devices::CRON_HOOK) ? 'Planifié' : 'Non planifié');
        $checks[] = self::check('google', 'Google', !empty($settings['client_id']) && !empty($settings['client_secret']), !empty($settings['client_id']) && !empty($settings['client_secret']) ? 'Identifiants présents' : 'Configuration incomplète');
        $ms_ok = ($settings['microsoft_enabled'] ?? 'no') !== 'yes' || (!empty($settings['microsoft_client_id']) && !empty($settings['microsoft_client_secret']));
        $checks[] = self::check('microsoft', 'Microsoft', $ms_ok, $ms_ok ? 'Configuration cohérente' : 'Fournisseur actif mais incomplet');
        $country_policy = ($settings['country_policy_mode'] ?? 'off') !== 'off';
        $cf_trusted = (defined('DIP_TRUST_CLOUDFLARE_HEADERS') && DIP_TRUST_CLOUDFLARE_HEADERS)
            || (defined('DIP_TRUST_CLOUDFLARE_CONNECTING_IP') && DIP_TRUST_CLOUDFLARE_CONNECTING_IP);
        $checks[] = self::check(
            'cloudflare_trust',
            'Confiance Cloudflare',
            !$country_policy || $cf_trusted,
            !$country_policy ? 'Politique pays désactivée' : ($cf_trusted ? 'Confiance explicite activée' : 'Politique pays active mais constante de confiance absente')
        );
        $audit_table = DIP_Audit::table();
        $device_table = DIP_Devices::table();
        $checks[] = self::check('audit_table', 'Table audit', $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table)) === $audit_table, $audit_table);
        $checks[] = self::check('device_table', 'Table appareils', $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $device_table)) === $device_table, $device_table);
        return $checks;
    }

    private static function check($id, $label, $ok, $detail) {
        return ['id'=>$id, 'label'=>$label, 'status'=>$ok ? 'healthy' : 'error', 'detail'=>(string)$detail];
    }

    public static function compatibility() {
        $plugins = [
            'WooCommerce' => class_exists('WooCommerce'),
            'Elementor' => did_action('elementor/loaded') || defined('ELEMENTOR_VERSION'),
            'LiteSpeed Cache' => defined('LSCWP_V'),
            'Cloudflare' => !empty($_SERVER['HTTP_CF_RAY']),
            'TeraWallet' => class_exists('Woo_Wallet') || defined('WOOCOMMERCE_WALLET_VERSION'),
            'Nextend Social Login' => class_exists('NextendSocialLogin') || defined('NSL_PATH_FILE'),
        ];
        return $plugins;
    }

    public static function provider_usage($days = 30) {
        global $wpdb;
        $days = max(1, min(180, absint($days)));
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT context, COUNT(*) total FROM ' . DIP_Audit::table() . " WHERE event_type='login_success' AND created_at >= %s GROUP BY context", $cutoff), ARRAY_A);
        $out = ['google'=>0,'microsoft'=>0,'unknown'=>0];
        foreach ($rows as $row) {
            $ctx = json_decode((string)$row['context'], true);
            $provider = sanitize_key($ctx['provider'] ?? 'unknown');
            if (!isset($out[$provider])) $provider = 'unknown';
            $out[$provider] += (int)$row['total'];
        }
        return $out;
    }

    public static function export_csv() {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT created_at,event_type,severity,user_id,fingerprint,context FROM ' . DIP_Audit::table() . ' ORDER BY id DESC LIMIT 5000', ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-security-' . gmdate('Ymd-His') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at_utc','event_type','severity','user_id','fingerprint_prefix','context']);
        foreach ($rows as $row) {
            fputcsv($out, [$row['created_at'],$row['event_type'],$row['severity'],(int)$row['user_id'],substr($row['fingerprint'],0,12),$row['context']]);
        }
        fclose($out);
        exit;
    }
}
