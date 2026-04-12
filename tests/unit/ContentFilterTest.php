<?php
/**
 * Unit tests for Peptide_News_Content_Filter.
 *
 * @since 1.3.0
 */

use PHPUnit\Framework\TestCase;

class ContentFilterTest extends TestCase {

	/**
	 * Helper: build a minimal article array for testing.
	 *
	 * @param array $overrides Key-value overrides.
	 * @return array
	 */
	private function make_article( $overrides = array() ) {
		return array_merge( array(
			'source'        => 'example.com',
			'source_url'    => 'https://example.com/article-123',
			'title'         => 'New Peptide Research Shows Promise for Cancer Treatment',
			'excerpt'       => 'Researchers at MIT have discovered a novel peptide compound.',
			'content'       => '<p>A team of researchers at MIT has identified a new class of peptide compounds that show significant anti-tumor activity in preclinical trials.</p>',
			'author'        => 'Jane Doe',
			'thumbnail_url' => 'https://example.com/thumb.jpg',
			'published_at'  => '2026-04-01 10:00:00',
			'categories'    => 'Research, Oncology',
			'tags'          => '',
			'language'      => 'en',
		), $overrides );
	}

	// =============================================
	// evaluate_article() — Domain blocking tests
	// =============================================

	public function test_blocks_article_from_blocked_domain() {
		$article = $this->make_article( array(
			'source'     => 'prnewswire.com',
			'source_url' => 'https://www.prnewswire.com/news/peptide-launch',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			Peptide_News_Content_Filter::get_title_keywords(),
			Peptide_News_Content_Filter::get_body_keywords(),
			Peptide_News_Content_Filter::get_blocked_domains(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
		$this->assertStringContainsString( 'blocked_domain', $result['rule'] );
	}

	public function test_blocks_article_with_blocked_domain_in_url() {
		$article = $this->make_article( array(
			'source'     => 'news.globenewswire.com',
			'source_url' => 'https://news.globenewswire.com/release/2026/peptide',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array( 'globenewswire.com' ),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	public function test_allows_article_from_clean_domain() {
		$article = $this->make_article( array(
			'source'     => 'nature.com',
			'source_url' => 'https://nature.com/articles/peptide-study',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array( 'prnewswire.com' ),
			2
		);

		$this->assertSame( 'clean', $result['verdict'] );
	}

	// =============================================
	// evaluate_article() — Title keyword tests
	// =============================================

	public function test_blocks_article_with_press_release_in_title() {
		$article = $this->make_article( array(
			'title' => 'Press Release: BioTech Corp Announces New Peptide Drug',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'press release' ),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
		$this->assertStringContainsString( 'title_keyword', $result['rule'] );
	}

	public function test_blocks_article_with_sponsored_in_title() {
		$article = $this->make_article( array(
			'title' => 'Sponsored: The Future of Peptide Therapeutics',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'sponsored' ),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	public function test_title_keyword_match_is_case_insensitive() {
		$article = $this->make_article( array(
			'title' => 'PRESS RELEASE: Major Discovery in Peptide Science',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'press release' ),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	public function test_allows_article_with_clean_title() {
		$article = $this->make_article( array(
			'title' => 'Scientists Discover Novel Peptide for Treating Alzheimer\'s',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'press release', 'sponsored', 'advertisement' ),
			array(),
			array(),
			2
		);

		$this->assertNotSame( 'promotional', $result['verdict'] );
	}

	// =============================================
	// evaluate_article() — Body keyword tests
	// =============================================

	public function test_blocks_article_exceeding_body_keyword_threshold() {
		$article = $this->make_article( array(
			'content' => '<p>For immediate release. Media contact: PR Team. About the company: We are a leader in peptide research. Forward-looking statements apply.</p>',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array( 'for immediate release', 'media contact', 'about the company', 'forward-looking statements' ),
			array(),
			2 // threshold = 2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
		$this->assertStringContainsString( 'body_keywords', $result['rule'] );
	}

	public function test_borderline_when_body_matches_below_threshold() {
		$article = $this->make_article( array(
			'content' => '<p>This is a real article with some media contact info at the end.</p>',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array( 'media contact', 'for immediate release', 'about the company' ),
			array(),
			2
		);

		$this->assertSame( 'borderline', $result['verdict'] );
	}

	public function test_clean_when_no_body_keywords_match() {
		$article = $this->make_article( array(
			'content' => '<p>A rigorous study published in Nature Chemistry demonstrates that cyclic peptides can target previously undruggable protein-protein interactions.</p>',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array( 'for immediate release', 'media contact', 'forward-looking statements' ),
			array(),
			2
		);

		$this->assertSame( 'clean', $result['verdict'] );
	}

	public function test_strict_sensitivity_blocks_with_single_body_match() {
		$article = $this->make_article( array(
			'content' => '<p>Great peptide research. Forward-looking statements may apply.</p>',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array( 'forward-looking statements' ),
			array(),
			1 // strict threshold
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	public function test_lenient_sensitivity_requires_more_body_matches() {
		$article = $this->make_article( array(
			'content' => '<p>For immediate release. Media contact details below.</p>',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array( 'for immediate release', 'media contact', 'forward-looking statements' ),
			array(),
			3 // lenient threshold
		);

		// Only 2 matches but threshold is 3, so borderline not promotional.
		$this->assertSame( 'borderline', $result['verdict'] );
	}

	// =============================================
	// evaluate_article() — URL path tests
	// =============================================

	public function test_blocks_article_with_press_release_url_path() {
		$article = $this->make_article( array(
			'source_url' => 'https://example.com/press-releases/peptide-announcement',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
		$this->assertStringContainsString( 'url_path', $result['rule'] );
	}

	public function test_blocks_article_with_sponsored_url_path() {
		$article = $this->make_article( array(
			'source_url' => 'https://example.com/sponsored/peptide-overview',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	// =============================================
	// evaluate_article() — Priority / rule ordering
	// =============================================

	public function test_domain_block_takes_priority_over_clean_title() {
		$article = $this->make_article( array(
			'title'      => 'Legitimate Sounding Peptide Research Title',
			'source'     => 'businesswire.com',
			'source_url' => 'https://www.businesswire.com/news/peptide-research',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array( 'businesswire.com' ),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
		$this->assertStringContainsString( 'blocked_domain', $result['rule'] );
	}

	// =============================================
	// filter_articles() — Batch filtering
	// =============================================

	public function test_filter_articles_removes_promotional_keeps_clean() {
		$articles = array(
			$this->make_article( array(
				'title' => 'Genuine Peptide Research Breakthrough',
			) ),
			$this->make_article( array(
				'title'      => 'Press Release: BioTech Announces Product',
				'source_url' => 'https://prnewswire.com/release/123',
				'source'     => 'prnewswire.com',
			) ),
			$this->make_article( array(
				'title' => 'Another Real Study on Peptide Folding',
			) ),
		);

		$filtered = Peptide_News_Content_Filter::filter_articles( $articles );

		$this->assertCount( 2, $filtered );
		$this->assertSame( 'Genuine Peptide Research Breakthrough', $filtered[0]['title'] );
		$this->assertSame( 'Another Real Study on Peptide Folding', $filtered[1]['title'] );
	}

	public function test_filter_articles_returns_all_when_disabled() {
		// Temporarily set filter to disabled.
		global $_test_options;
		$_test_options['peptide_news_filter_enabled'] = 0;

		$articles = array(
			$this->make_article( array( 'source' => 'prnewswire.com' ) ),
			$this->make_article( array( 'title' => 'Press Release: Something' ) ),
		);

		$filtered = Peptide_News_Content_Filter::filter_articles( $articles );

		$this->assertCount( 2, $filtered );

		// Restore.
		$_test_options['peptide_news_filter_enabled'] = 1;
	}

	public function test_filter_articles_skips_malformed_entries() {
		$articles = array(
			$this->make_article( array( 'title' => 'Valid Article' ) ),
			'not_an_array',
			array( 'title' => 'Missing source_url' ),
			$this->make_article( array( 'title' => 'Another Valid Article' ) ),
		);

		$filtered = Peptide_News_Content_Filter::filter_articles( $articles );

		// Only the two valid articles should survive.
		$this->assertCount( 2, $filtered );
		$this->assertSame( 'Valid Article', $filtered[0]['title'] );
		$this->assertSame( 'Another Valid Article', $filtered[1]['title'] );
	}

	public function test_filter_articles_handles_empty_array() {
		$filtered = Peptide_News_Content_Filter::filter_articles( array() );

		$this->assertIsArray( $filtered );
		$this->assertEmpty( $filtered );
	}

	// =============================================
	// Keyword/domain getter tests
	// =============================================

	public function test_get_title_keywords_returns_defaults_when_empty() {
		global $_test_options;
		$_test_options['peptide_news_filter_title_keywords'] = '';

		$keywords = Peptide_News_Content_Filter::get_title_keywords();

		$this->assertNotEmpty( $keywords );
		$this->assertContains( 'press release', $keywords );
		$this->assertContains( 'sponsored', $keywords );
	}

	public function test_get_title_keywords_returns_custom_when_set() {
		global $_test_options;
		$_test_options['peptide_news_filter_title_keywords'] = "custom keyword\nanother phrase";

		$keywords = Peptide_News_Content_Filter::get_title_keywords();

		$this->assertCount( 2, $keywords );
		$this->assertContains( 'custom keyword', $keywords );
		$this->assertContains( 'another phrase', $keywords );

		// Restore.
		$_test_options['peptide_news_filter_title_keywords'] = '';
	}

	public function test_get_blocked_domains_returns_defaults_when_empty() {
		global $_test_options;
		$_test_options['peptide_news_filter_blocked_domains'] = '';

		$domains = Peptide_News_Content_Filter::get_blocked_domains();

		$this->assertNotEmpty( $domains );
		$this->assertContains( 'prnewswire.com', $domains );
		$this->assertContains( 'businesswire.com', $domains );
	}

	public function test_get_body_keywords_returns_defaults_when_empty() {
		global $_test_options;
		$_test_options['peptide_news_filter_body_keywords'] = '';

		$keywords = Peptide_News_Content_Filter::get_body_keywords();

		$this->assertNotEmpty( $keywords );
		$this->assertContains( 'for immediate release', $keywords );
	}

	// =============================================
	// Classification prompt test
	// =============================================

	public function test_build_classification_prompt_contains_article_details() {
		// Use reflection to test private method.
		$method = new ReflectionMethod( 'Peptide_News_Content_Filter', 'build_classification_prompt' );
		$method->setAccessible( true );

		$article = $this->make_article( array(
			'title'   => 'Test Peptide Article',
			'excerpt' => 'This is a test excerpt.',
			'content' => '<p>This is the content body of the article.</p>',
			'source'  => 'example.com',
		) );

		$prompt = $method->invoke( null, $article );

		$this->assertStringContainsString( 'EDITORIAL', $prompt );
		$this->assertStringContainsString( 'PROMOTIONAL', $prompt );
		$this->assertStringContainsString( 'Test Peptide Article', $prompt );
		$this->assertStringContainsString( 'example.com', $prompt );
		// Verify structured delimiters are present for injection mitigation.
		$this->assertStringContainsString( '<article_to_classify>', $prompt );
		$this->assertStringContainsString( '</article_to_classify>', $prompt );
		$this->assertStringContainsString( '<title>', $prompt );
	}

	// =============================================
	// Edge cases
	// =============================================

	public function test_handles_article_with_empty_fields() {
		$article = $this->make_article( array(
			'title'      => '',
			'excerpt'    => '',
			'content'    => '',
			'source'     => '',
			'source_url' => '',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'press release' ),
			array( 'media contact' ),
			array( 'prnewswire.com' ),
			2
		);

		$this->assertSame( 'clean', $result['verdict'] );
	}

	public function test_handles_article_with_null_fields() {
		$article = array(
			'title'      => null,
			'excerpt'    => null,
			'content'    => null,
			'source'     => null,
			'source_url' => null,
		);

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'press release' ),
			array( 'media contact' ),
			array( 'prnewswire.com' ),
			2
		);

		$this->assertSame( 'clean', $result['verdict'] );
	}

	public function test_empty_keyword_lists_do_not_cause_errors() {
		$article = $this->make_article();

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array(),
			2
		);

		$this->assertSame( 'clean', $result['verdict'] );
	}

	public function test_keyword_list_with_blank_entries_is_handled() {
		$article = $this->make_article( array(
			'title' => 'Press Release: New Peptide',
		) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( '', '   ', 'press release', '' ),
			array(),
			array(),
			2
		);

		$this->assertSame( 'promotional', $result['verdict'] );
	}

	// =============================================
	// Score tracking
	// =============================================

	public function test_domain_block_returns_high_score() {
		$article = $this->make_article( array( 'source' => 'prnewswire.com' ) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array(),
			array(),
			array( 'prnewswire.com' ),
			2
		);

		$this->assertSame( 100, $result['score'] );
	}

	public function test_title_keyword_returns_significant_score() {
		$article = $this->make_article( array( 'title' => 'Sponsored content about peptides' ) );

		$result = Peptide_News_Content_Filter::evaluate_article(
			$article,
			array( 'sponsored' ),
			array(),
			array(),
			2
		);

		$this->assertGreaterThanOrEqual( 50, $result['score'] );
	}
}
