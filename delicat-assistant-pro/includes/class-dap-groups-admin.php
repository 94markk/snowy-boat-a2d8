<?php
/**
 * Groups admin: rooms, report queue, safety settings.
 * Every write goes through check_admin_referer() plus an explicit capability test — the
 * nonce alone only proves the request came from a Delicat screen, not that the person
 * behind it is allowed to moderate.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class DSA2_Groups_Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
        add_action( 'admin_post_dap_group_save', array( __CLASS__, 'save_group' ) );
        add_action( 'admin_post_dap_group_delete', array( __CLASS__, 'delete_group' ) );
        add_action( 'admin_post_dap_group_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_dap_group_report', array( __CLASS__, 'handle_report' ) );
    }

    public static function menu() {
        add_submenu_page(
            'delicat-assistant',
            'Groupes clients',
            'Groupes clients',
            DSA2_Install::CAP_SUPPORT,
            'delicat-assistant-groups',
            array( __CLASS__, 'page' )
        );
    }

    private static function can_moderate() {
        return current_user_can( DSA2_Install::CAP_SUPPORT ) || current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
    }

    private static function can_manage() {
        return current_user_can( DSA2_Install::CAP_SETTINGS ) || current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
    }

    private static function require_moderator() {
        if ( ! self::can_moderate() ) wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
    }

    private static function redirect( $tab = 'rooms', $notice = '' ) {
        $url = add_query_arg( array(
            'page'   => 'delicat-assistant-groups',
            'tab'    => sanitize_key( $tab ),
            'notice' => sanitize_key( $notice ),
        ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    /* ------------------------------------------------------------- write ops */

    public static function save_group() {
        self::require_moderator();
        check_admin_referer( 'dap_group_save' );
        global $wpdb;
        $table = DSA2_Groups::table( 'groups' );
        $id    = isset( $_POST['group_id'] ) ? absint( $_POST['group_id'] ) : 0;
        $name  = trim( sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ) );
        if ( '' === $name ) self::redirect( 'rooms', 'missing_name' );

        $slug = sanitize_title( wp_unslash( $_POST['slug'] ?? '' ) );
        if ( '' === $slug ) $slug = sanitize_title( $name );
        // Slug collisions would break the shortcode's group="" attribute.
        $clash = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug=%s AND id<>%d", $slug, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $clash ) $slug .= '-' . wp_generate_password( 4, false, false );

        $icon = trim( sanitize_text_field( wp_unslash( $_POST['icon'] ?? '' ) ) );
        $data = array(
            'slug'        => $slug,
            'name'        => DSA2_Compat::substr( $name, 0, 90 ),
            'description' => DSA2_Compat::substr( sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ), 0, 240 ),
            'icon'        => DSA2_Compat::substr( $icon, 0, 8 ),
            'visibility'  => ( 'hidden' === ( $_POST['visibility'] ?? 'public' ) ) ? 'hidden' : 'public',
            'status'      => ( 'archived' === ( $_POST['status'] ?? 'active' ) ) ? 'archived' : 'active',
            'locked'      => ! empty( $_POST['locked'] ) ? 1 : 0,
            'slow_mode'   => max( 0, min( 300, absint( $_POST['slow_mode'] ?? 5 ) ) ),
            'sort_order'  => max( 0, min( 999, absint( $_POST['sort_order'] ?? 1 ) ) ),
        );
        $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' );

        if ( $id ) {
            $wpdb->update( $table, $data, array( 'id' => $id ), $format, array( '%d' ) );
        } else {
            $data['member_count']  = 0;
            $data['message_count'] = 0;
            $data['created_by']    = get_current_user_id();
            $data['created_at']    = current_time( 'mysql', 1 );
            $format = array_merge( $format, array( '%d', '%d', '%d', '%s' ) );
            $wpdb->insert( $table, $data, $format );
            $id = (int) $wpdb->insert_id;
        }
        if ( $id ) DSA2_Groups::refresh_counts( $id );
        self::redirect( 'rooms', 'saved' );
    }

    public static function delete_group() {
        self::require_moderator();
        check_admin_referer( 'dap_group_delete' );
        $id = isset( $_GET['group_id'] ) ? absint( $_GET['group_id'] ) : 0;
        if ( ! $id ) self::redirect( 'rooms', 'error' );
        global $wpdb;
        $wpdb->delete( DSA2_Groups::table( 'reports' ), array( 'group_id' => $id ), array( '%d' ) );
        $wpdb->delete( DSA2_Groups::table( 'messages' ), array( 'group_id' => $id ), array( '%d' ) );
        $wpdb->delete( DSA2_Groups::table( 'members' ), array( 'group_id' => $id ), array( '%d' ) );
        $wpdb->delete( DSA2_Groups::table( 'groups' ), array( 'id' => $id ), array( '%d' ) );
        self::redirect( 'rooms', 'deleted' );
    }

    public static function handle_report() {
        self::require_moderator();
        check_admin_referer( 'dap_group_report' );
        $message_id = isset( $_POST['message_id'] ) ? absint( $_POST['message_id'] ) : 0;
        $op = sanitize_key( wp_unslash( $_POST['op'] ?? '' ) );
        if ( ! $message_id ) self::redirect( 'reports', 'error' );
        global $wpdb;
        $messages = DSA2_Groups::table( 'messages' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $messages WHERE id=%d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( ! $row ) self::redirect( 'reports', 'error' );

        if ( 'delete' === $op ) {
            $wpdb->update( $messages, array( 'status' => 'deleted' ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );
            $wpdb->update( DSA2_Groups::table( 'reports' ), array( 'status' => 'actioned' ), array( 'message_id' => $message_id ), array( '%s' ), array( '%d' ) );
        } elseif ( 'restore' === $op ) {
            $wpdb->update( $messages, array( 'status' => 'visible', 'report_count' => 0 ), array( 'id' => $message_id ), array( '%s', '%d' ), array( '%d' ) );
            $wpdb->update( DSA2_Groups::table( 'reports' ), array( 'status' => 'dismissed' ), array( 'message_id' => $message_id ), array( '%s' ), array( '%d' ) );
        } elseif ( 'ban' === $op ) {
            if ( ! DSA2_Groups::is_staff( (int) $row->user_id ) ) {
                update_user_meta( (int) $row->user_id, 'dap_groups_banned', 1 );
                $wpdb->update( $messages, array( 'status' => 'deleted' ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );
                $wpdb->update( DSA2_Groups::table( 'reports' ), array( 'status' => 'actioned' ), array( 'message_id' => $message_id ), array( '%s' ), array( '%d' ) );
            }
        } elseif ( 'mute' === $op ) {
            $wpdb->update(
                DSA2_Groups::table( 'members' ),
                array( 'muted_until' => gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS ) ),
                array( 'group_id' => (int) $row->group_id, 'user_id' => (int) $row->user_id ),
                array( '%s' ), array( '%d', '%d' )
            );
            $wpdb->update( DSA2_Groups::table( 'reports' ), array( 'status' => 'actioned' ), array( 'message_id' => $message_id ), array( '%s' ), array( '%d' ) );
        }
        DSA2_Groups::refresh_counts( (int) $row->group_id );
        self::redirect( 'reports', 'moderated' );
    }

    public static function save_settings() {
        if ( ! self::can_manage() ) wp_die( esc_html__( 'Accès refusé.', 'delicat-assistant' ) );
        check_admin_referer( 'dap_group_settings' );
        $current = (array) get_option( 'dap_settings', array() );
        $current['groups_enabled']        = ! empty( $_POST['groups_enabled'] ) ? 1 : 0;
        $current['groups_images']         = ! empty( $_POST['groups_images'] ) ? 1 : 0;
        $current['groups_links']          = ! empty( $_POST['groups_links'] ) ? 1 : 0;
        $current['groups_slow_mode']      = max( 0, min( 300, absint( $_POST['groups_slow_mode'] ?? 5 ) ) );
        $current['groups_max_length']     = max( 40, min( 2000, absint( $_POST['groups_max_length'] ?? 700 ) ) );
        $current['groups_history']        = max( 10, min( 200, absint( $_POST['groups_history'] ?? 60 ) ) );
        $current['groups_report_hide']    = max( 1, min( 20, absint( $_POST['groups_report_hide'] ?? 3 ) ) );
        $current['groups_retention_days'] = max( 0, min( 3650, absint( $_POST['groups_retention_days'] ?? 120 ) ) );
        $current['groups_blocklist']      = sanitize_textarea_field( wp_unslash( $_POST['groups_blocklist'] ?? '' ) );
        $current['groups_rules']          = sanitize_textarea_field( wp_unslash( $_POST['groups_rules'] ?? '' ) );
        update_option( 'dap_settings', $current );
        self::redirect( 'settings', 'saved' );
    }

    /* ------------------------------------------------------------------ view */

    public static function page() {
        self::require_moderator();
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'rooms'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $tab, array( 'rooms', 'reports', 'settings' ), true ) ) $tab = 'rooms';
        $notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $base = admin_url( 'admin.php?page=delicat-assistant-groups' );
        ?>
        <div class="wrap dap-groups-admin">
            <h1>Groupes clients</h1>
            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo 'error' === $notice || 'missing_name' === $notice ? 'error' : 'success'; ?> is-dismissible"><p><?php
                    $labels = array(
                        'saved'        => 'Enregistré.',
                        'deleted'      => 'Groupe supprimé.',
                        'moderated'    => 'Action de modération appliquée.',
                        'missing_name' => 'Le nom du groupe est obligatoire.',
                        'error'        => 'Action impossible.',
                    );
                    echo esc_html( $labels[ $notice ] ?? 'Terminé.' );
                ?></p></div>
            <?php endif; ?>
            <h2 class="nav-tab-wrapper">
                <a class="nav-tab <?php echo 'rooms' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=rooms' ); ?>">Salons</a>
                <a class="nav-tab <?php echo 'reports' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=reports' ); ?>">Signalements<?php
                    $open = self::open_reports_count();
                    if ( $open ) echo ' <span class="awaiting-mod"><span class="pending-count">' . (int) $open . '</span></span>';
                ?></a>
                <?php if ( self::can_manage() ) : ?><a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=settings' ); ?>">Sécurité</a><?php endif; ?>
            </h2>
            <?php
            if ( 'reports' === $tab ) self::reports_tab();
            elseif ( 'settings' === $tab && self::can_manage() ) self::settings_tab();
            else self::rooms_tab();
            ?>
        </div>
        <?php
    }

    private static function open_reports_count() {
        global $wpdb;
        $reports = DSA2_Groups::table( 'reports' );
        return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT message_id) FROM $reports WHERE status='open'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }

    private static function rooms_tab() {
        global $wpdb;
        $table = DSA2_Groups::table( 'groups' );
        $rows = (array) $wpdb->get_results( "SELECT * FROM $table ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $edit = null;
        foreach ( $rows as $row ) if ( (int) $row->id === $edit_id ) $edit = $row;
        ?>
        <p class="description">Les salons sont ouverts à tout client connecté. Utilisez le raccourci <code>[delicat_groups]</code> pour une page dédiée&nbsp;; l’onglet «&nbsp;Groupes&nbsp;» du widget apparaît automatiquement.</p>
        <table class="widefat striped" style="margin-bottom:24px">
            <thead><tr><th>Salon</th><th>Membres</th><th>Messages</th><th>Mode lent</th><th>État</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ( ! $rows ) : ?>
                <tr><td colspan="6">Aucun salon pour l’instant. Créez le premier ci-dessous.</td></tr>
            <?php endif; ?>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><strong><?php echo esc_html( $row->icon . ' ' . $row->name ); ?></strong><br><code><?php echo esc_html( $row->slug ); ?></code></td>
                    <td><?php echo (int) $row->member_count; ?></td>
                    <td><?php echo (int) $row->message_count; ?></td>
                    <td><?php echo (int) $row->slow_mode; ?> s</td>
                    <td>
                        <?php echo 'active' === $row->status ? 'Actif' : 'Archivé'; ?>
                        <?php echo (int) $row->locked ? ' · <em>lecture seule</em>' : ''; ?>
                        <?php echo 'public' !== $row->visibility ? ' · <em>masqué</em>' : ''; ?>
                    </td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=delicat-assistant-groups&tab=rooms&edit=' . (int) $row->id ) ); ?>">Modifier</a>
                        <a class="button button-small" style="color:#b32d2e" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dap_group_delete&group_id=' . (int) $row->id ), 'dap_group_delete' ) ); ?>" onclick="return confirm('Supprimer ce salon et tous ses messages ?')">Supprimer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2><?php echo $edit ? 'Modifier le salon' : 'Nouveau salon'; ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="dap_group_save">
            <input type="hidden" name="group_id" value="<?php echo $edit ? (int) $edit->id : 0; ?>">
            <?php wp_nonce_field( 'dap_group_save' ); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="dapg-name">Nom</label></th>
                    <td><input id="dapg-name" class="regular-text" type="text" name="name" required maxlength="90" value="<?php echo esc_attr( $edit->name ?? '' ); ?>"></td></tr>
                <tr><th scope="row"><label for="dapg-icon">Icône</label></th>
                    <td><input id="dapg-icon" type="text" name="icon" maxlength="8" size="4" value="<?php echo esc_attr( $edit->icon ?? '💬' ); ?>"> <span class="description">Un emoji.</span></td></tr>
                <tr><th scope="row"><label for="dapg-slug">Identifiant</label></th>
                    <td><input id="dapg-slug" class="regular-text" type="text" name="slug" value="<?php echo esc_attr( $edit->slug ?? '' ); ?>"> <span class="description">Laissez vide pour le générer. Utilisé par <code>[delicat_groups group="…"]</code>.</span></td></tr>
                <tr><th scope="row"><label for="dapg-desc">Description</label></th>
                    <td><textarea id="dapg-desc" class="large-text" rows="2" name="description" maxlength="240"><?php echo esc_textarea( $edit->description ?? '' ); ?></textarea></td></tr>
                <tr><th scope="row"><label for="dapg-slow">Mode lent</label></th>
                    <td><input id="dapg-slow" type="number" min="0" max="300" name="slow_mode" value="<?php echo (int) ( $edit->slow_mode ?? 5 ); ?>"> secondes entre deux messages d’un même client. <span class="description">0 pour désactiver. La modération n’est jamais ralentie.</span></td></tr>
                <tr><th scope="row"><label for="dapg-order">Ordre</label></th>
                    <td><input id="dapg-order" type="number" min="0" max="999" name="sort_order" value="<?php echo (int) ( $edit->sort_order ?? 1 ); ?>"></td></tr>
                <tr><th scope="row">État</th>
                    <td>
                        <label><input type="radio" name="status" value="active" <?php checked( ( $edit->status ?? 'active' ), 'active' ); ?>> Actif</label><br>
                        <label><input type="radio" name="status" value="archived" <?php checked( ( $edit->status ?? 'active' ), 'archived' ); ?>> Archivé</label><br><br>
                        <label><input type="checkbox" name="locked" value="1" <?php checked( (int) ( $edit->locked ?? 0 ), 1 ); ?>> Lecture seule (annonces)</label><br>
                        <label><input type="checkbox" name="visibility" value="hidden" <?php checked( ( $edit->visibility ?? 'public' ), 'hidden' ); ?>> Masquer de la liste</label>
                    </td></tr>
            </table>
            <?php submit_button( $edit ? 'Enregistrer' : 'Créer le salon' ); ?>
        </form>
        <?php
    }

    private static function reports_tab() {
        global $wpdb;
        $messages = DSA2_Groups::table( 'messages' );
        $reports  = DSA2_Groups::table( 'reports' );
        $groups   = DSA2_Groups::table( 'groups' );
        $rows = (array) $wpdb->get_results(
            "SELECT m.*, g.name AS group_name, COUNT(DISTINCT r.reporter_id) AS distinct_reports, MAX(r.created_at) AS latest
             FROM $reports r
             INNER JOIN $messages m ON m.id=r.message_id
             LEFT JOIN $groups g ON g.id=m.group_id
             WHERE r.status='open'
             GROUP BY m.id ORDER BY latest DESC LIMIT 100" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        );
        ?>
        <p class="description">Un message atteignant le seuil de signalements est masqué automatiquement, en attente de votre décision.</p>
        <table class="widefat striped">
            <thead><tr><th>Message</th><th>Auteur</th><th>Salon</th><th>Signalements</th><th>Filtres</th><th>Actions</th></tr></thead>
            <tbody>
            <?php if ( ! $rows ) : ?><tr><td colspan="6">Aucun signalement en attente.</td></tr><?php endif; ?>
            <?php foreach ( $rows as $row ) :
                $author = DSA2_Groups::display_name( (int) $row->user_id ); ?>
                <tr>
                    <td style="max-width:420px"><?php echo esc_html( DSA2_Compat::substr( $row->body, 0, 320 ) ); ?>
                        <br><small><?php echo esc_html( $row->created_at ); ?> · <?php echo esc_html( 'visible' === $row->status ? 'visible' : $row->status ); ?></small></td>
                    <td><?php echo esc_html( $author ); ?><br><small>#<?php echo (int) $row->user_id; ?></small></td>
                    <td><?php echo esc_html( $row->group_name ?? '—' ); ?></td>
                    <td><?php echo (int) $row->distinct_reports; ?></td>
                    <td><?php echo esc_html( $row->flags ? $row->flags : '—' ); ?></td>
                    <td>
                        <?php foreach ( array( 'delete' => 'Supprimer', 'restore' => 'Rétablir', 'mute' => 'Sourdine 24 h', 'ban' => 'Bannir' ) as $op => $label ) : ?>
                            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 3px 3px 0">
                                <input type="hidden" name="action" value="dap_group_report">
                                <input type="hidden" name="message_id" value="<?php echo (int) $row->id; ?>">
                                <input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
                                <?php wp_nonce_field( 'dap_group_report' ); ?>
                                <button class="button button-small" type="submit"<?php echo 'ban' === $op ? ' onclick="return confirm(\'Bannir ce client de tous les groupes ?\')"' : ''; ?>><?php echo esc_html( $label ); ?></button>
                            </form>
                        <?php endforeach; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private static function settings_tab() {
        $s = DSA2_Groups::settings();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="dap_group_settings">
            <?php wp_nonce_field( 'dap_group_settings' ); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row">Activation</th>
                    <td><label><input type="checkbox" name="groups_enabled" value="1" <?php checked( ! empty( $s['groups_enabled'] ) ); ?>> Activer les groupes clients</label>
                    <p class="description">Réservé aux clients connectés. Aucun accès invité, jamais.</p></td></tr>
                <tr><th scope="row">Images</th>
                    <td><label><input type="checkbox" name="groups_images" value="1" <?php checked( ! empty( $s['groups_images'] ) ); ?>> Autoriser l’envoi d’images</label>
                    <p class="description">Désactivé par défaut&nbsp;: les fausses «&nbsp;captures de paiement&nbsp;» sont l’outil principal des arnaqueurs.</p></td></tr>
                <tr><th scope="row">Liens externes</th>
                    <td><label><input type="checkbox" name="groups_links" value="1" <?php checked( ! empty( $s['groups_links'] ) ); ?>> Autoriser les liens vers d’autres sites</label>
                    <p class="description">Désactivé par défaut. Les liens vers <?php echo esc_html( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?> passent toujours.</p></td></tr>
                <tr><th scope="row"><label for="dapg-slowmode">Mode lent par défaut</label></th>
                    <td><input id="dapg-slowmode" type="number" min="0" max="300" name="groups_slow_mode" value="<?php echo (int) $s['groups_slow_mode']; ?>"> s <span class="description">Valeur proposée aux nouveaux salons.</span></td></tr>
                <tr><th scope="row"><label for="dapg-maxlen">Longueur maximale</label></th>
                    <td><input id="dapg-maxlen" type="number" min="40" max="2000" name="groups_max_length" value="<?php echo (int) $s['groups_max_length']; ?>"> caractères</td></tr>
                <tr><th scope="row"><label for="dapg-history">Historique chargé</label></th>
                    <td><input id="dapg-history" type="number" min="10" max="200" name="groups_history" value="<?php echo (int) $s['groups_history']; ?>"> messages</td></tr>
                <tr><th scope="row"><label for="dapg-reports">Seuil de masquage</label></th>
                    <td><input id="dapg-reports" type="number" min="1" max="20" name="groups_report_hide" value="<?php echo (int) $s['groups_report_hide']; ?>"> signalements distincts <span class="description">Le message est masqué et arrive dans la file de modération.</span></td></tr>
                <tr><th scope="row"><label for="dapg-retention">Conservation</label></th>
                    <td><input id="dapg-retention" type="number" min="0" max="3650" name="groups_retention_days" value="<?php echo (int) $s['groups_retention_days']; ?>"> jours <span class="description">0 conserve tout. Les messages épinglés ne sont jamais purgés.</span></td></tr>
                <tr><th scope="row"><label for="dapg-blocklist">Mots interdits</label></th>
                    <td><textarea id="dapg-blocklist" class="large-text code" rows="4" name="groups_blocklist"><?php echo esc_textarea( $s['groups_blocklist'] ); ?></textarea>
                    <p class="description">Un terme par ligne. Masqué automatiquement dans les messages.</p></td></tr>
                <tr><th scope="row"><label for="dapg-rules">Règles affichées</label></th>
                    <td><textarea id="dapg-rules" class="large-text" rows="5" name="groups_rules"><?php echo esc_textarea( $s['groups_rules'] ); ?></textarea>
                    <p class="description">Une règle par ligne, présentée avant de rejoindre un salon.</p></td></tr>
            </table>
            <h2>Toujours actif</h2>
            <p class="description" style="max-width:760px">
                Numéros de téléphone et MonCash/NatCash masqués · codes PIN et cartes cadeaux masqués · adresses e-mail masquées ·
                badge «&nbsp;Équipe&nbsp;» rendu depuis le rôle WordPress et impossible à imiter · noms d’affichage réservés interdits ·
                limitation de débit par compte · rejet des doublons · signalement client · sourdine, bannissement et verrouillage de salon.
            </p>
            <?php submit_button(); ?>
        </form>
        <?php
    }
}
