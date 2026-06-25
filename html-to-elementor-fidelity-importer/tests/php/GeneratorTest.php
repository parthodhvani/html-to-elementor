<?php
/**
 * Unit tests for the Elementor JSON generator and widget detector.
 *
 * @package HtmlToElementor
 */

declare( strict_types=1 );

namespace HtmlToElementor\Tests;

use HtmlToElementor\Services\RenderResult;
use HtmlToElementor\Elementor\ElementorJsonGenerator;
use HtmlToElementor\Elementor\WidgetDetector;
use PHPUnit\Framework\TestCase;

final class GeneratorTest extends TestCase {

	/**
	 * Build a minimal layout document for tests.
	 *
	 * @return array<string,mixed>
	 */
	private function layout(): array {
		return array(
			'meta'        => array( 'title' => 'Sample' ),
			'assets'      => array( 'combinedCss' => 'body{color:#000}' ),
			'sections'    => array(
				array(
					'tag'    => 'header',
					'html'   => '<header class="hero"><h1>Hello</h1><p>World</p></header>',
					'bbox'   => array( 'height' => 400 ),
					'styles' => array( 'backgroundColor' => 'rgb(13, 71, 161)', 'paddingTop' => '80px', 'paddingBottom' => '80px' ),
				),
				array(
					'tag'    => 'h2',
					'html'   => '<h2>Just a heading</h2>',
					'bbox'   => array( 'height' => 60 ),
					'styles' => array( 'backgroundColor' => 'transparent' ),
				),
			),
		);
	}

	public function test_preserve_mode_wraps_each_section_in_a_container(): void {
		$gen    = new ElementorJsonGenerator();
		$result = $gen->generate( RenderResult::from_array( $this->layout() ), array( 'mode' => 'preserve' ) );

		// 1 asset container + 2 section containers.
		$this->assertCount( 3, $result['data'] );
		foreach ( $result['data'] as $el ) {
			$this->assertSame( 'container', $el['elType'] );
			$this->assertNotEmpty( $el['id'] );
		}
		// First section keeps original HTML verbatim in an html widget.
		$section = $result['data'][1];
		$this->assertSame( 'widget', $section['elements'][0]['elType'] );
		$this->assertSame( 'html', $section['elements'][0]['widgetType'] );
		$this->assertStringContainsString( '<h1>Hello</h1>', $section['elements'][0]['settings']['html'] );
		$this->assertSame( 'preserve', $result['report']['mode'] );
		$this->assertSame( 2, $result['report']['sections'] );
	}

	public function test_container_applies_background_from_computed_styles(): void {
		$gen     = new ElementorJsonGenerator();
		$result  = $gen->generate( RenderResult::from_array( $this->layout() ), array( 'mode' => 'preserve' ) );
		$section = $result['data'][1];
		$this->assertSame( 'classic', $section['settings']['background_background'] );
		$this->assertSame( 'rgb(13, 71, 161)', $section['settings']['background_color'] );
		$this->assertSame( 400.0, $section['settings']['min_height']['size'] );
	}

	public function test_widget_mode_converts_single_heading_section(): void {
		$gen    = new ElementorJsonGenerator();
		$result = $gen->generate( RenderResult::from_array( $this->layout() ), array( 'mode' => 'widgets', 'confidence' => 95 ) );

		// The second section is a single <h2> and should become a heading widget.
		$this->assertGreaterThanOrEqual( 1, $result['report']['widgets'] );
		$this->assertSame( 1, $result['report']['widget_breakdown']['heading'] ?? 0 );

		// The multi-element hero section must stay as preserved HTML.
		$hero = $result['data'][1];
		$this->assertSame( 'html', $hero['elements'][0]['widgetType'] );
	}

	public function test_detector_rejects_low_confidence_complex_markup(): void {
		$detector = new WidgetDetector( 95 );
		$this->assertNull( $detector->detect( '<div><h1>a</h1><p>b</p></div>' ) );
		$this->assertNull( $detector->detect( '<a href="#">plain link</a>' ) );

		$heading = $detector->detect( '<h3>Title</h3>' );
		$this->assertNotNull( $heading );
		$this->assertSame( 'heading', $heading['type'] );
		$this->assertSame( 'h3', $heading['settings']['header_size'] );
	}
}
