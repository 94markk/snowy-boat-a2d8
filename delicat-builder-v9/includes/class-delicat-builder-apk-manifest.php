<?php
/**
 * pro.59 — reads package, versionCode and versionName straight from an APK's
 * compiled AndroidManifest.xml (Android binary XML), so the owner never types
 * them and the uploader can refuse a file that is not Delicat Store.
 *
 * Bounded, read-only: one ZIP entry streamed into memory (8 MiB cap), parsed
 * chunk by chunk; nothing is extracted to disk or executed.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Delicat_Builder_V9_APK_Manifest {
    const MAX_BYTES = 8388608;
    const ATTR_VERSION_CODE = 0x0101021b;
    const ATTR_VERSION_NAME = 0x0101021c;
    const ATTR_MIN_SDK      = 0x0101020c;
    const ATTR_TARGET_SDK   = 0x01010270;

    /** @return array{package:string,version_code:int,version_name:string,min_sdk:int,target_sdk:int}|WP_Error */
    public static function read( string $apk ) {
        if ( ! class_exists( 'ZipArchive' ) ) { return new WP_Error( 'apk_zip', 'Activez l’extension PHP ZIP pour lire le manifeste.' ); }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $apk, ZipArchive::RDONLY ) ) { return new WP_Error( 'apk_format', 'L’archive APK est illisible.' ); }
        try {
            $stat = $zip->statName( 'AndroidManifest.xml' );
            if ( ! is_array( $stat ) || $stat['size'] < 8 || $stat['size'] > self::MAX_BYTES ) { return new WP_Error( 'apk_manifest', 'Le manifeste Android manque ou est hors limites.' ); }
            $stream = $zip->getStream( 'AndroidManifest.xml' );
            if ( false === $stream ) { return new WP_Error( 'apk_manifest', 'Le manifeste Android est illisible.' ); }
            $data = stream_get_contents( $stream, (int) $stat['size'] );
            fclose( $stream );
        } finally { $zip->close(); }
        return self::parse( (string) $data );
    }

    /** Parses Android binary XML; only the <manifest> and <uses-sdk> attributes are read. */
    public static function parse( string $data ) {
        $len = strlen( $data );
        if ( $len < 8 || "\x03\x00\x08\x00" !== substr( $data, 0, 4 ) ) { return new WP_Error( 'apk_manifest', 'Le manifeste n’est pas au format binaire Android attendu.' ); }
        $u16 = static function ( int $o ) use ( $data, $len ): int { return $o + 2 <= $len ? unpack( 'v', substr( $data, $o, 2 ) )[1] : 0; };
        $u32 = static function ( int $o ) use ( $data, $len ): int { return $o + 4 <= $len ? unpack( 'V', substr( $data, $o, 4 ) )[1] : 0; };
        $limit = min( $len, max( 8, $u32( 4 ) ) );
        $out = array( 'package' => '', 'version_code' => 0, 'version_name' => '', 'min_sdk' => 0, 'target_sdk' => 0 );
        $strings = array(); $resmap = array(); $seen_manifest = false; $off = 8; $chunks = 0;
        while ( $off + 8 <= $limit && $chunks++ < 100000 ) {
            $type = $u16( $off ); $hsize = $u16( $off + 2 ); $csize = $u32( $off + 4 );
            if ( $csize < 8 || $hsize < 8 || $off + $csize > $limit ) { break; }
            if ( 0x0001 === $type ) {
                $strings = self::strings( $data, $off, $csize, $u16, $u32 );
            } elseif ( 0x0180 === $type ) {
                for ( $i = $off + $hsize; $i + 4 <= $off + $csize; $i += 4 ) { $resmap[] = $u32( $i ); }
            } elseif ( 0x0102 === $type ) {
                $name = $strings[ $u32( $off + 20 ) ] ?? '';
                if ( 'manifest' === $name || 'uses-sdk' === $name ) {
                    $attr_start = $u16( $off + 24 ); $attr_size = max( 20, $u16( $off + 26 ) ); $attr_count = min( 64, $u16( $off + 28 ) );
                    $base = $off + 16 + $attr_start;
                    for ( $a = 0; $a < $attr_count; $a++ ) {
                        $p = $base + $a * $attr_size;
                        if ( $p + 20 > $off + $csize ) { break; }
                        $name_idx = $u32( $p + 4 ); $raw = $u32( $p + 8 ); $dtype = ord( $data[ $p + 15 ] ); $dval = $u32( $p + 16 );
                        $aname = $strings[ $name_idx ] ?? ''; $resid = $resmap[ $name_idx ] ?? 0;
                        $text = 0x03 === $dtype ? (string) ( $strings[ $dval ] ?? '' ) : ( 0xFFFFFFFF !== $raw ? (string) ( $strings[ $raw ] ?? '' ) : '' );
                        if ( 'manifest' === $name ) {
                            $seen_manifest = true;
                            if ( 'package' === $aname && 0 === $resid ) { $out['package'] = $text; }
                            elseif ( self::ATTR_VERSION_CODE === $resid || ( 0 === $resid && 'versionCode' === $aname ) ) { $out['version_code'] = 0x10 === $dtype ? $dval : (int) $text; }
                            elseif ( self::ATTR_VERSION_NAME === $resid || ( 0 === $resid && 'versionName' === $aname ) ) { $out['version_name'] = '' !== $text ? $text : (string) $dval; }
                        } else {
                            if ( self::ATTR_MIN_SDK === $resid || 'minSdkVersion' === $aname ) { $out['min_sdk'] = 0x10 === $dtype ? $dval : (int) $text; }
                            elseif ( self::ATTR_TARGET_SDK === $resid || 'targetSdkVersion' === $aname ) { $out['target_sdk'] = 0x10 === $dtype ? $dval : (int) $text; }
                        }
                    }
                }
            }
            $off += $csize;
        }
        if ( ! $seen_manifest || ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+$/', $out['package'] ) ) { return new WP_Error( 'apk_manifest', 'Le manifeste ne déclare pas de paquet Android valide.' ); }
        $out['version_name'] = sanitize_text_field( substr( $out['version_name'], 0, 40 ) );
        return $out;
    }

    /** The string pool (UTF-16 by default, UTF-8 when flagged); bounded to 65 536 entries. */
    private static function strings( string $data, int $off, int $csize, callable $u16, callable $u32 ): array {
        $count = min( 65536, $u32( $off + 8 ) );
        $flags = $u32( $off + 16 );
        $start = $u32( $off + 20 );
        $utf8  = (bool) ( $flags & 0x100 );
        $end   = $off + $csize;
        $base  = $off + $start;
        $out   = array();
        for ( $i = 0; $i < $count; $i++ ) {
            $p = $base + $u32( $off + 28 + $i * 4 );
            if ( $p < $base || $p + 2 > $end ) { $out[] = ''; continue; }
            if ( $utf8 ) {
                $l = ord( $data[ $p ] ); $p += ( $l & 0x80 ) ? 2 : 1;
                if ( $p >= $end ) { $out[] = ''; continue; }
                $bl = ord( $data[ $p ] );
                if ( $bl & 0x80 ) { $bl = ( ( $bl & 0x7f ) << 8 ) | ord( $data[ $p + 1 ] ?? "\0" ); $p += 2; } else { $p += 1; }
                $out[] = substr( $data, $p, min( $bl, max( 0, $end - $p ) ) );
            } else {
                $l = $u16( $p ); $p += 2;
                if ( $l & 0x8000 ) { $l = ( ( $l & 0x7fff ) << 16 ) | $u16( $p ); $p += 2; }
                $raw = substr( $data, $p, min( $l * 2, max( 0, $end - $p ) ) );
                $s   = function_exists( 'iconv' ) ? @iconv( 'UTF-16LE', 'UTF-8//IGNORE', $raw ) : false;
                if ( false === $s && function_exists( 'mb_convert_encoding' ) ) { $s = @mb_convert_encoding( $raw, 'UTF-8', 'UTF-16LE' ); }
                $out[] = is_string( $s ) ? $s : '';
            }
        }
        return $out;
    }
}
