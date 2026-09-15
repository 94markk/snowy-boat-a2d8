<?php
defined('ABSPATH') || exit;

/**
 * Public extension and maintenance SDK for Delicat Identity Pro.
 */
final class DIP_SDK {
    const API_VERSION = '1.0';
    const SELF_TEST_OPTION = 'dip_sdk_last_self_test';
    private static $extensions = [];

    public static function init() {
        add_action('init', [__CLASS__, 'register_extensions'], 2);
        if (defined('WP_CLI') && WP_CLI) self::register_cli();
    }

    public static function register_extensions() {
        $extensions = apply_filters('dip_identity_extensions', []);
        if (!is_array($extensions)) $extensions = [];
        foreach ($extensions as $slug => $definition) {
            $slug = sanitize_key($slug);
            if (!$slug || !is_array($definition)) continue;
            self::$extensions[$slug] = wp_parse_args($definition, [
                'name' => $slug,
                'version' => '0.0.0',
                'requires_api' => self::API_VERSION,
                'health_callback' => null,
            ]);
        }
        do_action('dip_sdk_ready', self::API_VERSION, self::$extensions);
    }

    public static function extensions() { return self::$extensions; }

    public static function health() {
        $results = [];
        foreach (self::$extensions as $slug => $extension) {
            $status = 'healthy'; $message = 'Extension chargée.';
            if (version_compare(self::API_VERSION, (string) $extension['requires_api'], '<')) {
                $status = 'error'; $message = 'Version SDK insuffisante.';
            } elseif (is_callable($extension['health_callback'])) {
                try {
                    $check = call_user_func($extension['health_callback']);
                    if (is_array($check)) {
                        $status = in_array(($check['status'] ?? ''), ['healthy','warning','error'], true) ? $check['status'] : 'warning';
                        $message = sanitize_text_field($check['message'] ?? 'État fourni par l’extension.');
                    }
                } catch (Throwable $e) {
                    $status = 'error'; $message = 'Le contrôle de santé a échoué.';
                }
            }
            $results[$slug] = [
                'name' => sanitize_text_field($extension['name']),
                'version' => sanitize_text_field($extension['version']),
                'status' => $status,
                'message' => $message,
            ];
        }
        return $results;
    }

    public static function run_self_test() {
        global $wpdb;
        $checks = [];
        $checks['php'] = [
            'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'pass' : 'fail',
            'message' => 'PHP ' . PHP_VERSION,
        ];
        $checks['wordpress'] = [
            'status' => version_compare(get_bloginfo('version'), '6.2', '>=') ? 'pass' : 'warn',
            'message' => 'WordPress ' . get_bloginfo('version'),
        ];
        $checks['https'] = [
            'status' => DIP_Request::is_secure() ? 'pass' : 'fail',
            'message' => DIP_Request::is_secure() ? 'HTTPS actif' : 'HTTPS requis pour OAuth',
        ];
        $checks['crypto'] = [
            'status' => (function_exists('sodium_crypto_secretbox') || function_exists('openssl_encrypt')) ? 'pass' : 'fail',
            'message' => function_exists('sodium_crypto_secretbox') ? 'Sodium disponible' : (function_exists('openssl_encrypt') ? 'OpenSSL disponible' : 'Aucun moteur cryptographique compatible'),
        ];
        $audit_table = class_exists('DIP_Audit') ? DIP_Audit::table() : '';
        $checks['audit_table'] = [
            'status' => ($audit_table && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $audit_table)) === $audit_table) ? 'pass' : 'fail',
            'message' => 'Table d’audit',
        ];
        $settings = wp_parse_args((array) get_option(DIP_Plugin::OPTION, []), DIP_Plugin::defaults());
        $checks['google'] = [
            'status' => !empty($settings['client_id']) ? 'pass' : 'warn',
            'message' => !empty($settings['client_id']) ? 'Google configuré' : 'Google Client ID manquant',
        ];
        $checks['cron'] = [
            'status' => wp_next_scheduled(DIP_Audit::CRON_HOOK) ? 'pass' : 'warn',
            'message' => wp_next_scheduled(DIP_Audit::CRON_HOOK) ? 'Nettoyage planifié' : 'Tâche de nettoyage absente',
        ];
        $checks['rest'] = [
            'status' => function_exists('rest_get_server') ? 'pass' : 'fail',
            'message' => 'API REST WordPress',
        ];
        $checks['extensions'] = [
            'status' => 'pass',
            'message' => count(self::$extensions) . ' extension(s) SDK',
        ];
        $result = ['checked_at' => time(), 'checks' => $checks, 'sdk_version' => self::API_VERSION, 'plugin_version' => DIP_VERSION];
        update_option(self::SELF_TEST_OPTION, $result, false);
        do_action('dip_self_test_completed', $result);
        return $result;
    }

    public static function sanitized_report() {
        $test = get_option(self::SELF_TEST_OPTION, []);
        return [
            'generated_at' => gmdate('c'),
            'plugin_version' => DIP_VERSION,
            'sdk_version' => self::API_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'multisite' => is_multisite(),
            'woocommerce_active' => class_exists('WooCommerce'),
            'object_cache' => wp_using_ext_object_cache(),
            'self_test' => $test,
            'extensions' => self::health(),
        ];
    }

    private static function register_cli() {
        WP_CLI::add_command('delicat-identity status', function () {
            WP_CLI::line(wp_json_encode(self::sanitized_report(), JSON_PRETTY_PRINT));
        });
        WP_CLI::add_command('delicat-identity self-test', function () {
            $result = self::run_self_test();
            $failed = count(array_filter($result['checks'], function ($c) { return $c['status'] === 'fail'; }));
            $failed ? WP_CLI::warning($failed . ' contrôle(s) en échec.') : WP_CLI::success('Tous les contrôles critiques ont réussi.');
        });
        WP_CLI::add_command('delicat-identity repair-schedules', function () {
            DIP_Audit::install();
            if (class_exists('DIP_Devices')) DIP_Devices::install();
            if (class_exists('DIP_Performance') && !wp_next_scheduled(DIP_Performance::CRON_HOOK)) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', DIP_Performance::CRON_HOOK);
            WP_CLI::success('Tâches planifiées vérifiées.');
        });
    }
}
