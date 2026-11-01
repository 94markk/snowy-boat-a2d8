<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Read-only system health checks for Delicat Assistant Pro. */
class DSA2_Diagnostics {
    public static function init() {
        add_action( 'admin_post_dap_download_diagnostics', array( __CLASS__, 'download' ) );
    }

    public static function snapshot() {
        global $wpdb;
        $uploads = wp_upload_dir();
        $settings = (array) get_option( 'dap_settings', array() );
        $tables = array(
            'conversations' => $wpdb->prefix . 'dap_conversations',
            'messages'      => $wpdb->prefix . 'dap_messages',
            'faq'           => $wpdb->prefix . 'dap_faq',
            'sessions'      => $wpdb->prefix . 'dap_mobile_sessions',
            'devices'       => $wpdb->prefix . 'dap_mobile_devices',
            'admin_devices' => $wpdb->prefix . 'dap_admin_sessions',
        );
        $table_state = array();
        foreach ( $tables as $key => $table ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
            $table_state[ $key ] = array(
                'name'   => $table,
                'exists' => $exists,
                'rows'   => $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) : 0, // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            );
        }

        $required_columns = array(
            'conversations' => array( 'priority','last_customer_read_id','last_agent_read_id','last_agent_message_at' ),
            'messages'      => array( 'sender_id','channel','meta' ),
            'devices'       => array( 'installation_id','fcm_token' ),
            'admin_devices' => array( 'user_id','installation_id','token_hash','fcm_token','expires_at' ),
        );
        $schema_ok = true;
        $schema_missing = array();
        foreach ( $required_columns as $key => $columns ) {
            if ( empty( $table_state[ $key ]['exists'] ) ) {
                $schema_ok = false;
                $schema_missing[ $key ] = $columns;
                continue;
            }
            $available = (array) $wpdb->get_col( 'SHOW COLUMNS FROM ' . $table_state[ $key ]['name'], 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $missing = array_values( array_diff( $columns, $available ) );
            if ( $missing ) {
                $schema_ok = false;
                $schema_missing[ $key ] = $missing;
            }
        }

        $cron = wp_next_scheduled( 'dsa2_daily_cleanup' );
        $push_configured = class_exists( 'DSA2_Admin_Mobile_API' ) && method_exists( 'DSA2_Admin_Mobile_API', 'push_configured' ) ? DSA2_Admin_Mobile_API::push_configured() : ! empty( $settings['firebase_v1_webhook_url'] );
        $webhook_configured = ! empty( $settings['webhook_url'] );
        $permalinks = (string) get_option( 'permalink_structure', '' );
        $cache_plugins = array();
        if ( defined( 'LSCWP_V' ) ) $cache_plugins[] = 'LiteSpeed Cache';
        if ( defined( 'WP_ROCKET_VERSION' ) ) $cache_plugins[] = 'WP Rocket';
        if ( defined( 'W3TC' ) ) $cache_plugins[] = 'W3 Total Cache';
        if ( class_exists( 'autoptimizeCache' ) ) $cache_plugins[] = 'Autoptimize';

        $checks = array(
            array( 'id'=>'php', 'label'=>'PHP', 'ok'=>version_compare( PHP_VERSION, '7.4', '>=' ), 'value'=>PHP_VERSION, 'help'=>'PHP 7.4 minimum; PHP 8.1+ recommandé.' ),
            array( 'id'=>'wordpress', 'label'=>'WordPress', 'ok'=>version_compare( get_bloginfo('version'), '6.0', '>=' ), 'value'=>get_bloginfo('version'), 'help'=>'WordPress 6.0 minimum.' ),
            array( 'id'=>'woocommerce', 'label'=>'WooCommerce', 'ok'=>class_exists('WooCommerce') || function_exists('wc_get_orders'), 'value'=>defined('WC_VERSION') ? WC_VERSION : 'Non détecté', 'help'=>'Nécessaire pour les produits, commandes et comptes.' ),
            array( 'id'=>'schema', 'label'=>'Base de données', 'ok'=>$schema_ok, 'value'=>$schema_ok ? 'Schéma complet' : 'Colonnes manquantes', 'help'=>$schema_missing ? wp_json_encode($schema_missing) : 'Toutes les tables et colonnes requises sont présentes.' ),
            array( 'id'=>'cron', 'label'=>'Nettoyage planifié', 'ok'=>(bool)$cron, 'value'=>$cron ? wp_date('d/m/Y H:i', $cron) : 'Non planifié', 'help'=>'La tâche quotidienne supprime les anciennes sessions et données invitées.' ),
            array( 'id'=>'uploads', 'label'=>'Téléversements', 'ok'=>empty($uploads['error']) && is_writable($uploads['basedir']), 'value'=>empty($uploads['error']) ? $uploads['basedir'] : $uploads['error'], 'help'=>'Nécessaire pour les captures envoyées dans le chat.' ),
            array( 'id'=>'rest', 'label'=>'REST API mobile', 'ok'=>function_exists('rest_url'), 'value'=>rest_url( DSA2_Mobile_API::NS . '/config' ), 'help'=>$permalinks ? 'Permaliens actifs.' : 'Permaliens simples: vérifiez que la REST API reste accessible.' ),
            array( 'id'=>'admin_rest', 'label'=>'REST API Android administrateur', 'ok'=>class_exists('DSA2_Admin_Mobile_API') && (!isset($settings['admin_app_enabled']) || !empty($settings['admin_app_enabled'])), 'value'=>rest_url( DSA2_Admin_Mobile_API::NS . '/auth/login' ), 'help'=>'Connexion HTTPS réservée aux comptes administrateurs et agents support.' ),
            array( 'id'=>'mbstring', 'label'=>'Extension mbstring', 'ok'=>function_exists('mb_strlen'), 'value'=>function_exists('mb_strlen') ? 'Disponible' : 'Fallback actif', 'help'=>'Optionnelle; le plugin possède un fallback UTF-8.' ),
            array( 'id'=>'openssl', 'label'=>'OpenSSL', 'ok'=>extension_loaded('openssl'), 'value'=>extension_loaded('openssl') ? OPENSSL_VERSION_TEXT : 'Non disponible', 'help'=>'Recommandé pour les connexions webhook et Firebase.' ),
            array( 'id'=>'firebase', 'label'=>'Firebase Push', 'ok'=>$push_configured, 'value'=>$push_configured ? 'Webhook configuré' : 'Non configuré', 'help'=>'Les messages restent fonctionnels sans push, mais Android ne recevra pas les notifications.' ),
            array( 'id'=>'agent_webhook', 'label'=>'Webhook agent', 'ok'=>$webhook_configured || ! empty($settings['notify_enabled']), 'value'=>$webhook_configured ? 'Webhook configuré' : ( ! empty($settings['notify_enabled']) ? 'Alerte e-mail active' : 'Aucune alerte' ), 'help'=>'Au moins un canal d’alerte est recommandé.' ),
        );

        return array(
            'generated_at' => current_time( 'mysql' ),
            'site_url' => home_url('/'),
            'plugin_version' => DSA2_VERSION,
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : '',
            'checks' => $checks,
            'tables' => $table_state,
            'cache_plugins' => $cache_plugins,
            'runtime_mode' => class_exists('DSA2_Frontend') ? DSA2_Frontend::runtime_mode() : 'unknown',
            'debug_enabled' => defined('WP_DEBUG') && WP_DEBUG,
            'memory_limit' => ini_get('memory_limit'),
            'timezone' => wp_timezone_string(),
        );
    }

    public static function page() {
        if ( ! current_user_can( DSA2_Install::CAP_SETTINGS ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
        $snapshot = self::snapshot();
        $healthy = count( array_filter( $snapshot['checks'], static function( $check ){ return ! empty( $check['ok'] ); } ) );
        $total = count( $snapshot['checks'] );
        ?>
        <div class="wrap dap-admin dap-diagnostics-page">
            <header class="dap-admin-hero"><div><span class="dap-eyebrow">SANTÉ DU SYSTÈME</span><h1>Diagnostic Delicat Assistant</h1><p>Contrôles en lecture seule pour WordPress, WooCommerce, Android, Firebase et la base de données.</p></div><div class="dap-hero-actions"><a class="dap-secondary-link" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant-settings')); ?>">← Paramètres</a><a class="dap-version" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dap_download_diagnostics'),'dap_download_diagnostics')); ?>">Exporter JSON</a></div></header>
            <section class="dap-diagnostic-summary <?php echo $healthy===$total?'is-good':'has-warning'; ?>"><strong><?php echo (int)$healthy; ?>/<?php echo (int)$total; ?> contrôles réussis</strong><span>Généré le <?php echo esc_html(mysql2date('d/m/Y H:i', $snapshot['generated_at'])); ?></span></section>
            <div class="dap-diagnostic-grid">
                <?php foreach ( $snapshot['checks'] as $check ) : ?>
                    <article class="dap-diagnostic-card <?php echo !empty($check['ok'])?'is-ok':'is-warning'; ?>"><header><i><?php echo !empty($check['ok'])?'✓':'!'; ?></i><div><h2><?php echo esc_html($check['label']); ?></h2><strong><?php echo esc_html($check['value']); ?></strong></div></header><p><?php echo esc_html($check['help']); ?></p></article>
                <?php endforeach; ?>
            </div>
            <section class="dap-settings-card"><header><span>DB</span><div><h2>Volumes de données</h2><p>Les nombres ci-dessous ne contiennent aucune donnée client.</p></div></header><div class="dap-context-metrics"><?php foreach($snapshot['tables'] as $key=>$table): ?><div><span><?php echo esc_html(ucfirst($key)); ?></span><strong><?php echo !empty($table['exists'])?number_format_i18n($table['rows']):'Absent'; ?></strong></div><?php endforeach; ?></div></section>
            <section class="dap-settings-card"><header><span>ENV</span><div><h2>Environnement</h2><p>Informations utiles lors d’un dépannage.</p></div></header><div class="dap-settings-grid"><div class="dap-readonly-note"><strong>Mode de chargement</strong><p><?php echo esc_html($snapshot['runtime_mode']); ?></p></div><div class="dap-readonly-note"><strong>Mémoire PHP</strong><p><?php echo esc_html($snapshot['memory_limit']); ?></p></div><div class="dap-readonly-note"><strong>Fuseau horaire</strong><p><?php echo esc_html($snapshot['timezone']); ?></p></div><div class="dap-readonly-note"><strong>Cache détecté</strong><p><?php echo esc_html($snapshot['cache_plugins'] ? implode(', ', $snapshot['cache_plugins']) : 'Aucun plugin détecté'); ?></p></div></div></section>
        </div>
        <?php
    }

    public static function download() {
        if ( ! current_user_can( DSA2_Install::CAP_SETTINGS ) && ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
        check_admin_referer( 'dap_download_diagnostics' );
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="delicat-assistant-diagnostics-' . gmdate('Ymd-His') . '.json"' );
        echo wp_json_encode( self::snapshot(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
}
