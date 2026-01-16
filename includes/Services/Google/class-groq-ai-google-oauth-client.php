<?php

class Groq_AI_Google_OAuth_Client {
	/**
	 * @param array $settings
	 * @return string|WP_Error
	 */
	public function get_access_token( $settings ) {
		$client_id     = isset( $settings['google_oauth_client_id'] ) ? trim( (string) $settings['google_oauth_client_id'] ) : '';
		$client_secret = isset( $settings['google_oauth_client_secret'] ) ? trim( (string) $settings['google_oauth_client_secret'] ) : '';
		$refresh_token = isset( $settings['google_oauth_refresh_token'] ) ? trim( (string) $settings['google_oauth_refresh_token'] ) : '';

		if ( '' === $client_id || '' === $client_secret || '' === $refresh_token ) {
			return new WP_Error( 'groq_ai_google_oauth_missing', __( 'Google OAuth is niet (volledig) geconfigureerd.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$cache_key = 'groq_ai_google_access_token_' . md5( $client_id . '|' . $refresh_token );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			[
				'timeout' => 20,
				'headers' => [
					'Content-Type' => 'application/x-www-form-urlencoded',
				],
				'body' => [
					'client_id' => $client_id,
					'client_secret' => $client_secret,
					'refresh_token' => $refresh_token,
					'grant_type' => 'refresh_token',
				],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( (string) $body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			return new WP_Error( 'groq_ai_google_oauth_refresh_failed', __( 'Google token refresh mislukt.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$access_token = isset( $data['access_token'] ) ? sanitize_text_field( (string) $data['access_token'] ) : '';
		$expires_in   = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 0;

		if ( '' === $access_token ) {
			return new WP_Error( 'groq_ai_google_oauth_refresh_failed', __( 'Geen access token ontvangen van Google.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$ttl = max( 60, $expires_in - 60 );
		set_transient( $cache_key, $access_token, $ttl );

		return $access_token;
	}

	/**
	 * Diagnostics helper: returns scopes for a given access token.
	 *
	 * @param string $access_token
	 * @return array|WP_Error { 'scope' => string, 'expires_in' => int }
	 */
	public function get_access_token_info( $access_token ) {
		$access_token = trim( (string) $access_token );
		if ( '' === $access_token ) {
			return new WP_Error( 'groq_ai_google_tokeninfo_missing', __( 'Geen access token om te inspecteren.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$response = wp_remote_get(
			add_query_arg( [ 'access_token' => $access_token ], 'https://oauth2.googleapis.com/tokeninfo' ),
			[ 'timeout' => 15 ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( (string) $body, true );

		if ( 200 !== $status_code || ! is_array( $data ) ) {
			return new WP_Error( 'groq_ai_google_tokeninfo_failed', __( 'Kon tokeninfo niet ophalen.', GROQ_AI_PRODUCT_TEXT_DOMAIN ) );
		}

		$scope      = isset( $data['scope'] ) ? trim( (string) $data['scope'] ) : '';
		$expires_in = isset( $data['expires_in'] ) ? absint( $data['expires_in'] ) : 0;

		return [
			'scope' => $scope,
			'expires_in' => $expires_in,
		];
	}
}
