<?php

class Groq_AI_Product_Text_Settings_Page {
	private $plugin;
	private $provider_manager;
	private $brand_taxonomy = null;

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

	public function render_categories_overview_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$terms = get_terms(
			[
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'orderby' => 'name',
				'order' => 'ASC',
				'number' => 0,
			]
		);
		if ( is_wp_error( $terms ) ) {
			$terms = [];
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h1>
			<p><?php esc_html_e( 'Klik op een categorie om teksten te genereren en instellingen te beheren. De tabel toont de huidige woordlengte van de categorie-omschrijving.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></p>
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
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Geen categorieën gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $terms as $term ) : ?>
						<?php
							$link = $this->get_term_page_url( 'product_cat', $term->term_id );
							$words = $this->count_words( $term->description );
							$count = isset( $term->count ) ? absint( $term->count ) : 0;
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( $term->name ); ?></strong></a>
							</td>
							<td><?php echo esc_html( $term->slug ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
							<td><?php echo esc_html( (string) $words ); ?></td>
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

		$terms = get_terms(
			[
				'taxonomy' => $taxonomy,
				'hide_empty' => false,
				'orderby' => 'name',
				'order' => 'ASC',
				'number' => 0,
			]
		);
		if ( is_wp_error( $terms ) ) {
			$terms = [];
		}
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
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Merk', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Slug', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Producten', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
						<th><?php esc_html_e( 'Woorden (omschrijving)', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $terms ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Geen merken gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $terms as $term ) : ?>
						<?php
							$link = $this->get_term_page_url( $taxonomy, $term->term_id );
							$words = $this->count_words( $term->description );
							$count = isset( $term->count ) ? absint( $term->count ) : 0;
						?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $link ); ?>"><strong><?php echo esc_html( $term->name ); ?></strong></a>
							</td>
							<td><?php echo esc_html( $term->slug ); ?></td>
							<td><?php echo esc_html( (string) $count ); ?></td>
							<td><?php echo esc_html( (string) $words ); ?></td>
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
		$default_prompt = (string) $meta_prompt;
		if ( '' === trim( $default_prompt ) ) {
			$default_prompt = __( 'Schrijf een SEO-vriendelijke categorieomschrijving in het Nederlands. Gebruik duidelijke tussenkoppen en <p>-tags. Voeg geen prijsinformatie toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		}
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

	public function handle_save_term_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'groq_ai_save_term_content' );

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$term_id  = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$description = isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '';
		$bottom_description = isset( $_POST['groq_ai_term_bottom_description'] ) ? wp_kses_post( wp_unslash( $_POST['groq_ai_term_bottom_description'] ) ) : '';
		$custom_prompt = isset( $_POST['groq_ai_term_custom_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['groq_ai_term_custom_prompt'] ) ) : '';
		$rankmath_meta_title = isset( $_POST['groq_ai_rankmath_meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['groq_ai_rankmath_meta_title'] ) ) : '';
		$rankmath_meta_description = isset( $_POST['groq_ai_rankmath_meta_description'] ) ? sanitize_text_field( wp_unslash( $_POST['groq_ai_rankmath_meta_description'] ) ) : '';
		$rankmath_focus_keywords = isset( $_POST['groq_ai_rankmath_focus_keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['groq_ai_rankmath_focus_keywords'] ) ) : '';

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) || ! $term_id ) {
			wp_safe_redirect( $this->get_settings_page_url() );
			exit;
		}

		$result = wp_update_term(
			$term_id,
			$taxonomy,
			[
				'description' => $description,
			]
		);

		if ( ! is_wp_error( $result ) ) {
			update_term_meta( $term_id, 'groq_ai_term_custom_prompt', $custom_prompt );
			$settings = $this->plugin->get_settings();
			$term = get_term( $term_id, $taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$bottom_meta_key = $this->resolve_term_bottom_description_meta_key( $term, $settings );
				$effective_bottom_meta_key = '' !== $bottom_meta_key ? $bottom_meta_key : 'groq_ai_term_bottom_description';
				update_term_meta( $term_id, $effective_bottom_meta_key, $bottom_description );

				$rankmath_module_enabled = $this->plugin->is_module_enabled( 'rankmath', $settings );
				if ( $rankmath_module_enabled ) {
					$rankmath_keys = $this->resolve_rankmath_term_meta_keys( $term, $settings );
					update_term_meta( $term_id, $rankmath_keys['title'], $rankmath_meta_title );
					update_term_meta( $term_id, $rankmath_keys['description'], $rankmath_meta_description );
					update_term_meta( $term_id, $rankmath_keys['focus_keyword'], $rankmath_focus_keywords );
				}
			}
		}

		wp_safe_redirect( $this->get_term_page_url( $taxonomy, $term_id ) );
		exit;
	}

	public function register_settings() {
		register_setting( 'groq_ai_product_text_group', $this->plugin->get_option_key(), [ $this->plugin, 'sanitize_settings' ] );

		add_settings_section(
			'groq_ai_product_text_general',
			__( 'Algemene instellingen', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'__return_false',
			'groq-ai-product-text'
		);

		add_settings_section(
			'groq_ai_product_text_google',
			__( 'Google koppeling (OAuth)', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
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

		add_settings_field(
			'groq_ai_google_oauth_client_id',
			__( 'Google Client ID', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_oauth_client_id_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
		);

		add_settings_field(
			'groq_ai_google_oauth_client_secret',
			__( 'Google Client secret', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_oauth_client_secret_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
		);

		add_settings_field(
			'groq_ai_google_oauth_status',
			__( 'Google status', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_oauth_status_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
		);

		add_settings_field(
			'groq_ai_google_gsc_site_url',
			__( 'Search Console site URL', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_gsc_site_url_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
		);

		add_settings_field(
			'groq_ai_google_ga4_property_id',
			__( 'GA4 property ID', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_ga4_property_id_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
		);

		add_settings_field(
			'groq_ai_google_context_toggles',
			__( 'Google data gebruiken', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_google_context_toggles_field' ],
			'groq-ai-product-text',
			'groq_ai_product_text_google'
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
			'groq_ai_max_output_tokens',
			__( 'Max output tokens', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_max_output_tokens_field' ],
			'groq-ai-product-text-prompts',
			'groq_ai_product_text_prompts'
		);

		add_settings_field(
			'groq_ai_term_bottom_description_meta_key',
			__( 'Term-veld (onderaan) meta key', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			[ $this, 'render_term_bottom_description_meta_key_field' ],
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
			<?php $this->render_google_oauth_admin_notice(); ?>
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

	private function get_google_oauth_redirect_uri() {
		return admin_url( 'admin-post.php?action=groq_ai_google_oauth_callback' );
	}

	private function get_google_oauth_scopes() {
		return [
			'openid',
			'email',
			'https://www.googleapis.com/auth/webmasters.readonly',
			'https://www.googleapis.com/auth/analytics.readonly',
		];
	}

	private function get_google_oauth_state_key() {
		return 'groq_ai_google_oauth_state_' . get_current_user_id();
	}

	private function get_settings_page_url() {
		return admin_url( 'options-general.php?page=groq-ai-product-text' );
	}

	private function render_google_oauth_admin_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$status  = isset( $_GET['groq_ai_google_oauth'] ) ? sanitize_text_field( wp_unslash( $_GET['groq_ai_google_oauth'] ) ) : '';
		$message = isset( $_GET['groq_ai_google_oauth_message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['groq_ai_google_oauth_message'] ) ) ) : '';

		if ( '' === $status ) {
			return;
		}

		$type = 'info';
		if ( 'success' === $status ) {
			$type = 'success';
		} elseif ( 'error' === $status ) {
			$type = 'error';
		}

		if ( '' === $message ) {
			if ( 'success' === $status ) {
				$message = __( 'Google koppeling bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			} elseif ( 'error' === $status ) {
				$message = __( 'Google koppeling mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			}
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible">
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	private function get_google_test_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=groq_ai_google_test_connection' ),
			'groq_ai_google_test_connection',
			'_wpnonce'
		);
	}

	public function render_google_oauth_client_id_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['google_oauth_client_id'] ) ? $settings['google_oauth_client_id'] : '';
		?>
		<input type="text" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_oauth_client_id]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" />
		<p class="description">
			<?php esc_html_e( 'Client ID uit Google Cloud Console (OAuth 2.0 Client).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_google_oauth_client_secret_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['google_oauth_client_secret'] ) ? $settings['google_oauth_client_secret'] : '';
		?>
		<input type="password" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_oauth_client_secret]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" autocomplete="off" />
		<p class="description">
			<?php esc_html_e( 'Client secret uit Google Cloud Console. Wordt opgeslagen in de WordPress options tabel.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_google_oauth_status_field() {
		$settings      = $this->plugin->get_settings();
		$connected     = ! empty( $settings['google_oauth_refresh_token'] );
		$email         = isset( $settings['google_oauth_connected_email'] ) ? $settings['google_oauth_connected_email'] : '';
		$connected_at  = isset( $settings['google_oauth_connected_at'] ) ? absint( $settings['google_oauth_connected_at'] ) : 0;
		$redirect_uri  = $this->get_google_oauth_redirect_uri();

		$start_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=groq_ai_google_oauth_start' ),
			'groq_ai_google_oauth_start',
			'_wpnonce'
		);

		$disconnect_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=groq_ai_google_oauth_disconnect' ),
			'groq_ai_google_oauth_disconnect',
			'_wpnonce'
		);
		?>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Let op: als je Client ID/secret net hebt ingevuld of gewijzigd, klik eerst op "Wijzigingen opslaan" voordat je verbindt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<p class="description" style="margin-top:0;">
			<?php esc_html_e( 'Redirect URI (kopieer deze naar Google Cloud Console):', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			<br />
			<code><?php echo esc_html( $redirect_uri ); ?></code>
		</p>
		<?php if ( $connected ) : ?>
			<p>
				<strong><?php esc_html_e( 'Verbonden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong>
				<?php if ( $email ) : ?>
					— <?php echo esc_html( $email ); ?>
				<?php endif; ?>
				<?php if ( $connected_at ) : ?>
					(<?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $connected_at ) ); ?>)
				<?php endif; ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $start_url ); ?>">
					<?php esc_html_e( 'Opnieuw verbinden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
				<a class="button button-secondary" href="<?php echo esc_url( $disconnect_url ); ?>" style="margin-left:8px;">
					<?php esc_html_e( 'Ontkoppelen', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
			</p>
		<?php else : ?>
			<p><strong><?php esc_html_e( 'Niet verbonden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></strong></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $start_url ); ?>">
					<?php esc_html_e( 'Google verbinden', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
				</a>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Deze stap doet alleen authenticatie en slaat een refresh token op. Data ophalen (Search Console / Analytics) voegen we daarna toe.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( $this->get_google_test_url() ); ?>">
				<?php esc_html_e( 'Test Google verbinding', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</a>
			<span class="description" style="margin-left:8px;">
				<?php esc_html_e( 'Doet een token refresh en (indien ingevuld) een test-call naar Search Console/GA4.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
			</span>
		</p>
		<?php
	}

	public function handle_google_test_connection() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'groq_ai_google_test_connection' );

		$settings = $this->plugin->get_settings();
		$messages = [];
		$status   = 'success';

		$oauth = new Groq_AI_Google_OAuth_Client();
		$token = $oauth->get_access_token( $settings );
		if ( is_wp_error( $token ) ) {
			$status = 'error';
			$messages[] = sprintf( __( 'OAuth: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $token->get_error_message() );
		} else {
			$messages[] = __( 'OAuth: OK (access token opgehaald).', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			$info = $oauth->get_access_token_info( $token );
			if ( is_array( $info ) ) {
				$scope = isset( $info['scope'] ) ? trim( (string) $info['scope'] ) : '';
				if ( '' !== $scope ) {
					$messages[] = sprintf( __( 'OAuth scopes: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $scope );
					if ( false === strpos( $scope, 'https://www.googleapis.com/auth/webmasters' ) ) {
						$messages[] = __( 'Tip: je access token mist Search Console scope. Klik op "Opnieuw verbinden" zodat je toestemming opnieuw wordt gevraagd.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
					}
				}
			}
		}

		$range_days = 7;
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', time() - ( $range_days * DAY_IN_SECONDS ) );

		if ( 'error' !== $status && ! empty( $settings['google_enable_gsc'] ) ) {
			$gsc = new Groq_AI_Google_Search_Console_Client( $oauth );
			$sites = $gsc->list_sites( $settings );
			if ( is_wp_error( $sites ) ) {
				$status = 'error';
				$messages[] = sprintf( __( 'Search Console: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $sites->get_error_message() );
			} else {
				$count = is_array( $sites ) ? count( $sites ) : 0;
				$messages[] = sprintf( __( 'Search Console: OK (%d properties zichtbaar).', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $count );
				$site_url = isset( $settings['google_gsc_site_url'] ) ? trim( (string) $settings['google_gsc_site_url'] ) : '';
				if ( '' !== $site_url && is_array( $sites ) && ! in_array( $site_url, $sites, true ) ) {
					$messages[] = __( 'Let op: de ingestelde site URL is niet gevonden in jouw zichtbare GSC properties.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				}
			}
		}

		if ( 'error' !== $status && ! empty( $settings['google_enable_ga'] ) ) {
			$property_id = isset( $settings['google_ga4_property_id'] ) ? trim( (string) $settings['google_ga4_property_id'] ) : '';
			if ( '' !== $property_id ) {
				$ga = new Groq_AI_Google_Analytics_Data_Client( $oauth );
				$stats = $ga->get_property_sessions_summary( $settings, $property_id, $start_date, $end_date );
				if ( is_wp_error( $stats ) ) {
					$status = 'error';
					$messages[] = sprintf( __( 'Analytics: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $stats->get_error_message() );
				} else {
					$sessions = isset( $stats['sessions'] ) ? absint( $stats['sessions'] ) : 0;
					$messages[] = sprintf( __( 'Analytics: OK (sessies laatste %1$d dagen: ~%2$d).', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $range_days, $sessions );
				}
			} else {
				$messages[] = __( 'Analytics: overgeslagen (GA4 property ID niet ingevuld).', GROQ_AI_PRODUCT_TEXT_DOMAIN );
			}
		}

		$url = add_query_arg(
			[
				'groq_ai_google_oauth' => $status,
				'groq_ai_google_oauth_message' => implode( ' ', $messages ),
			],
			$this->get_settings_page_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function render_google_gsc_site_url_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['google_gsc_site_url'] ) ? (string) $settings['google_gsc_site_url'] : '';
		?>
		<input type="url" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_gsc_site_url]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="sc-domain:example.com" />
		<p class="description">
			<?php esc_html_e( 'Voorbeeld: sc-domain:example.com of https://www.example.com/. Moet exact overeenkomen met je property in Search Console.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_google_ga4_property_id_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['google_ga4_property_id'] ) ? (string) $settings['google_ga4_property_id'] : '';
		?>
		<input type="text" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_ga4_property_id]" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="123456789" />
		<p class="description">
			<?php esc_html_e( 'GA4 property ID (cijferreeks). Nodig voor Analytics Data API.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_google_context_toggles_field() {
		$settings = $this->plugin->get_settings();
		$gsc = ! empty( $settings['google_enable_gsc'] );
		$ga  = ! empty( $settings['google_enable_ga'] );
		?>
		<label style="display:block;margin-bottom:6px;">
			<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_enable_gsc]" value="1" <?php checked( $gsc ); ?> />
			<?php esc_html_e( 'Search Console data meesturen (queries/clicks/impressions).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</label>
		<label style="display:block;">
			<input type="checkbox" name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[google_enable_ga]" value="1" <?php checked( $ga ); ?> />
			<?php esc_html_e( 'Analytics (GA4) data meesturen (indicatieve sessies).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</label>
		<?php
	}

	public function handle_google_oauth_start() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'groq_ai_google_oauth_start' );

		$settings     = $this->plugin->get_settings();
		$client_id    = isset( $settings['google_oauth_client_id'] ) ? trim( (string) $settings['google_oauth_client_id'] ) : '';
		$client_secret = isset( $settings['google_oauth_client_secret'] ) ? trim( (string) $settings['google_oauth_client_secret'] ) : '';

		if ( '' === $client_id || '' === $client_secret ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'Vul eerst Google Client ID en Client secret in.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$state = wp_generate_password( 32, false, false );
		set_transient( $this->get_google_oauth_state_key(), $state, 10 * MINUTE_IN_SECONDS );

		$scope = implode( ' ', $this->get_google_oauth_scopes() );
		$redirect_uri = $this->get_google_oauth_redirect_uri();

		$auth_url = add_query_arg(
			[
				'client_id' => $client_id,
				'redirect_uri' => $redirect_uri,
				'response_type' => 'code',
				'access_type' => 'offline',
				'prompt' => 'consent',
				'include_granted_scopes' => 'true',
				'scope' => $scope,
				'state' => $state,
			],
			'https://accounts.google.com/o/oauth2/v2/auth'
		);
		$auth_url = esc_url_raw( $auth_url );
		$parsed   = wp_parse_url( $auth_url );
		$host     = isset( $parsed['host'] ) ? strtolower( (string) $parsed['host'] ) : '';
		if ( 'accounts.google.com' !== $host ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'OAuth URL ongeldig. Controleer plugin instellingen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		// Let op: wp_safe_redirect staat standaard geen externe hosts toe en valt dan terug naar /wp-admin.
		wp_redirect( $auth_url );
		exit;
	}

	public function handle_google_oauth_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$expected_state = get_transient( $this->get_google_oauth_state_key() );
		delete_transient( $this->get_google_oauth_state_key() );

		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		if ( '' !== $error ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => sprintf( __( 'Google OAuth error: %s', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $error ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		if ( empty( $expected_state ) || '' === $state || $state !== $expected_state ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'Ongeldige OAuth state. Probeer opnieuw te verbinden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		if ( '' === $code ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'Geen OAuth code ontvangen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$settings      = $this->plugin->get_settings();
		$client_id     = isset( $settings['google_oauth_client_id'] ) ? trim( (string) $settings['google_oauth_client_id'] ) : '';
		$client_secret = isset( $settings['google_oauth_client_secret'] ) ? trim( (string) $settings['google_oauth_client_secret'] ) : '';
		$redirect_uri  = $this->get_google_oauth_redirect_uri();

		if ( '' === $client_id || '' === $client_secret ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'Client ID/secret ontbreken. Sla eerst de instellingen op.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$token_response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'code' => $code,
					'client_id' => $client_id,
					'client_secret' => $client_secret,
					'redirect_uri' => $redirect_uri,
					'grant_type' => 'authorization_code',
				],
			]
		);

		if ( is_wp_error( $token_response ) ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => $token_response->get_error_message(),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$status_code = wp_remote_retrieve_response_code( $token_response );
		$body        = wp_remote_retrieve_body( $token_response );
		$data        = json_decode( (string) $body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			$url = add_query_arg(
				[
					'groq_ai_google_oauth' => 'error',
					'groq_ai_google_oauth_message' => __( 'Token exchange mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
				$this->get_settings_page_url()
			);
			wp_safe_redirect( $url );
			exit;
		}

		$access_token  = isset( $data['access_token'] ) ? sanitize_text_field( (string) $data['access_token'] ) : '';
		$refresh_token = isset( $data['refresh_token'] ) ? sanitize_text_field( (string) $data['refresh_token'] ) : '';

		if ( '' === $refresh_token ) {
			$refresh_token = isset( $settings['google_oauth_refresh_token'] ) ? sanitize_text_field( (string) $settings['google_oauth_refresh_token'] ) : '';
		}

		$connected_email = '';
		if ( '' !== $access_token ) {
			$userinfo_response = wp_remote_get(
				'https://openidconnect.googleapis.com/v1/userinfo',
				[
					'timeout' => 20,
					'headers' => [
						'Authorization' => 'Bearer ' . $access_token,
					],
				]
			);
			if ( ! is_wp_error( $userinfo_response ) && 200 === wp_remote_retrieve_response_code( $userinfo_response ) ) {
				$userinfo_body = wp_remote_retrieve_body( $userinfo_response );
				$userinfo_data = json_decode( (string) $userinfo_body, true );
				if ( is_array( $userinfo_data ) && ! empty( $userinfo_data['email'] ) ) {
					$connected_email = sanitize_email( (string) $userinfo_data['email'] );
				}
			}
		}

		$options = get_option( $this->plugin->get_option_key(), [] );
		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$options['google_oauth_refresh_token']   = $refresh_token;
		$options['google_oauth_connected_email'] = $connected_email;
		$options['google_oauth_connected_at']    = time();
		update_option( $this->plugin->get_option_key(), $options );

		$url = add_query_arg(
			[
				'groq_ai_google_oauth' => 'success',
				'groq_ai_google_oauth_message' => __( 'Google succesvol verbonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			$this->get_settings_page_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	public function handle_google_oauth_disconnect() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Geen toestemming.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		check_admin_referer( 'groq_ai_google_oauth_disconnect' );

		$options = get_option( $this->plugin->get_option_key(), [] );
		if ( ! is_array( $options ) ) {
			$options = [];
		}

		$options['google_oauth_refresh_token']   = '';
		$options['google_oauth_connected_email'] = '';
		$options['google_oauth_connected_at']    = 0;
		update_option( $this->plugin->get_option_key(), $options );

		$url = add_query_arg(
			[
				'groq_ai_google_oauth' => 'success',
				'groq_ai_google_oauth_message' => __( 'Google koppeling verwijderd.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			],
			$this->get_settings_page_url()
		);
		wp_safe_redirect( $url );
		exit;
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

	public function render_max_output_tokens_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['max_output_tokens'] ) ? absint( $settings['max_output_tokens'] ) : 2048;
		$value    = max( 128, min( 8192, $value ) );
		?>
		<input type="number"
			name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[max_output_tokens]"
			min="128"
			max="8192"
			step="128"
			value="<?php echo esc_attr( (string) $value ); ?>"
			class="small-text"
		/>
		<p class="description">
			<?php esc_html_e( 'Limiet voor lengte van het AI-antwoord. Als teksten afgekapt worden, zet dit hoger (kost vaak wel meer tokens).', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
		<?php
	}

	public function render_term_bottom_description_meta_key_field() {
		$settings = $this->plugin->get_settings();
		$value    = isset( $settings['term_bottom_description_meta_key'] ) ? (string) $settings['term_bottom_description_meta_key'] : '';
		?>
		<input type="text"
			name="<?php echo esc_attr( $this->plugin->get_option_key() ); ?>[term_bottom_description_meta_key]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="bijv. bottom_description"
		/>
		<p class="description">
			<?php esc_html_e( 'Dit is de term meta key van het extra customfields-veld dat onderaan de categorie/merk pagina wordt getoond (LiveBetter customfields). Laat leeg om alleen de standaard term-omschrijving te gebruiken.', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?>
		</p>
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
