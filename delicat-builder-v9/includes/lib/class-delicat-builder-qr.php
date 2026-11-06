<?php
/**
 * QR code encoder for the shareable order receipt (byte mode, error correction M,
 * versions 1 to 10, automatic mask). No network call, no third-party service.
 *
 * ---------------------------------------------------------------------------
 * QR Code Generator
 * Copyright (c) 2009 Kazuhiko Arase
 * URL: http://www.d-project.com/
 * Licensed under the MIT license:
 *   http://www.opensource.org/licenses/mit-license.php
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * The word "QR Code" is registered trademark of DENSO WAVE INCORPORATED.
 * ---------------------------------------------------------------------------
 * The Dart port used by the Délicat app (package qr 3.0.2) was consulted so the
 * two encoders draw identical matrices (tests/order_confirmation_smoke.php checks
 * tests/fixtures/qr-golden.json, produced by that package):
 *
 * Copyright 2014, the Dart QR project authors. All rights reserved.
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *   * Redistributions of source code must retain the above copyright notice,
 *     this list of conditions and the following disclaimer.
 *   * Redistributions in binary form must reproduce the above copyright notice,
 *     this list of conditions and the following disclaimer in the documentation
 *     and/or other materials provided with the distribution.
 *   * Neither the name of Google Inc. nor the names of its contributors may be
 *     used to endorse or promote products derived from this software without
 *     specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON
 * ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 * ---------------------------------------------------------------------------
 * PHP port for Delicat Builder, 2026.
 *
 * @package Delicat_Builder_V9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds QR matrices (true = dark module) and renders them as SVG or hex rows.
 */
final class Delicat_Builder_V9_Qr {

	/** Largest version drawn (57 x 57 modules, 213 bytes at level M). */
	public const MAX_VERSION = 10;

	/** Error correction level M as numbered by the original code (L 1, M 0, Q 3, H 2). */
	private const LEVEL_M = 0;

	/** Reed-Solomon blocks at level M: (count, total codewords, data codewords) groups. */
	private const RS_BLOCKS_M = array(
		1  => array( 1, 26, 16 ),
		2  => array( 1, 44, 28 ),
		3  => array( 1, 70, 44 ),
		4  => array( 2, 50, 32 ),
		5  => array( 2, 67, 43 ),
		6  => array( 4, 43, 27 ),
		7  => array( 4, 49, 31 ),
		8  => array( 2, 60, 38, 2, 61, 39 ),
		9  => array( 3, 58, 36, 2, 59, 37 ),
		10 => array( 4, 69, 43, 1, 70, 44 ),
	);

	/** Alignment pattern centres. */
	private const ALIGNMENT = array(
		1  => array(),
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/** @var int[]|null Galois field exponent table. */
	private static $exp = null;

	/** @var int[]|null Galois field logarithm table. */
	private static $log = null;

	/**
	 * QR matrix for a UTF-8 string, or null when it is empty or too long for version 10.
	 *
	 * @param string $data Payload (bytes as given; PHP strings are already UTF-8 here).
	 * @return array<int, array<int, bool>>|null Rows of modules.
	 */
	public static function matrix( string $data ): ?array {
		if ( '' === $data ) {
			return null;
		}
		$bytes   = array_values( unpack( 'C*', $data ) );
		$version = self::version_for( count( $bytes ) );
		if ( 0 === $version ) {
			return null;
		}
		$codewords = self::codewords( $bytes, $version );
		$best      = 0;
		$min       = 0.0;
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$lost = self::lost_point( self::build( $version, $codewords, $mask, true ) );
			if ( 0 === $mask || $min > $lost ) {
				$min  = $lost;
				$best = $mask;
			}
		}
		return self::build( $version, $codewords, $best, false );
	}

	/**
	 * One hex string per row (bit 1 = dark, left to right, padded with 0 to a multiple of 4).
	 *
	 * @param array $m Matrix from matrix().
	 * @return string[]
	 */
	public static function rows_hex( array $m ): array {
		$rows = array();
		foreach ( $m as $row ) {
			$bits = '';
			foreach ( $row as $dark ) {
				$bits .= $dark ? '1' : '0';
			}
			$bits .= str_repeat( '0', ( 4 - strlen( $bits ) % 4 ) % 4 );
			$hex   = '';
			foreach ( str_split( $bits, 4 ) as $nibble ) {
				$hex .= dechex( (int) bindec( $nibble ) );
			}
			$rows[] = $hex;
		}
		return $rows;
	}

	/**
	 * Inline SVG with a white plate and a 4-module quiet zone, one path of dark runs.
	 *
	 * @param array  $m  Matrix from matrix().
	 * @param int    $px Rendered width and height in CSS pixels.
	 * @param string $fg Module colour.
	 * @return string
	 */
	public static function svg( array $m, int $px = 64, string $fg = '#0D1B4D' ): string {
		$n = count( $m );
		if ( $n < 21 ) {
			return '';
		}
		$fg   = preg_match( '/^#[0-9A-Fa-f]{3,8}$/', $fg ) ? $fg : '#0D1B4D';
		$size = $n + 8;
		$path = '';
		/* One 1-unit stroke per run of dark modules, relative moves within a row: about
		 * 2 KB for the 33 x 33 view-order code. */
		foreach ( $m as $y => $row ) {
			$x   = 0;
			$end = -1;
			while ( $x < $n ) {
				if ( empty( $row[ $x ] ) ) {
					++$x;
					continue;
				}
				$start = $x;
				while ( $x < $n && ! empty( $row[ $x ] ) ) {
					++$x;
				}
				$path .= ( $end < 0 ? 'M' . ( $start + 4 ) . ' ' . ( $y + 4 ) . '.5' : 'm' . ( $start - $end ) . ' 0' ) . 'h' . ( $x - $start );
				$end   = $x;
			}
		}
		$px = max( 21, $px );
		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $px . '" height="' . $px . '" shape-rendering="crispEdges" aria-hidden="true" focusable="false">'
			. '<rect width="' . $size . '" height="' . $size . '" fill="#fff"/>'
			. '<path fill="none" stroke="' . $fg . '" stroke-width="1" d="' . $path . '"/></svg>';
	}

	// ------------------------------------------------------------------
	// Encoding
	// ------------------------------------------------------------------

	/** Smallest version (1 to 10) that holds $length bytes, 0 when none does. */
	private static function version_for( int $length ): int {
		for ( $version = 1; $version <= self::MAX_VERSION; $version++ ) {
			$bits = 4 + self::length_bits( $version ) + 8 * $length;
			if ( $bits <= self::data_count( $version ) * 8 ) {
				return $version;
			}
		}
		return 0;
	}

	/** Character count indicator size in byte mode. */
	private static function length_bits( int $version ): int {
		return $version < 10 ? 8 : 16;
	}

	/** @return array<int, array{0:int,1:int}> One (total, data) pair per block. */
	private static function blocks( int $version ): array {
		$table  = self::RS_BLOCKS_M[ $version ];
		$blocks = array();
		for ( $i = 0, $n = count( $table ); $i < $n; $i += 3 ) {
			for ( $j = 0; $j < $table[ $i ]; $j++ ) {
				$blocks[] = array( $table[ $i + 1 ], $table[ $i + 2 ] );
			}
		}
		return $blocks;
	}

	private static function data_count( int $version ): int {
		$total = 0;
		foreach ( self::blocks( $version ) as $block ) {
			$total += $block[1];
		}
		return $total;
	}

	/** Data and error correction codewords, interleaved. */
	private static function codewords( array $bytes, int $version ): array {
		$buffer = array( 'bytes' => array(), 'length' => 0 );
		self::put( $buffer, 4, 4 );
		self::put( $buffer, count( $bytes ), self::length_bits( $version ) );
		foreach ( $bytes as $byte ) {
			self::put( $buffer, $byte, 8 );
		}
		$total_bits = self::data_count( $version ) * 8;
		if ( $buffer['length'] + 4 <= $total_bits ) {
			self::put( $buffer, 0, 4 );
		}
		while ( 0 !== $buffer['length'] % 8 ) {
			self::put( $buffer, 0, 1 );
		}
		$count = 0;
		while ( $buffer['length'] < $total_bits ) {
			self::put( $buffer, 0 === ( $count++ % 2 ) ? 0xEC : 0x11, 8 );
		}

		$blocks = self::blocks( $version );
		$offset = 0;
		$max_dc = 0;
		$max_ec = 0;
		$dc     = array();
		$ec     = array();
		foreach ( $blocks as $r => $block ) {
			$dc_count = $block[1];
			$ec_count = $block[0] - $dc_count;
			$max_dc   = max( $max_dc, $dc_count );
			$max_ec   = max( $max_ec, $ec_count );
			$dc[ $r ] = array_slice( $buffer['bytes'], $offset, $dc_count );
			$offset  += $dc_count;
			$rs_poly  = self::generator( $ec_count );
			$mod      = self::poly_mod( self::poly( $dc[ $r ], count( $rs_poly ) - 1 ), $rs_poly );
			$ec[ $r ] = array();
			$ec_len   = count( $rs_poly ) - 1;
			for ( $i = 0; $i < $ec_len; $i++ ) {
				$index        = $i + count( $mod ) - $ec_len;
				$ec[ $r ][ $i ] = $index >= 0 ? $mod[ $index ] : 0;
			}
		}
		$data = array();
		for ( $i = 0; $i < $max_dc; $i++ ) {
			foreach ( $dc as $item ) {
				if ( $i < count( $item ) ) {
					$data[] = $item[ $i ];
				}
			}
		}
		for ( $i = 0; $i < $max_ec; $i++ ) {
			foreach ( $ec as $item ) {
				if ( $i < count( $item ) ) {
					$data[] = $item[ $i ];
				}
			}
		}
		return $data;
	}

	/** Append the $length low bits of $number, most significant first. */
	private static function put( array &$buffer, int $number, int $length ): void {
		for ( $i = 0; $i < $length; $i++ ) {
			$index = intdiv( $buffer['length'], 8 );
			if ( ! isset( $buffer['bytes'][ $index ] ) ) {
				$buffer['bytes'][ $index ] = 0;
			}
			if ( 1 === ( ( $number >> ( $length - $i - 1 ) ) & 1 ) ) {
				$buffer['bytes'][ $index ] |= 0x80 >> ( $buffer['length'] % 8 );
			}
			++$buffer['length'];
		}
	}

	// ------------------------------------------------------------------
	// Galois field and polynomials
	// ------------------------------------------------------------------

	private static function tables(): void {
		if ( null !== self::$exp ) {
			return;
		}
		$exp = array_fill( 0, 256, 0 );
		for ( $i = 0; $i < 8; $i++ ) {
			$exp[ $i ] = 1 << $i;
		}
		for ( $i = 8; $i < 256; $i++ ) {
			$exp[ $i ] = $exp[ $i - 4 ] ^ $exp[ $i - 5 ] ^ $exp[ $i - 6 ] ^ $exp[ $i - 8 ];
		}
		$log = array_fill( 0, 256, 0 );
		for ( $i = 0; $i < 255; $i++ ) {
			$log[ $exp[ $i ] ] = $i;
		}
		self::$exp = $exp;
		self::$log = $log;
	}

	private static function gexp( int $n ): int {
		self::tables();
		return self::$exp[ $n % 255 ];
	}

	private static function glog( int $n ): int {
		self::tables();
		return self::$log[ $n ];
	}

	/** Leading zeros dropped, $shift zeros appended. */
	private static function poly( array $num, int $shift ): array {
		$offset = 0;
		$n      = count( $num );
		while ( $offset < $n && 0 === $num[ $offset ] ) {
			++$offset;
		}
		$out = array_slice( $num, $offset );
		for ( $i = 0; $i < $shift; $i++ ) {
			$out[] = 0;
		}
		return $out;
	}

	private static function poly_multiply( array $a, array $b ): array {
		$out = array_fill( 0, count( $a ) + count( $b ) - 1, 0 );
		foreach ( $a as $i => $x ) {
			foreach ( $b as $j => $y ) {
				$out[ $i + $j ] ^= self::gexp( self::glog( $x ) + self::glog( $y ) );
			}
		}
		return self::poly( $out, 0 );
	}

	private static function poly_mod( array $a, array $e ): array {
		while ( count( $a ) >= count( $e ) && count( $a ) > 0 ) {
			$ratio = self::glog( $a[0] ) - self::glog( $e[0] );
			foreach ( $e as $i => $value ) {
				$a[ $i ] ^= self::gexp( self::glog( $value ) + $ratio );
			}
			$a = self::poly( $a, 0 );
		}
		return $a;
	}

	private static function generator( int $length ): array {
		$poly = array( 1 );
		for ( $i = 0; $i < $length; $i++ ) {
			$poly = self::poly_multiply( $poly, array( 1, self::gexp( $i ) ) );
		}
		return $poly;
	}

	// ------------------------------------------------------------------
	// Matrix
	// ------------------------------------------------------------------

	private static function build( int $version, array $data, int $mask, bool $test ): array {
		$n = $version * 4 + 17;
		$m = array_fill( 0, $n, array_fill( 0, $n, null ) );
		self::probe( $m, 0, 0 );
		self::probe( $m, $n - 7, 0 );
		self::probe( $m, 0, $n - 7 );
		$pos = self::ALIGNMENT[ $version ];
		foreach ( $pos as $row ) {
			foreach ( $pos as $col ) {
				if ( null !== $m[ $row ][ $col ] ) {
					continue;
				}
				for ( $r = -2; $r <= 2; $r++ ) {
					for ( $c = -2; $c <= 2; $c++ ) {
						$m[ $row + $r ][ $col + $c ] = ( -2 === $r || 2 === $r || -2 === $c || 2 === $c || ( 0 === $r && 0 === $c ) );
					}
				}
			}
		}
		for ( $r = 8; $r < $n - 8; $r++ ) {
			if ( null === $m[ $r ][6] ) {
				$m[ $r ][6] = 0 === $r % 2;
			}
		}
		for ( $c = 8; $c < $n - 8; $c++ ) {
			if ( null === $m[6][ $c ] ) {
				$m[6][ $c ] = 0 === $c % 2;
			}
		}
		$bits = self::bch_type_info( ( self::LEVEL_M << 3 ) | $mask );
		for ( $i = 0; $i < 15; $i++ ) {
			$mod = ! $test && 1 === ( ( $bits >> $i ) & 1 );
			if ( $i < 6 ) {
				$m[ $i ][8] = $mod;
			} elseif ( $i < 8 ) {
				$m[ $i + 1 ][8] = $mod;
			} else {
				$m[ $n - 15 + $i ][8] = $mod;
			}
		}
		for ( $i = 0; $i < 15; $i++ ) {
			$mod = ! $test && 1 === ( ( $bits >> $i ) & 1 );
			if ( $i < 8 ) {
				$m[8][ $n - $i - 1 ] = $mod;
			} elseif ( $i < 9 ) {
				$m[8][ 15 - $i ] = $mod;
			} else {
				$m[8][ 15 - $i - 1 ] = $mod;
			}
		}
		$m[ $n - 8 ][8] = ! $test;
		if ( $version >= 7 ) {
			$type = self::bch_type_number( $version );
			for ( $i = 0; $i < 18; $i++ ) {
				$mod = ! $test && 1 === ( ( $type >> $i ) & 1 );
				$m[ intdiv( $i, 3 ) ][ $i % 3 + $n - 11 ] = $mod;
				$m[ $i % 3 + $n - 11 ][ intdiv( $i, 3 ) ] = $mod;
			}
		}
		self::map_data( $m, $data, $mask, $n );
		return $m;
	}

	private static function probe( array &$m, int $row, int $col ): void {
		$n = count( $m );
		for ( $r = -1; $r <= 7; $r++ ) {
			if ( $row + $r <= -1 || $n <= $row + $r ) {
				continue;
			}
			for ( $c = -1; $c <= 7; $c++ ) {
				if ( $col + $c <= -1 || $n <= $col + $c ) {
					continue;
				}
				$m[ $row + $r ][ $col + $c ] = ( $r >= 0 && $r <= 6 && ( 0 === $c || 6 === $c ) )
					|| ( $c >= 0 && $c <= 6 && ( 0 === $r || 6 === $r ) )
					|| ( $r >= 2 && $r <= 4 && $c >= 2 && $c <= 4 );
			}
		}
	}

	private static function map_data( array &$m, array $data, int $mask, int $n ): void {
		$inc   = -1;
		$row   = $n - 1;
		$bit   = 7;
		$index = 0;
		$count = count( $data );
		for ( $col = $n - 1; $col > 0; $col -= 2 ) {
			if ( 6 === $col ) {
				--$col;
			}
			while ( true ) {
				for ( $c = 0; $c < 2; $c++ ) {
					if ( null !== $m[ $row ][ $col - $c ] ) {
						continue;
					}
					$dark = $index < $count && 1 === ( ( $data[ $index ] >> $bit ) & 1 );
					if ( self::mask( $mask, $row, $col - $c ) ) {
						$dark = ! $dark;
					}
					$m[ $row ][ $col - $c ] = $dark;
					--$bit;
					if ( -1 === $bit ) {
						++$index;
						$bit = 7;
					}
				}
				$row += $inc;
				if ( $row < 0 || $n <= $row ) {
					$row -= $inc;
					$inc  = -$inc;
					break;
				}
			}
		}
	}

	private static function mask( int $pattern, int $i, int $j ): bool {
		switch ( $pattern ) {
			case 0:
				return 0 === ( $i + $j ) % 2;
			case 1:
				return 0 === $i % 2;
			case 2:
				return 0 === $j % 3;
			case 3:
				return 0 === ( $i + $j ) % 3;
			case 4:
				return 0 === ( intdiv( $i, 2 ) + intdiv( $j, 3 ) ) % 2;
			case 5:
				return 0 === ( $i * $j ) % 2 + ( $i * $j ) % 3;
			case 6:
				return 0 === ( ( $i * $j ) % 2 + ( $i * $j ) % 3 ) % 2;
			default:
				return 0 === ( ( $i * $j ) % 3 + ( $i + $j ) % 2 ) % 2;
		}
	}

	private static function bch_digit( int $data ): int {
		$digit = 0;
		while ( 0 !== $data ) {
			++$digit;
			$data >>= 1;
		}
		return $digit;
	}

	private static function bch_type_info( int $data ): int {
		$g15  = ( 1 << 10 ) | ( 1 << 8 ) | ( 1 << 5 ) | ( 1 << 4 ) | ( 1 << 2 ) | ( 1 << 1 ) | 1;
		$mask = ( 1 << 14 ) | ( 1 << 12 ) | ( 1 << 10 ) | ( 1 << 4 ) | ( 1 << 1 );
		$d    = $data << 10;
		while ( self::bch_digit( $d ) - self::bch_digit( $g15 ) >= 0 ) {
			$d ^= $g15 << ( self::bch_digit( $d ) - self::bch_digit( $g15 ) );
		}
		return ( ( $data << 10 ) | $d ) ^ $mask;
	}

	private static function bch_type_number( int $data ): int {
		$g18 = ( 1 << 12 ) | ( 1 << 11 ) | ( 1 << 10 ) | ( 1 << 9 ) | ( 1 << 8 ) | ( 1 << 5 ) | ( 1 << 2 ) | 1;
		$d   = $data << 12;
		while ( self::bch_digit( $d ) - self::bch_digit( $g18 ) >= 0 ) {
			$d ^= $g18 << ( self::bch_digit( $d ) - self::bch_digit( $g18 ) );
		}
		return ( $data << 12 ) | $d;
	}

	/** Penalty score of a test matrix (same rules and arithmetic as the original). */
	private static function lost_point( array $m ): float {
		$n    = count( $m );
		$lost = 0.0;
		for ( $row = 0; $row < $n; $row++ ) {
			for ( $col = 0; $col < $n; $col++ ) {
				$same = 0;
				$dark = $m[ $row ][ $col ];
				for ( $r = -1; $r <= 1; $r++ ) {
					if ( $row + $r < 0 || $n <= $row + $r ) {
						continue;
					}
					for ( $c = -1; $c <= 1; $c++ ) {
						if ( $col + $c < 0 || $n <= $col + $c || ( 0 === $r && 0 === $c ) ) {
							continue;
						}
						if ( $dark === $m[ $row + $r ][ $col + $c ] ) {
							++$same;
						}
					}
				}
				if ( $same > 5 ) {
					$lost += 3 + $same - 5;
				}
			}
		}
		for ( $row = 0; $row < $n - 1; $row++ ) {
			for ( $col = 0; $col < $n - 1; $col++ ) {
				$count = (int) $m[ $row ][ $col ] + (int) $m[ $row + 1 ][ $col ] + (int) $m[ $row ][ $col + 1 ] + (int) $m[ $row + 1 ][ $col + 1 ];
				if ( 0 === $count || 4 === $count ) {
					$lost += 3;
				}
			}
		}
		for ( $row = 0; $row < $n; $row++ ) {
			for ( $col = 0; $col < $n - 6; $col++ ) {
				if ( $m[ $row ][ $col ] && ! $m[ $row ][ $col + 1 ] && $m[ $row ][ $col + 2 ] && $m[ $row ][ $col + 3 ] && $m[ $row ][ $col + 4 ] && ! $m[ $row ][ $col + 5 ] && $m[ $row ][ $col + 6 ] ) {
					$lost += 40;
				}
			}
		}
		for ( $col = 0; $col < $n; $col++ ) {
			for ( $row = 0; $row < $n - 6; $row++ ) {
				if ( $m[ $row ][ $col ] && ! $m[ $row + 1 ][ $col ] && $m[ $row + 2 ][ $col ] && $m[ $row + 3 ][ $col ] && $m[ $row + 4 ][ $col ] && ! $m[ $row + 5 ][ $col ] && $m[ $row + 6 ][ $col ] ) {
					$lost += 40;
				}
			}
		}
		$dark = 0;
		foreach ( $m as $row ) {
			foreach ( $row as $module ) {
				if ( $module ) {
					++$dark;
				}
			}
		}
		$ratio = abs( 100 * $dark / $n / $n - 50 ) / 5;
		return $lost + $ratio * 10;
	}
}
