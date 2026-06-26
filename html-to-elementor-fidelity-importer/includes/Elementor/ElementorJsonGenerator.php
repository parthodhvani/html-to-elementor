<?php
/**
 * Converts a Chromium layout document into valid Elementor _elementor_data.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Services\RenderResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The JSON generator. Walks the segmented sections and emits one Elementor
 * container per section. By default each container holds a single HTML widget
 * with the section's original markup (preservation mode). When widget mode is
 * enabled, sections that map to a widget with >= the confidence threshold are
 * converted; everything else stays as original HTML.
 */
final class ElementorJsonGenerator {

	private ContainerFactory $containers;
	private WidgetFactory $widgets;

	public function __construct() {
		$this->containers = new ContainerFactory();
		$this->widgets    = new WidgetFactory();
	}

	/**
	 * Generate Elementor data plus a structured report.
	 *
	 * @param RenderResult        $result Layout document.
	 * @param array<string,mixed> $opts   { mode: "preserve"|"widgets", confidence:int }.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	public function generate( RenderResult $result, array $opts = array() ): array {
		$mode       = (string) ( $opts['mode'] ?? 'preserve' );
		$confidence = (int) ( $opts['confidence'] ?? 95 );
		$detector   = new WidgetDetector( $confidence );

		$elements = array();
		$report   = array(
			'sections'        => 0,
			'containers'      => 0,
			'html_blocks'     => 0,
			'widgets'         => 0,
			'widget_breakdown'=> array(),
			'mode'            => $mode,
		);

		// Global CSS/JS preservation block injected once at the top so every
		// section's original markup renders with its original styling.
		$assets    = $result->assets();
		$style_html = $this->build_asset_block( $assets );
		if ( '' !== $style_html ) {
			$elements[] = $this->containers->section(
				array( 'styles' => array(), 'bbox' => array() ),
				array( $this->widgets->html( $style_html ) )
			);
			$report['containers']++;
			$report['html_blocks']++;
		}

		foreach ( $result->sections() as $section ) {
			$report['sections']++;
			$html = (string) ( $section['html'] ?? '' );

			$child = null;
			if ( 'widgets' === $mode ) {
				$detected = $detector->detect( $html );
				if ( null !== $detected ) {
					$child = $this->widgets->widget( $detected['type'], $detected['settings'] );
					$report['widgets']++;
					$report['widget_breakdown'][ $detected['type'] ] =
						( $report['widget_breakdown'][ $detected['type'] ] ?? 0 ) + 1;
				}
			}

			if ( null === $child ) {
				$child = $this->widgets->html( $html );
				$report['html_blocks']++;
			}

			$elements[] = $this->containers->section( $section, array( $child ) );
			$report['containers']++;
		}

		return array(
			'data'   => $elements,
			'report' => $report,
		);
	}

	/**
	 * Build a single HTML blob that re-injects collected CSS and JS so the
	 * preserved markup renders identically to the source.
	 *
	 * @param array<string,mixed> $assets Asset bundle from the renderer.
	 */
	public function build_asset_block( array $assets ): string {
		$out = '';

		foreach ( (array) ( $assets['stylesheets'] ?? array() ) as $href ) {
			$href = esc_url( (string) $href );
			if ( '' !== $href ) {
				$out .= '<link rel="stylesheet" href="' . $href . '" />' . "\n";
			}
		}

		$css = (string) ( $assets['combinedCss'] ?? '' );
		if ( '' !== trim( $css ) ) {
			$out .= '<style>' . "\n" . $css . "\n" . '</style>' . "\n";
		}

		$js = (string) ( $assets['combinedJs'] ?? '' );
		if ( '' !== trim( $js ) ) {
			$out .= '<script>' . "\n" . $js . "\n" . '</script>' . "\n";
		}

		return $out;
	}
}
