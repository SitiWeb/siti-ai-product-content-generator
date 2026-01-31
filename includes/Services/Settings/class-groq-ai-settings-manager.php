<?php

/**
 * Beheert alle plugininstellingen: ophalen, standaardiseren en sanitizen.
 */
class Groq_AI_Settings_Manager {
	/** @var string */
	private $option_key;

	/** @var Groq_AI_Provider_Manager */
	private $provider_manager;

	/** @var array|null */
	private $context_field_definitions = null;

	/** @var array|null */
	private $default_modules = null;

	public function __construct( $option_key, Groq_AI_Provider_Manager $provider_manager ) {
		$this->option_key       = $option_key;
		$this->provider_manager = $provider_manager;
	}

	/**
	 * Geeft samengestelde instellingen terug (voormalige get_settings()).
	 *
	 * @return array
	 */
	public function all() {
		$defaults = [
			'provider'       => 'groq',
			'model'          => '',
			'store_context'  => '',
			'default_prompt' => '',
			'max_output_tokens' => 2048,
			'product_attribute_includes' => [],
			'term_bottom_description_meta_key' => '',
			'groq_api_key'   => '',
			'openai_api_key' => '',
			'google_api_key' => '',
			'google_oauth_client_id' => '',
			'google_oauth_client_secret' => '',
			'google_oauth_refresh_token' => '',
			'google_oauth_connected_email' => '',
			'google_oauth_connected_at' => 0,
			'google_enable_gsc' => true,
			'google_enable_ga' => true,
			'google_gsc_site_url' => '',
			'google_ga4_property_id' => '',
			'google_safety_settings' => [],
			'context_fields' => $this->get_default_context_fields(),
			'modules'        => $this->get_default_modules_settings(),
			'image_context_mode' => 'url',
			'image_context_limit' => 3,
			'response_format_compat' => false,
			'term_top_description_char_limit' => 600,
			'term_bottom_description_char_limit' => 1200,
		];

		$settings = get_option( $this->option_key, [] );
		$settings = wp_parse_args( (array) $settings, $defaults );
		$settings['context_fields'] = $this->normalize_context_fields( isset( $settings['context_fields'] ) ? $settings['context_fields'] : [] );
		$settings['modules']        = $this->sanitize_modules_settings( isset( $settings['modules'] ) ? $settings['modules'] : [] );
		$settings['google_safety_settings'] = $this->sanitize_google_safety_settings( isset( $settings['google_safety_settings'] ) ? $settings['google_safety_settings'] : [] );
		$settings['model']          = Groq_AI_Model_Exclusions::ensure_allowed( $settings['provider'], isset( $settings['model'] ) ? $settings['model'] : '' );

		$image_mode = isset( $settings['image_context_mode'] ) ? sanitize_text_field( $settings['image_context_mode'] ) : 'url';
		if ( 'none' === $image_mode ) {
			$settings['context_fields']['images'] = false;
			$settings['image_context_mode']       = 'none';
		} elseif ( in_array( $image_mode, [ 'url', 'base64' ], true ) ) {
			$settings['context_fields']['images'] = true;
			$settings['image_context_mode']       = $image_mode;
		} else {
			$settings['context_fields']['images'] = true;
			$settings['image_context_mode']       = 'url';
		}

		$limit = isset( $settings['image_context_limit'] ) ? $this->sanitize_image_context_limit_value( $settings['image_context_limit'] ) : 3;
		$settings['image_context_limit'] = $limit;

		$settings['product_attribute_includes'] = $this->sanitize_product_attribute_includes(
			isset( $settings['product_attribute_includes'] ) ? $settings['product_attribute_includes'] : []
		);

		$settings['term_top_description_char_limit'] = $this->sanitize_term_description_char_limit_value(
			isset( $settings['term_top_description_char_limit'] ) ? $settings['term_top_description_char_limit'] : $defaults['term_top_description_char_limit'],
			$defaults['term_top_description_char_limit']
		);
		$settings['term_bottom_description_char_limit'] = $this->sanitize_term_description_char_limit_value(
			isset( $settings['term_bottom_description_char_limit'] ) ? $settings['term_bottom_description_char_limit'] : $defaults['term_bottom_description_char_limit'],
			$defaults['term_bottom_description_char_limit']
		);

		return $settings;
	}

	/**
	 * Sanitizelogica voor register_setting callback.
	 *
	 * @param array $input
	 * @return array
	 */
	public function sanitize( $input ) {
		$base_defaults = [
			'provider'       => 'groq',
			'model'          => '',
			'store_context'  => '',
			'default_prompt' => '',
			'max_output_tokens' => 2048,
			'product_attribute_includes' => [],
			'term_bottom_description_meta_key' => '',
			'groq_api_key'   => '',
			'openai_api_key' => '',
			'google_api_key' => '',
			'google_oauth_client_id' => '',
			'google_oauth_client_secret' => '',
			'google_oauth_refresh_token' => '',
			'google_oauth_connected_email' => '',
			'google_oauth_connected_at' => 0,
			'google_enable_gsc' => true,
			'google_enable_ga' => true,
			'google_gsc_site_url' => '',
			'google_ga4_property_id' => '',
			'google_safety_settings' => [],
			'context_fields' => $this->get_default_context_fields(),
			'modules'        => $this->get_default_modules_settings(),
			'image_context_mode' => 'url',
			'image_context_limit' => 3,
			'response_format_compat' => false,
			'term_top_description_char_limit' => 600,
			'term_bottom_description_char_limit' => 1200,
		];

		$current_settings = $this->all();
		$defaults         = wp_parse_args( $current_settings, $base_defaults );
		$raw_input        = (array) $input;
		$input            = wp_parse_args( $raw_input, $defaults );
		$context_posted   = array_key_exists( 'context_fields', $raw_input );
		$modules_posted   = array_key_exists( 'modules', $raw_input );

		$provider = sanitize_text_field( $input['provider'] );
		if ( ! $this->provider_manager->get_provider( $provider ) ) {
			$provider = 'groq';
		}

		$model = sanitize_text_field( $input['model'] );
		$model = Groq_AI_Model_Exclusions::ensure_allowed( $provider, $model );

		$image_mode = isset( $input['image_context_mode'] ) ? sanitize_text_field( $input['image_context_mode'] ) : $defaults['image_context_mode'];
		$allowed_modes = [ 'none', 'base64', 'url' ];
		if ( ! in_array( $image_mode, $allowed_modes, true ) ) {
			$image_mode = 'url';
		}

		$image_limit = isset( $input['image_context_limit'] ) ? $this->sanitize_image_context_limit_value( $input['image_context_limit'] ) : $defaults['image_context_limit'];

		$max_output_tokens = isset( $input['max_output_tokens'] ) ? absint( $input['max_output_tokens'] ) : absint( $defaults['max_output_tokens'] );
		// Keep within sane bounds across providers.
		$max_output_tokens = max( 128, min( 8192, $max_output_tokens ) );

		$context_fields = $this->normalize_context_fields( $context_posted ? $raw_input['context_fields'] : $defaults['context_fields'] );

		if ( 'none' === $image_mode ) {
			$context_fields['images'] = false;
		} else {
			$context_fields['images'] = true;
		}

		$top_char_limit = $this->sanitize_term_description_char_limit_value(
			isset( $raw_input['term_top_description_char_limit'] ) ? $raw_input['term_top_description_char_limit'] : $defaults['term_top_description_char_limit'],
			$defaults['term_top_description_char_limit']
		);
		$bottom_char_limit = $this->sanitize_term_description_char_limit_value(
			isset( $raw_input['term_bottom_description_char_limit'] ) ? $raw_input['term_bottom_description_char_limit'] : $defaults['term_bottom_description_char_limit'],
			$defaults['term_bottom_description_char_limit']
		);

		return [
			'provider'       => $provider,
			'model'          => $model,
			'store_context'  => sanitize_textarea_field( $input['store_context'] ),
			'default_prompt' => sanitize_textarea_field( $input['default_prompt'] ),
			'max_output_tokens' => $max_output_tokens,
			'product_attribute_includes' => $this->sanitize_product_attribute_includes( isset( $raw_input['product_attribute_includes'] ) ? $raw_input['product_attribute_includes'] : [] ),
			'term_bottom_description_meta_key' => sanitize_key( (string) $input['term_bottom_description_meta_key'] ),
			'groq_api_key'   => sanitize_text_field( $input['groq_api_key'] ),
			'openai_api_key' => sanitize_text_field( $input['openai_api_key'] ),
			'google_api_key' => sanitize_text_field( $input['google_api_key'] ),
			'google_oauth_client_id' => sanitize_text_field( $input['google_oauth_client_id'] ),
			'google_oauth_client_secret' => sanitize_text_field( $input['google_oauth_client_secret'] ),
			'google_oauth_refresh_token' => sanitize_text_field( $input['google_oauth_refresh_token'] ),
			'google_oauth_connected_email' => sanitize_text_field( $input['google_oauth_connected_email'] ),
			'google_oauth_connected_at' => absint( $input['google_oauth_connected_at'] ),
			'google_enable_gsc' => ! empty( $raw_input['google_enable_gsc'] ),
			'google_enable_ga' => ! empty( $raw_input['google_enable_ga'] ),
			'google_gsc_site_url' => esc_url_raw( (string) $input['google_gsc_site_url'] ),
			'google_ga4_property_id' => sanitize_text_field( (string) $input['google_ga4_property_id'] ),
			'google_safety_settings' => $this->sanitize_google_safety_settings( isset( $raw_input['google_safety_settings'] ) ? $raw_input['google_safety_settings'] : [] ),
			'response_format_compat' => ! empty( $raw_input['response_format_compat'] ),
			'image_context_mode' => $image_mode,
			'image_context_limit' => $image_limit,
			'term_top_description_char_limit' => $top_char_limit,
			'term_bottom_description_char_limit' => $bottom_char_limit,
			'context_fields' => $context_fields,
			'modules'        => $this->sanitize_modules_settings(
				$modules_posted ? $raw_input['modules'] : [],
				$defaults['modules'],
				isset( $current_settings['modules'] ) ? (array) $current_settings['modules'] : $this->get_default_modules_settings(),
				$modules_posted
			),
		];
	}

	private function sanitize_product_attribute_includes( $value ) {
		if ( ! is_array( $value ) ) {
			return [];
		}

		$clean = [];
		foreach ( $value as $item ) {
			$item = sanitize_key( (string) $item );
			if ( '' === $item ) {
				continue;
			}

			// Allow special tokens and attribute taxonomies.
			if ( in_array( $item, [ '__all__', '__custom__' ], true ) || 0 === strpos( $item, 'pa_' ) ) {
				$clean[] = $item;
			}
		}

		$clean = array_values( array_unique( $clean ) );
		// Hard cap to avoid overly large option payloads.
		if ( count( $clean ) > 200 ) {
			$clean = array_slice( $clean, 0, 200 );
		}

		return $clean;
	}

	private function sanitize_term_description_char_limit_value( $value, $default ) {
		$default_value = absint( $default ) > 0 ? absint( $default ) : 600;

		if ( null === $value || '' === $value ) {
			$value = $default_value;
		}

		$value = absint( $value );

		if ( $value <= 0 ) {
			$value = $default_value;
		}

		return max( 100, min( 5000, $value ) );
	}

	public function get_context_field_definitions() {
		if ( null === $this->context_field_definitions ) {
			$this->context_field_definitions = [
				'title'             => [
					'label'       => __( 'Producttitel', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Voeg de huidige producttitel toe als context.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => true,
				],
				'short_description' => [
					'label'       => __( 'Korte beschrijving', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Gebruik de bestaande korte beschrijving (indien aanwezig).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => true,
				],
				'description'       => [
					'label'       => __( 'Volledige beschrijving', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Stuurt de huidige productbeschrijving mee als bronmateriaal.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => true,
				],
				'attributes'        => [
					'label'       => __( 'Attributen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Voeg gestructureerde productattributen toe (zoals kleur, maat, materiaal).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => false,
				],
				'brands'           => [
					'label'       => __( 'Merken', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Voegt gekoppelde productmerken toe (detecteert WooCommerce merk-taxonomieën).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => true,
				],
				'images'            => [
					'label'       => __( 'Afbeeldingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'description' => __( 'Voeg een korte lijst toe met productafbeeldingen (beschrijving + URL).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'default'     => false,
				],
			];
		}

		return $this->context_field_definitions;
	}

	public function get_default_context_fields() {
		$definitions = $this->get_context_field_definitions();
		$defaults    = [];

		foreach ( $definitions as $key => $data ) {
			$defaults[ $key ] = ! empty( $data['default'] );
		}

		return $defaults;
	}

	public function normalize_context_fields( $fields ) {
		$definitions = $this->get_context_field_definitions();
		$normalized  = [];

		foreach ( $definitions as $key => $data ) {
			$normalized[ $key ] = ! empty( $data['default'] );
		}

		if ( ! is_array( $fields ) ) {
			return $normalized;
		}

		foreach ( $fields as $key => $value ) {
			if ( is_int( $key ) ) {
				$key   = sanitize_text_field( $value );
				$value = true;
			}

			if ( array_key_exists( $key, $normalized ) ) {
				$normalized[ $key ] = (bool) $value;
			}
		}

		return $normalized;
	}

	public function get_default_modules_settings() {
		if ( null === $this->default_modules ) {
			$this->default_modules = [
				'rankmath' => [
					'enabled'                 => true,
					'focus_keyword_limit'     => 3,
					'meta_title_pixel_limit'  => 580,
					'meta_description_pixel_limit' => 920,
				],
			];
		}

		return $this->default_modules;
	}

	public function get_module_config( $module, $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$defaults = $this->get_default_modules_settings();
		$modules  = isset( $settings['modules'] ) && is_array( $settings['modules'] ) ? $settings['modules'] : [];
		$config   = isset( $modules[ $module ] ) ? (array) $modules[ $module ] : [];

		return wp_parse_args( $config, isset( $defaults[ $module ] ) ? $defaults[ $module ] : [] );
	}

	public function is_module_enabled( $module, $settings = null ) {
		$config = $this->get_module_config( $module, $settings );

		return ! empty( $config['enabled'] );
	}

	public function get_rankmath_focus_keyword_limit( $settings = null ) {
		$config = $this->get_module_config( 'rankmath', $settings );
		$limit  = isset( $config['focus_keyword_limit'] ) ? absint( $config['focus_keyword_limit'] ) : 3;

		return max( 1, min( 100, $limit ) );
	}

	public function get_rankmath_meta_title_pixel_limit( $settings = null ) {
		$config = $this->get_module_config( 'rankmath', $settings );
		$value  = isset( $config['meta_title_pixel_limit'] ) ? absint( $config['meta_title_pixel_limit'] ) : 580;

		return max( 200, min( 1200, $value ) );
	}

	public function get_rankmath_meta_description_pixel_limit( $settings = null ) {
		$config = $this->get_module_config( 'rankmath', $settings );
		$value  = isset( $config['meta_description_pixel_limit'] ) ? absint( $config['meta_description_pixel_limit'] ) : 920;

		return max( 200, min( 2000, $value ) );
	}

	public function get_image_context_mode( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$mode          = isset( $settings['image_context_mode'] ) ? sanitize_text_field( $settings['image_context_mode'] ) : 'url';
		$allowed_modes = [ 'none', 'base64', 'url' ];

		return in_array( $mode, $allowed_modes, true ) ? $mode : 'url';
	}

	public function get_image_context_limit( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$limit = isset( $settings['image_context_limit'] ) ? $settings['image_context_limit'] : 3;

		return $this->sanitize_image_context_limit_value( $limit );
	}

	public function get_term_top_description_char_limit( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$value = isset( $settings['term_top_description_char_limit'] ) ? $settings['term_top_description_char_limit'] : 600;

		return $this->sanitize_term_description_char_limit_value( $value, 600 );
	}

	public function get_term_bottom_description_char_limit( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$value = isset( $settings['term_bottom_description_char_limit'] ) ? $settings['term_bottom_description_char_limit'] : 1200;

		return $this->sanitize_term_description_char_limit_value( $value, 1200 );
	}

	public function get_google_safety_settings( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		return $this->sanitize_google_safety_settings( isset( $settings['google_safety_settings'] ) ? $settings['google_safety_settings'] : [] );
	}

	public function get_google_safety_categories() {
		return self::get_google_safety_categories_list();
	}

	public function get_google_safety_thresholds() {
		return self::get_google_safety_thresholds_list();
	}

	public function get_loggable_settings_snapshot( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		$allowed_keys = [
			'store_context',
			'default_prompt',
			'max_output_tokens',
			'product_attribute_includes',
			'context_fields',
			'modules',
			'image_context_mode',
			'image_context_limit',
			'response_format_compat',
			'term_top_description_char_limit',
			'term_bottom_description_char_limit',
			'term_bottom_description_meta_key',
			'google_safety_settings',
			'google_enable_gsc',
			'google_enable_ga',
			'google_gsc_site_url',
			'google_ga4_property_id',
		];

		$snapshot = [];

		foreach ( $allowed_keys as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$snapshot[ $key ] = $settings[ $key ];
			}
		}

		return $snapshot;
	}

	public static function get_google_safety_categories_list() {
		return [
			'HARM_CATEGORY_HARASSMENT'       => [
				'label'       => __( 'Harassment & intimidatie', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'description' => __( 'Detecteert bedreigingen en pesterijen in de output.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			'HARM_CATEGORY_HATE_SPEECH'      => [
				'label'       => __( 'Haatspraak', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'description' => __( 'Beperkt discriminerende of denigrerende taal.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			'HARM_CATEGORY_SEXUALLY_EXPLICIT' => [
				'label'       => __( 'Seksueel expliciet', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'description' => __( 'Filtert beschrijvingen van seksuele handelingen of fetish-content.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			'HARM_CATEGORY_DANGEROUS_CONTENT' => [
				'label'       => __( 'Gevaarlijke activiteiten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'description' => __( 'Voorkomt instructies rond geweld, wapens of gevaarlijke middelen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			'HARM_CATEGORY_CIVIC_INTEGRITY'   => [
				'label'       => __( 'Civieke integriteit', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'description' => __( 'Vermindert desinformatie rond verkiezingen en burgerprocessen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
		];
	}

	public static function get_google_safety_thresholds_list() {
		return [
			''                                => __( 'Google standaard (niet meesturen)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'HARM_BLOCK_THRESHOLD_UNSPECIFIED' => __( 'Onbekende drempel (laat Google beslissen)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'BLOCK_LOW_AND_ABOVE'             => __( 'Blokkeer lage ernst en hoger', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'BLOCK_MEDIUM_AND_ABOVE'          => __( 'Blokkeer middel en hoger', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'BLOCK_ONLY_HIGH'                 => __( 'Blokkeer alleen hoge ernst', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'BLOCK_NONE'                      => __( 'Sta alles toe (geen blokkade)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
		];
	}

	public function is_response_format_compat_enabled( $settings = null ) {
		if ( null === $settings ) {
			$settings = $this->all();
		}

		return ! empty( $settings['response_format_compat'] );
	}

	/**
	 * @param array|null $modules
	 * @param array|null $defaults
	 * @param array|null $current
	 * @param bool       $is_posted
	 * @return array
	 */
	private function sanitize_modules_settings( $modules, $defaults = null, $current = null, $is_posted = false ) {
		$module_defaults = $this->get_default_modules_settings();

		if ( ! is_array( $defaults ) ) {
			$defaults = $module_defaults;
		}

		if ( ! is_array( $current ) ) {
			$current = $defaults;
		}

		$current = wp_parse_args( $current, $defaults );

		if ( ! is_array( $modules ) ) {
			$modules = [];
		}

		if ( ! $is_posted ) {
			$clean = [];
			foreach ( $module_defaults as $module_key => $module_default_config ) {
				$raw               = isset( $modules[ $module_key ] ) ? (array) $modules[ $module_key ] : [];
				$clean[ $module_key ] = wp_parse_args( $raw, isset( $current[ $module_key ] ) ? $current[ $module_key ] : $module_default_config );
			}

			return $clean;
		}

		$result = $current;

		foreach ( $module_defaults as $module_key => $module_default_config ) {
			$raw            = isset( $modules[ $module_key ] ) ? (array) $modules[ $module_key ] : [];
			$current_config = isset( $current[ $module_key ] ) ? (array) $current[ $module_key ] : $module_default_config;

			$result[ $module_key ]['enabled'] = isset( $raw['enabled'] ) ? (bool) $raw['enabled'] : ( isset( $current_config['enabled'] ) ? (bool) $current_config['enabled'] : false );

			if ( 'rankmath' === $module_key ) {
				$limit = isset( $raw['focus_keyword_limit'] ) ? absint( $raw['focus_keyword_limit'] ) : ( isset( $current_config['focus_keyword_limit'] ) ? absint( $current_config['focus_keyword_limit'] ) : $module_default_config['focus_keyword_limit'] );
				if ( $limit <= 0 ) {
					$limit = $module_default_config['focus_keyword_limit'];
				}
				$result[ $module_key ]['focus_keyword_limit'] = max( 1, min( 100, $limit ) );

				$title_pixel_limit = isset( $raw['meta_title_pixel_limit'] ) ? absint( $raw['meta_title_pixel_limit'] ) : ( isset( $current_config['meta_title_pixel_limit'] ) ? absint( $current_config['meta_title_pixel_limit'] ) : $module_default_config['meta_title_pixel_limit'] );
				if ( $title_pixel_limit <= 0 ) {
					$title_pixel_limit = $module_default_config['meta_title_pixel_limit'];
				}
				$result[ $module_key ]['meta_title_pixel_limit'] = max( 200, min( 1200, $title_pixel_limit ) );

				$pixel_limit = isset( $raw['meta_description_pixel_limit'] ) ? absint( $raw['meta_description_pixel_limit'] ) : ( isset( $current_config['meta_description_pixel_limit'] ) ? absint( $current_config['meta_description_pixel_limit'] ) : $module_default_config['meta_description_pixel_limit'] );
				if ( $pixel_limit <= 0 ) {
					$pixel_limit = $module_default_config['meta_description_pixel_limit'];
				}
				$result[ $module_key ]['meta_description_pixel_limit'] = max( 200, min( 2000, $pixel_limit ) );
			}
		}

		return $result;
	}

	private function sanitize_image_context_limit_value( $value ) {
		$limit = absint( $value );

		if ( $limit <= 0 ) {
			$limit = 1;
		}

		return min( 10, $limit );
	}

	private function sanitize_google_safety_settings( $settings ) {
		if ( ! is_array( $settings ) ) {
			return [];
		}

		$categories = array_keys( self::get_google_safety_categories_list() );
		$thresholds = array_keys( self::get_google_safety_thresholds_list() );
		$clean      = [];

		foreach ( $settings as $category => $threshold ) {
			$category  = sanitize_text_field( (string) $category );
			$threshold = sanitize_text_field( (string) $threshold );

			if ( '' === $threshold || ! in_array( $category, $categories, true ) || ! in_array( $threshold, $thresholds, true ) ) {
				continue;
			}

			$clean[ $category ] = $threshold;
		}

		return $clean;
	}
}
