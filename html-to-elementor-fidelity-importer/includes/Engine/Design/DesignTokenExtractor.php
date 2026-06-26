<?php
/**
 * Design Token Extractor — refines Node-extracted tokens for Elementor globals.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Design;

use HtmlToElementor\Services\RenderResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes design tokens and maps them to Elementor global style candidates.
 */
final class DesignTokenExtractor {

	/**
	 * Extract design tokens from a render result.
	 */
	public function extract( RenderResult $result ): DesignTokens {
		$raw = $result->design_tokens();
		if ( empty( $raw ) ) {
			$raw = $this->extract_from_sections( $result );
		}
		return DesignTokens::from_array( $this->normalize( $raw ) );
	}

	/**
	 * Build Elementor kit-style global settings from tokens.
	 *
	 * @return array<string,mixed>
	 */
	public function to_elementor_globals( DesignTokens $tokens ): array {
		$data   = $tokens->to_array();
		$colors = $data['colors'] ?? array();
		$typo   = $data['typography'] ?? array();

		$globals = array();
		if ( ! empty( $colors['primary'] ) ) {
			$globals['system_colors'] = array(
				array(
					'_id'   => 'primary',
					'title' => 'Primary',
					'color' => (string) $colors['primary'],
				),
			);
			if ( ! empty( $colors['secondary'] ) ) {
				$globals['system_colors'][] = array(
					'_id'   => 'secondary',
					'title' => 'Secondary',
					'color' => (string) $colors['secondary'],
				);
			}
			if ( ! empty( $colors['accent'] ) ) {
				$globals['system_colors'][] = array(
					'_id'   => 'accent',
					'title' => 'Accent',
					'color' => (string) $colors['accent'],
				);
			}
		}

		$families = $typo['families'] ?? array();
		if ( ! empty( $families[0] ) ) {
			$globals['system_typography'] = array(
				array(
					'_id'               => 'primary',
					'title'             => 'Primary',
					'typography_font_family' => (string) $families[0],
				),
			);
		}

		return $globals;
	}

	/**
	 * @param array<string,mixed> $raw Raw tokens.
	 * @return array<string,mixed>
	 */
	private function normalize( array $raw ): array {
		return array(
			'colors'     => is_array( $raw['colors'] ?? null ) ? $raw['colors'] : array(),
			'typography' => is_array( $raw['typography'] ?? null ) ? $raw['typography'] : array(),
			'spacing'    => is_array( $raw['spacing'] ?? null ) ? $raw['spacing'] : array(),
			'radius'     => is_array( $raw['radius'] ?? null ) ? $raw['radius'] : array(),
			'shadows'    => is_array( $raw['shadows'] ?? null ) ? $raw['shadows'] : array(),
			'containers' => is_array( $raw['containers'] ?? null ) ? $raw['containers'] : array(),
		);
	}

	/**
	 * Fallback token extraction from section styles when v1 layout is used.
	 *
	 * @return array<string,mixed>
	 */
	private function extract_from_sections( RenderResult $result ): array {
		$colors = array();
		$sizes  = array();
		foreach ( $result->sections() as $section ) {
			$styles = is_array( $section['styles'] ?? null ) ? $section['styles'] : array();
			foreach ( array( 'color', 'backgroundColor' ) as $key ) {
				if ( ! empty( $styles[ $key ] ) ) {
					$colors[] = (string) $styles[ $key ];
				}
			}
			if ( ! empty( $styles['fontSize'] ) ) {
				$sizes[] = (string) $styles['fontSize'];
			}
		}
		$colors = array_values( array_unique( $colors ) );
		return array(
			'colors'     => array(
				'primary'   => $colors[0] ?? null,
				'secondary' => $colors[1] ?? null,
				'accent'    => $colors[2] ?? null,
				'neutral'   => array_slice( $colors, 3 ),
				'all'       => $colors,
			),
			'typography' => array( 'scale' => array_values( array_unique( $sizes ) ) ),
			'spacing'    => array( 'scale' => array() ),
			'radius'     => array( 'scale' => array() ),
			'shadows'    => array( 'scale' => array() ),
			'containers' => array( 'widths' => array() ),
		);
	}
}
