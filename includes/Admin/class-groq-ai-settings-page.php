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
			#adminmenu a[href="options-general.php?page=groq-ai-product-text-term"] {
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
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Geen categorieën gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></td></tr>
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
		if ( ! in_array( $hook, [
			'settings_page_groq-ai-product-text',
			'settings_page_groq-ai-product-text-modules',
			'settings_page_groq-ai-product-text-prompts',
			'settings_page_groq-ai-product-text-categories',
			'settings_page_groq-ai-product-text-brands',
			'settings_page_groq-ai-product-text-term',
		], true ) ) {
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

		if ( 'settings_page_groq-ai-product-text-term' === $hook ) {
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

		if ( 'settings_page_groq-ai-product-text-categories' === $hook ) {
			$bulk_taxonomy    = 'product_cat';
			$bulk_strings     = [
				'statusIdle'     => __( 'Bulk gestart. AI werkt de geselecteerde categorieën bij…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusProgress' => __( 'Categorie %1$s van %2$s: %3$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusDone'     => __( 'Klaar! %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusStopped'  => __( 'Bulk generatie gestopt. %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'statusEmpty'    => __( 'Geen categorieën zonder omschrijving gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logSuccess'     => __( '%1$s gevuld (%2$d woorden).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'logError'       => __( '%1$s mislukt: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				'confirmStop'    => __( 'Weet je zeker dat je wilt stoppen? De huidige categorie kan onafgemaakt blijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			];
		} elseif ( 'settings_page_groq-ai-product-text-brands' === $hook ) {
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
}
