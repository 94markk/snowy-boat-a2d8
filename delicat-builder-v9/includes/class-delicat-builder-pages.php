<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Pages {
	public const META_ENABLED   = '_delicat_builder_v9_enabled';
	public const META_LAYOUT    = '_delicat_builder_v9_layout';
	public const META_REVISIONS = '_delicat_builder_v9_revisions';
	public const MAX_REVISIONS  = 10;

	private static bool $rendering = false;
	private static bool $replaced_main_content = false;
	private static array $layout_cache = array();

	public static function boot(): void {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save_meta_box' ), 20, 3 );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 999 );
		/* RC63: Organization + WebSite JSON-LD on the front page. */
		add_action( 'wp_head', array( __CLASS__, 'organization_structured_data' ), 6 );
	}

	/**
	 * RC63: Organization and WebSite structured data on the front page — name,
	 * URL, logo and `sameAs` profiles (Google Business Profile, socials) via
	 * the `delicat_builder_v9_organization_same_as` filter or the
	 * `delicat_builder_v9_same_as` option (array of URLs). Deliberately no
	 * aggregateRating: reviews of a store printed by the store itself are
	 * "self-serving" in Google's review-snippet policy and would be ignored
	 * or penalised. Product stars come from the Product data on product
	 * pages (Native Product Engine); store stars come from Google Business
	 * Profile reviews.
	 */
	public static function organization_structured_data(): void {
		if ( is_admin() || wp_doing_ajax() || ! is_front_page() ) {
			return;
		}
		/* RC65: an SEO plugin that prints its own Organization graph wins —
		 * two Organization nodes for one site is a validation warning. */
		$seo_plugin = defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath', false ) || defined( 'AIOSEO_VERSION' ) || defined( 'SEOPRESS_VERSION' );
		if ( ! apply_filters( 'delicat_builder_v9_organization_structured_data', ! $seo_plugin ) ) {
			return;
		}
		$name = trim( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) );
		if ( '' === $name ) {
			return;
		}
		$home = home_url( '/' );
		$logo = '';
		$logo_id = absint( get_theme_mod( 'custom_logo' ) );
		if ( $logo_id <= 0 ) {
			$logo_id = absint( get_option( 'site_icon' ) );
		}
		if ( $logo_id > 0 ) {
			$logo = (string) wp_get_attachment_image_url( $logo_id, 'full' );
		}
		$same_as = get_option( 'delicat_builder_v9_same_as', array() );
		$same_as = is_array( $same_as ) ? $same_as : array();
		$same_as = apply_filters( 'delicat_builder_v9_organization_same_as', $same_as );
		$same_as = array_values( array_unique( array_filter( array_map( 'esc_url_raw', array_map( 'strval', (array) $same_as ) ) ) ) );

		$organization = array(
			'@type' => 'Organization',
			'@id'   => $home . '#organization',
			'name'  => $name,
			'url'   => $home,
		);
		if ( '' !== $logo ) {
			$organization['logo'] = $logo;
		}
		if ( ! empty( $same_as ) ) {
			$organization['sameAs'] = $same_as;
		}
		$website = array(
			'@type'     => 'WebSite',
			'@id'       => $home . '#website',
			'name'      => $name,
			'url'       => $home,
			'publisher' => array( '@id' => $home . '#organization' ),
		);
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array( $organization, $website ),
		);
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built from sanitised values.
	}

	public static function register_meta(): void {
		register_post_meta(
			'page',
			self::META_ENABLED,
			array(
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'auth_callback'     => static function () { return current_user_can( 'edit_pages' ); },
			)
		);
	}

	public static function meta_box(): void {
		add_meta_box(
			'delicat-builder-v9-page',
			__( 'Delicat Builder V9', 'delicat-builder-v9' ),
			array( __CLASS__, 'render_meta_box' ),
			'page',
			'side',
			'high'
		);
	}

	public static function render_meta_box( WP_Post $post ): void {
		wp_nonce_field( 'delicat_builder_v9_page_meta', 'delicat_builder_v9_page_nonce' );
		$enabled = self::is_enabled_for_page( $post->ID );
		$url = add_query_arg(
			array(
				'page'    => 'delicat-builder-v9-editor',
				'page_id' => $post->ID,
			),
			admin_url( 'admin.php' )
		);
		?>
		<p>
			<label>
				<input type="checkbox" name="delicat_builder_v9_page_enabled" value="1" <?php checked( $enabled ); ?>>
				<strong><?php esc_html_e( 'Use Delicat Builder output', 'delicat-builder-v9' ); ?></strong>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'Opt-in per page. If disabled, normal WordPress content renders unchanged.', 'delicat-builder-v9' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Open Visual Builder', 'delicat-builder-v9' ); ?></a></p>
		<?php
	}

	public static function save_meta_box( int $post_id, WP_Post $post, bool $update ): void {
		if ( ! isset( $_POST['delicat_builder_v9_page_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['delicat_builder_v9_page_nonce'] ) ), 'delicat_builder_v9_page_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = isset( $_POST['delicat_builder_v9_page_enabled'] ) ? 1 : 0;
		update_post_meta( $post_id, self::META_ENABLED, $enabled );
		if ( $enabled ) {
			$layout = self::get_layout( $post_id );
			if ( ! empty( $layout ) ) {
				Delicat_Builder_V9_Compiler::compile_page( $post_id, $layout );
			}
		}
		Delicat_Builder_V9_Cache::purge_page( $post_id );
	}

	public static function is_enabled_for_page( int $page_id ): bool {
		return (bool) get_post_meta( $page_id, self::META_ENABLED, true );
	}

	public static function get_layout( int $page_id ): array {
		if ( array_key_exists( $page_id, self::$layout_cache ) ) {
			return self::$layout_cache[ $page_id ];
		}

		$raw = get_post_meta( $page_id, self::META_LAYOUT, true );
		self::$layout_cache[ $page_id ] = Delicat_Builder_V9_Schema::sanitize_layout( is_array( $raw ) ? $raw : array() );
		return self::$layout_cache[ $page_id ];
	}

	public static function page_needs_carousel( int $page_id ): bool {
		return self::is_enabled_for_page( $page_id ) && Delicat_Builder_V9_Renderer::layout_needs_carousel( self::get_layout( $page_id ) );
	}

	public static function page_needs_product_data( int $page_id ): bool {
		if ( ! self::is_enabled_for_page( $page_id ) ) {
			return false;
		}
		$layout = self::get_layout( $page_id );
		if ( Delicat_Builder_V9_Renderer::layout_needs_carousel( $layout ) ) {
			return true;
		}
		foreach ( $layout as $section ) {
			if ( 'hero' === ( $section['type'] ?? '' ) && ! empty( $section['content']['search_enabled'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function save_layout( int $page_id, $raw_layout ) {
		if ( ! current_user_can( 'edit_post', $page_id ) || 'page' !== get_post_type( $page_id ) ) {
			return new WP_Error( 'forbidden', __( 'You cannot edit this page.', 'delicat-builder-v9' ) );
		}

		$layout   = Delicat_Builder_V9_Schema::sanitize_layout( $raw_layout );
		$previous = self::get_layout( $page_id );

		if ( wp_json_encode( $previous ) !== wp_json_encode( $layout ) ) {
			self::push_revision( $page_id, $previous );
			update_post_meta( $page_id, self::META_LAYOUT, $layout );
			self::$layout_cache[ $page_id ] = $layout;
			Delicat_Builder_V9_Cache::purge_page( $page_id );
			Delicat_Builder_V9_Compiler::compile_page( $page_id, $layout );
		}

		return $layout;
	}

	public static function revisions( int $page_id ): array {
		$items = get_post_meta( $page_id, self::META_REVISIONS, true );
		return is_array( $items ) ? array_slice( $items, 0, self::MAX_REVISIONS ) : array();
	}

	public static function restore_revision( int $page_id, string $revision_id ): bool {
		$revisions = self::revisions( $page_id );
		foreach ( $revisions as $revision ) {
			if ( hash_equals( (string) ( $revision['id'] ?? '' ), $revision_id ) ) {
				$current = self::get_layout( $page_id );
				self::push_revision( $page_id, $current );
				$restored = Delicat_Builder_V9_Schema::sanitize_layout( $revision['layout'] ?? array() );
				update_post_meta( $page_id, self::META_LAYOUT, $restored );
				self::$layout_cache[ $page_id ] = $restored;
				Delicat_Builder_V9_Cache::purge_page( $page_id );
				Delicat_Builder_V9_Compiler::compile_page( $page_id, $restored );
				return true;
			}
		}
		return false;
	}

	private static function push_revision( int $page_id, array $layout ): void {
		if ( empty( $layout ) ) {
			return;
		}

		$revisions = self::revisions( $page_id );
		array_unshift(
			$revisions,
			array(
				'id'      => wp_generate_uuid4(),
				'time'    => time(),
				'user_id' => get_current_user_id(),
				'layout'  => Delicat_Builder_V9_Schema::sanitize_layout( $layout ),
			)
		);

		update_post_meta( $page_id, self::META_REVISIONS, array_slice( $revisions, 0, self::MAX_REVISIONS ) );
	}

	public static function filter_content( $content ) {
		if (
			! is_string( $content )
			|| self::$rendering
			|| self::$replaced_main_content
			|| is_admin()
			|| ! Delicat_Builder_V9_Core::is_enabled()
			|| ! is_singular( 'page' )
		) {
			return $content;
		}

		$page_id = get_queried_object_id();
		if ( ! $page_id || ! self::is_enabled_for_page( $page_id ) ) {
			return $content;
		}

		global $post;
		if ( ! $post instanceof WP_Post || (int) $post->ID !== (int) $page_id ) {
			return $content;
		}

		$layout = self::get_layout( $page_id );
		if ( empty( $layout ) ) {
			return $content;
		}

		self::$rendering = true;
		$rendered = '';

		try {
			$rendered = Delicat_Builder_V9_Renderer::render_layout( $layout, true );
		} catch ( Throwable $error ) {
			if ( class_exists( 'Delicat_Builder_V9_Renderer' ) && method_exists( 'Delicat_Builder_V9_Renderer', 'runtime_failure' ) ) {
				Delicat_Builder_V9_Renderer::runtime_failure(
					'page_render',
					$error,
					array( 'page_id' => $page_id )
				);
			}
			$rendered = '';
		} finally {
			self::$rendering = false;
		}

		if ( '' === $rendered ) {
			// Emergency rollback path: return the untouched WordPress content
			// instead of letting a Builder runtime exception become a site-wide
			// WordPress critical-error screen.
			return $content;
		}

		self::$replaced_main_content = true;
		return $rendered;
	}
}
