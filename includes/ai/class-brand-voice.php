<?php
/**
 * Brand voice value object.
 *
 * @package ApexChute\ApexCast\AI
 */

declare( strict_types=1 );

namespace ApexChute\ApexCast\AI;

/**
 * Immutable brand-voice configuration fed verbatim into the AI system prompt.
 */
final class BrandVoice {

	public const STRATEGY_SPARSE   = 'sparse';
	public const STRATEGY_MODERATE = 'moderate';
	public const STRATEGY_HEAVY    = 'heavy';

	/**
	 * Constructor.
	 *
	 * @param string   $tone             Free-text tone descriptor (e.g. "friendly, expert").
	 * @param string   $voice_notes      Free-text voice notes. Fed verbatim into the system prompt.
	 * @param string   $hashtag_strategy One of the STRATEGY_* constants.
	 * @param string[] $do_not_use       Phrases or patterns the model must avoid.
	 */
	public function __construct(
		public readonly string $tone = '',
		public readonly string $voice_notes = '',
		public readonly string $hashtag_strategy = self::STRATEGY_MODERATE,
		public readonly array $do_not_use = array()
	) {}

	/**
	 * Build from the stored brand_voice settings sub-array.
	 *
	 * @param array<string, mixed> $settings The brand_voice sub-array of apex_cast_settings.
	 * @return self
	 */
	public static function from_settings( array $settings ): self {
		$raw_avoid  = $settings['do_not_use'] ?? array();
		$do_not_use = is_array( $raw_avoid )
			? array_values( array_map( 'strval', $raw_avoid ) )
			: array();

		$strategy = (string) ( $settings['hashtag_strategy'] ?? self::STRATEGY_MODERATE );
		if ( ! in_array( $strategy, array( self::STRATEGY_SPARSE, self::STRATEGY_MODERATE, self::STRATEGY_HEAVY ), true ) ) {
			$strategy = self::STRATEGY_MODERATE;
		}

		return new self(
			(string) ( $settings['tone'] ?? '' ),
			(string) ( $settings['voice_notes'] ?? '' ),
			$strategy,
			$do_not_use
		);
	}

	/**
	 * Export as a plain associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'tone'             => $this->tone,
			'voice_notes'      => $this->voice_notes,
			'hashtag_strategy' => $this->hashtag_strategy,
			'do_not_use'       => $this->do_not_use,
		);
	}
}
