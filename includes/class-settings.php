<?php
/**
 * Settings store.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use RuntimeException;

/**
 * Read/write wrapper around the single `apex_cast_settings` option.
 *
 * Encryption is intentionally one-directional from the caller's perspective:
 * the settings page encrypts before calling `save()`, the consumers (AI
 * provider, backend adapter) call `get_secret()` to retrieve plaintext.
 */
final class Settings {

	private const OPTION_NAME = 'apex_cast_settings';

	/**
	 * Encryption helper used for sensitive sub-fields.
	 *
	 * @var Encryption
	 */
	private Encryption $encryption;

	/**
	 * Constructor.
	 *
	 * @param Encryption $encryption Encryption helper.
	 */
	public function __construct( Encryption $encryption ) {
		$this->encryption = $encryption;
	}

	/**
	 * Return the full settings array, merged on top of defaults.
	 *
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_replace_recursive( self::defaults(), $stored );
	}

	/**
	 * Read a value by dot-path (e.g. "ai_provider.anthropic.model").
	 *
	 * @param string $path          Dot-separated path into the settings tree.
	 * @param mixed  $default_value Value to return if the path is missing.
	 * @return mixed
	 */
	public function get( string $path, mixed $default_value = null ): mixed {
		$value = $this->all();
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return $default_value;
			}
			$value = $value[ $segment ];
		}
		return $value;
	}

	/**
	 * Persist the full settings array.
	 *
	 * Callers are expected to merge incoming partial updates on top of `all()`
	 * before calling this — Settings does not do field-level patching to keep
	 * concurrent-write semantics simple.
	 *
	 * @param array<string, mixed> $settings Settings tree to persist.
	 * @return void
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION_NAME, $settings );
	}

	/**
	 * Decrypt a secret stored at a given dot-path.
	 *
	 * Returns an empty string for unset paths or when decryption fails (e.g.
	 * after AUTH_KEY rotation). Callers detect "not configured" via empty
	 * string and prompt the user to re-enter the key.
	 *
	 * @param string $path Dot-separated path to a `*_encrypted` field.
	 * @return string Plaintext, or empty string if unavailable.
	 */
	public function get_secret( string $path ): string {
		$encrypted = $this->get( $path, '' );
		if ( ! is_string( $encrypted ) || '' === $encrypted ) {
			return '';
		}
		try {
			return $this->encryption->decrypt( $encrypted );
		} catch ( RuntimeException $e ) {
			return '';
		}
	}

	/**
	 * Encrypt a plaintext secret for storage at a given dot-path.
	 *
	 * Returns the ciphertext to store; callers are responsible for merging it
	 * into the settings tree and calling `save()`.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string Base64-encoded ciphertext suitable for `wp_options`.
	 */
	public function encrypt_secret( string $plaintext ): string {
		return $this->encryption->encrypt( $plaintext );
	}

	/**
	 * Canonical default settings tree.
	 *
	 * Single source of truth — Installer seeds the option from this, and `all()`
	 * uses it as the floor for any stored partial state.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'version'   => 4,
			'store'     => array(
				'name'              => '',
				'description'       => '',
				'default_platforms' => array( 'facebook', 'instagram', 'pinterest' ),
			),
			// Per-platform publisher configuration. Each platform's substructure
			// is owned by its publisher class; the keys present here are the
			// minimum a fresh install needs so the settings UI has something to
			// bind to.
			'platforms' => array(
				'facebook'  => array(
					'user_access_token_encrypted' => '',
					'user_token_expires_at'       => 0,
					'page_id'                     => '',
					'page_name'                   => '',
					'page_access_token_encrypted' => '',
				),
				'instagram' => array(
					'ig_business_account_id'      => '',
					'username'                    => '',
					'page_access_token_encrypted' => '',
				),
				'pinterest' => array(
					'access_token_encrypted'  => '',
					'refresh_token_encrypted' => '',
					'expires_at'              => 0,
					// Fallback board ID used when no per-tag mapping resolves.
					'board_id'                => '',
					// WC product_tag slug → Pinterest board id.
					'tag_board_map'           => array(),
					// WC product_tag slug → bool. When true and no mapping
					// exists, the publisher will auto-create a public board for
					// that tag on first send.
					'tag_auto_create'         => array(),
				),
			),
		);
	}
}
