<?php
defined( 'ABSPATH' ) || exit;
$id = dipes_current_email_id( isset( $email ) ? $email : null );
$footer_ctx = dipes_context( isset( $email ) ? $email : null );
$support_url = dipes_get( 'support_url' );
$whatsapp = preg_replace( '/\D+/', '', (string) dipes_get( 'whatsapp' ) );
$support_email = sanitize_email( dipes_get( 'support_email' ) ?: get_option( 'admin_email' ) );
$footer = dipes_get( 'footer_custom_text' );
$note = dipes_replace_tokens( dipes_get( 'footer_note' ), $footer_ctx );
?>
</div></div></td></tr>
<tr><td id="desp-footer" bgcolor="<?php echo esc_attr( dipes_get( 'footer_bg' ) ); ?>">
<?php if ( 'yes' === dipes_get( 'support_enabled' ) ) : ?>
<div class="desp-support">
<p class="desp-support-title"><?php echo esc_html( dipes_get( 'support_title' ) ); ?></p>
<p class="desp-support-text"><?php echo esc_html( dipes_get( 'support_text' ) ); ?></p>
<div>
<?php if ( $support_url ) : ?><a href="<?php echo esc_url( $support_url ); ?>" style="font-weight:900;"><?php echo esc_html( dipes_get( 'support_label' ) ); ?></a><?php endif; ?>
<?php if ( $whatsapp ) : ?><?php echo $support_url ? '&nbsp;&nbsp;·&nbsp;&nbsp;' : ''; ?><a href="https://wa.me/<?php echo esc_attr( $whatsapp ); ?>" style="font-weight:900;">WhatsApp</a><?php endif; ?>
</div>
</div>
<?php endif; ?>

<div class="desp-footer-links">
<?php if ( $support_email ) : ?><a href="mailto:<?php echo esc_attr( $support_email ); ?>"><?php echo esc_html( $support_email ); ?></a><br /><?php endif; ?>
<a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php echo esc_html( wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ); ?></a>
</div>

<?php if ( dipes_get( 'social_facebook' ) || dipes_get( 'social_instagram' ) || dipes_get( 'social_tiktok' ) ) : ?><div class="desp-social">
<?php if ( dipes_get( 'social_facebook' ) ) : ?><a href="<?php echo esc_url( dipes_get( 'social_facebook' ) ); ?>">Facebook</a><?php endif; ?>
<?php if ( dipes_get( 'social_instagram' ) ) : ?><a href="<?php echo esc_url( dipes_get( 'social_instagram' ) ); ?>">Instagram</a><?php endif; ?>
<?php if ( dipes_get( 'social_tiktok' ) ) : ?><a href="<?php echo esc_url( dipes_get( 'social_tiktok' ) ); ?>">TikTok</a><?php endif; ?>
</div><?php endif; ?>

<?php if ( $footer ) : ?><div><?php echo wp_kses_post( wpautop( dipes_replace_tokens( $footer, $footer_ctx ) ) ); ?></div><?php endif; ?>
<?php if ( $note ) : ?><p style="margin:14px 0 0"><?php echo esc_html( $note ); ?></p><?php endif; ?>
<p style="margin:8px 0 0">© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php echo esc_html( dipes_brand_name() ); ?>.</p>
</td></tr>
</table></td></tr></table>
</body></html>
