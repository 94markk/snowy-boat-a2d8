<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Responsive media helpers.
 *
 * Security rule: only WordPress image attachments are accepted. No arbitrary
 * image URLs are stored or emitted by the builder media engine.
 */
final class Delicat_Builder_V9_Media {
	public static function is_image_attachment( int $attachment_id ): bool {
		return $attachment_id > 0
			&& 'attachment' === get_post_type( $attachment_id )
			&& wp_attachment_is_image( $attachment_id );
	}

	public static function image(
		int $attachment_id,
		string $size,
		string $class,
		bool $priority = false,
		string $sizes = '100vw'
	): string {
		if ( ! self::is_image_attachment( $attachment_id ) ) {
			return '';
		}

		$attrs = array(
			'class'    => sanitize_html_class( $class ),
			'loading'  => $priority ? 'eager' : 'lazy',
			'decoding' => 'async',
			'sizes'    => sanitize_text_field( $sizes ),
		);

		if ( $priority ) {
			$attrs['fetchpriority'] = 'high';
			$attrs['data-delicat-lcp'] = '1';
		} else {
			$attrs['fetchpriority'] = 'low';
		}

		return (string) wp_get_attachment_image(
			$attachment_id,
			$size,
			false,
			$attrs
		);
	}

	public static function picture(
		int $desktop_id,
		int $mobile_id,
		string $size,
		string $class,
		bool $priority = false,
		string $sizes = '(max-width: 960px) 100vw, 50vw'
	): string {
		$desktop_id = self::is_image_attachment( $desktop_id ) ? $desktop_id : 0;
		$mobile_id  = self::is_image_attachment( $mobile_id ) ? $mobile_id : 0;

		if ( ! $desktop_id && ! $mobile_id ) {
			return '';
		}

		$primary_id = $desktop_id ?: $mobile_id;
		$img = self::image(
			$primary_id,
			$size,
			$class,
			$priority,
			$sizes
		);

		if ( ! $img ) {
			return '';
		}

		if ( ! $mobile_id || $mobile_id === $primary_id ) {
			return $img;
		}

		$mobile_srcset = wp_get_attachment_image_srcset( $mobile_id, $size );
		$mobile_src    = wp_get_attachment_image_url( $mobile_id, $size );
		if ( ! $mobile_srcset && ! $mobile_src ) {
			return $img;
		}

		$source = '<source media="(max-width:640px)"';
		if ( $mobile_srcset ) {
			$source .= ' srcset="' . esc_attr( $mobile_srcset ) . '" sizes="100vw"';
		} else {
			$source .= ' srcset="' . esc_url( $mobile_src ) . '"';
		}
		$source .= '>';

		return '<picture class="delicat-builder-picture">' . $source . $img . '</picture>';
	}

	public static function preload_links( int $desktop_id, int $mobile_id = 0, string $desktop_sizes = '(max-width:960px) 100vw, 50vw' ): string {
		$desktop_id = self::is_image_attachment( $desktop_id ) ? $desktop_id : 0;
		$mobile_id  = self::is_image_attachment( $mobile_id ) ? $mobile_id : 0;

		if ( ! $desktop_id && ! $mobile_id ) {
			return '';
		}

		$out = '';

		if ( $mobile_id ) {
			$out .= self::preload_link( $mobile_id, '(max-width:640px)', '100vw' );
		}

		$desktop_target = $desktop_id ?: $mobile_id;
		if ( $desktop_target ) {
			$media = $mobile_id ? '(min-width:641px)' : '';
			$out .= self::preload_link( $desktop_target, $media, $desktop_sizes );
		}

		return $out;
	}

	private static function preload_link( int $attachment_id, string $media, string $sizes ): string {
		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! $url ) {
			return '';
		}

		$srcset = wp_get_attachment_image_srcset( $attachment_id, 'large' );
		$link  = '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high"';
		if ( $srcset ) {
			$link .= ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ) . '"';
		}
		if ( '' !== $media ) {
			$link .= ' media="' . esc_attr( $media ) . '"';
		}
		$link .= '>' . "\n";

		return $link;
	}
}
