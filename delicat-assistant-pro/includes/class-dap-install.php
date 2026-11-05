<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class DSA2_Install {
    const CAP_SUPPORT = 'manage_delicat_assistant';
    const CAP_SETTINGS = 'manage_delicat_assistant_settings';
    const ROLE_SUPPORT = 'delicat_support_agent';

    public static function maybe_upgrade() {
        // Roles can appear after this plugin (for example WooCommerce adds shop_manager).
        // Reconcile capabilities even when the database is already current so the menu is
        // not permanently hidden until the next Delicat release.
        self::install_roles();
        if ( get_option( 'dap_db_version' ) === DSA2_VERSION ) return;
        if ( get_transient( 'dsa2_upgrade_lock' ) ) return;
        set_transient( 'dsa2_upgrade_lock', 1, MINUTE_IN_SECONDS );
        $ready = self::install_schema();
        self::schedule_cleanup();
        if ( $ready ) delete_transient( 'dsa2_upgrade_lock' );
        // Every release so far has needed a manual LiteSpeed + Cloudflare purge, and until that
        // purge happened guests kept being served the previous version's cached page. That is
        // why the last two visual fixes looked like they had done nothing. Now it is automatic.
        add_action( 'init', array( __CLASS__, 'purge_caches' ), 99 );
    }

    /** Best-effort flush of whatever cache layers are installed. Each call is guarded. */
    public static function purge_caches() {
        do_action( 'litespeed_purge_all' );                       // LiteSpeed Cache 4+
        do_action( 'cloudflare_purge_everything' );               // Cloudflare plugin
        if ( function_exists( 'rocket_clean_domain' ) ) rocket_clean_domain();
        if ( function_exists( 'w3tc_flush_all' ) ) w3tc_flush_all();
        if ( function_exists( 'wp_cache_clear_cache' ) ) wp_cache_clear_cache();
        if ( class_exists( 'autoptimizeCache' ) && method_exists( 'autoptimizeCache', 'clearall' ) ) {
            autoptimizeCache::clearall();
        }
        wp_cache_flush();
    }
    public static function activate() {
        self::deactivate_legacy_standalone_versions();
        self::install_schema();
        self::install_roles();
        self::schedule_cleanup();
    }

    public static function schedule_cleanup() {
        if ( ! wp_next_scheduled( 'dsa2_daily_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsa2_daily_cleanup' );
        }
    }
    public static function unschedule_cleanup() {
        wp_clear_scheduled_hook( 'dsa2_daily_cleanup' );
        wp_clear_scheduled_hook( 'dap_enterprise_push_retry' );
        wp_clear_scheduled_hook( 'dsa2_mobile_push' );
        wp_clear_scheduled_hook( 'dsa2_admin_mobile_push' );
        wp_clear_scheduled_hook( 'dsa2_private_files_batch' );
        wp_clear_scheduled_hook( 'dsa2_send_alert_summary' );
    }

    /**
     * 7.1.1 -- one-time security change of stored settings (each one is in the release notes
     * so the owner can put it back in Settings):
     *  - staff phone app: off unless the owner saved the settings page with it on (a value only
     *    written by the defaults no longer counts); keys last 7 days at most, 2FA required;
     *  - team alert e-mails: a stored 0 (one e-mail per message) becomes 120 seconds, and at
     *    most 30 alerts an hour, then one summary.
     * Runs once (option flag), whatever the update path.
     */
    public static function migrate_711() {
        if ( get_option( 'dap_711_migrated' ) ) return;
        $raw      = get_option( 'dap_settings', null );
        $upgrade  = is_array( $raw ) && $raw;
        $settings = is_array( $raw ) ? $raw : array();
        // Only the settings form writes this key (since 6.6.1): its presence means the owner saved
        // the page, so the app switch they saw is their own choice. Otherwise the 1 came from the
        // defaults that earlier versions wrote on install.
        $owner_saved = array_key_exists( 'training_corpus_enabled', $settings );
        $app_on      = $owner_saved && ! empty( $settings['admin_app_enabled'] );
        $settings['admin_app_enabled'] = $app_on ? 1 : 0;
        if ( ! array_key_exists( 'admin_app_require_2fa', $settings ) ) $settings['admin_app_require_2fa'] = 1;
        $days = isset( $settings['admin_session_days'] ) ? (int) $settings['admin_session_days'] : 7;
        $settings['admin_session_days'] = max( 1, min( DSA2_STAFF_SESSION_MAX_DAYS, $days > 0 ? $days : DSA2_STAFF_SESSION_MAX_DAYS ) );
        if ( ! isset( $settings['notify_throttle'] ) || (int) $settings['notify_throttle'] < 1 ) $settings['notify_throttle'] = 120;
        if ( ! isset( $settings['notify_hourly_cap'] ) ) $settings['notify_hourly_cap'] = 30;
        if ( ! isset( $settings['image_retention_days'] ) ) $settings['image_retention_days'] = 0;
        update_option( 'dap_settings', $settings );

        global $wpdb;
        $sessions = $wpdb->prefix . 'dap_admin_sessions';
        if ( $app_on ) {
            // Keys issued before this version: valid 7 days after sign-in at most.
            $wpdb->query( $wpdb->prepare( "UPDATE $sessions SET expires_at=LEAST(expires_at, DATE_ADD(created_at, INTERVAL %d DAY))", DSA2_STAFF_SESSION_MAX_DAYS ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        } else {
            // The app is off: sign every phone out, so switching it back on later needs a new sign-in (with 2FA).
            $wpdb->query( "DELETE FROM $sessions" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        }
        // "Souvent pris ensemble" lists cached with the first viewer's prices (RSL-01): drop them.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_dap\\_reco\\_503\\_%' OR option_name LIKE '\\_transient\\_timeout\\_dap\\_reco\\_503\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        // The old customer API's push retry queue has nothing left to send (AST-06).
        wp_clear_scheduled_hook( 'dap_enterprise_push_retry' );
        if ( $upgrade ) update_option( 'dap_711_app_notice', $app_on ? 'kept' : 'off', false );
        update_option( 'dap_711_migrated', gmdate( 'c' ), false );
        // Payment screenshots already in the public uploads folder move to the private folder in batches.
        if ( ! wp_next_scheduled( 'dsa2_private_files_batch' ) ) wp_schedule_single_event( time() + 60, 'dsa2_private_files_batch' );
    }

    /** Prunes old resolved guest conversations so the tables stay small on shared hosting. */
    public static function cleanup() {
        $s = get_option( 'dap_settings', array() );
        $days = isset( $s['retention_days'] ) ? (int) $s['retention_days'] : 90;
        global $wpdb;
        $conv = $wpdb->prefix . 'dap_conversations';
        $msg  = $wpdb->prefix . 'dap_messages';
        $sessions = $wpdb->prefix . 'dap_mobile_sessions';
        $devices  = $wpdb->prefix . 'dap_mobile_devices';
        $admin_sessions = $wpdb->prefix . 'dap_admin_sessions';
        // Expired app sessions must not leave push tokens receiving account messages.
        $expired_installations = (array) $wpdb->get_col( "SELECT installation_id FROM $sessions WHERE expires_at<=UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        foreach ( $expired_installations as $installation_id ) delete_option( 'dap_mobile_prefs_' . (string) $installation_id );
        $wpdb->query( "DELETE FROM $sessions WHERE expires_at<=UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DELETE FROM $admin_sessions WHERE expires_at<=UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( "DELETE d FROM $devices d LEFT JOIN $sessions s ON s.installation_id=d.installation_id WHERE d.installation_id<>'' AND s.installation_id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( class_exists( 'DSA2_Groups', false ) ) DSA2_Groups::cleanup();
        // 7.1.1: payment screenshots. Old public files move to the private folder (a batch a day
        // even if the one-off batches were missed); the retention setting (0 = keep, the default)
        // removes the private files older than that many days.
        if ( class_exists( 'DSA2_Data', false ) ) {
            DSA2_Data::migrate_public_images( 50 );
            $image_days = isset( $s['image_retention_days'] ) ? (int) $s['image_retention_days'] : 0;
            if ( $image_days > 0 ) DSA2_Data::expire_private_images( $image_days );
        }
        if ( $days < 1 ) return; // 0 disables conversation pruning, as stated in Settings.
        // last_message_at is written with current_time('mysql'), so the cutoff must be site
        // time too — gmdate() shifted the window by the timezone offset (5 h in Haiti).
        $cutoff = gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS ) );
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT c.id FROM $conv c WHERE c.user_id=0 AND c.last_message_at<%s AND (c.status='resolved' OR NOT EXISTS (SELECT 1 FROM $msg m WHERE m.conversation_id=c.id)) AND NOT EXISTS (SELECT 1 FROM $sessions s WHERE s.conversation_id=c.id AND s.expires_at>UTC_TIMESTAMP()) LIMIT 500", $cutoff
        ) );
        if ( ! $ids ) return;
        $in = implode( ',', array_map( 'absint', $ids ) );
        // Remove the uploaded screenshots before the rows that point at them.
        if ( class_exists( 'DSA2_Data' ) ) DSA2_Data::delete_conversation_images( $ids );
        foreach ( $ids as $conversation_id ) delete_option( 'dap_internal_notes_' . absint( $conversation_id ) );
        $tickets = get_posts( array(
            'post_type'=>'dap_ticket', 'post_status'=>'any', 'fields'=>'ids', 'posts_per_page'=>-1,
            'meta_query'=>array(array('key'=>'dap_conversation_id','value'=>array_map('absint',$ids),'compare'=>'IN','type'=>'NUMERIC')),
        ) );
        foreach ( (array) $tickets as $ticket_id ) wp_delete_post( $ticket_id, true );
        $wpdb->query( "DELETE FROM $devices WHERE conversation_id IN ($in)" );
        $wpdb->query( "DELETE FROM $sessions WHERE conversation_id IN ($in)" );
        $wpdb->query( "DELETE FROM $msg WHERE conversation_id IN ($in)" );
        $wpdb->query( "DELETE FROM $conv WHERE id IN ($in)" );
    }

    private static function install_schema() {
        global $wpdb;
        $previous_db_version = (string) get_option( 'dap_db_version', '' );
        $charset = $wpdb->get_charset_collate();
        $conv = $wpdb->prefix . 'dap_conversations';
        $msg  = $wpdb->prefix . 'dap_messages';
        $faq  = $wpdb->prefix . 'dap_faq';
        $mobile_sessions = $wpdb->prefix . 'dap_mobile_sessions';
        $mobile_devices = $wpdb->prefix . 'dap_mobile_devices';
        $admin_sessions = $wpdb->prefix . 'dap_admin_sessions';
        $groups         = $wpdb->prefix . 'dap_groups';
        $group_members  = $wpdb->prefix . 'dap_group_members';
        $group_messages = $wpdb->prefix . 'dap_group_messages';
        $group_reports  = $wpdb->prefix . 'dap_group_reports';
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( "CREATE TABLE $conv (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_key VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            customer_name VARCHAR(190) NOT NULL DEFAULT '',
            customer_email VARCHAR(190) NOT NULL DEFAULT '',
            customer_phone VARCHAR(40) NOT NULL DEFAULT '',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            language VARCHAR(10) NOT NULL DEFAULT 'fr',
            assigned_to BIGINT UNSIGNED NOT NULL DEFAULT 0,
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            last_customer_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_agent_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_agent_message_at DATETIME NULL,
            last_message_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY session_key (session_key),
            KEY user_id (user_id),
            KEY status (status),
            KEY assigned_to (assigned_to),
            KEY last_message_at (last_message_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE $msg (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            sender VARCHAR(20) NOT NULL,
            sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            channel VARCHAR(20) NOT NULL DEFAULT 'bot',
            body LONGTEXT NOT NULL,
            message_type VARCHAR(30) NOT NULL DEFAULT 'text',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id),
            KEY created_at (created_at),
            KEY conversation_type (conversation_id,message_type),
            KEY conversation_channel (conversation_id,channel,id),
            KEY channel_sender (channel,sender,id)
        ) $charset;" );

        dbDelta( "CREATE TABLE $faq (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legacy_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            keywords LONGTEXT NULL,
            question TEXT NULL,
            answer_fr LONGTEXT NULL,
            answer_en LONGTEXT NULL,
            answer_ht LONGTEXT NULL,
            buttons LONGTEXT NULL,
            active TINYINT NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY legacy_id (legacy_id)
        ) $charset;" );

        dbDelta( "CREATE TABLE $mobile_sessions (
            installation_id VARCHAR(100) NOT NULL,
            conversation_id BIGINT UNSIGNED NOT NULL,
            token_hash CHAR(64) NOT NULL,
            language VARCHAR(10) NOT NULL DEFAULT 'fr',
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (installation_id),
            UNIQUE KEY token_hash (token_hash),
            KEY conversation_id (conversation_id)
        ) $charset;" );
        dbDelta( "CREATE TABLE $mobile_devices (
            fcm_token VARCHAR(4096) NOT NULL,
            installation_id VARCHAR(100) NOT NULL DEFAULT '',
            conversation_id BIGINT UNSIGNED NOT NULL,
            platform VARCHAR(20) NOT NULL DEFAULT 'android',
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (fcm_token(191)),
            KEY conversation_id (conversation_id),
            KEY installation_id (installation_id)
        ) $charset;" );
        dbDelta( "CREATE TABLE $admin_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            installation_id VARCHAR(100) NOT NULL,
            token_hash CHAR(64) NOT NULL,
            fcm_token VARCHAR(4096) NOT NULL DEFAULT '',
            platform VARCHAR(20) NOT NULL DEFAULT 'android',
            device_label VARCHAR(120) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY user_installation (user_id,installation_id),
            KEY user_id (user_id),
            KEY expires_at (expires_at)
        ) $charset;" );
        // v6.6.0 — community groups.
        dbDelta( "CREATE TABLE $groups (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            slug VARCHAR(120) NOT NULL,
            name VARCHAR(190) NOT NULL,
            description VARCHAR(255) NOT NULL DEFAULT '',
            icon VARCHAR(16) NOT NULL DEFAULT '',
            visibility VARCHAR(20) NOT NULL DEFAULT 'public',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            locked TINYINT NOT NULL DEFAULT 0,
            slow_mode SMALLINT UNSIGNED NOT NULL DEFAULT 5,
            member_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            message_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_message_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY listing (status,visibility,sort_order)
        ) $charset;" );
        dbDelta( "CREATE TABLE $group_members (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'member',
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            last_read_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            muted_until DATETIME NULL,
            joined_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY group_user (group_id,user_id),
            KEY user_id (user_id),
            KEY group_status (group_id,status)
        ) $charset;" );
        dbDelta( "CREATE TABLE $group_messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            author_role VARCHAR(20) NOT NULL DEFAULT 'member',
            body LONGTEXT NOT NULL,
            message_type VARCHAR(20) NOT NULL DEFAULT 'text',
            meta LONGTEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'visible',
            flags VARCHAR(190) NOT NULL DEFAULT '',
            report_count INT UNSIGNED NOT NULL DEFAULT 0,
            pinned TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY group_id (group_id,id),
            KEY group_status (group_id,status,id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE $group_reports (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id BIGINT UNSIGNED NOT NULL,
            group_id BIGINT UNSIGNED NOT NULL,
            reporter_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(30) NOT NULL DEFAULT 'other',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY message_reporter (message_id,reporter_id),
            KEY status (status),
            KEY group_id (group_id)
        ) $charset;" );

        $migrated = self::migrate_message_channels();
        self::purge_order_receipts( $previous_db_version );
        self::import_builder_faq();
        $schema_ready = self::schema_ready();
        if ( $migrated && $schema_ready ) update_option( 'dap_db_version', DSA2_VERSION );
        $defaults = array(
            'enabled'=>1,'auto_open'=>0,'whatsapp'=>'','show_whatsapp'=>1,'agent_status'=>'online','brand_name'=>'Delicat Assistant',
            'primary_color'=>'#ff5a2b','business_hours'=>'Lun–Sam, 8:00–20:00',
            'launcher_label_enabled'=>1,'launcher_kicker'=>'Besoin d’aide ?','launcher_message'=>'Discutez avec nous !','launcher_popup_delay'=>10,'launcher_popup_interval'=>75,'launcher_popup_visible'=>6,'launcher_popup_max'=>3,'launcher_suppress_checkout'=>1,
            'products_enabled'=>1,'recommend_enabled'=>1,'product_context_enabled'=>1,'order_support_enabled'=>1,'runtime_load'=>'demand','launcher_side'=>'right','launcher_bottom'=>16,'guide_images'=>'','training_enabled'=>1,'smalltalk_enabled'=>1,'quick_replies_enabled'=>1,'quick_groups_off'=>'','notify_enabled'=>1,'notify_email'=>get_option('admin_email'),'notify_every_message'=>1,'notify_staff'=>1,'notify_throttle'=>120,'notify_hourly_cap'=>30,'order_updates_in_chat'=>0,'agent_eta_online'=>'5–15 min','agent_eta_away'=>'30–60 min',
            'moncash_number'=>'','natcash_number'=>'','min_recharge'=>'','delivery_time'=>'','guides_text'=>'',
            'webhook_url'=>'','webhook_secret'=>'','firebase_project_id'=>'','firebase_legacy_server_key'=>'','firebase_v1_webhook_url'=>'','firebase_v1_webhook_secret'=>'','retention_days'=>90,'open_hour'=>8,'close_hour'=>20,
            'url_wallet'=>'','url_redeem'=>'','url_rewards'=>'','url_shop'=>'','canned_replies'=>'','font_scale'=>100,'force_reduced_motion'=>0,
            'admin_app_enabled'=>0,'admin_app_require_2fa'=>1,'admin_push_enabled'=>1,'admin_push_preview'=>0,'admin_session_days'=>7,'image_retention_days'=>0,
            'groups_enabled'=>1,'groups_images'=>0,'groups_links'=>0,'groups_slow_mode'=>5,'groups_max_length'=>700,
            'groups_history'=>60,'groups_report_hide'=>3,'groups_retention_days'=>120,'groups_blocklist'=>'',
            'groups_rules'=>"Aucun numéro MonCash / NatCash dans le groupe.\nAucun code PIN ni code cadeau.\nL’équipe Delicat ne demande jamais un paiement en message privé.\nRespect obligatoire : pas d’insultes, pas de spam.",
            'faq_text'=>"prix|tarif=Consultez nos produits pour voir les prix à jour.\ndelai|combien de temps=La plupart des produits numériques sont livrés rapidement après validation du paiement."
        );
        if ( class_exists( 'DSA2_Groups', false ) && $schema_ready ) DSA2_Groups::seed_default_group();
        if ( $schema_ready ) self::migrate_711();
        $current = get_option('dap_settings', array());
        if ( version_compare( $previous_db_version ?: '0', '5.3.0', '<' ) && ( ! isset($current['runtime_load']) || 'auto' === $current['runtime_load'] ) ) {
            $current['runtime_load'] = 'demand';
        }
        update_option('dap_settings', wp_parse_args($current, $defaults));
        return $migrated && $schema_ready;
    }

    /**
     * v6.2.0 — deletes the automatic order-status receipts that older releases posted into
     * every customer thread. These are the "Commande #12157 : Processing / Completed"
     * cards: sender and type both "system", channel "agent", meta carrying an order block.
     * Nothing a human or the assistant wrote matches that shape, so the conversation
     * itself is untouched. Deleted in batches so a store with a long history does not hold
     * the table during one enormous statement.
     */
    private static function purge_order_receipts( $previous_db_version ) {
        if ( version_compare( $previous_db_version ?: '0', '6.2.0', '>=' ) ) return;
        $settings = (array) get_option( 'dap_settings', array() );
        if ( ! empty( $settings['order_updates_in_chat'] ) ) return; // the store opted back in
        global $wpdb;
        $msg = $wpdb->prefix . 'dap_messages';
        $like = '%' . $wpdb->esc_like( '"order"' ) . '%';
        for ( $pass = 0; $pass < 40; $pass++ ) {
            $deleted = $wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                "DELETE FROM $msg WHERE sender='system' AND message_type='system' AND channel='agent' AND meta LIKE %s LIMIT 500", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $like
            ) );
            if ( ! $deleted ) break;
        }
    }

    /** All columns used unconditionally by v5 must exist before the version is committed. */
    private static function schema_ready() {
        global $wpdb;
        $required = array(
            $wpdb->prefix . 'dap_conversations' => array( 'priority', 'last_agent_message_at' ),
            $wpdb->prefix . 'dap_messages'      => array( 'sender_id', 'channel' ),
            $wpdb->prefix . 'dap_mobile_devices'=> array( 'installation_id' ),
            $wpdb->prefix . 'dap_admin_sessions'=> array( 'user_id', 'installation_id', 'token_hash', 'fcm_token', 'expires_at' ),
            $wpdb->prefix . 'dap_groups'        => array( 'slug', 'status', 'slow_mode', 'locked' ),
            $wpdb->prefix . 'dap_group_members' => array( 'group_id', 'user_id', 'status', 'last_read_id' ),
            $wpdb->prefix . 'dap_group_messages'=> array( 'group_id', 'user_id', 'status', 'flags', 'pinned' ),
            $wpdb->prefix . 'dap_group_reports' => array( 'message_id', 'reporter_id', 'status' ),
        );
        foreach ( $required as $table => $columns ) {
            $available = (array) $wpdb->get_col( "SHOW COLUMNS FROM $table", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( array_diff( $columns, $available ) ) return false;
        }
        return true;
    }

    /**
     * Gives support staff access to the inbox without granting store-wide WooCommerce
     * administration. Administrators and shop managers keep access automatically.
     */
    public static function install_roles() {
        add_role( self::ROLE_SUPPORT, 'Agent support Delicat', array(
            'read'                 => true,
            self::CAP_SUPPORT      => true,
        ) );
        foreach ( array( 'administrator', 'shop_manager', self::ROLE_SUPPORT ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role && ! $role->has_cap( self::CAP_SUPPORT ) ) {
                $role->add_cap( self::CAP_SUPPORT );
            }
            if ( self::ROLE_SUPPORT === $role_name && $role && ! $role->has_cap( 'read' ) ) $role->add_cap( 'read' );
        }
        foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
            $role = get_role( $role_name );
            if ( $role && ! $role->has_cap( self::CAP_SETTINGS ) ) $role->add_cap( self::CAP_SETTINGS );
        }
    }

    /**
     * v5 stores the automated assistant and the human-support thread as explicit channels.
     * Existing rows are classified once. Old customer messages after the first handoff are
     * treated as agent messages; new rows are tagged exactly when they are written.
     */
    private static function migrate_message_channels() {
        if ( (int) get_option( 'dap_channel_migration', 0 ) >= 1 ) return true;
        global $wpdb;
        $msg  = $wpdb->prefix . 'dap_messages';
        $conv = $wpdb->prefix . 'dap_conversations';

        // Do not mark a half-upgrade as complete when a restrictive database user kept
        // dbDelta() from adding the v5 columns. A later request can then retry safely.
        $message_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM $msg", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $conversation_columns = (array) $wpdb->get_col( "SHOW COLUMNS FROM $conv", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! in_array( 'channel', $message_columns, true ) || ! in_array( 'sender_id', $message_columns, true ) || ! in_array( 'last_agent_message_at', $conversation_columns, true ) ) return false;

        $agent_types = "'agent_card','contact_card','rate_card','rating'";
        $ok = false !== $wpdb->query( "UPDATE $msg SET channel='agent' WHERE sender='agent' OR message_type IN ($agent_types)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $starts = $wpdb->get_results( "SELECT conversation_id, MIN(id) AS first_agent_id FROM $msg WHERE sender='agent' OR message_type IN ($agent_types) GROUP BY conversation_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( null === $starts ) $ok = false;
        foreach ( (array) $starts as $start ) {
            if ( false === $wpdb->query( $wpdb->prepare(
                "UPDATE $msg SET channel='agent' WHERE conversation_id=%d AND id>=%d AND (sender IN ('customer','agent') OR message_type IN ('image','attachment','system','rating','agent_card','contact_card','rate_card'))",
                (int) $start->conversation_id,
                (int) $start->first_agent_id
            ) ) ) $ok = false;
        }
        // Legacy rows did not record who sent them. Leave sender_id=0 instead of falsely
        // attributing every historical reply to whoever happens to own the thread today.
        if ( false === $wpdb->query( "UPDATE $conv c SET c.last_agent_message_at=(SELECT MAX(m.created_at) FROM $msg m WHERE m.conversation_id=c.id AND m.channel='agent')" ) ) $ok = false; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( ! $ok ) return false;
        update_option( 'dap_channel_migration', 1, false );
        return true;
    }

    /**
     * Keeps dap_faq aligned with the Builder table. The old flow imported only on
     * version upgrades, so Builder edits stayed invisible until the next release.
     */
    public static function maybe_sync_faq( $force = false ) {
        if ( ! $force && get_transient( 'dap_faq_synced' ) ) return;
        set_transient( 'dap_faq_synced', 1, 15 * MINUTE_IN_SECONDS );
        self::import_builder_faq();
    }

    private static function import_builder_faq() {
        global $wpdb;
        $source = $wpdb->prefix . 'delicat_chat_faq';
        $target = $wpdb->prefix . 'dap_faq';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $source ) ) !== $source ) return;

        $columns = $wpdb->get_col( "SHOW COLUMNS FROM " . $source, 0 );
        $required = array( 'id', 'keywords', 'question', 'answer_fr' );
        if ( array_diff( $required, (array) $columns ) ) return;

        $select = array( 'id', 'keywords', 'question', 'answer_fr' );
        foreach ( array( 'answer_en', 'answer_ht', 'buttons', 'active' ) as $optional ) {
            if ( in_array( $optional, (array) $columns, true ) ) $select[] = $optional;
        }
        $rows = $wpdb->get_results( 'SELECT ' . implode( ',', $select ) . " FROM " . $source );
        $keep = array();
        // UPDATE-or-INSERT, never REPLACE: REPLACE deletes and re-inserts, so every sync
        // handed each question a brand new id. FAQ buttons already rendered in a customer's
        // chat ("faq_12") then pointed at a row that no longer existed — "Action indisponible".
        foreach ( (array) $rows as $row ) {
            $keep[] = (int) $row->id;
            $data = array(
                'keywords'  => isset( $row->keywords ) ? (string) $row->keywords : '',
                'question'  => isset( $row->question ) ? (string) $row->question : '',
                'answer_fr' => isset( $row->answer_fr ) ? (string) $row->answer_fr : '',
                'answer_en' => isset( $row->answer_en ) ? (string) $row->answer_en : '',
                'answer_ht' => isset( $row->answer_ht ) ? (string) $row->answer_ht : '',
                'buttons'   => isset( $row->buttons ) ? (string) $row->buttons : '[]',
                'active'    => isset( $row->active ) ? (int) $row->active : 1,
            );
            $format   = array( '%s','%s','%s','%s','%s','%s','%d' );
            $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $target WHERE legacy_id=%d", (int) $row->id ) );
            if ( $existing ) {
                $wpdb->update( $target, $data, array( 'id' => $existing ), $format, array( '%d' ) );
            } else {
                $data['legacy_id'] = (int) $row->id;
                $format[] = '%d';
                $wpdb->insert( $target, $data, $format );
            }
        }
        // Questions deleted in the Builder disappear here too.
        if ( $keep ) {
            $wpdb->query( "DELETE FROM " . $target . " WHERE legacy_id > 0 AND legacy_id NOT IN (" . implode( ',', array_map( 'absint', $keep ) ) . ")" );
        } else {
            $wpdb->query( "DELETE FROM " . $target . " WHERE legacy_id > 0" );
        }
    }

    private static function deactivate_legacy_standalone_versions() {
        if ( ! is_admin() ) return;
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $current = plugin_basename( DSA2_FILE );
        foreach ( (array) get_option( 'active_plugins', array() ) as $plugin ) {
            if ( $plugin === $current ) continue;
            if ( preg_match( '#^delicat-assistant-pro-v[0-9][^/]*/delicat-assistant-pro\.php$#', $plugin ) ) {
                deactivate_plugins( $plugin, true );
            }
        }
    }
}
