<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Delicat_Builder_V9_Motion_Admin {
	public const OPTION = 'delicat_builder_v9_motion';

	public static function boot(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 22 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'delicat-builder-v9',
			__( 'Animations & Motion', 'delicat-builder-v9' ),
			__( 'Animations & Motion', 'delicat-builder-v9' ),
			'manage_options',
			'delicat-builder-v9-motion',
			array( __CLASS__, 'page' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'delicat_builder_v9_motion_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Delicat_Builder_V9_Core::motion_defaults(),
			)
		);
	}

	public static function sanitize( $input ): array {
		$old = Delicat_Builder_V9_Core::motion_settings();
		$clean = Delicat_Builder_V9_Core::sanitize_motion_settings( $input );
		Delicat_Builder_V9_Core::reset_motion_settings_cache();
		if ( wp_json_encode( $old ) !== wp_json_encode( $clean ) && class_exists( 'Delicat_Builder_V9_Cache', false ) ) {
			Delicat_Builder_V9_Cache::bump_version();
		}
		return $clean;
	}

	public static function assets( string $hook ): void {
		if ( 'delicat-builder_page_delicat-builder-v9-motion' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'delicat-builder-v9-admin',
			DELICAT_BUILDER_V9_URL . 'assets/css/admin.css',
			array(),
			DELICAT_BUILDER_V9_VERSION
		);
	}

	private static function option( string $value, string $current, string $label ): void {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $current, $value, false ),
			esc_html( $label )
		);
	}

	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Delicat_Builder_V9_Core::motion_settings();
		$core = Delicat_Builder_V9_Core::settings();
		?>
		<div class="wrap delicat-admin dbv9-motion-admin">
			<div class="delicat-admin__hero">
				<div>
					<span class="delicat-admin__eyebrow">RC 45 · PREMIUM MOTION ENGINE</span>
					<h1><?php esc_html_e( 'Animations & Motion', 'delicat-builder-v9' ); ?></h1>
					<p><?php esc_html_e( 'Control premium scroll reveals and page transitions without scroll hijacking or animation libraries. All storefront motion stays transform/opacity-only; reduced-motion is respected and low-power devices automatically receive lighter motion.', 'delicat-builder-v9' ); ?></p>
				</div>
				<div class="delicat-admin__version"><?php echo esc_html( DELICAT_BUILDER_V9_VERSION ); ?></div>
			</div>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Motion settings saved.', 'delicat-builder-v9' ); ?></p></div>
			<?php endif; ?>

			<div class="delicat-warning">
				<strong><?php esc_html_e( 'Performance protection', 'delicat-builder-v9' ); ?></strong>
				<p><?php esc_html_e( 'The first viewport is never hidden for scroll reveals. Reduced Motion remains a hard accessibility stop; Save-Data and low-memory/low-core devices now receive an adaptive lightweight motion profile instead of losing animation. Page transitions still require App Navigation in Runtime Settings.', 'delicat-builder-v9' ); ?></p>
			</div>

			<form method="post" action="options.php" id="dbv9-motion-form">
				<?php settings_fields( 'delicat_builder_v9_motion_group' ); ?>
				<div class="delicat-admin__grid">
					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Scroll reveal', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch">
							<input type="checkbox" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[enabled]" value="1" <?php checked( ! empty( $s['enabled'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Enable premium scroll motion', 'delicat-builder-v9' ); ?></strong><small><?php esc_html_e( 'Animates only Builder sections below the initial viewport.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label for="dbv9-motion-effect"><strong><?php esc_html_e( 'Reveal effect', 'delicat-builder-v9' ); ?></strong></label>
							<select id="dbv9-motion-effect" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[effect]">
								<?php self::option( 'fade-up', $s['effect'], 'Fade Up · recommended' ); ?>
								<?php self::option( 'fade', $s['effect'], 'Fade' ); ?>
								<?php self::option( 'scale', $s['effect'], 'Scale In' ); ?>
								<?php self::option( 'slide-left', $s['effect'], 'Slide Left' ); ?>
								<?php self::option( 'slide-right', $s['effect'], 'Slide Right' ); ?>
								<?php self::option( 'soft-zoom', $s['effect'], 'Soft Zoom' ); ?>
							</select>
						</div>

						<div class="delicat-field">
							<label for="dbv9-motion-duration"><strong><?php esc_html_e( 'Duration', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-motion-duration-label"><?php echo esc_html( (string) $s['duration_ms'] ); ?> ms</span></label>
							<input id="dbv9-motion-duration" data-motion-control type="range" min="180" max="900" step="20" name="<?php echo esc_attr( self::OPTION ); ?>[duration_ms]" value="<?php echo esc_attr( (string) $s['duration_ms'] ); ?>">
						</div>

						<div class="delicat-field">
							<label for="dbv9-motion-intensity"><strong><?php esc_html_e( 'Intensity', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-motion-intensity-label"><?php echo esc_html( (string) $s['intensity'] ); ?>%</span></label>
							<input id="dbv9-motion-intensity" data-motion-control type="range" min="0" max="100" step="5" name="<?php echo esc_attr( self::OPTION ); ?>[intensity]" value="<?php echo esc_attr( (string) $s['intensity'] ); ?>">
							<small><?php esc_html_e( 'Controls travel/scale distance, not GPU-heavy filters.', 'delicat-builder-v9' ); ?></small>
						</div>

						<div class="delicat-field">
							<label for="dbv9-motion-stagger"><strong><?php esc_html_e( 'Stagger', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-motion-stagger-label"><?php echo esc_html( (string) $s['stagger_ms'] ); ?> ms</span></label>
							<input id="dbv9-motion-stagger" data-motion-control type="range" min="0" max="150" step="5" name="<?php echo esc_attr( self::OPTION ); ?>[stagger_ms]" value="<?php echo esc_attr( (string) $s['stagger_ms'] ); ?>">
						</div>

						<div class="delicat-field">
							<label for="dbv9-motion-delay"><strong><?php esc_html_e( 'Base delay', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-motion-delay-label"><?php echo esc_html( (string) $s['base_delay_ms'] ); ?> ms</span></label>
							<input id="dbv9-motion-delay" data-motion-control type="range" min="0" max="300" step="10" name="<?php echo esc_attr( self::OPTION ); ?>[base_delay_ms]" value="<?php echo esc_attr( (string) $s['base_delay_ms'] ); ?>">
						</div>

						<div class="delicat-field">
							<label for="dbv9-motion-threshold"><strong><?php esc_html_e( 'Reveal threshold', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-motion-threshold-label"><?php echo esc_html( (string) $s['threshold_percent'] ); ?>%</span></label>
							<input id="dbv9-motion-threshold" data-motion-control type="range" min="1" max="30" step="1" name="<?php echo esc_attr( self::OPTION ); ?>[threshold_percent]" value="<?php echo esc_attr( (string) $s['threshold_percent'] ); ?>">
						</div>
					</section>

					<section class="delicat-admin__card">
						<h2><?php esc_html_e( 'Devices & transitions', 'delicat-builder-v9' ); ?></h2>

						<label class="delicat-switch"><input type="checkbox" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[desktop_enabled]" value="1" <?php checked( ! empty( $s['desktop_enabled'] ) ); ?>><span><strong><?php esc_html_e( 'Desktop motion', 'delicat-builder-v9' ); ?></strong><small>&gt; 960 px</small></span></label>
						<label class="delicat-switch"><input type="checkbox" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[tablet_enabled]" value="1" <?php checked( ! empty( $s['tablet_enabled'] ) ); ?>><span><strong><?php esc_html_e( 'Tablet motion', 'delicat-builder-v9' ); ?></strong><small>641–960 px</small></span></label>
						<label class="delicat-switch"><input type="checkbox" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[mobile_enabled]" value="1" <?php checked( ! empty( $s['mobile_enabled'] ) ); ?>><span><strong><?php esc_html_e( 'Mobile motion', 'delicat-builder-v9' ); ?></strong><small>≤ 640 px</small></span></label>

						<div class="delicat-field">
							<label for="dbv9-mobile-intensity"><strong><?php esc_html_e( 'Mobile intensity multiplier', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-mobile-intensity-label"><?php echo esc_html( (string) $s['mobile_intensity'] ); ?>%</span></label>
							<input id="dbv9-mobile-intensity" data-motion-control type="range" min="20" max="100" step="5" name="<?php echo esc_attr( self::OPTION ); ?>[mobile_intensity]" value="<?php echo esc_attr( (string) $s['mobile_intensity'] ); ?>">
							<small><?php esc_html_e( '70% is recommended to keep iPhone/Android movement elegant and stable.', 'delicat-builder-v9' ); ?></small>
						</div>

						<label class="delicat-switch">
							<input type="checkbox" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[page_transitions]" value="1" <?php checked( ! empty( $s['page_transitions'] ) ); ?>>
							<span><strong><?php esc_html_e( 'Premium page transitions', 'delicat-builder-v9' ); ?></strong><small><?php echo ! empty( $core['app_navigation'] ) ? esc_html__( 'App Navigation is enabled.', 'delicat-builder-v9' ) : esc_html__( 'Requires App Navigation in Runtime Settings.', 'delicat-builder-v9' ); ?></small></span>
						</label>

						<div class="delicat-field">
							<label for="dbv9-page-transition"><strong><?php esc_html_e( 'Page transition style', 'delicat-builder-v9' ); ?></strong></label>
							<select id="dbv9-page-transition" data-motion-control name="<?php echo esc_attr( self::OPTION ); ?>[page_transition]">
								<?php self::option( 'fade-slide', $s['page_transition'], 'Fade + Slide · recommended' ); ?>
								<?php self::option( 'fade', $s['page_transition'], 'Fade' ); ?>
								<?php self::option( 'scale', $s['page_transition'], 'Soft Scale' ); ?>
								<?php self::option( 'none', $s['page_transition'], 'Instant / none' ); ?>
							</select>
						</div>

						<div class="delicat-field">
							<label for="dbv9-page-duration"><strong><?php esc_html_e( 'Page transition duration', 'delicat-builder-v9' ); ?></strong> <span id="dbv9-page-duration-label"><?php echo esc_html( (string) $s['page_duration_ms'] ); ?> ms</span></label>
							<input id="dbv9-page-duration" data-motion-control type="range" min="120" max="600" step="20" name="<?php echo esc_attr( self::OPTION ); ?>[page_duration_ms]" value="<?php echo esc_attr( (string) $s['page_duration_ms'] ); ?>">
						</div>

						<div class="delicat-warning"><strong><?php esc_html_e( 'Recommended Delicat preset', 'delicat-builder-v9' ); ?></strong><p><?php esc_html_e( 'Fade Up · 440 ms · 45% intensity · 55 ms stagger · 70% mobile intensity · Fade + Slide pages.', 'delicat-builder-v9' ); ?></p></div>
					</section>

					<section class="delicat-admin__card" style="grid-column:1/-1">
						<h2><?php esc_html_e( 'Live preview', 'delicat-builder-v9' ); ?></h2>
						<p class="description"><?php esc_html_e( 'Preview uses the same transform/opacity model as the storefront. Change a control or press Replay.', 'delicat-builder-v9' ); ?></p>
						<div id="dbv9-motion-preview-stage" style="overflow:hidden;min-height:190px;border:1px solid rgba(90,90,140,.16);border-radius:22px;padding:24px;background:linear-gradient(145deg,rgba(255,255,255,.98),rgba(244,245,255,.94))">
							<div id="dbv9-motion-preview" style="max-width:560px;margin:auto;padding:24px;border:1px solid rgba(101,93,215,.18);border-radius:20px;background:#fff;box-shadow:0 14px 34px rgba(32,28,95,.10)">
								<strong style="display:block;font-size:20px;margin-bottom:8px">Delicat Premium Motion</strong>
								<span style="color:#667085">Smooth, restrained and performance-first.</span>
							</div>
						</div>
						<p><button type="button" class="button" id="dbv9-motion-replay"><?php esc_html_e( 'Replay preview', 'delicat-builder-v9' ); ?></button></p>
					</section>
				</div>
				<?php submit_button( __( 'Save Animation & Motion settings', 'delicat-builder-v9' ) ); ?>
			</form>
		</div>
		<script>
		(() => {
			'use strict';
			const form = document.getElementById('dbv9-motion-form');
			const preview = document.getElementById('dbv9-motion-preview');
			const replay = document.getElementById('dbv9-motion-replay');
			if (!form || !preview) return;
			const value = (id, fallback) => Number(document.getElementById(id)?.value || fallback);
			const effect = () => document.getElementById('dbv9-motion-effect')?.value || 'fade-up';
			const updateLabels = () => {
				[['duration','ms'],['intensity','%'],['stagger','ms'],['delay','ms'],['threshold','%']].forEach(([key, unit]) => {
					const input = document.getElementById('dbv9-motion-' + key);
					const label = document.getElementById('dbv9-motion-' + key + '-label');
					if (input && label) label.textContent = input.value + ' ' + unit;
				});
				const mi = document.getElementById('dbv9-mobile-intensity');
				const mil = document.getElementById('dbv9-mobile-intensity-label');
				if (mi && mil) mil.textContent = mi.value + '%';
				const pd = document.getElementById('dbv9-page-duration');
				const pdl = document.getElementById('dbv9-page-duration-label');
				if (pd && pdl) pdl.textContent = pd.value + ' ms';
			};
			const replayPreview = () => {
				updateLabels();
				const intensity = value('dbv9-motion-intensity', 45) / 100;
				const distance = Math.round(4 + intensity * 28);
				const scale = 1 - (0.008 + intensity * 0.032);
				let from = `translate3d(0,${distance}px,0)`;
				if (effect() === 'fade') from = 'none';
				if (effect() === 'scale') from = `scale(${scale})`;
				if (effect() === 'slide-left') from = `translate3d(-${distance}px,0,0)`;
				if (effect() === 'slide-right') from = `translate3d(${distance}px,0,0)`;
				if (effect() === 'soft-zoom') from = `scale(${1 + 0.008 + intensity * 0.025})`;
				preview.getAnimations?.().forEach(a => a.cancel());
				preview.animate([
					{ opacity: 0.001, transform: from },
					{ opacity: 1, transform: 'none' }
				], {
					duration: value('dbv9-motion-duration', 440),
					delay: value('dbv9-motion-delay', 0),
					easing: 'cubic-bezier(.22,.61,.36,1)',
					fill: 'both'
				});
			};
			form.querySelectorAll('[data-motion-control]').forEach(el => el.addEventListener('input', replayPreview));
			replay?.addEventListener('click', replayPreview);
			replayPreview();
		})();
		</script>
		<?php
	}
}
