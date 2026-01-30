<?php

class Groq_AI_Product_Text_Settings_Page {
	private $plugin;
	private $provider_manager;
	private $brand_taxonomy = null;
	private $term_overview_cache = [];

	public function __construct( $plugin, Groq_AI_Provider_Manager $provider_manager ) {
		$this->plugin            = $plugin;
		$this->provider_manager  = $provider_manager;

		add_action( 'admin_menu', [ $this, 'register_settings_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_head', [ $this, 'hide_menu_links' ] );
		add_action( 'admin_post_groq_ai_google_oauth_start', [ $this, 'handle_google_oauth_start' ] );
		add_action( 'admin_post_groq_ai_google_oauth_callback', [ $this, 'handle_google_oauth_callback' ] );
		add_action( 'admin_post_groq_ai_google_oauth_disconnect', [ $this, 'handle_google_oauth_disconnect' ] );
		add_action( 'admin_post_groq_ai_save_term_content', [ $this, 'handle_save_term_content' ] );
		add_action( 'admin_post_groq_ai_google_test_connection', [ $this, 'handle_google_test_connection' ] );
	}

	public function register_settings_pages() {
		add_options_page(
			__( 'Siti AI Productteksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text',
			[ $this, 'render_settings_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Categorieën', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-categories',
			[ $this, 'render_categories_overview_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Merken', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-brands',
			[ $this, 'render_brands_overview_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-term',
			[ $this, 'render_term_generator_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-modules',
			[ $this, 'render_modules_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-logs',
			[ $this, 'render_logs_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-prompts',
			[ $this, 'render_prompt_settings_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Log detail', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Log detail', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-log',
			[ $this, 'render_log_detail_page' ]
		);

	}
	private function get_page_url( $slug = 'groq-ai-product-text', $args = [] ) {
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

	private function get_request_redirect_url( $field, $page_slug = 'groq-ai-product-text' ) {
		$default = $this->get_page_url( $page_slug );
		$value   = isset( $_REQUEST[ $field ] ) ? wp_unslash( $_REQUEST[ $field ] ) : '';

		if ( '' === $value ) {
			return $default;
		}

		return wp_validate_redirect( $value, $default );
	}

	private function parse_oauth_state( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return [];
		}

		$decoded = base64_decode( $value, true );
		if ( ! is_string( $decoded ) || '' === $decoded ) {
			return [];
		}

		$data = json_decode( $decoded, true );

		return is_array( $data ) ? $data : [];
	}

	private function redirect_with_google_notice( $type, $message = '', $redirect = null, $status = 'success' ) {
		$redirect = $redirect ? $redirect : $this->get_page_url();
		$args     = [
			'groq_ai_google_notice'        => sanitize_key( (string) $type ),
			'groq_ai_google_notice_status' => sanitize_key( (string) $status ),
		];
		if ( '' !== $message ) {
			$args['groq_ai_google_notice_message'] = rawurlencode( (string) $message );
		}

		wp_safe_redirect( add_query_arg( $args, $redirect ) );
		exit;
	}

	private function redirect_with_term_notice( $taxonomy, $term_id, $type, $message = '', $status = 'success' ) {
		$url = ( $taxonomy && $term_id ) ? $this->get_term_page_url( $taxonomy, $term_id ) : $this->get_page_url( 'groq-ai-product-text-categories' );

		$args = [
			'groq_ai_term_notice' => sanitize_key( (string) $type ),
			'groq_ai_term_status' => sanitize_key( (string) $status ),
		];

		if ( '' !== $message ) {
			$args['groq_ai_term_notice_message'] = rawurlencode( (string) $message );
		}

		wp_safe_redirect( add_query_arg( $args, $url ) );
		exit;
	}

	private function get_google_redirect_uri() {
		return add_query_arg(
			'action',
			'groq_ai_google_oauth_callback',
			admin_url( 'admin-post.php' )
		);
	}

	private function update_settings_partial( array $updates ) {
		$option_key = $this->plugin->get_option_key();
		$current    = get_option( $option_key, [] );

		if ( ! is_array( $current ) ) {
			$current = [];
		}

		foreach ( $updates as $key => $value ) {
			$current[ $key ] = $value;
		}

		update_option( $option_key, $current );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_key     = $this->plugin->get_option_key();
		$settings       = $this->plugin->get_settings();
		$providers      = $this->provider_manager->get_providers();
		$current_page   = $this->get_page_url();
		$prompt_url     = $this->get_page_url( 'groq-ai-product-text-prompts' );
		$modules_url    = $this->get_page_url( 'groq-ai-product-text-modules' );
		$logs_url       = $this->get_page_url( 'groq-ai-product-text-logs' );
		$categories_url = $this->get_page_url( 'groq-ai-product-text-categories' );
		$brands_url     = $this->get_page_url( 'groq-ai-product-text-brands' );

		$prompt_preview = $this->plugin->build_prompt_template_preview( $settings );
		$google_notice  = isset( $_GET['groq_ai_google_notice'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_google_notice'] ) ) : '';
		$google_status  = isset( $_GET['groq_ai_google_notice_status'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_google_notice_status'] ) ) : '';
		$google_message = '';
		if ( isset( $_GET['groq_ai_google_notice_message'] ) ) {
			$google_message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['groq_ai_google_notice_message'] ) ) );
		}

		$google_connected       = ! empty( $settings['google_oauth_refresh_token'] );
		$google_connected_email = isset( $settings['google_oauth_connected_email'] ) ? (string) $settings['google_oauth_connected_email'] : '';
		$google_connected_at    = isset( $settings['google_oauth_connected_at'] ) ? absint( $settings['google_oauth_connected_at'] ) : 0;
		$oauth_redirect         = add_query_arg( 'action', 'groq_ai_google_oauth_callback', admin_url( 'admin-post.php' ) );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Kies je AI-aanbieder, beheer API-sleutels en koppel optioneel Google Search Console/Analytics voor extra context.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
			<p style="margin:16px 0; display:flex; flex-wrap:wrap; gap:8px;">
				<a class="button" href="<?php echo esc_url( $prompt_url ); ?>"><?php esc_html_e( 'Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $modules_url ); ?>"><?php esc_html_e( 'Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $logs_url ); ?>"><?php esc_html_e( 'AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $categories_url ); ?>"><?php esc_html_e( 'Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $brands_url ); ?>"><?php esc_html_e( 'Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
			</p>
			<?php settings_errors( $option_key ); ?>
			<?php if ( $google_notice ) :
				$class = ( 'error' === $google_status ) ? 'notice-error' : 'notice-success';
				$google_message = '' !== $google_message ? $google_message : ( 'connected' === $google_notice ? __( 'Google OAuth is verbonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : ( 'disconnected' === $google_notice ? __( 'Google OAuth is ontkoppeld.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : __( 'Google test afgerond.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ) );
				?>
				<div class="notice <?php echo esc_attr( $class ); ?>"><p><?php echo esc_html( $google_message ); ?></p></div>
			<?php endif; ?>
			<div style="margin:16px 0; padding:16px; background:#fff; border:1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Huidige promptcontext', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
				<pre style="background:#f6f7f7; padding:12px; overflow:auto; margin-top:8px; white-space:pre-wrap;"><?php echo esc_html( $prompt_preview ); ?></pre>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( $option_key ); ?>
				<h2><?php esc_html_e( 'AI-aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="groq-ai-provider"><?php esc_html_e( 'Aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select id="groq-ai-provider" name="<?php echo esc_attr( $option_key ); ?>[provider]">
								<?php foreach ( $providers as $provider ) :
									$provider_key = $provider->get_key();
									?>
									<option value="<?php echo esc_attr( $provider_key ); ?>" <?php selected( $settings['provider'], $provider_key ); ?>><?php echo esc_html( $provider->get_label() ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Selecteer welke aanbieder de product- en termteksten schrijft.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-model-select"><?php esc_html_e( 'Model', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<div class="groq-ai-model-field">
								<select id="groq-ai-model-select" name="<?php echo esc_attr( $option_key ); ?>[model]" data-current-model="<?php echo esc_attr( isset( $settings['model'] ) ? $settings['model'] : '' ); ?>">
									<option value="" selected="selected"><?php esc_html_e( 'Selecteer eerst een aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></option>
								</select>
							</div>
							<button type="button" class="button" id="groq-ai-refresh-models"><?php esc_html_e( 'Live modellen ophalen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
							<p id="groq-ai-refresh-models-status" class="description"></p>
						</td>
					</tr>
					<?php foreach ( $providers as $provider ) :
						$provider_key = $provider->get_key();
						$option_field = $provider->get_option_key();
						$value        = isset( $settings[ $option_field ] ) ? (string) $settings[ $option_field ] : '';
						?>
						<tr id="groq_ai_api_key_<?php echo esc_attr( $provider_key ); ?>" data-provider-row="<?php echo esc_attr( $provider_key ); ?>">
							<th scope="row"><label for="groq-ai-api-<?php echo esc_attr( $provider_key ); ?>"><?php esc_html_e( 'API-sleutel', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<input type="password" id="groq-ai-api-<?php echo esc_attr( $provider_key ); ?>" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[<?php echo esc_attr( $option_field ); ?>]" value="<?php echo esc_attr( $value ); ?>" autocomplete="off" />
								<p class="description"><?php printf( esc_html__( 'Voer de API-sleutel in voor %s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), esc_html( $provider->get_label() ) ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<h2><?php esc_html_e( 'Algemene instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="groq-ai-max-output"><?php esc_html_e( 'Maximale output tokens', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="number" id="groq-ai-max-output" name="<?php echo esc_attr( $option_key ); ?>[max_output_tokens]" value="<?php echo esc_attr( isset( $settings['max_output_tokens'] ) ? (int) $settings['max_output_tokens'] : 2048 ); ?>" min="128" max="8192" />
							<p class="description"><?php esc_html_e( 'Limitering van het aantal tokens per output voor compatibiliteit met verschillende modellen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-term-bottom-meta"><?php esc_html_e( 'Term meta key (onderste tekst)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="text" id="groq-ai-term-bottom-meta" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[term_bottom_description_meta_key]" value="<?php echo esc_attr( isset( $settings['term_bottom_description_meta_key'] ) ? $settings['term_bottom_description_meta_key'] : '' ); ?>" />
							<p class="description"><?php esc_html_e( 'Optioneel: overschrijf in welke term meta key de onderste omschrijving moet landen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Response format fallback', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td>
							<?php $this->render_response_format_compat_field(); ?>
						</td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Google Search Console & Analytics', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="groq-ai-google-client-id"><?php esc_html_e( 'Google OAuth client ID', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="text" id="groq-ai-google-client-id" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[google_oauth_client_id]" value="<?php echo esc_attr( isset( $settings['google_oauth_client_id'] ) ? $settings['google_oauth_client_id'] : '' ); ?>" autocomplete="off" />
							<p class="description">
								<?php
								printf(
									esc_html__( 'Stel deze plugin in als OAuth-client in Google Cloud Console en gebruik onderstaande redirect-URL.', GROQ_AI_PRODUCT_TEXT_DOMAIN )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-google-client-secret"><?php esc_html_e( 'Google OAuth client secret', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<input type="password" id="groq-ai-google-client-secret" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[google_oauth_client_secret]" value="<?php echo esc_attr( isset( $settings['google_oauth_client_secret'] ) ? $settings['google_oauth_client_secret'] : '' ); ?>" autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Redirect URI voor OAuth (voeg exact zo toe in Google Cloud → Credentials):', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?><br />
								<code><?php echo esc_html( $oauth_redirect ); ?></code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Search Console koppeling', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[google_enable_gsc]" value="1" <?php checked( ! empty( $settings['google_enable_gsc'] ) ); ?> />
								<?php esc_html_e( 'Search Console data gebruiken in term prompts', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
							</label>
							<p>
								<input type="url" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[google_gsc_site_url]" value="<?php echo esc_attr( isset( $settings['google_gsc_site_url'] ) ? $settings['google_gsc_site_url'] : '' ); ?>" placeholder="sc-domain:voorbeeld.nl" />
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Analytics koppeling', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[google_enable_ga]" value="1" <?php checked( ! empty( $settings['google_enable_ga'] ) ); ?> />
								<?php esc_html_e( 'GA4 data meesturen (landing page statistieken)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
							</label>
							<p>
								<input type="text" class="regular-text" name="<?php echo esc_attr( $option_key ); ?>[google_ga4_property_id]" value="<?php echo esc_attr( isset( $settings['google_ga4_property_id'] ) ? $settings['google_ga4_property_id'] : '' ); ?>" placeholder="properties/123456789" />
							</p>
						</td>
					</tr>
				</table>
				<p class="submit"><?php submit_button( __( 'Instellingen opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'primary', 'submit', false ); ?></p>
			</form>
			<div style="margin-top:24px; padding:16px; border:1px solid #dcdcde; background:#fff;">
				<h2><?php esc_html_e( 'Google verbinding', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<p>
					<?php
					if ( $google_connected ) {
						$timestamp = $google_connected_at ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $google_connected_at ) : '';
						printf(
							esc_html__( 'Verbonden als %1$s%2$s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
							$google_connected_email ? esc_html( $google_connected_email ) : esc_html__( 'onbekende gebruiker', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
							$timestamp ? ' — ' . esc_html( $timestamp ) : ''
						);
					} else {
						esc_html_e( 'Nog niet gekoppeld aan Google OAuth.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
					}
					?>
				</p>
				<div style="display:flex; flex-wrap:wrap; gap:12px; align-items:center;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'groq_ai_google_oauth', '_wpnonce' ); ?>
						<input type="hidden" name="action" value="groq_ai_google_oauth_start" />
						<input type="hidden" name="redirect_to" value="<?php echo esc_url( $current_page ); ?>" />
						<button type="submit" class="button button-primary"><?php echo $google_connected ? esc_html__( 'Opnieuw verbinden', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : esc_html__( 'Verbind met Google', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
					</form>
					<?php if ( $google_connected ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'groq_ai_google_disconnect', '_wpnonce' ); ?>
							<input type="hidden" name="action" value="groq_ai_google_oauth_disconnect" />
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $current_page ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Ontkoppelen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'groq_ai_google_test_connection', '_wpnonce' ); ?>
							<input type="hidden" name="action" value="groq_ai_google_test_connection" />
							<input type="hidden" name="redirect_to" value="<?php echo esc_url( $current_page ); ?>" />
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Verbinding testen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Register plugin settings with WordPress.
	 */
	public function register_settings() {
		register_setting(
			$this->plugin->get_option_key(),
			$this->plugin->get_option_key(),
			[ $this->plugin, 'sanitize_settings' ]
		);
	}

	public function hide_menu_links() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<style>
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-modules"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-logs"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-prompts"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-term"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-log"] {
				display: none !important;
			}
		</style>
		<?php
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
		return 0;
	}

	private function detect_brand_taxonomy() {
		if ( null !== $this->brand_taxonomy ) {
			return $this->brand_taxonomy;
		}

		$candidates = [
			'product_brand',
			'pwb-brand',
			'yith_product_brand',
			'berocket_brand',
		];

		// Attribute-taxonomy fallback (vaak pa_brand).
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
		$this->brand_taxonomy = sanitize_key( (string) $found );
		return $this->brand_taxonomy;
	}

	private function get_term_page_url( $taxonomy, $term_id ) {
		return add_query_arg(
			[
				'page' => 'groq-ai-product-text-term',
				'taxonomy' => sanitize_key( (string) $taxonomy ),
				'term_id' => absint( $term_id ),
			],
			admin_url( 'options-general.php' )
		);
	}

	private function get_term_overview_data( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( isset( $this->term_overview_cache[ $taxonomy ] ) ) {
			return $this->term_overview_cache[ $taxonomy ];
		}

		$rows = [];
		$empty_rows = [];

		if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
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

			foreach ( $terms as $term ) {
				if ( ! $term || ! is_object( $term ) || empty( $term->term_id ) ) {
					continue;
				}

				$words           = $this->count_words( isset( $term->description ) ? $term->description : '' );
				$has_description = $words > 0;

				$row = [
					'id'              => absint( $term->term_id ),
					'name'            => (string) $term->name,
					'slug'            => (string) $term->slug,
					'count'           => isset( $term->count ) ? absint( $term->count ) : 0,
					'words'           => $words,
					'has_description' => $has_description,
					'url'             => $this->get_term_page_url( $taxonomy, $term->term_id ),
				];

				$rows[] = $row;
				if ( ! $has_description ) {
					$empty_rows[] = $row;
				}
			}
		}

		$data = [
			'rows'        => $rows,
			'empty_rows'  => $empty_rows,
			'empty_count' => count( $empty_rows ),
		];

		$this->term_overview_cache[ $taxonomy ] = $data;

		return $data;
	}

	private function render_term_bulk_panel( $label_plural, $empty_count ) {
		$label_plural = (string) $label_plural;
		?>
		<div class="groq-ai-bulk-panel">
			<p>
				<?php
				if ( $empty_count > 0 ) {
					printf(
						/* translators: 1: amount, 2: label plural (e.g. categorieën) */
						esc_html__( 'Er zijn %1$d %2$s zonder omschrijving. Klik op de knop hieronder om automatisch teksten te genereren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						(int) $empty_count,
						esc_html( $label_plural )
					);
				} else {
					printf(
						esc_html__( 'Alle %s hebben al een omschrijving.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						esc_html( $label_plural )
					);
				}
				?>
			</p>
			<p class="groq-ai-bulk-actions">
				<?php
				$button_label = sprintf(
					esc_html__( 'Genereer teksten voor lege %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					$label_plural
				);
				?>
				<button type="button" class="button button-primary" id="groq-ai-bulk-generate"><?php echo esc_html( $button_label ); ?></button>
				<button type="button" class="button" id="groq-ai-bulk-cancel" hidden><?php esc_html_e( 'Stop bulk generatie', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
			</p>
			<div id="groq-ai-bulk-status" class="description"></div>
			<ol id="groq-ai-bulk-log" class="groq-ai-bulk-log"></ol>
		</div>
		<?php
	}

	private function localize_term_bulk_script( $taxonomy, $overrides = [] ) {
		$overview = $this->get_term_overview_data( $taxonomy );
		$rows     = isset( $overview['rows'] ) ? $overview['rows'] : [];

		$terms = [];
		foreach ( $rows as $row ) {
			$terms[] = [
				'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'name'            => isset( $row['name'] ) ? (string) $row['name'] : '',
				'slug'            => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'count'           => isset( $row['count'] ) ? (int) $row['count'] : 0,
				'words'           => isset( $row['words'] ) ? (int) $row['words'] : 0,
				'hasDescription'  => ! empty( $row['has_description'] ),
			];
		}

		$defaults = [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'groq_ai_bulk_generate_terms' ),
			'taxonomy'        => $taxonomy,
			'terms'           => $terms,
			'allowRegenerate' => false,
			'strings'         => [],
		];

		$config = wp_parse_args( $overrides, $defaults );

		wp_localize_script( 'groq-ai-term-bulk', 'GroqAITermBulk', $config );
	}

	public function render_categories_overview_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$taxonomy    = 'product_cat';
		$overview    = $this->get_term_overview_data( $taxonomy );
		$rows        = isset( $overview['rows'] ) ? $overview['rows'] : [];
		$empty_count = isset( $overview['empty_count'] ) ? (int) $overview['empty_count'] : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p><?php esc_html_e( 'Klik op een categorie om teksten te genereren en instellingen te beheren. De tabel toont de huidige woordlengte van de categorie-omschrijving.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<?php $this->render_term_bulk_panel( __( 'categorieën', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $empty_count ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Categorie', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Slug', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Producten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Woorden (omschrijving)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Acties', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Geen categorieën gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
							$row_classes = [ 'groq-ai-term-row' ];
							if ( empty( $row['has_description'] ) ) {
								$row_classes[] = 'groq-ai-term-missing';
							}
							$link  = isset( $row['url'] ) ? $row['url'] : '';
							$count = isset( $row['count'] ) ? (int) $row['count'] : 0;
							$words = isset( $row['words'] ) ? (int) $row['words'] : 0;
						?>
						<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-groq-ai-term-id="<?php echo esc_attr( isset( $row['id'] ) ? (string) $row['id'] : '' ); ?>">
							<td>
								<a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( isset( $row['name'] ) ? $row['name'] : '' ); ?></strong></a>
							</td>
							<td><?php echo esc_html( isset( $row['slug'] ) ? $row['slug'] : '' ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
							<td class="groq-ai-word-cell"><span class="groq-ai-word-count"><?php echo esc_html( (string) $words ); ?></span></td>
							<td class="groq-ai-term-actions">
								<button type="button" class="button button-secondary groq-ai-regenerate-term" data-term-id="<?php echo esc_attr( isset( $row['id'] ) ? (string) $row['id'] : '' ); ?>">
									<?php esc_html_e( 'Genereer opnieuw', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
	public function render_brands_overview_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$taxonomy = $this->detect_brand_taxonomy();
		if ( '' === $taxonomy ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
				<p><?php esc_html_e( 'Geen merk-taxonomie gevonden. Installeer/activeer een merken-plugin of stel een taxonomie in via de filter groq_ai_brand_taxonomy.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			</div>
			<?php
			return;
		}

		$overview    = $this->get_term_overview_data( $taxonomy );
		$rows        = isset( $overview['rows'] ) ? $overview['rows'] : [];
		$empty_count = isset( $overview['empty_count'] ) ? (int) $overview['empty_count'] : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: taxonomy key */
					esc_html__( 'Gedetecteerde merk-taxonomie: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					esc_html( $taxonomy )
				);
				?>
			</p>
			<?php $this->render_term_bulk_panel( __( 'merken', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $empty_count ); ?>
			<p class="description"><?php esc_html_e( 'Gebruik de knop "Genereer opnieuw" in de tabel om bestaande merkteksten opnieuw laten schrijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Merk', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Slug', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Producten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Woorden (omschrijving)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Acties', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Geen merken gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
							$row_classes = [ 'groq-ai-term-row' ];
							if ( empty( $row['has_description'] ) ) {
								$row_classes[] = 'groq-ai-term-missing';
							}
							$link  = isset( $row['url'] ) ? $row['url'] : '';
							$count = isset( $row['count'] ) ? (int) $row['count'] : 0;
							$words = isset( $row['words'] ) ? (int) $row['words'] : 0;
						?>
						<tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>" data-groq-ai-term-id="<?php echo esc_attr( isset( $row['id'] ) ? (string) $row['id'] : '' ); ?>">
							<td>
								<a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( isset( $row['name'] ) ? $row['name'] : '' ); ?></strong></a>
							</td>
							<td><?php echo esc_html( isset( $row['slug'] ) ? $row['slug'] : '' ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
							<td class="groq-ai-word-cell"><span class="groq-ai-word-count"><?php echo esc_html( (string) $words ); ?></span></td>
							<td class="groq-ai-term-actions">
								<button type="button" class="button button-secondary groq-ai-regenerate-term" data-term-id="<?php echo esc_attr( isset( $row['id'] ) ? (string) $row['id'] : '' ); ?>">
									<?php esc_html_e( 'Genereer opnieuw', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function render_modules_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_key = $this->plugin->get_option_key();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p class="description"><?php esc_html_e( 'Schakel aanvullende integraties in en bepaal grenzen voor gegenereerde content.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<?php settings_errors( $option_key ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( $option_key ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rank Math integratie', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td><?php $this->render_rankmath_module_field(); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Modules opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_logs_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$logs_table = new Groq_AI_Logs_Table( $this->plugin );
		$logs_table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="groq-ai-product-text-logs" />
				<?php $logs_table->search_box( __( 'Zoek logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'groq-ai-logs' ); ?>
				<?php $logs_table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function render_log_detail_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$log_id   = isset( $_GET['log_id'] ) ? absint( $_GET['log_id'] ) : 0;
		$back_url = $this->get_page_url( 'groq-ai-product-text-logs' );
		$log      = null;

		if ( $log_id ) {
			global $wpdb;
			$table = $wpdb->prefix . 'groq_ai_generation_logs';
			$query = $wpdb->prepare(
				"SELECT l.*, p.post_title FROM {$table} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE l.id = %d",
				$log_id
			);
			$log = $wpdb->get_row( $query, ARRAY_A );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Logdetail', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p>
				<a href="<?php echo esc_url( $back_url ); ?>" class="button">&larr; <?php esc_html_e( 'Terug naar logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
			</p>
			<?php if ( ! $log ) : ?>
				<p><?php esc_html_e( 'Log niet gevonden of verwijderd.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="margin-top:16px;">
					<tbody>
						<tr>
							<th><?php esc_html_e( 'Datum', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $log['created_at'] ) ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Gebruiker', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td>
								<?php
								if ( $log['user_id'] ) {
									$user = get_userdata( $log['user_id'] );
									echo $user ? esc_html( $user->display_name ) : esc_html( (string) $log['user_id'] );
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Product', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td>
								<?php
								if ( $log['post_id'] ) {
									$link = get_edit_post_link( $log['post_id'] );
									$title = $log['post_title'] ? $log['post_title'] : sprintf( __( 'Product #%d', GROQ_AI_PRODUCT_TEXT_DOMAIN ), (int) $log['post_id'] );
									echo $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title );
								} else {
									echo '—';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Provider', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( $log['provider'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Model', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( $log['model'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td><?php echo esc_html( $log['status'] ); ?></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Tokens', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td>
								<?php
								printf(
									esc_html__( 'Prompt: %1$s — Completion: %2$s — Totaal: %3$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
									isset( $log['tokens_prompt'] ) ? number_format_i18n( (int) $log['tokens_prompt'] ) : '—',
									isset( $log['tokens_completion'] ) ? number_format_i18n( (int) $log['tokens_completion'] ) : '—',
									isset( $log['tokens_total'] ) ? number_format_i18n( (int) $log['tokens_total'] ) : '—'
								);
								?>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Foutmelding', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
							<td><?php echo $log['error_message'] ? esc_html( $log['error_message'] ) : '—'; ?></td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;"><?php echo esc_html( $log['prompt'] ); ?></pre>

				<h2><?php esc_html_e( 'AI-respons', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<pre style="background:#f9f9f9;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;"><?php echo esc_html( $log['response'] ); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_prompt_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_key   = $this->plugin->get_option_key();
		$settings     = $this->plugin->get_settings();
		$definitions  = $this->plugin->get_context_field_definitions();
		$context_vals = isset( $settings['context_fields'] ) ? (array) $settings['context_fields'] : $this->plugin->get_default_context_fields();
		$image_mode   = $this->plugin->get_image_context_mode( $settings );
		$image_limit  = $this->plugin->get_image_context_limit( $settings );
		$preview      = $this->plugin->build_prompt_template_preview( $settings );
		$term_top_limit = $this->plugin->get_term_top_description_char_limit( $settings );
		$term_bottom_limit = $this->plugin->get_term_bottom_description_char_limit( $settings );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prompt & context', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p class="description"><?php esc_html_e( 'Bepaal welke winkelcontext standaard meegestuurd wordt en hoe de prompt eruit ziet voor nieuwe generaties.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<div style="margin:16px 0; padding:16px; background:#fff; border:1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Voorbeeldprompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
				<pre style="background:#f6f7f7; padding:12px; border-radius:4px; white-space:pre-wrap; overflow:auto; margin-top:8px;"><?php echo esc_html( $preview ); ?></pre>
			</div>
			<?php settings_errors( $option_key ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( $option_key ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="groq-ai-store-context"><?php esc_html_e( 'Winkelcontext', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea id="groq-ai-store-context" class="large-text" rows="4" name="<?php echo esc_attr( $option_key ); ?>[store_context]"><?php echo esc_textarea( isset( $settings['store_context'] ) ? $settings['store_context'] : '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Beschrijf je winkel, tone-of-voice en doelgroep. Wordt in system prompts gebruikt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-default-prompt"><?php esc_html_e( 'Standaard prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea id="groq-ai-default-prompt" class="large-text" rows="6" name="<?php echo esc_attr( $option_key ); ?>[default_prompt]"><?php echo esc_textarea( isset( $settings['default_prompt'] ) ? $settings['default_prompt'] : '' ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Vult automatisch de AI-modal en termgenerator. Je kunt dit per product/term overschrijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contextvelden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td>
							<div class="groq-ai-context-defaults">
								<?php foreach ( $definitions as $key => $info ) :
									$checked = ! empty( $context_vals[ $key ] );
									$label   = isset( $info['label'] ) ? $info['label'] : $key;
									$description = isset( $info['description'] ) ? $info['description'] : '';
									?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $option_key ); ?>[context_fields][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
										<strong><?php echo esc_html( $label ); ?></strong>
									</label>
									<?php if ( $description ) : ?>
										<p class="description" style="margin:0 0 8px 24px;"><?php echo esc_html( $description ); ?></p>
									<?php endif; ?>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Productattributen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td><?php $this->render_product_attribute_includes_field(); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Term omschrijving lengte (tekens)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td>
							<label style="display:block; margin-bottom:8px;">
								<span><?php esc_html_e( 'Korte omschrijving (top_description)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></span><br />
								<input type="number" name="<?php echo esc_attr( $option_key ); ?>[term_top_description_char_limit]" value="<?php echo esc_attr( $term_top_limit ); ?>" min="100" max="5000" step="10" />
							</label>
							<label style="display:block;">
								<span><?php esc_html_e( 'Lange omschrijving (bottom_description)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></span><br />
								<input type="number" name="<?php echo esc_attr( $option_key ); ?>[term_bottom_description_char_limit]" value="<?php echo esc_attr( $term_bottom_limit ); ?>" min="100" max="5000" step="10" />
							</label>
							<p class="description">
								<?php esc_html_e( 'Deze waardes worden doorgegeven aan de AI met een marge van ±10%. Gebruik dit om bijvoorbeeld short-form (bv. 600 tekens) en long-form (bv. 1200 tekens) teksten te sturen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-image-mode"><?php esc_html_e( 'Afbeeldingen als context', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<select id="groq-ai-image-mode" name="<?php echo esc_attr( $option_key ); ?>[image_context_mode]">
								<option value="none" <?php selected( 'none', $image_mode ); ?>><?php esc_html_e( 'Niet meesturen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></option>
								<option value="url" <?php selected( 'url', $image_mode ); ?>><?php esc_html_e( 'Alleen URL en korte beschrijving', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></option>
								<option value="base64" <?php selected( 'base64', $image_mode ); ?>><?php esc_html_e( 'Inline base64 (alleen voor modellen die dit vereisen)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sommige modellen (zoals Gemini) ondersteunen beeldcontext; let op je tokenverbruik.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
							<label>
								<?php esc_html_e( 'Maximaal aantal afbeeldingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
								<input type="number" min="1" max="10" name="<?php echo esc_attr( $option_key ); ?>[image_context_limit]" value="<?php echo esc_attr( $image_limit ); ?>" style="width:80px;" />
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Prompt instellingen opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ); ?>
			</form>
		</div>
		<?php
	}

	public function render_term_generator_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
		$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! $term_id ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
				<p><?php esc_html_e( 'Ongeldige term.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			</div>
			<?php
			return;
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			?>
			<div class="wrap">
				<h1><?php esc_html_e( 'Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
				<p><?php esc_html_e( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			</div>
			<?php
			return;
		}

		$term_notice         = isset( $_GET['groq_ai_term_notice'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_term_notice'] ) ) : '';
		$term_notice_status  = isset( $_GET['groq_ai_term_status'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_term_status'] ) ) : 'success';
		$term_notice_message = '';
		if ( isset( $_GET['groq_ai_term_notice_message'] ) ) {
			$term_notice_message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['groq_ai_term_notice_message'] ) ) );
		}
		if ( $term_notice && '' === $term_notice_message ) {
			if ( 'saved' === $term_notice ) {
				$term_notice_message = __( 'Term succesvol opgeslagen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			} else {
				$term_notice_message = __( 'Actie voltooid.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			}
		}

		$term_label = ( 'product_cat' === $taxonomy ) ? __( 'Categorie', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : __( 'Term', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$word_count = $this->count_words( $term->description );
		$meta_prompt = get_term_meta( $term_id, 'groq_ai_term_custom_prompt', true );
		$settings = $this->plugin->get_settings();
		$bottom_meta_key = $this->resolve_term_bottom_description_meta_key( $term, $settings );
		$effective_bottom_meta_key = '' !== $bottom_meta_key ? $bottom_meta_key : 'groq_ai_term_bottom_description';
		$bottom_description = (string) get_term_meta( $term_id, $effective_bottom_meta_key, true );
		$rankmath_module_enabled = $this->plugin->is_module_enabled( 'rankmath', $settings );
		$rankmath_active = $this->plugin->is_rankmath_active();
		$rankmath_title = '';
		$rankmath_description = '';
		$rankmath_focus_keywords = '';
		if ( $rankmath_module_enabled ) {
			$rankmath_keys = $this->resolve_rankmath_term_meta_keys( $term, $settings );
			$rankmath_title = (string) get_term_meta( $term_id, $rankmath_keys['title'], true );
			$rankmath_description = (string) get_term_meta( $term_id, $rankmath_keys['description'], true );
			$rankmath_focus_keywords = (string) get_term_meta( $term_id, $rankmath_keys['focus_keyword'], true );
		}
		$default_prompt = $this->get_term_prompt_text( $term, $meta_prompt );
		?>
		<div class="wrap">
			<h1>
				<?php echo esc_html( $term_label ); ?>: <?php echo esc_html( $term->name ); ?>
			</h1>
			<?php if ( $term_notice ) : ?>
				<?php $notice_class = ( 'error' === $term_notice_status ) ? 'notice notice-error' : 'notice notice-success'; ?>
				<div class="<?php echo esc_attr( $notice_class ); ?>">
					<p><?php echo esc_html( $term_notice_message ); ?></p>
				</div>
			<?php endif; ?>
			<p>
				<?php
				printf(
					/* translators: 1: taxonomy key, 2: term id, 3: word count */
					esc_html__( 'Taxonomie: %1$s — Term ID: %2$d — Huidige omschrijving: %3$d woorden', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					esc_html( $taxonomy ),
					(int) $term_id,
					(int) $word_count
				);
				?>
			</p>

			<h2><?php esc_html_e( 'Omschrijving bewerken', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'groq_ai_save_term_content', '_wpnonce' ); ?>
				<input type="hidden" name="action" value="groq_ai_save_term_content" />
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
				<input type="hidden" name="term_id" value="<?php echo esc_attr( (string) $term_id ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="description"><?php esc_html_e( 'Omschrijving', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea name="description" id="description" rows="8" class="large-text"><?php echo esc_textarea( (string) $term->description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Dit is de standaard WordPress term-omschrijving (wordt o.a. gebruikt op categorie/merk pagina’s).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-term-bottom-description"><?php esc_html_e( 'Omschrijving (onderaan)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea name="groq_ai_term_bottom_description" id="groq-ai-term-bottom-description" rows="8" class="large-text"><?php echo esc_textarea( (string) $bottom_description ); ?></textarea>
							<p class="description">
								<?php
									printf(
										/* translators: %s: meta key */
										esc_html__( 'Deze tekst wordt opgeslagen in term meta (%s) en is bedoeld voor helemaal onderaan (LiveBetter customfields).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
										esc_html( $effective_bottom_meta_key )
									);
									if ( '' === $bottom_meta_key ) {
										echo ' ' . esc_html__( 'Let op: stel de juiste LiveBetter meta key in via de plugin-instelling of via de filter groq_ai_term_bottom_description_meta_key.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
									}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-term-custom-prompt"><?php esc_html_e( 'Prompt (optioneel, per term)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea name="groq_ai_term_custom_prompt" id="groq-ai-term-custom-prompt" rows="4" class="large-text"><?php echo esc_textarea( (string) $meta_prompt ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Laat leeg om de standaard prompt te gebruiken. Deze prompt wordt gebruikt wanneer je op de knop "Genereer" klikt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<?php if ( $rankmath_module_enabled ) : ?>
						<?php if ( ! $rankmath_active ) : ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'Rank Math', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
								<td>
									<p class="description"><?php esc_html_e( 'Rank Math plugin lijkt niet actief. Velden zijn wel invulbaar en worden opgeslagen in term meta, maar Rank Math gebruikt ze pas na activatie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
								</td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-title"><?php esc_html_e( 'Rank Math meta title', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea name="groq_ai_rankmath_meta_title" id="groq-ai-rankmath-title" rows="2" class="large-text"><?php echo esc_textarea( (string) $rankmath_title ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-description"><?php esc_html_e( 'Rank Math meta description', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea name="groq_ai_rankmath_meta_description" id="groq-ai-rankmath-description" rows="3" class="large-text"><?php echo esc_textarea( (string) $rankmath_description ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-keywords"><?php esc_html_e( 'Rank Math focus keywords', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea name="groq_ai_rankmath_focus_keywords" id="groq-ai-rankmath-keywords" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'bijv. luxe massage apparaat, wellness cadeau', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>"><?php echo esc_textarea( (string) $rankmath_focus_keywords ); ?></textarea>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ); ?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Tekst genereren', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
			<form id="groq-ai-term-form">
				<p class="description"><?php esc_html_e( 'De AI gebruikt de winkelcontext + termcontext (o.a. top-verkopers in deze categorie/dit merk). Later voegen we Search Console/Analytics context toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
				<p>
					<label>
						<input type="checkbox" id="groq-ai-term-include-top-products" checked />
						<?php esc_html_e( 'Top producten meenemen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
					</label>
					&nbsp;
					<label>
						<?php esc_html_e( 'Aantal:', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
						<input type="number" id="groq-ai-term-top-products-limit" value="10" min="1" max="25" style="width:80px;" />
					</label>
				</p>
				<textarea id="groq-ai-term-prompt" class="large-text" rows="5"><?php echo esc_textarea( $default_prompt ); ?></textarea>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Genereer', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
					<button type="button" class="button" id="groq-ai-term-apply"><?php esc_html_e( 'Zet in velden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
				</p>
				<div id="groq-ai-term-status" class="description" aria-live="polite"></div>
				<h3><?php esc_html_e( 'Gegenereerde tekst (omschrijving, 1 alinea)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
				<textarea id="groq-ai-term-generated-top" class="large-text" rows="6"></textarea>
				<h3><?php esc_html_e( 'Gegenereerde tekst (onderaan)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
				<textarea id="groq-ai-term-generated-bottom" class="large-text" rows="10"></textarea>
				<?php if ( $rankmath_module_enabled ) : ?>
					<h3><?php esc_html_e( 'Gegenereerde Rank Math meta title', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
					<textarea id="groq-ai-term-generated-meta-title" class="large-text" rows="2"></textarea>
					<h3><?php esc_html_e( 'Gegenereerde Rank Math meta description', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
					<textarea id="groq-ai-term-generated-meta-description" class="large-text" rows="3"></textarea>
					<h3><?php esc_html_e( 'Gegenereerde Rank Math focus keywords', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
					<textarea id="groq-ai-term-generated-focus-keywords" class="large-text" rows="2"></textarea>
				<?php endif; ?>
				<h3><?php esc_html_e( 'Ruwe JSON-output', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h3>
				<pre id="groq-ai-term-raw" style="background:#fff;border:1px solid #ddd;padding:12px;max-height:240px;overflow:auto;"></pre>
			</form>
		</div>
		<?php
	}

	private function resolve_term_bottom_description_meta_key( $term, $settings ) {
		$default_key = '';
		if ( is_array( $settings ) && isset( $settings['term_bottom_description_meta_key'] ) ) {
			$default_key = sanitize_key( (string) $settings['term_bottom_description_meta_key'] );
		}

		$key = apply_filters( 'groq_ai_term_bottom_description_meta_key', $default_key, $term, $settings );
		$key = sanitize_key( (string) $key );
		return $key;
	}

	private function resolve_rankmath_term_meta_keys( $term, $settings ) {
		$keys = [
			'title'        => 'rank_math_title',
			'description'  => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		];
		$keys = apply_filters( 'groq_ai_rankmath_term_meta_keys', $keys, $term, $settings );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}

		return [
			'title'        => isset( $keys['title'] ) ? sanitize_key( (string) $keys['title'] ) : 'rank_math_title',
			'description'  => isset( $keys['description'] ) ? sanitize_key( (string) $keys['description'] ) : 'rank_math_description',
			'focus_keyword' => isset( $keys['focus_keyword'] ) ? sanitize_key( (string) $keys['focus_keyword'] ) : 'rank_math_focus_keyword',
		];
	}

	private function get_term_prompt_text( $term, $custom_prompt = null ) {
		$prompt = ( null !== $custom_prompt ) ? $custom_prompt : '';

		if ( null === $custom_prompt && $term && isset( $term->term_id ) ) {
			$prompt = get_term_meta( $term->term_id, 'groq_ai_term_custom_prompt', true );
		}

		$prompt = trim( (string) $prompt );
		if ( '' !== $prompt ) {
			return $prompt;
		}

		$default_prompt = __( 'Schrijf een SEO-vriendelijke categorieomschrijving in het Nederlands. Gebruik duidelijke tussenkoppen en <p>-tags. Voeg geen prijsinformatie toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN );

		return apply_filters( 'groq_ai_default_term_prompt', $default_prompt, $term );
	}

	

	public function render_product_attribute_includes_field() {
		$settings = $this->plugin->get_settings();
		$values   = isset( $settings['product_attribute_includes'] ) && is_array( $settings['product_attribute_includes'] )
			? $settings['product_attribute_includes']
			: [];
		$values = array_values( array_unique( array_map( 'sanitize_key', $values ) ) );

		$options = $this->get_product_attribute_include_options();
		?>
		<div class="groq-ai-attribute-includes">
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Selecteer welke productattributen je als context mee wilt sturen naar de AI. Als je niets selecteert, worden attributen niet meegestuurd (tenzij je dit eerder al had ingeschakeld via de oude instelling).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
			<?php if ( empty( $options ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Geen WooCommerce-attributen gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</p>
			<?php else : ?>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px;">
					<?php foreach ( $options as $key => $label ) :
						$checked = in_array( $key, $values, true );
						?>
						<label style="display:flex;gap:8px;align-items:flex-start;">
							<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[product_attribute_includes][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $checked ); ?> />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function get_product_attribute_include_options() {
		$options = [
			'__custom__' => __( 'Custom attributen (niet-taxonomie)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
		];

		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			$taxonomies = wc_get_attribute_taxonomies();
			if ( is_array( $taxonomies ) ) {
				foreach ( $taxonomies as $attr ) {
					$name  = isset( $attr->attribute_name ) ? sanitize_key( (string) $attr->attribute_name ) : '';
					$label = isset( $attr->attribute_label ) ? sanitize_text_field( (string) $attr->attribute_label ) : '';
					if ( '' === $name ) {
						continue;
					}
					$taxonomy = 'pa_' . $name;
					if ( '' === $label ) {
						$label = function_exists( 'wc_attribute_label' ) ? wc_attribute_label( $taxonomy ) : $taxonomy;
					}
					$options[ $taxonomy ] = $label;
				}
			}
		}

		if ( count( $options ) > 1 ) {
			$fixed = [
				'__custom__' => $options['__custom__'],
			];
			unset( $options['__custom__'] );
			asort( $options, SORT_NATURAL | SORT_FLAG_CASE );
			$options = $fixed + $options;
		}

		return $options;
	}

	public function render_response_format_compat_field() {
		$settings = $this->plugin->get_settings();
		$is_enabled = ! empty( $settings['response_format_compat'] );
		?>
		<label>
			<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[response_format_compat]" value="1" <?php checked( $is_enabled ); ?> />
			<?php esc_html_e( 'Compatibele modus inschakelen (instructies toevoegen aan de prompt).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Standaard gebruikt de plugin de response_format-functie van aanbieders zoals Groq en OpenAI voor gegarandeerde JSON-uitvoer. Schakel deze optie alleen in wanneer je problemen ervaart met oudere modellen of eigen integraties die deze functie niet ondersteunen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_rankmath_module_field() {
		$settings       = $this->plugin->get_settings();
		$defaults       = $this->plugin->get_default_modules_settings();
		$modules        = isset( $settings['modules'] ) ? $settings['modules'] : $defaults;
		$config         = isset( $modules['rankmath'] ) ? $modules['rankmath'] : ( $defaults['rankmath'] ?? [] );
		$rankmath_active = $this->plugin->is_rankmath_active();
		$enabled        = $rankmath_active && ! empty( $config['enabled'] );
		$keyword_limit  = isset( $config['focus_keyword_limit'] ) ? absint( $config['focus_keyword_limit'] ) : ( $defaults['rankmath']['focus_keyword_limit'] ?? 3 );
		$keyword_limit  = $keyword_limit > 0 ? $keyword_limit : 3;
		$title_pixels   = isset( $config['meta_title_pixel_limit'] ) ? absint( $config['meta_title_pixel_limit'] ) : ( $defaults['rankmath']['meta_title_pixel_limit'] ?? 580 );
		$title_pixels   = $title_pixels > 0 ? $title_pixels : 580;
		$pixel_limit    = isset( $config['meta_description_pixel_limit'] ) ? absint( $config['meta_description_pixel_limit'] ) : ( $defaults['rankmath']['meta_description_pixel_limit'] ?? 920 );
		$pixel_limit    = $pixel_limit > 0 ? $pixel_limit : 920;
		$rankmath_active = $this->plugin->is_rankmath_active();
		?>
		<div class="groq-ai-module-field">
			<input type="hidden" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[modules][rankmath][enabled]" value="0" />
			<label>
				<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[modules][rankmath][enabled]" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $rankmath_active ); ?> />
				<?php esc_html_e( 'Activeer Rank Math integratie (meta title, meta description en focus keywords genereren).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</label>
			<p class="description" style="margin-top:4px;">
				<?php
				if ( ! $rankmath_active ) {
					esc_html_e( 'Installeer en activeer Rank Math om deze opties te gebruiken. Velden zijn momenteel alleen-lezen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				} else {
					esc_html_e( 'Wanneer ingeschakeld worden extra velden in de AI-modal getoond en automatisch gekoppeld aan Rank Math.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				}
				?>
			</p>
			<label for="groq-ai-rankmath-keywords">
				<?php esc_html_e( 'Aantal focus keywords om te genereren', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</label>
			<input
				type="number"
				id="groq-ai-rankmath-keywords"
				min="1"
				max="100"
				name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[modules][rankmath][focus_keyword_limit]"
				value="<?php echo esc_attr( $keyword_limit ); ?>"
				style="width: 80px;"
				<?php disabled( ! $rankmath_active ); ?>
			/>
			<p class="description">
				<?php esc_html_e( 'Bepaal hoeveel zoekwoorden de AI maximaal mag teruggeven (bijvoorbeeld 3).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
			<label for="groq-ai-rankmath-title-pixels">
				<?php esc_html_e( 'Maximale meta title breedte (pixels)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</label>
			<input
				type="number"
				id="groq-ai-rankmath-title-pixels"
				min="1"
				max="1200"
				step="1"
				name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[modules][rankmath][meta_title_pixel_limit]"
				value="<?php echo esc_attr( $title_pixels ); ?>"
				style="width: 100px;"
				<?php disabled( ! $rankmath_active ); ?>
			/>
			<p class="description">
				<?php esc_html_e( 'Bepaal hoe breed (in pixels) de meta title maximaal mag zijn volgens de SERP-richtlijnen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
			<label for="groq-ai-rankmath-pixels">
				<?php esc_html_e( 'Maximale meta description breedte (pixels)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</label>
			<input
				type="number"
				id="groq-ai-rankmath-pixels"
				min="1"
				max="2000"
				step="1"
				name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[modules][rankmath][meta_description_pixel_limit]"
				value="<?php echo esc_attr( $pixel_limit ); ?>"
				style="width: 100px;"
				<?php disabled( ! $rankmath_active ); ?>
			/>
			<p class="description">
				<?php esc_html_e( 'Gebruik het SERP-voorbeeld als referentie. De AI krijgt door dat de meta description deze pixelbreedte niet mag overschrijden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
		</div>
		<?php
	}

	public function enqueue_settings_assets( $hook ) {
		$allowed_hooks = [
			'settings_page_groq-ai-product-text',
			'settings_page_groq-ai-product-text-modules',
			'settings_page_groq-ai-product-text-prompts',
			'settings_page_groq-ai-product-text-categories',
			'settings_page_groq-ai-product-text-brands',
			'settings_page_groq-ai-product-text-term',
			'settings_page_groq-ai-product-text-logs',
		];

		$matches_hook = false;
		foreach ( $allowed_hooks as $allowed ) {
			if ( 0 === strpos( (string) $hook, $allowed ) ) {
				$matches_hook = true;
				break;
			}
		}

		if ( ! $matches_hook ) {
			return;
		}

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

		wp_enqueue_script(
			'groq-ai-settings',
			plugins_url( 'assets/js/settings.js', GROQ_AI_PRODUCT_TEXT_FILE ),
			[],
			GROQ_AI_PRODUCT_TEXT_VERSION,
			true
		);

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( 0 === strpos( (string) $hook, 'settings_page_groq-ai-product-text-term' ) ) {
			wp_enqueue_script(
				'groq-ai-term-admin',
				plugins_url( 'assets/js/term-admin.js', GROQ_AI_PRODUCT_TEXT_FILE ),
				[],
				GROQ_AI_PRODUCT_TEXT_VERSION,
				true
			);

			$taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_key( wp_unslash( $_GET['taxonomy'] ) ) : '';
			$term_id  = isset( $_GET['term_id'] ) ? absint( $_GET['term_id'] ) : 0;
			wp_localize_script(
				'groq-ai-term-admin',
				'GroqAITermGenerator',
				[
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'  => wp_create_nonce( 'groq_ai_generate_term' ),
					'taxonomy' => $taxonomy,
					'termId' => $term_id,
				]
			);
		}

		$bulk_taxonomy     = '';
		$bulk_allow_regen  = false;
		$bulk_strings      = [];

		if ( 0 === strpos( (string) $hook, 'settings_page_groq-ai-product-text-categories' ) ) {
			$bulk_taxonomy    = 'product_cat';
			$bulk_allow_regen = true;
			$bulk_strings     = [
				'statusIdle'     => __( 'Bulk gestart. AI werkt de geselecteerde categorieën bij…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusProgress' => __( 'Categorie %1$s van %2$s: %3$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusDone'     => __( 'Klaar! %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusStopped'  => __( 'Bulk generatie gestopt. %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusEmpty'    => __( 'Geen categorieën zonder omschrijving gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logSuccess'     => __( '%1$s gevuld (%2$d woorden).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logError'       => __( '%1$s mislukt: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'confirmStop'    => __( 'Weet je zeker dat je wilt stoppen? De huidige categorie kan onafgemaakt blijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'confirmRegenerate'  => __( 'Wil je categorie %s opnieuw laten schrijven?', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateProgress' => __( '%s wordt opnieuw geschreven…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateDone'     => __( '%s is bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateError'    => __( 'Kon %1$s niet bijwerken: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateBlocked'  => __( 'Wacht tot de bulk generatie klaar is voordat je een categorie opnieuw genereert.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			];
		} elseif ( 0 === strpos( (string) $hook, 'settings_page_groq-ai-product-text-brands' ) ) {
			$detected_taxonomy = $this->detect_brand_taxonomy();
			if ( '' !== $detected_taxonomy ) {
				$bulk_taxonomy    = $detected_taxonomy;
				$bulk_allow_regen = true;
				$bulk_strings     = [
					'statusIdle'           => __( 'Bulk gestart. AI werkt de geselecteerde merken bij…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusProgress'       => __( 'Merk %1$s van %2$s: %3$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusDone'           => __( 'Klaar! %d merken bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusStopped'        => __( 'Bulk generatie gestopt. %d merken bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusEmpty'          => __( 'Geen merken zonder omschrijving gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'logSuccess'           => __( '%1$s gevuld (%2$d woorden).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'logError'             => __( '%1$s mislukt: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'confirmStop'          => __( 'Weet je zeker dat je wilt stoppen? Het huidige merk kan onafgemaakt blijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'confirmRegenerate'    => __( 'Wil je %s opnieuw laten schrijven?', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateProgress'   => __( '%s wordt opnieuw geschreven…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateDone'       => __( '%s is bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateError'      => __( 'Kon %1$s niet bijwerken: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateBlocked'    => __( 'Wacht tot de bulk generatie klaar is voordat je een merk opnieuw genereert.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				];
			}
		}

		if ( '' !== $bulk_taxonomy ) {
			wp_enqueue_script(
				'groq-ai-term-bulk',
				plugins_url( 'assets/js/term-bulk.js', GROQ_AI_PRODUCT_TEXT_FILE ),
				[],
				GROQ_AI_PRODUCT_TEXT_VERSION,
				true
			);

			$this->localize_term_bulk_script(
				$bulk_taxonomy,
				[
					'allowRegenerate' => $bulk_allow_regen,
					'strings'         => $bulk_strings,
				]
			);
		}

		$current_settings = $this->plugin->get_settings();
		$data = [
			'optionKey'       => $this->plugin->get_option_key(),
			'providers'       => [],
			'currentProvider' => $current_settings['provider'],
			'currentModel'    => $current_settings['model'],
			'providerRows'    => [],
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'refreshNonce'    => wp_create_nonce( 'groq_ai_refresh_models' ),
			'excludedModels'  => Groq_AI_Model_Exclusions::get_all(),
			'placeholders'    => [
				'selectModel' => __( 'Selecteer een model via "Live modellen ophalen"', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
		];

		foreach ( $this->provider_manager->get_providers() as $provider ) {
			$provider_key   = $provider->get_key();
			$cached_models  = $this->plugin->get_cached_models_for_provider( $provider_key );
			$cached_models  = Groq_AI_Model_Exclusions::filter_models( $provider_key, $cached_models );
			$data['providers'][ $provider->get_key() ] = [
				'default_label' => sprintf( __( 'Gebruik standaardmodel (%s)', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $provider->get_default_model() ),
				'models'        => $cached_models,
				'supports_live' => $provider->supports_live_models(),
			];
			$data['providerRows'][ $provider->get_key() ] = 'groq_ai_api_key_' . $provider->get_key();
		}

		wp_localize_script( 'groq-ai-settings', 'GroqAISettingsData', $data );
	}

	public function handle_google_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'groq_ai_google_oauth' );

		$redirect = $this->get_request_redirect_url( 'redirect_to' );
		$settings = $this->plugin->get_settings();

		$client_id     = isset( $settings['google_oauth_client_id'] ) ? trim( (string) $settings['google_oauth_client_id'] ) : '';
		$client_secret = isset( $settings['google_oauth_client_secret'] ) ? trim( (string) $settings['google_oauth_client_secret'] ) : '';

		if ( '' === $client_id || '' === $client_secret ) {
			$this->redirect_with_google_notice( 'error', __( 'Vul eerst het Google client ID en secret in en sla de instellingen op.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$state_payload = [
			'nonce'     => wp_create_nonce( 'groq_ai_google_oauth_state' ),
			'redirect'  => $redirect,
			'timestamp' => time(),
		];

		$state_json = wp_json_encode( $state_payload );
		if ( ! is_string( $state_json ) ) {
			$state_json = wp_json_encode( (object) [] );
		}
		$state = base64_encode( (string) $state_json );

		$scopes = [
			'https://www.googleapis.com/auth/webmasters.readonly',
			'https://www.googleapis.com/auth/analytics.readonly',
			'https://www.googleapis.com/auth/userinfo.email',
		];

		$auth_url = add_query_arg(
			[
				'response_type'          => 'code',
				'client_id'              => $client_id,
				'redirect_uri'           => $this->get_google_redirect_uri(),
				'scope'                  => implode( ' ', $scopes ),
				'access_type'            => 'offline',
				'prompt'                 => 'consent',
				'include_granted_scopes' => 'true',
				'state'                  => $state,
			],
			'https://accounts.google.com/o/oauth2/v2/auth'
		);

		wp_safe_redirect( $auth_url );
		exit;
	}

	public function handle_google_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
		}

		$state_value = isset( $_GET['state'] ) ? wp_unslash( $_GET['state'] ) : '';
		$state       = $this->parse_oauth_state( $state_value );
		$redirect    = isset( $state['redirect'] ) ? wp_validate_redirect( (string) $state['redirect'], $this->get_page_url() ) : $this->get_page_url();

		if ( isset( $_GET['error'] ) ) {
			$error_message = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			if ( isset( $_GET['error_description'] ) ) {
				$error_message .= ': ' . sanitize_text_field( wp_unslash( $_GET['error_description'] ) );
			}
			$this->redirect_with_google_notice( 'error', $error_message, $redirect, 'error' );
		}

		if ( isset( $state['nonce'] ) && ! wp_verify_nonce( $state['nonce'], 'groq_ai_google_oauth_state' ) ) {
			$this->redirect_with_google_notice( 'error', __( 'Ongeldige OAuth-sessie. Probeer het opnieuw.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$timestamp = isset( $state['timestamp'] ) ? absint( $state['timestamp'] ) : 0;
		if ( $timestamp && ( time() - $timestamp ) > HOUR_IN_SECONDS ) {
			$this->redirect_with_google_notice( 'error', __( 'OAuth-sessie verlopen. Probeer het opnieuw.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		if ( '' === $code ) {
			$this->redirect_with_google_notice( 'error', __( 'Geen autorisatiecode ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$settings      = $this->plugin->get_settings();
		$client_id     = isset( $settings['google_oauth_client_id'] ) ? trim( (string) $settings['google_oauth_client_id'] ) : '';
		$client_secret = isset( $settings['google_oauth_client_secret'] ) ? trim( (string) $settings['google_oauth_client_secret'] ) : '';

		if ( '' === $client_id || '' === $client_secret ) {
			$this->redirect_with_google_notice( 'error', __( 'Google client ID en secret ontbreken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 20,
				'body'    => [
					'code'          => $code,
					'client_id'     => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri'  => $this->get_google_redirect_uri(),
					'grant_type'    => 'authorization_code',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			$this->redirect_with_google_notice( 'error', $response->get_error_message(), $redirect, 'error' );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( (string) $body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			$this->redirect_with_google_notice( 'error', __( 'Kon tokens niet ophalen bij Google.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$access_token  = isset( $data['access_token'] ) ? trim( (string) $data['access_token'] ) : '';
		$refresh_token = isset( $data['refresh_token'] ) ? trim( (string) $data['refresh_token'] ) : '';

		if ( '' === $refresh_token ) {
			$existing = isset( $settings['google_oauth_refresh_token'] ) ? (string) $settings['google_oauth_refresh_token'] : '';
			$refresh_token = $existing;
		}

		if ( '' === $refresh_token ) {
			$this->redirect_with_google_notice( 'error', __( 'Google retourneerde geen refresh token. Forceer toestemming opnieuw en probeer het nogmaals.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		if ( '' === $access_token ) {
			$this->redirect_with_google_notice( 'error', __( 'Google retourneerde geen access token.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect, 'error' );
		}

		$email = '';
		$userinfo = wp_remote_get(
			'https://openidconnect.googleapis.com/v1/userinfo',
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Bearer ' . $access_token,
				],
			]
		);

		if ( ! is_wp_error( $userinfo ) ) {
			$userinfo_code = wp_remote_retrieve_response_code( $userinfo );
			$userinfo_body = json_decode( wp_remote_retrieve_body( $userinfo ), true );
			if ( 200 === $userinfo_code && is_array( $userinfo_body ) && isset( $userinfo_body['email'] ) ) {
				$email = sanitize_email( (string) $userinfo_body['email'] );
			}
		}

		$this->update_settings_partial(
			[
				'google_oauth_refresh_token'   => sanitize_text_field( $refresh_token ),
				'google_oauth_connected_email' => $email,
				'google_oauth_connected_at'    => current_time( 'timestamp' ),
			]
		);

		$this->redirect_with_google_notice( 'connected', __( 'Google OAuth is verbonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect );
	}

	public function handle_google_oauth_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'groq_ai_google_disconnect' );

		$redirect = $this->get_request_redirect_url( 'redirect_to' );

		$this->update_settings_partial(
			[
				'google_oauth_refresh_token'   => '',
				'google_oauth_connected_email' => '',
				'google_oauth_connected_at'    => 0,
			]
		);

		$this->redirect_with_google_notice( 'disconnected', __( 'Google OAuth is ontkoppeld.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $redirect );
	}

	public function handle_google_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'groq_ai_google_test_connection' );

		$redirect = $this->get_request_redirect_url( 'redirect_to' );
		$settings = $this->plugin->get_settings();

		$oauth_client = new Groq_AI_Google_OAuth_Client();
		$token        = $oauth_client->get_access_token( $settings );

		if ( is_wp_error( $token ) ) {
			$this->redirect_with_google_notice( 'test', $token->get_error_message(), $redirect, 'error' );
		}

		$messages = [ __( 'OAuth token opgehaald.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ];

		$token_info = $oauth_client->get_access_token_info( $token );
		if ( ! is_wp_error( $token_info ) && ! empty( $token_info['scope'] ) ) {
			$messages[] = sprintf( __( 'Scopes: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $token_info['scope'] );
		}

		if ( ! empty( $settings['google_enable_gsc'] ) && ! empty( $settings['google_gsc_site_url'] ) ) {
			$gsc_client = new Groq_AI_Google_Search_Console_Client( $oauth_client );
			$result     = $gsc_client->list_sites( $settings );
			if ( is_wp_error( $result ) ) {
				$this->redirect_with_google_notice( 'test', $result->get_error_message(), $redirect, 'error' );
			}
			$messages[] = __( 'Search Console API bereikbaar.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		}

		if ( ! empty( $settings['google_enable_ga'] ) && ! empty( $settings['google_ga4_property_id'] ) ) {
			$ga_client = new Groq_AI_Google_Analytics_Data_Client( $oauth_client );
			$end_date  = gmdate( 'Y-m-d' );
			$start_date = gmdate( 'Y-m-d', time() - ( 7 * DAY_IN_SECONDS ) );
			$summary   = $ga_client->get_property_sessions_summary( $settings, $settings['google_ga4_property_id'], $start_date, $end_date );
			if ( is_wp_error( $summary ) ) {
				$this->redirect_with_google_notice( 'test', $summary->get_error_message(), $redirect, 'error' );
			}
			$sessions = isset( $summary['sessions'] ) ? absint( $summary['sessions'] ) : 0;
			$messages[] = sprintf( __( 'GA4 API bereikbaar (sessies laatste 7 dagen: %d).', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $sessions );
		}

		$this->redirect_with_google_notice( 'test', implode( ' ', $messages ), $redirect );
	}

	public function handle_save_term_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( 'groq_ai_save_term_content' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! $term_id ) {
			$this->redirect_with_term_notice( $taxonomy, $term_id, 'error', __( 'Ongeldige term.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'error' );
		}

		$term = get_term( $term_id, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			$this->redirect_with_term_notice( $taxonomy, $term_id, 'error', __( 'Term niet gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'error' );
		}

		$description        = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$bottom_description = isset( $_POST['groq_ai_term_bottom_description'] ) ? wp_kses_post( wp_unslash( $_POST['groq_ai_term_bottom_description'] ) ) : '';
		$custom_prompt      = isset( $_POST['groq_ai_term_custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['groq_ai_term_custom_prompt'] ) ) : '';

		$update = wp_update_term(
			$term_id,
			$taxonomy,
			[
				'description' => $description,
			]
		);

		if ( is_wp_error( $update ) ) {
			$this->redirect_with_term_notice( $taxonomy, $term_id, 'error', $update->get_error_message(), 'error' );
		}

		$settings        = $this->plugin->get_settings();
		$bottom_meta_key = $this->resolve_term_bottom_description_meta_key( $term, $settings );
		$bottom_meta_key = '' !== $bottom_meta_key ? $bottom_meta_key : 'groq_ai_term_bottom_description';
		update_term_meta( $term_id, $bottom_meta_key, $bottom_description );

		if ( '' === trim( $custom_prompt ) ) {
			delete_term_meta( $term_id, 'groq_ai_term_custom_prompt' );
		} else {
			update_term_meta( $term_id, 'groq_ai_term_custom_prompt', $custom_prompt );
		}

		if ( isset( $_POST['groq_ai_term_bottom_description'] ) && $bottom_meta_key !== 'groq_ai_term_bottom_description' ) {
			update_term_meta( $term_id, 'groq_ai_term_bottom_description', $bottom_description );
		}

		if ( $this->plugin->is_module_enabled( 'rankmath', $settings ) ) {
			$rankmath_keys        = $this->resolve_rankmath_term_meta_keys( $term, $settings );
			$rankmath_title       = isset( $_POST['groq_ai_rankmath_meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['groq_ai_rankmath_meta_title'] ) ) : '';
			$rankmath_description = isset( $_POST['groq_ai_rankmath_meta_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['groq_ai_rankmath_meta_description'] ) ) : '';
			$rankmath_keywords    = isset( $_POST['groq_ai_rankmath_focus_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['groq_ai_rankmath_focus_keywords'] ) ) : '';

			update_term_meta( $term_id, $rankmath_keys['title'], $rankmath_title );
			update_term_meta( $term_id, $rankmath_keys['description'], $rankmath_description );
			update_term_meta( $term_id, $rankmath_keys['focus_keyword'], $rankmath_keywords );
		}

		$this->redirect_with_term_notice( $taxonomy, $term_id, 'saved', __( 'Term opgeslagen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
	}
}
