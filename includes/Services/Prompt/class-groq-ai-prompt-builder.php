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
		$base_instruction = __( 'Je bent een copywriter voor een WooCommerce winkel en schrijft overtuigende productbeschrijvingen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );

		if ( $context ) {
			$base_instruction = sprintf(
				__( 'Je bent een copywriter voor een WooCommerce winkel. Gebruik de volgende context indien beschikbaar: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$context
			);
		}

		return sprintf(
			__( 'Conversatie-ID: %1$s. %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			$conversation_id,
			$base_instruction
		);
	}

	public function build_term_system_prompt( $settings, $conversation_id, $term ) {
		$context          = isset( $settings['store_context'] ) ? trim( $settings['store_context'] ) : '';
		$term_name        = is_object( $term ) && isset( $term->name ) ? (string) $term->name : '';
		$base_instruction = __( 'Je bent een copywriter voor een WooCommerce winkel en schrijft SEO-vriendelijke categorie- en merkpagina teksten.', GROQ_AI_PRODUCT_TEXT_DOMAIN );

		if ( $context ) {
			$base_instruction = sprintf(
				__( 'Je bent een copywriter voor een WooCommerce winkel. Gebruik de volgende winkelcontext indien beschikbaar: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$context
			);
		}

		if ( '' !== $term_name ) {
			$base_instruction .= ' ' . sprintf(
				__( 'Je schrijft nu voor de term: %s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$term_name
			);
		}

		return sprintf(
			__( 'Conversatie-ID: %1$s. %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			$conversation_id,
			$base_instruction
		);
	}

	private function detect_brand_taxonomy() {
		$candidates = [
			'product_brand',
			'pwb-brand',
			'yith_product_brand',
			'berocket_brand',
		];

		if ( taxonomy_exists( 'pa_brand' ) ) {
			array_unshift( $candidates, 'pa_brand' );
		}

		$candidates = apply_filters( 'groq_ai_brand_taxonomy_candidates', $candidates );
		$found = '';
		foreach ( $candidates as $tax ) {
			$tax = sanitize_key( (string) $tax );
			if ( $tax && taxonomy_exists( $tax ) ) {
				$found = $tax;
				break;
			}
		}

		$found = apply_filters( 'groq_ai_brand_taxonomy', $found );
		return sanitize_key( (string) $found );
	}

	private function get_internal_link_suggestions( $taxonomy, $current_term_id, $limit = 10 ) {
		$taxonomy        = sanitize_key( (string) $taxonomy );
		$current_term_id = absint( $current_term_id );
		$limit           = max( 0, min( 50, absint( $limit ) ) );
		if ( '' === $taxonomy || $limit <= 0 || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}

		$cache_key = 'groq_ai_internal_links_' . $taxonomy;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			$all = $cached;
		} else {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
					'orderby'    => 'name',
					'order'      => 'ASC',
					'number'     => 0,
				]
			);
			if ( is_wp_error( $terms ) ) {
				$terms = [];
			}

			$all = [];
			foreach ( (array) $terms as $t ) {
				if ( ! $t || ! is_object( $t ) || empty( $t->term_id ) ) {
					continue;
				}
				$link = get_term_link( $t );
				if ( is_wp_error( $link ) || ! is_string( $link ) || '' === $link ) {
					continue;
				}
				$name = isset( $t->name ) ? trim( wp_strip_all_tags( (string) $t->name ) ) : '';
				if ( '' === $name ) {
					continue;
				}
				$all[] = [
					'term_id' => absint( $t->term_id ),
					'name'    => $name,
					'url'     => esc_url_raw( $link ),
				];
			}

			set_transient( $cache_key, $all, HOUR_IN_SECONDS );
		}

		$suggestions = [];
		foreach ( $all as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$tid = isset( $row['term_id'] ) ? absint( $row['term_id'] ) : 0;
			if ( $current_term_id && $tid === $current_term_id ) {
				continue;
			}
			$name = isset( $row['name'] ) ? (string) $row['name'] : '';
			$url  = isset( $row['url'] ) ? (string) $row['url'] : '';
			if ( '' === $name || '' === $url ) {
				continue;
			}
			$suggestions[] = [
				'name' => $name,
				'url'  => $url,
			];
			if ( count( $suggestions ) >= $limit ) {
				break;
			}
		}

		return $suggestions;
	}

	private function build_internal_links_context( $term ) {
		if ( ! $term || ! is_object( $term ) ) {
			return '';
		}
		$current_tax = isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '';
		$current_id  = isset( $term->term_id ) ? absint( $term->term_id ) : 0;

		$links = [];

		// Categories.
		if ( taxonomy_exists( 'product_cat' ) ) {
			$links = array_merge( $links, $this->get_internal_link_suggestions( 'product_cat', 'product_cat' === $current_tax ? $current_id : 0, 10 ) );
		}

		// Brands.
		$brand_tax = $this->detect_brand_taxonomy();
		if ( '' !== $brand_tax ) {
			$links = array_merge( $links, $this->get_internal_link_suggestions( $brand_tax, $brand_tax === $current_tax ? $current_id : 0, 10 ) );
		}

		if ( empty( $links ) ) {
			return '';
		}

		$lines = [];
		$lines[] = __( 'Interne links (gebruik 2–5 relevante links in de tekst, als HTML: <a href="URL">Anker</a>):', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		foreach ( $links as $link ) {
			$name = isset( $link['name'] ) ? (string) $link['name'] : '';
			$url  = isset( $link['url'] ) ? (string) $link['url'] : '';
			if ( '' === $name || '' === $url ) {
				continue;
			}
			$lines[] = sprintf( '- %s → %s', $name, $url );
		}

		return implode( "\n", $lines );
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
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen data ontvangen van de AI.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$clean = trim( $raw );

		if ( preg_match( '/```(?:json)?\s*(.*?)```/is', $clean, $matches ) ) {
			$clean = trim( $matches[1] );
		}

		$decoded = json_decode( $clean, true );

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'groq_ai_parse_error', __( 'Kon de AI-respons niet als JSON lezen. Probeer het opnieuw.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$fields = [
			'title'             => trim( (string) ( $decoded['title'] ?? '' ) ),
			'short_description' => trim( (string) ( $decoded['short_description'] ?? '' ) ),
			'description'       => trim( (string) ( $decoded['description'] ?? '' ) ),
		];

		$title_suggestions = [];
		if ( isset( $decoded['title_suggestions'] ) && is_array( $decoded['title_suggestions'] ) ) {
			foreach ( $decoded['title_suggestions'] as $suggestion ) {
				$suggestion = sanitize_text_field( (string) $suggestion );
				$suggestion = trim( preg_replace( '/\s+/', ' ', $suggestion ) );

				if ( '' === $suggestion ) {
					continue;
				}

				$title_suggestions[] = $suggestion;

				if ( count( $title_suggestions ) >= 3 ) {
					break;
				}
			}
		}

		if ( empty( $title_suggestions ) && '' !== $fields['title'] ) {
			$title_suggestions[] = $fields['title'];
		}

		if ( '' === $fields['title'] && ! empty( $title_suggestions ) ) {
			$fields['title'] = $title_suggestions[0];
		}

		$fields['title_suggestions'] = $title_suggestions;

		$slug_value = isset( $decoded['slug'] ) ? sanitize_title( $decoded['slug'] ) : '';
		if ( '' === $slug_value && '' !== $fields['title'] ) {
			$slug_value = sanitize_title( $fields['title'] );
		}
		$fields['slug'] = $slug_value;

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

		$primary_values = [
			$fields['title'],
			$fields['short_description'],
			$fields['description'],
		];

		$has_primary_content = false;
		foreach ( $primary_values as $value ) {
			if ( '' !== trim( (string) $value ) ) {
				$has_primary_content = true;
				break;
			}
		}

		if ( ! $has_primary_content ) {
			return new WP_Error( 'groq_ai_parse_error', __( 'De AI-respons bevatte geen bruikbare velden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
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

	public function build_product_context_block( $post_id, $fields, $image_mode = 'url', $image_limit = 3, $settings = null ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return '';
		}

		$parts = [];

		if ( ! empty( $fields['title'] ) ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				$parts[] = sprintf( __( 'Titel: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), wp_strip_all_tags( $title ) );
			}
		}

		if ( ! empty( $fields['short_description'] ) ) {
			$excerpt = get_post_field( 'post_excerpt', $post_id );
			if ( $excerpt ) {
				$parts[] = sprintf( __( 'Korte beschrijving: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), wp_strip_all_tags( $excerpt ) );
			}
		}

		if ( ! empty( $fields['description'] ) ) {
			$content = get_post_field( 'post_content', $post_id );
			if ( $content ) {
				$parts[] = sprintf( __( 'Beschrijving: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), wp_strip_all_tags( $content ) );
			}
		}

		$attribute_includes = [];
		if ( is_array( $settings ) && isset( $settings['product_attribute_includes'] ) && is_array( $settings['product_attribute_includes'] ) ) {
			$attribute_includes = array_values( array_unique( array_map( 'sanitize_key', $settings['product_attribute_includes'] ) ) );
		}

		$include_attributes = ! empty( $attribute_includes ) || ! empty( $fields['attributes'] );
		if ( $include_attributes ) {
			$attributes = $this->get_product_attributes_text( $post_id, $attribute_includes );
			if ( $attributes ) {
				$parts[] = sprintf( __( 'Attributen: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $attributes );
			}
		}

		if ( ! empty( $fields['images'] ) && 'url' === $image_mode ) {
			$images = $this->get_product_images_text( $post_id, $image_limit );
			if ( $images ) {
				$parts[] = sprintf( __( 'Afbeeldingen: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $images );
			}
		}

		if ( ! empty( $fields['brands'] ) ) {
			$brands_context = $this->get_product_brand_context_text( $post_id );
			if ( '' !== $brands_context ) {
				$parts[] = sprintf( __( 'Merken: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $brands_context );
			}
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	private function get_product_brand_context_text( $post_id ) {
		$post_id  = absint( $post_id );
		$taxonomy = $this->detect_brand_taxonomy();

		if ( ! $post_id || '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, $taxonomy );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return '';
		}

		$entries = [];
		foreach ( $terms as $term ) {
			if ( ! $term || ! is_object( $term ) ) {
				continue;
			}

			$name = isset( $term->name ) ? trim( wp_strip_all_tags( (string) $term->name ) ) : '';
			if ( '' === $name ) {
				continue;
			}

			$description = isset( $term->description ) ? trim( wp_strip_all_tags( (string) $term->description ) ) : '';
			if ( '' !== $description ) {
				$entries[] = sprintf( '%s - %s', $name, $description );
			} else {
				$entries[] = $name;
			}
		}

		$entries = array_values( array_unique( array_filter( $entries ) ) );
		if ( empty( $entries ) ) {
			return '';
		}

		$context = implode( '; ', $entries );

		/**
		 * Filters the product brand context string added to prompts.
		 *
		 * @param string $context
		 * @param int    $post_id
		 * @param array  $terms
		 * @param string $taxonomy
		 */
		return (string) apply_filters( 'groq_ai_product_brand_context', $context, $post_id, $terms, $taxonomy );
	}

	public function prepend_context_to_prompt( $prompt, $context ) {
		$context = trim( (string) $context );

		if ( '' === $context ) {
			return $prompt;
		}

		$intro = __( 'Gebruik de volgende productcontext bij het schrijven:', GROQ_AI_PRODUCT_TEXT_DOMAIN );

		return $intro . "\n" . $context . "\n\n" . $prompt;
	}

	public function build_term_context_block( $term, $options = [], $settings = null ) {
		if ( ! $term || ! is_object( $term ) ) {
			return '';
		}

		$taxonomy = isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '';
		$term_id  = isset( $term->term_id ) ? absint( $term->term_id ) : 0;
		if ( '' === $taxonomy || ! $term_id ) {
			return '';
		}

		$include_top_products = ! empty( $options['include_top_products'] );
		$top_products_limit   = isset( $options['top_products_limit'] ) ? absint( $options['top_products_limit'] ) : 10;
		$top_products_limit   = max( 1, min( 25, $top_products_limit ) );

		$parts = [];
		$parts[] = sprintf( __( 'Term: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), wp_strip_all_tags( (string) $term->name ) );
		if ( isset( $term->slug ) && '' !== (string) $term->slug ) {
			$parts[] = sprintf( __( 'Slug: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), sanitize_title( (string) $term->slug ) );
		}
		if ( isset( $term->count ) ) {
			$parts[] = sprintf( __( 'Aantal producten: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), (string) absint( $term->count ) );
		}
		if ( isset( $term->description ) && '' !== trim( (string) $term->description ) ) {
			$parts[] = sprintf( __( 'Huidige omschrijving: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), wp_strip_all_tags( (string) $term->description ) );
		}

		$bottom_meta_key = $this->resolve_term_bottom_description_meta_key( $term, $settings );
		$bottom_meta_key = '' !== $bottom_meta_key ? $bottom_meta_key : 'groq_ai_term_bottom_description';
		if ( '' !== $bottom_meta_key && $term_id ) {
			$bottom = (string) get_term_meta( $term_id, $bottom_meta_key, true );
			$bottom = trim( wp_strip_all_tags( $bottom ) );
			if ( '' !== $bottom ) {
				$parts[] = sprintf( __( 'Huidige omschrijving (onderaan): %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $bottom );
			}
		}

		if ( $include_top_products ) {
			$top_products = $this->get_top_products_for_term( $taxonomy, $term_id, $top_products_limit );
			if ( ! empty( $top_products ) ) {
				$lines = [];
				foreach ( $top_products as $product_row ) {
					$lines[] = sprintf( '- %s', $product_row );
				}
				$parts[] = __( 'Top verkochte producten (indicatief):', GROQ_AI_PRODUCT_TEXT_DOMAIN ) . "\n" . implode( "\n", $lines );
			}
		}

		$internal_links = $this->build_internal_links_context( $term );
		$internal_links = trim( (string) $internal_links );
		if ( '' !== $internal_links ) {
			$parts[] = $internal_links;
		}

		$google_context = apply_filters( 'groq_ai_term_google_context', '', $term, $settings );
		$google_context = trim( (string) $google_context );
		if ( '' !== $google_context ) {
			$parts[] = $google_context;
		}

		return implode( "\n\n", array_filter( $parts ) );
	}

	public function prepend_term_context_to_prompt( $prompt, $context ) {
		$context = trim( (string) $context );
		if ( '' === $context ) {
			return $prompt;
		}
		$intro = __( 'Gebruik de volgende categorie/term-context bij het schrijven:', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		return $intro . "\n" . $context . "\n\n" . $prompt;
	}

	public function get_term_response_format_definition( $settings = null ) {
		$rankmath_enabled = $this->settings_manager->is_module_enabled( 'rankmath', $settings );
		$keyword_limit    = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
		$title_pixels     = $this->settings_manager->get_rankmath_meta_title_pixel_limit( $settings );
		$desc_pixels      = $this->settings_manager->get_rankmath_meta_description_pixel_limit( $settings );

		$properties = [
			'top_description' => [
				'type'        => 'string',
				'description' => __( 'Korte HTML-omschrijving (1 alinea) voor de standaard WordPress term description. Exact één alinea in <p>-tags.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 20,
			],
			'bottom_description' => [
				'type'        => 'string',
				'description' => __( 'Uitgebreide HTML-omschrijving (helemaal onderaan), 2–4 alinea’s, met paragrafen en eventueel lijstjes.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 20,
			],
		];

		if ( $rankmath_enabled ) {
			$properties['meta_title'] = [
				'type'        => 'string',
				'description' => sprintf(
					__( 'SEO-meta title (max. %1$d tekens en %2$d pixels).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					60,
					$title_pixels
				),
				'maxLength'   => 120,
			];
			$properties['meta_description'] = [
				'type'        => 'string',
				'description' => sprintf(
					__( 'SEO-meta description (max. %1$d tekens en %2$d pixels).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					160,
					$desc_pixels
				),
				'maxLength'   => 320,
			];
			$properties['focus_keywords'] = [
				'type'        => 'array',
				'description' => __( 'Lijst met korte zoekwoorden zonder hashtags of extra tekst.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
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
			'required'             => [ 'top_description', 'bottom_description' ],
			'additionalProperties' => false,
		];

		return [
			'type'        => 'json_schema',
			'json_schema' => [
				'name'   => 'groq_ai_term_text',
				'schema' => $schema,
			],
		];
	}

	public function append_term_response_instructions( $prompt, $settings ) {
		$instructions = (string) ( $this->get_term_structured_response_instructions( $settings ) ?? '' );
		$prompt       = trim( (string) $prompt );
		if ( '' === $instructions ) {
			return $prompt;
		}
		if ( false !== strpos( $prompt, $instructions ) ) {
			return $prompt;
		}
		return $prompt . "\n\n" . $instructions;
	}

	public function parse_term_structured_response( $raw, $settings = null ) {
		if ( empty( $raw ) ) {
			return new WP_Error( 'groq_ai_empty_response', __( 'Geen data ontvangen van de AI.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$clean = trim( (string) $raw );
		if ( preg_match( '/```(?:json)?\s*(.*?)```/is', $clean, $matches ) ) {
			$clean = trim( $matches[1] );
		}

		$decoded = json_decode( $clean, true );
		if ( ! is_array( $decoded ) ) {
			// Fallback: treat as plain text.
			return [
				'description' => trim( (string) $raw ),
			];
		}

		$top = isset( $decoded['top_description'] ) ? trim( (string) $decoded['top_description'] ) : '';
		$bottom = isset( $decoded['bottom_description'] ) ? trim( (string) $decoded['bottom_description'] ) : '';
		// Backward compatibility: older prompts only returned `description`.
		if ( '' === $top && isset( $decoded['description'] ) ) {
			$top = trim( (string) $decoded['description'] );
		}
		if ( '' === $top && '' === $bottom ) {
			return new WP_Error( 'groq_ai_parse_error', __( 'De AI-respons bevatte geen top_description/bottom_description velden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$result = [];
		if ( '' !== $top ) {
			$result['top_description'] = $top;
			// For backwards compatibility with existing UI, keep `description` alias.
			$result['description'] = $top;
		}
		if ( '' !== $bottom ) {
			$result['bottom_description'] = $bottom;
		}

		if ( isset( $decoded['meta_title'] ) ) {
			$result['meta_title'] = $this->truncate_meta_field( (string) $decoded['meta_title'], 60 );
		}
		if ( isset( $decoded['meta_description'] ) ) {
			$result['meta_description'] = $this->truncate_meta_field( (string) $decoded['meta_description'], 160 );
		}
		if ( isset( $decoded['focus_keywords'] ) ) {
			if ( is_array( $decoded['focus_keywords'] ) ) {
				$keywords = [];
				foreach ( $decoded['focus_keywords'] as $kw ) {
					$kw = trim( (string) $kw );
					if ( '' !== $kw ) {
						$keywords[] = $kw;
					}
				}
				$keywords = array_values( array_unique( $keywords ) );
				$result['focus_keywords'] = implode( ', ', $keywords );
			}
		}

		return $result;
	}

	private function get_term_structured_response_instructions( $settings = null ) {
		$schema_parts = [
			'"top_description":"..."',
			'"bottom_description":"..."',
		];

		$rankmath_enabled = $this->settings_manager->is_module_enabled( 'rankmath', $settings );
		if ( $rankmath_enabled ) {
			$schema_parts[] = '"meta_title":"..."';
			$schema_parts[] = '"meta_description":"..."';
			$schema_parts[] = '"focus_keywords":["...","..."]';
		}

		$json_structure = '{' . implode( ',', $schema_parts ) . '}';

		$instruction = sprintf(
			__( 'Geef ALLEEN een geldig JSON-object terug met deze structuur: %s. Gebruik dubbele aanhalingstekens, geen Markdown of extra tekst. Gebruik \n voor regeleinden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			$json_structure
		);

		$instruction .= ' ' . __( 'Zorg dat top_description en bottom_description geldige HTML bevatten. top_description moet exact één alinea zijn in <p>-tags. bottom_description moet 2–4 alinea’s bevatten.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$instruction .= ' ' . __( 'Voeg geen extra tekst buiten het JSON-object toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$instruction .= ' ' . __( 'Als in de context een sectie "Interne links" staat, verwerk dan 2–5 van deze links natuurlijk in bottom_description als HTML-links (<a href="URL">Anker</a>).', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		return $instruction;
	}

	private function resolve_term_bottom_description_meta_key( $term = null, $settings = null ) {
		$default_key = '';
		if ( is_array( $settings ) && isset( $settings['term_bottom_description_meta_key'] ) ) {
			$default_key = sanitize_key( (string) $settings['term_bottom_description_meta_key'] );
		}
		$key = apply_filters( 'groq_ai_term_bottom_description_meta_key', $default_key, $term, $settings );
		return sanitize_key( (string) $key );
	}

	private function get_top_products_for_term( $taxonomy, $term_id, $limit = 10 ) {
		$taxonomy = sanitize_key( (string) $taxonomy );
		$term_id  = absint( $term_id );
		$limit    = max( 1, min( 25, absint( $limit ) ) );

		$query = new WP_Query(
			[
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'no_found_rows'  => true,
				'meta_key'       => 'total_sales',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
				'tax_query'      => [
					[
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => [ $term_id ],
					],
				],
			]
		);

		$rows = [];
		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$title = isset( $post->post_title ) ? wp_strip_all_tags( (string) $post->post_title ) : '';
				$rows[] = $title;
			}
		}
		wp_reset_postdata();

		return array_values( array_filter( $rows ) );
	}

	public function get_response_format_definition( $settings = null ) {
		$rankmath_enabled = $this->settings_manager->is_module_enabled( 'rankmath', $settings );
		$keyword_limit    = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
		$title_pixels     = $this->settings_manager->get_rankmath_meta_title_pixel_limit( $settings );
		$desc_pixels      = $this->settings_manager->get_rankmath_meta_description_pixel_limit( $settings );

		$properties = [
			'title_suggestions' => [
				'type'        => 'array',
				'description' => __( 'Exact drie korte producttitelvoorstellen in het Nederlands. Kies de beste ook als title.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minItems'    => 3,
				'maxItems'    => 3,
				'items'       => [
					'type'      => 'string',
					'minLength' => 3,
					'maxLength' => 120,
				],
			],
			'title'             => [
				'type'        => 'string',
				'description' => __( 'Korte, overtuigende producttitel in het Nederlands.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 3,
			],
			'slug'              => [
				'type'        => 'string',
				'description' => __( 'Productslug voor de URL (alleen kleine letters, cijfers en koppeltekens).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 3,
				'pattern'     => '^[a-z0-9\\-]+$',
			],
			'short_description' => [
				'type'        => 'string',
				'description' => __( "Korte HTML-beschrijving in <p>-tags (maximaal 2 alinea's).", GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 10,
			],
			'description'       => [
				'type'        => 'string',
				'description' => __( 'Uitgebreide HTML-productbeschrijving met paragrafen en eventueel lijsten.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'minLength'   => 20,
			],
		];

		if ( $rankmath_enabled ) {
			$properties['meta_title'] = [
				'type'        => 'string',
				'description' => sprintf(
					/* translators: 1: maximum character count, 2: maximum pixels */
					__( 'SEO-meta title (max. %1$d tekens en %2$d pixels).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					60,
					$title_pixels
				),
				'maxLength'   => 120,
			];
			$properties['meta_description'] = [
				'type'        => 'string',
				'description' => sprintf(
					/* translators: 1: maximum character count, 2: maximum pixels */
					__( 'SEO-meta description (max. %1$d tekens en %2$d pixels).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					160,
					$desc_pixels
				),
				'maxLength'   => 320,
			];
			$properties['focus_keywords'] = [
				'type'        => 'array',
				'description' => __( 'Lijst met korte zoekwoorden zonder hashtags of extra tekst.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
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
			'required'             => [ 'title_suggestions', 'title', 'slug', 'short_description', 'description' ],
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
			'"title_suggestions":["...","...","..."]',
			'"title":"..."',
			'"slug":"..."',
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
			__( 'Geef ALLEEN een geldig JSON-object terug met deze structuur: %s. Gebruik dubbele aanhalingstekens, geen Markdown of extra tekst. Gebruik \\n voor regeleinden. Zorg dat zowel short_description als description nooit leeg zijn.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			$json_structure
		);

		if ( $rankmath_enabled ) {
			$keyword_limit = $this->settings_manager->get_rankmath_focus_keyword_limit( $settings );
			$title_pixels  = $this->settings_manager->get_rankmath_meta_title_pixel_limit( $settings );
			$desc_pixels   = $this->settings_manager->get_rankmath_meta_description_pixel_limit( $settings );
			$instruction   .= ' ' . sprintf(
				/* translators: 1: focus keyword limit, 2: meta title pixel limit, 3: meta description pixel limit */
				__( 'Beperk meta_title tot maximaal 60 tekens en %2$d pixels en meta_description tot maximaal 160 tekens en %3$d pixels. Lever maximaal %1$d focuskeywords in het focus_keywords-array (korte termen zonder hashtag of extra tekst).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$keyword_limit,
				$title_pixels,
				$desc_pixels
			);
		}

		$instruction .= ' ' . __( 'Lever exact drie verschillende titelvoorstellen in title_suggestions en kopieer de beste keuze naar title.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$instruction .= ' ' . __( 'Zorg dat short_description en description geldige HTML bevatten (gebruik minimaal <p>-tags en waar relevant lijstjes of benadrukking). Voeg geen extra tekst buiten het JSON-object toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$instruction .= ' ' . __( 'Maak de slug URL-vriendelijk, gebruik alleen kleine letters, cijfers en koppeltekens en geen spaties.', GROQ_AI_PRODUCT_TEXT_DOMAIN );

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

	private function get_product_attributes_text( $post_id, $attribute_includes = [] ) {
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

		$attribute_includes = is_array( $attribute_includes ) ? array_values( array_unique( array_map( 'sanitize_key', $attribute_includes ) ) ) : [];
		$include_all        = empty( $attribute_includes ) || in_array( '__all__', $attribute_includes, true );
		$include_custom     = $include_all || in_array( '__custom__', $attribute_includes, true );

		$lines = [];

		foreach ( $attributes as $attribute ) {
			if ( $attribute->is_taxonomy() ) {
				$taxonomy_name = sanitize_key( (string) $attribute->get_name() );
				if ( ! $include_all && ! in_array( $taxonomy_name, $attribute_includes, true ) ) {
					continue;
				}

				$terms = wc_get_product_terms( $post_id, $taxonomy_name, [ 'fields' => 'names' ] );
				$value = implode( ', ', array_map( 'sanitize_text_field', (array) $terms ) );
				$label = wc_attribute_label( $taxonomy_name );
			} else {
				if ( ! $include_custom ) {
					continue;
				}

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

	private function get_product_images_text( $post_id, $limit = 3 ) {
		$limit     = max( 0, (int) $limit );
		$image_ids = $this->get_product_image_ids( $post_id );

		if ( $limit > 0 ) {
			$image_ids = array_slice( $image_ids, 0, $limit );
		}

		if ( empty( $image_ids ) ) {
			return '';
		}

		$entries = [];

		foreach ( $image_ids as $index => $attachment_id ) {
			$descriptor = $this->describe_product_image( $attachment_id, $index + 1 );
			if ( ! $descriptor ) {
				continue;
			}

			$entries[] = sprintf( '%s - %s', $descriptor['label'], $descriptor['url'] );
		}

		return implode( '; ', array_filter( $entries ) );
	}

	public function get_product_image_payloads( $post_id, $limit = 3, $max_filesize = 1572864 ) {
		$limit = max( 0, (int) $limit );

		if ( $limit <= 0 ) {
			return [];
		}

		$image_ids = array_slice( $this->get_product_image_ids( $post_id ), 0, $limit );

		if ( empty( $image_ids ) ) {
			return [];
		}

		$payloads = [];

		foreach ( $image_ids as $index => $attachment_id ) {
			$descriptor = $this->describe_product_image( $attachment_id, $index + 1 );
			if ( ! $descriptor || empty( $descriptor['path'] ) ) {
				continue;
			}

			$path = $descriptor['path'];

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				continue;
			}

			$filesize = filesize( $path );
			if ( false !== $filesize && $filesize > $max_filesize ) {
				continue;
			}

			$data = @file_get_contents( $path );
			if ( false === $data ) {
				continue;
			}

			$payloads[] = [
				'attachment_id' => $attachment_id,
				'label'         => $descriptor['label'],
				'mime_type'     => $descriptor['mime_type'],
				'data'          => base64_encode( $data ),
				'url'           => $descriptor['url'],
			];
		}

		return $payloads;
	}

	public function get_product_image_count( $post_id ) {
		return count( $this->get_product_image_ids( $post_id ) );
	}

	private function get_product_image_ids( $post_id ) {
		$post_id = absint( $post_id );

		if ( ! $post_id ) {
			return [];
		}

		$image_ids = [];

		$featured_id = get_post_thumbnail_id( $post_id );
		if ( $featured_id ) {
			$image_ids[] = $featured_id;
		}

		$gallery_ids = [];
		if ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post_id );
			if ( $product ) {
				$gallery_ids = (array) $product->get_gallery_image_ids();
			}
		}

		if ( empty( $gallery_ids ) ) {
			$raw_gallery = get_post_meta( $post_id, '_product_image_gallery', true );
			if ( is_string( $raw_gallery ) && '' !== trim( $raw_gallery ) ) {
				$gallery_ids = array_filter( array_map( 'absint', explode( ',', $raw_gallery ) ) );
			}
		}

		if ( ! empty( $gallery_ids ) ) {
			$image_ids = array_merge( $image_ids, $gallery_ids );
		}

		return array_values( array_unique( array_filter( array_map( 'absint', $image_ids ) ) ) );
	}

	private function describe_product_image( $attachment_id, $position ) {
		$url = wp_get_attachment_url( $attachment_id );

		if ( ! $url ) {
			return null;
		}

		$label = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' === $label ) {
			$label = get_the_title( $attachment_id );
		}
		$label = trim( wp_strip_all_tags( (string) $label ) );

		if ( '' === $label ) {
			$label = sprintf( __( 'Afbeelding %d', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $position );
		}

		$path = get_attached_file( $attachment_id );
		$mime = get_post_mime_type( $attachment_id );
		if ( ! $mime && $path ) {
			$mime = wp_get_image_mime( $path );
		}

		if ( $mime && 0 !== strpos( $mime, 'image/' ) ) {
			$mime = '';
		}

		return [
			'attachment_id' => $attachment_id,
			'label'         => $label,
			'url'           => esc_url_raw( $url ),
			'path'          => $path,
			'mime_type'     => $mime ? $mime : 'image/jpeg',
		];
	}
}
