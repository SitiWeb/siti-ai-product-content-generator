<?php

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Groq_AI_Logs_Table extends WP_List_Table {
	/** @var Groq_AI_Product_Text_Plugin */
	private $plugin;

	/** @var string */
	private $table;
	/** @var string */
	private $posts_table;

	public function __construct( Groq_AI_Product_Text_Plugin $plugin ) {
		$this->plugin = $plugin;
		global $wpdb;
		$this->table = $wpdb->prefix . 'groq_ai_generation_logs';
		$this->posts_table = $wpdb->posts;

		parent::__construct(
			[
				'singular' => 'groq_ai_log',
				'plural'   => 'groq_ai_logs',
				'ajax'     => false,
			]
		);
	}

	public function get_columns() {
		return [
			'created_at' => __( 'Datum', 'groq-ai-product-text' ),
			'user_id'    => __( 'Gebruiker', 'groq-ai-product-text' ),
			'post_title' => __( 'Product', 'groq-ai-product-text' ),
			'provider'   => __( 'Provider', 'groq-ai-product-text' ),
			'model'      => __( 'Model', 'groq-ai-product-text' ),
			'status'     => __( 'Status', 'groq-ai-product-text' ),
			'tokens_total' => __( 'Tokens', 'groq-ai-product-text' ),
		];
	}

	protected function get_sortable_columns() {
		return [
			'created_at' => [ 'created_at', true ],
			'provider'   => [ 'provider', false ],
			'model'      => [ 'model', false ],
			'status'     => [ 'status', false ],
		];
	}

	protected function get_default_primary_column_name() {
		return 'created_at';
	}

	public function prepare_items() {
		global $wpdb;

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		if ( ! $orderby ) {
			$orderby = 'created_at';
		}
		$order = isset( $_REQUEST['order'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) ) : 'DESC';
		$order = in_array( $order, [ 'ASC', 'DESC' ], true ) ? $order : 'DESC';

		$search = isset( $_REQUEST['s'] ) ? wp_unslash( trim( $_REQUEST['s'] ) ) : '';

		$where  = '1=1';
		$params = [];

		if ( $search ) {
			$like  = '%' . $wpdb->esc_like( $search ) . '%';
			$where .= ' AND (provider LIKE %s OR model LIKE %s OR prompt LIKE %s OR response LIKE %s OR error_message LIKE %s )';
			$params = array_merge( $params, [ $like, $like, $like, $like, $like ] );
		}

		$total_query = "SELECT COUNT(*) FROM {$this->table} l LEFT JOIN {$this->posts_table} p ON p.ID = l.post_id WHERE {$where}";
		$total_items = (int) $wpdb->get_var(
			$params ? $wpdb->prepare( $total_query, $params ) : $total_query
		);

		$query = "SELECT l.*, p.post_title FROM {$this->table} l LEFT JOIN {$this->posts_table} p ON p.ID = l.post_id WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$params_with_limits = array_merge( $params, [ $per_page, $offset ] );
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		$this->items = $params
			? $wpdb->get_results( $wpdb->prepare( $query, $params_with_limits ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( $query, $per_page, $offset ), ARRAY_A );

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => ceil( $total_items / $per_page ),
			]
		);
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'created_at':
				return esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) );
			case 'provider':
			case 'model':
			case 'status':
				return esc_html( $item[ $column_name ] );
			case 'tokens_total':
				return isset( $item['tokens_total'] ) ? absint( $item['tokens_total'] ) : '—';
			case 'post_title':
				if ( ! $item['post_id'] ) {
					return '—';
				}
				$title = $item['post_title'] ? $item['post_title'] : sprintf( __( 'Product #%d', 'groq-ai-product-text' ), (int) $item['post_id'] );
				$link  = get_edit_post_link( $item['post_id'] );
				return $link ? sprintf( '<a href="%s">%s</a>', esc_url( $link ), esc_html( $title ) ) : esc_html( $title );
			case 'user_id':
				if ( empty( $item['user_id'] ) ) {
					return '—';
				}
				$user = get_userdata( $item['user_id'] );
				return $user ? esc_html( $user->display_name ) : (int) $item['user_id'];
			case 'error_message':
				return $item['error_message'] ? esc_html( $item['error_message'] ) : '—';
			default:
				return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
		}
	}

	public function no_items() {
		esc_html_e( 'Nog geen AI-logboeken gevonden.', 'groq-ai-product-text' );
	}

	protected function column_created_at( $item ) {
		$date = esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['created_at'] ) );
		$usage = $this->get_usage_meta( $item );
		$payload = [
			'created_at'        => $item['created_at'],
			'user'              => $this->column_default( $item, 'user_id' ),
			'post_title'        => $item['post_title'],
			'provider'          => $item['provider'],
			'model'             => $item['model'],
			'status'            => $item['status'],
			'tokens_prompt'     => isset( $item['tokens_prompt'] ) ? (int) $item['tokens_prompt'] : null,
			'tokens_completion' => isset( $item['tokens_completion'] ) ? (int) $item['tokens_completion'] : null,
			'tokens_total'      => isset( $item['tokens_total'] ) ? (int) $item['tokens_total'] : null,
			'prompt'            => $item['prompt'],
			'response'          => $item['response'],
			'error_message'     => $item['error_message'],
			'image_context'     => isset( $usage['image_context'] ) ? $usage['image_context'] : null,
		];
		$encoded = esc_attr( wp_json_encode( $payload ) );
		return sprintf(
			'<a href="#" class="groq-ai-log-row" data-groq-log="%s">%s</a>',
			$encoded,
			$date
		);
	}

	private function get_usage_meta( $item ) {
		if ( empty( $item['usage_json'] ) ) {
			return [];
		}

		$data = json_decode( $item['usage_json'], true );

		return is_array( $data ) ? $data : [];
	}
}
