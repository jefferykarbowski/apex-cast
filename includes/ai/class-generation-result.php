<?php
/**
 * Generation result value object.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

/**
 * Immutable drafts + metadata returned from an AI provider.
 */
final class GenerationResult {

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $drafts        Drafts keyed by platform.
	 * @param string                              $notes         Creative-angle note for the reviewer.
	 * @param string                              $model         Model identifier that produced the drafts.
	 * @param int                                 $input_tokens  Prompt tokens consumed.
	 * @param int                                 $output_tokens Completion tokens produced.
	 */
	public function __construct(
		public readonly array $drafts,
		public readonly string $notes = '',
		public readonly string $model = '',
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0
	) {}

	/**
	 * Get the draft for a single platform, if present.
	 *
	 * @param string $platform Platform identifier (e.g. "facebook").
	 * @return array<string, mixed>|null
	 */
	public function for_platform( string $platform ): ?array {
		$draft = $this->drafts[ $platform ] ?? null;
		return is_array( $draft ) ? $draft : null;
	}

	/**
	 * Export as a plain associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'drafts'        => $this->drafts,
			'notes'         => $this->notes,
			'model'         => $this->model,
			'input_tokens'  => $this->input_tokens,
			'output_tokens' => $this->output_tokens,
		);
	}
}
