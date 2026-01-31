<?php
/**
 * Plugin Name: SitiAI Product Teksten
 * Description: Genereer productteksten met diverse AI-aanbieders rechtstreeks vanuit WooCommerce.
 * Version: 1.8.0
 * Author: SitiAI
 * Text Domain: siti-ai-product-content-generator
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'GROQ_AI_PRODUCT_TEXT_FILE' ) ) {
	define( 'GROQ_AI_PRODUCT_TEXT_FILE', __FILE__ );
}

if ( ! defined( 'GROQ_AI_PRODUCT_TEXT_VERSION' ) ) {
	$groq_ai_plugin_data = get_file_data(
		__FILE__,
		[
			'Version' => 'Version',
		],
		false
	);

	$groq_ai_version = isset( $groq_ai_plugin_data['Version'] ) && $groq_ai_plugin_data['Version'] ? $groq_ai_plugin_data['Version'] : '1.0.0';
	define( 'GROQ_AI_PRODUCT_TEXT_VERSION', $groq_ai_version );
}

if ( ! defined( 'GROQ_AI_PRODUCT_TEXT_DOMAIN' ) ) {
	define( 'GROQ_AI_PRODUCT_TEXT_DOMAIN', 'siti-ai-product-content-generator' );
}

if ( ! defined( 'GROQ_AI_PRODUCT_TEXT_LEGACY_DOMAIN' ) ) {
	define( 'GROQ_AI_PRODUCT_TEXT_LEGACY_DOMAIN', 'groq-ai-product-text' );
}

if ( ! defined( 'GROQ_AI_DEBUG_TRACE_ADDED' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	define( 'GROQ_AI_DEBUG_TRACE_ADDED', true );
}

require_once __DIR__ . '/includes/Core/class-groq-ai-service-container.php';
require_once __DIR__ . '/includes/Core/class-groq-ai-model-exclusions.php';
require_once __DIR__ . '/includes/Core/class-groq-ai-ajax-controller.php';
require_once __DIR__ . '/includes/Contracts/interface-groq-ai-provider.php';
require_once __DIR__ . '/includes/Providers/class-groq-ai-abstract-openai-provider.php';
require_once __DIR__ . '/includes/Providers/class-groq-ai-provider-groq.php';
require_once __DIR__ . '/includes/Providers/class-groq-ai-provider-openai.php';
require_once __DIR__ . '/includes/Providers/class-groq-ai-provider-google.php';
require_once __DIR__ . '/includes/Providers/class-groq-ai-provider-manager.php';
require_once __DIR__ . '/includes/Services/Settings/class-groq-ai-settings-manager.php';
require_once __DIR__ . '/includes/Services/Prompt/class-groq-ai-prompt-builder.php';
require_once __DIR__ . '/includes/Services/Conversations/class-groq-ai-conversation-manager.php';
require_once __DIR__ . '/includes/Services/Logging/class-groq-ai-generation-logger.php';
require_once __DIR__ . '/includes/Services/Google/class-groq-ai-google-oauth-client.php';
require_once __DIR__ . '/includes/Services/Google/class-groq-ai-google-search-console-client.php';
require_once __DIR__ . '/includes/Services/Google/class-groq-ai-google-analytics-data-client.php';
require_once __DIR__ . '/includes/Services/Google/class-groq-ai-google-context-builder.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-admin-base.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-term-admin-base.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-categories-admin.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-brands-admin.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-settings-page.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-logs-admin.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-logs-table.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-product-ui.php';
require_once __DIR__ . '/includes/Admin/class-groq-ai-settings-renderer.php';

if( ! class_exists( 'SitiWebUpdater' ) ){
	include_once( plugin_dir_path( __FILE__ ) . 'SitiWebUpdater.php' );
}

$updater = new SitiWebUpdater( __FILE__ );
$updater->set_username( 'SitiWeb' );
$updater->set_repository( 'siti-ai-product-content-generator' );
$updater->initialize();


final class Groq_AI_Product_Text_Plugin {
	const OPTION_KEY = 'groq_ai_product_text_settings';
	const CONVERSATION_OPTION_KEY = 'groq_ai_product_text_conversations';
	const MODELS_CACHE_OPTION_KEY = 'groq_ai_product_text_models';

	/** @var bool */
	private $textdomain_loaded = false;

	private static $instance = null;

	/** @var Groq_AI_Service_Container */
	private $container;

	/** @var Groq_AI_Product_Text_Settings_Page */
	private $settings_page;

	/** @var Groq_AI_Categories_Admin */
	private $categories_admin;

	/** @var Groq_AI_Brands_Admin */
	private $brands_admin;

	/** @var Groq_AI_Logs_Admin */
	private $logs_admin;

	/** @var Groq_AI_Product_Text_Product_UI */
	private $product_ui;

	/** @var bool */
	private $missing_wc_notice = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->register_services();

		$this->settings_page    = new Groq_AI_Product_Text_Settings_Page( $this, $this->get_provider_manager() );
		$this->categories_admin = new Groq_AI_Categories_Admin( $this );
		$this->brands_admin     = new Groq_AI_Brands_Admin( $this );
		$this->logs_admin       = new Groq_AI_Logs_Admin( $this );
		$this->product_ui       = new Groq_AI_Product_Text_Product_UI( $this );

		add_action( 'init', [ $this, 'load_textdomain' ] );
		add_action( 'plugins_loaded', [ $this, 'maybe_create_logs_table' ] );
		add_action( 'load-plugins.php', [ $this, 'maybe_deactivate_if_woocommerce_missing' ] );
		add_filter( 'groq_ai_term_google_context', [ $this, 'inject_google_term_context' ], 10, 3 );
	}

	public function load_textdomain() {
		if ( $this->textdomain_loaded ) {
			return;
		}

		$relative_path = dirname( plugin_basename( GROQ_AI_PRODUCT_TEXT_FILE ) ) . '/languages';

		load_plugin_textdomain( GROQ_AI_PRODUCT_TEXT_DOMAIN, false, $relative_path );

		if ( defined( 'GROQ_AI_PRODUCT_TEXT_LEGACY_DOMAIN' ) && GROQ_AI_PRODUCT_TEXT_LEGACY_DOMAIN !== GROQ_AI_PRODUCT_TEXT_DOMAIN ) {
			load_plugin_textdomain( GROQ_AI_PRODUCT_TEXT_LEGACY_DOMAIN, false, $relative_path );
		}

		$this->textdomain_loaded = true;
	}

	private function register_services() {
		$this->container = new Groq_AI_Service_Container();

		$this->container->set(
			'provider_manager',
			function () {
				return new Groq_AI_Provider_Manager();
			}
		);

		$this->container->set(
			'settings_manager',
			function ( Groq_AI_Service_Container $container ) {
				return new Groq_AI_Settings_Manager( self::OPTION_KEY, $container->get( 'provider_manager' ) );
			}
		);

		$this->container->set(
			'prompt_builder',
			function ( Groq_AI_Service_Container $container ) {
				return new Groq_AI_Prompt_Builder( $container->get( 'settings_manager' ) );
			}
		);

		$this->container->set(
			'conversation_manager',
			function () {
				return new Groq_AI_Conversation_Manager( self::CONVERSATION_OPTION_KEY );
			}
		);

		$this->container->set(
			'generation_logger',
			function () {
				return new Groq_AI_Generation_Logger();
			}
		);

		$this->container->set(
			'ajax_controller',
			function () {
				return new Groq_AI_Ajax_Controller( $this );
			}
		);

		$this->container->set(
			'google_oauth_client',
			function () {
				return new Groq_AI_Google_OAuth_Client();
			}
		);

		$this->container->set(
			'gsc_client',
			function ( Groq_AI_Service_Container $container ) {
				return new Groq_AI_Google_Search_Console_Client( $container->get( 'google_oauth_client' ) );
			}
		);

		$this->container->set(
			'ga_client',
			function ( Groq_AI_Service_Container $container ) {
				return new Groq_AI_Google_Analytics_Data_Client( $container->get( 'google_oauth_client' ) );
			}
		);

		$this->container->set(
			'google_context_builder',
			function ( Groq_AI_Service_Container $container ) {
				return new Groq_AI_Google_Context_Builder( $container->get( 'gsc_client' ), $container->get( 'ga_client' ) );
			}
		);

		// Instantiate controller immediately so hooks are registered.
		$this->container->get( 'ajax_controller' );
	}

	public function inject_google_term_context( $existing, $term, $settings ) {
		$builder = $this->container->get( 'google_context_builder' );
		if ( ! $builder ) {
			return (string) $existing;
		}

		return $builder->build_term_google_context( $existing, $term, $settings );
	}

	public function get_option_key() {
		return self::OPTION_KEY;
	}

	public function get_provider_manager() {
		return $this->container->get( 'provider_manager' );
	}

	public function get_settings_manager() {
		return $this->container->get( 'settings_manager' );
	}

	public function get_prompt_builder() {
		return $this->container->get( 'prompt_builder' );
	}

	public function get_conversation_manager() {
		return $this->container->get( 'conversation_manager' );
	}

	public function get_generation_logger() {
		return $this->container->get( 'generation_logger' );
	}

	public function get_settings() {
		return $this->get_settings_manager()->all();
	}

	public function sanitize_settings( $input ) {
		return $this->get_settings_manager()->sanitize( $input );
	}

	public function maybe_deactivate_if_woocommerce_missing() {
		if ( $this->is_woocommerce_active() ) {
			return;
		}

		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( plugin_basename( GROQ_AI_PRODUCT_TEXT_FILE ) );
		$this->missing_wc_notice = true;

		add_action( 'admin_notices', [ $this, 'render_missing_wc_notice' ] );
	}

	public function render_missing_wc_notice() {
		if ( ! $this->missing_wc_notice ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<?php esc_html_e( 'SitiAI Product Teksten vereist WooCommerce en is gedeactiveerd omdat WooCommerce niet actief is.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
		</div>
		<?php
	}

	public function build_prompt_template_preview( $settings ) {
		$parts = [];

		if ( ! empty( $settings['store_context'] ) ) {
			$parts[] = sprintf( __( 'Winkelcontext: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $settings['store_context'] );
		}

		if ( ! empty( $settings['default_prompt'] ) ) {
			$parts[] = sprintf( __( 'Standaard prompt: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $settings['default_prompt'] );
		}

		if ( empty( $parts ) ) {
			return __( 'Nog geen promptinformatie opgeslagen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		}

		return implode( "\n\n", $parts );
	}

	public function get_context_field_definitions() {
		return $this->get_settings_manager()->get_context_field_definitions();
	}

	public function get_default_modules_settings() {
		return $this->get_settings_manager()->get_default_modules_settings();
	}

	public function get_default_context_fields() {
		return $this->get_settings_manager()->get_default_context_fields();
	}

	public function normalize_context_fields( $fields ) {
		return $this->get_settings_manager()->normalize_context_fields( $fields );
	}

	public function get_module_config( $module, $settings = null ) {
		return $this->get_settings_manager()->get_module_config( $module, $settings );
	}

	public function is_module_enabled( $module, $settings = null ) {
		return $this->get_settings_manager()->is_module_enabled( $module, $settings );
	}

	public function get_rankmath_focus_keyword_limit( $settings = null ) {
		return $this->get_settings_manager()->get_rankmath_focus_keyword_limit( $settings );
	}

	public function get_rankmath_meta_title_pixel_limit( $settings = null ) {
		return $this->get_settings_manager()->get_rankmath_meta_title_pixel_limit( $settings );
	}

	public function get_rankmath_meta_description_pixel_limit( $settings = null ) {
		return $this->get_settings_manager()->get_rankmath_meta_description_pixel_limit( $settings );
	}

	public function is_response_format_compat_enabled( $settings = null ) {
		return $this->get_settings_manager()->is_response_format_compat_enabled( $settings );
	}

	public function get_image_context_mode( $settings = null ) {
		return $this->get_settings_manager()->get_image_context_mode( $settings );
	}

	public function get_image_context_limit( $settings = null ) {
		return $this->get_settings_manager()->get_image_context_limit( $settings );
	}

	public function get_term_top_description_char_limit( $settings = null ) {
		return $this->get_settings_manager()->get_term_top_description_char_limit( $settings );
	}

	public function get_term_bottom_description_char_limit( $settings = null ) {
		return $this->get_settings_manager()->get_term_bottom_description_char_limit( $settings );
	}

	public function get_google_safety_settings( $settings = null ) {
		return $this->get_settings_manager()->get_google_safety_settings( $settings );
	}

	public function get_google_safety_categories() {
		return $this->get_settings_manager()->get_google_safety_categories();
	}

	public function get_google_safety_thresholds() {
		return $this->get_settings_manager()->get_google_safety_thresholds();
	}

	public function get_loggable_settings_snapshot( $settings = null ) {
		return $this->get_settings_manager()->get_loggable_settings_snapshot( $settings );
	}

	public function create_settings_renderer( $values = null ) {
		if ( null === $values ) {
			$values = $this->get_settings();
		}

		return new Groq_AI_Settings_Renderer( self::OPTION_KEY, $values );
	}

	public function should_use_response_format( Groq_AI_Provider_Interface $provider, $settings ) {
		return ! $this->is_response_format_compat_enabled( $settings ) && $provider->supports_response_format();
	}

	public function is_rankmath_active() {
		if ( class_exists( 'RankMath' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'seo-by-rank-math/rank-math.php' );
	}

	public function is_woocommerce_active() {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'woocommerce/woocommerce.php' );
	}

	public function get_selected_model( Groq_AI_Provider_Interface $provider, $settings ) {
		$provider_key = $provider->get_key();
		$model        = ! empty( $settings['model'] ) ? $settings['model'] : '';
		$model        = Groq_AI_Model_Exclusions::ensure_allowed( $provider_key, $model );

		if ( '' === $model ) {
			$default       = Groq_AI_Model_Exclusions::ensure_allowed( $provider_key, $provider->get_default_model() );
			if ( '' !== $default ) {
				return $default;
			}

			$available = Groq_AI_Model_Exclusions::filter_models( $provider_key, $provider->get_available_models() );
			if ( ! empty( $available ) ) {
				return $available[0];
			}
		}

		return $model;
	}

	public function get_cached_models_for_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$cache    = $this->get_models_cache();

		return isset( $cache[ $provider ] ) ? $cache[ $provider ] : [];
	}

	public function update_cached_models_for_provider( $provider, $models ) {
		$provider = sanitize_key( (string) $provider );
		$models   = $this->sanitize_models_list( $models );

		$cache = $this->get_models_cache();
		$cache[ $provider ] = $models;

		update_option( self::MODELS_CACHE_OPTION_KEY, $cache );

		return $models;
	}

	private function get_models_cache() {
		$cache = get_option( self::MODELS_CACHE_OPTION_KEY, [] );

		if ( ! is_array( $cache ) ) {
			$cache = [];
		}

		foreach ( $cache as $provider => $models ) {
			$cache[ $provider ] = $this->sanitize_models_list( $models );
		}

		return $cache;
	}

	private function sanitize_models_list( $models ) {
		if ( ! is_array( $models ) ) {
			return [];
		}

		$models = array_map( 'sanitize_text_field', $models );
		$models = array_filter(
			$models,
			function ( $model ) {
				return '' !== $model;
			}
		);

		$models = array_values( array_unique( $models ) );

		if ( ! empty( $models ) ) {
			sort( $models, SORT_NATURAL | SORT_FLAG_CASE );
		}

		return $models;
	}

	public function log_debug( $message, $context = [] ) {
		$this->get_generation_logger()->log_debug( $message, $context );
	}
	private function extract_content_text( $result ) {
		if ( is_array( $result ) && isset( $result['content'] ) ) {
			return (string) $result['content'];
		}

		return (string) $result;
	}

	public function maybe_create_logs_table() {
		$this->get_generation_logger()->maybe_create_table();
	}

	public static function activate() {
		$logger = new Groq_AI_Generation_Logger();
		$logger->create_table();
	}
}

register_activation_hook( GROQ_AI_PRODUCT_TEXT_FILE, [ 'Groq_AI_Product_Text_Plugin', 'activate' ] );
Groq_AI_Product_Text_Plugin::instance();
