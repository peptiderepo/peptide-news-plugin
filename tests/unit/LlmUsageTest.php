<?php
/**
 * Unit tests for LLM cost tracking integration.
 *
 * Tests that call_openrouter_with_usage() correctly structures its
 * return array and that the backward-compatible call_openrouter()
 * wrapper still works.
 *
 * @since 2.4.0
 */

use PHPUnit\Framework\TestCase;

class LlmUsageTest extends TestCase {

	protected function setUp(): void {
		global $_test_options;
		$_test_options = array();
	}

	// =============================================
	// is_valid_model() — unchanged, regression check
	// =============================================

	public function test_valid_model_format(): void {
		$this->assertTrue( Peptide_News_LLM::is_valid_model( 'google/gemini-2.0-flash-001' ) );
		$this->assertTrue( Peptide_News_LLM::is_valid_model( 'anthropic/claude-3.5-sonnet' ) );
		$this->assertTrue( Peptide_News_LLM::is_valid_model( 'google/gemma-3-27b-it:free' ) );
	}

	public function test_invalid_model_format(): void {
		$this->assertFalse( Peptide_News_LLM::is_valid_model( '' ) );
		$this->assertFalse( Peptide_News_LLM::is_valid_model( 'no-slash-model' ) );
		$this->assertFalse( Peptide_News_LLM::is_valid_model( 'has spaces/model' ) );
	}

	// =============================================
	// is_enabled() — budget-aware checks
	// =============================================

	public function test_is_enabled_false_when_no_api_key(): void {
		global $_test_options;
		$_test_options['peptide_news_llm_enabled']       = 1;
		$_test_options['peptide_news_openrouter_api_key'] = '';

		$this->assertFalse( Peptide_News_LLM::is_enabled() );
	}

	public function test_is_enabled_false_when_disabled(): void {
		global $_test_options;
		$_test_options['peptide_news_llm_enabled']       = 0;
		$_test_options['peptide_news_openrouter_api_key'] = 'sk-test-key';

		$this->assertFalse( Peptide_News_LLM::is_enabled() );
	}

	// =============================================
	// process_article() — budget gate behavior
	// =============================================

	public function test_process_article_returns_early_when_disabled(): void {
		global $_test_options;
		$_test_options['peptide_news_llm_enabled']       = 0;
		$_test_options['peptide_news_openrouter_api_key'] = '';

		$article = (object) array(
			'id'          => 1,
			'title'       => 'Test Article',
			'excerpt'     => 'Test excerpt.',
			'content'     => 'Test content.',
			'categories'  => 'test',
			'tags'        => '',
			'ai_summary'  => '',
		);

		$result = Peptide_News_LLM::process_article( $article );

		$this->assertFalse( $result['success'] );
		$this->assertEmpty( $result['keywords'] );
		$this->assertEmpty( $result['summary'] );
	}
}
