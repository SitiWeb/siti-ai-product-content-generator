<?php

final class Groq_AI_Model_Exclusions {
	private const DEFAULT_EXCLUSIONS = [
		'groq'   => [
			'playai-tts-arabic',
			'moonshotai/kimi-k2-instruct',
			'meta-llama/llama-prompt-guard-2-22m',
			'groq/compound-mini',
			'meta-llama/llama-guard-4-12b',
			'openai/gpt-oss-20b',
			'groq/compound',
			'openai/gpt-oss-safeguard-20b',
			'whisper-large-v3-turbo',
			'meta-llama/llama-4-scout-17b-16e-instruct',
			'allam-2-7b',
			'playai-tts',
			'moonshotai/kimi-k2-instruct-0905',
			'whisper-large-v3',
			'meta-llama/llama-prompt-guard-2-86m',
		],
		'openai' => [],
		'google' => [
			'embedding-gecko-001',
			'embedding-001',
			'text-embedding-004',
			'gemini-embedding-exp-03-07',
			'gemini-embedding-exp',
			'gemini-embedding-001',
			'gemini-2.5-flash-image-preview',
			'gemini-2.5-flash-image',
			'gemini-2.5-flash-preview-tts',
			'gemini-2.5-pro-preview-tts',
			'gemini-2.5-flash-native-audio-latest',
			'gemini-2.5-flash-native-audio-preview-09-2025',
			'gemini-2.5-computer-use-preview-10-2025',
			'gemini-3-pro-image-preview',
			'nano-banana-pro-preview',
			'gemini-robotics-er-1.5-preview',
			'deep-research-pro-preview-12-2025',
			'aqa',
			'imagen-4.0-generate-preview-06-06',
			'imagen-4.0-ultra-generate-preview-06-06',
			'imagen-4.0-generate-001',
			'imagen-4.0-ultra-generate-001',
			'imagen-4.0-fast-generate-001',
			'veo-2.0-generate-001',
			'veo-3.0-generate-001',
			'veo-3.0-fast-generate-001',
			'veo-3.1-generate-preview',
			'veo-3.1-fast-generate-preview',
		],
	];

	/**
	 * Geeft de volledige lijst met uitgesloten modellen terug, gegroepeerd per aanbieder.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_all() {
		$list = apply_filters( 'groq_ai_model_exclusions', self::DEFAULT_EXCLUSIONS );

		if ( ! is_array( $list ) ) {
			$list = [];
		}

		$normalized = [];
		foreach ( $list as $provider => $models ) {
			$normalized[ self::normalize_provider( $provider ) ] = self::normalize_models_list( $models );
		}

		return $normalized;
	}

	/**
	 * @param string $provider
	 * @return string[]
	 */
	public static function get_for_provider( $provider ) {
		$provider = self::normalize_provider( $provider );
		$list     = self::get_all();

		return isset( $list[ $provider ] ) ? $list[ $provider ] : [];
	}

	public static function is_excluded( $provider, $model ) {
		if ( '' === $model ) {
			return false;
		}

		$model    = sanitize_text_field( $model );
		$provider = self::normalize_provider( $provider );
		$list     = self::get_for_provider( $provider );

		return in_array( $model, $list, true );
	}

	/**
	 * @param string $provider
	 * @param string $model
	 * @return string
	 */
	public static function ensure_allowed( $provider, $model ) {
		if ( self::is_excluded( $provider, $model ) ) {
			return '';
		}

		return $model;
	}

	/**
	 * @param string $provider
	 * @param array  $models
	 * @return array
	 */
	public static function filter_models( $provider, $models ) {
		if ( ! is_array( $models ) ) {
			return [];
		}

		$provider = self::normalize_provider( $provider );

		return array_values(
			array_filter(
				array_map(
					'sanitize_text_field',
					$models
				),
				function ( $model ) use ( $provider ) {
					return ! self::is_excluded( $provider, $model );
				}
			)
		);
	}

	private static function normalize_provider( $provider ) {
		return sanitize_key( (string) $provider );
	}

	private static function normalize_models_list( $models ) {
		if ( ! is_array( $models ) ) {
			$models = [];
		}

		$models = array_map( 'sanitize_text_field', $models );
		$models = array_filter(
			$models,
			function ( $model ) {
				return '' !== $model;
			}
		);

		return array_values( array_unique( $models ) );
	}
}
