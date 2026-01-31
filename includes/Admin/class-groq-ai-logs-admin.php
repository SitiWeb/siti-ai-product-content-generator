<?php

class Groq_AI_Logs_Admin extends Groq_AI_Admin_Base {
	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		parent::__construct( $plugin );
		add_action( 'admin_menu', [ $this, 'register_menu_pages' ] );
	}

	public function register_menu_pages() {
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
			__( 'Siti AI Log detail', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			__( 'Siti AI Log detail', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			'manage_options',
			'groq-ai-product-text-log',
			[ $this, 'render_log_detail_page' ]
		);
	}

	public function render_logs_page() {
		if ( ! $this->current_user_can_manage() ) {
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
		if ( ! $this->current_user_can_manage() ) {
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
				<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;">
					<?php echo esc_html( $log['prompt'] ); ?>
				</pre>

				<h2><?php esc_html_e( 'AI-respons', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
				<pre style="background:#f9f9f9;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;">
					<?php echo esc_html( $log['response'] ); ?>
				</pre>

				<?php if ( ! empty( $log['request_json'] ) ) :
					$request_params = json_decode( $log['request_json'], true );
					$request_params = is_array( $request_params ) ? $request_params : [];
					if ( ! empty( $request_params ) ) :
						$request_pretty = wp_json_encode( $request_params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						$request_pretty = $request_pretty ? $request_pretty : wp_json_encode( $request_params );
						?>
						<h2><?php esc_html_e( 'Request parameters', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
						<pre style="background:#fff;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;">
							<?php echo esc_html( $request_pretty ); ?>
						</pre>
					<?php endif; endif; ?>

				<?php if ( ! empty( $log['usage_json'] ) ) :
					$usage_meta = json_decode( $log['usage_json'], true );
					$usage_meta = is_array( $usage_meta ) ? $usage_meta : [];
					if ( ! empty( $usage_meta ) ) :
						$usage_pretty = wp_json_encode( $usage_meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
						$usage_pretty = $usage_pretty ? $usage_pretty : wp_json_encode( $usage_meta );
						?>
						<h2><?php esc_html_e( 'Usage metadata', GROQ_AI_PRODUCT_TEXT_DOMAIN ); ?></h2>
						<pre style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;white-space:pre-wrap;">
							<?php echo esc_html( $usage_pretty ); ?>
						</pre>
					<?php endif; endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
