<?php

class Groq_AI_Provider_Groq extends Groq_AI_Abstract_OpenAI_Provider {
	public function get_key() {
		return 'groq';
	}

	public function get_label() {
		return __( 'Groq', 'groq-ai-product-text' );
	}

	public function get_default_model() {
		return 'llama3-70b-8192';
	}

	public function get_available_models() {
		return [
			'llama3-70b-8192',
			'llama3-8b-8192',
			'mixtral-8x7b-32768',
			'gemma-7b-it',
		];
	}

	public function get_option_key() {
		return 'groq_api_key';
	}

	protected function get_endpoint() {
		return 'https://api.groq.com/openai/v1/chat/completions';
	}

	protected function get_models_endpoint() {
		return 'https://api.groq.com/openai/v1/models';
	}
}
