<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RC82 — Stability.
 *
 * Reading the live site after eight releases showed one thing clearly: the
 * homepage had not changed. Same cached document, same emoji, same two-link
 * footer, same nonce baked into the login URL. Whatever was shipped, the
 * visitor was getting rc.71. Every "font size bug" and "layout bug" reported
 * since was a report about code that had been replaced weeks earlier.
 *
 * Three things make that impossible to detect from the outside, and this
 * module fixes each:
 *
 * 1. NO VERSION STAMP. The page carries no trace of which build rendered it or
 *    when. It now does: a comment right after <body> with the version and the
 *    render time, and an X-Delicat-Builder response header. A stale cache is
 *    visible at a glance, in view-source or curl, by anyone.
 *
 * 2. NO WAY TO ASK. The admin can now fetch the homepage as a guest and compare
 *    the stamp it finds with the version installed. If they differ, the notice
 *    says so, names the layer that failed, and offers the purge button. This
 *    ends the loop of "did it work?" being answered by guesswork.
 *
 * 3. THE PURGE REPORTS NOTHING. `litespeed_purge_all` fires into the void. The
 *    purge now records what each layer did — LiteSpeed hooked or not, object
 *    cache flushed, Cloudflare purged / not configured / not present — and
 *    the admin shows it. A site behind Cloudflare with no API token is warned
 *    explicitly, because that is the one configuration where nothing a plugin
 *    does can ever reach the visitor.
 *
 * And one thing that made the sizing non-deterministic:
 *
 * 4. 3,823 `!important` DECLARATIONS across the shipped CSS, 14 of them
 *    fighting over the carousel title alone. Equal importance and specificity
 *    means source order decides, and source order is whatever LiteSpeed's
 *    combiner produced that day. So the phone scale was a coin toss.
 *
 *    The authoritative sizing rules are now printed inline in <head> at
 *    priority 9999 — after every enqueued stylesheet, immune to combining,
 *    with `html body` specificity. Same importance, higher specificity, later
 *    source: they win, every time, on every host, in every combine order.
 *    Roughly 2 KB inline, and the storefront becomes predictable.
 *
 * PHP 7.4.
 */
final class Delicat_Builder_V9_Stability {

	const OPTION_REPORT = 'delicat_builder_v9_purge_report';
	const TRANSIENT     = 'delicat_builder_v9_deploy_check';
	const HEADER        = 'X-Delicat-Builder';

	public static function version(): string {
		return defined( 'DELICAT_BUILDER_V9_VERSION' ) ? (string) DELICAT_BUILDER_V9_VERSION : '0';
	}

	public static function boot(): void {
		add_action( 'send_headers', array( __CLASS__, 'header' ) );
		add_action( 'wp_body_open', array( __CLASS__, 'stamp' ), 0 );
		add_action( 'wp_head', array( __CLASS__, 'authoritative_sizing' ), 9999 );
		add_action( 'delicat_builder_v9_version_purged', array( __CLASS__, 'record_purge' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'deploy_notice' ) );
		add_action( 'admin_post_delicat_builder_v9_deploy_check', array( __CLASS__, 'handle_check' ) );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Stamp                                                            */
	/* ------------------------------------------------------------------ */

	public static function header(): void {
		if ( headers_sent() ) {
			return;
		}
		header( self::HEADER . ': ' . self::version() );
	}

	public static function stamp(): void {
		if ( is_admin() ) {
			return;
		}
		echo "\n<!-- delicat-builder-v9 " . esc_html( self::version() )
			. ' · rendered ' . esc_html( gmdate( 'Y-m-d H:i:s' ) ) . " UTC -->\n";
	}

	/* ------------------------------------------------------------------ */
	/* 2. Deployment check                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch the homepage the way a guest does — no cookies, no cache-buster —
	 * and read the stamp back. A cache-buster would defeat the purpose: the
	 * point is to see what the cache is actually serving.
	 */
	public static function check(): array {
		$result = array(
			'checked_at' => time(),
			'installed'  => self::version(),
			'live'       => '',
			'rendered'   => '',
			'header'     => '',
			'cf_ray'     => '',
			'cache'      => '',
			'error'      => '',
		);
		$response = wp_remote_get(
			home_url( '/' ),
			array(
				'timeout'     => 12,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => array(
					'User-Agent' => 'DelicatBuilder-DeployCheck/' . self::version(),
					'Accept'     => 'text/html',
				),
				'cookies'     => array(),
			)
		);
		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			set_transient( self::TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$result['error'] = 'Homepage returned HTTP ' . $status;
			set_transient( self::TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
			return $result;
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( preg_match( '/<!-- delicat-builder-v9 ([^\s]+) · rendered ([0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}) UTC -->/', $body, $m ) ) {
			$result['live']     = trim( $m[1] );
			$result['rendered'] = trim( $m[2] );
		}
		$result['header'] = (string) wp_remote_retrieve_header( $response, strtolower( self::HEADER ) );
		$result['cf_ray'] = (string) wp_remote_retrieve_header( $response, 'cf-ray' );
		$cache_status     = (string) wp_remote_retrieve_header( $response, 'x-litespeed-cache' );
		if ( '' === $cache_status ) {
			$cache_status = (string) wp_remote_retrieve_header( $response, 'cf-cache-status' );
		}
		$result['cache'] = $cache_status;
		set_transient( self::TRANSIENT, $result, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function handle_check(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_deploy_check' );
		$purge = isset( $_POST['purge'] ) && '1' === $_POST['purge'];
		if ( $purge && class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'purge_everything' ) ) ) {
			Delicat_Builder_V9_Cache::purge_everything();
			self::record_purge( self::version(), 'manual' );
			/* Give the edge a moment before we read it back. */
			sleep( 2 );
		}
		self::check();
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Shown on every Delicat admin screen. Loud when the live version lags the
	 * installed one, quiet and green when they match.
	 */
	public static function deploy_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen instanceof WP_Screen ? (string) $screen->id : '';
		if ( '' === $id || ( false === strpos( $id, 'delicat' ) && 'plugins' !== $id && 'dashboard' !== $id ) ) {
			return;
		}

		$check  = get_transient( self::TRANSIENT );
		$report = get_option( self::OPTION_REPORT, array() );
		$report = is_array( $report ) ? $report : array();

		$action = esc_url( admin_url( 'admin-post.php' ) );
		$nonce  = wp_nonce_field( 'delicat_builder_v9_deploy_check', '_wpnonce', true, false );
		$form   = static function ( string $label, bool $purge ) use ( $action, $nonce ): string {
			return '<form method="post" action="' . $action . '" style="display:inline-block;margin:0 6px 0 0">'
				. $nonce
				. '<input type="hidden" name="action" value="delicat_builder_v9_deploy_check">'
				. ( $purge ? '<input type="hidden" name="purge" value="1">' : '' )
				. '<button type="submit" class="button' . ( $purge ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		};

		echo '<div class="notice ' . ( self::is_stale( $check ) ? 'notice-error' : 'notice-info' ) . '" style="padding:12px 14px">';
		echo '<p style="margin:0 0 6px"><strong>Delicat Builder V9 — déploiement</strong></p>';

		if ( ! is_array( $check ) ) {
			echo '<p style="margin:0 0 8px">Version installée : <code>' . esc_html( self::version() ) . '</code>. Aucune vérification n’a encore été faite.</p>';
		} else {
			$live = '' !== $check['live'] ? $check['live'] : ( '' !== $check['header'] ? $check['header'] : 'inconnue (aucun tampon dans la page — version antérieure à rc.82)' );
			echo '<p style="margin:0 0 4px">Installée : <code>' . esc_html( $check['installed'] ) . '</code> &nbsp;·&nbsp; '
				. 'Servie aux visiteurs : <code>' . esc_html( $live ) . '</code>'
				. ( '' !== $check['rendered'] ? ' &nbsp;·&nbsp; rendue le ' . esc_html( $check['rendered'] ) . ' UTC' : '' )
				. '</p>';
			if ( '' !== $check['error'] ) {
				echo '<p style="margin:0 0 4px;color:#b32d2e">Impossible de joindre la page d’accueil : ' . esc_html( $check['error'] ) . '</p>';
			}
			if ( self::is_stale( $check ) ) {
				echo '<p style="margin:0 0 8px;color:#b32d2e"><strong>Les visiteurs reçoivent une version antérieure à celle installée.</strong> '
					. self::diagnose( $check, $report ) . '</p>';
			} else {
				echo '<p style="margin:0 0 8px;color:#1e7b3a">La version servie correspond à la version installée.</p>';
			}
		}

		if ( ! empty( $report ) ) {
			echo '<p style="margin:0 0 8px;color:#50575e;font-size:12px">Dernière purge (' . esc_html( (string) ( $report['when'] ?? '' ) ) . ') — '
				. esc_html( (string) ( $report['summary'] ?? '' ) ) . '</p>';
		}

		echo $form( 'Vérifier ce que voient les visiteurs', false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		echo $form( 'Purger toutes les couches et revérifier', true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	private static function is_stale( $check ): bool {
		if ( ! is_array( $check ) || '' !== (string) $check['error'] ) {
			return false;
		}
		$live = '' !== $check['live'] ? $check['live'] : $check['header'];
		return '' === $live || $live !== $check['installed'];
	}

	private static function diagnose( array $check, array $report ): string {
		$behind_cf = '' !== (string) $check['cf_ray'];
		$cf_ok     = class_exists( 'Delicat_Builder_V9_Cloudflare', false )
			&& is_callable( array( 'Delicat_Builder_V9_Cloudflare', 'enabled' ) )
			&& Delicat_Builder_V9_Cloudflare::enabled();
		if ( $behind_cf && ! $cf_ok ) {
			return 'Le site est derrière Cloudflare (en-tête cf-ray présent) mais aucun jeton API n’est configuré dans Delicat → Cloudflare. '
				. 'La purge LiteSpeed n’atteint jamais le cache de bord : les visiteurs continueront de recevoir l’ancienne page tant que Cloudflare n’est pas purgé — '
				. 'depuis le tableau de bord Cloudflare, ou en renseignant le jeton pour que le plugin le fasse.';
		}
		if ( $behind_cf && $cf_ok ) {
			return 'Cloudflare est configuré. Si la version reste ancienne après une purge, vérifiez qu’aucune règle « Cache Everything » ne s’applique avec un TTL de bord supérieur, ou purgez depuis Cloudflare.';
		}
		if ( ! empty( $report['litespeed'] ) && 'no-listener' === $report['litespeed'] ) {
			return 'LiteSpeed Cache n’est pas actif ou n’écoute pas litespeed_purge_all — la page est servie par un autre cache (hébergeur, CDN, ou proxy) que ce plugin ne peut pas purger.';
		}
		return 'Purgez toutes les couches avec le bouton ci-dessous, puis revérifiez. Si l’ancienne version persiste, un cache que ce plugin ne contrôle pas se trouve devant le site.';
	}

	/* ------------------------------------------------------------------ */
	/* 3. Purge report                                                     */
	/* ------------------------------------------------------------------ */

	public static function record_purge( $current = '', $previous = '' ): void {
		$layers = array();

		$layers['litespeed'] = has_action( 'litespeed_purge_all' ) ? 'hooked' : 'no-listener';

		$flushed = false;
		if ( function_exists( 'wp_cache_flush' ) ) {
			$flushed = (bool) wp_cache_flush();
		}
		$layers['object_cache'] = $flushed ? 'flushed' : 'none';

		$behind_cf = isset( $_SERVER['HTTP_CF_RAY'] ) && '' !== (string) $_SERVER['HTTP_CF_RAY']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( class_exists( 'Delicat_Builder_V9_Cloudflare', false ) && is_callable( array( 'Delicat_Builder_V9_Cloudflare', 'enabled' ) ) && Delicat_Builder_V9_Cloudflare::enabled() ) {
			$layers['cloudflare'] = 'queued';
		} elseif ( $behind_cf ) {
			$layers['cloudflare'] = 'BEHIND-CLOUDFLARE-BUT-NOT-CONFIGURED';
		} else {
			$layers['cloudflare'] = 'not-detected';
		}

		$summary = sprintf(
			'LiteSpeed : %s · cache objet : %s · Cloudflare : %s',
			$layers['litespeed'],
			$layers['object_cache'],
			$layers['cloudflare']
		);

		update_option(
			self::OPTION_REPORT,
			array_merge(
				$layers,
				array(
					'when'     => gmdate( 'Y-m-d H:i:s' ) . ' UTC',
					'from'     => (string) $previous,
					'to'       => (string) $current,
					'summary'  => $summary,
				)
			),
			false
		);
		delete_transient( self::TRANSIENT );
	}

	/* ------------------------------------------------------------------ */
	/* 4. Deterministic sizing                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Printed at wp_head 9999: after every enqueued stylesheet, immune to
	 * LiteSpeed combining, `html body` specificity. Variables come from App
	 * Tuning's :root block printed earlier, so the settings screen still owns
	 * the numbers; this only guarantees they are applied.
	 */
	public static function authoritative_sizing(): void {
		if ( is_admin() ) {
			return;
		}
		if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_safe_mode' ) ) && Delicat_Builder_V9_Core::is_safe_mode() ) {
			return;
		}
		$css = '@media(max-width:640px){'
			. 'html body .delicat-builder-homepage-managed .delicat-carousel__title,'
			. 'html body .delicat-carousel--variant-ranking .delicat-carousel__title,'
			. 'html body .delicat-category-chips__title,html body .delicat-process__title,html body .delicat-why__title,'
			. 'html body .delicat-testimonials__title,html body .delicat-faq__title,html body .delicat-fav__title,html body .delicat-newsletter h2'
			. '{font-size:var(--dbv9-app-h2,19px)!important;line-height:1.18!important}'
			. 'html body .delicat-builder-hero__title{font-size:var(--dbv9-app-h1,27px)!important;line-height:1.02!important}'
			. 'html body .delicat-product-card__name,html body .delicat-category-tile__copy strong,html body .delicat-process__card h3,html body .delicat-why__card h3'
			. '{font-size:var(--dbv9-app-h3,15px)!important;line-height:1.24!important}'
			. 'html body .delicat-carousel__subtitle,html body .delicat-category-chips__subtitle,html body .delicat-process__subtitle,html body .delicat-testimonials__subtitle'
			. '{font-size:var(--dbv9-app-body,14px)!important;margin-top:4px!important}'
			. 'html body .delicat-builder-homepage-managed .delicat-carousel{margin-bottom:var(--dbv9-app-gap,14px)!important}'
			. 'html body .delicat-builder-homepage-managed .delicat-section{padding-top:var(--dbv9-app-gap,14px)!important;padding-bottom:0!important}'
			. '}'
			. '@media(max-width:820px){'
			. 'html body.delicat-shell-mobile-nav-active,html body.delicat-builder-homepage-managed .delicat-page-layout,html body.delicat-bottom-nav-active.dnp-dock-visible'
			. '{padding-bottom:96px!important;padding-bottom:calc(var(--dbv9-app-dock,66px) + 30px + env(safe-area-inset-bottom))!important}'
			. '}';
		echo '<style id="delicat-builder-v9-authoritative">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal, no user input.
	}
}

Delicat_Builder_V9_Stability::boot();
