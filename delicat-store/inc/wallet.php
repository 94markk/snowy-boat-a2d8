<?php
/**
 * Delicat Wallet = TeraWallet (plugin "woo-wallet"). The theme shows the
 * balance and links to the wallet; the plugin owns every credit and debit.
 * Without the plugin nothing here renders and the tab bar offers Search
 * instead of Wallet.
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

function ds_wallet_active(): bool {
	return function_exists( 'woo_wallet' );
}

/**
 * URL of the wallet screen in My Account.
 */
function ds_wallet_url(): string {
	if ( ! ds_wallet_active() ) {
		return '';
	}
	if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
		return wc_get_account_endpoint_url( 'woo-wallet' );
	}
	return ds_account_url();
}

/**
 * Are we on the wallet screen?
 */
function ds_is_wallet_page(): bool {
	if ( ! ds_wallet_active() ) {
		return false;
	}
	if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'woo-wallet' ) ) {
		return true;
	}
	return is_page( array( 'my-wallet', 'wallet', 'portefeuille', 'mon-portefeuille' ) );
}

/**
 * Formatted balance of the current user, or '' when not applicable.
 */
function ds_wallet_balance_html(): string {
	if ( ! ds_wallet_active() || ! is_user_logged_in() ) {
		return '';
	}
	try {
		$html = woo_wallet()->wallet->get_wallet_balance( get_current_user_id() );
		return is_string( $html ) ? wp_kses_post( $html ) : '';
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Header chip: balance + link to top up. Printed by header.php.
 */
function ds_wallet_chip(): void {
	$balance = ds_wallet_balance_html();
	if ( '' === $balance ) {
		return;
	}
	echo '<a class="ds-wallet-chip" href="' . esc_url( ds_wallet_url() ) . '" title="Mon Wallet">' . ds_icon( 'wallet' ) . '<span class="ds-wallet-chip__amount">' . $balance . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- balance sanitised with wp_kses_post.
}

/**
 * When the wallet gateway declines for lack of funds, WooCommerce prints the
 * gateway's own message. We add a top-up button under the notices so the
 * customer can act on it without hunting for the wallet page.
 */
function ds_wallet_topup_hint(): void {
	if ( ! ds_wallet_active() || ! is_checkout() || ! is_user_logged_in() ) {
		return;
	}
	echo '<div class="ds-wallet-hint" hidden data-ds-wallet-hint><p>Solde insuffisant ? <a class="ds-btn ds-btn--small" href="' . esc_url( ds_wallet_url() ) . '">Recharger mon Wallet</a> ou choisissez un autre moyen de paiement.</p></div>';
}
add_action( 'woocommerce_before_checkout_form', 'ds_wallet_topup_hint', 5 );
