<?php
/**
 * Small PHP/hosting compatibility helpers.
 *
 * WordPress can run without the optional mbstring extension. The assistant must
 * not crash when that extension is unavailable, so all multibyte operations go
 * through these guarded fallbacks.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

final class DSA2_Compat {
    public static function length( $value ) {
        $value = (string) $value;
        return function_exists( 'mb_strlen' ) ? mb_strlen( $value, 'UTF-8' ) : strlen( $value );
    }

    public static function substr( $value, $start, $length = null ) {
        $value = (string) $value;
        if ( function_exists( 'mb_substr' ) ) {
            return null === $length
                ? mb_substr( $value, (int) $start, null, 'UTF-8' )
                : mb_substr( $value, (int) $start, (int) $length, 'UTF-8' );
        }
        return null === $length
            ? substr( $value, (int) $start )
            : substr( $value, (int) $start, (int) $length );
    }

    public static function upper( $value ) {
        $value = (string) $value;
        return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
    }
}
