<?php

class Groq_AI_Categories_Admin extends Groq_AI_Term_Admin_Base {
	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		parent::__construct( $plugin );
		add_action( 'admin_menu', [ $this, 'register_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_category_assets' ] );
	}

	public function register_menu_pages() {
		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Categorie teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Categorieën', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-categories',
			[ $this, 'render_categories_overview_page' ]
		);

		$this->register_term_page();
	}

	public function render_categories_overview_page() {
		if ( ! $this->current_user_can_manage() ) {
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

	public function enqueue_category_assets( $hook ) {
		if ( 0 !== strpos( (string) $hook, 'settings_page_groq-ai-product-text-categories' ) ) {
			return;
		}

		$this->enqueue_admin_styles();

		wp_enqueue_script(
			'groq-ai-term-bulk',
			plugins_url( 'assets/js/term-bulk.js', GROQ_AI_PRODUCT_TEXT_FILE ),
			[],
			GROQ_AI_PRODUCT_TEXT_VERSION,
			true
		);

		$this->localize_term_bulk_script(
			'product_cat',
			[
				'allowRegenerate' => true,
				'strings'         => [
					'statusIdle'           => __( 'Bulk gestart. AI werkt de geselecteerde categorieën bij…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusProgress'       => __( 'Categorie %1$s van %2$s: %3$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusDone'           => __( 'Klaar! %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusStopped'        => __( 'Bulk generatie gestopt. %d categorieën bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'statusEmpty'          => __( 'Geen categorieën zonder omschrijving gevonden.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'logSuccess'           => __( '%1$s gevuld (%2$d woorden).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'logError'             => __( '%1$s mislukt: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'confirmStop'          => __( 'Weet je zeker dat je wilt stoppen? De huidige categorie kan onafgemaakt blijven.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'confirmRegenerate'    => __( 'Wil je categorie %s opnieuw laten schrijven?', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateProgress'   => __( '%s wordt opnieuw geschreven…', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateDone'       => __( '%s is bijgewerkt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateError'      => __( 'Kon %1$s niet bijwerken: %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
					'regenerateBlocked'    => __( 'Wacht tot de bulk generatie klaar is voordat je een categorie opnieuw genereert.', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				],
			]
		);
	}
}
