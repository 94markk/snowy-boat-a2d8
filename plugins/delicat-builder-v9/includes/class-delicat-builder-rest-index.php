<?php
/**
 * 9.13.0 (audit 2026-11-12, L-7): the public /wp-json/ index listed 50+ plugin namespaces and every route
 * to anyone, and building it is heavy (every route's schema) on a request that is never page-cached.
 *
 * For signed-out requests (no authenticated user - REST cookie auth also needs its nonce) the index answers
 * only name / description / url / home with empty namespaces / routes / authentication, and a namespace index
 * (/wp-json/<namespace>) answers its namespace with empty routes. Both are answered BEFORE WordPress builds
 * the route map. The routes themselves keep working exactly as before: the index is discovery only (the
 * Delicat Android app calls fixed paths). Signed-in requests (application passwords, OAuth, cookie + nonce)
 * still get the full index.
 *
 * Switch: Builder › Security Studio › « Masquer l'index de l'API aux visiteurs » (option
 * delicat_builder_v9_hide_rest_index, ON when absent). Filter « delicat_builder_v9_hide_rest_index ».
 *
 * @package Delicat_Builder_V9
 */

defined( 'ABSPATH' ) || exit;

final class Delicat_Builder_V9_Rest_Index {

	public const OPTION = 'delicat_builder_v9_hide_rest_index';

	public static function boot(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'pre_dispatch' ), 5, 3 );
		add_filter( 'rest_index', array( __CLASS__, 'filter_index' ), 1000 );
		/* 9.14.0 (audit X-2): the same backstop for a namespace index built by another path */
		add_filter( 'rest_namespace_index', array( __CLASS__, 'filter_namespace_index' ), 1000, 2 );
		add_action( 'admin_post_delicat_builder_v9_rest_index_save', array( __CLASS__, 'save' ) );
	}

	public static function enabled(): bool {
		/* not autoloaded on purpose: read only on REST discovery requests and the admin page */
		$on = '0' !== (string) get_option( self::OPTION, '1' );
		return (bool) apply_filters( 'delicat_builder_v9_hide_rest_index', $on );
	}

	private static function hide_for_request(): bool {
		return ! is_user_logged_in() && self::enabled();
	}

	private static function minimal_index(): array {
		return array(
			'name'            => (string) get_option( 'blogname' ),
			'description'     => '',
			'url'             => get_option( 'siteurl' ),
			'home'            => home_url(),
			'gmt_offset'      => get_option( 'gmt_offset' ),
			'timezone_string' => get_option( 'timezone_string' ),
			'namespaces'      => array(),
			'authentication'  => array(),
			'routes'          => array(),
		);
	}

	/**
	 * Answer the index and the namespace indexes for guests without building the route map.
	 *
	 * @param mixed           $result  Response so far (null = not handled yet).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function pre_dispatch( $result, $server, $request ) {
		if ( null !== $result || ! ( $request instanceof WP_REST_Request ) || 'GET' !== strtoupper( (string) $request->get_method() ) ) {
			return $result;
		}
		$route     = '/' . trim( (string) $request->get_route(), '/' );
		$namespace = '';
		if ( '/' !== $route ) {
			/* 9.14.0 (audit X-2): WordPress matches routes ignoring case ('@^…$@i'), so /wp-json/WC/V3 and
			 * ?rest_route=/Wp/V2 reach the namespace index too: compare in lower case. The namespace answered is the
			 * one the route names, never a ?namespace= parameter. */
			$namespace = self::namespace_of( $route, $server );
			if ( '' === $namespace ) {
				return $result;
			}
		}
		if ( ! self::hide_for_request() ) {
			return $result;
		}
		$data = '/' === $route ? self::minimal_index() : array( 'namespace' => $namespace, 'routes' => array() );
		$response = new WP_REST_Response( $data, 200 );
		return $response;
	}

	/** The registered namespace a route is exactly (any letter case), or ''. */
	private static function namespace_of( string $route, $server ): string {
		if ( ! is_object( $server ) || ! is_callable( array( $server, 'get_namespaces' ) ) ) {
			return '';
		}
		$asked = strtolower( trim( $route, '/' ) );
		if ( '' === $asked ) {
			return '';
		}
		foreach ( (array) $server->get_namespaces() as $known ) {
			if ( strtolower( (string) $known ) === $asked ) {
				return (string) $known;
			}
		}
		return '';
	}

	/**
	 * 9.14.0 (audit X-2): belt and braces for a namespace index (/wp-json/<namespace>) built by another path: for
	 * guests its routes are emptied. The namespace comes from the route, not from a ?namespace= parameter.
	 *
	 * @param WP_REST_Response $response Namespace index response.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function filter_namespace_index( $response, $request = null ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! self::hide_for_request() ) {
			return $response;
		}
		$data      = $response->get_data();
		$namespace = $request instanceof WP_REST_Request
			? strtolower( trim( (string) $request->get_route(), '/' ) )
			: strtolower( (string) ( is_array( $data ) ? ( $data['namespace'] ?? '' ) : '' ) );
		$response->set_data( array( 'namespace' => $namespace, 'routes' => array() ) );
		return $response;
	}

	/**
	 * Belt and braces: an index built by another path is reduced the same way for guests.
	 *
	 * @param WP_REST_Response $response Index response.
	 * @return WP_REST_Response
	 */
	public static function filter_index( $response ) {
		if ( ! ( $response instanceof WP_REST_Response ) || ! self::hide_for_request() ) {
			return $response;
		}
		$response->set_data( self::minimal_index() );
		return $response;
	}

	public static function save(): void {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			wp_die( esc_html__( 'Method not allowed.', 'delicat-builder-v9' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'delicat-builder-v9' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'delicat_builder_v9_rest_index_save' );
		$on = ! empty( $_POST['hide_rest_index'] );
		update_option( self::OPTION, $on ? '1' : '0', false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-v9-security', 'rest-index' => $on ? 'on' : 'off' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** The card on Builder › Security Studio. */
	public static function admin_card(): void {
		$on = self::enabled();
		?>
		<section class="delicat-admin__card" style="margin-top:20px" id="delicat-rest-index">
			<h2>Index de l’API (wp-json)</h2>
			<?php if ( isset( $_GET['rest-index'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only ?>
				<div class="notice notice-success inline"><p><?php echo 'on' === $_GET['rest-index'] ? 'Index masqué aux visiteurs.' : 'Index visible par tous.'; // phpcs:ignore ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="delicat_builder_v9_rest_index_save">
				<?php wp_nonce_field( 'delicat_builder_v9_rest_index_save' ); ?>
				<label class="delicat-switch">
					<input type="checkbox" name="hide_rest_index" value="1" <?php checked( $on ); ?>>
					<span><strong>Masquer l’index de l’API aux visiteurs</strong><small>Les visiteurs non connectés ne voient plus la liste des extensions et des routes de /wp-json/. Le site, l’application Android et les paiements continuent de fonctionner (ils appellent des adresses fixes). Si l’application WooCommerce, Jetpack ou Site Kit n’arrive plus à trouver le site, décochez cette case.</small></span>
				</label>
				<?php submit_button( 'Enregistrer', 'secondary', 'submit', false ); ?>
			</form>
		</section>
		<?php
	}
}

Delicat_Builder_V9_Rest_Index::boot();
