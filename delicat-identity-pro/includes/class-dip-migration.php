<?php
defined('ABSPATH') || exit;

final class DIP_Migration {
    const REPORT_OPTION = 'dip_migration_report_v1';
    const BACKUPS_OPTION = 'dip_settings_backups_v1';
    const DRY_RUN_OPTION = 'dip_migration_dry_run_v2';
    const STATE_OPTION = 'dip_migration_state_v2';
    const NEXTEND_PLUGIN = 'nextend-facebook-connect/nextend-facebook-connect.php';
    const AUTO_OPTION = 'dip_migration_auto_v1';
    const CRON_HOOK = 'dip_automatic_migration_tick';
    const FINAL_OPTION = 'dip_migration_final_v1';

    public static function plugin_status() {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $file = WP_PLUGIN_DIR . '/' . self::NEXTEND_PLUGIN;
        if (is_plugin_active(self::NEXTEND_PLUGIN)) return 'active';
        if (file_exists($file)) return 'installed';
        return 'missing';
    }

    private static function nextend_schema() {
        global $wpdb;
        $table = $wpdb->prefix . 'social_users';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);
        $columns = array_values(array_filter(array_map('sanitize_key', is_array($columns) ? $columns : [])));
        $pick = static function(array $choices) use ($columns) {
            foreach ($choices as $choice) if (in_array($choice, $columns, true)) return $choice;
            return '';
        };
        return [
            'table' => $table,
            'columns' => $columns,
            'user_col' => $pick(['id','user_id','wp_user_id']),
            'provider_col' => $pick(['type','provider']),
            'identifier_col' => $pick(['identifier','provider_id','social_id','subject']),
        ];
    }

    private static function nextend_rows() {
        global $wpdb;
        $schema = self::nextend_schema();
        if (empty($schema['table']) || empty($schema['user_col']) || empty($schema['provider_col']) || empty($schema['identifier_col'])) return [];
        $table = $schema['table'];
        $u = $schema['user_col']; $p = $schema['provider_col']; $i = $schema['identifier_col'];
        $sql = "SELECT `{$u}` AS user_id, `{$i}` AS identifier FROM `{$table}` WHERE LOWER(`{$p}`) = 'google' ORDER BY `{$u}` ASC";
        $rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers come from inspected schema allowlist.
        return is_array($rows) ? $rows : [];
    }


    /**
     * Resolve an immutable Google subject through Nextend's own provider link.
     * This is intentionally not an e-mail lookup: the exact provider identifier
     * must map to exactly one existing WordPress user.
     *
     * @return int|WP_Error 0 when no Nextend link exists.
     */
    public static function nextend_google_owner_by_subject($subject) {
        global $wpdb;
        $subject = trim((string) $subject);
        if ($subject === '' || strlen($subject) > 255) return 0;
        $ids = [];

        $schema = self::nextend_schema();
        if (!empty($schema['table']) && !empty($schema['user_col']) && !empty($schema['provider_col']) && !empty($schema['identifier_col'])) {
            $table = $schema['table']; $u = $schema['user_col']; $p = $schema['provider_col']; $i = $schema['identifier_col'];
            $sql = $wpdb->prepare("SELECT `{$u}` AS user_id FROM `{$table}` WHERE LOWER(`{$p}`) = 'google' AND `{$i}` = %s LIMIT 3", $subject); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers come from inspected schema allowlist.
            $rows = $wpdb->get_col($sql); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared above; identifiers are schema allowlisted.
            $ids = array_merge($ids, array_map('absint', is_array($rows) ? $rows : []));
        }

        // Older Nextend installations may expose the provider identifier through
        // a plugin-specific usermeta key instead of (or before creating) the
        // social_users table. Only exact Nextend-specific keys are accepted.
        if (self::plugin_status() !== 'missing') {
            $meta_rows = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_value = %s AND meta_key IN ('nsl_google_user_id','nextend_google_user_id') LIMIT 3",
                $subject
            ));
            $ids = array_merge($ids, array_map('absint', is_array($meta_rows) ? $meta_rows : []));
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (count($ids) > 1) return new WP_Error('duplicate_nextend_google_identity', 'Cette identité Google est liée à plusieurs comptes Nextend.');
        if (!$ids) return 0;
        return get_userdata($ids[0]) ? (int) $ids[0] : 0;
    }

    public static function nextend_google_link_matches($user_id, $subject) {
        $owner = self::nextend_google_owner_by_subject($subject);
        return !is_wp_error($owner) && absint($owner) > 0 && absint($owner) === absint($user_id);
    }

    public static function scan() {
        global $wpdb;
        $known_keys = ['nsl_google_user_id','nextend_google_user_id','google_user_id'];
        $known = [];
        foreach ($known_keys as $key) {
            $known[$key] = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", $key));
        }
        $patterns = ['%nextend%google%', '%nsl%google%', '%google%identifier%'];
        $discovered = [];
        foreach ($patterns as $pattern) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_key, COUNT(DISTINCT user_id) AS users_count FROM {$wpdb->usermeta} WHERE meta_key LIKE %s AND meta_value <> '' GROUP BY meta_key ORDER BY users_count DESC LIMIT 25", $pattern), ARRAY_A);
            foreach ($rows as $row) { $key = sanitize_key($row['meta_key']); if ($key) $discovered[$key] = max((int)($discovered[$key] ?? 0), (int)$row['users_count']); }
        }
        $schema = self::nextend_schema();
        $table_found = !empty($schema);
        $table_candidates = count(self::nextend_rows());
        $dip_duplicates = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM (SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value HAVING COUNT(DISTINCT user_id) > 1) d", DIP_Identity::META_SUB));
        $orphaned = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} um LEFT JOIN {$wpdb->users} u ON u.ID = um.user_id WHERE um.meta_key = %s AND u.ID IS NULL", DIP_Identity::META_SUB));
        $linked = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value <> ''", DIP_Identity::META_SUB));
        $email_conflicts = (int) $wpdb->get_var("SELECT COUNT(*) FROM (SELECT LOWER(user_email) email_key FROM {$wpdb->users} WHERE user_email <> '' GROUP BY LOWER(user_email) HAVING COUNT(*) > 1) e");
        $nextend_candidates = max($table_candidates, array_sum($known));
        foreach ($discovered as $key => $count) if (!isset($known[$key])) $nextend_candidates = max($nextend_candidates, $count);
        $critical = $dip_duplicates + $orphaned + $email_conflicts;
        $report = [
            'version'=>2,'created_at'=>time(),'nextend_status'=>self::plugin_status(),'nextend_candidate_links'=>$nextend_candidates,
            'nextend_table_found'=>$table_found,'nextend_table_candidates'=>$table_candidates,'nextend_schema_complete'=>!empty($schema['user_col'])&&!empty($schema['provider_col'])&&!empty($schema['identifier_col']),
            'detected_sources'=>array_values(array_filter([array_sum($known)?'Métadonnées utilisateur connues':'',$discovered?'Métadonnées découvertes':'',$table_found?'Table social_users Nextend':''])),
            'known_meta_keys'=>$known,'discovered_meta_keys'=>$discovered,'dip_linked_accounts'=>$linked,'duplicate_google_identities'=>$dip_duplicates,
            'orphaned_references'=>$orphaned,'duplicate_email_groups'=>$email_conflicts,'critical_issues'=>$critical,'ready'=>$critical===0 && $table_found,
            'notes'=>['The scan is read-only and never merges, deletes, or changes users.','The dry run validates every Nextend Google row before any mapping is written.','Only unambiguous mappings are eligible for staged migration.'],
        ];
        update_option(self::REPORT_OPTION,$report,false); do_action('dip_migration_scan_completed',$report); return $report;
    }

    private static function is_privileged($user_id) {
        $user = get_userdata($user_id); if (!$user) return false;
        return user_can($user, 'manage_options') || user_can($user, 'manage_woocommerce') || in_array('editor',(array)$user->roles,true);
    }

    public static function dry_run() {
        global $wpdb;
        $rows = self::nextend_rows();
        $identifier_counts = [];
        foreach ($rows as $row) { $id = trim((string)($row['identifier'] ?? '')); if ($id !== '') $identifier_counts[$id] = ($identifier_counts[$id] ?? 0) + 1; }
        $result = ['created_at'=>time(),'total'=>count($rows),'ready'=>0,'already_linked'=>0,'manual_review'=>0,'missing_user'=>0,'missing_identifier'=>0,'duplicate_identifier'=>0,'privileged'=>0,'conflicting_link'=>0,'issues'=>[]];
        foreach ($rows as $row) {
            $uid = absint($row['user_id'] ?? 0); $identifier = trim((string)($row['identifier'] ?? ''));
            $reason = '';
            if (!$uid || !get_userdata($uid)) { $reason='missing_user'; }
            elseif ($identifier === '') { $reason='missing_identifier'; }
            elseif (($identifier_counts[$identifier] ?? 0) > 1) { $reason='duplicate_identifier'; }
            elseif (self::is_privileged($uid)) { $reason='privileged'; }
            else {
                $current = (string)get_user_meta($uid,DIP_Identity::META_SUB,true);
                if ($current === $identifier) { $result['already_linked']++; continue; }
                if ($current !== '' && $current !== $identifier) { $reason='conflicting_link'; }
                else {
                    $other = (int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s AND user_id<>%d LIMIT 1",DIP_Identity::META_SUB,$identifier,$uid));
                    if ($other) $reason='duplicate_identifier';
                }
            }
            if ($reason) {
                $result[$reason]++; $result['manual_review']++;
                if (count($result['issues']) < 100) $result['issues'][]=['user_id'=>$uid,'reason'=>$reason];
            } else $result['ready']++;
        }
        update_option(self::DRY_RUN_OPTION,$result,false); return $result;
    }

    public static function dry_run_report() { $r=get_option(self::DRY_RUN_OPTION,[]); return is_array($r)?$r:[]; }
    public static function state() { $s=get_option(self::STATE_OPTION,[]); return is_array($s)?$s:[]; }

    public static function migrate(array $selected_user_ids = [], $batch_size = 25) {
        global $wpdb;
        $dry=self::dry_run_report(); if (!$dry || empty($dry['created_at'])) return new WP_Error('dry_run_required','Exécutez d’abord la simulation.');
        if (!self::backups()) return new WP_Error('backup_required','Créez une sauvegarde de configuration avant la migration.');
        $rows=self::nextend_rows(); $selected=array_values(array_filter(array_map('absint',$selected_user_ids)));
        $state=self::state(); if (!$state) $state=['started_at'=>time(),'migrated'=>[],'skipped'=>[],'mode'=>$selected?'test':'bulk','completed'=>false];
        $processed=0;
        foreach ($rows as $row) {
            $uid=absint($row['user_id']??0); $identifier=trim((string)($row['identifier']??''));
            if ($selected && !in_array($uid,$selected,true)) continue;
            if (isset($state['migrated'][$uid]) || isset($state['skipped'][$uid])) continue;
            if ($processed >= max(1,min(100,$batch_size))) break;
            $processed++;
            $reason='';
            if (!$uid || !get_userdata($uid)) $reason='missing_user';
            elseif ($identifier==='') $reason='missing_identifier';
            elseif (self::is_privileged($uid)) $reason='privileged';
            else {
                $current=(string)get_user_meta($uid,DIP_Identity::META_SUB,true);
                if ($current!=='' && $current!==$identifier) $reason='conflicting_link';
                $other=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value=%s AND user_id<>%d LIMIT 1",DIP_Identity::META_SUB,$identifier,$uid));
                if ($other) $reason='duplicate_identifier';
            }
            if ($reason) { $state['skipped'][$uid]=['reason'=>$reason,'at'=>time()]; continue; }
            $previous=(string)get_user_meta($uid,DIP_Identity::META_SUB,true);
            update_user_meta($uid,DIP_Identity::META_SUB,$identifier);
            if (!get_user_meta($uid,DIP_Identity::META_LINKED_AT,true)) update_user_meta($uid,DIP_Identity::META_LINKED_AT,time());
            $state['migrated'][$uid]=['identifier_hash'=>hash_hmac('sha256',$identifier,wp_salt('auth')),'previous'=>$previous,'at'=>time()];
            do_action('dip_account_linked',$uid,'google');
        }
        $eligible_total=(int)($dry['ready']??0);
        $terminal=0;
        foreach ($rows as $check_row) {
            $check_uid=absint($check_row['user_id']??0); $check_identifier=trim((string)($check_row['identifier']??''));
            if (isset($state['migrated'][$check_uid]) || isset($state['skipped'][$check_uid])) { $terminal++; continue; }
            if ($check_uid && $check_identifier !== '' && (string)get_user_meta($check_uid,DIP_Identity::META_SUB,true) === $check_identifier) $terminal++;
        }
        $state['updated_at']=time();
        $state['completed']=!$selected && count($rows)>0 && $terminal >= count($rows);
        $state['eligible_total']=$eligible_total;
        $state['source_total']=count($rows);
        $state['terminal_total']=$terminal;
        update_option(self::STATE_OPTION,$state,false); return $state;
    }

    public static function automatic_state() {
        $state=get_option(self::AUTO_OPTION,[]);
        return is_array($state)?$state:[];
    }

    private static function schedule_tick($delay=20) {
        if (!wp_next_scheduled(self::CRON_HOOK)) wp_schedule_single_event(time()+max(10,absint($delay)),self::CRON_HOOK);
    }

    public static function start_automatic($batch_size=25) {
        $report=self::scan();
        if (empty($report['ready'])) return new WP_Error('migration_not_ready','La migration automatique est bloquée jusqu’à la résolution des contrôles critiques.');
        if (!is_ssl()) return new WP_Error('https_required','HTTPS est obligatoire avant la migration automatique.');
        if (!self::backups()) self::create_backup('Automatic migration safety backup');
        $dry=self::dry_run();
        if (empty($dry['total'])) return new WP_Error('no_accounts','Aucun compte Google Nextend n’a été trouvé.');
        $batch_size=max(5,min(50,absint($batch_size)));
        $auto=[
            'status'=>'running','started_at'=>time(),'updated_at'=>time(),'batch_size'=>$batch_size,
            'total'=>(int)($dry['total']??0),'ready'=>(int)($dry['ready']??0),'already_linked'=>(int)($dry['already_linked']??0),
            'manual_review'=>(int)($dry['manual_review']??0),'last_error'=>'','completed_at'=>0,
        ];
        update_option(self::AUTO_OPTION,$auto,false);
        self::schedule_tick(10);
        do_action('dip_automatic_migration_started',$auto);
        return $auto;
    }

    public static function pause_automatic($reason='Paused by administrator') {
        $auto=self::automatic_state();
        $auto['status']='paused'; $auto['updated_at']=time(); $auto['pause_reason']=sanitize_text_field($reason);
        update_option(self::AUTO_OPTION,$auto,false);
        $timestamp=wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp,self::CRON_HOOK);
        do_action('dip_automatic_migration_paused',$auto);
        return $auto;
    }

    public static function resume_automatic() {
        $auto=self::automatic_state();
        if (!$auto) return self::start_automatic(25);
        if (($auto['status']??'')==='completed') return new WP_Error('already_completed','La migration automatique est déjà terminée.');
        $auto['status']='running'; $auto['updated_at']=time(); $auto['last_error']=''; unset($auto['pause_reason']);
        update_option(self::AUTO_OPTION,$auto,false); self::schedule_tick(10);
        return $auto;
    }

    public static function maybe_run_automatic_fallback() {
        $auto=self::automatic_state();
        if (($auto['status']??'')!=='running') return;
        if (!wp_next_scheduled(self::CRON_HOOK)) self::schedule_tick(20);
        if ((time()-(int)($auto['updated_at']??0)) < 120) return;
        if (get_transient('dip_auto_migration_request_gate')) return;
        set_transient('dip_auto_migration_request_gate',1,90);
        self::automatic_tick();
    }

    public static function automatic_tick() {
        $auto=self::automatic_state();
        if (($auto['status']??'')!=='running') return;
        if (get_transient('dip_auto_migration_lock')) { self::schedule_tick(60); return; }
        set_transient('dip_auto_migration_lock',1,120);
        try {
            $report=self::report();
            if (!$report || !empty($report['critical_issues']) || empty($report['nextend_table_found'])) {
                $auto['status']='paused'; $auto['last_error']='Security pre-flight checks are no longer healthy.'; $auto['updated_at']=time();
                update_option(self::AUTO_OPTION,$auto,false);
                do_action('dip_automatic_migration_blocked',$auto);
                return;
            }
            $result=self::migrate([],max(5,min(50,absint($auto['batch_size']??25))));
            if (is_wp_error($result)) {
                $auto['status']='paused'; $auto['last_error']=$result->get_error_message(); $auto['updated_at']=time();
                update_option(self::AUTO_OPTION,$auto,false);
                do_action('dip_automatic_migration_blocked',$auto);
                return;
            }
            $auto['updated_at']=time();
            $auto['migrated']=count((array)($result['migrated']??[]));
            $auto['skipped']=count((array)($result['skipped']??[]));
            $auto['terminal_total']=(int)($result['terminal_total']??0);
            $auto['total']=(int)($result['source_total']??($auto['total']??0));
            if (!empty($result['completed'])) {
                $auto['status']='completed'; $auto['completed_at']=time();
                update_option(self::AUTO_OPTION,$auto,false);
                do_action('dip_automatic_migration_completed',$auto);
            } else {
                update_option(self::AUTO_OPTION,$auto,false);
                self::schedule_tick(45);
            }
        } finally { delete_transient('dip_auto_migration_lock'); }
    }


    public static function skipped_accounts() {
        $state = self::state();
        $items = [];
        foreach ((array) ($state['skipped'] ?? []) as $user_id => $entry) {
            $user_id = absint($user_id);
            $user = $user_id ? get_userdata($user_id) : false;
            $items[] = [
                'user_id' => $user_id,
                'display_name' => $user ? $user->display_name : 'Utilisateur introuvable',
                'masked_email' => $user && is_email($user->user_email) ? self::mask_email($user->user_email) : '',
                'reason' => sanitize_key((string) ($entry['reason'] ?? 'unknown')),
                'at' => absint($entry['at'] ?? 0),
            ];
        }
        return $items;
    }

    private static function mask_email($email) {
        $parts = explode('@', strtolower((string) $email), 2);
        if (count($parts) !== 2) return '';
        $name = $parts[0];
        $visible = substr($name, 0, min(2, strlen($name)));
        return $visible . str_repeat('*', max(2, strlen($name) - strlen($visible))) . '@' . $parts[1];
    }

    public static function final_verification() {
        global $wpdb;
        $state = self::state();
        $rows = self::nextend_rows();
        $migrated = (array) ($state['migrated'] ?? []);
        $skipped = (array) ($state['skipped'] ?? []);
        $valid_links = 0;
        $invalid_links = 0;
        $users_with_orders = 0;
        $users_with_wallet_meta = 0;
        $users_with_addresses = 0;
        $duplicate_users_created = 0;

        $email_seen = [];
        foreach ($migrated as $user_id => $entry) {
            $user_id = absint($user_id);
            $user = $user_id ? get_userdata($user_id) : false;
            $current = $user_id ? (string) get_user_meta($user_id, DIP_Identity::META_SUB, true) : '';
            $expected_hash = (string) ($entry['identifier_hash'] ?? '');
            if ($user && $current !== '' && $expected_hash !== '' && hash_equals($expected_hash, hash_hmac('sha256', $current, wp_salt('auth')))) $valid_links++;
            else $invalid_links++;
            if (!$user) continue;
            $email_key = strtolower(trim($user->user_email));
            if ($email_key !== '') { $email_seen[$email_key] = ($email_seen[$email_key] ?? 0) + 1; }
            $wallet_keys = ['_current_woo_wallet_balance', 'woo_wallet_balance', 'tera_wallet_balance'];
            foreach ($wallet_keys as $wallet_key) { if (metadata_exists('user', $user_id, $wallet_key)) { $users_with_wallet_meta++; break; } }
            if (get_user_meta($user_id, 'billing_address_1', true) || get_user_meta($user_id, 'shipping_address_1', true)) $users_with_addresses++;
        }
        foreach ($email_seen as $count) if ($count > 1) $duplicate_users_created += ($count - 1);

        $report = self::report();
        $auto = self::automatic_state();
        $source_total = count($rows);
        $terminal = count($migrated) + count($skipped);
        $remaining = max(0, $source_total - $terminal);
        $healthy = $source_total > 0 && $remaining === 0 && $invalid_links === 0 && empty($report['critical_issues']);
        $result = [
            'created_at' => time(),
            'healthy' => $healthy,
            'source_total' => $source_total,
            'migrated' => count($migrated),
            'skipped' => count($skipped),
            'remaining' => $remaining,
            'valid_links' => $valid_links,
            'invalid_links' => $invalid_links,
            'users_with_orders' => $users_with_orders,
            'users_with_wallet_meta' => $users_with_wallet_meta,
            'users_with_addresses' => $users_with_addresses,
            'duplicate_users_created' => $duplicate_users_created,
            'automatic_status' => sanitize_key((string) ($auto['status'] ?? 'idle')),
            'nextend_status' => self::plugin_status(),
        ];
        update_option(self::FINAL_OPTION, $result, false);
        do_action('dip_migration_final_verification', $result);
        return $result;
    }

    public static function final_report() {
        $report = get_option(self::FINAL_OPTION, []);
        return is_array($report) ? $report : [];
    }

    public static function finalize() {
        $verification = self::final_verification();
        if (empty($verification['healthy'])) return new WP_Error('verification_failed', 'La vérification finale a détecté un problème. Consultez les détails avant la bascule.');
        $final = $verification;
        $final['finalized_at'] = time();
        $final['monitor_until'] = time() + (7 * DAY_IN_SECONDS);
        $final['status'] = 'monitoring';
        update_option(self::FINAL_OPTION, $final, false);
        do_action('dip_migration_finalized', $final);
        return $final;
    }

    public static function uninstall_automatic_schedule() {
        $timestamp=wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp,self::CRON_HOOK);
        delete_transient('dip_auto_migration_lock');
    }

    public static function rollback_wizard_mappings() {
        $state=self::state(); if (!$state || empty($state['migrated'])) return new WP_Error('nothing_to_rollback','Aucune liaison créée par l’assistant.');
        $rolled=0;
        foreach ($state['migrated'] as $uid=>$entry) {
            $uid=absint($uid); $current=(string)get_user_meta($uid,DIP_Identity::META_SUB,true);
            if (hash_equals((string)$entry['identifier_hash'],hash_hmac('sha256',$current,wp_salt('auth')))) {
                if (($entry['previous']??'')==='') delete_user_meta($uid,DIP_Identity::META_SUB); else update_user_meta($uid,DIP_Identity::META_SUB,(string)$entry['previous']);
                $rolled++;
            }
        }
        update_option(self::STATE_OPTION,['rolled_back_at'=>time(),'rolled_back'=>$rolled],false); return $rolled;
    }

    public static function report() { $r=get_option(self::REPORT_OPTION,[]); return is_array($r)?$r:[]; }
    public static function create_backup($label='') { $settings=get_option(DIP_Plugin::OPTION,[]); if(!is_array($settings))$settings=[]; $backups=get_option(self::BACKUPS_OPTION,[]); if(!is_array($backups))$backups=[]; $id=gmdate('YmdHis').'-'.wp_generate_password(6,false,false); $backups[$id]=['created_at'=>time(),'label'=>sanitize_text_field($label?:'Before Nextend migration'),'settings'=>$settings,'created_by'=>get_current_user_id()]; uasort($backups,static function($a,$b){return((int)$b['created_at'])<=>((int)$a['created_at']);}); update_option(self::BACKUPS_OPTION,array_slice($backups,0,5,true),false); return $id; }
    public static function backups(){ $b=get_option(self::BACKUPS_OPTION,[]); return is_array($b)?$b:[]; }
    public static function restore_backup($id){$b=self::backups();if(empty($b[$id]['settings'])||!is_array($b[$id]['settings']))return new WP_Error('backup_missing','Configuration backup not found.');update_option(DIP_Plugin::OPTION,$b[$id]['settings'],false);do_action('dip_migration_backup_restored',$id);return true;}
    public static function public_report(array $r){return['generated_at_utc'=>!empty($r['created_at'])?gmdate('c',(int)$r['created_at']):null,'plugin_version'=>defined('DIP_VERSION')?DIP_VERSION:'','site_host'=>wp_parse_url(home_url('/'),PHP_URL_HOST),'https'=>is_ssl(),'nextend_status'=>$r['nextend_status']??'unknown','nextend_candidate_links'=>(int)($r['nextend_candidate_links']??0),'nextend_table_found'=>!empty($r['nextend_table_found']),'nextend_table_candidates'=>(int)($r['nextend_table_candidates']??0),'delicat_linked_accounts'=>(int)($r['dip_linked_accounts']??0),'duplicate_google_identities'=>(int)($r['duplicate_google_identities']??0),'orphaned_references'=>(int)($r['orphaned_references']??0),'duplicate_email_groups'=>(int)($r['duplicate_email_groups']??0),'ready'=>!empty($r['ready']),'dry_run'=>self::dry_run_report(),'migration_state'=>array_diff_key(self::state(),['migrated'=>1]),'notes'=>(array)($r['notes']??[])];}
}
