<?php

use PHPUnit\Framework\TestCase;

class ProviderRequestBuilderTest extends TestCase {
	public function test_openai_request_payload_respects_settings() {
		$provider = new Groq_AI_Provider_OpenAI();
		$result = $provider->generate_content(
			[
				'prompt' => 'Hallo',
				'system_prompt' => 'System',
				'model' => 'gpt-4o-mini',
				'settings' => [
					'openai_api_key' => 'test-key',
					'max_output_tokens' => 512,
				],
				'temperature' => 0.5,
				'response_format' => [
					'type' => 'json_object',
				],
			]
		);

		$this->assertIsArray( $result );
		$payload = $result['request_payload']['body'];
		$this->assertSame( 'gpt-4o-mini', $payload['model'] );
		$this->assertSame( 0.5, $payload['temperature'] );
		$this->assertSame( 512, $payload['max_tokens'] );
		$this->assertSame( 'json_object', $payload['response_format']['type'] );
		$this->assertSame( 'System', $payload['messages'][0]['content'] );
		$this->assertSame( 'Hallo', $payload['messages'][1]['content'] );
	}

	public function test_groq_request_payload_uses_default_model_when_missing() {
		$provider = new Groq_AI_Provider_Groq();
		$result = $provider->generate_content(
			[
				'prompt' => 'Hallo',
				'system_prompt' => 'System',
				'settings' => [
					'groq_api_key' => 'test-key',
				],
			]
		);

		$this->assertIsArray( $result );
		$payload = $result['request_payload']['body'];
		$this->assertSame( $provider->get_default_model(), $payload['model'] );
		$this->assertSame( 'System', $payload['messages'][0]['content'] );
		$this->assertSame( 'Hallo', $payload['messages'][1]['content'] );
	}

	public function test_google_request_payload_builds_schema_and_images() {
		$provider = new Groq_AI_Provider_Google();
		$result = $provider->generate_content(
			[
				'prompt' => 'Hallo',
				'system_prompt' => 'System',
				'model' => 'gemini-1.5-flash',
				'settings' => [
					'google_api_key' => 'test-key',
					'max_output_tokens' => 256,
					'google_safety_settings' => [
						'HARM_CATEGORY_HARASSMENT' => 'BLOCK_LOW_AND_ABOVE',
					],
				],
				'temperature' => 0.2,
				'response_format' => [
					'type' => 'json_schema',
					'json_schema' => [
						'schema' => [
							'type' => 'object',
							'properties' => [
								'name' => [
									'type' => 'string',
								],
							],
						],
					],
				],
				'image_context' => [
					[
						'label' => 'Image 1',
						'mime_type' => 'image/png',
						'data' => 'BASE64DATA',
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$payload = $result['request_payload']['body'];
		$this->assertSame( 0.2, $payload['generationConfig']['temperature'] );
		$this->assertSame( 256, $payload['generationConfig']['maxOutputTokens'] );
		$this->assertSame( 'application/json', $payload['generationConfig']['responseMimeType'] );
		$this->assertArrayHasKey( 'responseJsonSchema', $payload['generationConfig'] );
		$this->assertSame( 'System', $payload['contents'][0]['parts'][0]['text'] );
		$this->assertSame( 'Hallo', $payload['contents'][0]['parts'][1]['text'] );
		$this->assertSame( 'image/png', $payload['contents'][0]['parts'][3]['inline_data']['mime_type'] );
		$this->assertSame( 'BASE64DATA', $payload['contents'][0]['parts'][3]['inline_data']['data'] );
	}
}
