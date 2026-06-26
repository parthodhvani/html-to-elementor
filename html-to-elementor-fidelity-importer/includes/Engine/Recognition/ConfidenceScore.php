<?php
/**
 * Confidence score for component recognition.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Recognition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable confidence result from the recognition engine.
 */
final class ConfidenceScore {

	/**
	 * @param string              $widget_type Elementor widget type.
	 * @param int                 $confidence  0-100 score.
	 * @param array<string,mixed> $settings    Widget settings.
	 * @param string              $reason      Classification reason.
	 */
	public function __construct(
		public readonly string $widget_type,
		public readonly int $confidence,
		public readonly array $settings,
		public readonly string $reason = ''
	) {}

	/**
	 * Whether the score meets the minimum threshold.
	 */
	public function passes( int $threshold ): bool {
		return $this->confidence >= $threshold;
	}

	/**
	 * @return array{type:string,confidence:int,settings:array<string,mixed>,reason:string}
	 */
	public function to_array(): array {
		return array(
			'type'       => $this->widget_type,
			'confidence' => $this->confidence,
			'settings'   => $this->settings,
			'reason'     => $this->reason,
		);
	}
}
