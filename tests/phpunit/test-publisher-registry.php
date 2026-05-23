<?php
/**
 * PublisherRegistry unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\PublisherRegistry;
use ApexChute\ApexCast\Publishers\PlatformPublisherInterface;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublishResult;
use ApexChute\ApexCast\Support\TestConnectionResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests the publisher registry — the central lookup that replaces v0.1's
 * Postiz-specific AdapterFactory.
 */
final class Publisher_Registry_Test extends TestCase {

	/**
	 * Build a minimal in-memory publisher for testing.
	 *
	 * @param string $platform   Platform identifier.
	 * @param bool   $configured Whether `is_configured()` returns true.
	 * @return PlatformPublisherInterface
	 */
	private function fake_publisher( string $platform, bool $configured = true ): PlatformPublisherInterface {
		return new class( $platform, $configured ) implements PlatformPublisherInterface {

			/**
			 * Platform identifier.
			 *
			 * @var string
			 */
			private string $platform_id;

			/**
			 * Configured flag.
			 *
			 * @var bool
			 */
			private bool $is_configured;

			/**
			 * Constructor.
			 *
			 * @param string $platform_id   Platform identifier.
			 * @param bool   $is_configured Whether the publisher reports as configured.
			 */
			public function __construct( string $platform_id, bool $is_configured ) {
				$this->platform_id   = $platform_id;
				$this->is_configured = $is_configured;
			}

			public function get_platform_id(): string {
				return $this->platform_id;
			}

			public function is_configured(): bool {
				return $this->is_configured;
			}

			public function test_connection(): TestConnectionResult {
				return TestConnectionResult::success( 'ok' );
			}

			public function publish( PublishRequest $request ): PublishResult {
				return PublishResult::success_for( $this->platform_id, 'fake_id', 'https://example.com/' . $this->platform_id );
			}
		};
	}

	public function test_empty_registry_returns_null_for_unknown_platform(): void {
		$registry = new PublisherRegistry();
		$this->assertNull( $registry->get( 'pinterest' ) );
		$this->assertFalse( $registry->has( 'pinterest' ) );
		$this->assertFalse( $registry->is_configured( 'pinterest' ) );
		$this->assertSame( array(), $registry->all() );
		$this->assertSame( array(), $registry->configured_platforms() );
	}

	public function test_register_makes_a_publisher_lookupable(): void {
		$registry = new PublisherRegistry();
		$pinterest = $this->fake_publisher( 'pinterest' );

		$registry->register( $pinterest );

		$this->assertTrue( $registry->has( 'pinterest' ) );
		$this->assertSame( $pinterest, $registry->get( 'pinterest' ) );
	}

	public function test_is_configured_reflects_publisher_state(): void {
		$registry = new PublisherRegistry();
		$registry->register( $this->fake_publisher( 'pinterest', true ) );
		$registry->register( $this->fake_publisher( 'x', false ) );

		$this->assertTrue( $registry->is_configured( 'pinterest' ) );
		$this->assertFalse( $registry->is_configured( 'x' ) );
	}

	public function test_configured_platforms_lists_only_configured_ones(): void {
		$registry = new PublisherRegistry();
		$registry->register( $this->fake_publisher( 'pinterest', true ) );
		$registry->register( $this->fake_publisher( 'reddit', true ) );
		$registry->register( $this->fake_publisher( 'x', false ) );

		$ids = $registry->configured_platforms();
		sort( $ids );
		$this->assertSame( array( 'pinterest', 'reddit' ), $ids );
	}

	public function test_register_overwrites_duplicate_platform_id(): void {
		$registry = new PublisherRegistry();
		$first    = $this->fake_publisher( 'pinterest', false );
		$second   = $this->fake_publisher( 'pinterest', true );

		$registry->register( $first );
		$registry->register( $second );

		$this->assertSame( $second, $registry->get( 'pinterest' ) );
		$this->assertCount( 1, $registry->all() );
	}

	public function test_all_returns_keyed_by_platform_id(): void {
		$registry = new PublisherRegistry();
		$registry->register( $this->fake_publisher( 'pinterest' ) );
		$registry->register( $this->fake_publisher( 'reddit' ) );

		$all = $registry->all();
		$this->assertArrayHasKey( 'pinterest', $all );
		$this->assertArrayHasKey( 'reddit', $all );
	}
}
