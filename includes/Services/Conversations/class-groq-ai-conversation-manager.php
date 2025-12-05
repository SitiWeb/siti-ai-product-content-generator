<?php

/**
 * Beheert conversatie-ID's per provider + context-hash.
 *
 * Verplaatst vanuit groq-ai-product-text.php:
 * - ensure_conversation_id()
 * - get/save_conversation_states()
 * - get_context_hash()
 */
class Groq_AI_Conversation_Manager {
	/** @var string */
	private $option_key;

	public function __construct( $option_key ) {
		$this->option_key = $option_key;
	}

	/**
	 * Retourneert of creëert een conversatie-ID.
	 *
	 * @param string $provider_key
	 * @param string $store_context
	 * @return string
	 */
	public function ensure_id( $provider_key, $store_context ) {
		$states       = $this->get_states();
		$context_hash = $this->get_context_hash( $store_context );

		if ( isset( $states[ $provider_key ]['hash'], $states[ $provider_key ]['id'] ) && $states[ $provider_key ]['hash'] === $context_hash ) {
			return $states[ $provider_key ]['id'];
		}

		$conversation_id         = wp_generate_uuid4();
		$states[ $provider_key ] = [
			'hash' => $context_hash,
			'id'   => $conversation_id,
		];
		$this->save_states( $states );

		return $conversation_id;
	}

	/**
	 * @return array
	 */
	private function get_states() {
		$states = get_option( $this->option_key, [] );

		return is_array( $states ) ? $states : [];
	}

	/**
	 * @param array $states
	 * @return void
	 */
	private function save_states( $states ) {
		update_option( $this->option_key, $states, false );
	}

	/**
	 * @param string $store_context
	 * @return string
	 */
	private function get_context_hash( $store_context ) {
		return md5( wp_json_encode( trim( (string) $store_context ) ) );
	}
}
