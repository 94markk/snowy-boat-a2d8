<?php
/**
 * 6.17.0 — Age declaration (18+).
 *
 * Right after a customer creates an account (and once for every existing
 * customer who has not answered yet), a dialog asks « Avez-vous 18 ans ou plus ? ».
 * Only the answer and its date are kept (no birth date): data minimisation.
 * A customer under 18 sees a notice that purchases need the agreement of a
 * parent or legal guardian, with the « Protection des mineurs » policy, and can
 * continue. The first answer is final for the customer; staff can see it (and
 * reset it) on the user's profile.
 *
 * @package Delicat_Identity_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DIP_Age_Declaration {

	public const META    = 'dip_age_declaration';   // 'adult' | 'minor'
	public const META_AT = 'dip_age_declared_at';   // Unix timestamp
	public const ACTION  = 'dip_age_declaration';

	public static function init(): void {
		add_action( 'wp_footer', array( __CLASS__, 'render' ), 20 );
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'save' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_row' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_row' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'profile_save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'profile_save' ) );
	}

	/** Customers only, never staff; not in the middle of paying. */
	private static function needs_answer(): bool {
		if ( is_admin() || ! is_user_logged_in() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) ) {
			return false;
		}
		if ( '' !== (string) get_user_meta( $user->ID, self::META, true ) ) {
			return false;
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return false; // checkout, order-pay and order-received: ask on the next page instead
		}
		return (bool) apply_filters( 'dip_age_declaration_required', true, $user );
	}

	private static function minors_policy_url(): string {
		if ( class_exists( 'Delicat_Builder_V9_Legal' ) && is_callable( array( 'Delicat_Builder_V9_Legal', 'url' ) ) ) {
			return (string) Delicat_Builder_V9_Legal::url( 'minors' );
		}
		return home_url( '/protection-des-mineurs/' );
	}

	private static function icon( string $name, int $size ): string {
		return function_exists( 'delicat_ds_icon' ) ? delicat_ds_icon( $name, $size ) : '';
	}

	public static function render(): void {
		if ( ! self::needs_answer() ) {
			return;
		}
		$nonce = wp_create_nonce( self::ACTION );
		?>
		<dialog id="dip-age" class="dip-age" aria-labelledby="dip-age-title" aria-describedby="dip-age-text" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-endpoint="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<div class="dip-age__ask">
				<span class="dip-age__icon"><?php echo self::icon( 'verified-user', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG from the design system. ?></span>
				<h2 id="dip-age-title" class="dip-age__title"><?php esc_html_e( 'Avez-vous 18 ans ou plus ?', 'delicat-google-login' ); ?></h2>
				<p id="dip-age-text" class="dip-age__text"><?php esc_html_e( 'Pour finaliser votre compte, indiquez si vous êtes majeur. Votre réponse est enregistrée une seule fois.', 'delicat-google-login' ); ?></p>
				<div class="dip-age__actions">
					<button type="button" class="dip-age__btn dip-age__btn--primary" data-dip-age="adult"><?php esc_html_e( "Oui, j'ai 18 ans ou plus", 'delicat-google-login' ); ?></button>
					<button type="button" class="dip-age__btn" data-dip-age="minor"><?php esc_html_e( "Non, j'ai moins de 18 ans", 'delicat-google-login' ); ?></button>
				</div>
				<p class="dip-age__error" role="alert" hidden></p>
			</div>
			<div class="dip-age__minor" hidden>
				<span class="dip-age__icon"><?php echo self::icon( 'family-restroom', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG. ?></span>
				<h2 class="dip-age__title" tabindex="-1"><?php esc_html_e( 'Merci pour votre réponse', 'delicat-google-login' ); ?></h2>
				<p class="dip-age__text"><?php esc_html_e( "Les achats d'une personne de moins de 18 ans doivent se faire avec l'accord d'un parent ou d'un tuteur légal.", 'delicat-google-login' ); ?></p>
				<p class="dip-age__text"><a href="<?php echo esc_url( self::minors_policy_url() ); ?>"><?php esc_html_e( 'Lire notre politique Protection des mineurs', 'delicat-google-login' ); ?></a></p>
				<div class="dip-age__actions">
					<button type="button" class="dip-age__btn dip-age__btn--primary" data-dip-age-close><?php esc_html_e( "J'ai compris", 'delicat-google-login' ); ?></button>
				</div>
			</div>
		</dialog>
		<style id="dip-age-css">
		.dip-age{width:min(420px,calc(100vw - 32px));max-width:none;border:0;border-radius:var(--ds-radius-xl,20px);padding:var(--ds-space-6,24px);background:var(--ds-color-surface,#fff);color:var(--ds-color-text,#111827);box-shadow:var(--ds-shadow-3,0 12px 32px rgba(15,23,42,.14));font-family:var(--ds-font-sans,system-ui,sans-serif);text-align:center}
		.dip-age::backdrop{background:var(--ds-color-overlay,rgba(15,23,42,.48))}
		.dip-age__icon{display:inline-flex;width:52px;height:52px;align-items:center;justify-content:center;border-radius:var(--ds-radius-full,999px);background:var(--ds-color-primary-subtle,#eff6ff);color:var(--ds-color-primary,#2563eb);margin-bottom:var(--ds-space-3,12px)}
		.dip-age__title{margin:0 0 var(--ds-space-2,8px);font-size:var(--ds-text-section,21px);line-height:var(--ds-leading-tight,1.2);font-weight:var(--ds-font-weight-bold,700);color:var(--ds-color-text,#111827)}
		.dip-age__text{margin:0 0 var(--ds-space-4,16px);font-size:var(--ds-text-body,15px);line-height:var(--ds-leading-body,1.5);color:var(--ds-color-text-muted,#5f6b7e)}
		.dip-age__text a{color:var(--ds-color-primary,#2563eb);font-weight:var(--ds-font-weight-semibold,600)}
		.dip-age__actions{display:grid;gap:var(--ds-space-2,8px)}
		.dip-age__btn{min-height:var(--ds-tap-target,44px);border-radius:var(--ds-radius-md,12px);border:1px solid var(--ds-color-border-strong,#7f8a9c);background:var(--ds-color-surface,#fff);color:var(--ds-color-text,#111827);font:inherit;font-size:var(--ds-text-button,15px);font-weight:var(--ds-font-weight-semibold,600);cursor:pointer;transition:transform var(--ds-duration-press,110ms) var(--ds-ease,ease)}
		.dip-age__btn--primary{border-color:transparent;background:var(--ds-color-primary,#2563eb);color:var(--ds-color-primary-contrast,#fff)}
		.dip-age__btn:active{transform:scale(.98)}
		.dip-age__btn:focus-visible{outline:2px solid var(--ds-color-focus,#2563eb);outline-offset:2px}
		.dip-age__btn[disabled]{opacity:.6;cursor:progress}
		.dip-age__error{margin:var(--ds-space-3,12px) 0 0;color:var(--ds-color-danger-text,#dc2626);font-size:var(--ds-text-secondary,13px)}
		@media (prefers-reduced-motion:reduce){.dip-age__btn{transition:none}}
		</style>
		<script id="dip-age-js">
		(function () {
			var d = document.getElementById('dip-age');
			if (!d || typeof d.showModal !== 'function') { return; }
			var ask = d.querySelector('.dip-age__ask'), minor = d.querySelector('.dip-age__minor'), err = d.querySelector('.dip-age__error');
			var buttons = d.querySelectorAll('[data-dip-age]');
			d.addEventListener('cancel', function (e) { if (!ask.hidden) { e.preventDefault(); } });
			function busy(on) { for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = on; } }
			function send(answer) {
				busy(true); err.hidden = true;
				var body = new FormData();
				body.append('action', '<?php echo esc_js( self::ACTION ); ?>');
				body.append('nonce', d.getAttribute('data-nonce'));
				body.append('answer', answer);
				fetch(d.getAttribute('data-endpoint'), { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						if (!res || !res.success) { throw new Error(); }
						if (answer === 'minor') { ask.hidden = true; minor.hidden = false; minor.querySelector('.dip-age__title').focus(); }
						else { d.close(); }
					})
					.catch(function () {
						busy(false); err.hidden = false;
						err.textContent = '<?php echo esc_js( __( "La réponse n'a pas pu être enregistrée. Vérifiez votre connexion et réessayez.", 'delicat-google-login' ) ); ?>';
					});
			}
			for (var i = 0; i < buttons.length; i++) {
				buttons[i].addEventListener('click', function () { send(this.getAttribute('data-dip-age')); });
			}
			var closer = d.querySelector('[data-dip-age-close]');
			if (closer) { closer.addEventListener('click', function () { d.close(); }); }
			d.showModal();
		})();
		</script>
		<?php
	}

	/** Save the customer's own answer once (nonce + logged-in session; the first answer is final). */
	public static function save(): void {
		check_ajax_referer( self::ACTION, 'nonce' );
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			wp_send_json_error( array( 'message' => 'Session expirée.' ), 403 );
		}
		$answer = isset( $_POST['answer'] ) ? sanitize_key( wp_unslash( $_POST['answer'] ) ) : '';
		if ( ! in_array( $answer, array( 'adult', 'minor' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Réponse invalide.' ), 400 );
		}
		if ( '' !== (string) get_user_meta( $user_id, self::META, true ) ) {
			wp_send_json_success( array( 'answer' => (string) get_user_meta( $user_id, self::META, true ), 'already' => true ) );
		}
		update_user_meta( $user_id, self::META, $answer );
		update_user_meta( $user_id, self::META_AT, time() );
		if ( class_exists( 'DIP_Audit' ) && is_callable( array( 'DIP_Audit', 'record' ) ) ) {
			DIP_Audit::record( 'age_declaration', 'info', $user_id, array( 'answer' => $answer ) );
		}
		do_action( 'dip_age_declared', $user_id, $answer );
		wp_send_json_success( array( 'answer' => $answer ) );
	}

	/** Staff see the answer on the user's profile and can reset it so the customer is asked again. */
	public static function profile_row( $user ): void {
		if ( ! $user instanceof WP_User || ! current_user_can( 'edit_user', $user->ID ) || ! current_user_can( 'list_users' ) ) {
			return;
		}
		$answer = (string) get_user_meta( $user->ID, self::META, true );
		$at     = (int) get_user_meta( $user->ID, self::META_AT, true );
		$label  = 'adult' === $answer ? '18 ans ou plus' : ( 'minor' === $answer ? 'Moins de 18 ans' : 'Pas encore déclarée' );
		wp_nonce_field( 'dip_age_reset_' . $user->ID, 'dip_age_reset_nonce' );
		echo '<h2>Delicat Identity — déclaration d’âge</h2><table class="form-table"><tr><th>Réponse</th><td><strong>' . esc_html( $label ) . '</strong>';
		if ( $at ) {
			echo ' <span class="description">(' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $at ) ) . ')</span>';
		}
		if ( '' !== $answer ) {
			echo '<p><label><input type="checkbox" name="dip_age_reset" value="1"> Redemander au client à sa prochaine visite</label></p>';
		}
		echo '</td></tr></table>';
	}

	public static function profile_save( $user_id ): void {
		$user_id = absint( $user_id );
		if ( empty( $_POST['dip_age_reset'] ) || ! current_user_can( 'edit_user', $user_id ) || ! current_user_can( 'list_users' ) ) {
			return;
		}
		check_admin_referer( 'dip_age_reset_' . $user_id, 'dip_age_reset_nonce' );
		delete_user_meta( $user_id, self::META );
		delete_user_meta( $user_id, self::META_AT );
		if ( class_exists( 'DIP_Audit' ) && is_callable( array( 'DIP_Audit', 'record' ) ) ) {
			DIP_Audit::record( 'age_declaration_reset', 'info', get_current_user_id(), array( 'user_id' => $user_id ) );
		}
	}
}
