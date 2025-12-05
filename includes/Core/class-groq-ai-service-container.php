<?php

/**
 * Lightweight container voor Groq AI plugin services.
 *
 * Doel:
 * - Centraliseren van service creatie en dependency sharing.
 * - Mogelijke vervanging voor de huidige singleton/inline instanties in groq-ai-product-text.php.
 */
class Groq_AI_Service_Container {
	/** @var array<string,mixed> */
	private $services = [];

	/**
	 * Registreer een service factory.
	 *
	 * @param string   $key
	 * @param callable $factory
	 */
	public function set( $key, callable $factory ) {
		$this->services[ $key ] = $factory;
	}

	/**
	 * Haal een service op en initialiseer deze lazy.
	 *
	 * @param string $key
	 * @return mixed
	 */
	public function get( $key ) {
		if ( ! isset( $this->services[ $key ] ) ) {
			return null;
		}

		if ( is_callable( $this->services[ $key ] ) ) {
			$this->services[ $key ] = call_user_func( $this->services[ $key ], $this );
		}

		return $this->services[ $key ];
	}
}
