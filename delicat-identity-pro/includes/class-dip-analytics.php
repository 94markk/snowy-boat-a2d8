<?php
defined('ABSPATH') || exit;

/**
 * Phase 8: privacy-conscious identity analytics and customer timelines.
 * Uses aggregated audit data and never stores raw IP addresses or secrets.
 */
final class DIP_Analytics {
    const CRON_HOOK = 'dip_analytics_daily_rollup';
    const CACHE_GROUP = 'dip_analytics_';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 32);
        add_action('admin_post_dip_analytics_export', [__CLASS__, 'export_csv']);
        add_action('admin_post_dip_analytics_refresh', [__CLASS__, 'refresh_now']);
        add_action(self::CRON_HOOK, [__CLASS__, 'rollup']);
        add_action('show_user_profile', [__CLASS__, 'user_timeline_profile']);
        add_action('edit_user_profile', [__CLASS__, 'user_timeline_profile']);
        add_action('user_register', [__CLASS__, 'record_registration'], 20, 1);
        add_action('profile_update', [__CLASS__, 'record_profile_update'], 20, 2);
        add_action('after_password_reset', [__CLASS__, 'record_password_reset'], 20, 2);
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            metric_date date NOT NULL,
            event_type varchar(64) NOT NULL,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY date_event (metric_date,event_type),
            KEY metric_date (metric_date),
            KEY event_type (event_type)
        ) {$charset};");
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
        self::rollup(45);
    }

    public static function uninstall_schedule() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    private static function table() {
        global $wpdb;
        return $wpdb->prefix . 'dip_analytics_daily';
    }

    private static function audit_table_exists() {
        global $wpdb;
        $table = DIP_Audit::table();
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    public static function record_registration($user_id) {
        DIP_Audit::record('user_registered', 'info', absint($user_id));
        self::flush_cache();
    }

    public static function record_profile_update($user_id, $old_user_data) {
        unset($old_user_data);
        DIP_Audit::record('profile_updated', 'notice', absint($user_id));
        self::flush_cache();
    }

    public static function record_password_reset($user, $new_pass) {
        unset($new_pass);
        if ($user instanceof WP_User) DIP_Audit::record('password_changed', 'notice', (int) $user->ID);
        self::flush_cache();
    }

    public static function rollup($days = 45) {
        global $wpdb;
        if (!self::audit_table_exists()) return;
        $days = min(365, max(1, absint($days)));
        $audit = DIP_Audit::table();
        $daily = self::table();
        $cutoff = gmdate('Y-m-d 00:00:00', time() - ($days * DAY_IN_SECONDS));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) metric_date, event_type, COUNT(*) total
             FROM {$audit} WHERE created_at >= %s
             GROUP BY DATE(created_at), event_type",
            $cutoff
        ), ARRAY_A);
        foreach ($rows as $row) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$daily} (metric_date,event_type,total) VALUES (%s,%s,%d)
                 ON DUPLICATE KEY UPDATE total=VALUES(total)",
                $row['metric_date'], sanitize_key($row['event_type']), absint($row['total'])
            ));
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$daily} WHERE metric_date < %s", gmdate('Y-m-d', time() - 400 * DAY_IN_SECONDS)));
        self::flush_cache();
        update_option('dip_analytics_last_rollup', time(), false);
    }

    private static function flush_cache() {
        foreach ([7,30,90,365] as $days) delete_transient(self::CACHE_GROUP . $days);
    }

    private static function range_days() {
        $days = isset($_GET['days']) ? absint($_GET['days']) : 30;
        return in_array($days, [7,30,90,365], true) ? $days : 30;
    }

    public static function data($days = 30) {
        global $wpdb;
        $days = in_array((int) $days, [7,30,90,365], true) ? (int) $days : 30;
        $cache_key = self::CACHE_GROUP . $days;
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;
        self::rollup($days + 2);
        $table = self::table();
        $cutoff = gmdate('Y-m-d', time() - (($days - 1) * DAY_IN_SECONDS));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT metric_date,event_type,total FROM {$table} WHERE metric_date >= %s ORDER BY metric_date ASC",
            $cutoff
        ), ARRAY_A);
        $totals = [];
        $series = [];
        foreach ($rows as $row) {
            $event = sanitize_key($row['event_type']);
            $count = absint($row['total']);
            $totals[$event] = isset($totals[$event]) ? $totals[$event] + $count : $count;
            if (!isset($series[$row['metric_date']])) $series[$row['metric_date']] = [];
            $series[$row['metric_date']][$event] = $count;
        }
        $devices = class_exists('DIP_Devices') ? DIP_Devices::summary($days) : [];
        $result = ['days'=>$days,'totals'=>$totals,'series'=>$series,'devices'=>$devices,'generated'=>time()];
        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    private static function total_like(array $totals, array $needles) {
        $sum = 0;
        foreach ($totals as $event => $count) {
            foreach ($needles as $needle) {
                if ($event === $needle || strpos($event, $needle) !== false) { $sum += (int) $count; break; }
            }
        }
        return $sum;
    }

    private static function metrics(array $data) {
        $t = $data['totals'];
        return [
            'logins' => self::total_like($t, ['login_success','mobile_biometric_success','mobile_session_issued']),
            'registrations' => self::total_like($t, ['user_registered','registration_success','account_created']),
            'failed' => self::total_like($t, ['login_failed','login_blocked','mobile_rate_limited','authentication_failed','lockout']),
            'providers' => self::total_like($t, ['google_login','microsoft_login','social_login','account_connected']),
            'new_devices' => self::total_like($t, ['new_device_login']),
            'pairings' => self::total_like($t, ['app_pairing_approved','app_pairing_completed']),
            'warnings' => self::severity_count('warning', $data['days']),
        ];
    }

    private static function severity_count($severity, $days) {
        global $wpdb;
        if (!self::audit_table_exists()) return 0;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (absint($days) * DAY_IN_SECONDS));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DIP_Audit::table() . ' WHERE severity=%s AND created_at >= %s', sanitize_key($severity), $cutoff));
    }

    public static function admin_menu() {
        add_menu_page('Identity Analytics', 'Identity Analytics', 'manage_options', 'dip-analytics', [__CLASS__, 'render_page'], 'dashicons-chart-area', 58);
    }

    private static function admin_url($days) {
        return add_query_arg(['page'=>'dip-analytics','days'=>absint($days)], admin_url('admin.php'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) return;
        $days = self::range_days();
        $data = self::data($days);
        $metrics = self::metrics($data);
        $last = (int) get_option('dip_analytics_last_rollup', 0);
        $max = 1;
        foreach ($data['series'] as $day) $max = max($max, self::total_like($day, ['login_success','mobile_biometric_success','mobile_session_issued']));
        ?>
        <div class="wrap dip-analytics-wrap">
          <h1><?php esc_html_e('Delicat Identity Analytics', 'delicat-google-login'); ?></h1>
          <p><?php esc_html_e('Statistiques agrégées et respectueuses de la confidentialité. Aucune adresse IP brute ni aucun secret ne sont enregistrés.', 'delicat-google-login'); ?></p>
          <div class="dip-an-toolbar">
            <div><?php foreach ([7,30,90,365] as $range): ?><a class="button <?php echo $range===$days?'button-primary':''; ?>" href="<?php echo esc_url(self::admin_url($range)); ?>"><?php echo esc_html($range); ?> <?php esc_html_e('jours', 'delicat-google-login'); ?></a><?php endforeach; ?></div>
            <div><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_analytics_refresh&days='.$days), 'dip_analytics_refresh')); ?>"><?php esc_html_e('Actualiser', 'delicat-google-login'); ?></a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_analytics_export&days='.$days), 'dip_analytics_export')); ?>"><?php esc_html_e('Exporter CSV', 'delicat-google-login'); ?></a></div>
          </div>
          <div class="dip-an-cards">
          <?php $labels=['logins'=>'Connexions réussies','registrations'=>'Inscriptions','failed'=>'Échecs / blocages','providers'=>'Activité sociale','new_devices'=>'Nouveaux appareils','pairings'=>'Associations app','warnings'=>'Alertes']; foreach ($labels as $key=>$label): ?>
            <article><span><?php echo esc_html__($label,'delicat-google-login'); ?></span><strong><?php echo esc_html(number_format_i18n($metrics[$key])); ?></strong></article>
          <?php endforeach; ?>
          </div>
          <section class="dip-an-panel"><h2><?php esc_html_e('Tendance des connexions', 'delicat-google-login'); ?></h2>
            <div class="dip-an-chart" role="img" aria-label="<?php esc_attr_e('Connexions quotidiennes', 'delicat-google-login'); ?>">
            <?php foreach ($data['series'] as $date=>$events): $count=self::total_like($events,['login_success','mobile_biometric_success','mobile_session_issued']); $height=max(3,(int)round(($count/$max)*100)); ?>
              <div class="dip-an-bar-wrap" title="<?php echo esc_attr($date . ': ' . $count); ?>"><span class="dip-an-value"><?php echo esc_html($count); ?></span><div class="dip-an-bar" style="height:<?php echo esc_attr($height); ?>%"></div><small><?php echo esc_html(date_i18n('d/m', strtotime($date))); ?></small></div>
            <?php endforeach; ?>
            </div>
          </section>
          <section class="dip-an-panel"><h2><?php esc_html_e('Événements les plus fréquents', 'delicat-google-login'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Événement','delicat-google-login'); ?></th><th><?php esc_html_e('Total','delicat-google-login'); ?></th></tr></thead><tbody>
          <?php arsort($data['totals']); foreach (array_slice($data['totals'],0,15,true) as $event=>$count): ?><tr><td><code><?php echo esc_html($event); ?></code></td><td><?php echo esc_html(number_format_i18n($count)); ?></td></tr><?php endforeach; ?>
          </tbody></table></section>
          <p class="description"><?php echo esc_html($last ? sprintf(__('Dernière agrégation : %s','delicat-google-login'), date_i18n('Y-m-d H:i', $last)) : __('L’agrégation sera créée automatiquement.','delicat-google-login')); ?></p>
        </div>
        <style>
        .dip-an-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:18px 0}.dip-an-toolbar .button{margin-right:5px}.dip-an-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.dip-an-cards article,.dip-an-panel{background:#fff;border:1px solid #dcdcde;border-radius:14px;padding:18px;box-shadow:0 5px 20px rgba(0,0,0,.04)}.dip-an-cards span{display:block;color:#646970}.dip-an-cards strong{display:block;font-size:30px;line-height:1.2;margin-top:8px}.dip-an-panel{margin-top:18px}.dip-an-chart{height:260px;display:flex;align-items:flex-end;gap:5px;overflow-x:auto;padding:28px 4px 24px}.dip-an-bar-wrap{height:100%;min-width:28px;flex:1;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;position:relative}.dip-an-bar{width:72%;min-height:3px;background:linear-gradient(180deg,#2271b1,#135e96);border-radius:6px 6px 2px 2px}.dip-an-value{font-size:10px;margin-bottom:4px}.dip-an-bar-wrap small{position:absolute;bottom:-21px;font-size:9px;white-space:nowrap}@media(max-width:600px){.dip-an-chart{height:220px}.dip-an-bar-wrap{min-width:24px}.dip-an-cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
        </style>
        <?php
    }

    public static function refresh_now() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dip_analytics_refresh');
        $days = self::range_days();
        self::rollup($days + 2);
        wp_safe_redirect(self::admin_url($days)); exit;
    }

    public static function export_csv() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('dip_analytics_export');
        $days = self::range_days();
        $data = self::data($days);
        DIP_Audit::record('analytics_export_created', 'notice', get_current_user_id(), ['days'=>$days]);
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-analytics-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['date','event_type','total']);
        foreach ($data['series'] as $date=>$events) foreach ($events as $event=>$count) fputcsv($out, [$date,$event,$count]);
        fclose($out); exit;
    }

    private static function timeline($user_id, $limit = 30) {
        global $wpdb;
        if (!self::audit_table_exists()) return [];
        $limit = min(100,max(1,absint($limit)));
        return $wpdb->get_results($wpdb->prepare('SELECT created_at,event_type,severity,context FROM ' . DIP_Audit::table() . ' WHERE user_id=%d ORDER BY id DESC LIMIT %d', absint($user_id), $limit), ARRAY_A);
    }

    public static function user_timeline_profile($profile_user) {
        if (!current_user_can('list_users') || !($profile_user instanceof WP_User)) return;
        $events = self::timeline($profile_user->ID, 30);
        echo '<h2>' . esc_html__('Delicat Identity — Chronologie', 'delicat-google-login') . '</h2>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>' . esc_html__('Date','delicat-google-login') . '</th><th>' . esc_html__('Événement','delicat-google-login') . '</th><th>' . esc_html__('Niveau','delicat-google-login') . '</th><th>' . esc_html__('Détails','delicat-google-login') . '</th></tr></thead><tbody>';
        if (!$events) echo '<tr><td colspan="4">' . esc_html__('Aucun événement enregistré.','delicat-google-login') . '</td></tr>';
        foreach ($events as $event) {
            $context = json_decode((string)$event['context'], true); if (!is_array($context)) $context=[];
            $detail=[]; foreach ($context as $k=>$v) $detail[] = sanitize_key($k) . ': ' . sanitize_text_field((string)$v);
            echo '<tr><td>' . esc_html(get_date_from_gmt($event['created_at'], 'Y-m-d H:i')) . '</td><td><code>' . esc_html($event['event_type']) . '</code></td><td>' . esc_html($event['severity']) . '</td><td>' . esc_html(implode(' · ', $detail)) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">' . esc_html__('Les informations sensibles, adresses IP brutes et jetons ne sont jamais affichés ici.','delicat-google-login') . '</p>';
    }
}
