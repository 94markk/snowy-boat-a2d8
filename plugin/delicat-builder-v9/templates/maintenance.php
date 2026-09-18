<?php
/**
 * Delicat Builder V9 — maintenance document (RC40).
 *
 * Deliberately standalone: no wp_head(), no theme, no enqueued asset. The CSS
 * and JS arrive inlined in $delicat_maintenance, so the closed store answers
 * in a single request and cannot be broken by a theme or another plugin.
 *
 * @var array $delicat_maintenance Prepared and sanitized by Delicat_Builder_V9_Maintenance::context().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dm = isset( $delicat_maintenance ) && is_array( $delicat_maintenance ) ? $delicat_maintenance : array();

$dm_template = isset( $dm['template'] ) ? (string) $dm['template'] : 'aurora';
$dm_split    = ( 'boutique' === $dm_template );

$dm_icons = array(
	'whatsapp'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.96L2 22l5.25-1.38a9.9 9.9 0 0 0 4.79 1.22h.01c5.46 0 9.91-4.45 9.91-9.91C21.96 6.45 17.5 2 12.04 2Zm5.8 14.06c-.24.68-1.2 1.26-1.97 1.42-.53.11-1.21.2-3.51-.75-2.95-1.22-4.84-4.2-4.99-4.4-.14-.2-1.18-1.57-1.18-3s.75-2.13 1.02-2.42c.27-.29.58-.36.78-.36h.56c.18 0 .42-.03.65.5.24.57.82 1.98.89 2.13.07.14.12.31.02.5-.09.2-.14.32-.28.49-.14.17-.3.37-.42.5-.14.14-.29.29-.12.57.16.28.73 1.2 1.56 1.94 1.08.96 1.98 1.26 2.26 1.4.28.14.44.12.61-.07.17-.2.7-.82.89-1.1.19-.28.37-.23.63-.14.26.09 1.66.78 1.94.93.28.14.47.21.54.32.07.12.07.66-.17 1.34Z"/></svg>',
	'email'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.5" y="4.5" width="19" height="15" rx="3"/><path d="m3 7 8.1 5.6a1.6 1.6 0 0 0 1.8 0L21 7"/></svg>',
	'phone'     => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6.3 3h2.7l1.4 3.5-2 1.4a12 12 0 0 0 5.7 5.7l1.4-2 3.5 1.4v2.7a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.3 5.2 2 2 0 0 1 6.3 3Z"/></svg>',
	'facebook'  => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M13.5 21v-7.5h2.5l.4-3h-2.9V8.6c0-.86.24-1.45 1.48-1.45H16.5V4.46c-.27-.04-1.2-.12-2.28-.12-2.26 0-3.8 1.38-3.8 3.9V10.5H8v3h2.42V21h3.08Z"/></svg>',
	'instagram' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="3.6"/><circle cx="17.1" cy="6.9" r="1.1" fill="currentColor" stroke="none"/></svg>',
	'tiktok'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M14.3 3h2.6c.2 1.5 1.1 2.9 2.5 3.6.6.3 1.2.5 1.9.5v2.7a7.4 7.4 0 0 1-4.3-1.4v5.9a5.7 5.7 0 1 1-5.7-5.7c.3 0 .6 0 .9.1v2.8a2.9 2.9 0 1 0 2 2.8V3Z"/></svg>',
);

/* ------------------------------------------------------------------ *
 * Blocks, built once and placed differently per template.
 * ------------------------------------------------------------------ */

ob_start();
if ( ! empty( $dm['show_logo'] ) && ! empty( $dm['logo'] ) ) :
	?>
	<img class="dm-logo" src="<?php echo esc_url( (string) $dm['logo'] ); ?>" alt="<?php echo esc_attr( (string) $dm['site_name'] ); ?>" width="168" height="76">
	<?php
else :
	$dm_initial = (string) $dm['site_name'];
	$dm_initial = function_exists( 'mb_substr' ) ? mb_substr( $dm_initial, 0, 1, 'UTF-8' ) : substr( $dm_initial, 0, 1 );
	$dm_initial = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $dm_initial, 'UTF-8' ) : strtoupper( $dm_initial );
	?>
	<span class="dm-mark" aria-hidden="true"><?php echo esc_html( '' !== $dm_initial ? $dm_initial : 'D' ); ?></span>
	<?php
endif;

if ( ! empty( $dm['show_brand'] ) ) :
	?>
	<p class="dm-brand"><?php echo esc_html( (string) $dm['brand']['lead'] ); ?><?php if ( '' !== (string) $dm['brand']['accent'] ) : ?><em><?php echo esc_html( (string) $dm['brand']['accent'] ); ?></em><?php endif; ?></p>
	<?php
endif;

if ( ! empty( $dm['eyebrow'] ) ) :
	?>
	<p class="dm-eyebrow"><span class="dm-pulse"></span><?php echo esc_html( (string) $dm['eyebrow'] ); ?></p>
	<?php
endif;
?>
<h1 class="dm-title dm-display"><?php echo esc_html( (string) $dm['title'] ); ?></h1>
<?php if ( ! empty( $dm['message'] ) ) : ?>
	<p class="dm-message"><?php echo esc_html( (string) $dm['message'] ); ?></p>
<?php endif; ?>
<?php
$dm_head_block = (string) ob_get_clean();

ob_start();
if ( ! empty( $dm['countdown'] ) ) :
	?>
	<ul class="dm-countdown" id="dm-countdown" data-end="<?php echo esc_attr( (string) (int) $dm['end_ts'] ); ?>" data-now="<?php echo esc_attr( (string) (int) $dm['now_ts'] ); ?>" aria-live="off">
		<li class="dm-unit"><b id="dm-d">--</b><span><?php esc_html_e( 'Jours', 'delicat-builder-v9' ); ?></span></li>
		<li class="dm-unit"><b id="dm-h">--</b><span><?php esc_html_e( 'Heures', 'delicat-builder-v9' ); ?></span></li>
		<li class="dm-unit"><b id="dm-m">--</b><span><?php esc_html_e( 'Minutes', 'delicat-builder-v9' ); ?></span></li>
		<li class="dm-unit"><b id="dm-s">--</b><span><?php esc_html_e( 'Secondes', 'delicat-builder-v9' ); ?></span></li>
	</ul>
	<?php if ( ! empty( $dm['end_label'] ) ) : ?>
		<p class="dm-eta"><?php echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Retour prévu le %s', 'delicat-builder-v9' ), (string) $dm['end_label'] ) ); ?></p>
	<?php endif; ?>
	<?php
endif;

if ( ! empty( $dm['show_progress'] ) ) :
	$dm_progress = (int) $dm['progress'];
	?>
	<div class="dm-progress">
		<div class="dm-track" role="progressbar" aria-valuenow="<?php echo esc_attr( (string) $dm_progress ); ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Progression de la maintenance', 'delicat-builder-v9' ); ?>">
			<span class="dm-bar" style="width:<?php echo esc_attr( (string) $dm_progress ); ?>%"></span>
		</div>
		<p><?php echo esc_html( sprintf( /* translators: %d: percentage */ __( 'Mise à jour à %d%%', 'delicat-builder-v9' ), $dm_progress ) ); ?></p>
	</div>
	<?php
endif;

if ( ! empty( $dm['links'] ) ) :
	?>
	<div class="dm-links">
		<?php
		$dm_first = true;
		foreach ( (array) $dm['links'] as $dm_link ) :
			$dm_type = isset( $dm_link['type'] ) ? (string) $dm_link['type'] : '';
			$dm_icon = isset( $dm_icons[ $dm_type ] ) ? $dm_icons[ $dm_type ] : '';
			?>
			<a class="dm-link<?php echo $dm_first ? ' dm-link--primary' : ''; ?>" href="<?php echo esc_url( (string) $dm_link['url'] ); ?>"<?php echo 'whatsapp' === $dm_type ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
				<?php echo $dm_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline icon markup defined above. ?>
				<span><?php echo esc_html( (string) $dm_link['label'] ); ?></span>
			</a>
			<?php
			$dm_first = false;
		endforeach;
		?>
	</div>
	<?php
endif;

if ( ! empty( $dm['social'] ) ) :
	?>
	<div class="dm-social">
		<?php foreach ( (array) $dm['social'] as $dm_item ) : ?>
			<a href="<?php echo esc_url( (string) $dm_item['url'] ); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr( (string) $dm_item['label'] ); ?>">
				<?php echo isset( $dm_icons[ $dm_item['type'] ] ) ? $dm_icons[ $dm_item['type'] ] : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline icon markup defined above. ?>
			</a>
		<?php endforeach; ?>
	</div>
	<?php
endif;

if ( ! empty( $dm['notices'] ) ) :
	$dm_count = count( (array) $dm['notices'] );
	?>
	<div class="dm-notices"<?php echo $dm_count > 1 ? ' data-rotate="1"' : ''; ?>>
		<?php foreach ( (array) $dm['notices'] as $dm_i => $dm_note ) : ?>
			<p class="dm-note<?php echo 0 === (int) $dm_i ? ' is-on' : ''; ?>"><?php echo esc_html( (string) $dm_note ); ?></p>
		<?php endforeach; ?>
		<?php if ( $dm_count > 1 ) : ?>
			<div class="dm-dots" role="tablist">
				<?php for ( $dm_i = 0; $dm_i < $dm_count; $dm_i++ ) : ?>
					<button type="button" class="<?php echo 0 === $dm_i ? 'is-on' : ''; ?>" data-index="<?php echo esc_attr( (string) $dm_i ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: announcement number */ __( 'Annonce %d', 'delicat-builder-v9' ), $dm_i + 1 ) ); ?>"></button>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
endif;

if ( '' !== (string) $dm['footer_text'] ) :
	?>
	<p class="dm-tagline"><?php echo esc_html( (string) $dm['footer_text'] ); ?></p>
	<?php
endif;

if ( ! empty( $dm['admin_link'] ) ) :
	?>
	<p class="dm-foot"><a href="<?php echo esc_url( (string) $dm['admin_link'] ); ?>"><?php esc_html_e( 'Espace administrateur', 'delicat-builder-v9' ); ?></a></p>
	<?php
endif;
$dm_body_block = (string) ob_get_clean();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
	<meta name="robots" content="noindex,follow">
	<meta name="theme-color" content="<?php echo esc_attr( (string) $dm['bg_start'] ); ?>">
	<title><?php echo esc_html( sprintf( /* translators: %s: site name */ __( 'Maintenance — %s', 'delicat-builder-v9' ), (string) $dm['site_name'] ) ); ?></title>
	<style>
		:root{
			--dm-bg-start:<?php echo esc_html( (string) $dm['bg_start'] ); ?>;
			--dm-bg-end:<?php echo esc_html( (string) $dm['bg_end'] ); ?>;
			--dm-accent:<?php echo esc_html( (string) $dm['accent'] ); ?>;
			--dm-accent-soft:<?php echo esc_html( (string) $dm['bg_end'] ); ?>;
			--dm-accent-ring:<?php echo esc_html( (string) $dm['accent'] ); ?>40;
			--dm-accent-shadow:<?php echo esc_html( (string) $dm['accent'] ); ?>80;
		}
		<?php echo (string) $dm['css']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-owned stylesheet read from disk; escaping would corrupt CSS combinators. ?>
	</style>
</head>
<body id="dm-root" class="dm dm--<?php echo esc_attr( $dm_template ); ?>">
	<div class="dm-scene" aria-hidden="true">
		<span class="dm-blob"></span>
		<span class="dm-blob"></span>
		<span class="dm-blob"></span>
	</div>

	<main class="dm-card" role="main">
		<?php if ( $dm_split ) : ?>
			<section class="dm-panel">
				<?php echo $dm_head_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above with per-field escaping. ?>
			</section>
			<section class="dm-body">
				<?php echo $dm_body_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above with per-field escaping. ?>
			</section>
		<?php else : ?>
			<?php echo $dm_head_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above with per-field escaping. ?>
			<?php if ( 'minimal' === $dm_template ) : ?>
				<div class="dm-rule"></div>
			<?php endif; ?>
			<?php echo $dm_body_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled above with per-field escaping. ?>
		<?php endif; ?>
	</main>

	<?php if ( ! empty( $dm['preview'] ) ) : ?>
		<p class="dm-preview"><?php esc_html_e( 'Aperçu administrateur', 'delicat-builder-v9' ); ?></p>
	<?php endif; ?>

	<?php if ( '' !== (string) $dm['js'] ) : ?>
		<script><?php echo (string) $dm['js']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin-owned script read from disk; escaping would corrupt JS operators. ?></script>
	<?php endif; ?>
</body>
</html>
