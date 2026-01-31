<?php
/**
 * Plugin Name: SitiAI Product Teksten
 * Description: Genereer productteksten met diverse AI-aanbieders rechtstreeks vanuit WooCommerce.
 * Version: 1.9.0
 * Author: Roberto Guagliardo | SitiWeb
 * Author URI: https://sitiweb.nl/
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
require_once __DIR__ . '/includes/Core/class-groq-ai-compatibility-service.php';
require_once __DIR__ . '/includes/Core/class-groq-ai-model-service.php';
require_once __DIR__ . '/includes/Core/class-groq-ai-log-scheduler.php';
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

	/** @var Groq_AI_Compatibility_Service */
	private $compatibility_service;

	/** @var Groq_AI_Model_Service */
	private $model_service;

	/** @var Groq_AI_Log_Scheduler */
	private $log_scheduler;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->register_services();
		$this->compatibility_service = new Groq_AI_Compatibility_Service();
		$this->model_service         = new Groq_AI_Model_Service();
		$this->log_scheduler          = new Groq_AI_Log_Scheduler( $this->get_settings_manager(), $this->get_generation_logger() );

		$this->settings_page    = new Groq_AI_Product_Text_Settings_Page( $this, $this->get_provider_manager() );
		$this->categories_admin = new Groq_AI_Categories_Admin( $this );
		$this->brands_admin     = new Groq_AI_Brands_Admin( $this );
		$this->logs_admin       = new Groq_AI_Logs_Admin( $this );
		$this->product_ui       = new Groq_AI_Product_Text_Product_UI( $this );

		add_action( 'init', [ $this, 'load_textdomain' ] );
		$logger = $this->container->get( 'generation_logger' );
		add_action( 'plugins_loaded', [ $logger, 'maybe_create_table' ] );
		add_action( 'load-plugins.php', [ $this->compatibility_service, 'maybe_deactivate_if_woocommerce_missing' ] );
		add_action( 'init', [ $this->log_scheduler, 'ensure_logs_cleanup_schedule' ] );
		add_action( 'groq_ai_cleanup_logs', [ $this->log_scheduler, 'cleanup_logs' ] );
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

	public function __call( $name, $arguments ) {
		switch ( $name ) {
			case 'get_provider_manager':
				return $this->container->get( 'provider_manager' );
			case 'get_settings_manager':
				return $this->container->get( 'settings_manager' );
			case 'get_prompt_builder':
				return $this->container->get( 'prompt_builder' );
			case 'get_conversation_manager':
				return $this->container->get( 'conversation_manager' );
			case 'get_generation_logger':
				return $this->container->get( 'generation_logger' );
			case 'get_settings':
				return $this->container->get( 'settings_manager' )->all();
			case 'sanitize_settings':
				return $this->container->get( 'settings_manager' )->sanitize( $arguments[0] ?? [] );
			case 'get_context_field_definitions':
				return $this->container->get( 'settings_manager' )->get_context_field_definitions();
			case 'get_default_modules_settings':
				return $this->container->get( 'settings_manager' )->get_default_modules_settings();
			case 'get_default_context_fields':
				return $this->container->get( 'settings_manager' )->get_default_context_fields();
			case 'normalize_context_fields':
				return $this->container->get( 'settings_manager' )->normalize_context_fields( $arguments[0] ?? [] );
			case 'get_module_config':
				return $this->container->get( 'settings_manager' )->get_module_config( $arguments[0] ?? '', $arguments[1] ?? null );
			case 'is_module_enabled':
				return $this->container->get( 'settings_manager' )->is_module_enabled( $arguments[0] ?? '', $arguments[1] ?? null );
			case 'get_rankmath_focus_keyword_limit':
				return $this->container->get( 'settings_manager' )->get_rankmath_focus_keyword_limit( $arguments[0] ?? null );
			case 'get_rankmath_meta_title_pixel_limit':
				return $this->container->get( 'settings_manager' )->get_rankmath_meta_title_pixel_limit( $arguments[0] ?? null );
			case 'get_rankmath_meta_description_pixel_limit':
				return $this->container->get( 'settings_manager' )->get_rankmath_meta_description_pixel_limit( $arguments[0] ?? null );
			case 'is_response_format_compat_enabled':
				return $this->container->get( 'settings_manager' )->is_response_format_compat_enabled( $arguments[0] ?? null );
			case 'get_image_context_mode':
				return $this->container->get( 'settings_manager' )->get_image_context_mode( $arguments[0] ?? null );
			case 'get_image_context_limit':
				return $this->container->get( 'settings_manager' )->get_image_context_limit( $arguments[0] ?? null );
			case 'get_term_top_description_char_limit':
				return $this->container->get( 'settings_manager' )->get_term_top_description_char_limit( $arguments[0] ?? null );
			case 'get_term_bottom_description_char_limit':
				return $this->container->get( 'settings_manager' )->get_term_bottom_description_char_limit( $arguments[0] ?? null );
			case 'get_google_safety_settings':
				return $this->container->get( 'settings_manager' )->get_google_safety_settings( $arguments[0] ?? null );
			case 'get_google_safety_categories':
				return $this->container->get( 'settings_manager' )->get_google_safety_categories();
			case 'get_google_safety_thresholds':
				return $this->container->get( 'settings_manager' )->get_google_safety_thresholds();
			case 'get_loggable_settings_snapshot':
				return $this->container->get( 'settings_manager' )->get_loggable_settings_snapshot( $arguments[0] ?? null );
			case 'create_settings_renderer':
				$values = $arguments[0] ?? null;
				if ( null === $values ) {
					$values = $this->container->get( 'settings_manager' )->all();
				}
				return new Groq_AI_Settings_Renderer( self::OPTION_KEY, $values );
			case 'should_use_response_format':
				$provider = $arguments[0] ?? null;
				$settings = $arguments[1] ?? null;
				if ( ! $provider instanceof Groq_AI_Provider_Interface ) {
					return false;
				}
				return ! $this->container->get( 'settings_manager' )->is_response_format_compat_enabled( $settings ) && $provider->supports_response_format();
			case 'is_rankmath_active':
				return $this->compatibility_service->is_rankmath_active();
			case 'is_woocommerce_active':
				return $this->compatibility_service->is_woocommerce_active();
			case 'get_selected_model':
				return $this->model_service->get_selected_model( $arguments[0], $arguments[1] ?? [] );
			case 'get_cached_models_for_provider':
				return $this->model_service->get_cached_models_for_provider( $arguments[0] ?? '' );
			case 'update_cached_models_for_provider':
				return $this->model_service->update_cached_models_for_provider( $arguments[0] ?? '', $arguments[1] ?? [] );
		}

		throw new BadMethodCallException( sprintf( 'Method %s does not exist.', $name ) );
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


	public static function activate() {
		$logger = new Groq_AI_Generation_Logger();
		$logger->create_table();
	}
}

register_activation_hook( GROQ_AI_PRODUCT_TEXT_FILE, [ 'Groq_AI_Product_Text_Plugin', 'activate' ] );
Groq_AI_Product_Text_Plugin::instance();
