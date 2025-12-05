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
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming voor deze actie.', 'groq-ai-product-text' ) ], 403 );
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
		$product_context_text = $prompt_builder->build_product_context_block( $post_id, $context_fields );
		$prompt_with_context  = $prompt_builder->prepend_context_to_prompt( $prompt, $product_context_text );

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
			]
		);

		if ( is_wp_error( $result ) ) {
			$this->plugin->get_generation_logger()->log_generation_event(
				[
					'provider'      => $provider_key,
					'model'         => $model,
					'prompt'        => $final_prompt,
					'response'      => '',
					'usage'         => [],
					'post_id'       => $post_id,
					'status'        => 'error',
					'error_message' => $result->get_error_message(),
				]
			);
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$response_text = $this->extract_content_text( $result );
		$response_usage = is_array( $result ) && isset( $result['usage'] ) ? $result['usage'] : [];

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
			wp_send_json_error( [ 'message' => __( 'Geen toestemming.', 'groq-ai-product-text' ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_refresh_models', 'nonce' );

		$provider_key = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$api_key      = isset( $_POST['apiKey'] ) ? sanitize_text_field( wp_unslash( $_POST['apiKey'] ) ) : '';

		if ( empty( $provider_key ) || empty( $api_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Provider en API-sleutel zijn verplicht.', 'groq-ai-product-text' ) ], 400 );
		}

		$provider = $this->plugin->get_provider_manager()->get_provider( $provider_key );

		if ( ! $provider || ! $provider->supports_live_models() ) {
			wp_send_json_error( [ 'message' => __( 'Deze aanbieder ondersteunt het ophalen van modellen niet.', 'groq-ai-product-text' ) ], 400 );
		}

		$result = $provider->fetch_live_models( $api_key );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		wp_send_json_success( [ 'models' => array_values( array_unique( $result ) ) ] );
	}

	private function extract_content_text( $result ) {
		if ( is_array( $result ) && isset( $result['content'] ) ) {
			return (string) $result['content'];
		}

		return (string) $result;
	}
}
