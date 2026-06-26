<?php
/**
 * CSS Mapping Engine — maps computed CSS to Elementor controls.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts computed style dictionaries into Elementor widget/container settings.
 * Inline CSS is only emitted for unsupported properties.
 */
final class CssMappingEngine {

	/**
	 * @param array<string,mixed> $styles Computed styles.
	 * @return array{settings:array<string,mixed>,unsupported:array<int,string>}
	 */
	public function map( array $styles ): array {
		$settings     = array();
		$unsupported  = array();

		$color = (string) ( $styles['color'] ?? '' );
		if ( '' !== $color ) {
			$settings['title_color'] = $color;
			$settings['text_color']  = $color;
		}

		$size = $this->px( $styles['fontSize'] ?? null );
		if ( null !== $size ) {
			$settings['typography_typography'] = 'custom';
			$settings['typography_font_size']  = array( 'unit' => 'px', 'size' => $size );
		}

		$family = (string) ( $styles['fontFamily'] ?? '' );
		if ( '' !== $family ) {
			$settings['typography_typography']    = 'custom';
			$settings['typography_font_family']   = $family;
		}

		$weight = (string) ( $styles['fontWeight'] ?? '' );
		if ( '' !== $weight && is_numeric( $weight ) ) {
			$settings['typography_font_weight'] = $weight;
		}

		$align = (string) ( $styles['textAlign'] ?? '' );
		if ( in_array( $align, array( 'left', 'center', 'right', 'justify' ), true ) ) {
			$settings['align'] = $align;
		}

		$radius = $this->px( $styles['borderRadius'] ?? null );
		if ( null !== $radius && $radius > 0 ) {
			$settings['border_radius'] = array(
				'unit'     => 'px',
				'top'      => (string) $radius,
				'right'    => (string) $radius,
				'bottom'   => (string) $radius,
				'left'     => (string) $radius,
				'isLinked' => true,
			);
		}

		$shadow = (string) ( $styles['boxShadow'] ?? '' );
		if ( '' !== $shadow && 'none' !== $shadow ) {
			$settings['box_shadow_box_shadow_type'] = 'yes';
			$settings['_css_unsupported_boxShadow'] = $shadow;
			$unsupported[] = 'boxShadow';
		}

		$transform = (string) ( $styles['transform'] ?? '' );
		if ( '' !== $transform && 'none' !== $transform ) {
			$settings['_css_unsupported_transform'] = $transform;
			$unsupported[] = 'transform';
		}

		return array( 'settings' => $settings, 'unsupported' => $unsupported );
	}

	/**
	 * @param mixed $value CSS value.
	 */
	private function px( $value ): ?float {
		if ( null === $value ) {
			return null;
		}
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		if ( is_string( $value ) && preg_match( '/(-?\d+(\.\d+)?)/', $value, $m ) ) {
			return (float) $m[1];
		}
		return null;
	}
}
