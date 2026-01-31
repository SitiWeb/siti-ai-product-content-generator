<?php

use PHPUnit\Framework\TestCase;

class SettingsManagerTest extends TestCase {
	private function make_manager() {
		$provider_manager = new Groq_AI_Provider_Manager();
		return new Groq_AI_Settings_Manager( 'groq_ai_test_settings', $provider_manager );
	}

	public function test_logs_retention_days_sanitized_and_capped() {
		$manager = $this->make_manager();

		$result = $manager->sanitize( [
			'logs_retention_days' => 5000,
		] );

		$this->assertSame( 3650, $result['logs_retention_days'] );
	}

	public function test_logs_retention_days_allows_zero() {
		$manager = $this->make_manager();

		$result = $manager->sanitize( [
			'logs_retention_days' => 0,
		] );

		$this->assertSame( 0, $result['logs_retention_days'] );
	}

	public function test_logs_retention_days_negative_becomes_zero() {
		$manager = $this->make_manager();

		$result = $manager->sanitize( [
			'logs_retention_days' => -5,
		] );

		$this->assertSame( 0, $result['logs_retention_days'] );
	}

	public function test_sanitize_accepts_all_settings_keys() {
		$manager = $this->make_manager();
		$context_fields = $manager->get_default_context_fields();
		$modules = $manager->get_default_modules_settings();
		$google_categories = Groq_AI_Settings_Manager::get_google_safety_categories_list();
		$first_category = array_key_first( $google_categories );

		$input = [
			'provider'       => 'openai',
			'model'          => 'gpt-4o-mini',
			'store_context'  => 'Test winkelcontext',
			'default_prompt' => 'Schrijf een korte tekst',
			'max_output_tokens' => 2048,
			'logs_retention_days' => 30,
			'product_attribute_includes' => [ '__all__', '__custom__', 'pa_color', 'invalid key' ],
			'term_bottom_description_meta_key' => 'custom_bottom_key',
			'groq_api_key'   => 'groq-key',
			'openai_api_key' => 'openai-key',
			'google_api_key' => 'google-key',
			'google_oauth_client_id' => 'client-id',
			'google_oauth_client_secret' => 'client-secret',
			'google_oauth_refresh_token' => 'refresh-token',
			'google_oauth_connected_email' => 'user@example.com',
			'google_oauth_connected_at' => 123456,
			'google_enable_gsc' => true,
			'google_enable_ga' => false,
			'google_gsc_site_url' => 'https://example.com/',
			'google_ga4_property_id' => '123456',
			'google_safety_settings' => $first_category ? [ $first_category => 'BLOCK_LOW_AND_ABOVE' ] : [],
			'context_fields' => $context_fields,
			'modules'        => $modules,
			'image_context_mode' => 'base64',
			'image_context_limit' => 5,
			'response_format_compat' => true,
			'term_top_description_char_limit' => 700,
			'term_bottom_description_char_limit' => 1400,
		];

		$result = $manager->sanitize( $input );

		$this->assertSame( 'openai', $result['provider'] );
		$this->assertSame( 'gpt-4o-mini', $result['model'] );
		$this->assertSame( 'Test winkelcontext', $result['store_context'] );
		$this->assertSame( 'Schrijf een korte tekst', $result['default_prompt'] );
		$this->assertSame( 2048, $result['max_output_tokens'] );
		$this->assertSame( 30, $result['logs_retention_days'] );
		$this->assertContains( '__all__', $result['product_attribute_includes'] );
		$this->assertContains( '__custom__', $result['product_attribute_includes'] );
		$this->assertContains( 'pa_color', $result['product_attribute_includes'] );
		$this->assertSame( 'custom_bottom_key', $result['term_bottom_description_meta_key'] );
		$this->assertSame( 'groq-key', $result['groq_api_key'] );
		$this->assertSame( 'openai-key', $result['openai_api_key'] );
		$this->assertSame( 'google-key', $result['google_api_key'] );
		$this->assertSame( 'client-id', $result['google_oauth_client_id'] );
		$this->assertSame( 'client-secret', $result['google_oauth_client_secret'] );
		$this->assertSame( 'refresh-token', $result['google_oauth_refresh_token'] );
		$this->assertSame( 'user@example.com', $result['google_oauth_connected_email'] );
		$this->assertSame( 123456, $result['google_oauth_connected_at'] );
		$this->assertTrue( $result['google_enable_gsc'] );
		$this->assertFalse( $result['google_enable_ga'] );
		$this->assertSame( 'https://example.com/', $result['google_gsc_site_url'] );
		$this->assertSame( '123456', $result['google_ga4_property_id'] );
		$this->assertIsArray( $result['google_safety_settings'] );
		$this->assertIsArray( $result['context_fields'] );
		$this->assertIsArray( $result['modules'] );
		$this->assertSame( 'base64', $result['image_context_mode'] );
		$this->assertSame( 5, $result['image_context_limit'] );
		$this->assertTrue( $result['response_format_compat'] );
		$this->assertSame( 700, $result['term_top_description_char_limit'] );
		$this->assertSame( 1400, $result['term_bottom_description_char_limit'] );
	}
}
