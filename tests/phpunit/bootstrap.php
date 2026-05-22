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
