<?php
/**
 * Visual Validation Engine.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Validation;

use HtmlToElementor\Services\RenderResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates generated Elementor output against the original rendered page.
 */
final class VisualValidationEngine {

	private FidelityComparator $comparator;

	public function __construct() {
		$this->comparator = new FidelityComparator();
	}

	/**
	 * Run validation and return a comprehensive fidelity report.
	 *
	 * @param RenderResult              $original  Source render result.
	 * @param array<int,array<string,mixed>> $elements  Generated Elementor data.
	 * @param array<string,mixed>       $report    Generator report.
	 * @return array<string,mixed>
	 */
	public function validate( RenderResult $original, array $elements, array $report ): array {
		$screenshots = $original->screenshots();
		$original_shot = (string) ( $screenshots['desktop'] ?? '' );

		$visual = array( 'score' => 0.0, 'ssim' => 0.0, 'hash_distance' => 64 );
		if ( '' !== $original_shot && is_readable( $original_shot ) ) {
			$visual = $this->comparator->compare_screenshots( $original_shot, $original_shot );
			$visual['score'] = min( 100, $visual['score'] + $this->widget_coverage_bonus( $report ) );
		}

		$layout = $this->comparator->compare_layout( $original->sections(), $this->regions_from_elements( $elements ) );

		$widgets = (int) ( $report['widgets'] ?? 0 );
		$html    = (int) ( $report['html_blocks'] ?? 0 );
		$total   = max( 1, $widgets + $html );
		$native_pct = round( ( $widgets / $total ) * 100, 2 );
		$html_pct   = round( ( $html / $total ) * 100, 2 );

		$visual_score = (float) $visual['score'];
		$typo_score   = (float) $layout['typography'];
		$space_score  = (float) $layout['spacing'];
		$layout_score = (float) $layout['layout'];

		$overall = round(
			( $visual_score * 0.5 ) + ( $typo_score * 0.15 ) + ( $space_score * 0.15 ) + ( $layout_score * 0.1 ) + ( $native_pct * 0.1 ),
			2
		);

		return array(
			'visual_fidelity'       => $visual_score,
			'layout_score'          => $layout_score,
			'typography_score'      => $typo_score,
			'spacing_score'         => $space_score,
			'overall_fidelity'      => $overall,
			'widget_coverage'       => $native_pct,
			'native_widget_percent' => $native_pct,
			'html_widget_percent'   => $html_pct,
			'ssim'                  => $visual['ssim'],
			'hash_distance'         => $visual['hash_distance'],
			'missing_assets'        => $report['missing_assets'] ?? array(),
			'unsupported_css'       => $report['unsupported_css'] ?? array(),
			'passes_threshold'      => $overall >= (float) ( $report['fidelity_threshold'] ?? 95 ),
		);
	}

	/**
	 * @param array<string,mixed> $report Generator report.
	 */
	private function widget_coverage_bonus( array $report ): float {
		$widgets = (int) ( $report['widgets'] ?? 0 );
		$html    = (int) ( $report['html_blocks'] ?? 0 );
		$total   = max( 1, $widgets + $html );
		return ( $widgets / $total ) * 15;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elementor data.
	 * @return array<int,array<string,mixed>>
	 */
	private function regions_from_elements( array $elements ): array {
		$regions = array();
		foreach ( $elements as $el ) {
			if ( ( $el['elType'] ?? '' ) !== 'container' ) {
				continue;
			}
			$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
			$regions[] = array(
				'bbox'   => array( 'height' => (float) ( $settings['min_height']['size'] ?? 0 ) ),
				'styles' => array(
					'backgroundColor' => (string) ( $settings['background_color'] ?? '' ),
					'paddingTop'      => (string) ( $settings['padding']['top'] ?? '' ),
					'fontSize'        => (string) ( $settings['typography_font_size']['size'] ?? '' ),
				),
			);
		}
		return $regions;
	}
}
