<?php

class Groq_AI_Product_Text_Product_UI {
	private $plugin;

	public function __construct( $plugin ) {
		$this->plugin = $plugin;

		add_action( 'add_meta_boxes', [ $this, 'register_meta_box' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_footer', [ $this, 'render_modal_markup' ] );
	}

	public function register_meta_box() {
		add_meta_box(
			'groq-ai-generator-box',
			__( 'Gebruik AI', 'groq-ai-product-text' ),
			[ $this, 'render_meta_box' ],
			'product',
			'side',
			'high'
		);
	}

	public function render_meta_box() {
		if ( ! current_user_can( 'edit_products' ) ) {
			echo '<p>' . esc_html__( 'Je hebt geen toestemming om deze actie uit te voeren.', 'groq-ai-product-text' ) . '</p>';
			return;
		}
		?>
		<p><?php esc_html_e( 'Laat de geselecteerde AI een concepttekst genereren op basis van een prompt.', 'groq-ai-product-text' ); ?></p>
		<button type="button" class="button button-primary groq-ai-open-modal" data-target="groq-ai-modal"><?php esc_html_e( 'Gebruik AI', 'groq-ai-product-text' ); ?></button>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Klik om een prompt in te voeren en een voorsteltekst te genereren. Plak het resultaat in de beschrijving of korte beschrijving.', 'groq-ai-product-text' ); ?>
		</p>
		<?php
	}

	public function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();

		if ( $screen && 'product' === $screen->post_type && in_array( $screen->base, [ 'post', 'post-new' ], true ) ) {
			wp_enqueue_style(
				'groq-ai-admin',
				plugins_url( 'assets/css/admin.css', GROQ_AI_PRODUCT_TEXT_FILE ),
				[],
				GROQ_AI_PRODUCT_TEXT_VERSION
			);

			wp_enqueue_script(
				'groq-ai-admin',
				plugins_url( 'assets/js/admin.js', GROQ_AI_PRODUCT_TEXT_FILE ),
				[ 'jquery' ],
				GROQ_AI_PRODUCT_TEXT_VERSION,
				true
			);

			global $post;
			$post_id = ( $post && isset( $post->ID ) ) ? (int) $post->ID : 0;

			$settings = $this->plugin->get_settings();

			wp_localize_script(
				'groq-ai-admin',
				'GroqAIGenerator',
				[
					'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
					'nonce'         => wp_create_nonce( 'groq_ai_generate' ),
					'defaultPrompt' => $settings['default_prompt'],
					'postId'        => $post_id,
					'contextDefaults' => isset( $settings['context_fields'] ) ? $settings['context_fields'] : $this->plugin->get_default_context_fields(),
				]
			);
		}
	}

	public function render_modal_markup() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$settings          = $this->plugin->get_settings();
		$rankmath_enabled  = $this->plugin->is_rankmath_active() && $this->plugin->is_module_enabled( 'rankmath', $settings );
		?>
		<div id="groq-ai-modal" class="groq-ai-modal" aria-hidden="true">
			<div class="groq-ai-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="groq-ai-modal-title">
				<button type="button" class="groq-ai-modal__close" aria-label="<?php esc_attr_e( 'Sluiten', 'groq-ai-product-text' ); ?>">&times;</button>
				<div class="groq-ai-modal__dialog-inner">
					<h2 id="groq-ai-modal-title"><?php esc_html_e( 'Siti AI prompt', 'groq-ai-product-text' ); ?></h2>
					<form id="groq-ai-form">
						<label for="groq-ai-prompt" class="screen-reader-text"><?php esc_html_e( 'Prompt', 'groq-ai-product-text' ); ?></label>
						<textarea id="groq-ai-prompt" rows="6" placeholder="<?php esc_attr_e( 'Beschrijf hier wat de AI moet schrijven...', 'groq-ai-product-text' ); ?>"></textarea>
					<div class="groq-ai-modal__actions">
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Genereer tekst', 'groq-ai-product-text' ); ?>
						</button>
					</div>
					<div class="groq-ai-advanced-settings">
						<button type="button" class="groq-ai-advanced-toggle" aria-expanded="false" aria-controls="groq-ai-advanced-panel">
							<span class="groq-ai-advanced-toggle__icon" aria-hidden="true"></span>
							<?php esc_html_e( 'Geavanceerde instellingen', 'groq-ai-product-text' ); ?>
						</button>
						<div id="groq-ai-advanced-panel" class="groq-ai-context-options" hidden>
							<h3><?php esc_html_e( 'Gebruik deze productinformatie in de prompt', 'groq-ai-product-text' ); ?></h3>
							<p class="description"><?php esc_html_e( 'Je kunt tijdelijk onderdelen uitzetten of weer inschakelen. Standaard zijn de opties aangevinkt zoals ingesteld op de instellingenpagina.', 'groq-ai-product-text' ); ?></p>
							<div class="groq-ai-context-options__grid">
								<?php
								$context_definitions = $this->plugin->get_context_field_definitions();
								$context_defaults    = isset( $settings['context_fields'] ) ? $settings['context_fields'] : $this->plugin->get_default_context_fields();
								foreach ( $context_definitions as $context_key => $context_info ) :
									$checked = ! empty( $context_defaults[ $context_key ] );
									?>
									<label class="groq-ai-context-option">
										<input type="checkbox" class="groq-ai-context-toggle" data-field="<?php echo esc_attr( $context_key ); ?>" <?php checked( $checked ); ?> />
										<div>
											<strong><?php echo esc_html( $context_info['label'] ); ?></strong>
											<?php if ( ! empty( $context_info['description'] ) ) : ?>
												<p class="description"><?php echo esc_html( $context_info['description'] ); ?></p>
											<?php endif; ?>
										</div>
									</label>
								<?php endforeach; ?>
							</div>
						</div>
					</div>
					</form>
					<div class="groq-ai-modal__result" hidden>
						<h3><?php esc_html_e( 'Resultaat', 'groq-ai-product-text' ); ?></h3>
					<div class="groq-ai-result-grid">
						<div class="groq-ai-result-field" data-field="title" data-target-input="#title" data-label="<?php esc_attr_e( 'Producttitel', 'groq-ai-product-text' ); ?>">
							<div class="groq-ai-result-field__header">
								<strong><?php esc_html_e( 'Producttitel', 'groq-ai-product-text' ); ?></strong>
								<div class="groq-ai-result-field__actions">
									<button type="button" class="button button-secondary groq-ai-copy-field" data-field="title"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
									<button type="button" class="button groq-ai-apply-field" data-field="title"><?php esc_html_e( 'Vul titel in', 'groq-ai-product-text' ); ?></button>
									<span class="groq-ai-apply-status" aria-hidden="true"></span>
								</div>
							</div>
							<textarea rows="2"></textarea>
						</div>
						<div class="groq-ai-result-field" data-field="short_description" data-target-input="#excerpt" data-label="<?php esc_attr_e( 'Korte beschrijving', 'groq-ai-product-text' ); ?>">
							<div class="groq-ai-result-field__header">
								<strong><?php esc_html_e( 'Korte beschrijving', 'groq-ai-product-text' ); ?></strong>
								<div class="groq-ai-result-field__actions">
									<button type="button" class="button button-secondary groq-ai-copy-field" data-field="short_description"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
									<button type="button" class="button groq-ai-apply-field" data-field="short_description"><?php esc_html_e( 'Vul korte beschrijving in', 'groq-ai-product-text' ); ?></button>
									<span class="groq-ai-apply-status" aria-hidden="true"></span>
								</div>
							</div>
							<textarea rows="3"></textarea>
						</div>
						<div class="groq-ai-result-field" data-field="description" data-target-input="#content" data-label="<?php esc_attr_e( 'Beschrijving', 'groq-ai-product-text' ); ?>">
							<div class="groq-ai-result-field__header">
								<strong><?php esc_html_e( 'Beschrijving', 'groq-ai-product-text' ); ?></strong>
								<div class="groq-ai-result-field__actions">
									<button type="button" class="button button-secondary groq-ai-copy-field" data-field="description"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
									<button type="button" class="button groq-ai-apply-field" data-field="description"><?php esc_html_e( 'Vul beschrijving in', 'groq-ai-product-text' ); ?></button>
									<span class="groq-ai-apply-status" aria-hidden="true"></span>
								</div>
							</div>
							<textarea rows="6"></textarea>
						</div>
						<?php if ( $rankmath_enabled ) : ?>
							<div class="groq-ai-result-field" data-field="meta_title" data-target-input="#rank_math_title" data-rankmath-action="updateTitle" data-label="<?php esc_attr_e( 'Rank Math meta titel', 'groq-ai-product-text' ); ?>">
								<div class="groq-ai-result-field__header">
									<strong><?php esc_html_e( 'Rank Math meta titel', 'groq-ai-product-text' ); ?></strong>
									<div class="groq-ai-result-field__actions">
										<button type="button" class="button button-secondary groq-ai-copy-field" data-field="meta_title"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
										<button type="button" class="button groq-ai-apply-field" data-field="meta_title"><?php esc_html_e( 'Vul meta titel in', 'groq-ai-product-text' ); ?></button>
										<span class="groq-ai-apply-status" aria-hidden="true"></span>
									</div>
								</div>
								<textarea rows="2"></textarea>
							</div>
							<div class="groq-ai-result-field" data-field="meta_description" data-target-input="#rank_math_description" data-rankmath-action="updateDescription" data-label="<?php esc_attr_e( 'Rank Math meta description', 'groq-ai-product-text' ); ?>">
								<div class="groq-ai-result-field__header">
									<strong><?php esc_html_e( 'Rank Math meta description', 'groq-ai-product-text' ); ?></strong>
									<div class="groq-ai-result-field__actions">
										<button type="button" class="button button-secondary groq-ai-copy-field" data-field="meta_description"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
										<button type="button" class="button groq-ai-apply-field" data-field="meta_description"><?php esc_html_e( 'Vul meta description in', 'groq-ai-product-text' ); ?></button>
										<span class="groq-ai-apply-status" aria-hidden="true"></span>
									</div>
								</div>
								<textarea rows="3"></textarea>
							</div>
							<div class="groq-ai-result-field" data-field="focus_keywords" data-target-input="#rank_math_focus_keyword" data-rankmath-action="updateKeywords" data-label="<?php esc_attr_e( 'Rank Math focus keyphrase', 'groq-ai-product-text' ); ?>">
								<div class="groq-ai-result-field__header">
									<strong><?php esc_html_e( 'Rank Math focus keyphrase', 'groq-ai-product-text' ); ?></strong>
									<div class="groq-ai-result-field__actions">
										<button type="button" class="button button-secondary groq-ai-copy-field" data-field="focus_keywords"><?php esc_html_e( 'Kopieer', 'groq-ai-product-text' ); ?></button>
										<button type="button" class="button groq-ai-apply-field" data-field="focus_keywords"><?php esc_html_e( 'Vul focus keyphrase in', 'groq-ai-product-text' ); ?></button>
										<span class="groq-ai-apply-status" aria-hidden="true"></span>
									</div>
								</div>
								<textarea rows="2" placeholder="<?php esc_attr_e( 'bijv. luxe massage apparaat, wellness cadeau', 'groq-ai-product-text' ); ?>"></textarea>
							</div>
						<?php endif; ?>
					</div>
					<div class="groq-ai-modal__raw">
						<h4><?php esc_html_e( 'Ruwe JSON-output', 'groq-ai-product-text' ); ?></h4>
						<pre id="groq-ai-output"></pre>
						<button type="button" class="button groq-ai-copy-json"><?php esc_html_e( 'Kopieer JSON', 'groq-ai-product-text' ); ?></button>
					</div>
				</div>
					<div class="groq-ai-modal__status" aria-live="polite"></div>
				</div>
			</div>
		</div>
		<?php
	}
}
