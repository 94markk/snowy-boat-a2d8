<?php
/** PRO14: owner-checked, durable checkout intents; WooCommerce alone processes payment. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Delicat_Builder_V9_Express_Payment {
 private static $context = null;

 /** Read the database, not a possibly stale per-request/persistent options cache. */
 public static function read( string $key ) {
  global $wpdb;
  $raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
  if ( $wpdb->last_error ) { throw new RuntimeException( 'Vérification du paiement indisponible. Réessayez plus tard.' ); }
  return null === $raw ? null : maybe_unserialize( $raw );
 }
 private static function invalidate( string $key ): void {
  wp_cache_delete( $key, 'options' );
  wp_cache_delete( 'notoptions', 'options' );
 }
 public static function insert( string $key, $value ): bool {
  global $wpdb;
  // No ON DUPLICATE KEY UPDATE. Exactly one contender may insert the UNIQUE option_name.
  $result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $key, maybe_serialize( $value ) ) );
  self::invalidate( $key );
  if ( false === $result || $wpdb->last_error ) { throw new RuntimeException( 'Protection du paiement indisponible.' ); }
  return 1 === $result;
 }
 /** Compare-and-swap; never release or overwrite another request's value. */
 public static function change( string $key, $before, $after ): bool {
  global $wpdb;
  $result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s", maybe_serialize( $after ), $key, maybe_serialize( $before ) ) );
  self::invalidate( $key );
  if ( false === $result || $wpdb->last_error ) { throw new RuntimeException( 'Enregistrement du paiement indisponible.' ); }
  return 1 === $result || ( 0 === $result && self::read( $key ) === $after );
 }
 public static function release( string $key, string $owner ): bool {
  global $wpdb;
  $result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = BINARY %s", $key, $owner ) );
  self::invalidate( $key );
  if ( false === $result || $wpdb->last_error ) { throw new RuntimeException( 'Vérification du paiement requise.' ); }
  return 1 === $result;
 }
 private static function input( string $name ): string {
  return isset( $_POST[ $name ] ) && is_scalar( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) ) : '';
 }
 private static function keys( int $uid, string $id ): array {
  return array( 'active' => 'delicat_payment_active_' . $uid, 'key' => 'delicat_payment_' . $uid . '_' . $id );
 }
 private static function fail( string $message, string $state = 'blocked' ): void {
  wp_send_json( array( 'result' => 'failure', 'delicat_state' => $state, 'messages' => '<ul><li>' . esc_html( $message ) . '</li></ul>' ) );
 }
 public static function fields(): void {
  if ( ! is_user_logged_in() || ! WC()->cart || ! WC()->session || WC()->cart->is_empty() ) { return; }
  $hash = WC()->cart->get_cart_hash();
  $intent = WC()->session->get( 'delicat_payment_intent' );
  if ( ! is_array( $intent ) || ( $intent['hash'] ?? '' ) !== $hash || empty( $intent['issued'] ) ) {
   $intent = array( 'hash' => $hash, 'id' => wp_generate_uuid4(), 'issued' => sprintf( '%.6F', microtime( true ) ) );
   WC()->session->set( 'delicat_payment_intent', $intent );
  }
  foreach ( array( 'delicat_intent' => $intent['id'], 'delicat_cart_hash' => $hash, 'delicat_issued' => $intent['issued'], 'delicat_intent_nonce' => wp_create_nonce( 'delicat_pay_' . $intent['id'] . '_' . $hash . '_' . $intent['issued'] ) ) as $key => $value ) {
   echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
  }
 }
 private static function credentials(): array {
  $id = self::input( 'delicat_intent' ); $hash = self::input( 'delicat_cart_hash' ); $issued = self::input( 'delicat_issued' );
  if ( ! is_ssl() || ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) || ! is_numeric( $issued ) || ! wp_verify_nonce( self::input( 'delicat_intent_nonce' ), 'delicat_pay_' . $id . '_' . $hash . '_' . $issued ) ) {
   throw new RuntimeException( 'Session expirée. Actualisez la commande avant de continuer.' );
  }
  return array( $id, $hash, $issued );
 }
 private static function begin(): void {
  if ( self::$context ) { return; }
  list( $id, $hash, $issued ) = self::credentials();
  $uid = get_current_user_id(); $keys = self::keys( $uid, $id );
  if ( self::read( $keys['key'] ) ) { throw new RuntimeException( 'Cette demande a déjà été envoyée. Vérifiez vos commandes.' ); }
  if ( ! self::insert( $keys['active'], $id ) ) { throw new RuntimeException( 'Un paiement est déjà en cours ou à vérifier dans vos commandes.' ); }
  try {
   // Everything that decides whether to charge is rechecked AFTER owning the mutex.
   $last = (float) self::read( 'delicat_payment_completed_' . $uid );
   if ( (float) $issued <= $last ) { throw new RuntimeException( 'Une commande a été payée depuis cette vérification. Actualisez le panier.' ); }
   if ( ! WC()->cart || WC()->cart->is_empty() ) { throw new RuntimeException( 'Votre panier est vide.' ); }
   WC()->cart->calculate_totals();
   if ( ! hash_equals( WC()->cart->get_cart_hash(), $hash ) ) { throw new RuntimeException( 'Le panier a changé. Actualisez pour vérifier le total.' ); }
   $record = array( 'status' => 'pending', 'order_id' => 0, 'created' => time(), 'hash' => $hash, 'issued' => $issued, 'creating' => false, 'method' => self::input( 'payment_method' ), 'balance' => self::wallet_balance( $uid ) );
   if ( ! self::insert( $keys['key'], $record ) ) { throw new RuntimeException( 'Cette demande a déjà été envoyée.' ); }
   self::$context = array_merge( $keys, array( 'uid' => $uid, 'id' => $id, 'record' => $record ) );
  } catch ( Throwable $e ) { self::release( $keys['active'], $id ); throw $e; }
  WC()->session->__unset( 'delicat_payment_intent' );
 }
 private static function save( array $record ): void {
  $c = self::$context;
  if ( ! $c || ! self::change( $c['key'], $c['record'], $record ) ) { throw new RuntimeException( 'État du paiement incertain. Consultez vos commandes.' ); }
  self::$context['record'] = $record;
 }
 public static function guard_classic(): void {
  if ( self::$context || ! is_user_logged_in() ) { return; }
  // All signed-in classic checkouts participate, even when another gateway is selected.
  self::begin();
 }
 public static function validated( $data, $errors ): void {
  if ( ! self::$context ) { return; }
  if ( ! hash_equals( self::$context['record']['hash'], WC()->cart->get_cart_hash() ) ) { $errors->add( 'delicat_cart_changed', 'Le total a changé. Actualisez la commande.' ); }
  if ( $errors->has_errors() || wc_notice_count( 'error' ) ) {
   $record = self::$context['record']; $record['status'] = 'rejected'; self::save( $record );
   WC()->session->set( 'refresh_totals', true );
   self::release( self::$context['active'], self::$context['id'] ); self::$context = null;
  }
 }
 public static function creating( $order, $data ): void {
  if ( ! self::$context ) { return; }
  $record = self::$context['record']; $record['creating'] = true; self::save( $record );
  $order->update_meta_data( '_delicat_payment_intent', self::$context['id'] );
 }
 public static function order_created( $order ): void {
  if ( ! self::$context ) { return; }
  $record = self::$context['record']; $record['order_id'] = $order->get_id(); self::save( $record );
 }
 public static function order_processed( $id, $data, $order ): void {
  // Also covers WooCommerce's reuse of order_awaiting_payment.
  if ( ! self::$context ) { return; }
  $order->update_meta_data( '_delicat_payment_intent', self::$context['id'] ); $order->save();
  self::order_created( $order );
 }
 public static function success( $result, $order_id ) {
  if ( ! self::$context ) { return $result; }
  $outcome = is_array( $result ) ? (string) ( $result['result'] ?? '' ) : '';
  if ( 'success' !== $outcome ) {
   /*
    * pro.16b: WC_Checkout::process_order_payment() applies this filter to EVERY
    * gateway result, not only successes, so a decline reaches us here. Recording
    * it is the only positive evidence that a gateway ran and said no; the absence
    * of a fatal proves nothing (a gateway that throws, or a request killed on a
    * dropped mobile link, leaves this key unset and the guard held).
    */
   try { $record = self::$context['record']; $record['gateway_result'] = '' !== $outcome ? $outcome : 'failure'; self::save( $record ); }
   catch ( Throwable $e ) { unset( $e ); /* Unrecorded verdict: shutdown() then fails closed. */ }
   return $result;
  }
  $order = wc_get_order( $order_id );
  if ( ! $order || (int) $order->get_customer_id() !== self::$context['uid'] ) { throw new RuntimeException( 'Commande à vérifier.' ); }
  $record = self::$context['record']; $record['order_id'] = (int) $order_id;
  $record['status'] = $order->is_paid() ? 'confirmed' : 'submitted'; $record['response'] = $result;
  self::save( $record );
  $key = 'delicat_payment_completed_' . self::$context['uid']; $stamp = sprintf( '%.6F', microtime( true ) ); $before = self::read( $key );
  if ( null === $before ? ! self::insert( $key, $stamp ) : ! self::change( $key, $before, $stamp ) ) { throw new RuntimeException( 'Résultat à vérifier dans vos commandes.' ); }
  // Hold the mutex until PHP shutdown, after WooCommerce saves its session.
  self::$context['finished'] = true;
  return $result;
 }
 public static function no_payment( $url, $order ) {
  self::success( array( 'result' => 'success', 'redirect' => $url ), $order->get_id() ); return $url;
 }
 public static function shutdown(): void {
  if ( ! self::$context ) { return; }
  $c = self::$context;
  if ( ! empty( $c['finished'] ) ) {
   try { self::release( $c['active'], $c['id'] ); } catch ( Throwable $e ) { /* Retain guard on storage failure. */ }
   self::$context = null; return;
  }
  // A failure strictly before order creation can be retried with a fresh review.
  if ( empty( $c['record']['creating'] ) && empty( $c['record']['order_id'] ) ) {
   try { $r = $c['record']; $r['status'] = 'rejected'; self::save( $r ); self::release( $c['active'], $c['id'] ); } catch ( Throwable $e ) { /* Fail closed. */ }
   return;
  }
  /*
   * PRO16: once creation began, PRO14 kept the per-customer guard until an
   * administrator released it by hand. That was right for an UNKNOWN outcome,
   * but it also fired on the most common outcome of all — the gateway said no
   * (insufficient wallet balance, refused card) — and every later checkout of
   * that customer then failed with "Un paiement est déjà en cours" until an
   * admin intervened. A decline is a known result: the request ran to
   * completion without a fatal, WooCommerce never reported a successful
   * payment, the order is unpaid, and the customer's wallet balance is
   * exactly what it was before the attempt. Only then is the guard released;
   * the intent record itself is kept (status "declined") so the same intent
   * can never be replayed. Anything less certain still fails closed.
   */
  if ( ! self::declined_without_charge( $c ) ) { return; }
  try {
   $r = $c['record']; $r['status'] = 'declined'; self::save( $r );
   self::release( $c['active'], $c['id'] );
  } catch ( Throwable $e ) { return; /* Fail closed: the guard stays for an administrator. */ }
  /*
   * The note is written only after the release. add_order_note() inserts a comment
   * and fires hooks that reach LiteSpeed, Cloudflare and any mailer; this runs inside
   * a shutdown callback, after do_action('shutdown'), so a throw in that stack must
   * not be able to leave the record saying 'declined' while the guard is still held —
   * the customer would be blocked by a message contradicting the one they just read.
   */
  try {
   $order = wc_get_order( (int) $c['record']['order_id'] );
   if ( $order ) { $order->add_order_note( 'Paiement refusé par la passerelle, aucun débit constaté : protection paiement direct libérée automatiquement.' ); }
  } catch ( Throwable $e ) { unset( $e ); }
 }
 /** Wallet balance snapshot (TeraWallet API), or null when it cannot be read. */
 private static function wallet_balance( int $uid ): ?string {
  if ( $uid <= 0 || ! function_exists( 'woo_wallet' ) ) { return null; }
  try {
   $wallet = woo_wallet();
   if ( ! is_object( $wallet ) || ! isset( $wallet->wallet ) || ! is_callable( array( $wallet->wallet, 'get_wallet_balance' ) ) ) { return null; }
   /* get_wallet_balance() returns wc_price() HTML in 'view' context and is run through
    * a public filter, so a non-numeric answer is real. Casting it would turn an
    * unreadable balance into a stable 0.0000 that compares equal to itself and would
    * license a release with no comparison having happened. Mirror the guard in
    * pro/class-dbp-state.php and fail closed instead. */
   $raw = $wallet->wallet->get_wallet_balance( $uid, 'edit' );
   if ( ! is_numeric( $raw ) ) { return null; }
   return sprintf( '%.4F', (float) $raw );
  } catch ( Throwable $e ) { return null; }
 }
 /**
  * True only when this attempt verifiably ended as a decline that moved no money.
  *
  * Every condition below must hold. Any one of them being unknown keeps the durable
  * guard and leaves the case to the administrator reconciliation screen, which is
  * PRO14's behaviour. Releasing wrongly is the one failure this module exists to
  * prevent: it tells the customer nothing was charged and re-arms the pay button.
  */
 private static function declined_without_charge( array $c ): bool {
  /*
   * 1. The wallet gateway, and only the wallet gateway.
   *
   * guard_classic() puts EVERY signed-in classic checkout under this mutex, including
   * MonCash and card gateways (a wallet top-up is itself an ordinary checkout). For
   * those, comparing the wallet balance proves nothing about whether the PSP captured
   * the payment — and an external charge whose HTTP response was lost on a 2G link
   * looks identical to a clean decline. Exact match, not a substring: an empty
   * payment_method is not evidence of anything.
   */
  $method = (string) ( $c['record']['method'] ?? 'wallet' );
  if ( 'wallet' !== $method ) { return false; }

  /*
   * 2. A gateway verdict that was actually observed, not inferred from silence.
   * success() records this for declines as well as successes.
   */
  $verdict = (string) ( $c['record']['gateway_result'] ?? '' );
  if ( '' === $verdict || 'success' === $verdict ) { return false; }

  /*
   * 3. No fatal — as an additional veto only. error_get_last() is not trustworthy on
   * its own here: shutdown callbacks registered before this one run first and any
   * notice they raise overwrites the fatal, and it is null after a client abort.
   */
  $error = error_get_last();
  if ( is_array( $error ) && in_array( (int) ( $error['type'] ?? 0 ), array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) { return false; }

  /* 4. An order exists and carries no sign of payment. */
  $order_id = (int) ( $c['record']['order_id'] ?? 0 );
  if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) { return false; }
  $order = wc_get_order( $order_id );
  if ( ! is_object( $order ) || ! is_callable( array( $order, 'is_paid' ) ) ) { return false; }
  if ( $order->is_paid() || $order->get_date_paid() || '' !== (string) $order->get_transaction_id() ) { return false; }
  if ( ! in_array( (string) $order->get_status(), array( 'pending', 'failed', 'cancelled' ), true ) ) { return false; }

  /*
   * 5. No partial wallet payment. TeraWallet debits the wallet share from
   * woocommerce_checkout_order_processed — before any gateway runs — and marks the
   * order. A marked order has already moved money even though it is still pending.
   */
  if ( is_callable( array( $order, 'get_meta' ) ) && '' !== (string) $order->get_meta( '_via_wallet_payment' ) ) { return false; }

  /* 6. Nothing in the wallet ledger references this order. */
  if ( ! self::wallet_ledger_quiet( $order_id ) ) { return false; }

  /*
   * 7. And finally the balance, from two readable numeric snapshots. This is the last
   * check rather than the only one, because it reads TeraWallet's derived balance meta:
   * a debit whose ledger row was written but whose meta had not caught up would read
   * as "no charge". Step 6 covers that ordering; this covers the reverse.
   */
  $before = $c['record']['balance'] ?? null;
  $after  = self::wallet_balance( (int) ( $c['uid'] ?? 0 ) );
  if ( null === $before || null === $after ) { return false; }
  return abs( (float) $before - (float) $after ) < 0.00001;
 }

 /**
  * False when TeraWallet's ledger holds a transaction for this order, or when the
  * ledger exists but cannot be read. True only when there is demonstrably nothing
  * there, or when TeraWallet keeps no such table on this install.
  */
 private static function wallet_ledger_quiet( int $order_id ): bool {
  global $wpdb;
  if ( $order_id <= 0 ) { return false; }
  $table  = $wpdb->prefix . 'woo_wallet_transaction_meta';
  $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
  if ( $wpdb->last_error ) { return false; }
  if ( $table !== $exists ) { return true; }
  $count = $wpdb->get_var(
   $wpdb->prepare(
    "SELECT COUNT(*) FROM `{$table}` WHERE meta_key IN ( '_wallet_payment_order_id', '_partial_payment_order_id', '_refund_order_id' ) AND meta_value = %s",
    (string) $order_id
   )
  );
  if ( $wpdb->last_error || null === $count ) { return false; }
  return 0 === (int) $count;
 }
 /** Daily: drop finished intent records once no nonce could still replay them. */
 public static function schedule_cleanup(): void {
  if ( ! wp_next_scheduled( 'delicat_builder_v9_payment_cleanup' ) ) {
   wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'delicat_builder_v9_payment_cleanup' );
  }
 }
 public static function cleanup(): void {
  global $wpdb;
  /* ORDER BY option_id: without it MySQL satisfies the LIKE range from the option_name
   * UNIQUE index, i.e. lexicographically by customer id as text, so past one window the
   * same low-sorting prefix is rescanned every run and older records belonging to
   * higher-sorting customer ids are never reclaimed. */
  $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_id ASC LIMIT 500", $wpdb->esc_like( 'delicat_payment_' ) . '%', $wpdb->esc_like( 'delicat_payment_active_' ) . '%', $wpdb->esc_like( 'delicat_payment_completed_' ) . '%' ) );
  /* A record is what refuses a replay of its intent, so it must outlive the nonce that
   * could carry that replay. Default nonce_life is 24h; a site may filter it longer. */
  $cutoff = time() - max( 7 * DAY_IN_SECONDS, 3 * (int) apply_filters( 'nonce_life', DAY_IN_SECONDS ) );
  foreach ( (array) $rows as $row ) {
   $record = maybe_unserialize( $row->option_value );
   $created = is_array( $record ) ? (int) ( $record['created'] ?? 0 ) : 0;
   if ( $created <= 0 || $created > $cutoff || ! preg_match( '/^delicat_payment_(\d+)_(.+)$/', (string) $row->option_name, $m ) ) { continue; }
   // Never remove the record behind a guard that is still held.
   try { $active = self::read( 'delicat_payment_active_' . (int) $m[1] ); } catch ( Throwable $e ) { continue; }
   if ( is_string( $active ) && hash_equals( $active, (string) $m[2] ) ) { continue; }
   delete_option( (string) $row->option_name );
  }
 }
 public static function pay(): void {
  nocache_headers();
  try {
   self::credentials();
   if ( ! wp_verify_nonce( self::input( 'woocommerce-process-checkout-nonce' ), 'woocommerce-process_checkout' ) ) { throw new RuntimeException( 'Session expirée. Rouvrez la fenêtre.' ); }
   if ( '1' !== self::input( 'delicat_consent' ) ) { throw new RuntimeException( 'Veuillez accepter les conditions avant de payer.' ); }
   if ( 'wallet' !== self::input( 'payment_method' ) || ! WC()->cart || WC()->cart->needs_shipping() ) { throw new RuntimeException( 'Utilisez le paiement complet pour cette commande.' ); }
   if ( ! defined( 'WOOCOMMERCE_CHECKOUT' ) ) { define( 'WOOCOMMERCE_CHECKOUT', true ); }
   self::begin();
   $gateways = WC()->payment_gateways()->get_available_payment_gateways();
   if ( ! isset( $gateways['wallet'] ) ) { throw new RuntimeException( 'Delicat Wallet est indisponible pour cette commande.' ); }
   WC()->checkout()->process_checkout();
   self::fail( 'Paiement non confirmé. Consultez vos commandes.' );
  } catch ( Throwable $e ) { self::fail( $e instanceof RuntimeException ? $e->getMessage() : 'Paiement à vérifier dans vos commandes.' ); }
 }
 public static function status(): void {
  nocache_headers();
  try {
   list( $id ) = self::credentials(); $keys = self::keys( get_current_user_id(), $id ); $record = self::read( $keys['key'] );
   $response = array( 'state' => 'unknown', 'ordersUrl' => wc_get_account_endpoint_url( 'orders' ) );
   if ( is_array( $record ) ) {
    $response['state'] = in_array( $record['status'], array( 'rejected', 'declined', 'confirmed', 'submitted' ), true ) ? $record['status'] : 'pending';
    /* Only a wallet decline is safe for the client to present as "no debit": for any
     * other method the server verified nothing about that gateway's books. */
    $response['walletDecline'] = ( 'declined' === $response['state'] && 'wallet' === (string) ( $record['method'] ?? '' ) );
    if ( ! empty( $record['order_id'] ) ) {
     $order = wc_get_order( $record['order_id'] );
     if ( $order && (int) $order->get_customer_id() === get_current_user_id() ) {
      if ( $order->is_paid() ) { $response['state'] = 'confirmed'; $response['redirect'] = $order->get_checkout_order_received_url(); }
      else { $response['orderStatus'] = $order->get_status(); }
     }
    }
   }
   // This endpoint reads only. It never charges, retries, or unlocks an uncertain intent.
   wp_send_json( $response );
  } catch ( Throwable $e ) { wp_send_json( array( 'state' => 'unavailable' ), 403 ); }
 }
}
add_action( 'woocommerce_review_order_before_submit', array( 'Delicat_Builder_V9_Express_Payment', 'fields' ), 99 );
add_action( 'wc_ajax_delicat_express_pay', array( 'Delicat_Builder_V9_Express_Payment', 'pay' ) );
add_action( 'wc_ajax_delicat_express_status', array( 'Delicat_Builder_V9_Express_Payment', 'status' ) );
add_action( 'woocommerce_checkout_process', array( 'Delicat_Builder_V9_Express_Payment', 'guard_classic' ), 1 );
add_action( 'woocommerce_after_checkout_validation', array( 'Delicat_Builder_V9_Express_Payment', 'validated' ), PHP_INT_MAX, 2 );
add_action( 'woocommerce_checkout_create_order', array( 'Delicat_Builder_V9_Express_Payment', 'creating' ), 1, 2 );
add_action( 'woocommerce_checkout_order_created', array( 'Delicat_Builder_V9_Express_Payment', 'order_created' ), 1 );
add_action( 'woocommerce_checkout_order_processed', array( 'Delicat_Builder_V9_Express_Payment', 'order_processed' ), 1, 3 );
add_filter( 'woocommerce_payment_successful_result', array( 'Delicat_Builder_V9_Express_Payment', 'success' ), PHP_INT_MAX, 2 );
add_filter( 'woocommerce_checkout_no_payment_needed_redirect', array( 'Delicat_Builder_V9_Express_Payment', 'no_payment' ), PHP_INT_MAX, 2 );
register_shutdown_function( array( 'Delicat_Builder_V9_Express_Payment', 'shutdown' ) );
add_action( 'init', array( 'Delicat_Builder_V9_Express_Payment', 'schedule_cleanup' ) );
add_action( 'delicat_builder_v9_payment_cleanup', array( 'Delicat_Builder_V9_Express_Payment', 'cleanup' ) );

add_action( 'admin_notices', static function () {
 if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
 global $wpdb;
 $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 20", $wpdb->esc_like( 'delicat_payment_active_' ) . '%' ) );
 foreach ( (array) $rows as $row ) {
  $uid = absint( str_replace( 'delicat_payment_active_', '', $row->option_name ) );
  try { $record = Delicat_Builder_V9_Express_Payment::read( 'delicat_payment_' . $uid . '_' . $row->option_value ); } catch ( Throwable $e ) { continue; }
  echo '<div class="notice notice-warning"><p>Paiement direct en cours / à vérifier — client #' . esc_html( $uid ) . ', commande #' . esc_html( $record['order_id'] ?? 0 ) . '. Vérifiez la commande ET le débit wallet avant de libérer cette protection.</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
  wp_nonce_field( 'delicat_reconcile_' . $uid . '_' . $row->option_value );
  echo '<input type="hidden" name="intent" value="' . esc_attr( $row->option_value ) . '">';
  echo '<input type="hidden" name="action" value="delicat_reconcile_payment"><input type="hidden" name="customer" value="' . esc_attr( $uid ) . '"><label><input type="checkbox" name="verified" value="1" required> Traitement terminé et résultat vérifié dans WooCommerce et le wallet</label> <button class="button">Libérer après vérification</button></form></div>';
 }
} );
add_action( 'admin_post_delicat_reconcile_payment', static function () {
 if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
 $uid = absint( $_POST['customer'] ?? 0 );
 $submitted = sanitize_text_field( wp_unslash( $_POST['intent'] ?? '' ) );
 check_admin_referer( 'delicat_reconcile_' . $uid . '_' . $submitted );
 if ( '1' !== ( $_POST['verified'] ?? '' ) ) { wp_die( 'Vérification requise.' ); }
 $active = 'delicat_payment_active_' . $uid;
 try {
 $id = Delicat_Builder_V9_Express_Payment::read( $active );
 if ( ! is_string( $id ) || ! hash_equals( $id, $submitted ) ) { wp_die( 'Cette protection a changé. Actualisez la page.' ); }
 $record = Delicat_Builder_V9_Express_Payment::read( 'delicat_payment_' . $uid . '_' . $id );
 if ( time() - (int) ( $record['created'] ?? time() ) < 300 ) { wp_die( 'Le traitement est récent. Attendez cinq minutes puis vérifiez son résultat.' ); }
 if ( ! empty( $record['order_id'] ) ) {
  $order = wc_get_order( $record['order_id'] );
  if ( $order ) { $order->add_order_note( 'Protection paiement direct libérée après vérification par administrateur #' . get_current_user_id() ); }
 }
 if ( ! Delicat_Builder_V9_Express_Payment::release( $active, $submitted ) ) { wp_die( 'Cette protection a changé. Actualisez la page.' ); }
 } catch ( Throwable $e ) { wp_die( 'Vérification indisponible. La protection reste en place.' ); }
 wp_safe_redirect( admin_url() ); exit;
} );
