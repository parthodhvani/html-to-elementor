<?php
/**
 * Animation Engine — maps CSS animations to Elementor Motion Effects.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Animation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Converts fade, slide, scale, rotate, opacity, transform, delay, and duration
 * into Elementor motion effect settings when supported.
 */
final class AnimationEngine {

	/**
	 * @param array<string,mixed> $styles Computed styles.
	 * @param string              $css    Combined page CSS for rule lookup.
	 * @return array<string,mixed>
	 */
	public function detect_motion( array $styles, string $css = '' ): array {
		$motion = array();

		$opacity = (float) ( $styles['opacity'] ?? 1 );
		$transform = (string) ( $styles['transform'] ?? '' );
		$transition = (string) ( $styles['transition'] ?? '' );

		if ( $opacity < 1 ) {
			$motion['motion_fx_opacity_effect'] = 'yes';
			$motion['motion_fx_opacity_direction'] = 'in-out';
		}

		if ( '' !== $transform && 'none' !== $transform ) {
			if ( str_contains( $transform, 'scale' ) ) {
				$motion['motion_fx_scale_effect'] = 'yes';
			}
			if ( str_contains( $transform, 'translate' ) ) {
				$motion['motion_fx_translateY_effect'] = 'yes';
			}
			if ( str_contains( $transform, 'rotate' ) ) {
				$motion['_css_unsupported_rotate'] = $transform;
			}
		}

		if ( '' !== $transition && 'none' !== $transition ) {
			$duration = $this->parse_duration( $transition );
			if ( null !== $duration ) {
				$motion['motion_fx_transition_duration'] = $duration;
			}
		}

		if ( '' !== $css && preg_match_all( '/transition(?:-delay)?\s*:\s*([^;]+)/i', $css, $m ) ) {
			foreach ( $m[1] as $val ) {
				$delay = $this->parse_duration( (string) $val );
				if ( null !== $delay && $delay < 1 ) {
					$motion['motion_fx_devices'] = array( 'desktop', 'tablet', 'mobile' );
				}
			}
		}

		return $motion;
	}

	/**
	 * @param string $transition CSS transition value.
	 */
	private function parse_duration( string $transition ): ?float {
		if ( preg_match( '/(\d+(\.\d+)?)\s*ms/', $transition, $m ) ) {
			return round( (float) $m[1] / 1000, 2 );
		}
		if ( preg_match( '/(\d+(\.\d+)?)\s*s/', $transition, $m ) ) {
			return (float) $m[1];
		}
		return null;
	}
}
