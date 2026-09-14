<?php
/**
 * Comments on posts and pages (WooCommerce supplies its own review
 * template for products).
 *
 * @package DelicatStore
 */

defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
	return;
}
?>
<section class="ds-comments" id="comments">
	<?php if ( have_comments() ) : ?>
		<h2 class="ds-comments__title"><?php echo esc_html( sprintf( '%d commentaire%s', get_comments_number(), get_comments_number() > 1 ? 's' : '' ) ); ?></h2>
		<ol class="ds-comments__list">
			<?php wp_list_comments( array( 'style' => 'ol', 'short_ping' => true, 'avatar_size' => 40 ) ); ?>
		</ol>
		<?php the_comments_pagination( array( 'prev_text' => '‹', 'next_text' => '›' ) ); ?>
	<?php endif; ?>
	<?php
	if ( comments_open() ) {
		comment_form(
			array(
				'title_reply'    => 'Laisser un commentaire',
				'label_submit'   => 'Publier',
				'class_submit'   => 'ds-btn ds-btn--brand',
				'comment_field'  => '<p class="comment-form-comment"><label for="comment">Commentaire</label><textarea id="comment" name="comment" class="ds-input" rows="5" required></textarea></p>',
			)
		);
	}
	?>
</section>
