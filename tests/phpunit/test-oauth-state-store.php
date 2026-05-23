<?php
/**
 * OAuthStateStore unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\OAuth\OAuthStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests the transient-backed state token store used to gate every OAuth
 * callback. State has to survive the round-trip to Pinterest and back, be
 * consumable exactly once, and match the user + platform that issued it.
 */
final class OAuth_State_Store_Test extends TestCase {

	/**
	 * Reset the in-memory transient store between tests.
	 */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['__apex_cast_test_transients'] = array();
	}

	public function test_create_returns_unique_state_tokens(): void {
		$store = new OAuthStateStore();
		$a     = $store->create( 'pinterest', 1 );
		$b     = $store->create( 'pinterest', 1 );
		$this->assertNotSame( $a, $b );
		$this->assertSame( 32, strlen( $a ), 'State tokens should be 32 hex chars (16 bytes).' );
	}

	public function test_consume_returns_stored_data_when_state_is_valid(): void {
		$store = new OAuthStateStore();
		$state = $store->create( 'pinterest', 42 );

		$data = $store->consume( $state, 'pinterest' );

		$this->assertIsArray( $data );
		$this->assertSame( 'pinterest', $data['platform'] );
		$this->assertSame( 42, $data['user_id'] );
		$this->assertGreaterThan( 0, $data['created_at'] );
	}

	public function test_consume_returns_null_for_unknown_state(): void {
		$store = new OAuthStateStore();
		$this->assertNull( $store->consume( 'never-issued', 'pinterest' ) );
	}

	public function test_consume_returns_null_for_empty_state(): void {
		$store = new OAuthStateStore();
		$this->assertNull( $store->consume( '', 'pinterest' ) );
	}

	public function test_consume_is_single_use(): void {
		$store = new OAuthStateStore();
		$state = $store->create( 'pinterest', 7 );

		$first  = $store->consume( $state, 'pinterest' );
		$second = $store->consume( $state, 'pinterest' );

		$this->assertNotNull( $first );
		$this->assertNull( $second, 'Second consume of the same state must return null.' );
	}

	public function test_consume_returns_null_when_platform_mismatches(): void {
		$store = new OAuthStateStore();
		$state = $store->create( 'pinterest', 7 );

		// State issued for pinterest can't be consumed by an x callback.
		$result = $store->consume( $state, 'x' );

		$this->assertNull( $result );
	}

	public function test_consume_deletes_token_even_on_platform_mismatch(): void {
		$store = new OAuthStateStore();
		$state = $store->create( 'pinterest', 7 );

		// First call with wrong platform: returns null AND burns the token.
		$store->consume( $state, 'x' );

		// Second call with the right platform: still null because the token is gone.
		$this->assertNull( $store->consume( $state, 'pinterest' ) );
	}
}
