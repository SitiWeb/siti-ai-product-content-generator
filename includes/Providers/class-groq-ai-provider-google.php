<?php

class Groq_AI_Provider_Google implements Groq_AI_Provider_Interface {
	public function get_key() {
		return 'google';
	}

	public function get_label() {
		return __( 'Google AI (Gemini)', GROQ_AI_PRODUCT_TEXT_DOMAIN );
	}

	public function get_default_model() {
		return 'gemini-1.5-flash';
	}

	public function get_available_models() {
		return [
			'gemini-1.5-flash',
			'gemini-1.5-pro',
			'gemini-pro',
		];
	}

	public function get_option_key() {
		return 'google_api_key';
	}

	public function supports_live_models() {
		return true;
	}

	public function supports_response_format() {
		return true;
	}

	public function supports_image_context() {
		return true;
	}

	public function fetch_live_models( $api_key ) {
		$endpoint = add_query_arg(
			[ 'key' => $api_key, 'pageSize' => 100 ],
			'https://generativelanguage.googleapis.com/v1beta/models'
		);

		$response = wp_remote_get(
			$endpoint,
			[
				'timeout' => 20,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return new WP_Error( 'groq_ai_provider_error', (string) $body['error']['message'] );
		}

		if ( empty( $body['models'] ) || ! is_array( $body['models'] ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$models = [];
		foreach ( $body['models'] as $model ) {
			if ( ! empty( $model['name'] ) ) {
				$parts = explode( '/', $model['name'] );
				$models[] = sanitize_text_field( end( $parts ) );
			}
		}

		if ( empty( $models ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		return $models;
	}

	public function generate_content( array $args ) {
		$settings      = isset( $args['settings'] ) ? (array) $args['settings'] : [];
		$prompt        = isset( $args['prompt'] ) ? $args['prompt'] : '';
		$system_prompt = isset( $args['system_prompt'] ) ? $args['system_prompt'] : '';
		$model         = ! empty( $args['model'] ) ? $args['model'] : $this->get_default_model();
		$api_key       = isset( $settings[ $this->get_option_key() ] ) ? $settings[ $this->get_option_key() ] : '';

		if ( empty( $api_key ) ) {
			return new WP_Error( 'groq_ai_missing_api_key', sprintf( __( 'Stel eerst de API-sleutel voor %s in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $this->get_label() ) );
		}

		$endpoint = add_query_arg(
			'key',
			$api_key,
			sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent', rawurlencode( $model ) )
		);

		$image_context = isset( $args['image_context'] ) && is_array( $args['image_context'] ) ? $args['image_context'] : [];

		$parts = [];

		if ( '' !== trim( (string) $system_prompt ) ) {
			$parts[] = [
				'text' => $system_prompt,
			];
		}

		if ( '' !== trim( (string) $prompt ) ) {
			$parts[] = [
				'text' => $prompt,
			];
		}

		if ( ! empty( $image_context ) ) {
			foreach ( $image_context as $image ) {
				if ( empty( $image['data'] ) ) {
					continue;
				}

				$label = isset( $image['label'] ) ? trim( (string) $image['label'] ) : '';
				if ( '' !== $label ) {
					$parts[] = [
						'text' => sprintf(
							/* translators: %s: image label */
							__( 'Contextafbeelding: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
							$label
						),
					];
				}

				$parts[] = [
					'inline_data' => [
						'mime_type' => ! empty( $image['mime_type'] ) ? $image['mime_type'] : 'image/jpeg',
						'data'      => $image['data'],
					],
				];
			}
		}

		if ( empty( $parts ) ) {
			$parts[] = [
				'text' => $prompt,
			];
		}

		$max_tokens = isset( $args['max_tokens'] ) ? absint( $args['max_tokens'] ) : 0;
		if ( $max_tokens <= 0 ) {
			$max_tokens = isset( $settings['max_output_tokens'] ) ? absint( $settings['max_output_tokens'] ) : 0;
		}
		if ( $max_tokens <= 0 ) {
			$max_tokens = 2048;
		}
		$max_tokens = max( 128, min( 8192, $max_tokens ) );

		$generation_config = [
			'temperature'     => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.7,
			'maxOutputTokens' => $max_tokens,
		];

		$response_format = isset( $args['response_format'] ) ? $args['response_format'] : null;
		$schema_payload  = $this->prepare_response_schema_payload( $response_format );
		if ( ! empty( $schema_payload ) ) {
			$generation_config['responseMimeType']   = 'application/json';
			$generation_config['responseJsonSchema'] = $schema_payload;
		}

		$payload = [
			'contents'         => [
				[
					'role'  => 'user',
					'parts' => $parts,
				],
			],
			'generationConfig' => $generation_config,
		];

		$safety_settings_payload = $this->build_safety_settings_payload(
			isset( $settings['google_safety_settings'] ) ? $settings['google_safety_settings'] : []
		);

		if ( ! empty( $safety_settings_payload ) ) {
			$payload['safetySettings'] = $safety_settings_payload;
		}

		$response = wp_remote_post(
			$endpoint,
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body'    => wp_json_encode( $payload ),
				'timeout' => isset( $args['timeout'] ) ? (int) $args['timeout'] : 60,
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error']['message'] ) ) {
			return new WP_Error( 'groq_ai_provider_error', (string) $body['error']['message'] );
		}

		if ( empty( $body['candidates'][0]['content']['parts'] ) ) {
			return new WP_Error(
				'groq_ai_empty_response',
				sprintf( __( 'Geen antwoord ontvangen van %s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $this->get_label() )
			);
		}

		$parts = $body['candidates'][0]['content']['parts'];
		$texts = [];

		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) ) {
				$texts[] = $part['text'];
			}
		}

		$content = trim( implode( "\n\n", array_filter( $texts ) ) );
		$usage_metadata = isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ? $body['usageMetadata'] : [];
		$usage   = $usage_metadata;
		if ( ! empty( $usage_metadata ) ) {
			$usage = array_merge( $usage, $this->map_usage_metadata_counts( $usage_metadata ) );
		}
		$finish_reason = isset( $body['candidates'][0]['finishReason'] ) ? sanitize_text_field( (string) $body['candidates'][0]['finishReason'] ) : '';
		if ( '' !== $finish_reason ) {
			$usage['finish_reason'] = $finish_reason;
		}

		return [
			'content'      => $content,
			'usage'        => $usage,
			'raw_response' => $body,
		];
	}

	private function build_safety_settings_payload( $settings ) {
		if ( empty( $settings ) || ! is_array( $settings ) ) {
			return [];
		}

		$categories = class_exists( 'Groq_AI_Settings_Manager' ) ? array_keys( Groq_AI_Settings_Manager::get_google_safety_categories_list() ) : [];
		$thresholds = class_exists( 'Groq_AI_Settings_Manager' ) ? array_keys( Groq_AI_Settings_Manager::get_google_safety_thresholds_list() ) : [];

		if ( empty( $categories ) || empty( $thresholds ) ) {
			return [];
		}

		$payload = [];
		foreach ( $settings as $category => $threshold ) {
			$category  = sanitize_text_field( (string) $category );
			$threshold = sanitize_text_field( (string) $threshold );

			if ( ! in_array( $category, $categories, true ) || ! in_array( $threshold, $thresholds, true ) ) {
				continue;
			}

			$payload[] = [
				'category'  => $category,
				'threshold' => $threshold,
			];
		}

		return $payload;
	}

	private function prepare_response_schema_payload( $response_format ) {
		if ( empty( $response_format ) || ! is_array( $response_format ) ) {
			return [];
		}

		if ( isset( $response_format['type'] ) && 'json_schema' === $response_format['type'] ) {
			if ( isset( $response_format['json_schema']['schema'] ) && is_array( $response_format['json_schema']['schema'] ) ) {
				return $this->sanitize_schema_definition( $response_format['json_schema']['schema'] );
			}

			if ( isset( $response_format['schema'] ) && is_array( $response_format['schema'] ) ) {
				return $this->sanitize_schema_definition( $response_format['schema'] );
			}
		}

		return [];
	}

	private function sanitize_schema_definition( $schema ) {
		if ( ! is_array( $schema ) ) {
			return [];
		}

		$encoded = wp_json_encode( $schema );
		if ( ! $encoded ) {
			return [];
		}

		$decoded = json_decode( $encoded, true );

		if ( ! is_array( $decoded ) ) {
			return [];
		}

		$this->remove_disallowed_schema_keys( $decoded );

		return $decoded;
	}

	private function remove_disallowed_schema_keys( array &$schema ) {
		$disallowed = [ 'additionalProperties' ];

		foreach ( $schema as $key => &$value ) {
			if ( in_array( $key, $disallowed, true ) ) {
				unset( $schema[ $key ] );
				continue;
			}

			if ( is_array( $value ) ) {
				$this->remove_disallowed_schema_keys( $value );
			}
		}

		unset( $value );
	}

	private function map_usage_metadata_counts( $metadata ) {
		if ( ! is_array( $metadata ) ) {
			return [];
		}

		$mapped = [];

		if ( isset( $metadata['promptTokenCount'] ) ) {
			$mapped['prompt_tokens'] = absint( $metadata['promptTokenCount'] );
		}

		if ( isset( $metadata['candidatesTokenCount'] ) ) {
			$mapped['completion_tokens'] = absint( $metadata['candidatesTokenCount'] );
		}

		if ( isset( $metadata['totalTokenCount'] ) ) {
			$mapped['total_tokens'] = absint( $metadata['totalTokenCount'] );
		}

		return $mapped;
	}
}
