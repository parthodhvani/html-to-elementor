<?php
/**
 * Layout Graph Engine — refines and navigates the semantic layout graph.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Layout;

use HtmlToElementor\Services\RenderResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides access to the layout graph and region traversal utilities.
 */
final class LayoutGraphEngine {

	/**
	 * @return array<string,mixed>|null
	 */
	public function root( RenderResult $result ): ?array {
		$graph = $result->layout_graph();
		return is_array( $graph['root'] ?? null ) ? $graph['root'] : null;
	}

	/**
	 * Top-level page regions.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function top_level_regions( RenderResult $result ): array {
		$root = $this->root( $result );
		if ( null === $root ) {
			return $this->sections_as_regions( $result );
		}
		$children = is_array( $root['children'] ?? null ) ? $root['children'] : array();
		return ! empty( $children ) ? $children : array( $root );
	}

	/**
	 * Flatten all regions depth-first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function flatten( array $region ): array {
		$out   = array( $region );
		$kids  = is_array( $region['children'] ?? null ) ? $region['children'] : array();
		foreach ( $kids as $child ) {
			$out = array_merge( $out, $this->flatten( $child ) );
		}
		return $out;
	}

	/**
	 * Whether a region should be rendered as a nested container.
	 *
	 * @param array<string,mixed> $region Region.
	 */
	public function is_container_region( array $region ): bool {
		$type = (string) ( $region['type'] ?? 'unknown' );
		$kids = is_array( $region['children'] ?? null ) ? $region['children'] : array();

		if ( in_array( $type, array( 'heading', 'text', 'button', 'image', 'list', 'divider', 'media' ), true ) ) {
			return false;
		}
		return count( $kids ) > 0 || in_array( $type, array(
			'section', 'row', 'column', 'stack', 'card', 'grid', 'hero', 'cta',
			'navigation', 'footer', 'feature_grid', 'content_group',
		), true );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sections_as_regions( RenderResult $result ): array {
		$regions = array();
		foreach ( $result->sections() as $section ) {
			$regions[] = array(
				'id'       => 's' . ( $section['index'] ?? count( $regions ) ),
				'type'     => $section['type'] ?? 'section',
				'tag'      => $section['tag'] ?? 'div',
				'html'     => $section['html'] ?? '',
				'bbox'     => $section['bbox'] ?? array(),
				'styles'   => $section['styles'] ?? array(),
				'children' => array(),
				'layout'   => $section['layout'] ?? array(),
			);
		}
		return $regions;
	}
}
