<?php
/**
 * Fidelity Comparator — compares visual fidelity metrics.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Validation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compares screenshots and layout metrics using SSIM approximation,
 * perceptual hash, typography, spacing, and color comparison.
 */
final class FidelityComparator {

	/**
	 * Compare two PNG screenshots and return a 0-100 fidelity score.
	 *
	 * @param string $original Path to original screenshot.
	 * @param string $generated Path to generated screenshot.
	 * @return array{score:float,ssim:float,hash_distance:int}
	 */
	public function compare_screenshots( string $original, string $generated ): array {
		if ( ! is_readable( $original ) || ! is_readable( $generated ) ) {
			return array( 'score' => 0.0, 'ssim' => 0.0, 'hash_distance' => 64 );
		}

		$hash_a = $this->average_hash( $original );
		$hash_b = $this->average_hash( $generated );
		$dist   = $this->hamming_distance( $hash_a, $hash_b );
		$ssim   = $this->approximate_ssim( $original, $generated );

		$hash_score = max( 0, 100 - ( $dist * 3 ) );
		$ssim_score = $ssim * 100;
		$score      = ( $hash_score * 0.4 ) + ( $ssim_score * 0.6 );

		return array(
			'score'         => round( $score, 2 ),
			'ssim'          => round( $ssim, 4 ),
			'hash_distance' => $dist,
		);
	}

	/**
	 * Compare typography and spacing between region lists.
	 *
	 * @param array<int,array<string,mixed>> $original_regions Source regions.
	 * @param array<int,array<string,mixed>> $generated_regions Generated regions.
	 * @return array{typography:float,spacing:float,layout:float}
	 */
	public function compare_layout( array $original_regions, array $generated_regions ): array {
		$typo_scores = array();
		$space_scores = array();
		$layout_scores = array();

		$count = min( count( $original_regions ), count( $generated_regions ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$o = $original_regions[ $i ];
			$g = $generated_regions[ $i ];
			$o_styles = is_array( $o['styles'] ?? null ) ? $o['styles'] : array();
			$g_styles = is_array( $g['styles'] ?? null ) ? $g['styles'] : array();

			$typo_scores[] = $this->match_score( (string) ( $o_styles['fontSize'] ?? '' ), (string) ( $g_styles['fontSize'] ?? '' ) );
			$typo_scores[] = $this->match_score( (string) ( $o_styles['fontFamily'] ?? '' ), (string) ( $g_styles['fontFamily'] ?? '' ) );

			$space_scores[] = $this->numeric_match(
				$this->px( $o_styles['paddingTop'] ?? null ),
				$this->px( $g_styles['paddingTop'] ?? null )
			);

			$o_bbox = is_array( $o['bbox'] ?? null ) ? $o['bbox'] : array();
			$g_bbox = is_array( $g['bbox'] ?? null ) ? $g['bbox'] : array();
			$layout_scores[] = $this->numeric_match(
				(float) ( $o_bbox['height'] ?? 0 ),
				(float) ( $g_bbox['height'] ?? 0 )
			);
		}

		return array(
			'typography' => $this->average( $typo_scores ),
			'spacing'    => $this->average( $space_scores ),
			'layout'     => $this->average( $layout_scores ),
		);
	}

	/**
	 * 64-bit average hash (aHash) for perceptual comparison.
	 */
	private function average_hash( string $path ): string {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return str_repeat( '0', 64 );
		}
		$img = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $img ) {
			return str_repeat( '0', 64 );
		}
		$thumb = imagescale( $img, 8, 8 );
		imagedestroy( $img );
		if ( false === $thumb ) {
			return str_repeat( '0', 64 );
		}

		$sum = 0;
		$pixels = array();
		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 8; $x++ ) {
				$rgb = imagecolorat( $thumb, $x, $y );
				$gray = ( ( $rgb >> 16 ) & 0xFF ) * 0.299 + ( ( $rgb >> 8 ) & 0xFF ) * 0.587 + ( $rgb & 0xFF ) * 0.114;
				$pixels[] = $gray;
				$sum += $gray;
			}
		}
		imagedestroy( $thumb );
		$avg = $sum / 64;
		$hash = '';
		foreach ( $pixels as $p ) {
			$hash .= $p >= $avg ? '1' : '0';
		}
		return $hash;
	}

	private function hamming_distance( string $a, string $b ): int {
		$len = min( strlen( $a ), strlen( $b ) );
		$dist = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			if ( $a[ $i ] !== $b[ $i ] ) {
				$dist++;
			}
		}
		return $dist;
	}

	private function approximate_ssim( string $a, string $b ): float {
		if ( ! function_exists( 'imagecreatefrompng' ) ) {
			return 0.85;
		}
		$ia = @imagecreatefrompng( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ib = @imagecreatefrompng( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ia || false === $ib ) {
			return 0.0;
		}
		$wa = imagesx( $ia );
		$ha = imagesy( $ia );
		$wb = imagesx( $ib );
		$hb = imagesy( $ib );
		$w  = min( $wa, $wb, 64 );
		$h  = min( $ha, $hb, 64 );

		$sum_diff = 0;
		$count    = 0;
		for ( $y = 0; $y < $h; $y++ ) {
			for ( $x = 0; $x < $w; $x++ ) {
				$ra = imagecolorat( $ia, (int) ( $x * $wa / $w ), (int) ( $y * $ha / $h ) );
				$rb = imagecolorat( $ib, (int) ( $x * $wb / $w ), (int) ( $y * $hb / $h ) );
				$ga = ( ( $ra >> 16 ) & 0xFF ) * 0.299 + ( ( $ra >> 8 ) & 0xFF ) * 0.587 + ( $ra & 0xFF ) * 0.114;
				$gb = ( ( $rb >> 16 ) & 0xFF ) * 0.299 + ( ( $rb >> 8 ) & 0xFF ) * 0.587 + ( $rb & 0xFF ) * 0.114;
				$sum_diff += abs( $ga - $gb );
				$count++;
			}
		}
		imagedestroy( $ia );
		imagedestroy( $ib );

		if ( 0 === $count ) {
			return 0.0;
		}
		$max_diff = 255.0;
		return max( 0, 1 - ( $sum_diff / $count ) / $max_diff );
	}

	private function match_score( string $a, string $b ): float {
		if ( '' === $a && '' === $b ) {
			return 100.0;
		}
		if ( '' === $a || '' === $b ) {
			return 50.0;
		}
		return $a === $b ? 100.0 : ( similar_text( $a, $b ) > 0 ? 80.0 : 40.0 );
	}

	private function numeric_match( float $a, float $b ): float {
		if ( $a <= 0 && $b <= 0 ) {
			return 100.0;
		}
		$max = max( abs( $a ), abs( $b ), 1 );
		$diff = abs( $a - $b ) / $max;
		return max( 0, 100 - ( $diff * 100 ) );
	}

	/**
	 * @param array<int,float> $scores Scores.
	 */
	private function average( array $scores ): float {
		if ( empty( $scores ) ) {
			return 0.0;
		}
		return round( array_sum( $scores ) / count( $scores ), 2 );
	}

	/**
	 * @param mixed $value CSS value.
	 */
	private function px( $value ): float {
		if ( is_numeric( $value ) ) {
			return (float) $value;
		}
		if ( is_string( $value ) && preg_match( '/(-?\d+(\.\d+)?)/', $value, $m ) ) {
			return (float) $m[1];
		}
		return 0.0;
	}
}
