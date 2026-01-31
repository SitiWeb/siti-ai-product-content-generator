<?php

use PHPUnit\Framework\TestCase;

class ModelExclusionsTest extends TestCase {
	public function test_ensure_allowed_blocks_excluded_model() {
		$blocked = Groq_AI_Model_Exclusions::ensure_allowed( 'groq', 'whisper-large-v3' );
		$this->assertSame( '', $blocked );
	}

	public function test_filter_models_removes_excluded_entries() {
		$models = [ 'llama3-70b-8192', 'whisper-large-v3', 'mixtral-8x7b-32768' ];
		$filtered = Groq_AI_Model_Exclusions::filter_models( 'groq', $models );

		$this->assertSame( [ 'llama3-70b-8192', 'mixtral-8x7b-32768' ], $filtered );
	}
}
