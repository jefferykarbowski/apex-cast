<?php
/**
 * Value object unit tests.
 *
 * @package ApexChute\ApexCast\Tests
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\Tests;

use ApexChute\ApexCast\AI\AIProviderException;
use ApexChute\ApexCast\AI\BrandVoice;
use ApexChute\ApexCast\AI\GenerationResult;
use ApexChute\ApexCast\AI\ProductContext;
use ApexChute\ApexCast\Publishers\PublishRequest;
use ApexChute\ApexCast\Publishers\PublishResult;
use ApexChute\ApexCast\Publishers\PublisherException;
use ApexChute\ApexCast\Support\TestConnectionResult;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the immutable DTOs exchanged between the AI provider,
 * the publisher implementations, and the REST layer.
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
			'instock',
			'https://apexchute.com/wp-content/uploads/apex.jpg'
		);

		$array = $ctx->to_array();

		$this->assertSame( 42, $array['product_id'] );
		$this->assertSame( 'Apex Chute 3.0', $array['title'] );
		$this->assertSame( 'instock', $array['stock_status'] );
		$this->assertSame( array( 'skydiving', 'apex' ), $array['tags'] );
	}

	public function test_brand_voice_defaults_when_settings_are_empty(): void {
		$voice = BrandVoice::from_settings( array() );

		$this->assertSame( '', $voice->tone );
		$this->assertSame( '', $voice->voice_notes );
		$this->assertSame( BrandVoice::STRATEGY_MODERATE, $voice->hashtag_strategy );
		$this->assertSame( array(), $voice->do_not_use );
	}

	public function test_brand_voice_normalises_unknown_strategy_to_moderate(): void {
		$voice = BrandVoice::from_settings( array( 'hashtag_strategy' => 'aggressive' ) );
		$this->assertSame( BrandVoice::STRATEGY_MODERATE, $voice->hashtag_strategy );
	}

	public function test_brand_voice_preserves_valid_strategy(): void {
		$voice = BrandVoice::from_settings( array( 'hashtag_strategy' => 'sparse' ) );
		$this->assertSame( BrandVoice::STRATEGY_SPARSE, $voice->hashtag_strategy );
	}

	public function test_brand_voice_coerces_do_not_use_to_strings(): void {
		$voice = BrandVoice::from_settings(
			array(
				'do_not_use' => array( 'cheap', 42, 'limited time' ),
			)
		);
		$this->assertSame( array( 'cheap', '42', 'limited time' ), $voice->do_not_use );
	}

	public function test_brand_voice_handles_non_array_do_not_use(): void {
		$voice = BrandVoice::from_settings( array( 'do_not_use' => 'oops, single string' ) );
		$this->assertSame( array(), $voice->do_not_use );
	}

	public function test_generation_result_for_platform_returns_present_draft(): void {
		$result = new GenerationResult(
			array(
				'facebook'  => array( 'content' => 'Hello, world.' ),
				'instagram' => array( 'content' => 'Hello, gram.' ),
			),
			'A playful, product-led angle.',
			'claude-sonnet-4-6',
			120,
			80
		);

		$this->assertSame( array( 'content' => 'Hello, world.' ), $result->for_platform( 'facebook' ) );
	}

	public function test_generation_result_for_platform_returns_null_for_missing(): void {
		$result = new GenerationResult( array(), '', '' );
		$this->assertNull( $result->for_platform( 'threads' ) );
	}

	public function test_generation_result_to_array_includes_token_counts(): void {
		$result = new GenerationResult( array(), '', 'm', 10, 20 );
		$array  = $result->to_array();
		$this->assertSame( 10, $array['input_tokens'] );
		$this->assertSame( 20, $array['output_tokens'] );
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
			'reddit',
			'Title',
			array(),
			'https://example.com',
			'https://example.com/img.jpg',
			null,
			array( 'subreddit' => 'skydiving' )
		);
		$this->assertSame( 'skydiving', $req->platform_options['subreddit'] );
	}

	public function test_publish_result_success_factory(): void {
		$result = PublishResult::success_for( 'pinterest', 'pin_42', 'https://pinterest.com/pin/42' );
		$this->assertTrue( $result->success );
		$this->assertSame( 'pin_42', $result->platform_post_id );
		$this->assertSame( 'https://pinterest.com/pin/42', $result->platform_url );
		$this->assertNull( $result->error_message );
	}

	public function test_publish_result_failure_factory(): void {
		$result = PublishResult::failure_for( 'x', 'Rate limited.' );
		$this->assertFalse( $result->success );
		$this->assertSame( 'x', $result->platform );
		$this->assertSame( 'Rate limited.', $result->error_message );
		$this->assertSame( '', $result->platform_post_id );
	}

	public function test_publish_result_to_array_shape(): void {
		$array = PublishResult::success_for( 'reddit', 'r_1', 'https://reddit.com/r/x/y' )->to_array();
		$this->assertSame(
			array( 'success', 'platform', 'platform_post_id', 'platform_url', 'error_message', 'context' ),
			array_keys( $array )
		);
	}

	public function test_ai_provider_exception_factories_produce_distinct_messages(): void {
		$messages = array(
			AIProviderException::auth_failed()->getMessage(),
			AIProviderException::rate_limited()->getMessage(),
			AIProviderException::malformed_response()->getMessage(),
			AIProviderException::malformed_response( 'extra' )->getMessage(),
			AIProviderException::http_error( 502 )->getMessage(),
		);

		// All non-empty.
		foreach ( $messages as $message ) {
			$this->assertNotSame( '', $message );
		}
		// http_error embeds the status.
		$this->assertStringContainsString( '502', $messages[4] );
	}

	public function test_publisher_exception_factories_embed_platform_and_status(): void {
		$this->assertStringContainsString( 'pinterest', PublisherException::not_configured( 'pinterest' )->getMessage() );
		$this->assertStringContainsString( 'reddit', PublisherException::auth_failed( 'reddit' )->getMessage() );
		$this->assertStringContainsString( 'x', PublisherException::rate_limited( 'x' )->getMessage() );
		$this->assertStringContainsString( 'facebook', PublisherException::http_error( 'facebook', 503 )->getMessage() );
		$this->assertStringContainsString( '503', PublisherException::http_error( 'facebook', 503 )->getMessage() );
		$this->assertStringContainsString( 'detail', PublisherException::malformed_response( 'instagram', 'detail' )->getMessage() );
	}
}
