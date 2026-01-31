<?php

use PHPUnit\Framework\TestCase;

class TermSaveTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['wp_term_updates'] = [];
		$GLOBALS['wp_term_meta_updates'] = [];
		$GLOBALS['wp_filters'] = [];
	}

	public function test_save_term_generation_result_saves_descriptions_and_filtered_meta_key() {
		$plugin = new class {
			public function get_settings() {
				return [ 'term_bottom_description_meta_key' => '' ];
			}
			public function is_module_enabled( $module, $settings = null ) {
				return false;
			}
		};

		$controller_ref = new ReflectionClass( Groq_AI_Ajax_Controller::class );
		$controller = $controller_ref->newInstanceWithoutConstructor();
		$plugin_prop = $controller_ref->getProperty( 'plugin' );
		$plugin_prop->setAccessible( true );
		$plugin_prop->setValue( $controller, $plugin );

		add_filter(
			'groq_ai_term_bottom_description_meta_key',
			function ( $default_key ) {
				return 'Custom Key';
			},
			10,
			3
		);

		$term = (object) [
			'term_id' => 12,
			'taxonomy' => 'product_cat',
			'name' => 'Test',
			'description' => '',
		];

		$result = [
			'top_description' => '<p>Dit is een test.</p>',
			'bottom_description' => '<p>Onderste tekst.</p>',
		];

		$settings = $plugin->get_settings();

		$method = $controller_ref->getMethod( 'save_term_generation_result' );
		$method->setAccessible( true );
		$saved = $method->invoke( $controller, $term, $result, $settings );

		$this->assertIsArray( $saved );
		$this->assertSame( 4, $saved['words'] );

		$this->assertCount( 1, $GLOBALS['wp_term_updates'] );
		$this->assertSame( 12, $GLOBALS['wp_term_updates'][0]['term_id'] );
		$this->assertSame( 'product_cat', $GLOBALS['wp_term_updates'][0]['taxonomy'] );
		$this->assertSame( '<p>Dit is een test.</p>', $GLOBALS['wp_term_updates'][0]['args']['description'] );

		$this->assertArrayHasKey( 12, $GLOBALS['wp_term_meta_updates'] );
		$this->assertArrayHasKey( 'customkey', $GLOBALS['wp_term_meta_updates'][12] );
		$this->assertSame( '<p>Onderste tekst.</p>', $GLOBALS['wp_term_meta_updates'][12]['customkey'] );
	}
}
