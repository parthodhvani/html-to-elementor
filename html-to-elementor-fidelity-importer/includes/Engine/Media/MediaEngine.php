<?php
/**
 * Media Engine — imports images and resolves URLs to Media Library IDs.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Media;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Automatically imports images, SVG, GIF, WebP, and background images.
 */
final class MediaEngine {

	/** @var array<string,int> */
	private array $cache = array();

	/**
	 * Resolve a URL to a media library attachment ID when possible.
	 *
	 * @param string $url Remote or local URL.
	 * @return array{url:string,id:int|string}
	 */
	public function resolve( string $url ): array {
		$url = trim( $url );
		if ( '' === $url ) {
			return array( 'url' => '', 'id' => '' );
		}

		if ( isset( $this->cache[ $url ] ) ) {
			return array( 'url' => $url, 'id' => $this->cache[ $url ] );
		}

		$id = $this->import_if_possible( $url );
		if ( $id > 0 ) {
			$this->cache[ $url ] = $id;
		}

		return array( 'url' => $url, 'id' => $id > 0 ? $id : '' );
	}

	/**
	 * Rewrite image widget settings with media library IDs.
	 *
	 * @param array<string,mixed> $settings Widget settings.
	 * @return array<string,mixed>
	 */
	public function rewrite_widget_settings( array $settings ): array {
		if ( isset( $settings['image'] ) && is_array( $settings['image'] ) ) {
			$url = (string) ( $settings['image']['url'] ?? '' );
			if ( '' !== $url ) {
				$settings['image'] = $this->resolve( $url );
			}
		}
		if ( isset( $settings['background_image'] ) && is_array( $settings['background_image'] ) ) {
			$url = (string) ( $settings['background_image']['url'] ?? '' );
			if ( '' !== $url ) {
				$settings['background_image'] = $this->resolve( $url );
			}
		}
		return $settings;
	}

	/**
	 * @return array<int,string> Missing asset URLs encountered.
	 */
	public function missing_assets(): array {
		return array();
	}

	/**
	 * Import a file into the media library when WordPress functions are available.
	 */
	private function import_if_possible( string $url ): int {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			return 0;
		}
		if ( str_starts_with( $url, 'data:' ) ) {
			return 0;
		}
		if ( str_starts_with( $url, 'file://' ) ) {
			$path = substr( $url, 7 );
			if ( is_readable( $path ) ) {
				return $this->import_local( $path );
			}
		}

		$allowed = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' );
		$ext     = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}

		$file = array(
			'name'     => basename( wp_parse_url( $url, PHP_URL_PATH ) ?? 'image.' . $ext ),
			'tmp_name' => $tmp,
		);
		$id = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 0;
		}
		return (int) $id;
	}

	private function import_local( string $path ): int {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = wp_tempnam( basename( $path ) );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			return 0;
		}
		$file = array( 'name' => basename( $path ), 'tmp_name' => $tmp );
		$id   = media_handle_sideload( $file, 0 );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 0;
		}
		return (int) $id;
	}
}
