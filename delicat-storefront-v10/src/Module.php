<?php
namespace Delicat\V10;

defined( 'ABSPATH' ) || exit;

/**
 * Every feature is a Module. A Module states, in its own file, when it wants to
 * exist — and the kernel obeys. Nothing outside the class needs to know it is
 * there, so nothing outside the class can fall out of step with it.
 *
 * This is the whole reason V10 has no module list to maintain, and why V9's
 * particular failure mode — a search index registered only for admin screens,
 * so the storefront's search box silently had nothing to search — cannot recur:
 * the declaration and the code that needs it are the same file.
 */
abstract class Module {

	/**
	 * Request shapes this module wants. Empty means every shape, which almost
	 * nothing should want.
	 *
	 * @return string[] Context::KIND_* values
	 */
	public static function kinds(): array {
		return array( Context::KIND_FRONT );
	}

	/**
	 * Routes this module wants, for modules that only matter on some pages.
	 * Empty means every route within the declared kinds.
	 *
	 * Route-scoped modules are constructed at `wp`, not at plugins_loaded,
	 * because the route is not knowable before the query is parsed.
	 *
	 * @return string[] Context::ROUTE_* values
	 */
	public static function routes(): array {
		return array();
	}

	/**
	 * A last gate for conditions the kind/route pair cannot express — a setting
	 * being off, WooCommerce being absent. Cheap: it runs on every request.
	 */
	public static function enabled(): bool {
		return true;
	}

	/** Attach hooks. Never echo, never query: this runs during loading. */
	abstract public function register(): void;

	/**
	 * Priority within the boot order. Lower runs first. Only modules that must
	 * beat another module need to say anything; the default is fine.
	 */
	public static function priority(): int {
		return 10;
	}
}
