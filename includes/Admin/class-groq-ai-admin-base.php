<?php

abstract class Groq_AI_Admin_Base {
	/** @var Groq_AI_Product_Text_Plugin */
	protected $plugin;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	protected function get_page_url( $slug = 'groq-ai-product-text', $args = [] ) {
		$slug = sanitize_key( (string) $slug );
		$url  = add_query_arg(
			[
				'page' => $slug,
			],
			admin_url( 'options-general.php' )
		);

		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}

		return $url;
	}

	protected function current_user_can_manage() {
		return current_user_can( 'manage_options' );
	}

	protected function enqueue_admin_styles() {
		wp_enqueue_style(
			'groq-ai-settings',
			plugins_url( 'assets/css/admin.css', GROQ_AI_PRODUCT_TEXT_FILE ),
			[],
			GROQ_AI_PRODUCT_TEXT_VERSION
		);

		wp_enqueue_style(
			'groq-ai-settings-extra',
			plugins_url( 'assets/css/settings.css', GROQ_AI_PRODUCT_TEXT_FILE ),
			[ 'groq-ai-settings' ],
			GROQ_AI_PRODUCT_TEXT_VERSION
		);
	}
}
