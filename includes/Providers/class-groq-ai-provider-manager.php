<?php

class Groq_AI_Provider_Manager {
	/** @var Groq_AI_Provider_Interface[] */
	private $providers = [];

	public function __construct() {
		$this->register_provider( new Groq_AI_Provider_Groq() );
		$this->register_provider( new Groq_AI_Provider_OpenAI() );
		$this->register_provider( new Groq_AI_Provider_Google() );
	}

	public function register_provider( Groq_AI_Provider_Interface $provider ) {
		$this->providers[ $provider->get_key() ] = $provider;
	}

	public function get_providers() {
		return $this->providers;
	}

	public function get_provider( $key ) {
		return isset( $this->providers[ $key ] ) ? $this->providers[ $key ] : null;
	}
}
