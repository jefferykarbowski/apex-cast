<?php
/**
 * Encryption unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\Encryption;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Behavioural tests for the Encryption helper.
 *
 * Encryption depends only on libsodium primitives + the AUTH_KEY constant,
 * so it is fully unit-testable without a WordPress runtime.
 */
final class Encryption_Test extends TestCase {

	public function test_round_trip_returns_original_plaintext(): void {
		$enc    = new Encryption();
		$secret = 'sk-ant-example-api-key-1234567890';

		$this->assertSame( $secret, $enc->decrypt( $enc->encrypt( $secret ) ) );
	}

	public function test_ciphertext_differs_from_plaintext(): void {
		$enc = new Encryption();
		$this->assertNotSame( 'hello', $enc->encrypt( 'hello' ) );
	}

	public function test_each_encryption_uses_a_fresh_nonce(): void {
		$enc = new Encryption();
		// Same plaintext encrypted twice should never produce identical ciphertext.
		$this->assertNotSame( $enc->encrypt( 'same input' ), $enc->encrypt( 'same input' ) );
	}

	public function test_decrypt_rejects_non_base64_input(): void {
		$enc = new Encryption();
		$this->expectException( RuntimeException::class );
		$enc->decrypt( 'not-valid-base64-!!!' );
	}

	public function test_decrypt_rejects_too_short_input(): void {
		$enc = new Encryption();
		$this->expectException( RuntimeException::class );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture only.
		$enc->decrypt( base64_encode( 'shorter-than-a-nonce' ) );
	}

	public function test_decrypt_fails_with_a_different_key(): void {
		$encoded = ( new Encryption() )->encrypt( 'secret' );
		$other   = new Encryption( 'a-completely-different-secret-value' );

		$this->expectException( RuntimeException::class );
		$other->decrypt( $encoded );
	}

	public function test_empty_string_round_trips(): void {
		$enc = new Encryption();
		$this->assertSame( '', $enc->decrypt( $enc->encrypt( '' ) ) );
	}

	public function test_long_string_round_trips(): void {
		$enc       = new Encryption();
		$plaintext = str_repeat( 'A long secret with whitespace and punctuation. ', 200 );
		$this->assertSame( $plaintext, $enc->decrypt( $enc->encrypt( $plaintext ) ) );
	}

	public function test_explicit_key_argument_overrides_auth_key_constant(): void {
		// Two instances with the same explicit key interoperate;
		// an instance using AUTH_KEY does not interoperate with them.
		$a = new Encryption( 'shared-test-key' );
		$b = new Encryption( 'shared-test-key' );
		$this->assertSame( 'payload', $b->decrypt( $a->encrypt( 'payload' ) ) );

		$default = new Encryption();
		$this->expectException( RuntimeException::class );
		$default->decrypt( $a->encrypt( 'payload' ) );
	}
}
