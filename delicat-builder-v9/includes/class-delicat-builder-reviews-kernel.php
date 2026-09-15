<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/** Lightweight invalidation bridge; the full reviews UI/query module stays request-aware. */
final class Delicat_Builder_V9_Reviews_Kernel {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) { return; }
		self::$booted = true;
		add_action( 'transition_post_status', array( __CLASS__, 'post_status' ), 10, 3 );
		add_action( 'transition_comment_status', array( __CLASS__, 'comment_status' ), 10, 3 );
		add_action( 'edit_comment', array( __CLASS__, 'comment_edited' ) );
		add_action( 'wp_insert_comment', array( __CLASS__, 'comment_inserted' ), 10, 2 );
	}

	private static function load(): bool {
		if ( ! class_exists( 'Delicat_Builder_V9_Reviews', false ) && function_exists( 'delicat_builder_v9_safe_require' ) ) {
			delicat_builder_v9_safe_require( 'includes/class-delicat-builder-reviews.php' );
		}
		if ( ! class_exists( 'Delicat_Builder_V9_Reviews', false ) ) { return false; }
		Delicat_Builder_V9_Reviews::boot();
		return true;
	}

	public static function post_status( $new_status, $old_status, $post ): void {
		if ( $post instanceof WP_Post && 'delicat_review' === (string) $post->post_type && self::load() ) {
			Delicat_Builder_V9_Reviews::maybe_purge_on_approval( $new_status, $old_status, $post );
		}
	}

	public static function comment_status( $new_status, $old_status, $comment ): void {
		if ( $comment instanceof WP_Comment && 'review' === (string) $comment->comment_type && self::load() ) {
			Delicat_Builder_V9_Reviews::woo_review_status_changed( $new_status, $old_status, $comment );
		}
	}

	public static function comment_edited( $comment_id ): void {
		$comment = get_comment( absint( $comment_id ) );
		if ( $comment instanceof WP_Comment && 'review' === (string) $comment->comment_type && self::load() ) {
			Delicat_Builder_V9_Reviews::woo_review_edited( $comment_id );
		}
	}

	public static function comment_inserted( $comment_id, $comment ): void {
		if ( $comment instanceof WP_Comment && 'review' === (string) $comment->comment_type && self::load() ) {
			Delicat_Builder_V9_Reviews::woo_review_inserted( $comment_id, $comment );
		}
	}
}
