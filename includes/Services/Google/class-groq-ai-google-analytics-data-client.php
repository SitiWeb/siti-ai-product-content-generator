<?php

class Groq_AI_Google_Analytics_Data_Client {
	/** @var Groq_AI_Google_OAuth_Client */
	private $oauth;

	public function __construct( Groq_AI_Google_OAuth_Client $oauth ) {
		$this->oauth = $oauth;
	}

	/**
	 * Simple connectivity check for GA4 Data API.
	 *
	 * @param array $settings
	 * @param string $property_id
	 * @param string $start_date
	 * @param string $end_date
	 * @return array|WP_Error
	 */
	public function get_property_sessions_summary( $settings, $property_id, $start_date, $end_date ) {
		$property_id = trim( (string) $property_id );
		if ( '' === $property_id ) {
			return new WP_Error( 'groq_ai_ga_missing', __( 'GA4 property ID ontbreekt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$token = $this->oauth->get_access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';
		$body = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate' => $end_date,
				],
			],
			'metrics' => [
				[ 'name' => 'sessions' ],
				[ 'name' => 'engagedSessions' ],
			],
			'limit' => 1,
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
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
			return new WP_Error( 'groq_ai_ga_error', __( 'GA4 Data API call mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : [];
		$sessions = 0;
		$engaged  = 0;
		foreach ( $rows as $row ) {
			$metric_values = isset( $row['metricValues'] ) && is_array( $row['metricValues'] ) ? $row['metricValues'] : [];
			if ( isset( $metric_values[0]['value'] ) ) {
				$sessions += absint( $metric_values[0]['value'] );
			}
			if ( isset( $metric_values[1]['value'] ) ) {
				$engaged += absint( $metric_values[1]['value'] );
			}
		}

		return [
			'sessions' => $sessions,
			'engagedSessions' => $engaged,
		];
	}

	/**
	 * Returns approximate GA4 sessions for a landing page path.
	 *
	 * @param array $settings
	 * @param string $property_id
	 * @param string $page_path e.g. /product-category/foo/
	 * @param string $start_date YYYY-MM-DD
	 * @param string $end_date YYYY-MM-DD
	 * @return array|WP_Error
	 */
	public function get_sessions_for_landing_page_path( $settings, $property_id, $page_path, $start_date, $end_date ) {
		$property_id = trim( (string) $property_id );
		$page_path   = trim( (string) $page_path );

		if ( '' === $property_id || '' === $page_path ) {
			return new WP_Error( 'groq_ai_ga_missing', __( 'GA4 property ID of page path ontbreekt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$token = $this->oauth->get_access_token( $settings );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$endpoint = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode( $property_id ) . ':runReport';

		$body = [
			'dateRanges' => [
				[
					'startDate' => $start_date,
					'endDate' => $end_date,
				],
			],
			'dimensions' => [
				[ 'name' => 'landingPagePlusQueryString' ],
			],
			'metrics' => [
				[ 'name' => 'sessions' ],
				[ 'name' => 'engagedSessions' ],
			],
			'dimensionFilter' => [
				'filter' => [
					'fieldName' => 'landingPagePlusQueryString',
					'stringFilter' => [
						'matchType' => 'CONTAINS',
						'value' => $page_path,
					],
				],
			],
			'limit' => 5,
		];

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 20,
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
					'Content-Type' => 'application/json',
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
			return new WP_Error( 'groq_ai_ga_error', __( 'GA4 Data API call mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$rows = isset( $data['rows'] ) && is_array( $data['rows'] ) ? $data['rows'] : [];
		$sessions = 0;
		$engaged  = 0;
		foreach ( $rows as $row ) {
			$metric_values = isset( $row['metricValues'] ) && is_array( $row['metricValues'] ) ? $row['metricValues'] : [];
			if ( isset( $metric_values[0]['value'] ) ) {
				$sessions += absint( $metric_values[0]['value'] );
			}
			if ( isset( $metric_values[1]['value'] ) ) {
				$engaged += absint( $metric_values[1]['value'] );
			}
		}

		return [
			'sessions' => $sessions,
			'engagedSessions' => $engaged,
		];
	}
}
