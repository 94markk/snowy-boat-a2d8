<?php
namespace Delicat\V10;

use Delicat\V10\Support\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Boots exactly the modules this request needs, and nothing else.
 *
 * The registry below is the only list in V10, and it is a list of *names*, not
 * of conditions: each named class carries its own conditions. Adding a feature
 * means adding one line here and one class; there is no second place that can
 * disagree about when it runs.
 */
final class Kernel {

	/** @var self|null */
	private static $instance = null;

	/** @var Module[] */
	private $booted = array();

	/** @var bool */
	private $ready = false;

	/**
	 * Every module in V10. Order is irrelevant — Module::priority() decides —
	 * so this stays grouped for a reader rather than sorted for a machine.
	 *
	 * @var string[]
	 */
	private const MODULES = array(
		/* Foundations: these three are what every other module stands on. */
		Design\Tokens::class,
		Assets::class,
		Http\Headers::class,

		/* The app shell — the chrome that never reloads. */
		Shell\Document::class,
		Shell\Header::class,
		Shell\TabBar::class,
		Shell\Drawer::class,

		/* Native feel: the platform's own navigation primitives. */
		Nav\ViewTransitions::class,
		Nav\Speculation::class,
		Nav\Instant::class,

		/* Storefront surfaces. */
		Storefront\Home::class,
		Storefront\Product::class,
		Storefront\Catalog::class,
		Storefront\Cart::class,
		Storefront\Checkout::class,
		Storefront\Account::class,
		Storefront\Search::class,

		/* Offline and installability. */
		App\ServiceWorker::class,
		App\Manifest::class,

		/* Administration. */
		Admin\Screen::class,
		Admin\Health::class,
	);

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function boot(): void {
		if ( $this->ready ) {
			return;
		}
		$this->ready = true;

		if ( version_compare( PHP_VERSION, DELICAT_V10_MIN_PHP, '<' ) ) {
			return;
		}

		/*
		 * plugins_loaded, not immediately: WooCommerce must exist before any
		 * module asks whether it does, and translations are not loaded before
		 * init. Priority 20 puts V10 after WooCommerce's own bootstrap (10).
		 */
		add_action( 'plugins_loaded', array( $this, 'load_kind_modules' ), 20 );
		add_action( 'wp', array( $this, 'load_route_modules' ), 1 );
	}

	/** Modules whose conditions are knowable before the query is parsed. */
	public function load_kind_modules(): void {
		$context = Context::instance();

		foreach ( $this->resolve( $context, false ) as $class ) {
			$this->start( $class );
		}
	}

	/** Modules that only apply to some routes, constructed once the route is real. */
	public function load_route_modules(): void {
		$context = Context::instance();

		foreach ( $this->resolve( $context, true ) as $class ) {
			$this->start( $class );
		}
	}

	/**
	 * @return string[]
	 */
	private function resolve( Context $context, bool $route_scoped ): array {
		$matched = array();

		foreach ( self::MODULES as $class ) {
			if ( isset( $this->booted[ $class ] ) || ! class_exists( $class ) ) {
				continue;
			}

			$routes = $class::routes();
			if ( $route_scoped !== ( array() !== $routes ) ) {
				continue;
			}

			$kinds = $class::kinds();
			if ( array() !== $kinds && ! in_array( $context->kind(), $kinds, true ) ) {
				continue;
			}

			if ( array() !== $routes && ! in_array( $context->route(), $routes, true ) ) {
				continue;
			}

			if ( ! $class::enabled() ) {
				continue;
			}

			$matched[ $class ] = $class::priority();
		}

		asort( $matched, SORT_NUMERIC );
		return array_keys( $matched );
	}

	/**
	 * One module failing takes out that module, for that request, and nothing
	 * else. V9 lost the whole storefront to a single bad file more than once.
	 */
	private function start( string $class ): void {
		$module = Guard::run(
			'module:' . $class,
			static function () use ( $class ) {
				$module = new $class();
				$module->register();
				return $module;
			}
		);

		if ( $module instanceof Module ) {
			$this->booted[ $class ] = $module;
		}
	}

	/** @return Module[] class => instance */
	public function booted(): array {
		return $this->booted;
	}

	public function has( string $class ): bool {
		return isset( $this->booted[ $class ] );
	}
}
