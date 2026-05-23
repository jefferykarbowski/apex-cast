<?php
/**
 * OAuth state-token store.
 *
 * @package ApexChute\ApexCast\OAuth
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\OAuth;

/**
 * Transient-backed store for OAuth `state` tokens — the CSRF parameter every
 * OAuth 2.0 flow needs to thread between the initiating "start" request and
 * the user-redirected "callback" request.
 *
 * Each token is a random hex string. The stored value is a small associative
 * array with the originating user id and the platform identifier; the callback
 * pulls the token back out, verifies the user_id matches the currently-logged-
 * in user, and only then proceeds with the code-for-token exchange.
 *
 * Tokens expire after 15 minutes. The store consumes (deletes) the token as
 * soon as it's looked up — single-use, so a leaked state in the URL bar can't
 * be replayed.
 */
final class OAuthStateStore {

	private const TRANSIENT_PREFIX = 'apex_cast_oauth_state_';
	private const TTL_SECONDS      = 900;

	/**
	 * Create a fresh state token, storing the caller-supplied user id + platform
	 * for later verification on callback.
	 *
	 * @param string $platform Platform identifier (e.g. "pinterest").
	 * @param int    $user_id  WordPress user id of the user initiating the flow.
	 * @return string The new state token.
	 */
	public function create( string $platform, int $user_id ): string {
		$state = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::TRANSIENT_PREFIX . $state,
			array(
				'platform'   => $platform,
				'user_id'    => $user_id,
				'created_at' => time(),
			),
			self::TTL_SECONDS
		);
		return $state;
	}

	/**
	 * Look up a state token and delete it (single-use).
	 *
	 * Returns null when the token doesn't exist, has expired, or its platform
	 * doesn't match the caller's expectation. Callers should treat null as "this
	 * callback is not from a flow we started — bail."
	 *
	 * @param string $state             The state token from the callback's query string.
	 * @param string $expected_platform Platform the caller is handling.
	 * @return array{platform: string, user_id: int, created_at: int}|null
	 */
	public function consume( string $state, string $expected_platform ): ?array {
		if ( '' === $state ) {
			return null;
		}

		$key  = self::TRANSIENT_PREFIX . $state;
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Single-use: delete on first read regardless of validity.
		delete_transient( $key );

		if ( ! isset( $data['platform'], $data['user_id'], $data['created_at'] ) ) {
			return null;
		}
		if ( $data['platform'] !== $expected_platform ) {
			return null;
		}

		return array(
			'platform'   => (string) $data['platform'],
			'user_id'    => (int) $data['user_id'],
			'created_at' => (int) $data['created_at'],
		);
	}
}
