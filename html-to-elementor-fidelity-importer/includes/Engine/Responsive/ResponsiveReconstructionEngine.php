<?php
/**
 * Responsive Reconstruction Engine.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Responsive;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Infers responsive constraints from multi-breakpoint measurements and
 * generates Elementor responsive control suffixes.
 */
final class ResponsiveReconstructionEngine {

	/** @var array<string,string> */
	private const DEVICE_MAP = array(
		'tablet' => 'tablet',
		'mobile' => 'mobile',
		'w1024'  => 'tablet',
		'w768'   => 'tablet',
		'w480'   => 'mobile',
		'w375'   => 'mobile',
	);

	/**
	 * Apply responsive overrides to container settings.
	 *
	 * @param array<string,mixed> $settings Base settings.
	 * @param array<string,mixed> $section  Section with responsive data.
	 * @return array<string,mixed>
	 */
	public function apply_to_container( array $settings, array $section ): array {
		$responsive = is_array( $section['responsive'] ?? null ) ? $section['responsive'] : array();
		$desktop    = is_array( $responsive['desktop'] ?? null ) ? $responsive['desktop'] : array();
		$desktop_st = is_array( $desktop['styles'] ?? null ) ? $desktop['styles'] : array();

		foreach ( $responsive as $device => $data ) {
			if ( 'desktop' === $device || ! is_array( $data ) ) {
				continue;
			}
			$suffix = self::DEVICE_MAP[ $device ] ?? null;
			if ( null === $suffix ) {
				continue;
			}
			$styles = is_array( $data['styles'] ?? null ) ? $data['styles'] : array();
			$key    = 'padding_' . $suffix;

			$pad_top = $this->px( $styles['paddingTop'] ?? null );
			$desk_top = $this->px( $desktop_st['paddingTop'] ?? null );
			if ( null !== $pad_top && null !== $desk_top && abs( $pad_top - $desk_top ) > 2 ) {
				$settings[ $key ] = array(
					'unit'     => 'px',
					'top'      => (string) round( $pad_top ),
					'right'    => (string) round( $this->px( $styles['paddingRight'] ?? null ) ?? 0 ),
					'bottom'   => (string) round( $this->px( $styles['paddingBottom'] ?? null ) ?? 0 ),
					'left'     => (string) round( $this->px( $styles['paddingLeft'] ?? null ) ?? 0 ),
					'isLinked' => false,
				);
			}

			$font_key = 'typography_font_size_' . $suffix;
			$fs       = $this->px( $styles['fontSize'] ?? null );
			$desk_fs  = $this->px( $desktop_st['fontSize'] ?? null );
			if ( null !== $fs && null !== $desk_fs && abs( $fs - $desk_fs ) > 1 ) {
				$settings[ $font_key ] = array( 'unit' => 'px', 'size' => $fs );
			}

			$dir = (string) ( $styles['flexDirection'] ?? '' );
			$desk_dir = (string) ( $desktop_st['flexDirection'] ?? '' );
			if ( '' !== $dir && $dir !== $desk_dir ) {
				$settings[ 'flex_direction_' . $suffix ] = 'row' === $dir ? 'row' : 'column';
			}
		}

		return $settings;
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
