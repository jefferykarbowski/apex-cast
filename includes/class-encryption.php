<?php
/**
 * Encryption helper.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use RuntimeException;

/**
 * Symmetric authenticated encryption for sensitive settings (API keys, etc).
 *
 * Uses libsodium's `crypto_secretbox` (XSalsa20 + Poly1305). The runtime key
 * is derived from `AUTH_KEY` so that ciphertext survives plugin updates but
 * becomes invalid (by design) if the site owner rotates `AUTH_KEY`. The
 * paragonie/sodium_compat package provides a pure-PHP fallback when the
 * native sodium extension is unavailable, so callers never need to branch.
 */
final class Encryption {

	/**
	 * Derived 32-byte symmetric key.
	 *
	 * @var non-empty-string
	 */
	private string $key;

	/**
	 * Constructor.
	 *
	 * @param string|null $key Optional override of the secret used to derive the key.
	 *                         If null, falls back to the AUTH_KEY constant.
	 *
	 * @throws RuntimeException When no key material is available.
	 */
	public function __construct( ?string $key = null ) {
		$secret = $key ?? ( defined( 'AUTH_KEY' ) ? (string) constant( 'AUTH_KEY' ) : '' );
		if ( '' === $secret ) {
			throw new RuntimeException( 'Apex Cast encryption requires AUTH_KEY to be defined.' );
		}

		// Domain-separated key derivation so the same AUTH_KEY can be reused safely
		// by other plugins doing the same thing.
		$this->key = sodium_crypto_generichash(
			'apex-cast|' . $secret,
			'',
			SODIUM_CRYPTO_SECRETBOX_KEYBYTES
		);
	}

	/**
	 * Encrypt a plaintext string.
	 *
	 * @param string $plaintext Value to encrypt. Empty strings are allowed.
	 * @return string Base64-encoded `nonce || ciphertext`.
	 */
	public function encrypt( string $plaintext ): string {
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->key );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding binary ciphertext for storage in wp_options, not obfuscation.
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Decrypt a previously encrypted value.
	 *
	 * @param string $encoded The base64-encoded `nonce || ciphertext`.
	 * @return string The original plaintext.
	 *
	 * @throws RuntimeException If the input is not valid base64, is too short to contain a nonce,
	 *                          or the MAC fails (key rotation, tampering, etc.).
	 */
	public function decrypt( string $encoded ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding stored binary ciphertext, not obfuscation.
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded || strlen( $decoded ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			throw new RuntimeException( 'Apex Cast could not decode the stored ciphertext.' );
		}

		$nonce      = substr( $decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->key );
		if ( false === $plaintext ) {
			throw new RuntimeException( 'Apex Cast could not decrypt the stored value. The key may have rotated or the ciphertext may have been tampered with.' );
		}

		return $plaintext;
	}
}
