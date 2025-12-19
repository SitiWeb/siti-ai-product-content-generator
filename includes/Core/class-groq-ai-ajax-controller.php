<?php

class Groq_AI_Ajax_Controller {
	/** @var Groq_AI_Product_Text_Plugin */
	private $plugin;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_ajax_groq_ai_generate_text', [ $this, 'handle_generate_text' ] );
		add_action( 'wp_ajax_groq_ai_refresh_models', [ $this, 'handle_refresh_models' ] );
	}

	public function handle_generate_text() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming voor deze actie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_generate', 'nonce' );

		$prompt = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		$settings      = $this->plugin->get_settings();
		$provider_manager = $this->plugin->get_provider_manager();
		$provider_key  = $settings['provider'];
		$provider      = $provider_manager->get_provider( $provider_key );

		if ( ! $provider ) {
			$provider      = $provider_manager->get_provider( 'groq' );
			$provider_key  = 'groq';
		}

		$conversation_id      = $this->plugin->get_conversation_manager()->ensure_id( $provider_key, $settings['store_context'] );
		$prompt_builder       = $this->plugin->get_prompt_builder();
		$system_prompt        = $prompt_builder->build_system_prompt( $settings, $conversation_id );
		$model                = $this->plugin->get_selected_model( $provider, $settings );
		$context_fields       = $prompt_builder->parse_context_fields_from_request( isset( $_POST['context_fields'] ) ? $_POST['context_fields'] : '', $settings );
		$image_context_mode   = $this->plugin->get_image_context_mode( $settings );
		$image_context_limit  = $this->plugin->get_image_context_limit( $settings );

		if ( 'none' === $image_context_mode ) {
			$context_fields['images'] = false;
		}

		$image_context_enabled = ! empty( $context_fields['images'] );
		$use_base64_payloads   = $image_context_enabled && 'base64' === $image_context_mode && $provider->supports_image_context();
		$total_image_count     = $image_context_enabled ? $prompt_builder->get_product_image_count( $post_id ) : 0;
		$image_context_count   = $image_context_enabled ? min( $image_context_limit, $total_image_count ) : 0;

		$prompt_image_mode = 'none';
		if ( $image_context_enabled ) {
			if ( $use_base64_payloads ) {
				$prompt_image_mode = 'base64';
			} else {
				$prompt_image_mode = 'url';
			}

			if ( 'base64' === $image_context_mode && ! $provider->supports_image_context() ) {
				$prompt_image_mode = 'url';
			}
		}

		$product_context_text = $prompt_builder->build_product_context_block( $post_id, $context_fields, $prompt_image_mode, $image_context_limit );
		$image_context_payloads = [];
		if ( $use_base64_payloads ) {
			$image_context_payloads = $prompt_builder->get_product_image_payloads( $post_id, $image_context_limit );
		}
		$prompt_with_context  = $prompt_builder->prepend_context_to_prompt( $prompt, $product_context_text );

		$image_context_meta = [
			'requested_mode' => $image_context_mode,
			'effective_mode' => $prompt_image_mode,
			'limit'          => $image_context_limit,
			'available'      => $total_image_count,
			'used'           => $image_context_count,
			'base64_sent'    => $use_base64_payloads ? count( $image_context_payloads ) : 0,
		];

		$response_format  = null;
		$use_response_format = $this->plugin->should_use_response_format( $provider, $settings );
		if ( $use_response_format ) {
			$response_format = $prompt_builder->get_response_format_definition( $settings );
			$final_prompt    = $prompt_with_context;
		} else {
			$final_prompt = $prompt_builder->append_response_instructions( $prompt_with_context, $settings );
		}

		$result = $provider->generate_content(
			[
				'prompt'          => $final_prompt,
				'system_prompt'   => $system_prompt,
				'model'           => $model,
				'settings'        => $settings,
				'temperature'     => 0.7,
				'conversation_id' => $conversation_id,
				'response_format' => $response_format,
				'image_context'   => $image_context_payloads,
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->plugin->get_generation_logger()->log_generation_event(
				[
					'provider'      => $provider_key,
					'model'         => $model,
					'prompt'        => $final_prompt,
					'response'      => '',
					'usage'         => [
						'image_context' => $image_context_meta,
					],
					'post_id'       => $post_id,
					'status'        => 'error',
					'error_message' => $result->get_error_message(),
				]
			);
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$response_text = $this->extract_content_text( $result );
		$response_usage = is_array( $result ) && isset( $result['usage'] ) ? $result['usage'] : [];
		if ( ! is_array( $response_usage ) ) {
			$response_usage = [];
		}
		$response_usage['image_context'] = $image_context_meta;

		$response = $prompt_builder->parse_structured_response( $response_text, $settings );

		if ( is_wp_error( $response ) ) {
			$this->plugin->get_generation_logger()->log_generation_event(
				[
					'provider'      => $provider_key,
					'model'         => $model,
					'prompt'        => $final_prompt,
					'response'      => $response_text,
					'usage'         => $response_usage,
					'post_id'       => $post_id,
					'status'        => 'error',
					'error_message' => $response->get_error_message(),
				]
			);
			wp_send_json_error( [ 'message' => $response->get_error_message() ], 500 );
		}

		$this->plugin->get_generation_logger()->log_generation_event(
			[
				'provider' => $provider_key,
				'model'    => $model,
				'prompt'   => $final_prompt,
				'response' => $response_text,
				'usage'    => $response_usage,
				'post_id'  => $post_id,
				'status'   => 'success',
			]
		);

		wp_send_json_success(
			[
				'fields' => $response,
				'raw'    => $response_text,
			]
		);
	}

	public function handle_refresh_models() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_refresh_models', 'nonce' );

		$provider_key = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$api_key      = isset( $_POST['apiKey'] ) ? sanitize_text_field( wp_unslash( $_POST['apiKey'] ) ) : '';

		if ( empty( $provider_key ) || empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Provider en API-sleutel zijn verplicht.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 400 );
		}

		$provider = $this->plugin->get_provider_manager()->get_provider( $provider_key );

		if ( ! $provider || ! $provider->supports_live_models() ) {
			wp_send_json_error( [ 'message' => __( 'Deze aanbieder ondersteunt het ophalen van modellen niet.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 400 );
		}

		$result = $provider->fetch_live_models( $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$models = Groq_AI_Model_Exclusions::filter_models( $provider_key, array_values( array_unique( $result ) ) );
		$models = $this->plugin->update_cached_models_for_provider( $provider_key, $models );

		wp_send_json_success( [ 'models' => $models ] );
	}

	private function extract_content_text( $result ) {
		if ( is_array( $result ) && isset( $result['content'] ) ) {
			return (string) $result['content'];
		}

		return (string) $result;
	}
}
