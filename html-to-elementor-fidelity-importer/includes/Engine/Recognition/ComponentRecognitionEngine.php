<?php
/**
 * Component Recognition Engine — confidence-based visual classifier.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Recognition;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies layout regions into Elementor widgets using visual appearance,
 * geometry, typography, children, spacing, ARIA roles, and layout context.
 * HTML widgets are only proposed when confidence is below threshold.
 */
final class ComponentRecognitionEngine {

	/**
	 * @param int $min_confidence Minimum confidence (0-100).
	 */
	public function __construct( private int $min_confidence = 95 ) {}

	/**
	 * Classify a layout region.
	 *
	 * @param array<string,mixed> $region Layout graph region.
	 * @param array<string,mixed> $context Parent context.
	 */
	public function classify( array $region, array $context = array() ): ?ConfidenceScore {
		$type = (string) ( $region['type'] ?? 'unknown' );
		$tag  = strtolower( (string) ( $region['tag'] ?? '' ) );
		$kids = is_array( $region['children'] ?? null ) ? $region['children'] : array();

		$candidates = array(
			$this->classify_heading( $region, $tag, $type ),
			$this->classify_text( $region, $tag, $type ),
			$this->classify_image( $region, $tag, $type ),
			$this->classify_button( $region, $tag, $type ),
			$this->classify_list( $region, $tag, $type ),
			$this->classify_video( $region, $tag, $type ),
			$this->classify_divider( $region, $tag, $type ),
			$this->classify_icon_box( $region, $type, $kids ),
			$this->classify_cta( $region, $type, $kids ),
			$this->classify_testimonial( $region, $type ),
			$this->classify_spacer( $region, $type ),
		);

		$best = null;
		foreach ( $candidates as $c ) {
			if ( null === $c ) {
				continue;
			}
			if ( null === $best || $c->confidence > $best->confidence ) {
				$best = $c;
			}
		}

		if ( null === $best || ! $best->passes( $this->min_confidence ) ) {
			return null;
		}
		return $best;
	}

	/**
	 * @param array<string,mixed> $region Region.
	 */
	private function classify_heading( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'heading' !== $type && ! in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ) {
			return null;
		}
		$text = trim( (string) ( $region['text'] ?? $region['innerTextSample'] ?? '' ) );
		if ( '' === $text ) {
			$text = $this->strip_tags( (string) ( $region['html'] ?? '' ) );
		}
		if ( '' === $text ) {
			return null;
		}
		$confidence = count( is_array( $region['children'] ?? null ) ? $region['children'] : array() ) > 0 ? 72 : 97;
		return new ConfidenceScore(
			'heading',
			$confidence,
			array( 'title' => $text, 'header_size' => in_array( $tag, array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' ), true ) ? $tag : 'h2' ),
			'heading typography + tag'
		);
	}

	private function classify_text( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'text' !== $type && 'p' !== $tag ) {
			return null;
		}
		$html = (string) ( $region['html'] ?? '' );
		if ( '' === $html ) {
			return null;
		}
		$confidence = empty( $region['children'] ) ? 96 : 70;
		return new ConfidenceScore(
			'text-editor',
			$confidence,
			array( 'editor' => $this->wrap_paragraph( $html ) ),
			'paragraph text block'
		);
	}

	private function classify_image( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'image' !== $type && ! in_array( $tag, array( 'img', 'picture', 'svg' ), true ) ) {
			return null;
		}
		$src = (string) ( $region['src'] ?? '' );
		if ( '' === $src && preg_match( '/src=["\']([^"\']+)["\']/', (string) ( $region['html'] ?? '' ), $m ) ) {
			$src = $m[1];
		}
		if ( '' === $src ) {
			return null;
		}
		return new ConfidenceScore(
			'image',
			96,
			array(
				'image'      => array( 'url' => $src, 'id' => '' ),
				'image_size' => 'full',
				'alt'        => (string) ( $region['alt'] ?? '' ),
			),
			'image geometry + src'
		);
	}

	private function classify_button( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'button' !== $type && 'button' !== $tag ) {
			$cls = strtolower( (string) ( $region['classes'] ?? '' ) );
			if ( 'a' !== $tag || ( false === strpos( $cls, 'btn' ) && false === strpos( $cls, 'button' ) ) ) {
				return null;
			}
		}
		$text = trim( (string) ( $region['text'] ?? '' ) );
		if ( '' === $text ) {
			$text = $this->strip_tags( (string) ( $region['html'] ?? '' ) );
		}
		if ( '' === $text ) {
			return null;
		}
		$url = '';
		if ( preg_match( '/href=["\']([^"\']+)["\']/', (string) ( $region['html'] ?? '' ), $m ) ) {
			$url = $m[1];
		}
		$settings = array( 'text' => $text );
		if ( '' !== $url ) {
			$settings['link'] = array( 'url' => $url, 'is_external' => '', 'nofollow' => '' );
		}
		return new ConfidenceScore( 'button', 96, $settings, 'button appearance + label' );
	}

	private function classify_list( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'list' !== $type && ! in_array( $tag, array( 'ul', 'ol' ), true ) ) {
			return null;
		}
		$items = array();
		foreach ( is_array( $region['children'] ?? null ) ? $region['children'] : array() as $child ) {
			if ( 'li' !== ( $child['tag'] ?? '' ) ) {
				continue;
			}
			$t = trim( (string) ( $child['text'] ?? '' ) );
			if ( '' !== $t ) {
				$items[] = array( 'text' => $t );
			}
		}
		if ( empty( $items ) ) {
			return null;
		}
		return new ConfidenceScore( 'icon-list', 95, array( 'icon_list' => $items ), 'list structure' );
	}

	private function classify_video( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'media' !== $type && 'video' !== $tag ) {
			return null;
		}
		$src = (string) ( $region['src'] ?? '' );
		if ( '' === $src && preg_match( '/src=["\']([^"\']+)["\']/', (string) ( $region['html'] ?? '' ), $m ) ) {
			$src = $m[1];
		}
		if ( '' === $src ) {
			return null;
		}
		return new ConfidenceScore(
			'video',
			95,
			array( 'video_type' => 'hosted', 'hosted_url' => array( 'url' => $src ) ),
			'video element'
		);
	}

	private function classify_divider( array $region, string $tag, string $type ): ?ConfidenceScore {
		if ( 'divider' !== $type && 'hr' !== $tag ) {
			return null;
		}
		return new ConfidenceScore( 'divider', 95, array(), 'horizontal rule' );
	}

	/**
	 * @param array<int,array<string,mixed>> $kids Children.
	 */
	private function classify_icon_box( array $region, string $type, array $kids ): ?ConfidenceScore {
		if ( 'card' !== $type || count( $kids ) < 2 ) {
			return null;
		}
		$heading = '';
		$body    = '';
		foreach ( $kids as $child ) {
			$ct = (string) ( $child['type'] ?? '' );
			if ( 'heading' === $ct && '' === $heading ) {
				$heading = trim( (string) ( $child['text'] ?? '' ) );
			}
			if ( 'text' === $ct && '' === $body ) {
				$body = trim( (string) ( $child['text'] ?? '' ) );
			}
		}
		if ( '' === $heading ) {
			return null;
		}
		return new ConfidenceScore(
			'icon-box',
			92,
			array(
				'title_text'       => $heading,
				'description_text' => $body,
			),
			'card with heading + text'
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $kids Children.
	 */
	private function classify_cta( array $region, string $type, array $kids ): ?ConfidenceScore {
		if ( 'cta' !== $type ) {
			return null;
		}
		foreach ( $kids as $child ) {
			$btn = $this->classify_button( $child, (string) ( $child['tag'] ?? '' ), (string) ( $child['type'] ?? '' ) );
			if ( null !== $btn && $btn->passes( 90 ) ) {
				return new ConfidenceScore( 'call-to-action', 91, $btn->settings, 'cta block with button' );
			}
		}
		return null;
	}

	private function classify_testimonial( array $region, string $type ): ?ConfidenceScore {
		if ( 'testimonial' !== $type ) {
			return null;
		}
		$text = trim( (string) ( $region['text'] ?? '' ) );
		if ( '' === $text ) {
			return null;
		}
		return new ConfidenceScore(
			'testimonials',
			90,
			array( 'testimonial_content' => $text ),
			'testimonial region'
		);
	}

	private function classify_spacer( array $region, string $type ): ?ConfidenceScore {
		if ( 'whitespace' !== $type ) {
			return null;
		}
		$h = (float) ( $region['bbox']['height'] ?? 20 );
		return new ConfidenceScore(
			'spacer',
			88,
			array( 'space' => array( 'unit' => 'px', 'size' => max( 10, round( $h ) ) ) ),
			'whitespace region'
		);
	}

	private function strip_tags( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}

	private function wrap_paragraph( string $html ): string {
		$html = trim( $html );
		if ( str_starts_with( $html, '<p' ) ) {
			return $html;
		}
		return '<p>' . $html . '</p>';
	}
}
