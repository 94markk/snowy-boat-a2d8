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
  if ( null === $raw ) { return null; }
  /*
   * Everything this class stores is an array or a string - never an object. A
   * serialized object reaching unserialize() is how PHP object injection turns
   * a database write into code execution, so one is refused outright rather
   * than trusted because "only we write here". Refusing fails the payment
   * closed, which is the safe direction.
   */
  if ( is_string( $raw ) && preg_match( '/(^|;|{)(?:O|C):\d+:"/', $raw ) ) {
   throw new RuntimeException( 'Vérification du paiement indisponible.' );
  }
  return is_serialized( $raw )
   ? unserialize( trim( $raw ), array( 'allowed_classes' => false ) )
   : $raw;
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
 /**
  * The customer's connection, not PHP's.
  *
  * TLS terminates at the edge on this storefront, so is_ssl() is false on a
  * site that is https end to end for every customer - and this gate then
  * refuses every express payment. See the bootstrap helper for why the
  * forwarded headers are safe to read here and only here.
  */
 private static function secure(): bool {
  return function_exists( 'delicat_builder_v9_request_is_secure' ) ? delicat_builder_v9_request_is_secure() : is_ssl();
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
  if ( ! self::secure() || ! is_user_logged_in() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! preg_match( '/^[a-f0-9-]{36}$/D', $id ) || ! is_numeric( $issued ) || ! wp_verify_nonce( self::input( 'delicat_intent_nonce' ), 'delicat_pay_' . $id . '_' . $hash . '_' . $issued ) ) {
   throw new RuntimeException( 'Session expirée. Actualisez la commande avant de continuer.' );
  }
  return array( $id, $hash, $issued );
 }
 private static function begin(): void {
  if ( self::$context ) { return; }
  list( $id, $hash, $issued ) = self::credentials();
  $uid = get_current_user_id(); $keys = self::keys( $uid, $id );
  if ( self::read( $keys['key'] ) ) { throw new RuntimeException( 'Cette demande a déjà été envoyée. Vérifiez vos commandes.' ); }
  if ( ! self::insert( $keys['active'], $id ) ) {
   /*
    * Someone already holds this customer's guard. Usually that is a payment in
    * flight and refusing is exactly right. But shutdown() is what releases it,
    * and shutdown() does not run when PHP is killed outright - a memory limit,
    * a request timeout, a worker restart. The guard then outlives the request
    * that took it and the customer cannot check out AT ALL, express or not,
    * until an administrator notices and releases it by hand.
    *
    * So a guard is reclaimed - but only when its own record proves nothing was
    * charged under it: no order created, creation never begun, and old enough
    * that no request could still be working on it. Anything that reached order
    * creation keeps the guard and the reconciliation screen, because a possible
    * double charge is worse than a customer who has to wait.
    */
   if ( ! self::reclaim_abandoned( $keys['active'], $uid ) || ! self::insert( $keys['active'], $id ) ) {
    throw new RuntimeException( 'Un paiement est déjà en cours ou à vérifier dans vos commandes.' );
   }
  }
  try {
   // Everything that decides whether to charge is rechecked AFTER owning the mutex.
   $last = (float) self::read( 'delicat_payment_completed_' . $uid );
   if ( (float) $issued <= $last ) { throw new RuntimeException( 'Une commande a été payée depuis cette vérification. Actualisez le panier.' ); }
   if ( ! WC()->cart || WC()->cart->is_empty() ) { throw new RuntimeException( 'Votre panier est vide.' ); }
   WC()->cart->calculate_totals();
   if ( ! hash_equals( WC()->cart->get_cart_hash(), $hash ) ) { throw new RuntimeException( 'Le panier a changé. Actualisez pour vérifier le total.' ); }
   $record = array( 'status' => 'pending', 'order_id' => 0, 'created' => time(), 'hash' => $hash, 'issued' => $issued, 'creating' => false );
   if ( ! self::insert( $keys['key'], $record ) ) { throw new RuntimeException( 'Cette demande a déjà été envoyée.' ); }
   self::$context = array_merge( $keys, array( 'uid' => $uid, 'id' => $id, 'record' => $record ) );
  } catch ( Throwable $e ) { self::release( $keys['active'], $id ); throw $e; }
  WC()->session->__unset( 'delicat_payment_intent' );
 }
 /** Seconds before an unfinished, uncharged guard may be reclaimed. */
 private const ABANDONED_AFTER = 300;

 /**
  * Release a guard whose request died before anything could be charged.
  *
  * Returns true only when the record shows creation never started and no order
  * exists, and the record is older than ABANDONED_AFTER. Every other state -
  * including a record that cannot be read - keeps the guard.
  *
  * A guard with no record at all is deliberately NOT reclaimed here: nothing
  * dates it, so it cannot be told apart from one taken a millisecond ago. Those
  * the reconciliation screen releases, where a human is doing the dating.
  */
 private static function reclaim_abandoned( string $active, int $uid ): bool {
  try {
   $held = self::read( $active );
   if ( ! is_string( $held ) || '' === $held ) { return false; }

   $record = self::read( 'delicat_payment_' . $uid . '_' . $held );
   if ( ! is_array( $record ) ) { return false; }

   if ( ( time() - (int) ( $record['created'] ?? time() ) ) < self::ABANDONED_AFTER ) { return false; }

   /*
    * PRO41: this used to refuse on `creating`/`order_id` BEFORE the age test,
    * and therefore forever.
    *
    * guard_classic() takes this mutex for every signed-in classic checkout on
    * every gateway, and WooCommerce creates the order before asking the gateway
    * to charge. So a declined card, a gateway timeout or an exception in
    * process_payment() left order_id set with `finished` never reached,
    * shutdown() retained the guard, and this function could never reclaim it at
    * any age. The customer was told "Un paiement est deja en cours ou a verifier
    * dans vos commandes." on every subsequent attempt, with a new cart, days
    * later, having been charged nothing -- until an administrator released it by
    * hand. One declined card ended that customer's ability to buy.
    *
    * The guard is there to stop a double charge while the outcome is UNKNOWN.
    * Past the abandon threshold we can often prove nothing was taken: only
    * reclaim when the order still exists, is not paid, and still needs payment.
    * A paid order, an order that no longer needs payment (an offsite gateway
    * that completed late), or an order we cannot load keeps the guard.
    */
   if ( ! empty( $record['creating'] ) || ! empty( $record['order_id'] ) ) {
    $order_id = (int) ( $record['order_id'] ?? 0 );
    if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) { return false; }
    $order = wc_get_order( $order_id );
    if ( ! $order || ! is_callable( array( $order, 'is_paid' ) ) || ! is_callable( array( $order, 'needs_payment' ) ) ) { return false; }
    if ( $order->is_paid() || ! $order->needs_payment() ) { return false; }
   }

   /* Compare-and-swap on the owner, so a guard taken between the read above
      and here is left alone. */
   return self::release( $active, $held );
  } catch ( Throwable $e ) {
   return false;
  }
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
  if ( ! self::$context || 'success' !== ( $result['result'] ?? '' ) ) { return $result; }
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
  // Once creation begins, keep the durable guard until the outcome is reconciled.
  if ( empty( $c['record']['creating'] ) && empty( $c['record']['order_id'] ) ) {
   try { $r = $c['record']; $r['status'] = 'rejected'; self::save( $r ); self::release( $c['active'], $c['id'] ); } catch ( Throwable $e ) { /* Fail closed. */ }
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
    $response['state'] = in_array( $record['status'], array( 'rejected', 'confirmed', 'submitted' ), true ) ? $record['status'] : 'pending';
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
 /*
  * Only a guard that recorded something can be too recent to judge. Without a
  * record the request died before it wrote one, so there is no order, no charge
  * and nothing to wait for - and the age test used to compute time() - time(),
  * which is zero, which is under five minutes forever: this screen could never
  * release such a guard, and that customer stayed locked out of checkout for
  * good.
  */
 if ( is_array( $record ) ) {
  if ( time() - (int) ( $record['created'] ?? time() ) < 300 ) { wp_die( 'Le traitement est récent. Attendez cinq minutes puis vérifiez son résultat.' ); }
  if ( ! empty( $record['order_id'] ) ) {
   $order = wc_get_order( $record['order_id'] );
   if ( $order ) { $order->add_order_note( 'Protection paiement direct libérée après vérification par administrateur #' . get_current_user_id() ); }
  }
 }
 if ( ! Delicat_Builder_V9_Express_Payment::release( $active, $submitted ) ) { wp_die( 'Cette protection a changé. Actualisez la page.' ); }
 } catch ( Throwable $e ) { wp_die( 'Vérification indisponible. La protection reste en place.' ); }
 wp_safe_redirect( admin_url() ); exit;
} );
