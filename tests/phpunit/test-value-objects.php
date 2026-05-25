<?php
/**
 * Value object unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\AI\ProductContext;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublishResult;
use ApexChute\ApexCast\Publishers\PublisherException;
use ApexChute\ApexCast\Support\TestConnectionResult;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the immutable DTOs exchanged between the publisher
 * implementations and the REST layer.
 */
final class Value_Objects_Test extends TestCase {

	public function test_product_context_to_array_contains_all_fields(): void {
		$ctx = new ProductContext(
			42,
			'Apex Chute 3.0',
			'https://apexchute.com/product/apex-chute-3-0',
			'A short description.',
			'A longer description, truncated.',
			'$199.00',
			array( 'Sporting Goods' ),
			array( 'skydiving', 'apex' ),
			array( 'skydiving', 'apex' ),
			'instock',
			'https://apexchute.com/wp-content/uploads/apex.jpg'
		);

		$array = $ctx->to_array();

		$this->assertSame( 42, $array['product_id'] );
		$this->assertSame( 'Apex Chute 3.0', $array['title'] );
		$this->assertSame( 'instock', $array['stock_status'] );
		$this->assertSame( array( 'skydiving', 'apex' ), $array['tags'] );
		$this->assertSame( array( 'skydiving', 'apex' ), $array['tag_slugs'] );
	}

	public function test_test_connection_result_success_factory(): void {
		$result = TestConnectionResult::success( 'All good.', array( 'integrations' => 3 ) );
		$this->assertTrue( $result->success );
		$this->assertSame( 'All good.', $result->message );
		$this->assertSame( array( 'integrations' => 3 ), $result->details );
	}

	public function test_test_connection_result_failure_factory(): void {
		$result = TestConnectionResult::failure( 'Nope.' );
		$this->assertFalse( $result->success );
		$this->assertSame( 'Nope.', $result->message );
		$this->assertSame( array(), $result->details );
	}

	public function test_test_connection_result_to_array_shape(): void {
		$array = TestConnectionResult::success( 'ok' )->to_array();
		$this->assertSame( array( 'success', 'message', 'details' ), array_keys( $array ) );
	}

	public function test_publish_request_defaults(): void {
		$req = new PublishRequest(
			42,
			'pinterest',
			'A great chute.',
			array( '#skydive', '#apexchute' ),
			'https://apexchute.com/product/apex-chute-3-0',
			'https://apexchute.com/wp-content/uploads/apex.jpg'
		);

		$this->assertSame( 42, $req->product_id );
		$this->assertSame( 'pinterest', $req->platform );
		$this->assertSame( 'A great chute.', $req->content );
		$this->assertSame( array( '#skydive', '#apexchute' ), $req->hashtags );
		$this->assertNull( $req->scheduled_at );
		$this->assertSame( array(), $req->platform_options );
	}

	public function test_publish_request_accepts_platform_options(): void {
		$req = new PublishRequest(
			1,
			'pinterest',
			'Title',
			array(),
			'https://example.com',
			'https://example.com/img.jpg',
			null,
			array( 'board_id' => 'b123' )
		);
		$this->assertSame( 'b123', $req->platform_options['board_id'] );
	}

	public function test_publish_result_success_factory(): void {
		$result = PublishResult::success_for( 'pinterest', 'pin_42', 'https://pinterest.com/pin/42' );
		$this->assertTrue( $result->success );
		$this->assertSame( 'pin_42', $result->platform_post_id );
		$this->assertSame( 'https://pinterest.com/pin/42', $result->platform_url );
		$this->assertNull( $result->error_message );
	}

	public function test_publish_result_failure_factory(): void {
		$result = PublishResult::failure_for( 'facebook', 'Rate limited.' );
		$this->assertFalse( $result->success );
		$this->assertSame( 'facebook', $result->platform );
		$this->assertSame( 'Rate limited.', $result->error_message );
		$this->assertSame( '', $result->platform_post_id );
	}

	public function test_publish_result_to_array_shape(): void {
		$array = PublishResult::success_for( 'instagram', 'ig_1', 'https://instagram.com/p/xyz' )->to_array();
		$this->assertSame(
			array( 'success', 'platform', 'platform_post_id', 'platform_url', 'error_message', 'context' ),
			array_keys( $array )
		);
	}

	public function test_publisher_exception_factories_embed_platform_and_status(): void {
		$this->assertStringContainsString( 'pinterest', PublisherException::not_configured( 'pinterest' )->getMessage() );
		$this->assertStringContainsString( 'facebook', PublisherException::auth_failed( 'facebook' )->getMessage() );
		$this->assertStringContainsString( 'instagram', PublisherException::rate_limited( 'instagram' )->getMessage() );
		$this->assertStringContainsString( 'facebook', PublisherException::http_error( 'facebook', 503 )->getMessage() );
		$this->assertStringContainsString( '503', PublisherException::http_error( 'facebook', 503 )->getMessage() );
		$this->assertStringContainsString( 'detail', PublisherException::malformed_response( 'instagram', 'detail' )->getMessage() );
	}
}
