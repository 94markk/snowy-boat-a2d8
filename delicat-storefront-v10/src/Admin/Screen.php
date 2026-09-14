<?php
namespace Delicat\V10\Admin;

use Delicat\V10\Compat\V9Options;
use Delicat\V10\Context;
use Delicat\V10\Design\Tokens;
use Delicat\V10\Module;

defined( 'ABSPATH' ) || exit;

/**
 * The settings screen. One screen.
 *
 * V9 had twenty-five: Design Studio, Homepage Studio, Header Studio v8, Shell
 * Studio, Menu Builder, Purchase Studio, Woo UI Studio, Performance Studio,
 * Security Studio, Announcement Studio, Swatch Studio, Motion, Release Center,
 * Production Center, Self-Test and more. Together they exposed several hundred
 * settings, and a large part of the storefront's fragility came from exactly
 * that: every component had its own sizes, its own colours and its own
 * breakpoints because every component had its own settings page to fill.
 *
 * V10 has one scale, so it has one screen. What is adjustable here is what a
 * merchant genuinely needs to decide - their colours and their corner radius -
 * and everything else derives from those.
 */
final class Screen extends Module {

	public const SLUG = 'delicat-v10';
	public const OPTION = 'delicat_v10_tokens';

	public static function kinds(): array {
		return array( Context::KIND_ADMIN );
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_delicat_v10_save', array( $this, 'save' ) );
	}

	public function menu(): void {
		add_menu_page(
			__( 'Storefront', 'delicat-v10' ),
			__( 'Storefront', 'delicat-v10' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-store',
			56
		);
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'delicat-v10' ), 403 );
		}

		check_admin_referer( 'delicat_v10_save' );

		$saved = array( 'color' => array(), 'color-dark' => array(), 'radius' => array() );

		/*
		 * Only the keys this screen offers are read, and each is validated
		 * against the same sanitiser the token layer uses when printing. A
		 * value that does not survive validation is dropped rather than
		 * corrected, so a mistake reverts to the default instead of becoming a
		 * different mistake.
		 */
		foreach ( array( 'brand', 'accent', 'ground', 'surface', 'ink' ) as $key ) {
			$value = isset( $_POST[ 'color_' . $key ] ) ? sanitize_hex_color( wp_unslash( $_POST[ 'color_' . $key ] ) ) : null;
			if ( $value ) {
				$saved['color'][ $key ] = $value;
			}

			$dark = isset( $_POST[ 'dark_' . $key ] ) ? sanitize_hex_color( wp_unslash( $_POST[ 'dark_' . $key ] ) ) : null;
			if ( $dark ) {
				$saved['color-dark'][ $key ] = $dark;
			}
		}

		if ( isset( $_POST['radius'] ) ) {
			$radius = max( 0, min( 40, absint( wp_unslash( $_POST['radius'] ) ) ) );
			$saved['radius'] = array(
				'sm' => max( 0, $radius - 10 ),
				'md' => max( 0, $radius - 6 ),
				'lg' => $radius,
				'xl' => $radius + 8,
			);
		}

		update_option( self::OPTION, array_filter( $saved ), false );

		do_action( 'delicat_v10_settings_saved' );

		wp_safe_redirect( add_query_arg( 'updated', '1', menu_page_url( self::SLUG, false ) ) );
		exit;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tokens   = Tokens::all();
		$problems = Health::problems();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Storefront', 'delicat-v10' ) );

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Saved. The storefront updates on the next page load.', 'delicat-v10' )
			);
		}

		if ( array() !== $problems ) {
			echo '<div class="notice notice-warning"><ul style="list-style:disc;margin-left:1.4em">';
			foreach ( $problems as $problem ) {
				printf( '<li>%s</li>', esc_html( $problem ) );
			}
			echo '</ul></div>';
		}

		if ( V9Options::available() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Your V9 colours, corner radius, menu and homepage layout were found and are being used. Nothing in V9 has been changed, so both can run side by side while you compare them.', 'delicat-v10' )
			);
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( 'delicat_v10_save' );
		echo '<input type="hidden" name="action" value="delicat_v10_save">';

		echo '<h2>' . esc_html__( 'Colours', 'delicat-v10' ) . '</h2>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Five colours decide the whole storefront. Everything else — borders, shadows, muted text, disabled states — is derived from them, in both light and dark.', 'delicat-v10' )
		);

		echo '<table class="form-table" role="presentation"><tbody>';

		$labels = array(
			'brand'   => __( 'Brand', 'delicat-v10' ),
			'accent'  => __( 'Accent', 'delicat-v10' ),
			'ground'  => __( 'Page background', 'delicat-v10' ),
			'surface' => __( 'Card background', 'delicat-v10' ),
			'ink'     => __( 'Text', 'delicat-v10' ),
		);

		foreach ( $labels as $key => $label ) {
			echo '<tr>';
			printf( '<th scope="row">%s</th>', esc_html( $label ) );
			echo '<td>';
			printf(
				'<label>%1$s <input type="color" name="color_%2$s" value="%3$s"></label> ',
				esc_html__( 'Light', 'delicat-v10' ),
				esc_attr( $key ),
				esc_attr( (string) ( $tokens['color'][ $key ] ?? '#000000' ) )
			);
			printf(
				'<label style="margin-left:1.5em">%1$s <input type="color" name="dark_%2$s" value="%3$s"></label>',
				esc_html__( 'Dark', 'delicat-v10' ),
				esc_attr( $key ),
				esc_attr( (string) ( $tokens['color-dark'][ $key ] ?? '#000000' ) )
			);
			echo '</td></tr>';
		}

		echo '<tr>';
		printf( '<th scope="row">%s</th>', esc_html__( 'Corner radius', 'delicat-v10' ) );
		printf(
			'<td><input type="number" name="radius" min="0" max="40" value="%d" class="small-text"> px'
			. '<p class="description">%s</p></td>',
			(int) ( $tokens['radius']['lg'] ?? 20 ),
			esc_html__( 'The other three radii step from this one, so cards, buttons and chips stay in proportion.', 'delicat-v10' )
		);
		echo '</tr>';

		echo '</tbody></table>';

		submit_button();
		echo '</form>';

		self::reference( $tokens );

		echo '</div>';
	}

	/**
	 * What the storefront is currently using, as it is actually emitted.
	 *
	 * A merchant rarely needs this; the person they call when something looks
	 * wrong always does, and the alternative is reading it out of a browser's
	 * developer tools over the phone.
	 *
	 * @param array<string,array<string,mixed>> $tokens
	 */
	private static function reference( array $tokens ): void {
		printf( '<h2>%s</h2>', esc_html__( 'In use right now', 'delicat-v10' ) );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Every measurement in the storefront, as the browser receives it. Nothing outside this list can set a size or a colour.', 'delicat-v10' )
		);

		echo '<details><summary style="cursor:pointer;padding:.6em 0">'
			. esc_html__( 'Show all design tokens', 'delicat-v10' ) . '</summary>';

		echo '<textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:12px">'
			. esc_textarea( str_replace( array( '}', ';' ), array( "}\n", ";\n" ), Tokens::css() ) )
			. '</textarea>';

		echo '</details>';

		unset( $tokens );
	}
}
