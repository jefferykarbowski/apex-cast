<?php
/**
 * Backend adapter factory.
 *
 * @package ApexChute\ApexCast
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast;

use ApexChute\ApexCast\Adapters\BackendAdapterInterface;
use ApexChute\ApexCast\Adapters\PostizAdapter;

/**
 * Builds a `BackendAdapterInterface` instance from the current settings.
 */
final class AdapterFactory {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Create the currently-configured adapter.
	 *
	 * @return BackendAdapterInterface|null
	 */
	public function create(): ?BackendAdapterInterface {
		$id = (string) $this->settings->get( 'backend.active', 'postiz' );

		if ( 'postiz' === $id ) {
			return $this->create_postiz();
		}
		return null;
	}

	/**
	 * Build a PostizAdapter, or null if not configured.
	 *
	 * @return PostizAdapter|null
	 */
	private function create_postiz(): ?PostizAdapter {
		$api_key = $this->settings->get_secret( 'backend.postiz.api_key_encrypted' );
		if ( '' === $api_key ) {
			return null;
		}

		$api_url = (string) $this->settings->get( 'backend.postiz.api_url', 'https://api.postiz.com/public/v1' );
		return new PostizAdapter( $api_key, $api_url );
	}
}
