<?php
/**
 * Imports generated Elementor data into a WordPress page.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Elementor;

use HtmlToElementor\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates or updates a WordPress page carrying Elementor container data.
 */
final class ImportEngine {

	/**
	 * Create a new Elementor page from generated data.
	 *
	 * @param array<int,array<string,mixed>> $data     Elementor _elementor_data array.
	 * @param array<string,mixed>            $args     { title:string, status:string, page_id:int }.
	 * @return int Post ID.
	 *
	 * @throws \RuntimeException When the page cannot be created.
	 */
	public function import( array $data, array $args = array() ): int {
		$title  = (string) ( $args['title'] ?? 'Imported Page' );
		$status = (string) ( $args['status'] ?? 'draft' );
		$existing = (int) ( $args['page_id'] ?? 0 );

		$postarr = array(
			'post_title'   => $title,
			'post_status'  => $status,
			'post_type'    => 'page',
			'post_content' => '', // Elementor stores its content in meta.
		);

		if ( $existing > 0 ) {
			$postarr['ID'] = $existing;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			throw new \RuntimeException( 'Failed to create page: ' . $post_id->get_error_message() );
		}
		$post_id = (int) $post_id;

		$this->apply_elementor_meta( $post_id, $data );

		Logger::debug( 'Imported page', array( 'post_id' => $post_id, 'elements' => count( $data ) ) );

		return $post_id;
	}

	/**
	 * Persist the Elementor meta and flush its CSS cache.
	 *
	 * @param int                            $post_id Target post.
	 * @param array<int,array<string,mixed>> $data    Elementor data.
	 */
	private function apply_elementor_meta( int $post_id, array $data ): void {
		// Elementor expects slash-escaped JSON in this meta key.
		$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $post_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '3.0.0' );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_canvas' );
		update_post_meta( $post_id, '_h2e_imported', 1 );
		update_post_meta( $post_id, '_h2e_imported_at', gmdate( 'c' ) );

		// Ask Elementor to regenerate cached CSS if it is active.
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
			try {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
				if ( $document ) {
					$document->save_type();
				}
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			} catch ( \Throwable $e ) {
				Logger::error( 'Elementor cache flush failed', array( 'error' => $e->getMessage() ) );
			}
		}
	}
}
