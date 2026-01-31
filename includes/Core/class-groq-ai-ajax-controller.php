<?php

class Groq_AI_Ajax_Controller {
	/** @var Groq_AI_Product_Text_Plugin */
	private $plugin;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		$this->plugin = $plugin;

		add_action( 'wp_ajax_groq_ai_generate_text', [ $this, 'handle_generate_text' ] );
		add_action( 'wp_ajax_groq_ai_refresh_models', [ $this, 'handle_refresh_models' ] );
		add_action( 'wp_ajax_groq_ai_generate_term_text', [ $this, 'handle_generate_term_text' ] );
		add_action( 'wp_ajax_groq_ai_bulk_generate_terms', [ $this, 'handle_bulk_generate_terms_request' ] );
	}

	public function handle_generate_term_text() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming voor deze actie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_generate_term', 'nonce' );

		$prompt   = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$include_top_products = ! empty( $_POST['include_top_products'] );
		$top_products_limit   = isset( $_POST['top_products_limit'] ) ? absint( $_POST['top_products_limit'] ) : 10;
		$top_products_limit   = max( 1, min( 25, $top_products_limit ) );

		if ( '' === $prompt || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Prompt, taxonomy en term_id zijn verplicht.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 400 );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 404 );
		}

		$result = $this->run_term_generation(
			$term,
			$prompt,
			[
				'include_top_products' => $include_top_products,
				'top_products_limit'   => $top_products_limit,
				'origin'               => 'term_manual',
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		wp_send_json_success( $result );
	}

	public function handle_bulk_generate_terms_request() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming voor deze actie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_bulk_generate_terms', 'nonce' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$force    = ! empty( $_POST['force'] );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! $term_id ) {
			wp_send_json_error( [ 'message' => __( 'Taxonomie en term_id zijn verplicht.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 400 );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			wp_send_json_error( [ 'message' => __( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 404 );
		}

		$current_description = isset( $term->description ) ? trim( wp_strip_all_tags( (string) $term->description ) ) : '';
		if ( '' !== $current_description && ! $force ) {
			wp_send_json_error(
				[
					'message' => __( 'Categorie heeft al een omschrijving.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'code'    => 'groq_ai_term_has_description',
				],
				400
			);
		}

		$options = apply_filters(
			'groq_ai_bulk_term_generation_options',
			[
				'include_top_products' => true,
				'top_products_limit'   => 10,
			],
			$term
		);

		$options['origin'] = $force ? 'term_force_regenerate' : 'term_bulk_auto';
		$options['force']  = $force;

		$result = $this->run_term_generation( $term, $this->get_term_prompt_text( $term ), $options );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ], 500 );
		}

		$settings = $this->plugin->get_settings();
		$saved    = $this->save_term_generation_result( $term, $result, $settings );

		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( [ 'message' => $saved->get_error_message() ], 500 );
		}

		wp_send_json_success(
			[
				'term_id' => $term_id,
				'name'    => isset( $term->name ) ? (string) $term->name : '',
				'words'   => isset( $saved['words'] ) ? absint( $saved['words'] ) : 0,
				'count'   => isset( $term->count ) ? absint( $term->count ) : 0,
			]
		);
	}

	private function run_term_generation( $term, $prompt, $options = [] ) {
		if ( ! $term || ! is_object( $term ) ) {
			return new WP_Error( 'groq_ai_invalid_term', __( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$taxonomy = isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '';
		$term_id  = isset( $term->term_id ) ? absint( $term->term_id ) : 0;

		$options = wp_parse_args(
			$options,
			[
				'include_top_products' => true,
				'top_products_limit'   => 10,
				'origin'              => 'term_manual',
				'force'               => false,
			]
		);

		$origin            = isset( $options['origin'] ) ? sanitize_key( (string) $options['origin'] ) : 'term_manual';
		$force_run         = ! empty( $options['force'] );
		$include_top_products = ! empty( $options['include_top_products'] );
		$top_products_limit   = isset( $options['top_products_limit'] ) ? absint( $options['top_products_limit'] ) : 10;
		$top_products_limit   = max( 1, min( 25, $top_products_limit ) );

		$logger          = $this->plugin->get_generation_logger();
		$settings         = $this->plugin->get_settings();
		$provider_manager = $this->plugin->get_provider_manager();
		$provider_key     = $settings['provider'];
		$provider         = $provider_manager->get_provider( $provider_key );

		if ( ! $provider ) {
			$provider     = $provider_manager->get_provider( 'groq' );
			$provider_key = 'groq';
		}

		$conversation_id = $this->plugin->get_conversation_manager()->ensure_id( $provider_key, $settings['store_context'] );
		$prompt_builder  = $this->plugin->get_prompt_builder();
		$system_prompt   = method_exists( $prompt_builder, 'build_term_system_prompt' )
			? $prompt_builder->build_term_system_prompt( $settings, $conversation_id, $term )
			: $prompt_builder->build_system_prompt( $settings, $conversation_id );

		$context_block = '';
		if ( method_exists( $prompt_builder, 'build_term_context_block' ) ) {
			$context_block = $prompt_builder->build_term_context_block(
				$term,
				[
					'include_top_products' => $include_top_products,
					'top_products_limit'   => $top_products_limit,
				],
				$settings
			);
		}
		$prompt_with_context = method_exists( $prompt_builder, 'prepend_term_context_to_prompt' )
			? $prompt_builder->prepend_term_context_to_prompt( $prompt, $context_block )
			: $prompt_builder->prepend_context_to_prompt( $prompt, $context_block );

		$usage_meta = [
			'term_context' => [
				'taxonomy' => $taxonomy,
				'term_id'  => $term_id,
				'origin'   => $origin,
			],
			'term_options' => [
				'include_top_products' => $include_top_products,
				'top_products_limit'   => $top_products_limit,
				'force'                => $force_run,
			],
		];

		$response_format  = null;
		$use_response_format = $this->plugin->should_use_response_format( $provider, $settings );
		if ( $use_response_format && method_exists( $prompt_builder, 'get_term_response_format_definition' ) ) {
			$response_format = $prompt_builder->get_term_response_format_definition( $settings );
			$final_prompt    = $prompt_with_context;
		} elseif ( method_exists( $prompt_builder, 'append_term_response_instructions' ) ) {
			$final_prompt = $prompt_builder->append_term_response_instructions( $prompt_with_context, $settings );
		} else {
			$final_prompt = $prompt_builder->append_response_instructions( $prompt_with_context, $settings );
		}

		$request_parameters = $this->build_request_parameters_snapshot(
			$settings,
			[
				'provider'                 => $provider_key,
				'conversation_id'          => $conversation_id,
				'temperature'              => 0.7,
				'response_format_mode'     => $use_response_format ? 'structured' : 'prompt',
				'response_format_definition' => $response_format,
				'term_context'             => [
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				],
				'term_options'             => $usage_meta['term_options'],
				'origin'                   => $origin,
				'google_safety_settings'   => isset( $settings['google_safety_settings'] ) ? $settings['google_safety_settings'] : [],
			]
		);

		$model  = $this->plugin->get_selected_model( $provider, $settings );
		$request_parameters['model'] = $model;
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

		$logged_parameters = $request_parameters;
		if ( is_array( $result ) && isset( $result['request_payload'] ) ) {
			$logged_parameters['http_request'] = $result['request_payload'];
			unset( $result['request_payload'] );
		}

		if ( is_wp_error( $result ) ) {
				if ( $logger ) {
					$logger->log_generation_event(
						[
							'provider'      => $provider_key,
							'model'         => $model,
							'prompt'        => $final_prompt,
							'response'      => '',
							'usage'         => $usage_meta,
							'status'        => 'error',
							'error_message' => $result->get_error_message(),
							'post_id'       => 0,
							'parameters'    => $logged_parameters,
						]
					);
				}
			return $result;
		}

		$response_text = $this->extract_content_text( $result );
		$response_usage = is_array( $result ) && isset( $result['usage'] ) ? $result['usage'] : [];
		if ( ! is_array( $response_usage ) ) {
			$response_usage = [];
		}
		$response_usage['term_context'] = $usage_meta['term_context'];
		$response_usage['term_options'] = $usage_meta['term_options'];
		$parsed        = null;
		if ( method_exists( $prompt_builder, 'parse_term_structured_response' ) ) {
			$parsed = $prompt_builder->parse_term_structured_response( $response_text, $settings );
		}
		if ( is_wp_error( $parsed ) ) {
				if ( $logger ) {
					$logger->log_generation_event(
						[
							'provider'      => $provider_key,
							'model'         => $model,
							'prompt'        => $final_prompt,
							'response'      => $response_text,
							'usage'         => $response_usage,
							'status'        => 'error',
							'error_message' => $parsed->get_error_message(),
							'post_id'       => 0,
							'parameters'    => $logged_parameters,
						]
					);
				}
			return $parsed;
		}
		if ( ! is_array( $parsed ) ) {
			$parsed = [
				'description' => trim( (string) $response_text ),
			];
		}

			if ( $logger ) {
				$logger->log_generation_event(
					[
						'provider' => $provider_key,
						'model'    => $model,
						'prompt'   => $final_prompt,
						'response' => $response_text,
						'usage'    => $response_usage,
						'status'   => 'success',
						'post_id'  => 0,
						'parameters' => $logged_parameters,
					]
				);
			}

		return [
			'top_description'    => isset( $parsed['top_description'] ) ? $parsed['top_description'] : ( isset( $parsed['description'] ) ? $parsed['description'] : '' ),
			'bottom_description' => isset( $parsed['bottom_description'] ) ? $parsed['bottom_description'] : '',
			'meta_title'         => isset( $parsed['meta_title'] ) ? $parsed['meta_title'] : '',
			'meta_description'   => isset( $parsed['meta_description'] ) ? $parsed['meta_description'] : '',
			'focus_keywords'     => isset( $parsed['focus_keywords'] ) ? $parsed['focus_keywords'] : '',
			'description'        => isset( $parsed['description'] ) ? $parsed['description'] : ( isset( $parsed['top_description'] ) ? $parsed['top_description'] : '' ),
			'raw'                => $response_text,
		];
	}

	private function save_term_generation_result( $term, $result, $settings ) {
		$term_id  = isset( $term->term_id ) ? absint( $term->term_id ) : 0;
		$taxonomy = isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '';

		if ( ! $term_id || '' === $taxonomy ) {
			return new WP_Error( 'groq_ai_invalid_term', __( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$top_description = '';
		if ( isset( $result['top_description'] ) && '' !== trim( (string) $result['top_description'] ) ) {
			$top_description = (string) $result['top_description'];
		} elseif ( isset( $result['description'] ) ) {
			$top_description = (string) $result['description'];
		}

		if ( '' === trim( wp_strip_all_tags( $top_description ) ) ) {
			return new WP_Error( 'groq_ai_missing_description', __( 'De AI gaf geen omschrijving terug.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$update = wp_update_term(
			$term_id,
			$taxonomy,
			[
				'description' => wp_kses_post( $top_description ),
			]
		);

		if ( is_wp_error( $update ) ) {
			return $update;
		}

		$bottom_key = $this->get_bottom_meta_key( $term, $settings );
		if ( '' !== $bottom_key ) {
			$bottom_description = isset( $result['bottom_description'] ) ? (string) $result['bottom_description'] : '';
			update_term_meta( $term_id, $bottom_key, wp_kses_post( $bottom_description ) );
		}

		if ( $this->plugin->is_module_enabled( 'rankmath', $settings ) ) {
			$rankmath_keys = $this->get_rankmath_term_meta_keys( $term, $settings );
			update_term_meta( $term_id, $rankmath_keys['title'], sanitize_text_field( isset( $result['meta_title'] ) ? $result['meta_title'] : '' ) );
			update_term_meta( $term_id, $rankmath_keys['description'], sanitize_text_field( isset( $result['meta_description'] ) ? $result['meta_description'] : '' ) );
			update_term_meta( $term_id, $rankmath_keys['focus_keyword'], sanitize_text_field( isset( $result['focus_keywords'] ) ? $result['focus_keywords'] : '' ) );
		}

		return [
			'words' => $this->count_words( $top_description ),
		];
	}

	private function get_bottom_meta_key( $term, $settings ) {
		$default_key = '';
		if ( is_array( $settings ) && isset( $settings['term_bottom_description_meta_key'] ) ) {
			$default_key = sanitize_key( (string) $settings['term_bottom_description_meta_key'] );
		}

		$key = apply_filters( 'groq_ai_term_bottom_description_meta_key', $default_key, $term, $settings );
		$key = sanitize_key( (string) $key );

		return '' !== $key ? $key : 'groq_ai_term_bottom_description';
	}

	private function get_rankmath_term_meta_keys( $term, $settings ) {
		$defaults = [
			'title'        => 'rank_math_title',
			'description'  => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		];

		$keys = apply_filters( 'groq_ai_rankmath_term_meta_keys', $defaults, $term, $settings );
		if ( ! is_array( $keys ) ) {
			$keys = $defaults;
		}

		return [
			'title'        => isset( $keys['title'] ) ? sanitize_key( (string) $keys['title'] ) : 'rank_math_title',
			'description'  => isset( $keys['description'] ) ? sanitize_key( (string) $keys['description'] ) : 'rank_math_description',
			'focus_keyword' => isset( $keys['focus_keyword'] ) ? sanitize_key( (string) $keys['focus_keyword'] ) : 'rank_math_focus_keyword',
		];
	}

	private function get_term_prompt_text( $term ) {
		$prompt = '';

		if ( $term && isset( $term->term_id ) ) {
			$prompt = (string) get_term_meta( $term->term_id, 'groq_ai_term_custom_prompt', true );
		}

		$prompt = trim( $prompt );
		if ( '' !== $prompt ) {
			return $prompt;
		}

		$default_prompt = __( 'Schrijf een SEO-vriendelijke categorieomschrijving in het Nederlands. Gebruik duidelijke tussenkoppen en <p>-tags. Voeg geen prijsinformatie toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN );

		return apply_filters( 'groq_ai_default_term_prompt', $default_prompt, $term );
	}

	private function count_words( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

		if ( '' === $text ) {
			return 0;
		}

		if ( preg_match_all( '/\pL[\pL\pN\']*/u', $text, $matches ) ) {
			return count( $matches[0] );
		}

		return str_word_count( $text );
	}

	public function handle_generate_text() {
		if ( ! current_user_can( 'edit_products' ) ) {
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming voor deze actie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

		check_ajax_referer( 'groq_ai_generate', 'nonce' );

		$prompt  = isset( $_POST['prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prompt'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		if ( ! $post_id ) {
			wp_send_json_error( [ 'message' => __( 'Post-ID ontbreekt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post || is_wp_error( $post ) || 'product' !== $post->post_type ) {
			wp_send_json_error( [ 'message' => __( 'Product niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 404 );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Je hebt geen toestemming om dit product te bewerken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ], 403 );
		}

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
		if ( array_key_exists( 'attribute_includes', $_POST ) ) {
			$attribute_includes = [];
			$attribute_raw      = (string) wp_unslash( $_POST['attribute_includes'] );
			$decoded            = json_decode( $attribute_raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $value ) {
					$key = sanitize_key( (string) $value );
					if ( '' === $key ) {
						continue;
					}
					if ( in_array( $key, [ '__custom__', '__all__' ], true ) || 0 === strpos( $key, 'pa_' ) ) {
						$attribute_includes[] = $key;
					}
				}
			}
			$settings['product_attribute_includes'] = array_values( array_unique( $attribute_includes ) );
		}
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

		$product_context_text = $prompt_builder->build_product_context_block( $post_id, $context_fields, $prompt_image_mode, $image_context_limit, $settings );
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

		$request_parameters = $this->build_request_parameters_snapshot(
			$settings,
			[
				'provider'                 => $provider_key,
				'model'                    => $model,
				'post_id'                  => $post_id,
				'conversation_id'          => $conversation_id,
				'temperature'              => 0.7,
				'response_format_mode'     => $use_response_format ? 'structured' : 'prompt',
				'response_format_definition' => $response_format,
				'context_fields'           => $context_fields,
				'attribute_includes'       => isset( $settings['product_attribute_includes'] ) ? $settings['product_attribute_includes'] : [],
				'image_context'            => $image_context_meta,
				'google_safety_settings'   => isset( $settings['google_safety_settings'] ) ? $settings['google_safety_settings'] : [],
			]
		);

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

		$logged_parameters = $request_parameters;
		if ( is_array( $result ) && isset( $result['request_payload'] ) ) {
			$logged_parameters['http_request'] = $result['request_payload'];
			unset( $result['request_payload'] );
		}

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
					'parameters'    => $logged_parameters,
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
					'parameters'    => $logged_parameters,
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
				'parameters' => $logged_parameters,
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

	private function build_request_parameters_snapshot( $settings, array $additional = [] ) {
		$snapshot = [
			'settings' => $this->plugin->get_loggable_settings_snapshot( $settings ),
		];

		foreach ( $additional as $key => $value ) {
			$snapshot[ $key ] = $value;
		}

		return $snapshot;
	}
}
