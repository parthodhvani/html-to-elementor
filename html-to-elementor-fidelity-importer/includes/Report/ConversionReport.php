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
		$widgets  = (int) ( $this->generator_report['widgets'] ?? 0 );
		$html     = (int) ( $this->generator_report['html_blocks'] ?? 0 );
		$total    = max( 1, $widgets + $html );

		return array(
			'job'                   => $this->meta['job'] ?? null,
			'title'                 => $this->meta['title'] ?? null,
			'mode'                  => $this->generator_report['mode'] ?? 'preserve',
			'engine_version'        => (int) ( $this->generator_report['engine_version'] ?? 1 ),
			'sections'              => (int) ( $this->generator_report['sections'] ?? 0 ),
			'containers'            => (int) ( $this->generator_report['containers'] ?? 0 ),
			'html_blocks'           => $html,
			'widgets'               => $widgets,
			'widget_breakdown'      => $this->generator_report['widget_breakdown'] ?? array(),
			'screenshots'           => $this->meta['screenshots'] ?? array(),
			'fidelity_score'        => $fidelity,
			'visual_fidelity'       => $this->generator_report['visual_fidelity'] ?? $fidelity,
			'layout_score'          => $this->generator_report['layout_score'] ?? null,
			'typography_score'      => $this->generator_report['typography_score'] ?? null,
			'spacing_score'         => $this->generator_report['spacing_score'] ?? null,
			'overall_fidelity'      => $this->generator_report['overall_fidelity'] ?? $fidelity,
			'widget_coverage'       => $this->generator_report['widget_coverage'] ?? round( ( $widgets / $total ) * 100, 2 ),
			'native_widget_percent' => $this->generator_report['native_widget_percent'] ?? round( ( $widgets / $total ) * 100, 2 ),
			'html_widget_percent'   => $this->generator_report['html_widget_percent'] ?? round( ( $html / $total ) * 100, 2 ),
			'missing_assets'        => $this->generator_report['missing_assets'] ?? array(),
			'unsupported_css'       => $this->generator_report['unsupported_css'] ?? array(),
			'repair_iterations'     => (int) ( $this->generator_report['repair_iterations'] ?? 0 ),
			'design_tokens'         => $this->generator_report['design_tokens'] ?? null,
			'generated_at'          => gmdate( 'c' ),
		);
	}

	/**
	 * A 0-100 fidelity estimate. Reconstruct mode uses validation metrics when present.
	 */
	private function fidelity_score(): int {
		if ( isset( $this->generator_report['overall_fidelity'] ) ) {
			return (int) round( (float) $this->generator_report['overall_fidelity'] );
		}

		$sections = max( 1, (int) ( $this->generator_report['sections'] ?? 0 ) );
		$widgets  = (int) ( $this->generator_report['widgets'] ?? 0 );
		$ratio    = min( 1.0, $widgets / $sections );
		return (int) round( 100 - ( $ratio * 8 ) );
	}
}
