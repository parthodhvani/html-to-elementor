<?php
/**
 * Constraint Layout Engine — infers Figma Auto Layout-style constraints.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts margins, padding, and repeated spacing into container constraints.
 */
final class ConstraintLayoutEngine {

	/**
	 * @param array<string,mixed> $region Layout graph region.
	 * @param array<string,mixed> $tokens Design token data.
	 * @return array<string,mixed> Elementor container settings fragment.
	 */
	public function to_container_settings( array $region, array $tokens = array() ): array {
		$layout      = is_array( $region['layout'] ?? null ) ? $region['layout'] : array();
		$constraints = is_array( $region['constraints'] ?? null ) ? $region['constraints'] : array();
		$padding     = is_array( $constraints['padding'] ?? null ) ? $constraints['padding'] : array();
		$margin      = is_array( $constraints['margin'] ?? null ) ? $constraints['margin'] : array();
		$styles      = is_array( $region['styles'] ?? null ) ? $region['styles'] : array();

		$direction = (string) ( $layout['direction'] ?? 'column' );
		$gap       = (float) ( $layout['gap'] ?? 0 );
		if ( $gap <= 0 ) {
			$gap = $this->nearest_token( $gap, $tokens['spacing']['scale'] ?? array() );
		}

		$settings = array(
			'flex_direction' => 'row' === $direction ? 'row' : 'column',
			'flex_gap'       => array(
				'column' => (string) round( $gap ),
				'row'    => (string) round( $gap ),
				'unit'   => 'px',
				'isLinked' => true,
			),
			'content_width' => 'full',
		);

		if ( 'flex-start' !== ( $layout['justify'] ?? 'flex-start' ) ) {
			$settings['flex_justify_content'] = $this->map_justify( (string) ( $layout['justify'] ?? 'flex-start' ) );
		}
		if ( 'stretch' !== ( $layout['align'] ?? 'stretch' ) ) {
			$settings['flex_align_items'] = $this->map_align( (string) ( $layout['align'] ?? 'stretch' ) );
		}

		$pad_top    = (float) ( $padding['top'] ?? 0 );
		$pad_right  = (float) ( $padding['right'] ?? 0 );
		$pad_bottom = (float) ( $padding['bottom'] ?? 0 );
		$pad_left   = (float) ( $padding['left'] ?? 0 );

		if ( $pad_top || $pad_right || $pad_bottom || $pad_left ) {
			$settings['padding'] = array(
				'unit'     => 'px',
				'top'      => (string) round( $pad_top ),
				'right'    => (string) round( $pad_right ),
				'bottom'   => (string) round( $pad_bottom ),
				'left'     => (string) round( $pad_left ),
				'isLinked' => false,
			);
		}

		// Convert outer margins to container padding on parent when possible.
		$mt = (float) ( $margin['top'] ?? 0 );
		$mb = (float) ( $margin['bottom'] ?? 0 );
		if ( $mt > 0 || $mb > 0 ) {
			$settings['margin'] = array(
				'unit'     => 'px',
				'top'      => (string) round( $mt ),
				'right'    => '0',
				'bottom'   => (string) round( $mb ),
				'left'     => '0',
				'isLinked' => false,
			);
		}

		$bg = (string) ( $styles['backgroundColor'] ?? '' );
		if ( '' !== $bg && ! $this->is_transparent( $bg ) ) {
			$settings['background_background'] = 'classic';
			$settings['background_color']      = $bg;
		}

		$bg_img = (string) ( $styles['backgroundImage'] ?? '' );
		if ( '' !== $bg_img && 'none' !== $bg_img ) {
			$settings['background_background'] = 'classic';
			$settings['background_image']      = array( 'url' => $this->extract_url( $bg_img ) );
		}

		$bbox = is_array( $region['bbox'] ?? null ) ? $region['bbox'] : array();
		$h    = (float) ( $bbox['height'] ?? 0 );
		if ( $h > 0 && in_array( $region['type'] ?? '', array( 'hero', 'section' ), true ) ) {
			$settings['min_height'] = array( 'unit' => 'px', 'size' => round( $h, 2 ) );
		}

		return $settings;
	}

	/**
	 * @param float              $value  Raw value.
	 * @param array<int,float>   $scale  Token scale.
	 */
	private function nearest_token( float $value, array $scale ): float {
		if ( empty( $scale ) ) {
			return $value;
		}
		$best = $scale[0];
		$diff = abs( $value - $best );
		foreach ( $scale as $token ) {
			$d = abs( $value - (float) $token );
			if ( $d < $diff ) {
				$diff = $d;
				$best = (float) $token;
			}
		}
		return $best;
	}

	private function map_justify( string $v ): string {
		return match ( $v ) {
			'center'        => 'center',
			'flex-end', 'end' => 'flex-end',
			'space-between' => 'space-between',
			'space-around'  => 'space-around',
			'space-evenly'  => 'space-evenly',
			default         => 'flex-start',
		};
	}

	private function map_align( string $v ): string {
		return match ( $v ) {
			'center'        => 'center',
			'flex-end', 'end' => 'flex-end',
			'flex-start', 'start' => 'flex-start',
			default         => 'stretch',
		};
	}

	private function is_transparent( string $color ): bool {
		$color = strtolower( trim( $color ) );
		if ( 'transparent' === $color || '' === $color ) {
			return true;
		}
		if ( preg_match( '/rgba?\(([^)]+)\)/', $color, $m ) ) {
			$parts = array_map( 'trim', explode( ',', $m[1] ) );
			if ( 4 === count( $parts ) && (float) $parts[3] === 0.0 ) {
				return true;
			}
		}
		return false;
	}

	private function extract_url( string $bg ): string {
		if ( preg_match( '/url\(["\']?([^"\')]+)["\']?\)/', $bg, $m ) ) {
			return $m[1];
		}
		return '';
	}
}
