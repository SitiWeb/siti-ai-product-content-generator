<?php

/**
 * Loggingservice voor AI-generaties en DB-tabellen.
 *
 * Te importeren logica:
 * - log_generation_event()
 * - log_debug()
 * - get_logs_table_name()/create_logs_table()/maybe_create_logs_table()
 */
class Groq_AI_Generation_Logger {
	const OPTION_TABLE_CREATED = 'groq_ai_logs_table_created';

	/** @var WC_Logger|null */
	private $woo_logger = null;

	/** @var bool|null */
	private $logs_table_exists = null;

	public function log_generation_event( array $args ) {
		if ( ! $this->logs_table_exists() ) {
			return;
		}

		global $wpdb;
		$table = $this->get_logs_table_name();

		$usage             = isset( $args['usage'] ) && is_array( $args['usage'] ) ? $args['usage'] : [];
		$parameters        = isset( $args['parameters'] ) && is_array( $args['parameters'] ) ? $args['parameters'] : [];
		$prompt_tokens     = $this->extract_usage_token_value(
			$usage,
			[
				'prompt_tokens',
				'promptTokenCount',
				'input_tokens',
				'inputTokenCount',
			]
		);
		$completion_tokens = $this->extract_usage_token_value(
			$usage,
			[
				'completion_tokens',
				'output_tokens',
				'candidatesTokenCount',
				'outputTokenCount',
			]
		);
		$total_tokens      = $this->extract_usage_token_value(
			$usage,
			[
				'total_tokens',
				'totalTokenCount',
			]
		);

		$wpdb->insert(
			$table,
			[
				'created_at'        => current_time( 'mysql' ),
				'user_id'           => get_current_user_id(),
				'post_id'           => isset( $args['post_id'] ) ? absint( $args['post_id'] ) : 0,
				'provider'          => isset( $args['provider'] ) ? sanitize_text_field( $args['provider'] ) : '',
				'model'             => isset( $args['model'] ) ? sanitize_text_field( $args['model'] ) : '',
				'prompt'            => isset( $args['prompt'] ) ? $args['prompt'] : '',
				'response'          => isset( $args['response'] ) ? $args['response'] : '',
				'tokens_prompt'     => $prompt_tokens,
				'tokens_completion' => $completion_tokens,
				'tokens_total'      => $total_tokens,
				'status'            => isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : 'success',
				'error_message'     => isset( $args['error_message'] ) ? $args['error_message'] : '',
				'usage_json'        => ! empty( $usage ) ? wp_json_encode( $usage ) : null,
				'request_json'      => ! empty( $parameters ) ? wp_json_encode( $parameters ) : null,
			]
		);
	}

	public function log_debug( $message, $context = [] ) {
		if ( class_exists( 'WC_Logger' ) ) {
			if ( ! $this->woo_logger ) {
				$this->woo_logger = wc_get_logger();
			}

			if ( $this->woo_logger ) {
				$context_string = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
				$this->woo_logger->debug( '[GroqAI] ' . $message . $context_string, [ 'source' => 'groq-ai-product-text' ] );
				return;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$entry = '[GroqAI] ' . $message;

			if ( ! empty( $context ) ) {
				$entry .= ' ' . wp_json_encode( $context );
			}

			error_log( $entry );
		}
	}

	public function maybe_create_table() {
		if ( get_option( self::OPTION_TABLE_CREATED ) ) {
			$this->logs_table_exists = true;
			$this->maybe_upgrade_table_schema();
			return;
		}

		$this->create_table();
	}

	public function create_table() {
		global $wpdb;

		$table = $this->get_logs_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			provider varchar(50) NOT NULL,
			model varchar(100) NOT NULL,
			prompt longtext NOT NULL,
			response longtext DEFAULT NULL,
			tokens_prompt int unsigned DEFAULT NULL,
			tokens_completion int unsigned DEFAULT NULL,
			tokens_total int unsigned DEFAULT NULL,
			status varchar(20) NOT NULL,
			error_message text DEFAULT NULL,
			usage_json longtext DEFAULT NULL,
			request_json longtext DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY provider (provider),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sql );

		$this->logs_table_exists = true;
		update_option( self::OPTION_TABLE_CREATED, 1 );
	}

	public function cleanup_old_logs( $retention_days ) {
		$retention_days = absint( $retention_days );
		if ( $retention_days <= 0 || ! $this->logs_table_exists() ) {
			return;
		}

		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );
		$cutoff = gmdate( 'Y-m-d H:i:s', $cutoff );

		global $wpdb;
		$table = $this->get_logs_table_name();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	private function extract_usage_token_value( $usage, $keys ) {
		foreach ( (array) $keys as $key ) {
			if ( isset( $usage[ $key ] ) ) {
				return absint( $usage[ $key ] );
			}
		}

		return null;
	}

	private function maybe_upgrade_table_schema() {
		global $wpdb;

		$table = $this->get_logs_table_name();
		$column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'request_json' ) );
		if ( ! $column ) {
			$this->create_table();
		}
	}

	private function get_logs_table_name() {
		global $wpdb;

		return $wpdb->prefix . 'groq_ai_generation_logs';
	}

	private function logs_table_exists() {
		if ( null !== $this->logs_table_exists ) {
			return $this->logs_table_exists;
		}

		global $wpdb;
		$table  = $this->get_logs_table_name();
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->logs_table_exists = ( $result === $table );

		return $this->logs_table_exists;
	}
}
