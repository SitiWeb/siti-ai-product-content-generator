<?php

class Groq_AI_Product_Text_Settings_Page extends Groq_AI_Admin_Base {
	private $provider_manager;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin, Groq_AI_Provider_Manager $provider_manager ) {
		parent::__construct( $plugin );
		$this->provider_manager = $provider_manager;

		add_action( 'admin_menu', [ $this, 'register_settings_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_head', [ $this, 'hide_menu_links' ] );
		add_action( 'admin_post_groq_ai_google_oauth_start', [ $this, 'handle_google_oauth_start' ] );
		add_action( 'admin_post_groq_ai_google_oauth_callback', [ $this, 'handle_google_oauth_callback' ] );
		add_action( 'admin_post_groq_ai_google_oauth_disconnect', [ $this, 'handle_google_oauth_disconnect' ] );
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
			__( 'Siti AI Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-modules',
			[ $this, 'render_modules_page' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-prompts',
			[ $this, 'render_prompt_settings_page' ]
		);

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

		$google_safety_settings = $this->plugin->get_google_safety_settings( $settings );
		$google_safety_categories = $this->plugin->get_google_safety_categories();
		$google_safety_thresholds = $this->plugin->get_google_safety_thresholds();
		$renderer = $this->plugin->create_settings_renderer( $settings );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Kies je AI-aanbieder en beheer API-sleutels voor de contentgeneratie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</p>
			<p style="margin:16px 0; display:flex; flex-wrap:wrap; gap:8px;">
				<a class="button" href="<?php echo esc_url( $prompt_url ); ?>"><?php esc_html_e( 'Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $modules_url ); ?>"><?php esc_html_e( 'Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $logs_url ); ?>"><?php esc_html_e( 'AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $categories_url ); ?>"><?php esc_html_e( 'Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
				<a class="button" href="<?php echo esc_url( $brands_url ); ?>"><?php esc_html_e( 'Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></a>
			</p>
			<?php settings_errors( $option_key ); ?>

			<div style="margin:16px 0; padding:16px; background:#fff; border:1px solid #dcdcde;">
				<strong><?php esc_html_e( 'Huidige promptcontext', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
				<pre style="background:#f6f7f7; padding:12px; overflow:auto; margin-top:8px; white-space:pre-wrap;"><?php echo esc_html( $prompt_preview ); ?></pre>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( $option_key ); ?>
				<h2><?php esc_html_e( 'AI-aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<?php
				$renderer->open_table();
				$provider_options = [];
				foreach ( $providers as $provider ) {
					$provider_options[ $provider->get_key() ] = $provider->get_label();
				}

				$renderer->field(
					[
						'label'       => __( 'Aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'         => 'provider',
						'type'        => 'select',
						'options'     => $provider_options,
						'attributes'  => [
							'id' => 'groq-ai-provider',
						],
						'description' => __( 'Selecteer welke aanbieder de product- en termteksten schrijft.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					]
				);

				$renderer->field(
					[
						'label'    => __( 'Model', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'      => 'model',
						'renderer' => [ $this, 'render_model_select_field' ],
						'attributes' => [
							'id' => 'groq-ai-model-select',
						],
					]
				);

				foreach ( $providers as $provider ) {
					$provider_key   = $provider->get_key();
					$option_field   = $provider->get_option_key();
					$renderer->field(
						[
							'label'          => __( 'API-sleutel', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
							'key'            => $option_field,
							'type'           => 'password',
							'attributes'     => [
								'id'           => 'groq-ai-api-' . $provider_key,
								'class'        => 'regular-text',
								'autocomplete' => 'off',
							],
							'row_attributes' => [
								'id'                => 'groq_ai_api_key_' . $provider_key,
								'data-provider-row' => $provider_key,
							],
							'description'    => sprintf( esc_html__( 'Voer de API-sleutel in voor %s.', GROQ_AI_PRODUCT_TEXT_DOMAIN ), esc_html( $provider->get_label() ) ),
							'renderer'       => [ $this, 'render_provider_api_key_field' ],
							'provider_key'   => $provider_key,
							'google_safety_categories' => $google_safety_categories,
							'google_safety_thresholds' => $google_safety_thresholds,
							'google_safety_settings'   => $google_safety_settings,
						]
					);
				}

				$renderer->close_table();
				?>
				<h2><?php esc_html_e( 'Algemene instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<?php
				$renderer->open_table();
				$renderer->field(
					[
						'label'       => __( 'Maximale output tokens', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'         => 'max_output_tokens',
						'type'        => 'number',
						'attributes'  => [
							'min' => 128,
							'max' => 8192,
						],
						'description' => __( 'Limitering van het aantal tokens per output voor compatibiliteit met verschillende modellen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					]
				);
				$renderer->field(
					[
						'label'       => __( 'Logboek retentie (dagen)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'         => 'logs_retention_days',
						'type'        => 'number',
						'attributes'  => [
							'min' => 0,
							'max' => 3650,
						],
						'description' => __( 'Hoe lang logboekregels bewaard blijven. Zet op 0 om logs onbeperkt te bewaren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					]
				);
				$renderer->field(
					[
						'label'       => __( 'Term meta key (onderste tekst)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'         => 'term_bottom_description_meta_key',
						'placeholder' => 'groq_ai_term_bottom_description',
						'description' => __( 'Optioneel: overschrijf in welke term meta key de onderste omschrijving moet landen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					]
				);
				$renderer->field(
					[
						'label'    => __( 'Response format fallback', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'renderer' => function ( $field, $renderer ) {
							$this->render_response_format_compat_field();
						},
					]
				);
				$renderer->close_table();
				?>

				<p class="submit"><?php submit_button( __( 'Instellingen opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'primary', 'submit', false ); ?></p>
			</form>
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

	public function render_modules_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$option_key  = $this->plugin->get_option_key();
		$settings    = $this->plugin->get_settings();
		$current_page = $this->get_page_url( 'groq-ai-product-text-modules' );
		$oauth_redirect = add_query_arg( 'action', 'groq_ai_google_oauth_callback', admin_url( 'admin-post.php' ) );
		$google_notice  = isset( $_GET['groq_ai_google_notice'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_google_notice'] ) ) : '';
		$google_status  = isset( $_GET['groq_ai_google_notice_status'] ) ? sanitize_key( wp_unslash( $_GET['groq_ai_google_notice_status'] ) ) : '';
		$google_message = '';
		if ( isset( $_GET['groq_ai_google_notice_message'] ) ) {
			$google_message = sanitize_text_field( rawurldecode( wp_unslash( $_GET['groq_ai_google_notice_message'] ) ) );
		}
		$google_connected       = ! empty( $settings['google_oauth_refresh_token'] );
		$google_connected_email = isset( $settings['google_oauth_connected_email'] ) ? (string) $settings['google_oauth_connected_email'] : '';
		$google_connected_at    = isset( $settings['google_oauth_connected_at'] ) ? absint( $settings['google_oauth_connected_at'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p class="description"><?php esc_html_e( 'Schakel aanvullende integraties in en bepaal grenzen voor gegenereerde content.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<?php settings_errors( $option_key ); ?>
			<?php if ( $google_notice ) :
				$class = ( 'error' === $google_status ) ? 'notice-error' : 'notice-success';
				$google_message = '' !== $google_message ? $google_message : ( 'connected' === $google_notice ? __( 'Google OAuth is verbonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : ( 'disconnected' === $google_notice ? __( 'Google OAuth is ontkoppeld.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) : __( 'Google test afgerond.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ) );
				?>
				<div class="notice <?php echo esc_attr( $class ); ?>"><p><?php echo esc_html( $google_message ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( $option_key ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Rank Math integratie', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<td><?php $this->render_rankmath_module_field(); ?></td>
					</tr>
				</table>
				<h2><?php esc_html_e( 'Google Search Console & Analytics', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<?php
				$renderer = $this->plugin->create_settings_renderer( $settings );
				$renderer->open_table();
				$renderer->field(
					[
						'label'       => __( 'Google OAuth client ID', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'         => 'google_oauth_client_id',
						'attributes'  => [ 'autocomplete' => 'off' ],
						'description' => __( 'Stel deze plugin in als OAuth-client in Google Cloud Console en gebruik onderstaande redirect-URL.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					]
				);
				$renderer->field(
					[
						'label'      => __( 'Google OAuth client secret', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'key'        => 'google_oauth_client_secret',
						'type'       => 'password',
						'attributes' => [ 'autocomplete' => 'off' ],
						'description' => sprintf(
							'%s<br /><code>%s</code>',
							esc_html__( 'Redirect URI voor OAuth (voeg exact zo toe in Google Cloud → Credentials):', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
							esc_html( $oauth_redirect )
						),
					]
				);
				$renderer->field(
					[
						'label'          => __( 'Search Console koppeling', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'type'           => 'checkbox',
						'key'            => 'google_enable_gsc',
						'checkbox_label' => __( 'Search Console data gebruiken in term prompts', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'renderer'       => function ( $field, $renderer ) use ( $option_key, $settings ) {
							$value = ! empty( $settings['google_gsc_site_url'] ) ? $settings['google_gsc_site_url'] : '';
							printf(
								'<p><input type="url" class="regular-text" name="%1$s[google_gsc_site_url]" value="%2$s" placeholder="sc-domain:voorbeeld.nl" /></p>',
								esc_attr( $option_key ),
								esc_attr( $value )
							);
						},
					]
				);
				$renderer->field(
					[
						'label'          => __( 'Analytics koppeling', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'type'           => 'checkbox',
						'key'            => 'google_enable_ga',
						'checkbox_label' => __( 'GA4 data meesturen (landing page statistieken)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
						'renderer'       => function ( $field, $renderer ) use ( $option_key, $settings ) {
							$value = ! empty( $settings['google_ga4_property_id'] ) ? $settings['google_ga4_property_id'] : '';
							printf(
								'<p><input type="text" class="regular-text" name="%1$s[google_ga4_property_id]" value="%2$s" placeholder="properties/123456789" /></p>',
								esc_attr( $option_key ),
								esc_attr( $value )
							);
						},
					]
				);
				$renderer->close_table();
				?>
				<?php submit_button( __( 'Modules opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ); ?>
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

	private function render_model_select_field( $field_args ) {
		$name    = isset( $field_args['name'] ) ? $field_args['name'] : '';
		$id      = isset( $field_args['id'] ) && '' !== $field_args['id'] ? $field_args['id'] : 'groq-ai-model-select';
		$current = isset( $field_args['value'] ) ? (string) $field_args['value'] : '';
		$placeholder = __( 'Selecteer eerst een aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		?>
		<div class="groq-ai-model-field">
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" data-current-model="<?php echo esc_attr( $current ); ?>">
				<option value="" selected="selected"><?php echo esc_html( $placeholder ); ?></option>
			</select>
		</div>
		<button type="button" class="button" id="groq-ai-refresh-models"><?php esc_html_e( 'Live modellen ophalen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></button>
		<p id="groq-ai-refresh-models-status" class="description"></p>
		<?php
	}

	private function render_provider_api_key_field( $field_args ) {
		$attributes = isset( $field_args['attributes'] ) && is_array( $field_args['attributes'] ) ? $field_args['attributes'] : [];
		$attributes['name'] = isset( $field_args['name'] ) ? $field_args['name'] : '';

		if ( ! isset( $attributes['id'] ) && ! empty( $field_args['id'] ) ) {
			$attributes['id'] = $field_args['id'];
		}

		if ( ! isset( $attributes['class'] ) ) {
			$attributes['class'] = 'regular-text';
		}

		if ( ! isset( $attributes['type'] ) ) {
			$attributes['type'] = 'password';
		}

		if ( ! isset( $attributes['autocomplete'] ) ) {
			$attributes['autocomplete'] = 'off';
		}

		$attributes['value'] = isset( $field_args['value'] ) ? $field_args['value'] : '';

		printf( '<input %s />', $this->format_html_attributes( $attributes ) );

		if ( isset( $field_args['provider_key'] ) && 'google' === $field_args['provider_key'] ) {
			$this->render_google_safety_fields( $field_args );
		}
	}

	private function render_google_safety_fields( $field_args ) {
		$categories = isset( $field_args['google_safety_categories'] ) && is_array( $field_args['google_safety_categories'] )
			? $field_args['google_safety_categories']
			: [];
		$thresholds = isset( $field_args['google_safety_thresholds'] ) && is_array( $field_args['google_safety_thresholds'] )
			? $field_args['google_safety_thresholds']
			: [];

		if ( empty( $categories ) || empty( $thresholds ) ) {
			return;
		}

		$selected_settings = isset( $field_args['google_safety_settings'] ) && is_array( $field_args['google_safety_settings'] )
			? $field_args['google_safety_settings']
			: [];
		$option_key = $this->plugin->get_option_key();
		?>
		<div class="groq-ai-google-safety-settings" style="margin-top:16px; padding:16px; border:1px solid #dcdcde; background:#f6f7f7;">
			<strong><?php esc_html_e( 'Gemini safety filters', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
			<p class="description" style="margin-top:4px;"><?php esc_html_e( 'Kies optioneel welke beleidscategorieën je zelf instelt. Laat op "Google standaard" om geen safetySettings mee te sturen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<?php foreach ( $categories as $category_key => $info ) :
				$category_label       = isset( $info['label'] ) ? $info['label'] : $category_key;
				$category_description = isset( $info['description'] ) ? $info['description'] : '';
				$selected_threshold   = isset( $selected_settings[ $category_key ] ) ? $selected_settings[ $category_key ] : '';
				$field_id             = 'groq-ai-google-safety-' . sanitize_html_class( $category_key );
				?>
				<label for="<?php echo esc_attr( $field_id ); ?>" style="display:block; margin:12px 0 4px;">
					<span style="display:block; margin-bottom:4px;"><strong><?php echo esc_html( $category_label ); ?></strong></span>
					<select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $option_key ); ?>[google_safety_settings][<?php echo esc_attr( $category_key ); ?>]" style="max-width:280px;">
						<?php foreach ( $thresholds as $threshold_key => $threshold_label ) : ?>
							<option value="<?php echo esc_attr( $threshold_key ); ?>" <?php selected( $selected_threshold, $threshold_key ); ?>><?php echo esc_html( $threshold_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( '' !== $category_description ) : ?>
						<p class="description" style="margin:4px 0 0;"><?php echo esc_html( $category_description ); ?></p>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function format_html_attributes( $attributes ) {
		$pairs = [];
		foreach ( $attributes as $key => $value ) {
			if ( '' === $value && 0 !== $value && '0' !== $value ) {
				continue;
			}

			$pairs[] = sprintf( '%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return implode( ' ', $pairs );
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

		$this->enqueue_admin_styles();

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$is_main_settings_screen = ( 0 === strpos( (string) $hook, 'settings_page_groq-ai-product-text' ) ) && ( 'groq-ai-product-text' === $current_page );

		if ( ! $is_main_settings_screen ) {
			return;
		}

		wp_enqueue_script(
			'groq-ai-settings',
			plugins_url( 'assets/js/settings.js', GROQ_AI_PRODUCT_TEXT_FILE ),
			[],
			GROQ_AI_PRODUCT_TEXT_VERSION,
			true
		);

		$current_settings = $this->plugin->get_settings();
		$data             = [
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
			'strings'         => [
				'providerUnsupported' => __( 'Deze aanbieder ondersteunt dit niet.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'apiKeyRequired'       => __( 'Vul eerst de API-sleutel in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'loadingModels'        => __( 'Modellen worden opgehaald…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'errorUnknown'         => __( 'Onbekende fout', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'successModels'        => __( 'Modellen bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'errorFetch'           => __( 'Ophalen mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
		];

		foreach ( $this->provider_manager->get_providers() as $provider ) {
			$provider_key  = $provider->get_key();
			$cached_models = $this->plugin->get_cached_models_for_provider( $provider_key );
			$cached_models = Groq_AI_Model_Exclusions::filter_models( $provider_key, $cached_models );
			$data['providers'][ $provider_key ] = [
				'default_label' => sprintf( __( 'Gebruik standaardmodel (%s)', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $provider->get_default_model() ),
				'models'        => $cached_models,
				'supports_live' => $provider->supports_live_models(),
			];
			$data['providerRows'][ $provider_key ] = 'groq_ai_api_key_' . $provider_key;
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

}
