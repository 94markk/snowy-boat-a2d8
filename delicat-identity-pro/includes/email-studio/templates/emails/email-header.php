<?php
defined( 'ABSPATH' ) || exit;
$email = isset( $email ) ? $email : null;
$id = dipes_current_email_id( $email );
$GLOBALS['dipes_current_email_id'] = $id;
$header_ctx = dipes_context( $email );
$brand = dipes_brand_name();
$logo = dipes_logo_url();
$layout = dipes_get( 'header_layout', 'banner' );
$logo_align = 'centered' === $layout ? 'center' : dipes_get( 'logo_align', 'left' );
$preheader = dipes_replace_tokens( dipes_email_setting( $id, 'preheader', '' ), $header_ctx );
$badge = dipes_replace_tokens( dipes_email_setting( $id, 'badge', '' ), $header_ctx );
$show_badge = 'yes' === dipes_email_setting( $id, 'show_badge', 'yes' );
$dark_enabled = 'yes' === dipes_get( 'dark_mode', 'yes' );
$appearance_mode = dipes_get( 'appearance_mode', 'auto' );
$scheme = 'dark' === $appearance_mode ? 'dark' : ( 'light' === $appearance_mode ? 'light' : ( $dark_enabled ? 'light dark' : 'light' ) );
$hero_kicker = strtoupper( $brand );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=<?php bloginfo( 'charset' ); ?>" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="x-apple-disable-message-reformatting" />
<meta name="color-scheme" content="<?php echo esc_attr( $scheme ); ?>" />
<meta name="supported-color-schemes" content="<?php echo esc_attr( $scheme ); ?>" />
<title><?php echo esc_html( $email_heading ); ?></title>
<?php if ( ! empty( $GLOBALS['dipes_preview_mode'] ) ) : ?><style type="text/css"><?php echo dipes_email_css( $email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style><?php endif; ?>
</head>
<body marginwidth="0" topmargin="0" marginheight="0" offset="0" bgcolor="<?php echo esc_attr( dipes_get( 'page_bg' ) ); ?>">
<div style="display:none!important;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;"><?php echo esc_html( wp_strip_all_tags( $preheader ) ); ?>&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" id="desp-outer" bgcolor="<?php echo esc_attr( dipes_get( 'page_bg' ) ); ?>"><tr><td align="center" valign="top">
<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="<?php echo esc_attr( dipes_get( 'container_width', 640 ) ); ?>" id="desp-shell" bgcolor="<?php echo esc_attr( dipes_get( 'container_bg' ) ); ?>">
<tr><td id="desp-accent-bar" bgcolor="<?php echo esc_attr( dipes_email_accent( $id ) ); ?>">&nbsp;</td></tr>
<tr><td id="desp-header" bgcolor="<?php echo esc_attr( dipes_get( 'header_bg' ) ); ?>">
<table class="desp-brand-row" role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%"><tr>
<td class="desp-brand-cell" align="<?php echo esc_attr( $logo_align ); ?>" valign="middle">
<?php if ( $logo ) : ?><a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><img id="desp-logo" src="<?php echo esc_url( $logo ); ?>" width="<?php echo esc_attr( min( 90, absint( dipes_get( 'logo_width', 90 ) ) ) ); ?>" alt="<?php echo esc_attr( $brand ); ?>" /></a><?php else : ?><div id="desp-brand"><?php echo esc_html( $brand ); ?></div><?php endif; ?>
</td>
<?php if ( 'centered' !== $layout && $show_badge && $badge ) : ?><td class="desp-status-cell" align="right" valign="middle" width="180"><span class="desp-status" style="background:<?php echo esc_attr( dipes_email_accent( $id ) ); ?>;"><?php echo esc_html( $badge ); ?></span></td><?php endif; ?>
</tr></table>
<div class="desp-hero">
<div class="desp-hero-kicker"><?php echo esc_html( $hero_kicker ); ?></div>
<h1><?php echo esc_html( $email_heading ); ?></h1>
<?php if ( 'centered' === $layout && $show_badge && $badge ) : ?><div style="margin-top:16px"><span class="desp-status" style="background:<?php echo esc_attr( dipes_email_accent( $id ) ); ?>;"><?php echo esc_html( $badge ); ?></span></div><?php endif; ?>
</div>
</td></tr>
<tr><td id="desp-body" bgcolor="<?php echo esc_attr( dipes_get( 'container_bg' ) ); ?>"><div id="body_content"><div id="body_content_inner">
