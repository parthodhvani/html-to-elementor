<?php
/**
 * Builds a human-readable conversion report.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalises raw generator statistics into a report payload for the admin UI.
 */
final class ConversionReport {

	/**
	 * @param array<string,mixed> $generator_report Report returned by the JSON generator.
	 * @param array<string,mixed> $meta             Extra metadata (job id, title, screenshots...).
	 */
	public function __construct(
		private array $generator_report,
		private array $meta = array()
	) {}

	/**
	 * Full report payload.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$fidelity = $this->fidelity_score();
		return array(
			'job'              => $this->meta['job'] ?? null,
			'title'            => $this->meta['title'] ?? null,
			'mode'             => $this->generator_report['mode'] ?? 'preserve',
			'sections'         => (int) ( $this->generator_report['sections'] ?? 0 ),
			'containers'       => (int) ( $this->generator_report['containers'] ?? 0 ),
			'html_blocks'      => (int) ( $this->generator_report['html_blocks'] ?? 0 ),
			'widgets'          => (int) ( $this->generator_report['widgets'] ?? 0 ),
			'widget_breakdown' => $this->generator_report['widget_breakdown'] ?? array(),
			'screenshots'      => $this->meta['screenshots'] ?? array(),
			'fidelity_score'   => $fidelity,
			'generated_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * A simple 0-100 estimate: preservation keeps the highest fidelity, while
	 * each widget conversion trades a little raw fidelity for widget purity.
	 */
	private function fidelity_score(): int {
		$sections = max( 1, (int) ( $this->generator_report['sections'] ?? 0 ) );
		$widgets  = (int) ( $this->generator_report['widgets'] ?? 0 );
		$ratio    = min( 1.0, $widgets / $sections );
		// Preservation = 100; every fully-converted section costs up to 8 points.
		return (int) round( 100 - ( $ratio * 8 ) );
	}
}
