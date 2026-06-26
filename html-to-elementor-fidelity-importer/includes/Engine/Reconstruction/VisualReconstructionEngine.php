<?php
/**
 * Visual Reconstruction Engine — primary v2 orchestrator.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Engine\Reconstruction;

use HtmlToElementor\Elementor\ContainerFactory;
use HtmlToElementor\Elementor\ElementId;
use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Elementor\WidgetFactory;
use HtmlToElementor\Engine\Animation\AnimationEngine;
use HtmlToElementor\Engine\Design\DesignTokenExtractor;
use HtmlToElementor\Engine\Layout\ConstraintLayoutEngine;
use HtmlToElementor\Engine\Layout\LayoutGraphEngine;
use HtmlToElementor\Engine\Layout\WrapperEliminator;
use HtmlToElementor\Engine\Mapping\CssMappingEngine;
use HtmlToElementor\Engine\Mapping\NativeWidgetMapper;
use HtmlToElementor\Engine\Media\MediaEngine;
use HtmlToElementor\Engine\Recognition\ComponentRecognitionEngine;
use HtmlToElementor\Engine\Repair\AutomaticRepairEngine;
use HtmlToElementor\Engine\Responsive\ResponsiveReconstructionEngine;
use HtmlToElementor\Engine\Validation\VisualValidationEngine;
use HtmlToElementor\Services\RenderResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transforms a rendered layout document into native Elementor containers and
 * widgets using the full visual reconstruction pipeline.
 */
final class VisualReconstructionEngine {

	private LayoutGraphEngine $layout;
	private WrapperEliminator $wrappers;
	private ConstraintLayoutEngine $constraints;
	private ComponentRecognitionEngine $recognition;
	private NativeWidgetMapper $mapper;
	private ResponsiveReconstructionEngine $responsive;
	private DesignTokenExtractor $tokens;
	private MediaEngine $media;
	private AnimationEngine $animation;
	private CssMappingEngine $css;
	private VisualValidationEngine $validation;
	private AutomaticRepairEngine $repair;
	private ContainerFactory $containers;
	private WidgetFactory $widgets;

	public function __construct() {
		$this->layout      = new LayoutGraphEngine();
		$this->wrappers    = new WrapperEliminator();
		$this->constraints = new ConstraintLayoutEngine();
		$this->recognition = new ComponentRecognitionEngine( 90 );
		$this->mapper      = new NativeWidgetMapper();
		$this->responsive  = new ResponsiveReconstructionEngine();
		$this->tokens      = new DesignTokenExtractor();
		$this->media       = new MediaEngine();
		$this->animation   = new AnimationEngine();
		$this->css         = new CssMappingEngine();
		$this->validation  = new VisualValidationEngine();
		$this->repair      = new AutomaticRepairEngine();
		$this->containers  = new ContainerFactory();
		$this->widgets     = new WidgetFactory();
	}

	/**
	 * Generate Elementor data using the visual reconstruction pipeline.
	 *
	 * @param RenderResult        $result Layout document.
	 * @param array<string,mixed> $opts   Options.
	 * @return array{data:array<int,array<string,mixed>>,report:array<string,mixed>}
	 */
	public function generate( RenderResult $result, array $opts = array() ): array {
		$confidence          = (int) ( $opts['confidence'] ?? 90 );
		$fidelity_threshold  = (float) ( $opts['fidelity_threshold'] ?? 95 );
		$max_repair          = (int) ( $opts['max_repair_iterations'] ?? 3 );
		$this->recognition   = new ComponentRecognitionEngine( $confidence );

		$design_tokens = $this->tokens->extract( $result );
		$token_data    = $design_tokens->to_array();
		$assets        = $result->assets();
		$combined_css  = (string) ( $assets['combinedCss'] ?? '' );

		$report = array(
			'sections'            => 0,
			'containers'          => 0,
			'html_blocks'         => 0,
			'widgets'             => 0,
			'widget_breakdown'    => array(),
			'mode'                => 'reconstruct',
			'engine_version'      => 2,
			'fidelity_threshold'  => $fidelity_threshold,
			'unsupported_css'     => array(),
			'missing_assets'      => array(),
			'repair_iterations'   => 0,
			'repair_changes'      => 0,
			'design_tokens'       => $token_data,
			'elementor_globals'   => $this->tokens->to_elementor_globals( $design_tokens ),
		);

		$elements = array();

		$asset_gen  = new ElementorJsonGenerator();
		$style_html = $asset_gen->build_asset_block( $assets );
		if ( '' !== $style_html && ! empty( $token_data['colors']['all'] ) ) {
			$style_html .= "\n<style>:root {\n";
			foreach ( array_slice( $token_data['colors']['all'], 0, 6 ) as $i => $color ) {
				$style_html .= '  --h2e-color-' . $i . ': ' . esc_attr( (string) $color ) . ";\n";
			}
			$style_html .= "}</style>\n";
		}
		if ( '' !== $style_html ) {
			$elements[] = $this->make_container( array(), array( $this->widgets->html( $style_html ) ) );
			$report['containers']++;
			$report['html_blocks']++;
		}

		$regions = $this->layout->top_level_regions( $result );
		foreach ( $regions as $region ) {
			$report['sections']++;
			$refined = $this->wrappers->eliminate( $region );
			$section_data = $this->find_matching_section( $result, $region );
			$container    = $this->build_region( $refined, $token_data, $combined_css, $report, $section_data );
			$elements[]   = $container;
			$report['containers']++;
		}

		$validation = $this->validation->validate( $result, $elements, $report );
		$report     = array_merge( $report, $validation );

		$iteration = 0;
		while ( ! ( $validation['passes_threshold'] ?? false ) && $iteration < $max_repair ) {
			$before_changes = (int) ( $report['repair_changes'] ?? 0 );
			$elements       = $this->repair->repair( $elements, $validation, $report );
			$validation     = $this->validation->validate( $result, $elements, $report );
			$report         = array_merge( $report, $validation );
			$iteration++;
			if ( (int) ( $report['repair_changes'] ?? 0 ) === $before_changes ) {
				break;
			}
		}

		return array(
			'data'   => $elements,
			'report' => $report,
		);
	}

	/**
	 * @param array<string,mixed> $region Region.
	 * @param array<string,mixed> $tokens Design tokens.
	 * @param string              $css    Combined CSS.
	 * @param array<string,mixed> $report Report (by reference).
	 * @param array<string,mixed> $section_data Matching section for responsive data.
	 * @return array<string,mixed>
	 */
	private function build_region( array $region, array $tokens, string $css, array &$report, array $section_data ): array {
		$children = array();

		if ( $this->layout->is_container_region( $region ) ) {
			$kids = is_array( $region['children'] ?? null ) ? $region['children'] : array();
			if ( ! empty( $kids ) ) {
				foreach ( $kids as $child ) {
					if ( $this->layout->is_container_region( $child ) ) {
						$children[] = $this->build_region( $child, $tokens, $css, $report, $section_data );
						$report['containers']++;
					} else {
						$children[] = $this->build_leaf( $child, $css, $report );
					}
				}
			} else {
				$children[] = $this->build_leaf( $region, $css, $report );
			}
		} else {
			$children[] = $this->build_leaf( $region, $css, $report );
		}

		$settings = $this->constraints->to_container_settings( $region, $tokens );
		$settings = $this->responsive->apply_to_container( $settings, $section_data );
		$motion   = $this->animation->detect_motion(
			is_array( $region['styles'] ?? null ) ? $region['styles'] : array(),
			$css
		);
		$settings = array_merge( $settings, $motion );

		return $this->make_container( $settings, $children );
	}

	/**
	 * @param array<string,mixed> $region Region.
	 * @param string              $css    Combined CSS.
	 * @param array<string,mixed> $report Report.
	 * @return array<string,mixed>
	 */
	private function build_leaf( array $region, string $css, array &$report ): array {
		$score = $this->recognition->classify( $region );
		if ( null !== $score ) {
			$widget = $this->mapper->map( $score, $region );
			$widget['settings'] = $this->media->rewrite_widget_settings( $widget['settings'] );
			$mapped = $this->css->map( is_array( $region['styles'] ?? null ) ? $region['styles'] : array() );
			$widget['settings'] = array_merge( $widget['settings'], $mapped['settings'] );
			$report['unsupported_css'] = array_merge( $report['unsupported_css'], $mapped['unsupported'] );
			$report['widgets']++;
			$type = (string) ( $widget['widgetType'] ?? 'unknown' );
			$report['widget_breakdown'][ $type ] = (int) ( $report['widget_breakdown'][ $type ] ?? 0 ) + 1;
			return $widget;
		}

		$report['html_blocks']++;
		return $this->mapper->fallback_html( $region );
	}

	/**
	 * @param array<string,mixed>              $settings Container settings.
	 * @param array<int,array<string,mixed>>   $children Child elements.
	 * @return array<string,mixed>
	 */
	private function make_container( array $settings, array $children ): array {
		return array(
			'id'       => ElementId::generate(),
			'elType'   => 'container',
			'settings' => $settings,
			'elements' => array_values( $children ),
			'isInner'  => false,
		);
	}

	/**
	 * @param RenderResult        $result Render result.
	 * @param array<string,mixed> $region Layout region.
	 * @return array<string,mixed>
	 */
	private function find_matching_section( RenderResult $result, array $region ): array {
		$graph_id = (string) ( $region['id'] ?? '' );
		foreach ( $result->sections() as $section ) {
			if ( ( $section['graphId'] ?? '' ) === $graph_id ) {
				return $section;
			}
		}
		return array(
			'responsive' => array(),
			'styles'     => is_array( $region['styles'] ?? null ) ? $region['styles'] : array(),
			'bbox'       => is_array( $region['bbox'] ?? null ) ? $region['bbox'] : array(),
		);
	}
}
