<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Beta 3 page compiler.
 *
 * The compiler never accepts arbitrary CSS/JS from users. It only combines
 * plugin-owned, allow-listed component assets selected by the sanitized layout.
 */
final class Delicat_Builder_V9_Compiler {
	public const META_MANIFEST = '_delicat_builder_v9_manifest';
	private const DIR_NAME     = 'delicat-builder-v9/compiled';
	private const LOCK_TTL     = 20;

	private static array $manifest_cache = array();

	public static function boot(): void {
		add_action( 'before_delete_post', array( __CLASS__, 'cleanup_deleted_post' ), 20 );
		add_action( 'admin_post_delicat_builder_v9_compile_all', array( __CLASS__, 'handle_compile_all' ) );
		add_action( 'admin_post_delicat_builder_v9_cleanup_compiled', array( __CLASS__, 'handle_cleanup' ) );
		/* 9.1 RC11: compiled bundles are build artifacts of the plugin
		 * version + component sources; every release must refresh them.
		 * Runs once per version on the first admin visit — never publicly. */
		add_action( 'admin_init', array( __CLASS__, 'maybe_recompile_for_version' ), 30 );
	}

	public static function component_map(): array {
		return array(
			'hero' => array(
				'css' => array( 'base', 'hero' ),
				'js'  => array(),
			),
			'text' => array(
				'css' => array( 'base', 'text' ),
				'js'  => array(),
			),
			'banner' => array(
				'css' => array( 'base', 'banner' ),
				'js'  => array(),
			),
			'products' => array(
				'css' => array( 'base', 'products' ),
				'js'  => array( 'core', 'carousel' ),
			),
			'category_chips' => array(
				'css' => array( 'base', 'category-chips' ),
				'js'  => array(),
			),
			'how_it_works' => array(
				'css' => array( 'base', 'how-it-works' ),
				'js'  => array(),
			),
			'testimonials' => array(
				'css' => array( 'base', 'testimonials' ),
				'js'  => array(),
			),
			'faq' => array(
				'css' => array( 'base', 'faq' ),
				'js'  => array(),
			),
			'why_delicat' => array(
				'css' => array( 'base', 'why-delicat' ),
				'js'  => array(),
			),
			'favorites' => array(
				'css' => array( 'base', 'favorites' ),
				'js'  => array(),
			),
			'bon_kliyan' => array(
				'css' => array( 'base', 'bon-kliyan' ),
				'js'  => array(),
			),
			'newsletter' => array(
				'css' => array( 'base', 'newsletter' ),
				'js'  => array(),
			),
			'trust_strip' => array(
				'css' => array( 'base', 'trust-strip' ),
				'js'  => array(),
			),
			'spacer' => array(
				'css' => array( 'base', 'spacer' ),
				'js'  => array(),
			),
		);
	}

	public static function analyze_layout( array $layout ): array {
		$map        = self::component_map();
		$components = array();
		$css        = array();
		$js         = array();

		foreach ( $layout as $section ) {
			$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
			if ( ! isset( $map[ $type ] ) ) {
				continue;
			}

			if ( ! in_array( $type, $components, true ) ) {
				$components[] = $type;
			}

			foreach ( $map[ $type ]['css'] as $asset ) {
				if ( ! in_array( $asset, $css, true ) ) {
					$css[] = $asset;
				}
			}
			foreach ( $map[ $type ]['js'] as $asset ) {
				if ( ! in_array( $asset, $js, true ) ) {
					$js[] = $asset;
				}
			}
		}

		$first_visible = '';
		$critical_css = array();
		foreach ( $layout as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}
			$visibility = is_array( $section['visibility'] ?? null ) ? $section['visibility'] : array();
			if ( empty( $visibility['desktop'] ) && empty( $visibility['tablet'] ) && empty( $visibility['mobile'] ) ) {
				continue;
			}
			$type = sanitize_key( (string) ( $section['type'] ?? '' ) );
			if ( isset( $map[ $type ] ) ) {
				$first_visible = $type;
				$critical_css = array_values( array_unique( $map[ $type ]['css'] ) );
			}
			break;
		}

		$preload_js = array();
		if ( 'products' === $first_visible ) {
			$preload_js[] = 'carousel';
		}

		return array(
			'components'     => $components,
			'css'            => $css,
			'js'             => $js,
			'needs_carousel' => in_array( 'products', $components, true ),
			'resource_graph'  => array(
				'first_visible_component' => $first_visible,
				'critical_css'            => $critical_css,
				'noncritical_css'         => array_values( array_diff( $css, $critical_css ) ),
				'interactive_js'          => $js,
				'preload_js'              => $preload_js,
			),
		);
	}

	public static function layout_hash( array $layout ): string {
		return substr( hash( 'sha256', wp_json_encode( $layout ) ?: '[]' ), 0, 24 );
	}

	private static function component_css_path( string $asset ): string {
		$allowed = array( 'base', 'hero', 'text', 'banner', 'products', 'category-chips', 'how-it-works', 'testimonials', 'faq', 'why-delicat', 'favorites', 'bon-kliyan', 'newsletter', 'trust-strip', 'spacer' );
		if ( ! in_array( $asset, $allowed, true ) ) {
			return '';
		}
		return DELICAT_BUILDER_V9_DIR . 'assets/components/' . $asset . '.css';
	}

	public static function source_signature( array $css_assets ): string {
		$parts = array( DELICAT_BUILDER_V9_VERSION );
		foreach ( $css_assets as $asset ) {
			$path = self::component_css_path( $asset );
			if ( $path && is_file( $path ) ) {
				$parts[] = $asset . ':' . (string) filemtime( $path ) . ':' . (string) filesize( $path );
			}
		}
		return substr( hash( 'sha256', implode( '|', $parts ) ), 0, 24 );
	}

	private static function minify_css( string $css ): string {
		// Source is plugin-owned. This is deliberately conservative to avoid altering values.
		$css = preg_replace( '#/\*[^!][\s\S]*?\*/#', '', $css );
		$css = preg_replace( '/\s+/', ' ', (string) $css );
		$css = preg_replace( '/\s*([{}:;,>])\s*/', '$1', (string) $css );
		return trim( (string) $css );
	}

	private static function build_css( array $assets ): string {
		$chunks = array();
		foreach ( $assets as $asset ) {
			$path = self::component_css_path( $asset );
			if ( ! $path || ! is_file( $path ) || ! is_readable( $path ) ) {
				continue;
			}
			$content = file_get_contents( $path );
			if ( is_string( $content ) && '' !== trim( $content ) ) {
				$chunks[] = $content;
			}
		}
		return self::minify_css( implode( "\n", $chunks ) );
	}

	private static function upload_info(): array {
		$upload = wp_upload_dir( null, false );
		if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) || empty( $upload['baseurl'] ) ) {
			return array();
		}

		$basedir = wp_normalize_path( (string) $upload['basedir'] );
		$baseurl = rtrim( (string) $upload['baseurl'], '/' );
		$dir     = wp_normalize_path( trailingslashit( $basedir ) . self::DIR_NAME );
		$url     = $baseurl . '/' . self::DIR_NAME;

		return array(
			'basedir' => $basedir,
			'baseurl' => $baseurl,
			'dir'     => $dir,
			'url'     => $url,
		);
	}

	public static function storage_status(): array {
		$info = self::upload_info();
		if ( empty( $info ) ) {
			return array( 'ok' => false, 'text' => __( 'Uploads unavailable — component fallback active', 'delicat-builder-v9' ) );
		}

		$dir = $info['dir'];
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return array( 'ok' => false, 'text' => __( 'Compiled directory not writable — component fallback active', 'delicat-builder-v9' ) );
		}

		return array(
			'ok'   => is_writable( $dir ),
			'text' => is_writable( $dir )
				? __( 'Compiled asset storage ready', 'delicat-builder-v9' )
				: __( 'Compiled directory not writable — component fallback active', 'delicat-builder-v9' ),
		);
	}

	private static function valid_compiled_filename( int $page_id, string $file ): bool {
		return 1 === preg_match( '/^page-' . preg_quote( (string) $page_id, '/' ) . '-[a-f0-9]{16}\.css$/', $file );
	}

	public static function compiled_asset( int $page_id, array $manifest ): array {
		$file = sanitize_file_name( (string) ( $manifest['file'] ?? '' ) );
		if ( ! self::valid_compiled_filename( $page_id, $file ) ) {
			return array();
		}

		$info = self::upload_info();
		if ( empty( $info ) ) {
			return array();
		}

		$path = wp_normalize_path( trailingslashit( $info['dir'] ) . $file );
		$base = trailingslashit( wp_normalize_path( $info['dir'] ) );
		if ( 0 !== strpos( $path, $base ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return array();
		}

		return array(
			'path' => $path,
			'url'  => trailingslashit( $info['url'] ) . rawurlencode( $file ),
			'file' => $file,
		);
	}

	public static function get_manifest( int $page_id ): array {
		if ( isset( self::$manifest_cache[ $page_id ] ) ) {
			return self::$manifest_cache[ $page_id ];
		}

		$manifest = get_post_meta( $page_id, self::META_MANIFEST, true );
		self::$manifest_cache[ $page_id ] = is_array( $manifest ) ? $manifest : array();
		return self::$manifest_cache[ $page_id ];
	}

	private static function manifest_is_current( int $page_id, array $layout, array $manifest ): bool {
		if ( empty( $manifest ) || (int) ( $manifest['page_id'] ?? 0 ) !== $page_id ) {
			return false;
		}

		$analysis = self::analyze_layout( $layout );
		return hash_equals( self::layout_hash( $layout ), (string) ( $manifest['layout_hash'] ?? '' ) )
			&& hash_equals( self::source_signature( $analysis['css'] ), (string) ( $manifest['source_signature'] ?? '' ) )
			&& (string) ( $manifest['version'] ?? '' ) === DELICAT_BUILDER_V9_VERSION;
	}

	public static function ensure_manifest( int $page_id, array $layout ): array {
		$manifest = self::get_manifest( $page_id );
		if ( self::manifest_is_current( $page_id, $layout, $manifest ) ) {
			return $manifest;
		}

		$lock_key = 'dbv9_compile_' . $page_id;
		if ( get_transient( $lock_key ) ) {
			return $manifest;
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );
		$manifest = self::compile_page( $page_id, $layout );
		delete_transient( $lock_key );

		return is_array( $manifest ) ? $manifest : array();
	}

	public static function compile_page( int $page_id, ?array $layout = null ): array {
		if ( 'page' !== get_post_type( $page_id ) ) {
			return array();
		}

		$layout = is_array( $layout ) ? Delicat_Builder_V9_Schema::sanitize_layout( $layout ) : Delicat_Builder_V9_Pages::get_layout( $page_id );
		$analysis = self::analyze_layout( $layout );
		$layout_hash = self::layout_hash( $layout );
		$source_signature = self::source_signature( $analysis['css'] );
		$bundle_hash = substr( hash( 'sha256', $layout_hash . '|' . $source_signature ), 0, 16 );

		$manifest = array(
			'version'          => DELICAT_BUILDER_V9_VERSION,
			'page_id'          => $page_id,
			'layout_hash'      => $layout_hash,
			'source_signature' => $source_signature,
			'components'       => $analysis['components'],
			'css'              => $analysis['css'],
			'js'               => $analysis['js'],
			'needs_carousel'   => $analysis['needs_carousel'],
			'resource_graph'    => $analysis['resource_graph'] ?? array(),
			'generated_at'     => time(),
			'storage'          => 'components',
			'file'             => '',
			'css_bytes'        => 0,
		);

		$settings = Delicat_Builder_V9_Core::settings();
		if ( empty( $settings['compiled_assets'] ) || empty( $analysis['css'] ) ) {
			self::cleanup_page_files( $page_id );
			update_post_meta( $page_id, self::META_MANIFEST, $manifest );
			self::$manifest_cache[ $page_id ] = $manifest;
			return $manifest;
		}

		$css = self::build_css( $analysis['css'] );
		if ( '' === $css ) {
			update_post_meta( $page_id, self::META_MANIFEST, $manifest );
			self::$manifest_cache[ $page_id ] = $manifest;
			return $manifest;
		}

		$info = self::upload_info();
		if ( ! empty( $info ) && ( is_dir( $info['dir'] ) || wp_mkdir_p( $info['dir'] ) ) && is_writable( $info['dir'] ) ) {
			$file = 'page-' . $page_id . '-' . $bundle_hash . '.css';
			$target = wp_normalize_path( trailingslashit( $info['dir'] ) . $file );
			$tmp = $target . '.tmp-' . wp_generate_password( 8, false, false );

			$written = file_put_contents( $tmp, $css, LOCK_EX );
			if ( false !== $written && $written === strlen( $css ) ) {
				if ( @rename( $tmp, $target ) ) {
					self::cleanup_page_files( $page_id, $file );
					$manifest['storage']   = 'compiled';
					$manifest['file']      = $file;
					$manifest['css_bytes'] = strlen( $css );
				} else {
					@unlink( $tmp );
				}
			} else {
				@unlink( $tmp );
			}

			$index = trailingslashit( $info['dir'] ) . 'index.html';
			if ( ! is_file( $index ) ) {
				@file_put_contents( $index, '', LOCK_EX );
			}
		}

		update_post_meta( $page_id, self::META_MANIFEST, $manifest );
		self::$manifest_cache[ $page_id ] = $manifest;
		return $manifest;
	}

	public static function cleanup_page_files( int $page_id, string $keep = '' ): void {
		$info = self::upload_info();
		if ( empty( $info ) || ! is_dir( $info['dir'] ) ) {
			return;
		}

		$pattern = trailingslashit( $info['dir'] ) . 'page-' . $page_id . '-*.css';
		$files = glob( $pattern );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			$name = basename( $file );
			if ( $keep && hash_equals( $keep, $name ) ) {
				continue;
			}
			if ( self::valid_compiled_filename( $page_id, $name ) ) {
				@unlink( $file );
			}
		}
	}

	public static function cleanup_deleted_post( int $post_id ): void {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return;
		}
		self::cleanup_page_files( $post_id );
		unset( self::$manifest_cache[ $post_id ] );
	}

	public static function static_css_url( string $asset ): string {
		$path = self::component_css_path( $asset );
		if ( ! $path ) {
			return '';
		}
		return DELICAT_BUILDER_V9_URL . 'assets/components/' . rawurlencode( $asset ) . '.css';
	}

	public static function static_css_path( string $asset ): string {
		return self::component_css_path( $asset );
	}

	/**
	 * Refresh every Builder page's compiled bundle once per plugin version.
	 *
	 * A compiled bundle is only used when its manifest matches both the plugin
	 * version and the component source signature, and public requests never
	 * compile. So until this has run, every Builder page -- the homepage
	 * included -- falls back to assets/components/all-components.min.css: one
	 * 177 KB render-blocking stylesheet in place of the handful of component
	 * sheets the page actually uses.
	 *
	 * Two things used to make that fallback permanent rather than temporary.
	 * The version stamp was written before the work, and a single Throwable
	 * from any one page aborted the whole loop -- so every page after the
	 * failing one stayed uncompiled, with the stamp already claiming the
	 * version was done and nothing to retry it.
	 *
	 * Now each page is compiled in isolation, the stamp is only written when
	 * the whole set has been attempted, and a failed pass is retried on a later
	 * admin visit. A short-lived lock keeps the retry from running on every
	 * request while one pass is already in flight.
	 */
	public static function maybe_recompile_for_version(): void {
		if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( DELICAT_BUILDER_V9_VERSION === (string) get_option( 'delicat_builder_v9_compiled_stamp', '' ) ) {
			return;
		}
		/* One pass at a time. Without this a slow or fatal pass would be
		 * restarted by every concurrent admin request. */
		if ( get_transient( 'delicat_builder_v9_compiling' ) ) {
			return;
		}
		set_transient( 'delicat_builder_v9_compiling', 1, 5 * MINUTE_IN_SECONDS );

		$failed = 0;
		$changed = 0;
		try {
			$ids = get_posts(
				array(
					'post_type'      => 'page',
					'post_status'    => array( 'publish', 'draft', 'private' ),
					'posts_per_page' => 250,
					'fields'         => 'ids',
					'meta_key'       => Delicat_Builder_V9_Pages::META_ENABLED,
					'meta_value'     => '1',
					'no_found_rows'  => true,
				)
			);
			foreach ( (array) $ids as $page_id ) {
				/* Per page, so one unparseable layout cannot cost every other
				 * page its bundle. */
				try {
					$layout = Delicat_Builder_V9_Pages::get_layout( (int) $page_id );
					if ( ! empty( $layout ) ) {
						$current = self::get_manifest( (int) $page_id );
						if ( self::manifest_is_current( (int) $page_id, $layout, $current ) && 'compiled' === ( $current['storage'] ?? '' ) && ! empty( self::compiled_asset( (int) $page_id, $current ) ) ) { continue; }
						$built = self::compile_page( (int) $page_id, $layout );
						$settings = Delicat_Builder_V9_Core::settings();
						if ( 'compiled' === ( $built['storage'] ?? '' ) ) { ++$changed; }
						if ( ! empty( $settings['compiled_assets'] ) && ! empty( $built['css'] ) && 'compiled' !== ( $built['storage'] ?? '' ) ) { ++$failed; }
					}
				} catch ( Throwable $page_error ) {
					++$failed;
					unset( $page_error );
				}
			}
			if ( $changed > 0 ) {
				if ( class_exists( 'Delicat_Builder_V9_Cache', false ) && is_callable( array( 'Delicat_Builder_V9_Cache', 'bump_version' ) ) ) {
					Delicat_Builder_V9_Cache::bump_version();
				}
				do_action( 'litespeed_purge_all' );
			}

			/* Stamp only successful builds. Failed storage writes are retried after
			 * a cooldown; already-current compiled pages are skipped on retries. */
			if ( 0 === $failed ) {
				update_option( 'delicat_builder_v9_compiled_stamp', DELICAT_BUILDER_V9_VERSION, true );
			}
			if ( $failed > 0 ) {
				update_option(
					'delicat_builder_v9_compiled_failures',
					array(
						'version' => DELICAT_BUILDER_V9_VERSION,
						'time'    => gmdate( 'c' ),
						'pages'   => (int) $failed,
					),
					false
				);
			} else {
				delete_option( 'delicat_builder_v9_compiled_failures' );
			}
		} catch ( Throwable $recompile_error ) {
			/* The pass itself failed -- the page query, the cache bump. The
			 * stamp is deliberately not written, so the next admin visit after
			 * the lock expires tries again instead of leaving every Builder page
			 * on the 177 KB fallback for good. */
			++$failed;
			unset( $recompile_error );
		} finally {
			if ( $failed > 0 ) {
				set_transient( 'delicat_builder_v9_compiling', 1, 15 * MINUTE_IN_SECONDS );
			} else {
				delete_transient( 'delicat_builder_v9_compiling' );
			}
		}
	}

	public static function handle_compile_all(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot compile Delicat Builder pages.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_compile_all' );

		$ids = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 250,
				'fields'         => 'ids',
				'meta_key'       => Delicat_Builder_V9_Pages::META_ENABLED,
				'meta_value'     => '1',
				'no_found_rows'  => true,
			)
		);

		$count = 0;
		foreach ( $ids as $page_id ) {
			$layout = Delicat_Builder_V9_Pages::get_layout( (int) $page_id );
			if ( ! empty( $layout ) ) {
				$built = self::compile_page( (int) $page_id, $layout );
						$settings = Delicat_Builder_V9_Core::settings();
						if ( 'compiled' === ( $built['storage'] ?? '' ) ) { ++$changed; }
						if ( ! empty( $settings['compiled_assets'] ) && ! empty( $built['css'] ) && 'compiled' !== ( $built['storage'] ?? '' ) ) { ++$failed; }
				$count++;
			}
		}

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_pages_recompiled',
				'notice',
				array( 'pages' => $count )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'     => 'delicat-builder-v9',
					'compiled' => $count,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot clean compiled assets.', 'delicat-builder-v9' ), 403 );
		}
		check_admin_referer( 'delicat_builder_v9_cleanup_compiled' );

		$info = self::upload_info();
		$removed = 0;
		if ( ! empty( $info ) && is_dir( $info['dir'] ) ) {
			$files = glob( trailingslashit( $info['dir'] ) . 'page-*-*.css' );
			if ( is_array( $files ) ) {
				foreach ( $files as $file ) {
					$name = basename( $file );
					if ( 1 === preg_match( '/^page-\d+-[a-f0-9]{16}\.css$/', $name ) && @unlink( $file ) ) {
						$removed++;
					}
				}
			}
		}

		if ( class_exists( 'Delicat_Builder_V9_Identity_Bridge', false ) && is_callable( array( 'Delicat_Builder_V9_Identity_Bridge', 'audit' ) ) ) {
			Delicat_Builder_V9_Identity_Bridge::audit(
				'builder_compiled_css_cleaned',
				'notice',
				array( 'files' => $removed )
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'delicat-builder-v9',
					'cleaned' => $removed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
