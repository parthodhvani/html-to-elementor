<?php
/**
 * Wrapper Elimination — PHP-side refinement of layout graph regions.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Layout;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Removes pass-through container regions that carry no visual identity.
 */
final class WrapperEliminator {

	/**
	 * @param array<string,mixed> $region Layout region.
	 * @return array<string,mixed>
	 */
	public function eliminate( array $region ): array {
		$children = array();
		foreach ( is_array( $region['children'] ?? null ) ? $region['children'] : array() as $child ) {
			$children[] = $this->eliminate( $child );
		}
		$region['children'] = $children;

		while ( count( $region['children'] ) === 1 && $this->is_pass_through( $region ) ) {
			$only = $region['children'][0];
			$region = array_merge( $only, array(
				'children' => is_array( $only['children'] ?? null ) ? $only['children'] : array(),
			) );
		}

		return $region;
	}

	/**
	 * @param array<string,mixed> $region Region.
	 */
	private function is_pass_through( array $region ): bool {
		$type = (string) ( $region['type'] ?? '' );
		if ( in_array( $type, array( 'hero', 'section', 'navigation', 'footer', 'form', 'card' ), true ) ) {
			return false;
		}
		$styles = is_array( $region['styles'] ?? null ) ? $region['styles'] : array();
		$bg     = (string) ( $styles['backgroundColor'] ?? '' );
		if ( '' !== $bg && 'transparent' !== $bg && 'rgba(0, 0, 0, 0)' !== $bg ) {
			return false;
		}
		$bg_img = (string) ( $styles['backgroundImage'] ?? '' );
		if ( '' !== $bg_img && 'none' !== $bg_img ) {
			return false;
		}
		$text = trim( (string) ( $region['text'] ?? '' ) );
		if ( '' !== $text ) {
			return false;
		}
		return 'content_group' === $type || 'unknown' === $type || 'stack' === $type;
	}
}
