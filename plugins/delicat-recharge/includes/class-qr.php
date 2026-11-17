<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * V10: QR codes drawn on the server, as a small SVG image, instead of an <img> from
 * api.qrserver.com. The wallet no longer opens a connection to a third-party host on
 * Haitian mobile networks, and the customer's Delicat code no longer leaves the site.
 *
 * Plain QR Code Model 2 (ISO/IEC 18004), written for this plugin: byte mode, error
 * correction level M (about 15 % of the code can be damaged or covered), versions 1 to 10
 * (up to 213 bytes, enough for the wallet page address with ?dsm_recipient=), the mask
 * chosen by the standard's four penalty rules. Longer data returns '' and the caller shows
 * the code in text only.
 */
final class Delicat_QR {

    /** Level M, versions 1-10: [ EC codewords per block, [ blocks, data codewords ], [ blocks, data codewords ] ]. */
    private const BLOCKS = [
        1  => [ 10, [ 1, 16 ], [ 0, 0 ] ],
        2  => [ 16, [ 1, 28 ], [ 0, 0 ] ],
        3  => [ 26, [ 1, 44 ], [ 0, 0 ] ],
        4  => [ 18, [ 2, 32 ], [ 0, 0 ] ],
        5  => [ 24, [ 2, 43 ], [ 0, 0 ] ],
        6  => [ 16, [ 4, 27 ], [ 0, 0 ] ],
        7  => [ 18, [ 4, 31 ], [ 0, 0 ] ],
        8  => [ 22, [ 2, 38 ], [ 2, 39 ] ],
        9  => [ 22, [ 3, 36 ], [ 2, 37 ] ],
        10 => [ 26, [ 4, 43 ], [ 1, 44 ] ],
    ];

    /** Centres of the alignment patterns, per version. */
    private const ALIGN = [
        1 => [], 2 => [ 6, 18 ], 3 => [ 6, 22 ], 4 => [ 6, 26 ], 5 => [ 6, 30 ], 6 => [ 6, 34 ],
        7 => [ 6, 22, 38 ], 8 => [ 6, 24, 42 ], 9 => [ 6, 26, 46 ], 10 => [ 6, 28, 50 ],
    ];

    /** @var int[] */
    private static $exp = [];
    /** @var int[] */
    private static $log = [];

    /**
     * The QR code as a data: URI for an <img src>, '' when the data is too long. Cached in the
     * object cache: a customer's code and wallet address do not change.
     */
    public static function data_uri( string $data, int $quiet = 2 ): string {
        $key = 'qr_' . md5( $quiet . '|' . $data );
        $uri = function_exists( 'wp_cache_get' ) ? wp_cache_get( $key, 'delicat_recharge' ) : false;
        if ( is_string( $uri ) ) {
            return $uri;
        }
        $svg = self::svg( $data, $quiet );
        $uri = '' === $svg ? '' : 'data:image/svg+xml,' . rawurlencode( $svg ); // smaller than base64 once gzipped
        if ( function_exists( 'wp_cache_set' ) ) {
            wp_cache_set( $key, $uri, 'delicat_recharge', DAY_IN_SECONDS );
        }
        return $uri;
    }

    /** The QR code as an SVG document (dark modules as one path), '' when the data is too long. */
    public static function svg( string $data, int $quiet = 2 ): string {
        $m = self::matrix( $data );
        if ( ! $m ) {
            return '';
        }
        $n    = count( $m );
        $full = $n + 2 * $quiet;
        $d    = '';
        for ( $y = 0; $y < $n; $y++ ) {
            for ( $x = 0; $x < $n; $x++ ) {
                if ( ! $m[ $y ][ $x ] ) {
                    continue;
                }
                $run = 1;
                while ( $x + $run < $n && $m[ $y ][ $x + $run ] ) {
                    $run++;
                }
                $d .= 'M' . ( $x + $quiet ) . ' ' . ( $y + $quiet ) . 'h' . $run . 'v1h-' . $run . 'z';
                $x += $run - 1;
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $full . ' ' . $full . '" shape-rendering="crispEdges">'
            . '<rect width="' . $full . '" height="' . $full . '" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
    }

    /**
     * The module matrix (rows of booleans, true = dark), [] when the data does not fit version 10.
     * $mask = -1 picks the mask with the lowest penalty; 0-7 forces one (tests).
     *
     * @return array<int, array<int, bool>>
     */
    public static function matrix( string $data, int $mask = -1 ): array {
        $len     = strlen( $data );
        $version = 0;
        foreach ( self::BLOCKS as $v => $b ) {
            $capacity = $b[1][0] * $b[1][1] + $b[2][0] * $b[2][1];
            if ( 4 + ( $v < 10 ? 8 : 16 ) + 8 * $len <= 8 * $capacity ) {
                $version = $v;
                break;
            }
        }
        if ( ! $version ) {
            return [];
        }
        $codewords = self::codewords( $data, $version );
        $size      = 17 + 4 * $version;
        [ $base, $reserved ] = self::function_patterns( $version, $size );
        self::place( $base, $reserved, $codewords, $size );

        $best = null;
        $pick = 0;
        foreach ( ( $mask >= 0 && $mask <= 7 ) ? [ $mask ] : range( 0, 7 ) as $k ) {
            $grid = self::apply_mask( $base, $reserved, $k, $size );
            self::draw_format( $grid, $k, $size );
            if ( $mask >= 0 ) {
                return $grid;
            }
            $score = self::penalty( $grid, $size );
            if ( null === $best || $score < $best ) {
                $best = $score;
                $pick = $grid;
            }
        }
        return $pick;
    }

    /* ---- encoding ------------------------------------------------------------------- */

    /** Data codewords (byte mode, padded) followed by the error correction, interleaved. */
    private static function codewords( string $data, int $version ): array {
        [ $ec_len, $g1, $g2 ] = self::BLOCKS[ $version ];
        $capacity = $g1[0] * $g1[1] + $g2[0] * $g2[1];

        $bits = '0100' . str_pad( decbin( strlen( $data ) ), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT );
        for ( $i = 0, $n = strlen( $data ); $i < $n; $i++ ) {
            $bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
        }
        $bits .= str_repeat( '0', min( 4, 8 * $capacity - strlen( $bits ) ) ); // terminator
        $bits .= str_repeat( '0', ( 8 - strlen( $bits ) % 8 ) % 8 );
        $bytes = array_map( 'bindec', str_split( $bits, 8 ) );
        for ( $pad = 0; count( $bytes ) < $capacity; $pad ^= 1 ) {
            $bytes[] = $pad ? 0x11 : 0xEC;
        }

        $blocks = [];
        $offset = 0;
        foreach ( [ $g1, $g2 ] as $group ) {
            for ( $b = 0; $b < $group[0]; $b++ ) {
                $blocks[] = array_slice( $bytes, $offset, $group[1] );
                $offset  += $group[1];
            }
        }
        $ecc = [];
        foreach ( $blocks as $block ) {
            $ecc[] = self::reed_solomon( $block, $ec_len );
        }
        $out     = [];
        $longest = max( $g1[1], $g2[1] );
        for ( $i = 0; $i < $longest; $i++ ) {
            foreach ( $blocks as $block ) {
                if ( isset( $block[ $i ] ) ) {
                    $out[] = $block[ $i ];
                }
            }
        }
        for ( $i = 0; $i < $ec_len; $i++ ) {
            foreach ( $ecc as $block ) {
                $out[] = $block[ $i ];
            }
        }
        return $out;
    }

    /** Reed-Solomon remainder over GF(256), primitive polynomial 0x11D. */
    private static function reed_solomon( array $data, int $ec_len ): array {
        if ( ! self::$exp ) {
            $x = 1;
            for ( $i = 0; $i < 255; $i++ ) {
                self::$exp[ $i ]  = $x;
                self::$log[ $x ]  = $i;
                $x <<= 1;
                if ( $x & 0x100 ) {
                    $x ^= 0x11D;
                }
            }
            for ( $i = 255; $i < 512; $i++ ) {
                self::$exp[ $i ] = self::$exp[ $i - 255 ];
            }
        }
        // Generator polynomial: product of (x - a^i), i = 0 .. ec_len - 1 (highest degree first).
        $gen = [ 1 ];
        for ( $i = 0; $i < $ec_len; $i++ ) {
            $next = array_fill( 0, count( $gen ) + 1, 0 );
            foreach ( $gen as $j => $coef ) {
                $next[ $j ] ^= $coef;
                if ( $coef ) {
                    $next[ $j + 1 ] ^= self::$exp[ self::$log[ $coef ] + $i ];
                }
            }
            $gen = $next;
        }
        $rem = array_merge( $data, array_fill( 0, $ec_len, 0 ) );
        for ( $i = 0, $n = count( $data ); $i < $n; $i++ ) {
            $lead = $rem[ $i ];
            if ( ! $lead ) {
                continue;
            }
            $l = self::$log[ $lead ];
            for ( $j = 1; $j <= $ec_len; $j++ ) {
                if ( $gen[ $j ] ) {
                    $rem[ $i + $j ] ^= self::$exp[ $l + self::$log[ $gen[ $j ] ] ];
                }
            }
        }
        return array_slice( $rem, count( $data ) );
    }

    /* ---- layout --------------------------------------------------------------------- */

    /** Finder, separator, timing and alignment patterns, the dark module and version information. */
    private static function function_patterns( int $version, int $size ): array {
        $grid = array_fill( 0, $size, array_fill( 0, $size, false ) );
        $res  = $grid;
        $set  = static function ( int $y, int $x, bool $dark ) use ( &$grid, &$res ): void {
            $grid[ $y ][ $x ] = $dark;
            $res[ $y ][ $x ]  = true;
        };
        foreach ( [ [ 0, 0 ], [ 0, $size - 7 ], [ $size - 7, 0 ] ] as [ $fy, $fx ] ) {
            for ( $dy = -1; $dy <= 7; $dy++ ) {
                for ( $dx = -1; $dx <= 7; $dx++ ) {
                    $y = $fy + $dy;
                    $x = $fx + $dx;
                    if ( $y < 0 || $x < 0 || $y >= $size || $x >= $size ) {
                        continue;
                    }
                    $in = $dy >= 0 && $dy <= 6 && $dx >= 0 && $dx <= 6;
                    $set( $y, $x, $in && ( 0 === $dy || 6 === $dy || 0 === $dx || 6 === $dx || ( $dy >= 2 && $dy <= 4 && $dx >= 2 && $dx <= 4 ) ) );
                }
            }
        }
        for ( $i = 8; $i < $size - 8; $i++ ) {
            $set( 6, $i, 0 === $i % 2 );
            $set( $i, 6, 0 === $i % 2 );
        }
        $centres = self::ALIGN[ $version ];
        $last    = count( $centres ) - 1;
        foreach ( $centres as $i => $cy ) {
            foreach ( $centres as $j => $cx ) {
                if ( ( 0 === $i && 0 === $j ) || ( 0 === $i && $last === $j ) || ( $last === $i && 0 === $j ) ) {
                    continue; // the three corners hold the finder patterns
                }
                for ( $dy = -2; $dy <= 2; $dy++ ) {
                    for ( $dx = -2; $dx <= 2; $dx++ ) {
                        $set( $cy + $dy, $cx + $dx, 2 === max( abs( $dy ), abs( $dx ) ) || ( 0 === $dy && 0 === $dx ) );
                    }
                }
            }
        }
        // Format information areas (written per mask later) and the dark module.
        for ( $i = 0; $i < 9; $i++ ) {
            $res[ 8 ][ $i ] = true;
            $res[ $i ][ 8 ] = true;
        }
        for ( $i = 0; $i < 8; $i++ ) {
            $res[ 8 ][ $size - 1 - $i ] = true;
            $res[ $size - 1 - $i ][ 8 ] = true;
        }
        $set( $size - 8, 8, true );
        if ( $version >= 7 ) {
            $rem = $version;
            for ( $i = 0; $i < 12; $i++ ) {
                $rem = ( $rem << 1 ) ^ ( ( $rem >> 11 ) * 0x1F25 );
            }
            $info = ( $version << 12 ) | $rem;
            for ( $i = 0; $i < 18; $i++ ) {
                $dark = (bool) ( ( $info >> $i ) & 1 );
                $a    = $size - 11 + $i % 3;
                $b    = intdiv( $i, 3 );
                $set( $b, $a, $dark );
                $set( $a, $b, $dark );
            }
        }
        return [ $grid, $res ];
    }

    /** Codeword bits in the two-column zigzag, bottom-right first; leftover modules stay light. */
    private static function place( array &$grid, array $res, array $codewords, int $size ): void {
        $bits = '';
        foreach ( $codewords as $cw ) {
            $bits .= str_pad( decbin( $cw ), 8, '0', STR_PAD_LEFT );
        }
        $total = strlen( $bits );
        $i     = 0;
        $up    = true;
        for ( $right = $size - 1; $right >= 1; $right -= 2 ) {
            if ( 6 === $right ) {
                $right = 5; // skip the vertical timing column
            }
            for ( $step = 0; $step < $size; $step++ ) {
                $y = $up ? $size - 1 - $step : $step;
                for ( $c = 0; $c < 2; $c++ ) {
                    $x = $right - $c;
                    if ( $res[ $y ][ $x ] ) {
                        continue;
                    }
                    $grid[ $y ][ $x ] = $i < $total && '1' === $bits[ $i ];
                    $i++;
                }
            }
            $up = ! $up;
        }
    }

    private static function apply_mask( array $grid, array $res, int $k, int $size ): array {
        for ( $y = 0; $y < $size; $y++ ) {
            for ( $x = 0; $x < $size; $x++ ) {
                if ( $res[ $y ][ $x ] ) {
                    continue;
                }
                switch ( $k ) {
                    case 0: $flip = 0 === ( $y + $x ) % 2; break;
                    case 1: $flip = 0 === $y % 2; break;
                    case 2: $flip = 0 === $x % 3; break;
                    case 3: $flip = 0 === ( $y + $x ) % 3; break;
                    case 4: $flip = 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2; break;
                    case 5: $flip = 0 === ( $y * $x ) % 2 + ( $y * $x ) % 3; break;
                    case 6: $flip = 0 === ( ( $y * $x ) % 2 + ( $y * $x ) % 3 ) % 2; break;
                    default: $flip = 0 === ( ( $y + $x ) % 2 + ( $y * $x ) % 3 ) % 2; break;
                }
                if ( $flip ) {
                    $grid[ $y ][ $x ] = ! $grid[ $y ][ $x ];
                }
            }
        }
        return $grid;
    }

    /** 15-bit format information (level M = 00, then the mask), BCH-coded, in both copies. */
    private static function draw_format( array &$grid, int $mask, int $size ): void {
        $data = $mask; // level M is 0b00
        $rem  = $data;
        for ( $i = 0; $i < 10; $i++ ) {
            $rem = ( $rem << 1 ) ^ ( ( $rem >> 9 ) * 0x537 );
        }
        $bits = ( ( $data << 10 ) | $rem ) ^ 0x5412;
        $bit  = static function ( int $i ) use ( $bits ): bool {
            return (bool) ( ( $bits >> $i ) & 1 );
        };
        for ( $i = 0; $i <= 5; $i++ ) {
            $grid[ $i ][ 8 ] = $bit( $i );
        }
        $grid[7][8] = $bit( 6 );
        $grid[8][8] = $bit( 7 );
        $grid[8][7] = $bit( 8 );
        for ( $i = 9; $i < 15; $i++ ) {
            $grid[8][ 14 - $i ] = $bit( $i );
        }
        for ( $i = 0; $i < 8; $i++ ) {
            $grid[8][ $size - 1 - $i ] = $bit( $i );
        }
        for ( $i = 8; $i < 15; $i++ ) {
            $grid[ $size - 15 + $i ][8] = $bit( $i );
        }
        $grid[ $size - 8 ][8] = true; // dark module
    }

    /** The standard's four penalty rules (runs, 2x2 blocks, finder-like patterns, dark balance). */
    private static function penalty( array $grid, int $size ): int {
        $score = 0;
        $dark  = 0;
        $lines = [];
        for ( $y = 0; $y < $size; $y++ ) {
            $row = '';
            $col = '';
            for ( $x = 0; $x < $size; $x++ ) {
                $row  .= $grid[ $y ][ $x ] ? '1' : '0';
                $col  .= $grid[ $x ][ $y ] ? '1' : '0';
                $dark += $grid[ $y ][ $x ] ? 1 : 0;
                if ( $y < $size - 1 && $x < $size - 1 ) {
                    $v = $grid[ $y ][ $x ];
                    if ( $v === $grid[ $y ][ $x + 1 ] && $v === $grid[ $y + 1 ][ $x ] && $v === $grid[ $y + 1 ][ $x + 1 ] ) {
                        $score += 3;
                    }
                }
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ( $lines as $line ) {
            if ( preg_match_all( '/0{5,}|1{5,}/', $line, $runs ) ) {
                foreach ( $runs[0] as $run ) {
                    $score += strlen( $run ) - 2;
                }
            }
            // 1:1:3:1:1 finder-like pattern with 4 light modules before or after it (outside the
            // symbol counts as light: the quiet zone).
            $at = strpos( $line, '1011101' );
            while ( false !== $at ) {
                $before = substr( $line, max( 0, $at - 4 ), min( 4, $at ) );
                $after  = (string) substr( $line, $at + 7, 4 );
                if ( 0 === $at || $size - 7 === $at || false === strpos( $before, '1' ) || false === strpos( $after, '1' ) ) {
                    $score += 40;
                    $at    += 7;
                } else {
                    $at += 4;
                }
                $at = $at < $size ? strpos( $line, '1011101', $at ) : false;
            }
        }
        $score += 10 * intdiv( abs( 20 * $dark - 10 * $size * $size ), $size * $size );
        return $score;
    }
}
