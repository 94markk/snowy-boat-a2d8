<?php
namespace Delicat\V10\Support;

defined( 'ABSPATH' ) || exit;

/**
 * PSR-4 for one namespace, with no Composer and no file list to maintain.
 *
 * V9 required 85 classes from hand-written arrays that had to be edited for
 * every new file and drifted anyway. Here the class name IS the path, so a
 * module that is never referenced is never read from disk — the cheapest
 * possible form of conditional loading, with nothing to keep in sync.
 */
final class Autoloader {

	/** @var array<string,string> prefix => base directory */
	private static $roots = array();

	public static function register( string $dir, string $prefix ): void {
		self::$roots[ $prefix ] = rtrim( $dir, '/\\' ) . '/';
		spl_autoload_register( array( __CLASS__, 'load' ), true, false );
	}

	public static function load( string $class ): void {
		foreach ( self::$roots as $prefix => $dir ) {
			if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
				continue;
			}

			$relative = substr( $class, strlen( $prefix ) );
			$path     = $dir . str_replace( '\\', '/', $relative ) . '.php';

			/*
			 * realpath() before require: a class name is attacker-influenced in
			 * principle (an unserialize gadget naming Delicat\V10\..\..\evil),
			 * and this check makes traversal out of src/ impossible rather than
			 * merely unlikely.
			 */
			$real = realpath( $path );
			if ( false !== $real && 0 === strncmp( $real, realpath( $dir ), strlen( realpath( $dir ) ) ) ) {
				require_once $real;
			}
			return;
		}
	}
}
