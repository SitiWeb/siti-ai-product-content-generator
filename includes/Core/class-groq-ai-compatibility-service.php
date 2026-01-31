<?php

class Groq_AI_Compatibility_Service {
	/** @var bool */
	private $missing_wc_notice = false;

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
}
