<?php
/**
 * Plugin settings store.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around a single options row holding all plugin settings.
 */
final class Settings {

	public const OPTION_KEY = 'h2e_settings';

	/**
	 * Default settings used on first install and as fallbacks.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			// How to reach the Node Chromium service: "cli" (spawn node) or "http".
			'render_mode'        => 'cli',
			'node_binary'        => 'node',
			'service_script'     => '', // Absolute path to chromium-service/cli.js. Auto-detected when empty.
			'service_url'        => 'http://127.0.0.1:8745',
			'service_token'      => '',
			// Conversion behaviour.
			'conversion_mode'    => 'preserve', // "preserve" | "widgets".
			'widget_confidence'  => 95,         // Minimum % confidence to convert a node to a widget.
			'breakpoints'        => array(
				'desktop' => 1280,
				'tablet'  => 768,
				'mobile'  => 375,
			),
			'wait_until'         => 'networkidle0',
			'render_timeout_ms'  => 60000,
			'capture_screenshots'=> true,
			'debug'              => false,
		);
	}

	/**
	 * Install defaults without overwriting an existing configuration.
	 */
	public static function install_defaults(): void {
		$existing = get_option( self::OPTION_KEY, null );
		if ( null === $existing ) {
			add_option( self::OPTION_KEY, self::defaults() );
		} else {
			update_option( self::OPTION_KEY, array_merge( self::defaults(), (array) $existing ) );
		}
	}

	/**
	 * Read the full settings array merged over defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Persist a partial settings update.
	 *
	 * @param array<string,mixed> $values Values to merge.
	 */
	public static function update( array $values ): void {
		update_option( self::OPTION_KEY, array_merge( self::all(), $values ) );
	}
}
