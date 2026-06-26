<?php
/**
 * Native Widget Mapper — maps recognition results to Elementor widget nodes.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Mapping;

use HtmlToElementor\Elementor\WidgetFactory;
use HtmlToElementor\Engine\Recognition\ConfidenceScore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prefers native Elementor widgets; HTML widget is the absolute last fallback.
 */
final class NativeWidgetMapper {

	private WidgetFactory $widgets;
	private CssMappingEngine $css;

	public function __construct() {
		$this->widgets = new WidgetFactory();
		$this->css     = new CssMappingEngine();
	}

	/**
	 * Map a confidence score to an Elementor widget element.
	 *
	 * @param ConfidenceScore     $score  Recognition result.
	 * @param array<string,mixed> $region Source region for style mapping.
	 * @return array<string,mixed>
	 */
	public function map( ConfidenceScore $score, array $region = array() ): array {
		$styles   = is_array( $region['styles'] ?? null ) ? $region['styles'] : array();
		$mapped   = $this->css->map( $styles );
		$settings = array_merge( $score->settings, $mapped['settings'] );
		return $this->widgets->widget( $score->widget_type, $settings );
	}

	/**
	 * HTML widget fallback — only when no native representation exists.
	 *
	 * @param array<string,mixed> $region Layout region.
	 * @return array<string,mixed>
	 */
	public function fallback_html( array $region ): array {
		$html = (string) ( $region['html'] ?? '' );
		return $this->widgets->html( $html );
	}
}
