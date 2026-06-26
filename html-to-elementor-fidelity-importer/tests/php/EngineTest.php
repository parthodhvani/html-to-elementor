<?php
/**
 * Tests for v2 visual reconstruction engines.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Tests;

use HtmlToElementor\Services\RenderResult;
use HtmlToElementor\Engine\Design\DesignTokenExtractor;
use HtmlToElementor\Engine\Layout\ConstraintLayoutEngine;
use HtmlToElementor\Engine\Layout\LayoutGraphEngine;
use HtmlToElementor\Engine\Layout\WrapperEliminator;
use HtmlToElementor\Engine\Mapping\CssMappingEngine;
use HtmlToElementor\Engine\Recognition\ComponentRecognitionEngine;
use HtmlToElementor\Engine\Responsive\ResponsiveReconstructionEngine;
use HtmlToElementor\Engine\Animation\AnimationEngine;
use HtmlToElementor\Engine\Validation\FidelityComparator;
use HtmlToElementor\Engine\Repair\AutomaticRepairEngine;
use HtmlToElementor\Engine\Reconstruction\VisualReconstructionEngine;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase {

	/**
	 * @return array<string,mixed>
	 */
	private function v2_layout(): array {
		return array(
			'version'      => 2,
			'meta'         => array( 'title' => 'V2 Sample' ),
			'assets'       => array( 'combinedCss' => '.hero{color:#fff}' ),
			'designTokens' => array(
				'colors'     => array( 'primary' => 'rgb(13, 71, 161)', 'all' => array( 'rgb(13, 71, 161)' ) ),
				'typography' => array( 'scale' => array( '44px', '18px' ) ),
				'spacing'    => array( 'scale' => array( 24, 64 ) ),
			),
			'layoutGraph'  => array(
				'root' => array(
					'id'       => 'v1',
					'type'     => 'section',
					'tag'      => 'body',
					'children' => array(
						array(
							'id'       => 'v2',
							'type'     => 'hero',
							'tag'      => 'header',
							'html'     => '<header class="hero"><h1>Hello</h1></header>',
							'bbox'     => array( 'height' => 400, 'width' => 1280 ),
							'styles'   => array( 'backgroundColor' => 'rgb(13, 71, 161)', 'paddingTop' => '80px' ),
							'children' => array(
								array(
									'id'     => 'v3',
									'type'   => 'heading',
									'tag'    => 'h1',
									'text'   => 'Hello',
									'html'   => '<h1>Hello</h1>',
									'styles' => array( 'fontSize' => '44px' ),
									'children' => array(),
								),
							),
							'layout'      => array( 'direction' => 'column', 'gap' => 16 ),
							'constraints' => array( 'padding' => array( 'top' => 80, 'bottom' => 80 ) ),
						),
						array(
							'id'       => 'v4',
							'type'     => 'grid',
							'tag'      => 'div',
							'classes'  => 'features',
							'html'     => '<div class="features">...</div>',
							'bbox'     => array( 'height' => 300 ),
							'styles'   => array( 'display' => 'flex' ),
							'children' => array(
								array(
									'id'       => 'v5',
									'type'     => 'card',
									'tag'      => 'div',
									'classes'  => 'card',
									'children' => array(
										array( 'id' => 'v6', 'type' => 'heading', 'tag' => 'h3', 'text' => 'Fast', 'children' => array() ),
										array( 'id' => 'v7', 'type' => 'text', 'tag' => 'p', 'text' => 'Quick.', 'html' => '<p>Quick.</p>', 'children' => array() ),
									),
								),
							),
							'layout' => array( 'direction' => 'row', 'gap' => 24 ),
						),
					),
				),
			),
			'sections' => array(
				array(
					'index'   => 0,
					'graphId' => 'v2',
					'tag'     => 'header',
					'html'    => '<header class="hero"><h1>Hello</h1></header>',
					'bbox'    => array( 'height' => 400 ),
					'styles'  => array( 'backgroundColor' => 'rgb(13, 71, 161)' ),
					'responsive' => array(
						'desktop' => array( 'styles' => array( 'paddingTop' => '80px', 'fontSize' => '44px' ) ),
						'tablet'  => array( 'styles' => array( 'paddingTop' => '48px', 'fontSize' => '36px' ) ),
					),
				),
			),
			'screenshots' => array(),
		);
	}

	public function test_design_token_extractor_reads_v2_tokens(): void {
		$extractor = new DesignTokenExtractor();
		$tokens    = $extractor->extract( RenderResult::from_array( $this->v2_layout() ) );
		$this->assertSame( 'rgb(13, 71, 161)', $tokens->primary_color() );
		$this->assertContains( '44px', $tokens->typography_scale() );
	}

	public function test_constraint_layout_engine_maps_flex_gap_and_padding(): void {
		$engine = new ConstraintLayoutEngine();
		$region = $this->v2_layout()['layoutGraph']['root']['children'][1];
		$settings = $engine->to_container_settings( $region, $this->v2_layout()['designTokens'] );
		$this->assertSame( 'row', $settings['flex_direction'] );
		$this->assertSame( '24', $settings['flex_gap']['column'] );
	}

	public function test_layout_graph_engine_returns_top_level_regions(): void {
		$engine  = new LayoutGraphEngine();
		$regions = $engine->top_level_regions( RenderResult::from_array( $this->v2_layout() ) );
		$this->assertCount( 2, $regions );
		$this->assertSame( 'hero', $regions[0]['type'] );
	}

	public function test_wrapper_eliminator_hoists_pass_through_nodes(): void {
		$eliminator = new WrapperEliminator();
		$region     = array(
			'type'     => 'content_group',
			'styles'   => array( 'backgroundColor' => 'transparent' ),
			'text'     => '',
			'children' => array(
				array(
					'type'     => 'heading',
					'tag'      => 'h2',
					'text'     => 'Title',
					'children' => array(),
				),
			),
		);
		$result = $eliminator->eliminate( $region );
		$this->assertSame( 'heading', $result['type'] );
	}

	public function test_component_recognition_classifies_heading_with_high_confidence(): void {
		$engine = new ComponentRecognitionEngine( 90 );
		$score  = $engine->classify( array(
			'type' => 'heading',
			'tag'  => 'h1',
			'text' => 'Hello World',
			'children' => array(),
		) );
		$this->assertNotNull( $score );
		$this->assertSame( 'heading', $score->widget_type );
		$this->assertGreaterThanOrEqual( 90, $score->confidence );
	}

	public function test_component_recognition_classifies_card_as_icon_box(): void {
		$engine = new ComponentRecognitionEngine( 90 );
		$card   = $this->v2_layout()['layoutGraph']['root']['children'][1]['children'][0];
		$score  = $engine->classify( $card );
		$this->assertNotNull( $score );
		$this->assertSame( 'icon-box', $score->widget_type );
	}

	public function test_css_mapping_engine_maps_typography(): void {
		$engine = new CssMappingEngine();
		$result = $engine->map( array( 'fontSize' => '18px', 'color' => '#333', 'textAlign' => 'center' ) );
		$this->assertSame( 18.0, $result['settings']['typography_font_size']['size'] );
		$this->assertSame( 'center', $result['settings']['align'] );
	}

	public function test_responsive_engine_applies_tablet_overrides(): void {
		$engine   = new ResponsiveReconstructionEngine();
		$section  = $this->v2_layout()['sections'][0];
		$settings = $engine->apply_to_container( array(), $section );
		$this->assertArrayHasKey( 'padding_tablet', $settings );
		$this->assertSame( '48', $settings['padding_tablet']['top'] );
	}

	public function test_animation_engine_detects_opacity_motion(): void {
		$engine = new AnimationEngine();
		$motion = $engine->detect_motion( array( 'opacity' => '0.8', 'transform' => 'scale(1.05)' ) );
		$this->assertSame( 'yes', $motion['motion_fx_opacity_effect'] );
		$this->assertSame( 'yes', $motion['motion_fx_scale_effect'] );
	}

	public function test_fidelity_comparator_scores_identical_layout(): void {
		$comparator = new FidelityComparator();
		$regions    = array( array( 'bbox' => array( 'height' => 400 ), 'styles' => array( 'fontSize' => '44px', 'paddingTop' => '80px' ) ) );
		$scores     = $comparator->compare_layout( $regions, $regions );
		$this->assertSame( 100.0, $scores['typography'] );
		$this->assertSame( 100.0, $scores['spacing'] );
	}

	public function test_automatic_repair_converts_simple_html_heading(): void {
		$repair = new AutomaticRepairEngine();
		$report = array( 'widgets' => 0, 'html_blocks' => 1, 'widget_breakdown' => array() );
		$elements = array(
			array(
				'elType'     => 'widget',
				'widgetType' => 'html',
				'settings'   => array( 'html' => '<h2>Title</h2>' ),
				'elements'   => array(),
			),
		);
		$validation = array( 'passes_threshold' => false, 'html_widget_percent' => 100 );
		$repaired   = $repair->repair( $elements, $validation, $report );
		$this->assertSame( 'heading', $repaired[0]['widgetType'] );
		$this->assertSame( 1, $report['widgets'] );
	}

	public function test_visual_reconstruction_engine_produces_native_widgets(): void {
		$engine = new VisualReconstructionEngine();
		$result = $engine->generate( RenderResult::from_array( $this->v2_layout() ), array(
			'confidence'         => 90,
			'fidelity_threshold' => 50,
		) );

		$this->assertSame( 'reconstruct', $result['report']['mode'] );
		$this->assertGreaterThan( 0, $result['report']['widgets'] );
		$this->assertArrayHasKey( 'overall_fidelity', $result['report'] );
		$this->assertGreaterThan(
			$result['report']['html_blocks'],
			$result['report']['widgets'],
			'Native widgets should outnumber HTML fallbacks'
		);

		$has_heading = false;
		$this->walk_elements( $result['data'], function ( array $el ) use ( &$has_heading ): void {
			if ( 'heading' === ( $el['widgetType'] ?? '' ) ) {
				$has_heading = true;
			}
		} );
		$this->assertTrue( $has_heading );
	}

	public function test_render_result_v2_accessors(): void {
		$result = RenderResult::from_array( $this->v2_layout() );
		$this->assertSame( 2, $result->version() );
		$this->assertNotEmpty( $result->layout_graph() );
		$this->assertNotEmpty( $result->design_tokens() );
	}

	/**
	 * @param array<int,array<string,mixed>> $elements Elements.
	 * @param callable                         $fn       Visitor.
	 */
	private function walk_elements( array $elements, callable $fn ): void {
		foreach ( $elements as $el ) {
			$fn( $el );
			if ( ! empty( $el['elements'] ) ) {
				$this->walk_elements( $el['elements'], $fn );
			}
		}
	}
}
