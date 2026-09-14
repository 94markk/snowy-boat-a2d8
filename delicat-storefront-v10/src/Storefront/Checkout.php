<?php
namespace Delicat\V10\Storefront;

use Delicat\V10\Context;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The checkout.
 *
 * -----------------------------------------------------------------------------
 * The shortest module in V10, and deliberately so
 * -----------------------------------------------------------------------------
 * A checkout is the one page where being clever is expensive. Everything that
 * decides whether an order is created, whether a payment succeeds, what a
 * customer is charged and what happens when a payment fails belongs to
 * WooCommerce and its gateways. V10 adds no endpoint, no transport, no lock and
 * no state of its own to this page.
 *
 * A customer with no funds is declined by their gateway, sees that gateway's own
 * message, and can try another payment method immediately - because nothing of
 * V10's survives the request to stop them.
 *
 * What is here is two things: better input types, so a phone shows the right
 * keyboard, and an autocomplete pass so the browser can fill the form. Both are
 * presentation. Removing this file entirely would change nothing about whether
 * the store takes money correctly.
 */
final class Checkout extends Module {

	public static function routes(): array {
		return array( Context::ROUTE_CHECKOUT );
	}

	public function register(): void {
		add_filter( 'woocommerce_checkout_fields', array( $this, 'field_types' ) );
		add_filter( 'woocommerce_form_field_args', array( $this, 'field_args' ), 10, 3 );
	}

	/**
	 * The right keyboard for each field.
	 *
	 * A phone number field that opens a full QWERTY keyboard is a field people
	 * mistype. `type=tel` opens a keypad; `type=email` puts @ on the first
	 * layer. WooCommerce sets both correctly for its own fields but not for
	 * every field a plugin adds, so the pass below is by field name.
	 *
	 * @param array<string,array<string,mixed>> $fields
	 * @return array<string,array<string,mixed>>
	 */
	public function field_types( $fields ): array {
		if ( ! is_array( $fields ) ) {
			return array();
		}

		foreach ( $fields as $group => $group_fields ) {
			if ( ! is_array( $group_fields ) ) {
				continue;
			}
			foreach ( $group_fields as $key => $field ) {
				if ( ! is_array( $field ) ) {
					continue;
				}
				if ( false !== strpos( (string) $key, 'phone' ) ) {
					$fields[ $group ][ $key ]['type']         = 'tel';
					$fields[ $group ][ $key ]['autocomplete'] = 'tel';
				}
				if ( false !== strpos( (string) $key, 'email' ) ) {
					$fields[ $group ][ $key ]['type']         = 'email';
					$fields[ $group ][ $key ]['autocomplete'] = 'email';
				}
				if ( false !== strpos( (string) $key, 'postcode' ) ) {
					$fields[ $group ][ $key ]['autocomplete'] = 'postal-code';
				}
			}
		}

		return $fields;
	}

	/**
	 * @param array<string,mixed> $args
	 * @param string $key
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	public function field_args( $args, $key, $value ): array {
		unset( $key, $value );

		if ( ! is_array( $args ) ) {
			return array();
		}

		/*
		 * A required field that only says so with a red asterisk is not saying
		 * so to a screen reader. WooCommerce renders the asterisk; this adds
		 * the attribute that carries the same meaning to everyone else - and to
		 * the browser's own validation, which then catches the mistake before
		 * the form is submitted rather than after a round trip.
		 */
		if ( ! empty( $args['required'] ) ) {
			$args['custom_attributes']['required'] = 'required';
		}

		return $args;
	}
}
