<?php
/**
 * Automatic Repair Engine.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Repair;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatically repairs generated Elementor JSON when fidelity is below threshold.
 */
final class AutomaticRepairEngine {

	/**
	 * Attempt repairs on Elementor data based on validation results.
	 *
	 * @param array<int,array<string,mixed>> $elements   Elementor data.
	 * @param array<string,mixed>            $validation Validation report.
	 * @param array<string,mixed>            $report     Generator report (by reference).
	 * @return array<int,array<string,mixed>>
	 */
	public function repair( array $elements, array $validation, array &$report ): array {
		if ( ( $validation['passes_threshold'] ?? false ) ) {
			return $elements;
		}

		$repaired = $elements;
		$changes  = 0;

		if ( ( $validation['spacing_score'] ?? 0 ) < 80 ) {
			$repaired = $this->repair_spacing( $repaired );
			$changes++;
		}

		if ( ( $validation['typography_score'] ?? 0 ) < 80 ) {
			$repaired = $this->repair_typography( $repaired );
			$changes++;
		}

		if ( ( $validation['html_widget_percent'] ?? 100 ) > 20 ) {
			$repaired = $this->repair_html_widgets( $repaired, $report );
			$changes++;
		}

		$report['repair_iterations'] = (int) ( $report['repair_iterations'] ?? 0 ) + 1;
		$report['repair_changes']    = (int) ( $report['repair_changes'] ?? 0 ) + $changes;

		return $repaired;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_spacing( array $elements ): array {
		foreach ( $elements as &$el ) {
			if ( ( $el['elType'] ?? '' ) !== 'container' ) {
				continue;
			}
			$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
			if ( empty( $settings['padding'] ) ) {
				$settings['padding'] = array(
					'unit' => 'px', 'top' => '16', 'right' => '16',
					'bottom' => '16', 'left' => '16', 'isLinked' => true,
				);
				$el['settings'] = $settings;
			}
		}
		unset( $el );
		return $elements;
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_typography( array $elements ): array {
		foreach ( $elements as &$el ) {
			$this->repair_element_typography( $el );
		}
		unset( $el );
		return $elements;
	}

	/**
	 * @param array<string,mixed> $el Element.
	 */
	private function repair_element_typography( array &$el ): void {
		if ( ( $el['elType'] ?? '' ) === 'widget' ) {
			$settings = is_array( $el['settings'] ?? null ) ? $el['settings'] : array();
			if ( empty( $settings['typography_font_size'] ) && in_array( $el['widgetType'] ?? '', array( 'heading', 'text-editor' ), true ) ) {
				$settings['typography_typography'] = 'custom';
				$settings['typography_font_size']  = array( 'unit' => 'px', 'size' => 'heading' === ( $el['widgetType'] ?? '' ) ? 32 : 16 );
				$el['settings'] = $settings;
			}
		}
		foreach ( is_array( $el['elements'] ?? null ) ? $el['elements'] : array() as &$child ) {
			$this->repair_element_typography( $child );
		}
		unset( $child );
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param array<string,mixed>            $report   Report.
	 * @return array<int,array<string,mixed>>
	 */
	private function repair_html_widgets( array $elements, array &$report ): array {
		foreach ( $elements as &$el ) {
			$this->repair_html_in_element( $el, $report );
		}
		unset( $el );
		return $elements;
	}

	/**
	 * @param array<string,mixed> $el Element.
	 * @param array<string,mixed> $report Report.
	 */
	private function repair_html_in_element( array &$el, array &$report ): void {
		if ( ( $el['elType'] ?? '' ) === 'widget' && 'html' === ( $el['widgetType'] ?? '' ) ) {
			$html = (string) ( $el['settings']['html'] ?? '' );
			if ( preg_match( '/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $html, $m ) ) {
				$el['widgetType'] = 'heading';
				$el['settings']   = array(
					'title'       => wp_strip_all_tags( $m[2] ),
					'header_size' => 'h' . $m[1],
				);
				$report['widgets'] = (int) ( $report['widgets'] ?? 0 ) + 1;
				$report['html_blocks'] = max( 0, (int) ( $report['html_blocks'] ?? 0 ) - 1 );
				$report['widget_breakdown']['heading'] = (int) ( $report['widget_breakdown']['heading'] ?? 0 ) + 1;
			}
		}
		foreach ( is_array( $el['elements'] ?? null ) ? $el['elements'] : array() as &$child ) {
			$this->repair_html_in_element( $child, $report );
		}
		unset( $child );
	}
}
