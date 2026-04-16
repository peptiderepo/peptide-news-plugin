<?php
declare( strict_types=1 );
/**
 * Prompt construction for LLM-based article analysis.
 *
 * Builds keyword-extraction and summarization prompts from article data.
 * When stored article content is sparse, fetches the source URL to
 * obtain meaningful text for the LLM.
 *
 * Called by Peptide_News_LLM::process_article() before each API call.
 * No external dependencies beyond WordPress HTTP API.
 *
 * @since      2.5.0
 * @see        class-peptide-news-llm.php          Orchestrator that consumes the prompts.
 * @see        class-peptide-news-llm-client.php   HTTP transport that sends them.
 */
class Peptide_News_LLM_Prompt_Builder {

	/**
	 * Build the prompt for keyword extraction.
	 *
	 * Concatenates title, excerpt, and a truncated body (≤ 2 000 chars)
	 * to keep token usage low while giving the model enough context.
	 *
	 * @param object $article DB row with title, excerpt, content.
	 * @return string          Ready-to-send prompt text.
	 */
	public static function keywords( object $article ): string {
		$text = $article->title;
		if ( ! empty( $article->excerpt ) ) {
			$text .= "\n\n" . $article->excerpt;
		}
		if ( ! empty( $article->content ) ) {
			// Limit content to ~2000 chars to keep token usage reasonable.
			$text .= "\n\n" . mb_substr( wp_strip_all_tags( $article->content ), 0, 2000 );
		}

		return "Extract 5-10 relevant keywords or key phrases from this peptide research article. "
			 . "Return ONLY a comma-separated list of keywords, nothing else. No numbering, no explanations.\n\n"
			 . "Article:\n" . $text;
	}

	/**
	 * Build the prompt for article summarization.
	 *
	 * When the article's stored content is sparse (empty or just the title),
	 * attempts to fetch the actual page content from the source URL.
	 *
	 * @param object $article DB row with title, excerpt, content, source_url.
	 * @return string          Ready-to-send prompt text.
	 */
	public static function summary( object $article ): string {
		$title       = trim( $article->title ?? '' );
		$excerpt     = trim( $article->excerpt ?? '' );
		$content     = trim( $article->content ?? '' );
		$content_raw = wp_strip_all_tags( $content );

		// Determine if we have meaningful content beyond just the title.
		$has_real_excerpt = ! empty( $excerpt ) && $excerpt !== $title;
		$has_real_content = mb_strlen( $content_raw ) > 100;

		// If content is sparse, try fetching the article's web page.
		if ( ! $has_real_content && ! empty( $article->source_url ) ) {
			$fetched = self::fetch_article_text( $article->source_url );
			if ( ! empty( $fetched ) ) {
				$content_raw     = $fetched;
				$has_real_content = true;
			}
		}

		$text = $title;
		if ( $has_real_excerpt ) {
			$text .= "\n\n" . $excerpt;
		}
		if ( $has_real_content ) {
			$text .= "\n\n" . mb_substr( $content_raw, 0, 3000 );
		}

		return "Summarize this peptide research article in 3-4 sentences. "
			 . "Be concise, factual, and accessible to a general audience interested in peptide science. "
			 . "Do not include any preamble or labels — just the summary text.\n\n"
			 . "Article:\n" . $text;
	}

	/**
	 * Fetch article text from a URL for summarization when RSS content is sparse.
	 *
	 * Extracts the main body text from the page, stripping navigation,
	 * scripts, styles, and other non-content elements.
	 *
	 * Side effects: one outbound HTTP GET request (10 s timeout).
	 *
	 * @param string $url The article URL.
	 * @return string      Cleaned plain text or empty string on failure.
	 */
	private static function fetch_article_text( string $url ): string {
		$response = wp_remote_get( $url, array(
			'timeout'    => 10,
			'user-agent' => 'Mozilla/5.0 (compatible; PeptideNewsBot/1.0; +https://peptiderepo.com)',
			'headers'    => array( 'Accept' => 'text/html' ),
		) );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );
		if ( empty( $html ) ) {
			return '';
		}

		// Strip elements that don't carry article content.
		$html = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $html );
		$html = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $html );
		$html = preg_replace( '/<nav[^>]*>.*?<\/nav>/is', '', $html );
		$html = preg_replace( '/<header[^>]*>.*?<\/header>/is', '', $html );
		$html = preg_replace( '/<footer[^>]*>.*?<\/footer>/is', '', $html );
		$html = preg_replace( '/<aside[^>]*>.*?<\/aside>/is', '', $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );

		// Try to extract content from <article> or <main> tags first.
		$text = '';
		if ( preg_match( '/<article[^>]*>(.*?)<\/article>/is', $html, $matches ) ) {
			$text = wp_strip_all_tags( $matches[1] );
		} elseif ( preg_match( '/<main[^>]*>(.*?)<\/main>/is', $html, $matches ) ) {
			$text = wp_strip_all_tags( $matches[1] );
		} else {
			$text = wp_strip_all_tags( $html );
		}

		// Collapse whitespace.
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Only return if we got meaningful content.
		if ( mb_strlen( $text ) < 100 ) {
			return '';
		}

		return $text;
	}
}
