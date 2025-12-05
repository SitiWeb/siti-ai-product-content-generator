<?php

interface Groq_AI_Provider_Interface {
	public function get_key();

	public function get_label();

	public function get_default_model();

	public function get_available_models();

	public function get_option_key();

	public function generate_content( array $args );

	public function supports_live_models();

	public function fetch_live_models( $api_key );

	public function supports_response_format();
}
