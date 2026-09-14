<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Assets {
	private static bool $needs_carousel = false;
	private static bool $needs_shortcode_style = false;
	private static bool $needs_hero_search = false;
	private static int $builder_page_id = 0;
	private static array $layout = array();
	private static array $manifest = array();

	public static function boot(): void {
		add_action( 'wp', array( __CLASS__, 'detect_page_needs' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 30 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_head', array( __CLASS__, 'managed_homepage_critical_css' ), 1 );
		add_action( 'wp_head', array( __CLASS__, 'preload_hero' ), 2 );
	}

	public static function detect_page_needs(): void {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || is_admin() ) {
			return;
		}

		$page_id = is_singular( 'page' ) ? get_queried_object_id() : 0;
		$post = $page_id ? get_post( $page_id ) : null;

		if ( ! $post instanceof WP_Post ) {
			global $post;
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		if ( has_shortcode( (string) $post->post_content, 'delicat_product_carousel' ) ) {
			self::$needs_carousel = true;
			self::$needs_shortcode_style = true;
		}

		if ( 'page' === $post->post_type && Delicat_Builder_V9_Pages::is_enabled_for_page( $post->ID ) ) {
			$layout = Delicat_Builder_V9_Pages::get_layout( $post->ID );
			if ( ! empty( $layout ) ) {
				self::$builder_page_id = $post->ID;
				self::$layout = $layout;

				try {
					/*
					 * RC39.11: never compile/write CSS during a public GET. Compilation is
					 * an editor/save concern. A stale or missing manifest falls back to the
					 * allow-listed component files without filesystem/transient writes on TTFB.
					 */
					self::$manifest = is_callable( array( 'Delicat_Builder_V9_Compiler', 'get_manifest' ) )
						? Delicat_Builder_V9_Compiler::get_manifest( $post->ID )
						: array();
					if (
						empty( self::$manifest )
						|| ! hash_equals( Delicat_Builder_V9_Compiler::layout_hash( $layout ), (string) ( self::$manifest['layout_hash'] ?? '' ) )
						|| (string) ( self::$manifest['version'] ?? '' ) !== DELICAT_BUILDER_V9_VERSION
					) {
						self::$manifest = array();
					}
					self::$needs_carousel = Delicat_Builder_V9_Renderer::layout_needs_carousel( $layout );
					foreach ( $layout as $section ) {
						if ( 'hero' === ( $section['type'] ?? '' ) && ! empty( $section['content']['search_enabled'] ) ) {
							self::$needs_hero_search = true;
							break;
						}
					}
				} catch ( Throwable $error ) {
					self::$manifest = array();
					self::$needs_carousel = Delicat_Builder_V9_Renderer::layout_needs_carousel( $layout );
					foreach ( $layout as $section ) {
						if ( 'hero' === ( $section['type'] ?? '' ) && ! empty( $section['content']['search_enabled'] ) ) {
							self::$needs_hero_search = true;
							break;
						}
					}
					if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
						Delicat_Builder_V9_Renderer::runtime_failure(
							'asset_detection',
							$error,
							array( 'page_id' => $post->ID )
						);
					}
				}
			}
		}
	}

	public static function managed_homepage_critical_css(): void {
		if (
			! self::$builder_page_id
			|| ! function_exists( 'delicat_builder_v9_is_managed_page' )
			|| ! delicat_builder_v9_is_managed_page( self::$builder_page_id )
		) {
			return;
		}

		$design = class_exists( 'Delicat_Builder_V9_Design' ) ? Delicat_Builder_V9_Design::settings() : array();
		$mode = in_array( $design['homepage_mode'] ?? '', array( 'light', 'dark' ), true ) ? $design['homepage_mode'] : 'light';
		$reference = 'lovable_reference' === ( $design['preset'] ?? '' );

		$background = sanitize_hex_color( (string) ( $design['homepage_background'] ?? '' ) );
		$heading = sanitize_hex_color( (string) ( $design['homepage_heading'] ?? '' ) );
		$subtitle = sanitize_hex_color( (string) ( $design['homepage_subtitle'] ?? '' ) );
		$view_bg = sanitize_hex_color( (string) ( $design['homepage_view_bg'] ?? '' ) );
		$view_text = sanitize_hex_color( (string) ( $design['homepage_view_text'] ?? '' ) );

		if ( 'dark' === $mode ) {
			$background = ( ! $background || '#ffffff' === strtolower( $background ) ) ? '#07143c' : $background;
			$heading = ( ! $heading || '#111827' === strtolower( $heading ) ) ? '#ffffff' : $heading;
			$subtitle = ( ! $subtitle || '#667085' === strtolower( $subtitle ) ) ? '#cbd3ed' : $subtitle;
		} else {
			$background = $background ?: '#ffffff';
			$heading = $heading ?: '#111827';
			$subtitle = $subtitle ?: '#667085';
		}

		$view_bg = $view_bg ?: '#151d48';
		$view_text = $view_text ?: '#ffffff';
		$title_d = min( 64, max( 18, absint( $design['homepage_title_d'] ?? 32 ) ) );
		$title_t = min( 56, max( 18, absint( $design['homepage_title_t'] ?? 30 ) ) );
		$title_m = min( 48, max( 16, absint( $design['homepage_title_m'] ?? 27 ) ) );
		$gap_d = min( 40, max( 0, absint( $design['carousel_gap_d'] ?? 18 ) ) );
		$gap_t = min( 36, max( 0, absint( $design['carousel_gap_t'] ?? 16 ) ) );
		$gap_m = min( 32, max( 0, absint( $design['carousel_gap_m'] ?? 14 ) ) );

		$vars = sprintf(
			'--dbv9-home-bg:%1$s;--dbv9-home-heading:%2$s;--dbv9-home-subtitle:%3$s;--dbv9-home-view-bg:%4$s;--dbv9-home-view-text:%5$s;--dbv9-home-title-d:%6$dpx;--dbv9-home-title-t:%7$dpx;--dbv9-home-title-m:%8$dpx;--dbv9-carousel-gap-d:%9$dpx;--dbv9-carousel-gap-t:%10$dpx;--dbv9-carousel-gap-m:%11$dpx',
			$background,
			$heading,
			$subtitle,
			$view_bg,
			$view_text,
			$title_d,
			$title_t,
			$title_m,
			$gap_d,
			$gap_t,
			$gap_m
		);

		// Structural + appearance variables only. This prevents a vertical flash
		// before products.css arrives without duplicating the full carousel skin.
		$mobile_width = $reference ? 'min(42.5vw,300px)' : 'min(43vw,300px)';
		$hero_critical = '';
		foreach ( self::$layout as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$visible = is_array( $section['visibility'] ?? null ) ? $section['visibility'] : array();
			if ( empty( $visible['desktop'] ) && empty( $visible['tablet'] ) && empty( $visible['mobile'] ) ) {
				continue;
			}
			if ( 'hero' === ( $section['type'] ?? '' ) ) {
				$hero_critical = '.delicat-section--hero .delicat-section__inner{position:relative;min-width:0}.delicat-builder-hero{position:relative;isolation:isolate;display:grid;grid-template-columns:minmax(0,1fr);align-items:center;gap:18px;min-width:0;overflow:hidden}.delicat-builder-hero__background{position:absolute;inset:0;z-index:0;overflow:hidden;border-radius:inherit;pointer-events:none}.delicat-builder-hero__background .delicat-builder-picture,.delicat-builder-hero__background-image{display:block;width:100%;height:100%}.delicat-builder-hero__background-image{object-fit:cover}.delicat-builder-hero__background-overlay{position:absolute;inset:0;background:rgba(6,9,28,.46)}.delicat-builder-hero__copy{position:relative;z-index:2;min-width:0}.delicat-builder-hero__benefits{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:stretch;gap:6px;width:100%;max-width:720px}.delicat-builder-hero__benefit{display:flex;align-items:center;justify-content:center;gap:6px;min-width:0;max-width:100%;text-align:center}.delicat-builder-hero .delicat-inline-icon{display:inline-grid!important;place-items:center!important;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;min-width:0!important;min-height:0!important;line-height:1!important}.delicat-builder-hero .delicat-inline-icon>svg{display:block!important;width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;fill:none!important;stroke:currentColor!important}.delicat-builder-hero__benefit>.delicat-inline-icon{flex:0 0 16px!important;width:16px!important;height:16px!important}.delicat-builder-hero-search{position:relative;z-index:5;width:min(100%,720px);max-width:100%;margin-top:14px}.delicat-builder-hero-search__form{display:grid!important;grid-template-columns:22px minmax(0,1fr) 42px!important;align-items:center!important;gap:8px!important;width:100%!important;max-width:100%!important;min-height:52px!important;padding:5px 6px 5px 13px!important;box-sizing:border-box!important;border:1px solid rgba(103,96,190,.18)!important;border-radius:16px!important;background:rgba(255,255,255,.94)!important}.delicat-builder-hero-search__icon{display:grid!important;place-items:center!important;width:22px!important;height:22px!important}.delicat-builder-hero-search__input{display:block!important;min-width:0!important;width:100%!important;min-height:39px!important;margin:0!important;padding:0!important;border:0!important;outline:0!important;background:transparent!important;box-shadow:none!important;color:#12172a!important;font:inherit!important}.delicat-builder-hero-search__submit{position:static!important;display:grid!important;place-items:center!important;float:none!important;width:42px!important;height:42px!important;min-width:42px!important;min-height:42px!important;margin:0!important;padding:0!important;border:0!important;border-radius:12px!important;background:linear-gradient(135deg,#5964ff,#df3eae)!important;color:#fff!important}.delicat-builder-hero-search__results{position:absolute;z-index:12;left:0;right:0;top:calc(100% + 8px);bottom:auto;max-height:280px;overflow:auto}.delicat-builder-hero-search__results[hidden]{display:none!important}@media(max-width:640px){.delicat-builder-hero{min-height:0!important}.delicat-builder-hero__benefits{grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:6px!important}.delicat-builder-hero__benefit{min-height:50px!important;padding:6px 4px!important}.delicat-builder-hero__benefit>.delicat-inline-icon{flex-basis:14px!important;width:14px!important;height:14px!important}}';
			}
			break;
		}
		echo '<style id="delicat-builder-v9-home-critical">'
			. '.delicat-builder-homepage-managed,.delicat-page-layout[data-delicat-homepage-managed="1"]{' . esc_html( $vars ) . '}'
			. '.delicat-builder-homepage-managed .delicat-page-layout,.delicat-page-layout[data-delicat-homepage-managed="1"]{width:100%;max-width:100%;overflow-x:clip;background:var(--dbv9-home-bg)}'
			. '.delicat-builder-homepage-managed .delicat-section,.delicat-builder-homepage-managed .delicat-section__inner,.delicat-builder-homepage-managed .delicat-carousel,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-section,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-section__inner,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-carousel{min-width:0;max-width:100%}'
			. '.delicat-builder-homepage-managed .delicat-carousel__viewport,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-carousel__viewport{width:100%;max-width:100%;overflow:hidden}'
			. '.delicat-builder-homepage-managed .delicat-carousel__track,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-carousel__track{display:grid;grid-auto-flow:column;grid-auto-columns:' . esc_html( $mobile_width ) . ';gap:var(--dbv9-carousel-gap-m,14px);overflow-x:auto;overflow-y:hidden;max-width:100%;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}'
			. '.delicat-builder-homepage-managed .delicat-product-card,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-product-card{min-width:0;scroll-snap-align:start}'
			. '@media(min-width:641px){.delicat-builder-homepage-managed .delicat-carousel__track,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-carousel__track{grid-auto-columns:min(30vw,250px);gap:var(--dbv9-carousel-gap-t,16px)}}'
			. '@media(min-width:961px){.delicat-builder-homepage-managed .delicat-carousel__track,.delicat-page-layout[data-delicat-homepage-managed="1"] .delicat-carousel__track{grid-auto-columns:clamp(196px,23vw,268px)}}'
			. $hero_critical
			. '</style>';
	}

	public static function preload_hero(): void {
		if ( ! self::$builder_page_id || empty( self::$layout ) ) {
			return;
		}

		$settings = Delicat_Builder_V9_Core::settings();
		if ( empty( $settings['hero_preload'] ) ) {
			return;
		}

		foreach ( self::$layout as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$visible = $section['visibility'] ?? array();
			if ( empty( $visible['desktop'] ) && empty( $visible['tablet'] ) && empty( $visible['mobile'] ) ) {
				continue;
			}

			if ( 'hero' !== ( $section['type'] ?? '' ) ) {
				// Only preload when the first visible content section is the hero.
				return;
			}

			// Avoid promoting a hero that is hidden on one device class. A future
			// device-specific preload phase can optimize those layouts separately.
			if ( empty( $visible['desktop'] ) || empty( $visible['tablet'] ) || empty( $visible['mobile'] ) ) {
				return;
			}

			$content = is_array( $section['content'] ?? null ) ? $section['content'] : array();
			$background_desktop = absint( $content['background_image_id'] ?? 0 );
			$background_mobile  = absint( $content['mobile_background_image_id'] ?? 0 );
			if (
				Delicat_Builder_V9_Media::is_image_attachment( $background_desktop )
				|| Delicat_Builder_V9_Media::is_image_attachment( $background_mobile )
			) {
				echo Delicat_Builder_V9_Media::preload_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					$background_desktop,
					$background_mobile,
					'(max-width:1200px) 100vw, 1200px'
				);
			} else {
				echo Delicat_Builder_V9_Media::preload_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					absint( $content['image_id'] ?? 0 ),
					absint( $content['mobile_image_id'] ?? 0 )
				);
			}
			return;
		}
	}

	public static function body_class( $classes ): array {
		$classes = is_array( $classes ) ? $classes : array();
		if ( Delicat_Builder_V9_Core::is_enabled() ) {
			$classes[] = 'delicat-builder-v9';
		}
		if ( self::$builder_page_id ) {
			$classes[] = 'delicat-builder-v9-compiled-page';
			if (
				function_exists( 'delicat_builder_v9_is_managed_page' )
				&& delicat_builder_v9_is_managed_page( self::$builder_page_id )
			) {
				$classes[] = 'delicat-builder-homepage-managed';
			}
		}
		return $classes;
	}

	private static function enqueue_component_css( array $assets ): void {
		foreach ( array_values( array_unique( $assets ) ) as $asset ) {
			$path = Delicat_Builder_V9_Compiler::static_css_path( $asset );
			$url  = Delicat_Builder_V9_Compiler::static_css_url( $asset );
			if ( ! $path || ! $url || ! is_file( $path ) ) {
				continue;
			}
			wp_enqueue_style(
				'delicat-builder-v9-' . sanitize_key( $asset ),
				$url,
				array(),
				DELICAT_BUILDER_V9_VERSION
			);
		}
	}

	/**
	 * A release-built, namespaced fallback for pages whose per-page compiled
	 * bundle is missing or stale. One request is materially faster than ten
	 * render-blocking component requests on a high-latency mobile connection.
	 */
	private static function enqueue_component_fallback_bundle(): bool {
		$path = DELICAT_BUILDER_V9_DIR . 'assets/components/all-components.min.css';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		wp_enqueue_style(
			'delicat-builder-v9-components',
			DELICAT_BUILDER_V9_URL . 'assets/components/all-components.min.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
		return true;
	}

	private static function enqueue_builder_css(): void {
		if ( ! self::$builder_page_id || empty( self::$layout ) ) {
			return;
		}

		/*
		 * RC41: prefer the already-validated compiled bundle for every Builder page,
		 * including the managed homepage. This turns many tiny component stylesheets
		 * into one request on the warm path. Public requests never compile/write;
		 * a missing or stale bundle still falls back to the allow-listed components.
		 */

		$manifest = self::$manifest;

		/*
		 * 9.1 RC11: honor the manifest contract on the public path too. A
		 * bundle compiled by an older plugin build (version mismatch) or
		 * against older component sources (signature mismatch) is stale
		 * evidence — serving it under new markup is what broke the signed-out
		 * homepage after the RC9 testimonial redesign. Stale ⇒ fall back to
		 * the allow-listed live components (correct immediately); the
		 * upgrade/admin recompile refreshes the bundle. Public requests still
		 * never compile or write.
		 */
		if ( DELICAT_BUILDER_V9_VERSION !== (string) ( $manifest['version'] ?? '' ) ) {
			$manifest = array();
		} else {
			try {
				$analysis_check = Delicat_Builder_V9_Compiler::analyze_layout( self::$layout );
				$live_signature = Delicat_Builder_V9_Compiler::source_signature( (array) ( $analysis_check['css'] ?? array() ) );
				if ( ! hash_equals( $live_signature, (string) ( $manifest['source_signature'] ?? '' ) ) ) {
					$manifest = array();
				}
			} catch ( Throwable $signature_error ) {
				unset( $signature_error );
			}
		}

		$compiled = Delicat_Builder_V9_Compiler::compiled_asset( self::$builder_page_id, $manifest );
		if ( ! empty( $compiled['url'] ) ) {
			wp_enqueue_style(
				'delicat-builder-v9-compiled',
				$compiled['url'],
				array(),
				(string) ( $manifest['source_signature'] ?? DELICAT_BUILDER_V9_VERSION )
			);
			return;
		}

		try {
			$analysis = Delicat_Builder_V9_Compiler::analyze_layout( self::$layout );
			if ( count( (array) ( $analysis['css'] ?? array() ) ) <= 3 || ! self::enqueue_component_fallback_bundle() ) {
				self::enqueue_component_css( (array) ( $analysis['css'] ?? array() ) );
			}
		} catch ( Throwable $error ) {
			if ( method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
				Delicat_Builder_V9_Renderer::runtime_failure(
					'asset_fallback_enqueue',
					$error,
					array( 'page_id' => self::$builder_page_id )
				);
			}
			if ( ! self::enqueue_component_fallback_bundle() ) {
				self::enqueue_component_css( array( 'base' ) );
			}
		}
	}

	private static function enqueue_script_asset( string $name, array $deps = array() ): void {
		$allowed = array( 'core', 'carousel', 'heart', 'hero-search', 'prefetch', 'navigation', 'shell', 'theme', 'motion', 'islands' );
		if ( ! in_array( $name, $allowed, true ) ) {
			return;
		}

		$path = DELICAT_BUILDER_V9_DIR . 'assets/js/' . $name . '.js';
		if ( ! is_file( $path ) ) {
			return;
		}

		wp_enqueue_script(
			'delicat-builder-v9-' . $name,
			DELICAT_BUILDER_V9_URL . 'assets/js/' . $name . '.js',
			$deps,
			DELICAT_BUILDER_V9_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/** Only warm documents the browser can actually reuse on the following tap. */
	private static function document_prefetch_reusable(): bool {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			return false;
		}
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( (string) $header, 'Cache-Control:' ) && false !== stripos( (string) $header, 'no-store' ) ) {
				return false;
			}
		}
		if ( 'geolocation' === sanitize_key( (string) get_option( 'woocommerce_default_customer_address', 'base' ) ) ) {
			return false;
		}
		if ( class_exists( 'Delicat_Builder_V9_Security', false ) ) {
			if ( is_user_logged_in() && is_callable( array( 'Delicat_Builder_V9_Security', 'private_cache_allowed' ) ) ) {
				return Delicat_Builder_V9_Security::private_cache_allowed();
			}
			if ( is_callable( array( 'Delicat_Builder_V9_Security', 'public_cache_allowed' ) ) ) {
				return Delicat_Builder_V9_Security::public_cache_allowed();
			}
		}
		return ! is_user_logged_in();
	}

	private static function enqueue_theme_assets(): void {
		/* RC51.9: theme ownership is global whenever Builder V9 is active.
		 * Header Studio V8 remains the header engine; this only supplies the
		 * lightweight visual theme + delegated toggle runtime. */
		$chrome_path = DELICAT_BUILDER_V9_DIR . 'assets/css/storefront-chrome.min.css';
		if ( is_file( $chrome_path ) ) {
			// Header, drawer and bottom-nav enqueue earlier (priorities 8/9).
			// Their cascade order is preserved inside the release-built bundle.
			foreach ( array( 'delicat-builder-v9-theme-system', 'delicat-builder-v9-header-studio-8', 'delicat-builder-v9-drawer', 'delicat-builder-v9-bottom-nav' ) as $handle ) {
				wp_dequeue_style( $handle );
			}
			wp_enqueue_style(
				'delicat-builder-v9-storefront-chrome',
				DELICAT_BUILDER_V9_URL . 'assets/css/storefront-chrome.min.css',
				array(),
				DELICAT_BUILDER_V9_VERSION
			);
		} else {
			$css_path = DELICAT_BUILDER_V9_DIR . 'assets/css/theme-system.css';
			if ( is_file( $css_path ) ) {
				wp_enqueue_style(
					'delicat-builder-v9-theme-system',
					DELICAT_BUILDER_V9_URL . 'assets/css/theme-system.css',
					array(),
					DELICAT_BUILDER_V9_VERSION
				);
			}
		}

		// RC51.34 zero-global-JS: retain theme runtime only on requests that can
		// actually expose a V9 visual surface. Header Studio/Shell still keep it.
		$performance = class_exists( 'Delicat_Builder_V9_Performance', false ) ? Delicat_Builder_V9_Performance::settings() : array();
		$needs_theme_runtime = self::$builder_page_id > 0
			|| self::$needs_shortcode_style
			|| self::shell_surface_expected();
		if ( empty( $performance['zero_global_js'] ) || $needs_theme_runtime ) {
			self::enqueue_script_asset( 'theme' );
		}
	}

	/**
	 * RC18: Shell::footer_expected() was retired in RC14 while this file kept
	 * calling it unguarded ("Call to undefined method" on any Builder page
	 * where the header/mobile dock was not expected). Probe both surfaces
	 * through is_callable() so a future Shell refactor can never fatal here.
	 */
	private static function shell_surface_expected(): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Shell', false ) ) {
			return false;
		}
		if ( is_callable( array( 'Delicat_Builder_V9_Shell', 'header_expected' ) ) && Delicat_Builder_V9_Shell::header_expected() ) {
			return true;
		}
		return is_callable( array( 'Delicat_Builder_V9_Shell', 'footer_expected' ) ) && Delicat_Builder_V9_Shell::footer_expected();
	}

	private static function enqueue_shell_assets(): void {
		if ( ! self::shell_surface_expected() ) {
			return;
		}

		$needs_full_shell_css = ! is_callable( array( 'Delicat_Builder_V9_Shell', 'needs_full_shell_css' ) )
			|| Delicat_Builder_V9_Shell::needs_full_shell_css();
		$css_path = DELICAT_BUILDER_V9_DIR . 'assets/css/shell.css';
		if ( $needs_full_shell_css && is_file( $css_path ) ) {
			wp_enqueue_style(
				'delicat-builder-v9-shell',
				DELICAT_BUILDER_V9_URL . 'assets/css/shell.css',
				array(),
				DELICAT_BUILDER_V9_VERSION
			);
		}

		if ( is_callable( array( 'Delicat_Builder_V9_Shell', 'needs_shell_js' ) ) && Delicat_Builder_V9_Shell::needs_shell_js() ) {
			self::enqueue_script_asset( 'shell' );
		}

	}

	public static function enqueue(): void {
		if ( ! Delicat_Builder_V9_Core::is_enabled() || is_admin() ) {
			return;
		}

		$settings = Delicat_Builder_V9_Core::settings();
		$motion = Delicat_Builder_V9_Core::motion_settings();

		self::enqueue_theme_assets();
		self::enqueue_shell_assets();

		if ( self::$builder_page_id ) {
			self::enqueue_builder_css();
		} elseif ( self::$needs_shortcode_style ) {
			self::enqueue_component_css( array( 'base', 'products' ) );
		}

		$safe_mode = Delicat_Builder_V9_Core::is_safe_mode();
		$performance_settings = class_exists( 'Delicat_Builder_V9_Performance', false ) ? Delicat_Builder_V9_Performance::settings() : array();
		$islands_mode = ! $safe_mode && self::$builder_page_id > 0 && ! empty( $performance_settings['islands_mode'] );

		/*
		 * RC46: configurable cross-device scroll-reveal motion for Builder pages.
		 * Reduced Motion is respected as a hard stop; Save-Data/low-power devices
		 * receive an adaptive lighter profile instead of losing motion entirely.
		 * First-viewport content remains untouched to protect LCP/CLS.
		 */
		if ( self::$builder_page_id && ! $safe_mode && ! empty( $motion['enabled'] ) ) {
			self::enqueue_script_asset( 'motion' );
			$motion_config = array(
				'enabled'           => true,
				'effect'            => (string) $motion['effect'],
				'duration'          => absint( $motion['duration_ms'] ),
				'intensity'         => absint( $motion['intensity'] ),
				'stagger'           => absint( $motion['stagger_ms'] ),
				'baseDelay'         => absint( $motion['base_delay_ms'] ),
				'threshold'         => absint( $motion['threshold_percent'] ) / 100,
				'desktop'           => ! empty( $motion['desktop_enabled'] ),
				'tablet'            => ! empty( $motion['tablet_enabled'] ),
				'mobile'            => ! empty( $motion['mobile_enabled'] ),
				'mobileIntensity'   => absint( $motion['mobile_intensity'] ),
			);
			wp_add_inline_script(
				'delicat-builder-v9-motion',
				'window.DelicaBuilderV9MotionConfig=' . wp_json_encode( $motion_config ) . ';',
				'before'
			);
		}
		if ( self::$needs_hero_search && ! $safe_mode ) {
			self::enqueue_script_asset( 'hero-search' );
		}

		$navigation_scope = in_array( $settings['navigation_scope'], array( 'marked', 'site' ), true ) ? $settings['navigation_scope'] : 'marked';
		/* RC34: Builder ships no marked-link emitter, and the live audit found
		 * zero targets. Do not download a partial DOM-swap engine that cannot
		 * handle any link. A custom integration can opt in after providing a
		 * complete script/body-class lifecycle for its marked destinations. */
		$marked_navigation_runtime = (bool) apply_filters( 'delicat_builder_v9_marked_navigation_runtime', false, self::$builder_page_id );
		$needs_navigation = ! $safe_mode
			&& ! empty( $settings['app_navigation'] )
			&& ( 'site' === $navigation_scope || $marked_navigation_runtime );
		$performance_predictive = ! $safe_mode && Delicat_Builder_V9_Performance::predictive_enabled_for_request();

		$performance_config = Delicat_Builder_V9_Performance::predictive_config();
		$document_prefetch_reusable = self::document_prefetch_reusable();
		$archive_product_links = class_exists( 'Delicat_Builder_V9_Woo_UI' )
			&& is_callable( array( 'Delicat_Builder_V9_Woo_UI', 'archive_active' ) )
			&& Delicat_Builder_V9_Woo_UI::archive_active();
		$instant_product_launch = ! empty( $performance_config['instantProductLaunch'] )
			&& $document_prefetch_reusable
			&& ( self::$needs_carousel || $archive_product_links );

		$normal_prefetch = ! $safe_mode && $document_prefetch_reusable && (
			(
				! empty( $settings['prefetch'] )
				&& ( self::$needs_carousel || $needs_navigation )
			) || $performance_predictive
		);
		// The instant Acheter accelerator is deliberately allowed in Safe Mode:
		// it is a same-origin, read-only GET intent warm with no cart/order mutation.
		$needs_prefetch = $normal_prefetch || $instant_product_launch;

		$needs_core = $needs_navigation || $needs_prefetch || ( self::$needs_carousel && ! $islands_mode );
		$needs_islands = $islands_mode && self::$needs_carousel;

		if ( ! $needs_core && ! $needs_islands ) {
			return;
		}

		if ( $needs_core ) {
			self::enqueue_script_asset( 'core' );
		}
		if ( $needs_islands ) {
			self::enqueue_script_asset( 'islands' );
		}

		$carousel_path = DELICAT_BUILDER_V9_DIR . 'assets/js/carousel.js';
		$carousel_url  = DELICAT_BUILDER_V9_URL . 'assets/js/carousel.js';
		if ( is_file( $carousel_path ) ) {
			$carousel_url = add_query_arg( 'ver', rawurlencode( DELICAT_BUILDER_V9_VERSION ), $carousel_url );
		}

		$heart_path = DELICAT_BUILDER_V9_DIR . 'assets/js/heart.js';
		$heart_url  = DELICAT_BUILDER_V9_URL . 'assets/js/heart.js';
		if ( is_file( $heart_path ) ) {
			$heart_url = add_query_arg( 'ver', rawurlencode( DELICAT_BUILDER_V9_VERSION ), $heart_url );
		}

		$core_path = DELICAT_BUILDER_V9_DIR . 'assets/js/core.js';
		$core_url  = DELICAT_BUILDER_V9_URL . 'assets/js/core.js';
		if ( is_file( $core_path ) ) {
			$core_url = add_query_arg( 'ver', rawurlencode( DELICAT_BUILDER_V9_VERSION ), $core_url );
		}

		$woo_settings = class_exists( 'Delicat_Builder_V9_Woo_UI' ) ? Delicat_Builder_V9_Woo_UI::effective_archive_settings() : array();

		$config = array(
			'appNavigation'   => $needs_navigation,
			'navigationScope' => $navigation_scope,
			'contentSelector' => Delicat_Builder_V9_Security::sanitize_selector( (string) $settings['content_selector'] ),
			'prefetch'        => ! $safe_mode && ! empty( $settings['prefetch'] ),
			'lowPowerMode'    => ! empty( $settings['low_power_mode'] ),
			'carouselProgressive' => ! empty( $settings['carousel_progressive'] ),
			'carouselInitial' => max( 3, min( 12, absint( $settings['carousel_initial'] ?? 8 ) ) ),
			'homeUrl'         => home_url( '/' ),
			'predictiveMode'  => ! $safe_mode && ! empty( $performance_config['predictiveMode'] ),
			'intentDelay'     => absint( $performance_config['intentDelay'] ?? 90 ),
			'prefetchBudget'  => absint( $performance_config['prefetchBudget'] ?? 6 ),
			'prefetchMaxBytes'=> absint( $performance_config['prefetchMaxBytes'] ?? 524288 ),
			'networkAware'    => ! empty( $performance_config['networkAware'] ),
			'instantProductLaunch' => $instant_product_launch,
			'instantTouchDelay' => min( 80, max( 0, absint( $performance_config['instantTouchDelay'] ?? 24 ) ) ),
			'archiveTouchPrefetch' => ! empty( $woo_settings['archive_touch_prefetch'] ),
			'motion'           => array(
				'pageTransitions' => ! empty( $motion['page_transitions'] ),
				'pageTransition'  => (string) $motion['page_transition'],
				'pageDuration'    => absint( $motion['page_duration_ms'] ),
			),
			'heart'           => class_exists( 'Delicat_Builder_V9_Heart_Engine' ) ? Delicat_Builder_V9_Heart_Engine::frontend_config() : array( 'enabled' => false ),
			'modules'         => array(
				'core'     => $core_url,
				'carousel' => $carousel_url,
				'heart'    => $heart_url,
			),
		);

		$config_handle = $needs_core ? 'delicat-builder-v9-core' : 'delicat-builder-v9-islands';
		wp_add_inline_script(
			$config_handle,
			'window.DelicaBuilderV9=' . wp_json_encode( $config ) . ';',
			'before'
		);

		if ( self::$needs_carousel && ! $islands_mode ) {
			self::enqueue_script_asset( 'carousel', array( 'delicat-builder-v9-core' ) );
		}

		/* Favorites must be ready for the first tap. Loading this tiny delegated
		 * handler only after the carousel island intersects could lose that tap. */
		if (
			self::$needs_carousel
			&& class_exists( 'Delicat_Builder_V9_Heart_Engine' )
			&& Delicat_Builder_V9_Heart_Engine::enabled()
		) {
			self::enqueue_script_asset( 'heart', array( $config_handle ) );
		}

		if ( $needs_prefetch ) {
			self::enqueue_script_asset( 'prefetch', array( 'delicat-builder-v9-core' ) );
		}

		if ( $needs_navigation ) {
			$deps = array( 'delicat-builder-v9-core' );
			if ( $needs_prefetch ) {
				$deps[] = 'delicat-builder-v9-prefetch';
			}
			self::enqueue_script_asset( 'navigation', $deps );
		}
	}
}
