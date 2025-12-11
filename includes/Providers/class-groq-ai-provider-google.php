<?php

class Groq_AI_Provider_Google implements Groq_AI_Provider_Interface {
	public function get_key() {
		return 'google';
	}

	public function get_label() {
		return __( 'Google AI (Gemini)', 'groq-ai-product-text' );
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
		return false;
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
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', 'groq-ai-product-text' ) );
		}

		$models = [];
		foreach ( $body['models'] as $model ) {
			if ( ! empty( $model['name'] ) ) {
				$parts = explode( '/', $model['name'] );
				$models[] = sanitize_text_field( end( $parts ) );
			}
		}

		if ( empty( $models ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', 'groq-ai-product-text' ) );
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
			return new WP_Error( 'groq_ai_missing_api_key', sprintf( __( 'Stel eerst de API-sleutel voor %s in.', 'groq-ai-product-text' ), $this->get_label() ) );
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
							__( 'Contextafbeelding: %s', 'groq-ai-product-text' ),
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

		$payload = [
			'contents'         => [
				[
					'role'  => 'user',
					'parts' => $parts,
				],
			],
			'generationConfig' => [
				'temperature'     => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.7,
				'maxOutputTokens' => 1024,
			],
		];

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
				sprintf( __( 'Geen antwoord ontvangen van %s.', 'groq-ai-product-text' ), $this->get_label() )
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
		$usage   = isset( $body['usageMetadata'] ) && is_array( $body['usageMetadata'] ) ? $body['usageMetadata'] : [];

		return [
			'content'      => $content,
			'usage'        => $usage,
			'raw_response' => $body,
		];
	}
}
