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
 * 2. NO WAY TO ASK. (V10: the admin deploy-check notice was removed; it lived in
 *    this storefront-only class and never displayed. Compare the stamp above
 *    with the installed version instead.)
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
 * Requires PHP 8.3 (gated in the main plugin file).
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

	/*
	 * V10: the « 2. Deployment check » admin notice (deploy_notice, handle_check, check, is_stale, diagnose)
	 * is gone. This class is loaded on storefront requests only, so admin_notices and
	 * admin_post_delicat_builder_v9_deploy_check never ran: the notice and its button were never shown.
	 * The stamp and the X-Delicat-Builder header above still show the live version (view-source / curl).
	 */

	/* ------------------------------------------------------------------ */
	/* 3. Purge report                                                     */
	/* ------------------------------------------------------------------ */

	public static function record_purge( $current = '', $previous = '' ): void {
		$layers = array();

		$layers['litespeed'] = has_action( 'litespeed_purge_all' ) ? 'hooked' : 'no-listener';

		/* V10: no wp_cache_flush() here any more. On Redis it emptied the WHOLE object cache once per release
		 * (WooCommerce, Identity Pro sign-in state, every rate-limit window), on top of the same flush in the Pro
		 * deploy check. Builder's own fragments and queries are keyed on its cache version, which the release
		 * purge has just renewed (Delicat_Builder_V9_Cache::purge_everything()); the rest of Redis stays warm. */
		$layers['object_cache'] = 'versioned';

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
			. '{padding-bottom:106px!important;padding-bottom:var(--dbv9-nav-band,calc(106px + env(safe-area-inset-bottom,0px)))!important}'
			. '}';
		echo '<style id="delicat-builder-v9-authoritative">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal, no user input.
	}
}

Delicat_Builder_V9_Stability::boot();
