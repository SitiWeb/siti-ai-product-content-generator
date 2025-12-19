<?php

class Groq_AI_Product_Text_Settings_Page {
	private $plugin;
	private $provider_manager;

	public function __construct( $plugin, Groq_AI_Provider_Manager $provider_manager ) {
		$this->plugin            = $plugin;
		$this->provider_manager  = $provider_manager;

		add_action( 'admin_menu', [ $this, 'register_settings_pages' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_settings_assets' ] );
		add_action( 'admin_head', [ $this, 'hide_menu_links' ] );
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

	}

	public function hide_menu_links() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<style>
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-modules"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-logs"],
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-prompts"] {
				display: none !important;
			}
		</style>
		<?php
	}

	public function register_settings() {
		register_setting( 'groq_ai_product_text_group', $this->plugin->get_option_key(), [ $this->plugin, 'sanitize_settings' ] );

		add_settings_section(
			'groq_ai_product_text_general',
			__( 'Algemene instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'__return_false',
			'groq-ai-product-text'
		);

		add_settings_field(
			'groq_ai_provider',
			__( 'AI-aanbieder', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_provider_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_general'
		);

		add_settings_field(
			'groq_ai_model',
			__( 'Model', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_model_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_general'
		);

		foreach ( $this->provider_manager->get_providers() as $provider ) {
		add_settings_field(
			'groq_ai_api_key_' . $provider->get_key(),
			sprintf( __( '%s API-sleutel', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $provider->get_label() ),
			[ $this, 'render_provider_api_key_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_general',
			[
				'provider' => $provider,
				]
			);
		}

		add_settings_section(
			'groq_ai_product_text_prompts',
			__( 'Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'__return_false',
			'groq-ai-product-text-prompts'
		);

		add_settings_field(
			'groq_ai_store_context',
			__( 'Winkelcontext', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_store_context_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_default_prompt',
			__( 'Standaard prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_default_prompt_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_context_fields',
			__( 'Standaard productcontext', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_context_fields_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_response_format_compat',
			__( 'Response-format compatibiliteit', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_response_format_compat_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_image_context_mode',
			__( 'Afbeeldingen toevoegen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_image_context_mode_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_image_context_limit',
			__( 'Maximaal aantal afbeeldingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_image_context_limit_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_section(
			'groq_ai_product_text_modules_rankmath',
			__( 'Rank Math SEO', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'__return_false',
			'groq-ai-product-text-modules'
		);

		add_settings_field(
			'groq_ai_module_rankmath',
			__( 'Rank Math SEO', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_rankmath_module_field' ],
			'groq-ai-product-text-modules',
			'groq_ai_product_text_modules_rankmath'
		);
	}

	public function render_image_context_mode_field() {
		$settings = $this->plugin->get_settings();
		$mode     = isset( $settings['image_context_mode'] ) ? $settings['image_context_mode'] : 'url';
		$options  = [
			'none'   => __( 'Nee, geen afbeeldingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'url'    => __( 'Ja, voeg afbeeldings-URL’s toe aan de prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'base64' => __( 'Ja, verstuur afbeeldingen als Base64 (indien ondersteund)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
		];
		?>
		<select name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[image_context_mode]">
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Bepaal hoe productafbeeldingen worden meegestuurd: helemaal niet, als URL’s in de prompt of als Base64-bijlagen voor modellen die beeldcontext ondersteunen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_image_context_limit_field() {
		$settings = $this->plugin->get_settings();
		$limit    = $this->plugin->get_image_context_limit( $settings );
		?>
		<input type="number"
			name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[image_context_limit]"
			min="1"
			max="10"
			step="1"
			value="<?php echo esc_attr( $limit ); ?>"
			class="small-text" />
		<p class="description">
			<?php esc_html_e( 'Stel hier het maximum aantal productafbeeldingen in dat wordt meegestuurd (we beginnen bij de uitgelichte afbeelding, gevolgd door de galerij).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI Productteksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p style="margin-bottom:16px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=groq-ai-product-text-prompts' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=groq-ai-product-text-modules' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Ga naar modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=groq-ai-product-text-logs' ) ); ?>" class="button">
					<?php esc_html_e( 'Bekijk AI-logboek', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
			</p>
			<p><?php esc_html_e( 'Kies je AI-aanbieder, stel de juiste API-sleutel en het gewenste model in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'groq_ai_product_text_group' );
				do_settings_sections( 'groq-ai-product-text' );
				submit_button();
			?>
			</form>
		</div>
		<?php
	}

	public function render_modules_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Siti AI Modules', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p><?php esc_html_e( 'Beheer aparte integraties zoals Rank Math. Het uitschakelen van een module verwijdert de bijbehorende AI-uitvoer automatisch uit de productmodal.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'groq_ai_product_text_group' );
				do_settings_sections( 'groq-ai-product-text-modules' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_prompt_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = $this->plugin->get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prompt instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p style="margin-bottom:16px;">
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=groq-ai-product-text' ) ); ?>" class="button button-secondary">
					<?php esc_html_e( 'Terug naar algemene instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
			</p>
			<p><?php esc_html_e( 'Beheer hier de winkelcontext, standaardprompt, productcontext en response-format instellingen. Deze keuzes bepalen hoe elke prompt richting de AI wordt opgebouwd.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'groq_ai_product_text_group' );
				do_settings_sections( 'groq-ai-product-text-prompts' );
				submit_button();
				?>
			</form>
			<div class="groq-ai-prompt-helper">
				<h2><?php esc_html_e( 'Prompt generator', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<p><?php esc_html_e( 'Gebruik deze velden om belangrijke informatie voor de AI bij te houden (bijvoorbeeld tone of voice, USP’s of doelgroepen). Voeg ze toe aan je prompt met kopiëren en plakken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
				<textarea class="large-text" rows="6" readonly><?php echo esc_textarea( $this->plugin->build_prompt_template_preview( $settings ) ); ?></textarea>
			</div>
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
			<p><?php esc_html_e( 'Bekijk recente AI-generaties inclusief status, gebruiker en tokens.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="groq-ai-product-text-logs" />
				<?php $logs_table->search_box( __( 'Zoek logs', GROQ_AI_PRODUCT_TEXT_DOMAIN ), 'groq-ai-logs' ); ?>
				<?php $logs_table->display(); ?>
			</form>
		</div>
		<div id="groq-ai-log-modal" class="groq-ai-log-modal" aria-hidden="true">
			<div class="groq-ai-log-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="groq-ai-log-modal-title">
				<button type="button" class="groq-ai-log-modal__close" aria-label="<?php esc_attr_e( 'Sluiten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>">&times;</button>
				<div class="groq-ai-log-modal__content">
					<h2 id="groq-ai-log-modal-title"><?php esc_html_e( 'Logdetails', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
					<p class="description groq-ai-log-meta"></p>
					<div class="groq-ai-log-fields">
						<label>
							<span><?php esc_html_e( 'Prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></span>
							<textarea id="groq-ai-log-prompt" readonly rows="6"></textarea>
						</label>
						<label>
							<span><?php esc_html_e( 'Response', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></span>
							<textarea id="groq-ai-log-response" readonly rows="6"></textarea>
						</label>
						<div class="groq-ai-log-tokens">
							<div>
								<strong><?php esc_html_e( 'Tokens prompt', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-tokens-prompt">—</span>
							</div>
							<div>
								<strong><?php esc_html_e( 'Tokens response', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-tokens-completion">—</span>
							</div>
							<div>
								<strong><?php esc_html_e( 'Tokens totaal', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-tokens-total">—</span>
							</div>
						</div>
						<div class="groq-ai-log-images">
							<div>
								<strong><?php esc_html_e( 'Afbeeldingsmodus', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-images-mode">—</span>
							</div>
							<div>
								<strong><?php esc_html_e( 'Beschikbare afbeeldingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-images-available">—</span>
							</div>
							<div>
								<strong><?php esc_html_e( 'Base64 meegestuurd', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
								<span id="groq-ai-log-images-base64">—</span>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
		<style>
			.groq-ai-log-modal{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);display:none;align-items:center;justify-content:center;z-index:100000;}
			.groq-ai-log-modal.is-open{display:flex;}
			.groq-ai-log-modal__dialog{background:#fff;max-width:900px;width:90%;padding:20px;box-shadow:0 10px 40px rgba(0,0,0,0.3);position:relative;}
			.groq-ai-log-modal__close{position:absolute;top:10px;right:10px;border:none;background:transparent;font-size:24px;cursor:pointer;}
			.groq-ai-log-fields label{display:block;margin-bottom:15px;}
			.groq-ai-log-fields textarea{width:100%;}
			.groq-ai-log-tokens{display:flex;gap:20px;margin-top:10px;}
			.groq-ai-log-images{display:flex;gap:20px;margin-top:10px;}
			.groq-ai-log-row{display:inline-block;}
		</style>
		<script>
			(function(){
				const modal=document.getElementById('groq-ai-log-modal');
				if(!modal){return;}
				const closeBtn=modal.querySelector('.groq-ai-log-modal__close');
				const promptField=document.getElementById('groq-ai-log-prompt');
				const responseField=document.getElementById('groq-ai-log-response');
				const tokensPrompt=document.getElementById('groq-ai-log-tokens-prompt');
				const tokensCompletion=document.getElementById('groq-ai-log-tokens-completion');
				const tokensTotal=document.getElementById('groq-ai-log-tokens-total');
				const imagesMode=document.getElementById('groq-ai-log-images-mode');
				const imagesAvailable=document.getElementById('groq-ai-log-images-available');
				const imagesBase64=document.getElementById('groq-ai-log-images-base64');
				const meta=document.querySelector('.groq-ai-log-meta');
				function openModal(data){
					if(!data){return;}
					if(promptField){promptField.value=data.prompt||'';}
					if(responseField){responseField.value=data.response||'';}
					if(tokensPrompt){tokensPrompt.textContent=Number.isFinite(data.tokens_prompt)?data.tokens_prompt:'—';}
					if(tokensCompletion){tokensCompletion.textContent=Number.isFinite(data.tokens_completion)?data.tokens_completion:'—';}
					if(tokensTotal){tokensTotal.textContent=Number.isFinite(data.tokens_total)?data.tokens_total:'—';}
					const imageContext=data.image_context||null;
					if(imagesMode){
						let mode='—';
						if(imageContext){
							mode=imageContext.effective_mode||imageContext.requested_mode||'—';
						}
						imagesMode.textContent=mode||'—';
					}
					if(imagesAvailable){
						const available=imageContext&&Number.isFinite(imageContext.available)?imageContext.available:'—';
						imagesAvailable.textContent=available;
					}
					if(imagesBase64){
						const base64=imageContext&&Number.isFinite(imageContext.base64_sent)?imageContext.base64_sent:'—';
						imagesBase64.textContent=base64;
					}
					if(meta){
						meta.textContent=(data.provider||'')+' • '+(data.model||'')+' • '+(data.post_title||'')+' • '+(data.status||'');
					}
					modal.classList.add('is-open');
					modal.setAttribute('aria-hidden','false');
				}
				function closeModal(){
					modal.classList.remove('is-open');
					modal.setAttribute('aria-hidden','true');
				}
				document.addEventListener('click',function(e){
					const link=e.target.closest('.groq-ai-log-row');
					if(link){
						e.preventDefault();
						let payload=link.getAttribute('data-groq-log');
						if(payload){
							try{
								const data=JSON.parse(payload);
								openModal(data);
							}catch(err){
								console.error('Invalid log payload',err);
							}
						}
					}
					if(e.target===modal){
						closeModal();
					}
				});
				if(closeBtn){
					closeBtn.addEventListener('click',closeModal);
				}
				document.addEventListener('keyup',function(e){
					if(e.key==='Escape' && modal.classList.contains('is-open')){
						closeModal();
					}
				});
			})();
		</script>
		<?php
	}

	public function render_provider_field() {
		$settings  = $this->plugin->get_settings();
		$providers = $this->provider_manager->get_providers();
		?>
		<select name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[provider]">
			<?php foreach ( $providers as $provider ) : ?>
				<option value="<?php echo esc_attr( $provider->get_key() ); ?>" <?php selected( $settings['provider'], $provider->get_key() ); ?>>
					<?php echo esc_html( $provider->get_label() ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Bepaal welke AI-dienst wordt aangesproken wanneer je teksten genereert.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
		<?php
	}

	public function render_model_field() {
		$settings       = $this->plugin->get_settings();
		$current_model  = $settings['model'];
		$current_provider = $settings['provider'];
		?>
		<div class="groq-ai-model-field">
			<select
				id="groq-ai-model-select"
				class="groq-ai-model-select"
				name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[model]"
				data-current-model="<?php echo esc_attr( $current_model ); ?>"
			>
				<option value=""><?php esc_html_e( 'Selecteer een model via "Live modellen ophalen"', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></option>
			</select>
			<p class="description"><?php esc_html_e( 'Gebruik de knop hieronder om rechtstreeks via het API-endpoint beschikbare modellen op te halen. Zonder een live lijst blijft de selectie leeg.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
			<button type="button" class="button" id="groq-ai-refresh-models" style="margin-top:10px;">
				<?php esc_html_e( 'Live modellen ophalen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</button>
			<p id="groq-ai-refresh-models-status" class="description" aria-live="polite"></p>
		</div>
		<?php
	}

	public function render_provider_api_key_field( $args ) {
		$settings = $this->plugin->get_settings();
		/** @var Groq_AI_Provider_Interface $provider */
		$provider       = $args['provider'];
		$field          = $provider->get_option_key();
		$provider_key   = $provider->get_key();
		?>
		<div class="groq-ai-provider-field" data-provider-row="<?php echo esc_attr( $provider_key ); ?>">
			<input type="password" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( $settings[ $field ] ); ?>" class="regular-text" autocomplete="off" />
			<p class="description">
				<?php
				printf(
					/* translators: %s: provider name */
					esc_html__( 'Voeg hier de API-sleutel voor %s toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					esc_html( $provider->get_label() )
				);
				?>
			</p>
		</div>
		<?php
	}

	public function render_store_context_field() {
		$settings = $this->plugin->get_settings();
		?>
		<textarea name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[store_context]" class="large-text" rows="4"><?php echo esc_textarea( $settings['store_context'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Beschrijf het merk, de tone of voice en andere relevante winkelinformatie.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
		<?php
	}

	public function render_default_prompt_field() {
		$settings = $this->plugin->get_settings();
		?>
		<textarea name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[default_prompt]" class="large-text" rows="4" placeholder="<?php esc_attr_e( 'Bijvoorbeeld: Schrijf een overtuigende productbeschrijving met nadruk op kwaliteit en levertijd.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>"><?php echo esc_textarea( $settings['default_prompt'] ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Deze tekst verschijnt vooraf ingevuld in de AI-popup, maar kan per product worden aangepast.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
		<?php
	}

	public function render_context_fields_field() {
		$settings    = $this->plugin->get_settings();
		$values      = isset( $settings['context_fields'] ) ? $settings['context_fields'] : $this->plugin->get_default_context_fields();
		$definitions = $this->plugin->get_context_field_definitions();
		?>
		<div class="groq-ai-context-defaults">
			<?php foreach ( $definitions as $key => $definition ) :
				$checked = ! empty( $values[ $key ] );
				?>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[context_fields][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $checked ); ?> />
					<strong><?php echo esc_html( $definition['label'] ); ?></strong>
				</label>
				<?php if ( ! empty( $definition['description'] ) ) : ?>
					<p class="description" style="margin-top:-8px;margin-bottom:12px;">
						<?php echo esc_html( $definition['description'] ); ?>
					</p>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
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
				max="99"
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
		if ( ! in_array( $hook, [ 'settings_page_groq-ai-product-text', 'settings_page_groq-ai-product-text-modules', 'settings_page_groq-ai-product-text-prompts' ], true ) ) {
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
}
