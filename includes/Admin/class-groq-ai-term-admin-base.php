<?php

abstract class Groq_AI_Term_Admin_Base extends Groq_AI_Admin_Base {
	protected $term_overview_cache = [];

	private static $term_page_registered         = false;
	private static $term_handler_registered      = false;
	private static $term_assets_hook_registered  = false;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		parent::__construct( $plugin );
		$this->ensure_term_handler_registered();
		$this->ensure_term_assets_hook();
	}

	protected function register_term_page() {
		if ( self::$term_page_registered ) {
			return;
		}

		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Term tekst', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-term',
			[ $this, 'render_term_generator_page' ]
		);

		self::$term_page_registered = true;
	}

	protected function render_term_bulk_panel( $label_plural, $empty_count ) {
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

	protected function localize_term_bulk_script( $taxonomy, $overrides = [] ) {
		$overview = $this->get_term_overview_data( $taxonomy );
		$rows     = isset( $overview['rows'] ) ? $overview['rows'] : [];

		$terms = [];
		foreach ( $rows as $row ) {
			$terms[] = [
				'id'             => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'name'           => isset( $row['name'] ) ? (string) $row['name'] : '',
				'slug'           => isset( $row['slug'] ) ? (string) $row['slug'] : '',
				'count'          => isset( $row['count'] ) ? (int) $row['count'] : 0,
				'words'          => isset( $row['words'] ) ? (int) $row['words'] : 0,
				'hasDescription' => ! empty( $row['has_description'] ),
			];
		}

		$defaults = [
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'groq_ai_bulk_generate_terms' ),
			'taxonomy'        => $taxonomy,
			'terms'           => $terms,
			'allowRegenerate' => false,
			'strings'         => [
				'unknownError'      => __( 'Onbekende fout', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'unknownTerm'       => __( 'Onbekende term.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'confirmStopFallback' => __( 'Stoppen?', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logErrorDefault'   => __( '%1$s: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logSuccessDefault' => __( '%1$s gevuld.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateErrorDefault' => __( '%1$s mislukt: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'regenerateDoneDefault'  => __( '%s is bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
		];

		$config = wp_parse_args( $overrides, $defaults );
		$override_strings = isset( $overrides['strings'] ) && is_array( $overrides['strings'] ) ? $overrides['strings'] : [];
		$config['strings'] = array_merge( $defaults['strings'], $override_strings );

		wp_localize_script( 'groq-ai-term-bulk', 'GroqAITermBulk', $config );
	}

	protected function get_term_overview_data( $taxonomy ) {
		$taxonomy = sanitize_key( (string) $taxonomy );

		if ( isset( $this->term_overview_cache[ $taxonomy ] ) ) {
			return $this->term_overview_cache[ $taxonomy ];
		}

		$rows       = [];
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

	protected function get_term_page_url( $taxonomy, $term_id ) {
		return add_query_arg(
			[
				'page'     => 'groq-ai-product-text-term',
				'taxonomy' => sanitize_key( (string) $taxonomy ),
				'term_id'  => absint( $term_id ),
			],
			admin_url( 'options-general.php' )
		);
	}

	public function enqueue_term_assets( $hook ) {
		if ( 0 !== strpos( (string) $hook, 'settings_page_groq-ai-product-text-term' ) ) {
			return;
		}

		$this->enqueue_admin_styles();

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
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'groq_ai_generate_term' ),
				'taxonomy' => $taxonomy,
				'termId'   => $term_id,
				'strings'  => [
					'promptRequired' => __( 'Vul eerst een prompt in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'loading'        => __( 'AI is bezig met schrijven...', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'success'        => __( 'Tekst gegenereerd. Je kunt hem toepassen en opslaan.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'applySuccess'   => __( 'Tekst ingevuld. Vergeet niet op "Opslaan" te klikken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'errorDefault'   => __( 'Er ging iets mis bij het genereren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'errorUnknown'   => __( 'Onbekende fout', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
			]
		);
	}

	public function render_term_generator_page() {
		if ( ! $this->current_user_can_manage() ) {
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
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Taxonomie', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( $taxonomy ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Huidige woordtelling', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
					<td><?php echo esc_html( (string) $word_count ); ?></td>
				</tr>
			</table>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="groq-ai-term-form">
				<?php wp_nonce_field( 'groq_ai_save_term_content' ); ?>
				<input type="hidden" name="action" value="groq_ai_save_term_content" />
				<input type="hidden" name="taxonomy" value="<?php echo esc_attr( $taxonomy ); ?>" />
				<input type="hidden" name="term_id" value="<?php echo esc_attr( $term_id ); ?>" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="groq-ai-term-description"><?php esc_html_e( 'Omschrijving (top description)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea id="groq-ai-term-description" class="large-text" rows="8" name="description"><?php echo esc_textarea( $term->description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Bovenste omschrijving van de term. Wordt op de term-archive bovenaan getoond.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-term-bottom"><?php esc_html_e( 'Onderste omschrijving', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea id="groq-ai-term-bottom" class="large-text" rows="10" name="groq_ai_term_bottom_description"><?php echo esc_textarea( $bottom_description ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Wordt onderaan op de term-archive geplaatst. Laat leeg wanneer je dit niet wilt gebruiken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="groq-ai-term-custom-prompt"><?php esc_html_e( 'Eigen prompt (optioneel)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
						<td>
							<textarea id="groq-ai-term-custom-prompt" class="large-text" rows="5" name="groq_ai_term_custom_prompt"><?php echo esc_textarea( $meta_prompt ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Overschrijft de standaard prompt alleen voor deze term.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
						</td>
					</tr>
					<?php if ( $rankmath_module_enabled ) : ?>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-title"><?php esc_html_e( 'Rank Math meta title', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea id="groq-ai-rankmath-title" class="large-text" rows="2" name="groq_ai_rankmath_meta_title" <?php disabled( ! $rankmath_active ); ?>><?php echo esc_textarea( $rankmath_title ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Wordt opgeslagen in Rank Math. Alleen beschikbaar als Rank Math actief is.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-description"><?php esc_html_e( 'Rank Math meta description', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea id="groq-ai-rankmath-description" class="large-text" rows="3" name="groq_ai_rankmath_meta_description" <?php disabled( ! $rankmath_active ); ?>><?php echo esc_textarea( $rankmath_description ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="groq-ai-rankmath-keywords"><?php esc_html_e( 'Rank Math focus keywords', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></label></th>
							<td>
								<textarea id="groq-ai-rankmath-keywords" class="large-text" rows="2" name="groq_ai_rankmath_focus_keywords" <?php disabled( ! $rankmath_active ); ?>><?php echo esc_textarea( $rankmath_focus_keywords ); ?></textarea>
							</td>
						</tr>
					<?php endif; ?>
				</table>
				<?php submit_button( __( 'Term opslaan', GROQ_AI_PRODUCT_TEXT_DOMAIN ) ); ?>
			</form>
			<hr />
			<form id="groq-ai-term-generator" action="javascript:void(0);">
				<h2><?php esc_html_e( 'AI-term generator', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<p><?php esc_html_e( 'Gebruik de AI om automatisch teksten te genereren. Pas deze aan voordat je opslaat.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
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
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		];
		$keys = apply_filters( 'groq_ai_rankmath_term_meta_keys', $keys, $term, $settings );
		if ( ! is_array( $keys ) ) {
			$keys = [];
		}

		return [
			'title'         => isset( $keys['title'] ) ? sanitize_key( (string) $keys['title'] ) : 'rank_math_title',
			'description'   => isset( $keys['description'] ) ? sanitize_key( (string) $keys['description'] ) : 'rank_math_description',
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

	private function ensure_term_handler_registered() {
		if ( self::$term_handler_registered ) {
			return;
		}

		add_action( 'admin_post_groq_ai_save_term_content', [ $this, 'handle_save_term_content' ] );
		self::$term_handler_registered = true;
	}

	private function ensure_term_assets_hook() {
		if ( self::$term_assets_hook_registered ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_term_assets' ] );
		self::$term_assets_hook_registered = true;
	}
}
