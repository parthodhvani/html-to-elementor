<?php
/**
 * Design token value object.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Design;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Immutable container for extracted design tokens.
 */
final class DesignTokens {

	/**
	 * @param array<string,mixed> $data Token document.
	 */
	public function __construct( private array $data ) {}

	/**
	 * @param array<string,mixed> $data Raw token data.
	 */
	public static function from_array( array $data ): self {
		return new self( $data );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}

	/**
	 * Primary brand color.
	 */
	public function primary_color(): ?string {
		return $this->data['colors']['primary'] ?? null;
	}

	/**
	 * Typography scale (font sizes).
	 *
	 * @return array<int,string>
	 */
	public function typography_scale(): array {
		return is_array( $this->data['typography']['scale'] ?? null )
			? $this->data['typography']['scale']
			: array();
	}

	/**
	 * Spacing scale in pixels.
	 *
	 * @return array<int,float>
	 */
	public function spacing_scale(): array {
		return is_array( $this->data['spacing']['scale'] ?? null )
			? $this->data['spacing']['scale']
			: array();
	}
}
