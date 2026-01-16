<?php

class Groq_AI_Google_Context_Builder {
	/** @var Groq_AI_Google_Search_Console_Client */
	private $gsc;

	/** @var Groq_AI_Google_Analytics_Data_Client */
	private $ga;

	public function __construct( Groq_AI_Google_Search_Console_Client $gsc, Groq_AI_Google_Analytics_Data_Client $ga ) {
		$this->gsc = $gsc;
		$this->ga  = $ga;
	}

	/**
	 * @param string $existing
	 * @param WP_Term $term
	 * @param array $settings
	 * @return string
	 */
	public function build_term_google_context( $existing, $term, $settings ) {
		if ( ! $term || ! is_object( $term ) ) {
			return (string) $existing;
		}

		$enabled_gsc = ! empty( $settings['google_enable_gsc'] );
		$enabled_ga  = ! empty( $settings['google_enable_ga'] );

		if ( ! $enabled_gsc && ! $enabled_ga ) {
			return (string) $existing;
		}

		$term_id  = isset( $term->term_id ) ? absint( $term->term_id ) : 0;
		$taxonomy = isset( $term->taxonomy ) ? sanitize_key( (string) $term->taxonomy ) : '';

		$range_days = 28;
		$end_date   = gmdate( 'Y-m-d' );
		$start_date = gmdate( 'Y-m-d', time() - ( $range_days * DAY_IN_SECONDS ) );

		$term_link = get_term_link( $term );
		if ( is_wp_error( $term_link ) ) {
			$term_link = '';
		}

		$page_path = '';
		if ( is_string( $term_link ) && '' !== $term_link ) {
			$parts = wp_parse_url( $term_link );
			if ( is_array( $parts ) && isset( $parts['path'] ) ) {
				$page_path = (string) $parts['path'];
			}
		}

		$cache_key = 'groq_ai_google_term_ctx_' . md5( $taxonomy . '|' . $term_id . '|' . $start_date . '|' . $end_date );
		$cached = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return trim( (string) $existing . "\n\n" . $cached );
		}

		$lines = [];
		$lines[] = sprintf(
			/* translators: %d: days */
			__( 'Google data (laatste %d dagen):', GROQ_AI_PRODUCT_TEXT_DOMAIN ),
			$range_days
		);

		if ( $enabled_gsc ) {
			$site_url = isset( $settings['google_gsc_site_url'] ) ? trim( (string) $settings['google_gsc_site_url'] ) : '';
			if ( '' !== $site_url && '' !== $term_link ) {
				$queries = $this->gsc->get_top_queries_for_page( $settings, $site_url, $term_link, $start_date, $end_date, 10 );
				if ( is_wp_error( $queries ) ) {
					$lines[] = __( 'Search Console: kon queries niet ophalen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				} elseif ( empty( $queries ) ) {
					$lines[] = __( 'Search Console: geen query data gevonden voor deze pagina.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				} else {
					$lines[] = __( 'Search Console top zoekopdrachten (query → clicks/impr):', GROQ_AI_PRODUCT_TEXT_DOMAIN );
					foreach ( $queries as $row ) {
						$q = isset( $row['query'] ) ? (string) $row['query'] : '';
						$c = isset( $row['clicks'] ) ? (float) $row['clicks'] : 0.0;
						$i = isset( $row['impressions'] ) ? (float) $row['impressions'] : 0.0;
						if ( '' === $q ) {
							continue;
						}
						$lines[] = sprintf( '- %s → %d/%d', $q, (int) round( $c ), (int) round( $i ) );
					}
				}
			}
		}

		if ( $enabled_ga ) {
			$property_id = isset( $settings['google_ga4_property_id'] ) ? trim( (string) $settings['google_ga4_property_id'] ) : '';
			if ( '' !== $property_id && '' !== $page_path ) {
				$stats = $this->ga->get_sessions_for_landing_page_path( $settings, $property_id, $page_path, $start_date, $end_date );
				if ( is_wp_error( $stats ) ) {
					$lines[] = __( 'Analytics: kon sessies niet ophalen.', GROQ_AI_PRODUCT_TEXT_DOMAIN );
				} else {
					$sessions = isset( $stats['sessions'] ) ? absint( $stats['sessions'] ) : 0;
					$engaged  = isset( $stats['engagedSessions'] ) ? absint( $stats['engagedSessions'] ) : 0;
					$lines[] = sprintf( __( 'Analytics (GA4): sessies ~%1$d, engaged sessies ~%2$d', GROQ_AI_PRODUCT_TEXT_DOMAIN ), $sessions, $engaged );
				}
			}
		}

		// If we only have the header, skip.
		if ( count( $lines ) <= 1 ) {
			return (string) $existing;
		}

		$context = implode( "\n", $lines );
		set_transient( $cache_key, $context, 15 * MINUTE_IN_SECONDS );

		return trim( (string) $existing . "\n\n" . $context );
	}
}
