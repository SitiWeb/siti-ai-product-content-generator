<?php

class Groq_AI_Model_Service {
	public function get_selected_model( Groq_AI_Provider_Interface $provider, $settings ) {
		$provider_key = $provider->get_key();
		$model        = ! empty( $settings['model'] ) ? $settings['model'] : '';
		$model        = Groq_AI_Model_Exclusions::ensure_allowed( $provider_key, $model );

		if ( '' === $model ) {
			$default = Groq_AI_Model_Exclusions::ensure_allowed( $provider_key, $provider->get_default_model() );
			if ( '' !== $default ) {
				return $default;
			}

			$available = Groq_AI_Model_Exclusions::filter_models( $provider_key, $provider->get_available_models() );
			if ( ! empty( $available ) ) {
				return $available[0];
			}
		}

		return $model;
	}

	public function get_cached_models_for_provider( $provider ) {
		$provider = sanitize_key( (string) $provider );
		$cache    = $this->get_models_cache();

		return isset( $cache[ $provider ] ) ? $cache[ $provider ] : [];
	}

	public function update_cached_models_for_provider( $provider, $models ) {
		$provider = sanitize_key( (string) $provider );
		$models   = $this->sanitize_models_list( $models );

		$cache = $this->get_models_cache();
		$cache[ $provider ] = $models;

		update_option( Groq_AI_Product_Text_Plugin::MODELS_CACHE_OPTION_KEY, $cache );

		return $models;
	}

	private function get_models_cache() {
		$cache = get_option( Groq_AI_Product_Text_Plugin::MODELS_CACHE_OPTION_KEY, [] );

		if ( ! is_array( $cache ) ) {
			$cache = [];
		}

		foreach ( $cache as $provider => $models ) {
			$cache[ $provider ] = $this->sanitize_models_list( $models );
		}

		return $cache;
	}

	private function sanitize_models_list( $models ) {
		if ( ! is_array( $models ) ) {
			return [];
		}

		$models = array_map( 'sanitize_text_field', $models );
		$models = array_filter(
			$models,
			function ( $model ) {
				return '' !== $model;
			}
		);

		$models = array_values( array_unique( $models ) );

		if ( ! empty( $models ) ) {
			sort( $models, SORT_NATURAL | SORT_FLAG_CASE );
		}

		return $models;
	}
}
