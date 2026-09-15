<?php
namespace Delicat\V10;

use Delicat\V10\App\Manifest;
use Delicat\V10\App\ServiceWorker;
use Delicat\V10\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Activation and deactivation.
 *
 * Both are short, and both are reversible. V10 creates no tables, writes no
 * ledgers and schedules no cron jobs, so there is nothing to migrate and nothing
 * left behind when it is switched off.
 *
 * That is a deliberate contrast. V9 created a payment-lock table, a web-push
 * device table, six option ledgers and three cron schedules, and its upgrade
 * path had seven numbered steps - one of which existed purely to release
 * customers that an earlier version had locked out of checkout.
 */
final class Install {

	public const VERSION_OPTION = 'delicat_v10_version';

	public static function activate(): void {
		/* The service worker and manifest are served from rewrite rules, so the
		 * rules must exist before the first request for them. */
		( new ServiceWorker() )->add_rewrite();
		( new Manifest() )->add_rewrite();
		flush_rewrite_rules( false );

		/* A previous activation may have switched a scope off after repeated
		 * failures. A fresh activation is an explicit statement that the
		 * merchant wants it to try again. */
		Guard::clear();

		update_option( self::VERSION_OPTION, DELICAT_V10_VERSION, false );
	}

	public static function deactivate(): void {
		/*
		 * The rewrite rules go, so the service worker URL stops resolving and
		 * browsers that still hold a registration find a 404 and unregister
		 * themselves. Leaving the rule behind would leave installed copies of
		 * the app serving a shell the store no longer uses.
		 */
		flush_rewrite_rules( false );
	}

	/**
	 * Everything V10 has ever written, for a merchant who wants it gone.
	 *
	 * Not called automatically by anything: deactivating a plugin should not
	 * destroy its settings. This exists so that uninstalling can be complete
	 * and so that the list is written down somewhere.
	 *
	 * @return string[]
	 */
	public static function options(): array {
		return array(
			self::VERSION_OPTION,
			'delicat_v10_tokens',
			Guard::LEDGER,
			Guard::DISABLED,
		);
	}

	public static function uninstall(): void {
		foreach ( self::options() as $option ) {
			delete_option( $option );
		}
		delete_transient( 'delicat_v10_search_index' );
	}
}
