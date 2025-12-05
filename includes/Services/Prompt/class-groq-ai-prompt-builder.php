<?php

/**
 * Bouwt prompts, verwerkt responses en verzamelt context.
 */
class Groq_AI_Prompt_Builder {
	/** @var Groq_AI_Settings_Manager */
	private $settings_manager;

	public function __construct( Groq_AI_Settings_Manager $settings_manager ) {
		$this->settings_manager = $settings_manager;
	}

	public function build_system_prompt( $settings, $conversation_id ) {
		$context          = isset( $settings['store_context'] ) ? trim( $settings['store_context'] ) : '';
		$base_instruction = __( 'Je bent een copywriter voor een WooCommerce winkel en schrijft overtuigende productbeschrijvingen.', 'groq-ai-product-text' );

		if ( $context ) {
			$base_instruction = sprintf(
				__( 'Je bent een copywriter voor een WooCommerce winkel. Gebruik de volgende context indien beschikbaar: %s', 'groq-ai-product-text' ),
				$context
			);
		}

		return sprintf(
			__( 'Conversatie-ID: %1$s. %2$s', 'groq-ai-product-text' ),
			$conversation_id,
			$base_instruction
		);
	}

	public function append_response_instructions( $prompt, $settings ) {
		$instructions = (string) ( $this->get_structured_response_instructions( $settings ) ?? '' );
		$prompt       = trim( (string) $prompt );

		if ( '' === $instructions ) {
			return $prompt;
		}

		if ( false !== strpos( $prompt, $instructions ) ) {
			return $prompt;
		}

		return $prompt . "\n\n" . $instructions;
	}

	public function parse_structured_response( $raw, $settings = null ) {
		if ( empty( $raw ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen data ontvangen van de AI.', 'groq-ai-product-text' ) );
		}

		$clean = trim( $raw );

		if ( preg_match( '/```(?:json)?\s*(.*?)```/is', $clean, $matches ) ) {
			$clean = trim( $matches[1] );
		}

		$decoded = json_decode( $clean, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'groq_ai_parse_error', __( 'Kon de AI-respons niet als JSON lezen. Probeer het opnieuw.', 'groq-ai-product-text' ) );
		}

		$fields = [
			'title'             => trim( (string) ( $decoded['title'] ?? '' ) ),
			'short_description' => trim( (string) ( $decoded['short_description'] ?? '' ) ),
			'description'       => trim( (string) ( $decoded['description'] ?? '' ) ),
		];

		if ( $this->settings_manager->is_module_enabled( 'rankmath', $settings ) ) {
			$keyword_limit   = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
			$focus_keywords  = [];
			$raw_keyword_set = isset( $decoded['focus_keywords'] ) ? $decoded['focus_keywords'] : [];

			if ( is_array( $raw_keyword_set ) ) {
				foreach ( $raw_keyword_set as $keyword ) {
					$keyword = trim( (string) $keyword );
					if ( '' !== $keyword ) {
						$focus_keywords[] = $keyword;
					}
				}
			} elseif ( is_string( $raw_keyword_set ) ) {
				$parts = preg_split( '/[,\\n]+/', $raw_keyword_set );
				if ( is_array( $parts ) ) {
					foreach ( $parts as $part ) {
						$part = trim( (string) $part );
						if ( '' !== $part ) {
							$focus_keywords[] = $part;
						}
					}
				}
			}

			$focus_keywords = array_slice( array_unique( $focus_keywords ), 0, $keyword_limit );

			$fields['meta_title']       = $this->truncate_meta_field( (string) ( $decoded['meta_title'] ?? '' ), 60 );
			$fields['meta_description'] = $this->truncate_meta_field( (string) ( $decoded['meta_description'] ?? '' ), 160 );
			$fields['focus_keywords']   = implode( ', ', $focus_keywords );
		}

		if ( implode( '', $fields ) === '' ) {
			return new WP_Error( 'groq_ai_parse_error', __( 'De AI-respons bevatte geen bruikbare velden.', 'groq-ai-product-text' ) );
		}

		return $fields;
	}

	public function parse_context_fields_from_request( $raw, $settings ) {
		if ( empty( $raw ) ) {
			return $settings['context_fields'];
		}

		$decoded = json_decode( wp_unslash( $raw ), true );

		if ( ! is_array( $decoded ) ) {
			return $settings['context_fields'];
		}

		$normalized = $this->settings_manager->normalize_context_fields( $decoded );

		if ( ! array_filter( $normalized ) ) {
			return $settings['context_fields'];
		}

		return $normalized;
	}

	public function build_product_context_block( $post_id, $fields ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$parts = [];

		if ( ! empty( $fields['title'] ) ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				$parts[] = sprintf( __( 'Titel: %s', 'groq-ai-product-text' ), wp_strip_all_tags( $title ) );
			}
		}

		if ( ! empty( $fields['short_description'] ) ) {
			$excerpt = get_post_field( 'post_excerpt', $post_id );
			if ( $excerpt ) {
				$parts[] = sprintf( __( 'Korte beschrijving: %s', 'groq-ai-product-text' ), wp_strip_all_tags( $excerpt ) );
			}
		}

		if ( ! empty( $fields['description'] ) ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( $content ) {
				$parts[] = sprintf( __( 'Beschrijving: %s', 'groq-ai-product-text' ), wp_strip_all_tags( $content ) );
			}
		}

		if ( ! empty( $fields['attributes'] ) ) {
			$attributes = $this->get_product_attributes_text( $post_id );
			if ( $attributes ) {
				$parts[] = sprintf( __( 'Attributen: %s', 'groq-ai-product-text' ), $attributes );
			}
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	public function prepend_context_to_prompt( $prompt, $context ) {
		$context = trim( (string) $context );

		if ( '' === $context ) {
			return $prompt;
		}

		$intro = __( 'Gebruik de volgende productcontext bij het schrijven:', 'groq-ai-product-text' );

		return $intro . "\n" . $context . "\n\n" . $prompt;
	}

	public function get_response_format_definition( $settings = null ) {
		$rankmath_enabled = $this->settings_manager->is_module_enabled( 'rankmath', $settings );
		$keyword_limit    = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
		$title_pixels     = $this->settings_manager->get_rankmath_meta_title_pixel_limit( $settings );
		$desc_pixels      = $this->settings_manager->get_rankmath_meta_description_pixel_limit( $settings );

		$properties = [
			'title'             => [
				'type'        => 'string',
				'description' => __( 'Korte, overtuigende producttitel in het Nederlands.', 'groq-ai-product-text' ),
				'minLength'   => 3,
			],
			'short_description' => [
				'type'        => 'string',
				'description' => __( "Korte HTML-beschrijving in <p>-tags (maximaal 2 alinea's).", 'groq-ai-product-text' ),
				'minLength'   => 10,
			],
			'description'       => [
				'type'        => 'string',
				'description' => __( 'Uitgebreide HTML-productbeschrijving met paragrafen en eventueel lijsten.', 'groq-ai-product-text' ),
				'minLength'   => 20,
			],
		];

		if ( $rankmath_enabled ) {
			$properties['meta_title'] = [
				'type'        => 'string',
				'description' => sprintf(
					/* translators: 1: maximum character count, 2: maximum pixels */
					__( 'SEO-meta title (max. %1$d tekens en %2$d pixels).', 'groq-ai-product-text' ),
					60,
					$title_pixels
				),
				'maxLength'   => 120,
			];
			$properties['meta_description'] = [
				'type'        => 'string',
				'description' => sprintf(
					/* translators: 1: maximum character count, 2: maximum pixels */
					__( 'SEO-meta description (max. %1$d tekens en %2$d pixels).', 'groq-ai-product-text' ),
					160,
					$desc_pixels
				),
				'maxLength'   => 320,
			];
			$properties['focus_keywords'] = [
				'type'        => 'array',
				'description' => __( 'Lijst met korte zoekwoorden zonder hashtags of extra tekst.', 'groq-ai-product-text' ),
				'maxItems'    => max( 1, $keyword_limit ),
				'items'       => [
					'type'      => 'string',
					'minLength' => 1,
				],
			];
		}

		$schema = [
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => [ 'title', 'short_description', 'description' ],
			'additionalProperties' => false,
		];

		return [
			'type'        => 'json_schema',
			'json_schema' => [
				'name'   => 'groq_ai_product_text',
				'schema' => $schema,
			],
		];
	}

	private function get_structured_response_instructions( $settings = null ) {
		$schema_parts = [
			'"title":"..."',
			'"short_description":"..."',
			'"description":"..."',
		];

		$rankmath_enabled = $this->settings_manager->is_module_enabled( 'rankmath', $settings );
		if ( $rankmath_enabled ) {
			$schema_parts[] = '"meta_title":"..."';
			$schema_parts[] = '"meta_description":"..."';
			$schema_parts[] = '"focus_keywords":["...","..."]';
		}

		$json_structure = '{' . implode( ',', $schema_parts ) . '}';

		$instruction = sprintf(
			/* translators: %s: JSON structure example */
			__( 'Geef ALLEEN een geldig JSON-object terug met deze structuur: %s. Gebruik dubbele aanhalingstekens, geen Markdown of extra tekst. Gebruik \\n voor regeleinden. Zorg dat zowel short_description als description nooit leeg zijn.', 'groq-ai-product-text' ),
			$json_structure
		);

		if ( $rankmath_enabled ) {
			$keyword_limit = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
			$title_pixels  = $this->settings_manager->get_rankmath_meta_title_pixel_limit( $settings );
			$desc_pixels   = $this->settings_manager->get_rankmath_meta_description_pixel_limit( $settings );
			$instruction   .= ' ' . sprintf(
				/* translators: 1: focus keyword limit, 2: meta title pixel limit, 3: meta description pixel limit */
				__( 'Beperk meta_title tot maximaal 60 tekens en %2$d pixels en meta_description tot maximaal 160 tekens en %3$d pixels. Lever maximaal %1$d focuskeywords in het focus_keywords-array (korte termen zonder hashtag of extra tekst).', 'groq-ai-product-text' ),
				$keyword_limit,
				$title_pixels,
				$desc_pixels
			);
		}

		$instruction .= ' ' . __( 'Zorg dat short_description en description geldige HTML bevatten (gebruik minimaal <p>-tags en waar relevant lijstjes of benadrukking). Voeg geen extra tekst buiten het JSON-object toe.', 'groq-ai-product-text' );

		return $instruction;
	}

	private function truncate_meta_field( $text, $limit ) {
		$text = trim( (string) $text );

		if ( '' === $text || $limit <= 0 ) {
			return '';
		}

		if ( function_exists( 'mb_strlen' ) ) {
			if ( mb_strlen( $text ) <= $limit ) {
				return $text;
			}

			return mb_substr( $text, 0, $limit );
		}

		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		return substr( $text, 0, $limit );
	}

	private function get_product_attributes_text( $post_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return '';
		}

		$product = wc_get_product( $post_id );

		if ( ! $product ) {
			return '';
		}

		$attributes = $product->get_attributes();

		if ( empty( $attributes ) ) {
			return '';
		}

		$lines = [];

		foreach ( $attributes as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$terms = wc_get_product_terms( $post_id, $attribute->get_name(), [ 'fields' => 'names' ] );
				$value = implode( ', ', array_map( 'sanitize_text_field', (array) $terms ) );
				$label = wc_attribute_label( $attribute->get_name() );
			} else {
				$options = $attribute->get_options();
				$value   = implode( ', ', array_map( 'sanitize_text_field', (array) $options ) );
				$label   = sanitize_text_field( $attribute->get_name() );
			}

			$value = trim( $value );

			if ( '' !== $value ) {
				$lines[] = sprintf( '%s: %s', $label, $value );
			}
		}

		return implode( '; ', $lines );
	}
}
