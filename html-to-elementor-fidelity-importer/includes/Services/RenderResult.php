<?php
/**
 * Value object wrapping the layout document produced by the Chromium service.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable view over the JSON layout document returned by the Node renderer.
 */
final class RenderResult {

	/**
	 * @param array<string,mixed> $data Decoded layout document.
	 */
	public function __construct( private array $data ) {}

	/**
	 * Build from a decoded JSON array.
	 *
	 * @param array<string,mixed> $data Decoded layout document.
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

	/**
	 * Raw underlying array.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Page title detected by Chromium.
	 */
	public function title(): string {
		return (string) ( $this->data['meta']['title'] ?? '' );
	}

	/**
	 * Detected sections.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function sections(): array {
		return is_array( $this->data['sections'] ?? null ) ? $this->data['sections'] : array();
	}

	/**
	 * Screenshot paths keyed by device.
	 *
	 * @return array<string,string>
	 */
	public function screenshots(): array {
		return is_array( $this->data['screenshots'] ?? null ) ? $this->data['screenshots'] : array();
	}

	/**
	 * Collected document-level CSS/JS asset references.
	 *
	 * @return array<string,mixed>
	 */
	public function assets(): array {
		return is_array( $this->data['assets'] ?? null ) ? $this->data['assets'] : array();
	}

	/**
	 * Engine version (1 = legacy, 2 = visual reconstruction).
	 */
	public function version(): int {
		return (int) ( $this->data['version'] ?? 1 );
	}

	/**
	 * Full visual tree from Chromium extraction.
	 *
	 * @return array<string,mixed>
	 */
	public function visual_tree(): array {
		return is_array( $this->data['visualTree'] ?? null ) ? $this->data['visualTree'] : array();
	}

	/**
	 * Semantic layout graph.
	 *
	 * @return array<string,mixed>
	 */
	public function layout_graph(): array {
		return is_array( $this->data['layoutGraph'] ?? null ) ? $this->data['layoutGraph'] : array();
	}

	/**
	 * Extracted design tokens.
	 *
	 * @return array<string,mixed>
	 */
	public function design_tokens(): array {
		return is_array( $this->data['designTokens'] ?? null ) ? $this->data['designTokens'] : array();
	}

	/**
	 * Extraction statistics.
	 *
	 * @return array<string,mixed>
	 */
	public function stats(): array {
		return is_array( $this->data['stats'] ?? null ) ? $this->data['stats'] : array();
	}
}
