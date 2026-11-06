<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tickets workspace for the support team (Delicat Assistant → Tickets).
 *
 * 7.1.1 (AST-06): the REST routes of the old customer API that lived here (account linking by
 * e-mail code, account, preferences, orders and order actions, wallet history, health) and its
 * push retry queue are removed; they were off by default since 6.6.1.
 */
class DSA2_Enterprise {
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 30 );
        add_action( 'admin_post_dap_agent_ticket_action', array( __CLASS__, 'ticket_action' ) );
    }

    public static function admin_menu() {
        add_submenu_page('delicat-assistant','Agent Workspace','Tickets',DSA2_Install::CAP_SUPPORT,'dap-agent-workspace',array(__CLASS__,'workspace'));
    }
    public static function workspace() {
        if(!current_user_can(DSA2_Install::CAP_SUPPORT))wp_die('Forbidden');
        $status=sanitize_key(wp_unslash($_GET['ticket_status']??'')); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if(!in_array($status,array('open','closed'),true))$status='';
        $search=trim(sanitize_text_field(wp_unslash($_GET['s']??''))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $args=array('post_type'=>'dap_ticket','post_status'=>array('publish','draft','pending'),'posts_per_page'=>75,'orderby'=>'modified','order'=>'DESC');
        if($status)$args['meta_query']=array(array('key'=>'dap_ticket_status','value'=>$status));
        if($search!=='')$args['s']=$search;
        $tickets=get_posts($args);
        $agents=get_users(array('capability'=>DSA2_Install::CAP_SUPPORT,'orderby'=>'display_name','order'=>'ASC'));
        ?>
        <div class="wrap dap-admin dap-ticket-workspace">
            <header class="dap-admin-hero"><div><span class="dap-eyebrow">SUPPORT STRUCTURÉ</span><h1>Tickets clients</h1><p>Assignez, priorisez et suivez les demandes créées depuis l’application.</p></div><div class="dap-hero-actions"><a class="dap-secondary-link" href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant')); ?>">← Retour à l’inbox</a><span class="dap-version"><?php echo count($tickets); ?> ticket(s)</span></div></header>
            <form method="get" class="dap-ticket-toolbar"><input type="hidden" name="page" value="dap-agent-workspace"><label><span>État</span><select name="ticket_status"><option value="">Tous</option><option value="open" <?php selected($status,'open'); ?>>Ouverts</option><option value="closed" <?php selected($status,'closed'); ?>>Fermés</option></select></label><label class="dap-ticket-search"><span>Recherche</span><input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Sujet ou numéro"></label><button type="submit">Filtrer</button></form>
            <?php if(isset($_GET['updated'])):?><div class="notice notice-success is-dismissible"><p>Ticket mis à jour.</p></div><?php endif; ?>
            <div class="dap-ticket-grid">
            <?php if(!$tickets):?><div class="dap-ticket-empty"><span>✓</span><h2>Aucun ticket</h2><p>Aucune demande ne correspond à ce filtre.</p></div><?php endif; ?>
            <?php foreach($tickets as $ticket):
                $ticket_status=sanitize_key(get_post_meta($ticket->ID,'dap_ticket_status',true)?:'open');
                $priority=sanitize_key(get_post_meta($ticket->ID,'dap_ticket_priority',true)?:'normal');
                $assigned=(int)get_post_meta($ticket->ID,'dap_ticket_assigned_to',true);
                $conversation_id=(int)get_post_meta($ticket->ID,'dap_conversation_id',true);
                $order_id=(int)get_post_meta($ticket->ID,'dap_order_id',true);
                $attachments=get_post_meta($ticket->ID,'dap_ticket_attachments',true);
                $notes=get_post_meta($ticket->ID,'dap_internal_notes',true);if(!is_array($notes))$notes=array();$last_note=$notes?end($notes):null;
                $rating=(int)get_post_meta($ticket->ID,'dap_ticket_rating',true);
            ?>
                <article class="dap-ticket-card is-<?php echo esc_attr($ticket_status); ?>">
                    <header><div><span class="dap-ticket-number">TICKET #<?php echo (int)$ticket->ID; ?></span><h2><?php echo esc_html($ticket->post_title); ?></h2></div><span class="dap-ticket-state is-<?php echo esc_attr($ticket_status); ?>"><?php echo 'closed'===$ticket_status?'Fermé':'Ouvert'; ?></span></header>
                    <p class="dap-ticket-message"><?php echo esc_html(wp_trim_words(wp_strip_all_tags($ticket->post_content),32,'…')); ?></p>
                    <div class="dap-ticket-meta"><span>Mis à jour <?php echo esc_html(human_time_diff(get_post_modified_time('U',true,$ticket),current_time('timestamp',true))); ?></span><?php if($conversation_id):?><a href="<?php echo esc_url(admin_url('admin.php?page=delicat-assistant&conversation='.$conversation_id)); ?>">Conversation #<?php echo (int)$conversation_id; ?></a><?php endif;?><?php if($order_id):?><span>Commande #<?php echo (int)$order_id; ?></span><?php endif;?><span><?php echo is_array($attachments)?count($attachments):0; ?> pièce(s)</span><?php if($rating):?><span><?php echo str_repeat('★',min(5,$rating)); ?></span><?php endif;?></div>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dap-ticket-form"><input type="hidden" name="action" value="dap_agent_ticket_action"><input type="hidden" name="ticket_id" value="<?php echo (int)$ticket->ID; ?>"><?php wp_nonce_field('dap_agent_ticket_action'); ?>
                        <label><span>État</span><select name="status"><option value="open" <?php selected($ticket_status,'open'); ?>>Ouvert</option><option value="closed" <?php selected($ticket_status,'closed'); ?>>Fermé</option></select></label>
                        <label><span>Priorité</span><select name="priority"><option value="low" <?php selected($priority,'low'); ?>>Basse</option><option value="normal" <?php selected($priority,'normal'); ?>>Normale</option><option value="high" <?php selected($priority,'high'); ?>>Haute</option><option value="urgent" <?php selected($priority,'urgent'); ?>>Urgente</option></select></label>
                        <label class="dap-ticket-assignee"><span>Agent</span><select name="assigned_to"><option value="0">Non assigné</option><?php foreach($agents as $agent):?><option value="<?php echo (int)$agent->ID; ?>" <?php selected($assigned,(int)$agent->ID); ?>><?php echo esc_html($agent->display_name); ?></option><?php endforeach;?></select></label>
                        <label class="dap-ticket-note"><span>Note interne (jamais visible par le client)</span><textarea name="internal_note" rows="2" maxlength="3000" placeholder="Ajouter un contexte pour l’équipe…"></textarea></label>
                        <?php if(is_array($last_note)):?><div class="dap-ticket-last-note"><strong>Dernière note</strong><p><?php echo esc_html(wp_trim_words((string)($last_note['note']??''),22,'…')); ?></p></div><?php endif;?>
                        <footer><a href="<?php echo esc_url(get_edit_post_link($ticket->ID)); ?>">Ouvrir le contenu ↗</a><button type="submit">Enregistrer</button></footer>
                    </form>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    public static function ticket_action() {
        if ( ! current_user_can( DSA2_Install::CAP_SUPPORT ) ) wp_die( 'Forbidden' );
        check_admin_referer( 'dap_agent_ticket_action' );
        $id = absint( $_POST['ticket_id'] ?? 0 );
        if ( 'dap_ticket' !== get_post_type( $id ) ) wp_die( 'Invalid ticket' );

        $old_status = sanitize_key( get_post_meta( $id, 'dap_ticket_status', true ) ?: 'open' );
        $new_status = $old_status;
        if ( isset( $_POST['assigned_to'] ) ) {
            $assigned = absint( $_POST['assigned_to'] );
            $user = $assigned ? get_userdata( $assigned ) : false;
            if ( ! $assigned || ( $user && user_can( $user, DSA2_Install::CAP_SUPPORT ) ) ) {
                update_post_meta( $id, 'dap_ticket_assigned_to', $assigned );
            }
        }
        if ( isset( $_POST['status'] ) ) {
            $status = sanitize_key( wp_unslash( $_POST['status'] ) );
            if ( in_array( $status, array( 'open', 'closed' ), true ) ) {
                $new_status = $status;
                update_post_meta( $id, 'dap_ticket_status', $status );
            }
        }
        if ( isset( $_POST['priority'] ) ) {
            $priority = sanitize_key( wp_unslash( $_POST['priority'] ) );
            if ( in_array( $priority, array( 'low', 'normal', 'high', 'urgent' ), true ) ) {
                update_post_meta( $id, 'dap_ticket_priority', $priority );
            }
        }
        if ( ! empty( $_POST['internal_note'] ) ) {
            $notes = (array) get_post_meta( $id, 'dap_internal_notes', true );
            $note = DSA2_Compat::substr( sanitize_textarea_field( wp_unslash( $_POST['internal_note'] ) ), 0, 3000 );
            if ( '' !== $note ) {
                $notes[] = array( 'user_id'=>get_current_user_id(), 'note'=>$note, 'time'=>current_time('mysql') );
                update_post_meta( $id, 'dap_internal_notes', array_slice( $notes, -200 ) );
            }
        }
        wp_update_post( array( 'ID'=>$id ) );

        if ( $new_status !== $old_status ) {
            $cid = (int) get_post_meta( $id, 'dap_conversation_id', true );
            if ( $cid ) {
                global $wpdb;
                $lang = (string) $wpdb->get_var( $wpdb->prepare( "SELECT language FROM {$wpdb->prefix}dap_conversations WHERE id=%d", $cid ) );
                if ( ! in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ) $lang = 'fr';
                if ( 'closed' === $new_status ) {
                    $text = 'en' === $lang ? "Ticket #$id closed by the team." : ( 'ht' === $lang ? "Ekip la fèmen tikè #$id." : "Ticket #$id fermé par l’équipe." );
                } else {
                    $text = 'en' === $lang ? "Ticket #$id reopened by the team." : ( 'ht' === $lang ? "Ekip la relouvri tikè #$id." : "Ticket #$id rouvert par l’équipe." );
                }
                DSA2_Data::add_message( $cid, 'agent', $text, 'text', array( 'ticket_id'=>$id, 'ticket_status'=>$new_status ), 'agent' );
                if ( 'open' === $new_status ) DSA2_Data::request_agent( $cid );
            }
        }
        if ( class_exists( 'DSA2_Production' ) ) DSA2_Production::audit( 'ticket_updated', array( 'ticket_id'=>$id, 'status'=>$new_status ) );
        $return = wp_get_referer() ?: admin_url( 'admin.php?page=dap-agent-workspace' );
        wp_safe_redirect( add_query_arg( 'updated', '1', $return ) );
        exit;
    }
}
