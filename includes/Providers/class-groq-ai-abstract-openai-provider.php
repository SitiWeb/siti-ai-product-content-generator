<?php

abstract class Groq_AI_Abstract_OpenAI_Provider implements Groq_AI_Provider_Interface {
	public function get_available_models() {
		return [];
	}

	public function supports_response_format() {
		return true;
	}

	public function supports_live_models() {
		return true;
	}

	public function fetch_live_models( $api_key ) {
		$endpoint = $this->get_models_endpoint();
		if ( empty( $endpoint ) ) {
			return new WP_Error( 'groq_ai_models_endpoint_missing', __( 'Geen model-endpoint beschikbaar voor deze aanbieder.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$response = wp_remote_get(
			$endpoint,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
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

		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$models = [];
		foreach ( $body['data'] as $model ) {
			if ( ! empty( $model['id'] ) ) {
				$models[] = sanitize_text_field( $model['id'] );
			}
		}

		if ( empty( $models ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen modeldata ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		return $models;
	}

	public function generate_content( array $args ) {
		$settings     = isset( $args['settings'] ) ? (array) $args['settings'] : [];
		$prompt       = isset( $args['prompt'] ) ? $args['prompt'] : '';
		$system_prompt = isset( $args['system_prompt'] ) ? $args['system_prompt'] : '';
		$model        = ! empty( $args['model'] ) ? $args['model'] : $this->get_default_model();
		$api_key      = $this->get_api_key( $settings );

		if ( empty( $api_key ) ) {
			return new WP_Error( 'groq_ai_missing_api_key', sprintf( __( 'Stel eerst de API-sleutel voor %s in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $this->get_label() ) );
		}

		$messages = [
			[
				'role'    => 'system',
				'content' => $system_prompt,
			],
			[
				'role'    => 'user',
				'content' => $prompt,
			],
		];

		$request_body = [
			'model'       => $model,
			'messages'    => $messages,
			'temperature' => isset( $args['temperature'] ) ? (float) $args['temperature'] : 0.7,
			'max_tokens'  => 1024,
		];

		if ( ! empty( $args['response_format'] ) ) {
			$request_body['response_format'] = $args['response_format'];
		}

		$response = wp_remote_post(
			$this->get_endpoint(),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $request_body ),
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

		if ( empty( $body['choices'][0]['message']['content'] ) ) {
			return new WP_Error(
				'groq_ai_empty_response',
				sprintf( __( 'Geen antwoord ontvangen van %s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $this->get_label() )
			);
		}

		$content = trim( $body['choices'][0]['message']['content'] );
		$usage   = isset( $body['usage'] ) && is_array( $body['usage'] ) ? $body['usage'] : [];

		return [
			'content'      => $content,
			'usage'        => $usage,
			'raw_response' => $body,
		];
	}

	abstract protected function get_endpoint();

	abstract protected function get_models_endpoint();

	protected function get_api_key( $settings ) {
		$field = $this->get_option_key();
		return isset( $settings[ $field ] ) ? $settings[ $field ] : '';
	}

	public function supports_image_context() {
		return false;
	}
}
