<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 9.4.2 — « Vitesse réelle »: real-user speed measurement and upload formats.
 *
 * 1. Real-user measurement (RUM). Google's field data (CrUX) does not see the Flutter app
 *    (Android WebView), Facebook / TikTok in-app browsers, Samsung Internet or iOS, nor the
 *    engine's in-page navigations, which is most of this store's traffic. A sampled share of
 *    page views (10 % by default) loads a 3 KB script once the page has loaded. It reads the
 *    browser's own performance entries (LCP, FCP, TTFB, CLS, INP and the engine's navigation
 *    time) and sends one small beacon when the page is hidden. The server keeps daily
 *    histograms per segment only: no IP address, no user, no URL, no cookie. Builder ->
 *    Vitesse réelle shows the 75th percentile against the Core Web Vitals thresholds.
 * 2. WebP uploads. New JPEG uploads get WebP sub-sizes when the server's image library can
 *    write WebP and no other optimizer already converts images (one generator only, so
 *    images are never converted twice). Existing images are left as they are.
 *
 * Writes are bounded: per-IP throttle (hashed, 10 minutes), a daily cap, fixed-size
 * histograms, 14 days kept. The HTML stays identical for every visitor (sampling happens in
 * the browser), so page caches are unaffected.
 */
final class Delicat_Builder_V9_Speed {

	public const OPTION = 'delicat_builder_v9_speed';
	private const DAY_PREFIX  = 'delicat_builder_v9_rum_';
	private const DAYS_OPTION = 'delicat_builder_v9_rum_days';
	private const KEEP_DAYS   = 14;
	private const DAILY_CAP   = 20000;

	/** Upper edges of the histogram buckets (the last bucket is open). */
	private const EDGES_MS  = array( 100, 200, 300, 400, 500, 600, 800, 1000, 1200, 1500, 1800, 2000, 2500, 3000, 3500, 4000, 5000, 6000, 8000, 10000, 15000, 20000, 30000 );
	private const EDGES_CLS = array( 0.01, 0.025, 0.05, 0.075, 0.1, 0.15, 0.2, 0.25, 0.35, 0.5, 0.75, 1, 2 );

	private const SEGMENTS = array( 'all', 'app', 'web', 'social', 'low' );

	/** @var array<string,mixed>|null */
	private static $settings = null;
	private static $booted   = false;

	public static function defaults(): array {
		return array(
			'rum'          => 1,
			'rum_sample'   => 10, /* 9.4.3: 10 % is plenty at this traffic and halves the beacon writes */
			'webp_uploads' => 'auto',
		);
	}

	public static function settings(): array {
		if ( null === self::$settings ) {
			$saved          = get_option( self::OPTION, array() );
			self::$settings = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$settings;
	}

	/** Metric => label, unit, good, poor (Core Web Vitals thresholds; « nav » is the engine's own). */
	public static function metrics(): array {
		return array(
			'lcp'  => array( 'Affichage principal (LCP)', 'ms', 2500, 4000 ),
			'inp'  => array( 'Réactivité au toucher (INP)', 'ms', 200, 500 ),
			'cls'  => array( 'Stabilité visuelle (CLS)', '', 0.1, 0.25 ),
			'fcp'  => array( 'Premier affichage (FCP)', 'ms', 1800, 3000 ),
			'ttfb' => array( 'Réponse du serveur (TTFB)', 'ms', 800, 1800 ),
			'nav'  => array( 'Navigation interne (moteur)', 'ms', 1000, 2500 ),
		);
	}

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		/* The upload filter and the beacon route are registered by the bootstrap (lazy hooks
		 * that parse this file when they fire); here: the admin page and the storefront loader. */
		if ( is_admin() ) {
			add_action( 'admin_menu', array( __CLASS__, 'menu' ), 24 );
			add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		} else {
			add_action( 'wp_footer', array( __CLASS__, 'print_loader' ), 99 );
		}
	}

	/* ------------------------------------------------------------ WebP uploads */

	/**
	 * @param array<string,string>|mixed $formats Input mime => output mime.
	 * @return array<string,string>|mixed
	 */
	public static function output_format( $formats ) {
		if ( ! self::webp_active() ) {
			return $formats;
		}
		$formats = is_array( $formats ) ? $formats : array();
		if ( ! isset( $formats['image/jpeg'] ) ) {
			$formats['image/jpeg'] = 'image/webp';
		}
		return $formats;
	}

	public static function webp_active(): bool {
		static $on = null;
		if ( null !== $on ) {
			return $on;
		}
		$mode = (string) self::settings()['webp_uploads'];
		if ( 'off' === $mode || ! function_exists( 'wp_image_editor_supports' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
			return $on = false;
		}
		return $on = ( 'on' === $mode || '' === self::other_optimizer() );
	}

	/** Name of another plugin that already makes WebP or AVIF images, '' when none. */
	public static function other_optimizer(): string {
		$known = array(
			'LiteSpeed Cache (WebP)' => (bool) get_option( 'litespeed.conf.img_optm-webp' ),
			'Imagify'                => defined( 'IMAGIFY_VERSION' ),
			'ShortPixel'             => defined( 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ),
			'EWWW Image Optimizer'   => defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
			'Optimole'               => defined( 'OPTML_VERSION' ),
			'Converter for Media'    => defined( 'WEBPC_VERSION' ),
			'Modern Image Formats'   => defined( 'WEBP_UPLOADS_VERSION' ),
			'Smush'                  => defined( 'WP_SMUSH_VERSION' ),
			'WebP Express'           => defined( 'WEBPEXPRESS_PLUGIN' ),
		);
		foreach ( $known as $name => $active ) {
			if ( $active ) {
				return $name;
			}
		}
		return '';
	}

	/* --------------------------------------------------------------- RUM loader */

	public static function print_loader(): void {
		$s = self::settings();
		$sample = max( 0, min( 100, (int) $s['rum_sample'] ) );
		if ( empty( $s['rum'] ) || $sample <= 0 || is_feed() || is_embed() || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
			return;
		}
		$src = DELICAT_BUILDER_V9_URL . 'assets/js/rum.js?ver=' . rawurlencode( DELICAT_BUILDER_V9_VERSION );
		$ep  = rest_url( 'delicat-builder-v9/v1/rum' );
		/* Sampled in the browser, after load and a pause, so the page's own work comes first;
		 * the script reads the entries the browser buffered meanwhile. Runs once per document
		 * (a soft navigation that replays inline scripts finds the flag). */
		printf(
			'<script id="delicat-builder-v9-rum-loader">(function(w,d){try{if(w.__dlcRumL||!w.PerformanceObserver||!navigator.sendBeacon||Math.random()*100>=%1$d)return;w.__dlcRumL=1;var go=function(){setTimeout(function(){var s=d.createElement("script");s.src=%2$s;s.async=true;s.setAttribute("data-endpoint",%3$s);d.head.appendChild(s)},1500)};if(d.readyState==="complete")go();else w.addEventListener("load",go,{once:true})}catch(e){}})(window,document);</script>' . "\n",
			(int) $sample,
			wp_json_encode( esc_url_raw( $src ) ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded URL.
			wp_json_encode( esc_url_raw( $ep ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded URL.
		);
	}

	/* ---------------------------------------------------------------- REST beacon */

	/**
	 * @param WP_REST_Request $request Beacon: {k, lo, f, lcp, fcp, ttfb, cls, inp, nav[]}.
	 */
	public static function record( $request ) {
		$done = new WP_REST_Response( null, 204 );
		$done->header( 'Cache-Control', 'no-store, private' );
		$s = self::settings();
		if ( empty( $s['rum'] ) ) {
			return $done;
		}
		$body = (string) $request->get_body();
		if ( '' === $body || strlen( $body ) > 2048 ) {
			return $done;
		}
		$data = json_decode( $body, true );
		/* 9.4.3: junk bodies are dropped before they cost a throttle write. */
		if ( ! is_array( $data ) || self::throttled() ) {
			return $done;
		}
		$kind     = in_array( $data['k'] ?? '', array( 'app', 'web', 'social' ), true ) ? (string) $data['k'] : 'web';
		$segments = array( 'all', $kind );
		if ( ! empty( $data['lo'] ) ) {
			$segments[] = 'low';
		}
		$values = array();
		if ( ! empty( $data['f'] ) ) {
			foreach ( array( 'lcp', 'fcp', 'ttfb', 'inp' ) as $k ) {
				if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
					$v = (float) $data[ $k ];
					if ( $v > 0 && $v < 120000 ) {
						$values[ $k ][] = $v;
					}
				}
			}
			if ( isset( $data['cls'] ) && is_numeric( $data['cls'] ) ) {
				$v = (float) $data['cls'];
				if ( $v >= 0 && $v < 50 ) {
					$values['cls'][] = $v;
				}
			}
		}
		if ( isset( $data['nav'] ) && is_array( $data['nav'] ) ) {
			foreach ( array_slice( $data['nav'], 0, 20 ) as $v ) {
				if ( is_numeric( $v ) && (float) $v > 0 && (float) $v < 60000 ) {
					$values['nav'][] = (float) $v;
				}
			}
		}
		if ( $values ) {
			self::add( $values, $segments );
		}
		return $done;
	}

	/** At most 40 beacons per address per 10 minutes (the address is hashed, never stored). */
	private static function throttled(): bool {
		/* 9.4.3: the visitor's address as Security resolves it (Cloudflare-aware). */
		$ip = ( class_exists( 'Delicat_Builder_V9_Security', false ) && is_callable( array( 'Delicat_Builder_V9_Security', 'client_ip' ) ) )
			? Delicat_Builder_V9_Security::client_ip()
			: ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
		$key = 'dbv9_rum_' . substr( hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) ), 0, 16 );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_add( $key, 0, 'delicat_rum', 10 * MINUTE_IN_SECONDS );
			return (int) wp_cache_incr( $key, 1, 'delicat_rum' ) > 40;
		}
		$n = (int) get_transient( $key );
		if ( $n >= 40 ) {
			return true;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	private static function bucket( string $metric, float $v ): int {
		$edges = 'cls' === $metric ? self::EDGES_CLS : self::EDGES_MS;
		foreach ( $edges as $i => $edge ) {
			if ( $v <= $edge ) {
				return $i;
			}
		}
		return count( $edges );
	}

	/**
	 * @param array<string,array<int,float>> $values   Metric => values.
	 * @param array<int,string>              $segments Segments this beacon counts in.
	 */
	private static function add( array $values, array $segments ): void {
		$day  = gmdate( 'Ymd' );
		$key  = self::DAY_PREFIX . $day;
		$data = get_option( $key, array() );
		$data = is_array( $data ) ? $data : array();
		$data['n'] = (int) ( $data['n'] ?? 0 ) + 1;
		if ( $data['n'] > self::DAILY_CAP ) {
			return;
		}
		foreach ( $values as $metric => $list ) {
			foreach ( $list as $v ) {
				$b = self::bucket( $metric, $v );
				foreach ( $segments as $seg ) {
					$data['h'][ $metric ][ $seg ][ $b ] = (int) ( $data['h'][ $metric ][ $seg ][ $b ] ?? 0 ) + 1;
				}
			}
		}
		update_option( $key, $data, false );
		self::index( $day );
	}

	/** Remembers which days exist and drops the ones older than two weeks. */
	private static function index( string $day ): void {
		$days = get_option( self::DAYS_OPTION, array() );
		$days = is_array( $days ) ? array_values( array_filter( $days, 'is_string' ) ) : array();
		if ( in_array( $day, $days, true ) ) {
			return;
		}
		$days[] = $day;
		sort( $days );
		while ( count( $days ) > self::KEEP_DAYS ) {
			delete_option( self::DAY_PREFIX . array_shift( $days ) );
		}
		update_option( self::DAYS_OPTION, $days, false );
	}

	/**
	 * p75 and sample count per metric and segment over the last $days days.
	 *
	 * @return array<string,array<string,array{p75:float,n:int}>>
	 */
	public static function summary( int $days ): array {
		$merged = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$data = get_option( self::DAY_PREFIX . gmdate( 'Ymd', time() - $i * DAY_IN_SECONDS ), array() );
			if ( ! is_array( $data ) || empty( $data['h'] ) || ! is_array( $data['h'] ) ) {
				continue;
			}
			foreach ( $data['h'] as $metric => $segs ) {
				foreach ( (array) $segs as $seg => $buckets ) {
					foreach ( (array) $buckets as $b => $n ) {
						$merged[ $metric ][ $seg ][ (int) $b ] = (int) ( $merged[ $metric ][ $seg ][ (int) $b ] ?? 0 ) + (int) $n;
					}
				}
			}
		}
		$out = array();
		foreach ( $merged as $metric => $segs ) {
			$edges = 'cls' === $metric ? self::EDGES_CLS : self::EDGES_MS;
			foreach ( $segs as $seg => $buckets ) {
				$total = array_sum( $buckets );
				if ( $total <= 0 ) {
					continue;
				}
				ksort( $buckets );
				$target = 0.75 * $total;
				$cum    = 0;
				$p75    = 0.0;
				foreach ( $buckets as $b => $n ) {
					if ( $cum + $n >= $target ) {
						$lo   = $b > 0 ? (float) $edges[ $b - 1 ] : 0.0;
						$hi   = isset( $edges[ $b ] ) ? (float) $edges[ $b ] : (float) end( $edges ) * 1.5;
						$p75  = $lo + ( $hi - $lo ) * ( $n > 0 ? ( $target - $cum ) / $n : 1 );
						break;
					}
					$cum += $n;
				}
				$out[ $metric ][ $seg ] = array(
					'p75' => $p75,
					'n'   => (int) $total,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------- admin */

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Vitesse réelle', 'delicat-builder-v9' ),
			__( 'Vitesse réelle', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-speed',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_speed',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Posted settings.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		return array(
			'rum'          => empty( $input['rum'] ) ? 0 : 1,
			'rum_sample'   => max( 1, min( 100, absint( $input['rum_sample'] ?? 10 ) ) ),
			'webp_uploads' => in_array( $input['webp_uploads'] ?? 'auto', array( 'auto', 'on', 'off' ), true ) ? (string) $input['webp_uploads'] : 'auto',
		);
	}

	/** Server checks shown under the numbers: what the plugin can see from here. */
	private static function checks(): array {
		$out = array();
		$out[] = array(
			wp_using_ext_object_cache(),
			__( 'Cache objet persistant (Redis / Memcached)', 'delicat-builder-v9' ),
			wp_using_ext_object_cache() ? __( 'actif : les pages non mises en cache répondent plus vite.', 'delicat-builder-v9' ) : __( 'absent : les clients connectés (portefeuille, compte) attendent chaque requête à la base. Activez Redis ou Memcached chez l’hébergeur.', 'delicat-builder-v9' ),
		);
		$lscwp = defined( 'LSCWP_V' );
		$priv  = $lscwp ? (bool) get_option( 'litespeed.conf.cache-priv' ) : false;
		$out[] = array(
			$lscwp && $priv,
			__( 'Cache privé LiteSpeed pour les clients connectés', 'delicat-builder-v9' ),
			! $lscwp ? __( 'LiteSpeed Cache n’est pas actif.', 'delicat-builder-v9' ) : ( $priv ? __( 'actif.', 'delicat-builder-v9' ) : __( 'désactivé : les clients connectés (vos meilleurs clients) ne profitent d’aucun cache. LiteSpeed Cache → Cache → « Cache Logged-in Users ».', 'delicat-builder-v9' ) ),
		);
		$opc = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$st  = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restricted APIs warn.
			$opc = is_array( $st ) && ! empty( $st['opcache_enabled'] );
		}
		$out[] = array( $opc, __( 'OPcache PHP', 'delicat-builder-v9' ), $opc ? __( 'actif.', 'delicat-builder-v9' ) : __( 'inactif ou non visible : demandez-le à l’hébergeur.', 'delicat-builder-v9' ) );
		$webp_ok = function_exists( 'wp_image_editor_supports' ) && wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		$other   = self::other_optimizer();
		$out[] = array(
			$webp_ok || '' !== $other,
			__( 'Images WebP', 'delicat-builder-v9' ),
			'' !== $other ? sprintf( /* translators: %s: plugin name */ __( 'faites par %s (Delicat ne convertit pas une seconde fois).', 'delicat-builder-v9' ), $other ) : ( self::webp_active() ? __( 'les nouvelles photos JPEG reçoivent des tailles WebP.', 'delicat-builder-v9' ) : ( $webp_ok ? __( 'possible, désactivé ici.', 'delicat-builder-v9' ) : __( 'la bibliothèque d’images du serveur n’écrit pas le WebP.', 'delicat-builder-v9' ) ) ),
		);
		$out[] = array(
			version_compare( PHP_VERSION, '8.1', '>=' ),
			__( 'Version de PHP', 'delicat-builder-v9' ),
			PHP_VERSION,
		);
		return $out;
	}

	private static function fmt( string $metric, float $v ): string {
		if ( 'cls' === $metric ) {
			return number_format_i18n( $v, 2 );
		}
		return $v >= 1000 ? number_format_i18n( $v / 1000, 1 ) . ' s' : number_format_i18n( round( $v / 10 ) * 10 ) . ' ms';
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$days  = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 7; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		$days  = in_array( $days, array( 1, 7, 14 ), true ) ? $days : 7;
		$sum   = self::summary( $days );
		$segs  = array(
			'all'    => __( 'Tous', 'delicat-builder-v9' ),
			'app'    => __( 'Application', 'delicat-builder-v9' ),
			'web'    => __( 'Navigateur', 'delicat-builder-v9' ),
			'social' => __( 'Facebook / TikTok', 'delicat-builder-v9' ),
			'low'    => __( 'Téléphones modestes', 'delicat-builder-v9' ),
		);
		$base  = admin_url( 'admin.php?page=delicat-builder-v9-speed' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Vitesse réelle', 'delicat-builder-v9' ); ?></h1>
			<p><?php esc_html_e( 'Mesures prises sur les téléphones de vos clients (une partie des visites, sans donnée personnelle). Chiffre affiché : le 75e percentile, la valeur que 3 visites sur 4 atteignent ou battent. Vert = bon, orange = à améliorer, rouge = lent.', 'delicat-builder-v9' ); ?></p>
			<p>
				<?php foreach ( array( 1 => __( '24 h', 'delicat-builder-v9' ), 7 => __( '7 jours', 'delicat-builder-v9' ), 14 => __( '14 jours', 'delicat-builder-v9' ) ) as $d => $label ) : ?>
					<a class="button<?php echo $d === $days ? ' button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d, $base ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</p>
			<?php if ( ! $sum ) : ?>
				<div class="notice notice-info inline"><p><?php echo empty( $s['rum'] ) ? esc_html__( 'La mesure est désactivée.', 'delicat-builder-v9' ) : esc_html__( 'Pas encore de mesure sur cette période : les premières arrivent après quelques visites.', 'delicat-builder-v9' ); ?></p></div>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th><?php esc_html_e( 'Mesure', 'delicat-builder-v9' ); ?></th>
						<?php foreach ( $segs as $label ) : ?><th><?php echo esc_html( $label ); ?></th><?php endforeach; ?>
					</tr></thead>
					<tbody>
					<?php foreach ( self::metrics() as $m => $def ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( $def[0] ); ?><br><small><?php echo esc_html( sprintf( /* translators: 1: good threshold 2: poor threshold */ __( 'bon ≤ %1$s · lent > %2$s', 'delicat-builder-v9' ), self::fmt( $m, (float) $def[2] ), self::fmt( $m, (float) $def[3] ) ) ); ?></small></th>
							<?php foreach ( array_keys( $segs ) as $seg ) : ?>
								<td>
									<?php
									if ( empty( $sum[ $m ][ $seg ] ) ) {
										echo '<span style="color:#8c8f94">—</span>';
									} else {
										$p     = (float) $sum[ $m ][ $seg ]['p75'];
										$color = $p <= $def[2] ? '#067647' : ( $p <= $def[3] ? '#b54708' : '#b42318' );
										printf(
											'<strong style="color:%1$s">%2$s</strong><br><small>%3$s</small>',
											esc_attr( $color ),
											esc_html( self::fmt( $m, $p ) ),
											esc_html( sprintf( /* translators: %s: number of measurements */ _n( '%s mesure', '%s mesures', (int) $sum[ $m ][ $seg ]['n'], 'delicat-builder-v9' ), number_format_i18n( (int) $sum[ $m ][ $seg ]['n'] ) ) )
										);
									}
									?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( '« Navigation interne » : temps entre le toucher d’un lien et l’affichage de la page suivante par le moteur Delicat (Google ne le mesure pas).', 'delicat-builder-v9' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Serveur', 'delicat-builder-v9' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px"><tbody>
			<?php foreach ( self::checks() as $c ) : ?>
				<tr><td style="width:24px"><span class="dashicons <?php echo $c[0] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" style="color:<?php echo $c[0] ? '#067647' : '#b54708'; ?>"></span></td><th scope="row" style="width:340px"><?php echo esc_html( $c[1] ); ?></th><td><?php echo esc_html( $c[2] ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>

			<h2><?php esc_html_e( 'Réglages', 'delicat-builder-v9' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'delicat_builder_v9_speed' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Mesure sur les téléphones des clients', 'delicat-builder-v9' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[rum]" value="1" <?php checked( ! empty( $s['rum'] ) ); ?>> <?php esc_html_e( 'Activée', 'delicat-builder-v9' ); ?></label></td></tr>
					<tr><th scope="row"><label for="dbv9-rum-sample"><?php esc_html_e( 'Part des visites mesurées (%)', 'delicat-builder-v9' ); ?></label></th>
						<td><input id="dbv9-rum-sample" type="number" min="1" max="100" name="<?php echo esc_attr( self::OPTION ); ?>[rum_sample]" value="<?php echo esc_attr( (string) (int) $s['rum_sample'] ); ?>" class="small-text"> <p class="description"><?php esc_html_e( '10 % suffit pour une boutique de cette taille ; chaque mesure est une petite requête après le départ du client.', 'delicat-builder-v9' ); ?></p></td></tr>
					<tr><th scope="row"><label for="dbv9-webp"><?php esc_html_e( 'Tailles WebP des nouvelles photos', 'delicat-builder-v9' ); ?></label></th>
						<td><select id="dbv9-webp" name="<?php echo esc_attr( self::OPTION ); ?>[webp_uploads]">
							<option value="auto" <?php selected( $s['webp_uploads'], 'auto' ); ?>><?php esc_html_e( 'Automatique (sauf si une autre extension s’en charge)', 'delicat-builder-v9' ); ?></option>
							<option value="on" <?php selected( $s['webp_uploads'], 'on' ); ?>><?php esc_html_e( 'Toujours', 'delicat-builder-v9' ); ?></option>
							<option value="off" <?php selected( $s['webp_uploads'], 'off' ); ?>><?php esc_html_e( 'Jamais', 'delicat-builder-v9' ); ?></option>
						</select></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
