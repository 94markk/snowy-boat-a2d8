<?php
/** 4.0 — HTG wallet rewards. Legacy PIN orders remain readable, never converted twice. */
defined( 'ABSPATH' ) || exit;

function delicat_wallet_available() {
    return function_exists( 'woo_wallet' ) && is_object( woo_wallet() ) && isset( woo_wallet()->wallet ) && is_callable( array( woo_wallet()->wallet, 'credit' ) ) && 'HTG' === strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
}
function delicat_reward_money( $amount ) {
    return rtrim( rtrim( number_format( (float) $amount, 2, ',', ' ' ), '0' ), ',' ) . ' HTG';
}
/** Authoritative HTG price, independent of stock. Explicit override wins; never use browser input. */
function delicat_reward_value( $diamonds ) {
    static $cache = array();
    $diamonds = (int) $diamonds;
    if ( $diamonds < 1 || $diamonds > DELICAT_MAX_REWARD ) return 0.0;
    if ( isset( $cache[ $diamonds ] ) ) return $cache[ $diamonds ];
    $values = (array) delicat_cfg( 'credit_values' );
    if ( isset( $values[ $diamonds ] ) && is_numeric( $values[ $diamonds ] ) && (float) $values[ $diamonds ] > 0 ) return $cache[ $diamonds ] = round( min( 1000000, (float) $values[ $diamonds ] ), 2 );
    if ( 'HTG' !== strtoupper( (string) get_option( 'woocommerce_currency', '' ) ) ) return 0.0;
    $pid = delicat_pid_by_diamonds( $diamonds );
    $product = $pid && function_exists( 'wc_get_product' ) ? wc_get_product( $pid ) : false;
    if ( $product && ! $product->is_type( 'variable' ) && 'publish' === $product->get_status() && is_numeric( $product->get_price( 'edit' ) ) && (float) $product->get_price( 'edit' ) > 0 ) return $cache[ $diamonds ] = round( (float) $product->get_price( 'edit' ), 2 );
    $plan = delicat_reward_plan( $diamonds, false ); $total = 0;
    if ( ! $plan ) return $cache[ $diamonds ] = 0.0;
    foreach ( $plan as $line ) {
        if ( (int) $line['denom'] === $diamonds ) return $cache[ $diamonds ] = 0.0;
        $v = delicat_reward_value( $line['denom'] );
        if ( $v <= 0 ) return $cache[ $diamonds ] = 0.0;
        $total += $v * (int) $line['qty'];
    }
    return $cache[ $diamonds ] = round( $total, 2 );
}

/** Bind the value, owner, deadline and program identity to the server's signing key. */
function delicat_wallet_order_signature( $o ) {
    $fields = array( $o->get_id(), $o->get_customer_id() );
    foreach ( array( '_delicat_reward', '_delicat_reward_kind', '_delicat_reward_amount', '_delicat_wallet_amount', '_delicat_reward_expires', '_delicat_reward_month', '_delicat_week_boundary', '_delicat_week_rank' ) as $k ) $fields[] = (string) $o->get_meta( $k );
    return delicat_sign( 'wallet-order|' . implode( '|', $fields ) );
}
function delicat_create_wallet_reward( $uid, $diamonds, $tag, array $meta, $expires ) {
    if ( ! function_exists( 'wc_create_order' ) || (int) delicat_cfg( 'dry_run' ) === 1 || ! get_userdata( (int) $uid ) || (int) $expires <= time() ) return 0;
    $amount = delicat_reward_value( $diamonds );
    if ( $amount <= 0 ) return 0;
    $o = null; $saved = false;
    try {
        $o = wc_create_order( array( 'customer_id' => (int) $uid ) );
        if ( is_wp_error( $o ) || ! $o ) return 0;
        $o->set_created_via( 'delicat-rewards-wallet' );
        $o->set_currency( 'HTG' ); $o->set_total( 0 );
        $o->update_meta_data( '_delicat_reward', sanitize_key( $tag ) );
        $o->update_meta_data( '_delicat_reward_delivery', 'wallet' );
        $o->update_meta_data( '_delicat_wallet_amount', number_format( $amount, 2, '.', '' ) );
        $o->update_meta_data( '_delicat_reward_expires', (int) $expires );
        $o->update_meta_data( '_delicat_wallet_status', 'claimable' );
        foreach ( $meta as $k => $v ) $o->update_meta_data( '_' . ltrim( sanitize_key( $k ), '_' ), sanitize_text_field( (string) $v ) );
        $o->save();
        $o->update_meta_data( '_delicat_wallet_sig', delicat_wallet_order_signature( $o ) );
        $o->save(); $saved = true;
        $o->add_order_note( 'Récompense à réclamer : ' . delicat_reward_money( $amount ) . ' avant le ' . wp_date( 'd/m/Y H:i', $expires ) . '. Aucun produit fournisseur à livrer.' );
    } catch ( Throwable $e ) {
        delicat_log( 'error', 'Wallet reward reservation interrupted.', array( 'order_id' => $saved ? $o->get_id() : 0 ) );
    }
    return $saved ? (int) $o->get_id() : 0;
}
function delicat_wallet_reference( $o ) {
    return 'Delicat Rewards ' . get_current_blog_id() . '#' . $o->get_id() . ' [' . substr( hash_hmac( 'sha256', $o->get_id() . '|' . $o->get_customer_id(), delicat_secret() ), 0, 24 ) . ']';
}
/** Read the wallet's ledger only to reconcile a response lost after its credit committed. */
function delicat_wallet_find_transaction( $o ) {
    global $wpdb;
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT transaction_id FROM {$wpdb->base_prefix}woo_wallet_transactions WHERE blog_id=%d AND user_id=%d AND type='credit' AND currency='HTG' AND amount=%s AND details=%s ORDER BY transaction_id ASC LIMIT 1",
        (int) get_current_blog_id(), (int) $o->get_customer_id(), (string) $o->get_meta( '_delicat_wallet_amount' ), delicat_wallet_reference( $o )
    ) );
}
function delicat_wallet_payout_key( $oid ) { return 'delicat_wallet_once_' . (int) $oid; }
/** No stale object-cache read may authorize another credit attempt. */
function delicat_wallet_payout_state( $oid ) {
    global $wpdb;
    $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", delicat_wallet_payout_key( $oid ) ) );
    if ( null === $raw || false === $raw ) return array();
    $state = maybe_unserialize( $raw );
    return is_array( $state ) && $state ? $state : array( 'status' => 'review', 'tx' => 0 );
}

/** One durable unique marker BEFORE calling TeraWallet. A failed/uncertain call is never blindly repeated. */
function delicat_credit_wallet_reward( $oid, $uid ) {
    $lock = 'delicat_wallet_reward_' . (int) $oid;
    if ( ! delicat_lock( $lock, 5 ) ) return false;
    try {
        $o = wc_get_order( (int) $oid );
        if ( ! $o || (int) $o->get_customer_id() !== (int) $uid || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) ) return false;
        if ( ! delicat_sig_ok( delicat_wallet_order_signature( $o ), (string) $o->get_meta( '_delicat_wallet_sig' ) ) ) return false;
        $state = delicat_wallet_payout_state( $oid );
        $tx = (int) ( $state['tx'] ?? $o->get_meta( '_delicat_wallet_tx' ) );
        if ( ! $tx && ! empty( $state ) && function_exists( 'woo_wallet' ) ) $tx = delicat_wallet_find_transaction( $o );
        if ( ! $tx && empty( $state ) ) {
            if ( time() >= (int) $o->get_meta( '_delicat_reward_expires' ) || $o->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) return false;
            if ( (int) delicat_cfg( 'dry_run' ) === 1 || delicat_is_excluded( $uid ) || ! delicat_wallet_available() ) return false;
            if ( (float) $o->get_meta( '_delicat_wallet_amount' ) <= 0 ) return false;
            $state = array( 'status' => 'crediting', 'at' => time(), 'tx' => 0 );
            // INSERT IGNORE is strictly insert-only even with a stale persistent object cache.
            // This permanent marker is NEVER swept or released, including on wallet failures.
            global $wpdb;
            $reserved = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", delicat_wallet_payout_key( $oid ), maybe_serialize( $state ) ) );
            if ( 1 !== (int) $reserved ) return false;
            try {
                $result = woo_wallet()->wallet->credit( (int) $uid, (float) $o->get_meta( '_delicat_wallet_amount' ), delicat_wallet_reference( $o ), array( 'currency' => 'HTG' ) );
                $tx = ! is_wp_error( $result ) && is_numeric( $result ) ? (int) $result : 0;
            } catch ( Throwable $e ) { $tx = 0; }
            if ( ! $tx ) $tx = delicat_wallet_find_transaction( $o );
        }
        if ( ! $tx ) {
            if ( ! empty( $state ) ) {
                $o->update_meta_data( '_delicat_wallet_status', 'review' ); $o->save();
                delicat_log( 'error', 'Wallet payout requires reconciliation; no automatic second credit.', array( 'order_id' => (int) $oid, 'user_id' => (int) $uid ) );
            }
            return false;
        }
        // The durable record survives a crash while saving the order or invoking WooCommerce hooks.
        update_option( delicat_wallet_payout_key( $oid ), array( 'status' => 'credited', 'tx' => $tx, 'at' => (int) ( $state['at'] ?? time() ) ), false );
        if ( (int) $o->get_meta( '_delicat_wallet_tx' ) !== $tx ) {
            $o->update_meta_data( '_delicat_wallet_tx', $tx );
            $o->update_meta_data( '_delicat_wallet_status', 'credited' );
            $o->update_meta_data( '_delicat_wallet_claimed_at', (int) ( $state['at'] ?? time() ) ); $o->save();
            $o->update_status( 'completed', 'Récompense créditée au portefeuille HTG. Transaction #' . $tx );
            if ( function_exists( 'clear_woo_wallet_cache' ) ) clear_woo_wallet_cache( (int) $uid );
            delicat_flush_user( $uid );
        }
        return true;
    } catch ( Throwable $e ) {
        delicat_log( 'error', 'Wallet payout interrupted; inspect saved transaction.', array( 'order_id' => (int) $oid ) ); return false;
    } finally { delicat_unlock( $lock ); }
}

function delicat_wallet_delivery( $o ) {
    $tx = (int) $o->get_meta( '_delicat_wallet_tx' );
    $expires = (int) $o->get_meta( '_delicat_reward_expires' );
    $state = delicat_wallet_payout_state( $o->get_id() );
    $status = $tx ? 'credited' : ( ! empty( $state ) ? 'review' : ( time() >= $expires ? 'expired' : 'claimable' ) );
    if ( ! $tx && $o->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) $status = 'unavailable';
    $amount = (float) $o->get_meta( '_delicat_wallet_amount' ); $date = $o->get_date_created();
    $messages = array(
        'credited' => 'Félicitations ! Vous avez gagné ' . delicat_reward_money( $amount ) . '. Ce montant a été ajouté à votre portefeuille Delicat Wallet.',
        'expired' => 'Cette récompense a expiré. Elle ne peut plus être réclamée.',
        'claimable' => 'Réclamez ' . delicat_reward_money( $amount ) . ' avant le ' . wp_date( 'd/m/Y H:i', $expires ) . ' (heure du site).',
        'review' => 'Votre demande est enregistrée. Le crédit est en cours de vérification. Contactez le support avec la référence #' . $o->get_id() . ' ; ne réclamez pas une seconde fois.',
        'unavailable' => 'Cette récompense n’est plus disponible. Contactez le support.',
    );
    return array( 'orderId' => (int) $o->get_id(), 'tier' => (string) $o->get_meta( '_delicat_reward' ), 'kind' => (string) $o->get_meta( '_delicat_reward_kind' ),
        'rewardType' => 'wallet', 'currency' => 'HTG', 'amount' => $amount, 'creditAmount' => $amount,
        'date' => $date ? $date->date( DATE_ATOM ) : '', 'status' => $status, 'transactionId' => $tx,
        'expiresAt' => $expires, 'claimedAt' => (int) $o->get_meta( '_delicat_wallet_claimed_at' ), 'expected' => 0, 'pins' => array(),
        'redeemUrl' => home_url( '/my-wallet/' ), 'message' => $messages[ $status ] );
}
function delicat_winner_token( $o ) {
    return delicat_sign( 'winner-claim|' . $o->get_id() . '|' . $o->get_customer_id() . '|' . $o->get_meta( '_delicat_reward_expires' ) );
}
function delicat_winner_rewards( $uid ) {
    if ( ! function_exists( 'wc_get_orders' ) ) return array( 'items' => array(), 'error' => 'WooCommerce indisponible.' );
    if ( (int) $uid !== (int) get_current_user_id() || (int) $uid < 1 ) return array( 'items' => array() );
    $args = array( 'customer_id' => (int) $uid, 'limit' => 30, 'orderby' => 'ID', 'order' => 'DESC', 'type' => 'shop_order' );
    $meta = array( array( 'key' => '_delicat_reward_kind', 'value' => 'weekly' ), array( 'key' => '_delicat_reward_delivery', 'value' => 'wallet' ) );
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) $args['meta_query'] = $meta;
    else $args['delicat_reward_meta'] = $meta;
    $orders = wc_get_orders( $args );
    $items = array(); $all = delicat_weekly_state_get();
    foreach ( $orders as $o ) {
        if ( 'weekly' !== $o->get_meta( '_delicat_reward_kind' ) || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) || (int) $o->get_customer_id() !== (int) $uid ) continue;
        $d = delicat_wallet_delivery( $o );
        $p = $all[ (int) $o->get_meta( '_delicat_week_boundary' ) ]['placements'][ (int) $o->get_meta( '_delicat_week_rank' ) ] ?? array();
        if ( ! empty( $p['verify'] ) && 'credited' !== $d['status'] ) { $d['status'] = 'review'; $d['message'] = 'Podium en cours de vérification après un remboursement.'; }
        $d['rank'] = (int) $o->get_meta( '_delicat_week_rank' );
        $d['token'] = 'claimable' === $d['status'] && delicat_meilleur_on() && ! delicat_is_excluded( $uid ) ? delicat_winner_token( $o ) : '';
        $items[] = $d;
    }
    return array( 'items' => $items, 'serverTime' => time() );
}
/** Serialized with closing, resetting, manual delivery and refund verification. */
function delicat_winner_claim( $oid, $token ) {
    $uid = (int) get_current_user_id();
    if ( ! $uid || ! get_userdata( $uid ) ) return new WP_Error( 'auth', 'Connectez-vous.', array( 'status' => 401 ) );
    if ( ! delicat_meilleur_on() || (int) delicat_cfg( 'dry_run' ) === 1 || delicat_is_excluded( $uid ) ) return new WP_Error( 'paused', 'Réclamation indisponible pour ce compte.', array( 'status' => 403 ) );
    if ( ! delicat_rate_ok( $uid ) ) return new WP_Error( 'slow_down', 'Trop de demandes.', array( 'status' => 429 ) );
    if ( ! delicat_lock( 'delicat_competition_control', 5 ) ) return new WP_Error( 'busy', 'Réessayez dans quelques instants.', array( 'status' => 409 ) );
    try {
        $o = wc_get_order( (int) $oid );
        if ( ! $o || (int) $o->get_customer_id() !== $uid || 'weekly' !== $o->get_meta( '_delicat_reward_kind' ) || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) ) return new WP_Error( 'not_found', 'Récompense introuvable.', array( 'status' => 404 ) );
        if ( ! delicat_sig_ok( delicat_winner_token( $o ), (string) $token ) ) return new WP_Error( 'stale', 'Demande invalide. Actualisez la page.', array( 'status' => 409 ) );
        $key = (int) $o->get_meta( '_delicat_week_boundary' ); $rank = (int) $o->get_meta( '_delicat_week_rank' );
        $all = delicat_weekly_state_get(); $p = $all[ $key ]['placements'][ $rank ] ?? array();
        if ( ! empty( $p['verify'] ) ) return new WP_Error( 'review', 'Le podium est en cours de vérification.', array( 'status' => 423 ) );
        if ( 'manual' === ( $p['status'] ?? '' ) ) return new WP_Error( 'claimed', 'Cette récompense a déjà été remise.', array( 'status' => 409 ) );
        $ok = delicat_credit_wallet_reward( $oid, $uid ); $o = wc_get_order( (int) $oid ); $d = delicat_wallet_delivery( $o );
        if ( $ok || 'expired' === $d['status'] ) {
            if ( $p ) { $all[ $key ]['placements'][ $rank ]['status'] = $ok ? 'claimed' : 'expired'; delicat_weekly_state_save( $all ); }
        }
        if ( ! $ok ) return new WP_Error( 'expired' === $d['status'] ? 'expired' : 'wallet_pending', 'claimable' === $d['status'] ? 'Le portefeuille est temporairement indisponible. Réessayez avant la date limite.' : $d['message'], array( 'status' => 'expired' === $d['status'] ? 410 : 503 ) );
        delicat_wallet_claim_email( $uid, $oid );
        return array( 'delivery' => $d, 'winners' => delicat_winner_rewards( $uid ) );
    } finally { delicat_unlock( 'delicat_competition_control' ); }
}

add_action( 'rest_api_init', function () {
    $auth = function () { return is_user_logged_in() && (bool) get_userdata( get_current_user_id() ); };
    register_rest_route( 'delicat/v1', '/winner-rewards', array( 'methods' => 'GET', 'permission_callback' => $auth, 'callback' => function () { return delicat_private_response( delicat_winner_rewards( get_current_user_id() ) ); } ) );
    register_rest_route( 'delicat/v1', '/winner-claim', array( 'methods' => 'POST', 'permission_callback' => $auth,
        'args' => array( 'order_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ), 'token' => array( 'required' => true, 'type' => 'string', 'maxLength' => 255 ) ),
        'callback' => function ( WP_REST_Request $r ) { $v = delicat_winner_claim( (int) $r->get_param( 'order_id' ), (string) $r->get_param( 'token' ) ); return is_wp_error( $v ) ? $v : delicat_private_response( $v ); } ) );
} );
function delicat_wallet_claim_email( $uid, $oid ) {
    $o = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : false;
    if ( ! $o || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) ) return false;
    if ( ! delicat_cfg( 'notify_client' ) || ! $o->get_meta( '_delicat_wallet_tx' ) || $o->get_meta( '_delicat_wallet_emailed' ) ) return true;
    $o->update_meta_data( '_delicat_wallet_emailed', 1 ); $o->save();
    delicat_mail_user( $uid, 'Delicat Store — récompense créditée', 'Félicitations ! Vous avez gagné ' . delicat_reward_money( $o->get_meta( '_delicat_wallet_amount' ) ) . '. Ce montant a été ajouté à votre portefeuille Delicat Wallet.\n' . home_url( '/my-wallet/' ) );
    return true;
}

/** Explicit price overrides are useful when suppliers/regions share the same diamond pack. */
add_action( 'admin_post_delicat_save_credit_values', function () {
    delicat_guard( 'delicat_save_credit_values' );
    $cfg = delicat_cfg(); $values = array();
    $rows = isset( $_POST['credit_values'] ) && is_array( $_POST['credit_values'] ) ? wp_unslash( $_POST['credit_values'] ) : array();
    foreach ( $rows as $denom => $v ) {
        $v = str_replace( ',', '.', trim( (string) $v ) );
        if ( (int) $denom > 0 && (int) $denom <= DELICAT_MAX_REWARD && is_numeric( $v ) && (float) $v > 0 && (float) $v <= 1000000 ) $values[ (int) $denom ] = round( (float) $v, 2 );
    }
    $cfg['credit_values'] = $values; $cfg['reward_method'] = 'wallet'; delicat_cfg_save( $cfg );
    delicat_audit_add( 'Montants HTG des récompenses enregistrés.' );
    delicat_back( 'reglages', array(), array( 'Montants enregistrés. Les récompenses Meilleur Client déjà disponibles gardent leur montant et leur échéance.' ) );
} );
function delicat_wallet_admin_values() {
    $denoms = array_keys( (array) delicat_cfg( 'pins' ) );
    foreach ( delicat_all_tiers() as $t ) $denoms[] = (int) $t['reward'];
    $denoms = array_unique( array_merge( $denoms, array_values( delicat_meilleur_prizes() ), array_keys( (array) delicat_cfg( 'credit_values' ) ) ) ); sort( $denoms );
    echo '<div class="box"><h2>Récompenses en argent — Delicat Wallet</h2><p>Bonus mensuels : à réclamer avant le 1er du mois suivant (heure WordPress). Meilleur Client : à réclamer dans les 5 jours suivant la mise à disposition. Le crédit reçu reste dans le portefeuille après cette échéance.</p><p>Montant automatique : prix WooCommerce HTG du produit associé, sans condition de stock. Pour fixer par exemple 110 diamants = 145 HTG, saisissez 145 ci-dessous. Un montant vide utilise le prix du catalogue ; un prix absent bloque la récompense.</p>';
    if ( ! delicat_wallet_available() ) echo '<p style="color:#b32d2e"><b>Activez TeraWallet / Delicat Wallet et la devise WooCommerce HTG avant les réclamations.</b></p>';
    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'delicat_save_credit_values' );
    echo '<input type="hidden" name="action" value="delicat_save_credit_values"><table class="widefat"><thead><tr><th>Équivalent diamants</th><th>Montant fixe HTG (facultatif)</th><th>Montant actuel</th></tr></thead><tbody>';
    $values = (array) delicat_cfg( 'credit_values' );
    foreach ( $denoms as $n ) {
        if ( $n <= 0 ) continue;
        echo '<tr><td>' . (int) $n . '</td><td><input type="number" min="0.01" max="1000000" step="0.01" name="credit_values[' . (int) $n . ']" value="' . esc_attr( $values[ $n ] ?? '' ) . '" placeholder="Prix automatique"></td><td>' . esc_html( delicat_reward_value( $n ) > 0 ? delicat_reward_money( delicat_reward_value( $n ) ) : 'À configurer' ) . '</td></tr>';
    }
    echo '</tbody></table><p><button class="button button-primary">Enregistrer les montants HTG</button></p></form></div>';
}

/** Availability notice carries the exact deadline; does not imply the money has been credited. */
function delicat_wallet_available_email( $o ) {
    if ( ! $o || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) || $o->get_meta( '_delicat_wallet_available_emailed' ) || ! delicat_cfg( 'notify_client' ) ) return;
    $o->update_meta_data( '_delicat_wallet_available_emailed', 1 ); $o->save();
    delicat_mail_user( $o->get_customer_id(), 'Delicat Store — votre récompense Meilleur Client vous attend',
        'Félicitations ! Votre récompense de ' . delicat_reward_money( $o->get_meta( '_delicat_wallet_amount' ) ) . ' vous attend. Cliquez sur « Réclamer » dans Classement avant le ' . wp_date( 'd/m/Y H:i', (int) $o->get_meta( '_delicat_reward_expires' ) ) . ' (heure du site). Après 5 jours sans réclamation, elle expire. ' . home_url( '/program-bon-kliyan/' ) );
}

/** The team may reconcile an interrupted attempt, but cannot use this action to issue a new credit. */
add_action( 'admin_post_delicat_wallet_reconcile', function () {
    delicat_guard( 'delicat_wallet_reconcile' );
    $oid = absint( $_POST['order_id'] ?? 0 );
    $o = function_exists( 'wc_get_order' ) ? wc_get_order( $oid ) : false;
    if ( ! $o || 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) || ! delicat_wallet_payout_state( $oid ) ) delicat_back( 'reglages', array( 'Aucune demande de crédit à vérifier pour cette référence.' ) );
    $ok = delicat_credit_wallet_reward( $oid, (int) $o->get_customer_id() );
    delicat_audit_add( 'Vérification du crédit récompense #' . $oid . ' : ' . ( $ok ? 'transaction confirmée' : 'à vérifier avec TeraWallet' ) );
    delicat_back( 'reglages', $ok ? array() : array( 'Aucune transaction correspondante confirmée. Vérifiez l’historique TeraWallet avec la référence de la récompense. Aucun second versement automatique effectué.' ), $ok ? array( 'Transaction confirmée. La récompense est affichée comme créditée.' ) : array() );
} );
function delicat_wallet_admin_review() {
    if ( ! function_exists( 'wc_get_orders' ) ) return;
    $meta = array( array( 'key' => '_delicat_reward_delivery', 'value' => 'wallet' ), array( 'key' => '_delicat_wallet_status', 'value' => 'review' ) );
    $args = array( 'type' => 'shop_order', 'limit' => 20, 'orderby' => 'ID', 'order' => 'DESC' );
    if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) $args['meta_query'] = $meta;
    else $args['delicat_reward_meta'] = $meta;
    $rows = wc_get_orders( $args );
    if ( ! $rows ) return;
    echo '<div class="box"><h3>Crédits à vérifier</h3><p>La vérification recherche une transaction déjà enregistrée dans TeraWallet. Elle ne fait jamais un second versement.</p>';
    foreach ( $rows as $o ) {
        if ( 'wallet' !== $o->get_meta( '_delicat_reward_delivery' ) || 'review' !== $o->get_meta( '_delicat_wallet_status' ) ) continue;
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">'; wp_nonce_field( 'delicat_wallet_reconcile' );
        echo '<input type="hidden" name="action" value="delicat_wallet_reconcile"><input type="hidden" name="order_id" value="' . (int) $o->get_id() . '"><p><b>#' . (int) $o->get_id() . '</b> · client #' . (int) $o->get_customer_id() . ' · ' . esc_html( delicat_reward_money( $o->get_meta( '_delicat_wallet_amount' ) ) ) . ' <button class="button">Vérifier le versement</button></p><p>Référence TeraWallet : <code>' . esc_html( delicat_wallet_reference( $o ) ) . '</code></p></form>';
    }
    echo '</div>';
}
