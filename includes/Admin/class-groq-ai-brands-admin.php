<?php

class Groq_AI_Brands_Admin extends Groq_AI_Term_Admin_Base {
	private $brand_taxonomy = null;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		parent::__construct( $plugin );
		add_action( 'admin_menu', [ $this, 'register_menu_pages' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_brand_assets' ] );
	}

	public function register_menu_pages() {
		add_submenu_page(
			'options-general.php',
			__( 'Siti AI Merk teksten', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Merken', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-brands',
			[ $this, 'render_brands_overview_page' ]
		);

		$this->register_term_page();
	}

	public function render_brands_overview_page() {
		if ( ! $this->current_user_can_manage() ) {
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

	public function enqueue_brand_assets( $hook ) {
		if ( 0 !== strpos( (string) $hook, 'settings_page_groq-ai-product-text-brands' ) ) {
			return;
		}

		$this->enqueue_admin_styles();

		$taxonomy = $this->detect_brand_taxonomy();
		if ( '' === $taxonomy ) {
			return;
		}

		wp_enqueue_script(
			'groq-ai-term-bulk',
			plugins_url( 'assets/js/term-bulk.js', GROQ_AI_PRODUCT_TEXT_FILE ),
			[],
			GROQ_AI_PRODUCT_TEXT_VERSION,
			true
		);

		$this->localize_term_bulk_script(
			$taxonomy,
			[
				'allowRegenerate' => true,
				'strings'         => [
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
				],
			]
		);
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
}
