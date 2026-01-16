<?php

class Groq_AI_Google_Search_Console_Client {
	/** @var Groq_AI_Google_OAuth_Client */
	private $oauth;

	public function __construct( Groq_AI_Google_OAuth_Client $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * @param int $status_code
	 * @param string $raw_body
	 * @return WP_Error
	 */
	private function build_http_error( $status_code, $raw_body ) {
		$status_code = absint( $status_code );
		$raw_body    = (string) $raw_body;

		$message = __( 'Search Console API call mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
		$details = '';

		$data = json_decode( $raw_body, true );
		if ( is_array( $data ) ) {
			// Google APIs often respond with: { error: { code, message, status, details/errors } }
			$err = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : [];
			$google_message = isset( $err['message'] ) ? trim( (string) $err['message'] ) : '';
			$google_status  = isset( $err['status'] ) ? trim( (string) $err['status'] ) : '';
			if ( '' !== $google_status || '' !== $google_message ) {
				$details = trim( $google_status . ( $google_status && $google_message ? ': ' : '' ) . $google_message );
			}
		}

		if ( '' !== $details ) {
			$message = sprintf(
				/* translators: 1: HTTP status, 2: details */
				__( 'Search Console API call mislukt (HTTP %1$d): %2$s', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$status_code,
				$details
			);
		} else {
			$message = sprintf(
				/* translators: %d: HTTP status */
				__( 'Search Console API call mislukt (HTTP %d).', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
				$status_code
			);
		}

		return new WP_Error( 'groq_ai_gsc_error', $message );
	}

	/**
	 * @param array $settings
	 * @return array|WP_Error Array of siteUrl strings.
	 */
	public function list_sites( $settings ) {
		$token = $this->oauth->get_access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = wp_remote_get(
			'https://searchconsole.googleapis.com/webmasters/v3/sites',
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Accept' => 'application/json',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( (string) $raw_body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			return $this->build_http_error( $status_code, $raw_body );
		}

		$entries = isset( $data['siteEntry'] ) && is_array( $data['siteEntry'] ) ? $data['siteEntry'] : [];
		$sites = [];
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$site_url = isset( $entry['siteUrl'] ) ? trim( (string) $entry['siteUrl'] ) : '';
			if ( '' !== $site_url ) {
				$sites[] = $site_url;
			}
		}

		$sites = array_values( array_unique( $sites ) );
		sort( $sites, SORT_NATURAL | SORT_FLAG_CASE );

		return $sites;
	}

	/**
	 * @param array $settings
	 * @param string $site_url
	 * @param string $page_url
	 * @param string $start_date YYYY-MM-DD
	 * @param string $end_date YYYY-MM-DD
	 * @param int $limit
	 * @return array|WP_Error
	 */
	public function get_top_queries_for_page( $settings, $site_url, $page_url, $start_date, $end_date, $limit = 10 ) {
		$site_url = trim( (string) $site_url );
		$page_url = trim( (string) $page_url );
		$limit    = max( 1, min( 25, absint( $limit ) ) );

		if ( '' === $site_url || '' === $page_url ) {
			return new WP_Error( 'groq_ai_gsc_missing', __( 'Search Console site URL of pagina URL ontbreekt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$token = $this->oauth->get_access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = 'https://searchconsole.googleapis.com/webmasters/v3/sites/' . rawurlencode( $site_url ) . '/searchAnalytics/query';

		$body = [
			'startDate' => $start_date,
			'endDate' => $end_date,
			'dimensions' => [ 'query' ],
			'rowLimit' => $limit,
			'dimensionFilterGroups' => [
				[
					'filters' => [
						[
							'dimension' => 'page',
							'operator' => 'equals',
							'expression' => $page_url,
						],
					],
				],
			],
			'aggregationType' => 'auto',
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
				],
				'body' => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$raw_body    = wp_remote_retrieve_body( $response );
		$data        = json_decode( (string) $raw_body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			return $this->build_http_error( $status_code, $raw_body );
		}

		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : [];
		$result = [];

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$keys = isset( $row['keys'] ) && is_array( $row['keys'] ) ? $row['keys'] : [];
			$query = isset( $keys[0] ) ? sanitize_text_field( (string) $keys[0] ) : '';
			if ( '' === $query ) {
				continue;
			}
			$result[] = [
				'query' => $query,
				'clicks' => isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0,
				'impressions' => isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0,
				'ctr' => isset( $row['ctr'] ) ? (float) $row['ctr'] : 0.0,
				'position' => isset( $row['position'] ) ? (float) $row['position'] : 0.0,
			];
		}

		return $result;
	}
}
