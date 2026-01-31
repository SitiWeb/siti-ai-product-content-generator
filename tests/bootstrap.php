<?php

if ( ! defined( 'GROQ_AI_PRODUCT_TEXT_DOMAIN' ) ) {
	define( 'GROQ_AI_PRODUCT_TEXT_DOMAIN', 'siti-ai-product-content-generator' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $message;

		public function __construct( $code = '', $message = '' ) {
			$this->message = (string) $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = [] ) {
		if ( is_object( $args ) ) {
			$args = get_object_vars( $args );
		}
		if ( ! is_array( $args ) ) {
			$args = [];
		}
		return array_merge( $defaults, $args );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $text ) {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $text ) {
		return trim( (string) $text );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $number ) {
		return abs( (int) $number );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_filters'][ $tag ][ $priority ][] = [
			'callback' => $callback,
			'accepted_args' => (int) $accepted_args,
		];
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		if ( empty( $GLOBALS['wp_filters'][ $tag ] ) ) {
			return $value;
		}
		ksort( $GLOBALS['wp_filters'][ $tag ] );
		foreach ( $GLOBALS['wp_filters'][ $tag ] as $callbacks ) {
			foreach ( $callbacks as $filter ) {
				$accepted = isset( $filter['accepted_args'] ) ? (int) $filter['accepted_args'] : 1;
				$call_args = array_slice( $args, 0, max( 1, $accepted ) );
				$call_args[0] = $value;
				$value = call_user_func_array( $filter['callback'], $call_args );
				$args[0] = $value;
			}
		}
		return $value;
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) {
		return (string) $content;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( (string) $text );
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = [] ) {
		$GLOBALS['wp_term_updates'][] = [
			'term_id' => (int) $term_id,
			'taxonomy' => (string) $taxonomy,
			'args' => $args,
		];
		return [ 'term_id' => (int) $term_id ];
	}
}

if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $meta_key, $meta_value ) {
		$term_id = (int) $term_id;
		if ( ! isset( $GLOBALS['wp_term_meta_updates'][ $term_id ] ) ) {
			$GLOBALS['wp_term_meta_updates'][ $term_id ] = [];
		}
		$GLOBALS['wp_term_meta_updates'][ $term_id ][ (string) $meta_key ] = $meta_value;
		return true;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		if ( is_string( $args ) ) {
			return $url;
		}
		$query = http_build_query( (array) $args );
		$separator = strpos( $url, '?' ) === false ? '?' : '&';
		return $url . $separator . $query;
	}
}

if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( $url, $args = [] ) {
		$GLOBALS['wp_last_http_request'] = [
			'url' => $url,
			'args' => $args,
		];

		$body = json_encode(
			[
				'choices' => [
					[
						'message' => [
							'content' => 'ok',
						],
						'finish_reason' => 'stop',
					],
				],
				'usage' => [
					'prompt_tokens' => 10,
					'completion_tokens' => 20,
					'total_tokens' => 30,
				],
				'candidates' => [
					[
						'content' => [
							'parts' => [
								[ 'text' => 'ok' ],
							],
						],
						'finishReason' => 'STOP',
					],
				],
				'usageMetadata' => [
					'promptTokenCount' => 10,
					'candidatesTokenCount' => 20,
					'totalTokenCount' => 30,
				],
			]
		);

		return [
			'body' => $body,
			'response' => [ 'code' => 200 ],
		];
	}
}

if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = [] ) {
		$GLOBALS['wp_last_http_request'] = [
			'url' => $url,
			'args' => $args,
		];

		return [
			'body' => json_encode( [ 'data' => [], 'models' => [] ] ),
			'response' => [ 'code' => 200 ],
		];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return isset( $response['body'] ) ? $response['body'] : '';
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return isset( $GLOBALS['wp_options'][ $key ] ) ? $GLOBALS['wp_options'][ $key ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['wp_options'][ $key ] = $value;
		return true;
	}
}

require_once __DIR__ . '/../includes/Core/class-groq-ai-model-exclusions.php';
require_once __DIR__ . '/../includes/Contracts/interface-groq-ai-provider.php';
require_once __DIR__ . '/../includes/Providers/class-groq-ai-abstract-openai-provider.php';
require_once __DIR__ . '/../includes/Providers/class-groq-ai-provider-groq.php';
require_once __DIR__ . '/../includes/Providers/class-groq-ai-provider-openai.php';
require_once __DIR__ . '/../includes/Providers/class-groq-ai-provider-google.php';
require_once __DIR__ . '/../includes/Providers/class-groq-ai-provider-manager.php';
require_once __DIR__ . '/../includes/Services/Settings/class-groq-ai-settings-manager.php';
require_once __DIR__ . '/../includes/Core/class-groq-ai-ajax-controller.php';
