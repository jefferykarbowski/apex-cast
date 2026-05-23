<?php
/**
 * PHPUnit bootstrap for Apex Cast unit tests.
 *
 * Loads the Composer autoloader (which exposes the plugin's classmap +
 * sodium_compat + PHPUnit) and defines the minimal WordPress constants
 * that plugin code reads outside a full WP runtime.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
}

// A deterministic, throw-away key just for unit testing the Encryption helper.
// In a real WordPress install this is provided by wp-config.php.
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'apex-cast-phpunit-static-key-do-not-use-in-production-0123456789' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// In-memory stand-ins for the handful of WP functions our publishers + OAuth
// state store touch. The real WP runtime ships its own versions.
if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Test-only stub: in-memory transient read.
	 *
	 * @param string $key Transient key.
	 * @return mixed
	 */
	function get_transient( string $key ) {
		return $GLOBALS['__apex_cast_test_transients'][ $key ] ?? false;
	}

	/**
	 * Test-only stub: in-memory transient write.
	 *
	 * @param string $key        Transient key.
	 * @param mixed  $value      Value to store.
	 * @param int    $expiration Ignored in tests.
	 * @return bool
	 */
	function set_transient( string $key, $value, int $expiration = 0 ): bool {
		unset( $expiration );
		$GLOBALS['__apex_cast_test_transients'][ $key ] = $value;
		return true;
	}

	/**
	 * Test-only stub: in-memory transient delete.
	 *
	 * @param string $key Transient key.
	 * @return bool
	 */
	function delete_transient( string $key ): bool {
		if ( isset( $GLOBALS['__apex_cast_test_transients'][ $key ] ) ) {
			unset( $GLOBALS['__apex_cast_test_transients'][ $key ] );
			return true;
		}
		return false;
	}
}
