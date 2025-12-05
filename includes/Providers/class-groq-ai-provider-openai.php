<?php

class Groq_AI_Provider_OpenAI extends Groq_AI_Abstract_OpenAI_Provider {
	public function get_key() {
		return 'openai';
	}

	public function get_label() {
		return __( 'OpenAI', 'groq-ai-product-text' );
	}

	public function get_default_model() {
		return 'gpt-4o-mini';
	}

	public function get_available_models() {
		return [
			'gpt-4o',
			'gpt-4o-mini',
			'gpt-4.1-mini',
			'gpt-3.5-turbo',
		];
	}

	public function get_option_key() {
		return 'openai_api_key';
	}

	protected function get_endpoint() {
		return 'https://api.openai.com/v1/chat/completions';
	}

	protected function get_models_endpoint() {
		return 'https://api.openai.com/v1/models';
	}
}
