<?php
defined('ABSPATH') || exit;

/**
 * Phase 8: privacy-conscious identity analytics and customer timelines.
 * Uses aggregated audit data and never stores raw IP addresses or secrets.
 */
final class DIP_Analytics {
    const CRON_HOOK = 'dip_analytics_daily_rollup';
    const CACHE_GROUP = 'dip_analytics_';
    /** Daily rows « sign-ins by method » (derived from the events below). */
    const METHOD_PREFIX = 'signin_via_';

    /**
     * Each sign-in flow records exactly one of these events (social, password
     * and app sign-ins: login_success; web Passkey; 2FA challenge after Google,
     * Microsoft or an e-mail code; e-mail code; magic link; QR code from the
     * app). App token renewals (mobile_session_issued) are not sign-ins.
     */
    const SIGNIN_EVENTS = ['login_success','passkey_login_success','two_factor_challenge_success','passwordless_otp_login','magic_link_login','app_pairing_consumed'];

    /** @var array<int,bool> users whose password change was recorded in this request */
    private static $password_recorded = [];

    public static function init() {
        add_action('admin_post_dip_analytics_export', [__CLASS__, 'export_csv']);
        add_action('admin_post_dip_analytics_refresh', [__CLASS__, 'refresh_now']);
        add_action(self::CRON_HOOK, [__CLASS__, 'rollup']);
        add_action('show_user_profile', [__CLASS__, 'user_timeline_profile']);
        add_action('edit_user_profile', [__CLASS__, 'user_timeline_profile']);
        add_action('user_register', [__CLASS__, 'record_registration'], 20, 1);
        add_action('profile_update', [__CLASS__, 'record_profile_update'], 20, 2);
        // password_reset fires for WordPress and WooCommerce « mot de passe
        // oublié » resets alike (WooCommerce never fires after_password_reset).
        add_action('password_reset', [__CLASS__, 'record_password_reset'], 20, 2);
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

    /**
     * profile_update also fires for every WooCommerce customer save (each
     * checkout) with nothing changed: only a real change of the password or
     * of an account field is recorded. The analytics cache is not flushed
     * here (the rollup refreshes it).
     */
    public static function record_profile_update($user_id, $old_user_data = null) {
        $user_id = absint($user_id);
        $current = $user_id ? get_userdata($user_id) : false;
        if (!$current || !($old_user_data instanceof WP_User)) return;
        $old_hash = (string) $old_user_data->user_pass;
        if ($old_hash !== '' && !hash_equals($old_hash, (string) $current->user_pass)) self::record_password_changed($user_id);
        $changed = [];
        foreach (['user_email','user_login','display_name','user_url'] as $field) {
            if ((string) $old_user_data->$field !== (string) $current->$field) $changed[] = $field;
        }
        if ($changed) DIP_Audit::record('profile_updated', 'notice', $user_id, ['fields'=>implode(',', $changed)]);
    }

    public static function record_password_reset($user, $new_pass = '') {
        unset($new_pass);
        if ($user instanceof WP_User) self::record_password_changed((int) $user->ID);
    }

    /** One « mot de passe modifié » event per account and request. */
    private static function record_password_changed($user_id) {
        $user_id = absint($user_id);
        if (!$user_id || isset(self::$password_recorded[$user_id])) return;
        self::$password_recorded[$user_id] = true;
        DIP_Audit::record('password_changed', 'notice', $user_id);
    }

    /** Sign-in method of one sign-in event (its context), for the « par méthode » rows. */
    private static function signin_method($event, $context) {
        $direct = ['passkey_login_success'=>'passkey','passwordless_otp_login'=>'email_code','magic_link_login'=>'magic_link','app_pairing_consumed'=>'app_pairing'];
        if (isset($direct[$event])) return $direct[$event];
        $ctx = is_array($context) ? $context : json_decode((string) $context, true);
        $ctx = is_array($ctx) ? $ctx : [];
        $raw = sanitize_key((string) ($ctx['provider'] ?? ($ctx['method'] ?? '')));
        $map = [
            'password'=>'password','google'=>'google','google_social'=>'google','mobile_google'=>'google',
            'microsoft'=>'microsoft','microsoft_social'=>'microsoft','passkey'=>'passkey','mobile_passkey'=>'passkey',
            'mobile_biometric'=>'app_quick','email_otp'=>'email_code','magic_link'=>'magic_link','delicat_app_pairing'=>'app_pairing',
        ];
        return $map[$raw] ?? 'other';
    }

    /**
     * Daily totals from the security log. The log is purged by age at the
     * cron's time of day, so its oldest day is only partly present: a stored
     * total is never lowered (the 90/365-day views would otherwise keep the
     * partial count for good). Only totals that grew are written, in
     * multi-row statements.
     */
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
        $fresh = [];
        foreach ((array) $rows as $row) {
            $event = substr(sanitize_key($row['event_type']), 0, 64);
            if ($event === '' || strpos($event, self::METHOD_PREFIX) === 0) continue;
            $fresh[$row['metric_date'] . '|' . $event] = absint($row['total']);
            if (in_array($event, ['passkey_login_success','passwordless_otp_login','magic_link_login','app_pairing_consumed'], true)) {
                $key = $row['metric_date'] . '|' . self::METHOD_PREFIX . self::signin_method($event, null);
                $fresh[$key] = ($fresh[$key] ?? 0) + absint($row['total']);
            }
        }
        // The method of social, password and app sign-ins is in their context.
        $contexts = $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) metric_date, event_type, context, COUNT(*) total
             FROM {$audit} WHERE created_at >= %s AND event_type IN ('login_success','two_factor_challenge_success')
             GROUP BY DATE(created_at), event_type, context",
            $cutoff
        ), ARRAY_A);
        foreach ((array) $contexts as $row) {
            $key = $row['metric_date'] . '|' . self::METHOD_PREFIX . self::signin_method((string) $row['event_type'], $row['context']);
            $fresh[$key] = ($fresh[$key] ?? 0) + absint($row['total']);
        }
        $stored = [];
        $existing = $wpdb->get_results($wpdb->prepare("SELECT metric_date,event_type,total FROM {$daily} WHERE metric_date >= %s", substr($cutoff, 0, 10)), ARRAY_A);
        foreach ((array) $existing as $row) $stored[$row['metric_date'] . '|' . $row['event_type']] = absint($row['total']);
        $values = [];
        foreach ($fresh as $key => $total) {
            if ($total <= ($stored[$key] ?? 0)) continue;
            list($date, $event) = explode('|', $key, 2);
            $values[] = $wpdb->prepare('(%s,%s,%d)', $date, $event, $total);
        }
        foreach (array_chunk($values, 200) as $chunk) {
            $wpdb->query("INSERT INTO {$daily} (metric_date,event_type,total) VALUES " . implode(',', $chunk) . " ON DUPLICATE KEY UPDATE total=GREATEST(total,VALUES(total))"); // phpcs:ignore WordPress.DB.PreparedSQL -- values prepared above
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
        // The daily cron aggregates the whole window: a cache miss only
        // refreshes today and yesterday (all of it when the cron is late).
        $last = (int) get_option('dip_analytics_last_rollup', 0);
        self::rollup($last > time() - 2 * DAY_IN_SECONDS ? 2 : $days + 2);
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
        $result = ['days'=>$days,'totals'=>$totals,'series'=>$series,'generated'=>time(),'warnings'=>self::severity_count('warning', $days)];
        set_transient($cache_key, $result, 15 * MINUTE_IN_SECONDS);
        return $result;
    }

    /** Exact event names (substrings counted refusals and maintenance toggles as sign-ins). */
    private static function total_of(array $totals, array $events, array $prefixes = [], array $except = []) {
        $sum = 0;
        foreach ($totals as $event => $count) {
            $event = (string) $event;
            if (in_array($event, $except, true)) continue;
            $hit = in_array($event, $events, true);
            foreach ($prefixes as $prefix) if (!$hit && strpos($event, $prefix) === 0) $hit = true;
            if ($hit) $sum += (int) $count;
        }
        return $sum;
    }

    private static function metrics(array $data) {
        $t = $data['totals'];
        return [
            'logins' => self::total_of($t, self::SIGNIN_EVENTS),
            'registrations' => self::total_of($t, ['user_registered']),
            // Social refusals are recorded as login_<code>.
            'failed' => self::total_of($t, ['passkey_login_failed','passkey_login_rate_limited','two_factor_login_failed','two_factor_challenge_failed','passwordless_otp_failed','passwordless_otp_verify_throttled','mobile_passkey_login_failed','mobile_biometric_proof_failed','mobile_two_factor_failed','mobile_rate_limited','temporary_lockout','authentication_failed'], ['login_'], ['login_success','login_session_redelivered']),
            'providers' => self::total_of($t, [self::METHOD_PREFIX . 'google', self::METHOD_PREFIX . 'microsoft']),
            'new_devices' => self::total_of($t, ['new_device_login']),
            'pairings' => self::total_of($t, ['app_pairing_approved']),
            'warnings' => isset($data['warnings']) ? (int) $data['warnings'] : self::severity_count('warning', $data['days']),
        ];
    }

    private static function method_labels() {
        return [
            'password'=>__('Mot de passe', 'delicat-google-login'),'google'=>__('Google (site et application)', 'delicat-google-login'),
            'microsoft'=>__('Microsoft', 'delicat-google-login'),'passkey'=>__('Passkey (site et application)', 'delicat-google-login'),
            'app_quick'=>__('Connexion rapide de l’application', 'delicat-google-login'),'email_code'=>__('Code reçu par e-mail', 'delicat-google-login'),
            'magic_link'=>__('Lien de connexion par e-mail', 'delicat-google-login'),'app_pairing'=>__('QR code depuis l’application', 'delicat-google-login'),
            'other'=>__('Autre méthode', 'delicat-google-login'),
        ];
    }

    private static function severity_count($severity, $days) {
        global $wpdb;
        if (!self::audit_table_exists()) return 0;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (absint($days) * DAY_IN_SECONDS));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . DIP_Audit::table() . ' WHERE severity=%s AND created_at >= %s', sanitize_key($severity), $cutoff));
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
        foreach ($data['series'] as $day) $max = max($max, self::total_of($day, self::SIGNIN_EVENTS));
        $by_method = [];
        foreach ($data['totals'] as $event => $count) {
            if (strpos((string) $event, self::METHOD_PREFIX) === 0 && $count > 0) $by_method[substr((string) $event, strlen(self::METHOD_PREFIX))] = (int) $count;
        }
        arsort($by_method);
        $method_labels = self::method_labels();
        ?>
        <div class="wrap dip-analytics-wrap">
          <h1><?php esc_html_e('Delicat Identity Analytics', 'delicat-google-login'); ?></h1>
          <p><?php esc_html_e('Statistiques agrégées et respectueuses de la confidentialité. Aucune adresse IP brute ni aucun secret ne sont enregistrés.', 'delicat-google-login'); ?></p>
          <div class="dip-an-toolbar">
            <div><?php foreach ([7,30,90,365] as $range): ?><a class="button <?php echo $range===$days?'button-primary':''; ?>" href="<?php echo esc_url(self::admin_url($range)); ?>"><?php echo esc_html($range); ?> <?php esc_html_e('jours', 'delicat-google-login'); ?></a><?php endforeach; ?></div>
            <div><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_analytics_refresh&days='.$days), 'dip_analytics_refresh')); ?>"><?php esc_html_e('Actualiser', 'delicat-google-login'); ?></a> <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_analytics_export&days='.$days), 'dip_analytics_export')); ?>"><?php esc_html_e('Exporter CSV', 'delicat-google-login'); ?></a></div>
          </div>
          <div class="dip-an-cards">
          <?php $labels=['logins'=>'Connexions réussies','registrations'=>'Inscriptions','failed'=>'Échecs / blocages','providers'=>'Connexions Google / Microsoft','new_devices'=>'Nouveaux appareils','pairings'=>'Associations app','warnings'=>'Alertes']; foreach ($labels as $key=>$label): ?>
            <article><span><?php echo esc_html__($label,'delicat-google-login'); ?></span><strong><?php echo esc_html(number_format_i18n($metrics[$key])); ?></strong></article>
          <?php endforeach; ?>
          </div>
          <section class="dip-an-panel"><h2><?php esc_html_e('Tendance des connexions', 'delicat-google-login'); ?></h2>
            <div class="dip-an-chart" role="img" aria-label="<?php esc_attr_e('Connexions quotidiennes', 'delicat-google-login'); ?>">
            <?php foreach ($data['series'] as $date=>$events): $count=self::total_of($events,self::SIGNIN_EVENTS); $height=max(3,(int)round(($count/$max)*100)); ?>
              <div class="dip-an-bar-wrap" title="<?php echo esc_attr($date . ': ' . $count); ?>"><span class="dip-an-value"><?php echo esc_html($count); ?></span><div class="dip-an-bar" style="height:<?php echo esc_attr($height); ?>%"></div><small><?php echo esc_html(date_i18n('d/m', strtotime($date))); ?></small></div>
            <?php endforeach; ?>
            </div>
          </section>
          <?php if ($by_method): ?><section class="dip-an-panel"><h2><?php esc_html_e('Connexions par méthode', 'delicat-google-login'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Méthode','delicat-google-login'); ?></th><th><?php esc_html_e('Total','delicat-google-login'); ?></th></tr></thead><tbody>
          <?php foreach ($by_method as $method=>$count): ?><tr><td><?php echo esc_html($method_labels[$method] ?? $method_labels['other']); ?></td><td><?php echo esc_html(number_format_i18n($count)); ?></td></tr><?php endforeach; ?>
          </tbody></table><p class="description"><?php esc_html_e('Les journées agrégées avant cette mise à jour ne détaillent pas la méthode de connexion.', 'delicat-google-login'); ?></p></section><?php endif; ?>
          <section class="dip-an-panel"><h2><?php esc_html_e('Événements les plus fréquents', 'delicat-google-login'); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e('Événement','delicat-google-login'); ?></th><th><?php esc_html_e('Total','delicat-google-login'); ?></th></tr></thead><tbody>
          <?php $top = array_filter($data['totals'], function ($event) { return strpos((string) $event, DIP_Analytics::METHOD_PREFIX) !== 0; }, ARRAY_FILTER_USE_KEY); arsort($top); foreach (array_slice($top,0,15,true) as $event=>$count): ?><tr><td><code><?php echo esc_html($event); ?></code></td><td><?php echo esc_html(number_format_i18n($count)); ?></td></tr><?php endforeach; ?>
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
        fputcsv($out, ['date','event_type','total'], ',', '"', '\\');
        foreach ($data['series'] as $date=>$events) foreach ($events as $event=>$count) fputcsv($out, [$date,$event,$count], ',', '"', '\\');
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
