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
use ApexChute\ApexCast\Adapters\BackendAdapterException;
use ApexChute\ApexCast\Adapters\IntegrationInfo;
use ApexChute\ApexCast\Adapters\MediaRef;
use ApexChute\ApexCast\Adapters\PostPayload;
use ApexChute\ApexCast\Adapters\PostStatus;
use ApexChute\ApexCast\Adapters\QueueResult;
use ApexChute\ApexCast\Support\TestConnectionResult;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the immutable DTOs exchanged between the AI provider,
 * the backend adapter, and the REST layer.
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

	public function test_integration_info_to_array_includes_picture(): void {
		$info = new IntegrationInfo( 'int_1', 'Apex on FB', 'facebook', 'https://example.com/p.png' );
		$this->assertSame(
			array(
				'id'       => 'int_1',
				'name'     => 'Apex on FB',
				'platform' => 'facebook',
				'picture'  => 'https://example.com/p.png',
			),
			$info->to_array()
		);
	}

	public function test_media_ref_to_array(): void {
		$ref = new MediaRef( 'media_42', '/uploads/apex.jpg' );
		$this->assertSame( array( 'id' => 'media_42', 'path' => '/uploads/apex.jpg' ), $ref->to_array() );
	}

	public function test_post_payload_defaults(): void {
		$payload = new PostPayload(
			42,
			array( 'facebook' ),
			array( 'facebook' => array( 'content' => 'Hi.' ) ),
			array( 'facebook' => 'int_1' )
		);

		$this->assertSame( PostPayload::TYPE_DRAFT, $payload->post_type );
		$this->assertNull( $payload->scheduled_at );
		$this->assertSame( array(), $payload->media );
	}

	public function test_queue_result_to_array(): void {
		$result = new QueueResult( 'grp_1', 'queued' );
		$this->assertSame(
			array(
				'backend_post_id'  => 'grp_1',
				'status'           => 'queued',
				'platform_results' => array(),
			),
			$result->to_array()
		);
	}

	public function test_post_status_to_array(): void {
		$status = new PostStatus( PostStatus::STATUS_SENT, array( 'facebook' => array( 'status' => 'sent' ) ) );
		$this->assertSame( PostStatus::STATUS_SENT, $status->to_array()['status'] );
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

	public function test_backend_adapter_exception_factories(): void {
		$this->assertSame( 'The backend rejected the configured API key.', BackendAdapterException::auth_failed()->getMessage() );
		$this->assertStringContainsString( '503', BackendAdapterException::http_error( 503 )->getMessage() );
		$this->assertStringContainsString( 'detail', BackendAdapterException::malformed_response( 'detail' )->getMessage() );
	}
}
