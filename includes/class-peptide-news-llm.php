<?php
declare( strict_types=1 );
/**
 * LLM integration via OpenRouter for article analysis.
 *
 * Provides keyword extraction and article summarization
 * using configurable models through the OpenRouter API.
 *
 * @since 1.1.0
 */
class Peptide_News_LLM {

	/** @var int Maximum retries for rate-limited requests. */
	const MAX_RETRIES = 1;

	/** @var int Maximum elapsed seconds before stopping a batch. */
	const BATCH_TIMEOUT = 120;

	/**
	 * Get the OpenRouter API URL from options with a hardcoded default.
	 *
	 * @return string
	 */
	private static function get_api_url(): string {
		return get_option( 'peptide_news_llm_api_url', 'https://openrouter.ai/api/v1/chat/completions' );
	}

	/**
	 * Check whether LLM processing is enabled and configured.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$api_key = self::get_api_key();
		return ! empty( $api_key ) && (bool) get_option( 'peptide_news_llm_enabled', 0 );
	}

	/**
	 * Retrieve and decrypt the OpenRouter API key.
	 *
	 * Handles both encrypted (AES-256-CBC) and legacy plaintext values
	 * transparently via Peptide_News_Encryption::decrypt().
	 *
	 * @return string Decrypted API key or empty string.
	 */
	private static function get_api_key(): string {
		$raw = get_option( 'peptide_news_openrouter_api_key', '' );
		if ( empty( $raw ) ) {
			return '';
		}
		if ( class_exists( 'Peptide_News_Encryption' ) ) {
			return Peptide_News_Encryption::decrypt( $raw );
		}
		return $raw;
	}

	/**
	 * Validate an OpenRouter model ID format.
	 *
	 * Accepts patterns like: provider/model-name, provider/model-name:version
	 *
	 * @param string $model
	 * @return bool
	 */
	public static function is_valid_model( string $model ): bool {
		return (bool) preg_match( '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9._-]+(:[a-zA-Z0-9._-]+)?$/', $model );
	}

	/**
	 * Process a single article: extract keywords and generate a summary.
	 *
	 * Skips articles that already have both tags and ai_summary unless $force is true.
	 * Checks budget before each API call and logs costs via Cost_Tracker.
	 *
	 * @param object $article  Database row with id, title, excerpt, content, categories.
	 * @param bool   $force    Re-process even if already analyzed.
	 * @return array            Results with 'keywords', 'summary', and 'success' keys.
	 */
	public static function process_article( object $article, bool $force = false ): array {
		if ( ! self::is_enabled() ) {
			return array( 'keywords' => '', 'summary' => '', 'success' => false );
		}

		$results = array(
			'keywords' => '',
			'summary'  => '',
			'success'  => false,
			'errors'   => array(),
		);

		$api_key = self::get_api_key();
		$any_success = false;
		$article_id = (int) $article->id;

		// --- Keyword extraction ---
		$keywords_model = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
		$need_keywords  = $force || empty( $article->tags );

		if ( $need_keywords && self::is_valid_model( $keywords_model ) ) {
			// Budget gate: block if monthly limit reached.
			if ( class_exists( 'Peptide_News_Cost_Tracker' ) && Peptide_News_Cost_Tracker::is_budget_exceeded() ) {
				$results['errors'][] = 'Monthly LLM budget exceeded — keywords skipped.';
				Peptide_News_Logger::warning( 'Budget exceeded, skipping keywords for article #' . $article_id, 'cost' );
			} else {
				$keywords_prompt = self::build_keywords_prompt( $article );
				$keywords_response = self::call_openrouter_with_usage( $api_key, $keywords_model, $keywords_prompt );

				if ( ! is_wp_error( $keywords_response['content'] ) ) {
					$results['keywords'] = self::sanitize_keywords( $keywords_response['content'] );
					$any_success = true;
					Peptide_News_Logger::debug( 'Keywords extracted for article #' . $article_id, 'llm' );
				} else {
					$err_msg = 'Keywords (' . $keywords_model . '): ' . $keywords_response['content']->get_error_message();
					$results['errors'][] = $err_msg;
					Peptide_News_Logger::error( 'Keyword extraction failed for article #' . $article_id . ': ' . $keywords_response['content']->get_error_message(), 'llm' );
				}

				// Log cost regardless of success — failed calls still consume tokens.
				if ( class_exists( 'Peptide_News_Cost_Tracker' ) && ! empty( $keywords_response['usage'] ) ) {
					Peptide_News_Cost_Tracker::log_api_call(
						$keywords_model,
						'keywords',
						$keywords_response['usage'],
						$article_id,
						$keywords_response['request_id'] ?? '',
						$keywords_response['cost'] ?? 0.0
					);
				}
			}
		} elseif ( $need_keywords ) {
			$results['errors'][] = 'Invalid keywords model ID: ' . $keywords_model;
			Peptide_News_Logger::error( 'Invalid keywords model ID: ' . $keywords_model, 'llm' );
		}

		// --- Summarization ---
		$summary_model = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );
		$need_summary  = $force || empty( $article->ai_summary );

		if ( $need_summary && self::is_valid_model( $summary_model ) ) {
			// Budget gate: re-check in case keyword extraction pushed us over.
			if ( class_exists( 'Peptide_News_Cost_Tracker' ) && Peptide_News_Cost_Tracker::is_budget_exceeded() ) {
				$results['errors'][] = 'Monthly LLM budget exceeded — summary skipped.';
				Peptide_News_Logger::warning( 'Budget exceeded, skipping summary for article #' . $article_id, 'cost' );
			} else {
				$summary_prompt = self::build_summary_prompt( $article );
				$summary_response = self::call_openrouter_with_usage( $api_key, $summary_model, $summary_prompt );

				if ( ! is_wp_error( $summary_response['content'] ) ) {
					// Strip all HTML tags from LLM output to prevent XSS.
					$results['summary'] = sanitize_textarea_field( wp_strip_all_tags( $summary_response['content'] ) );
					$any_success = true;
					Peptide_News_Logger::info( 'AI summary generated for article #' . $article_id . ': ' . mb_substr( $article->title, 0, 60 ), 'llm' );
				} else {
					$err_msg = 'Summary (' . $summary_model . '): ' . $summary_response['content']->get_error_message();
					$results['errors'][] = $err_msg;
					Peptide_News_Logger::error( 'Summarization failed for article #' . $article_id . ': ' . $summary_response['content']->get_error_message(), 'llm' );
				}

				// Log cost regardless of success.
				if ( class_exists( 'Peptide_News_Cost_Tracker' ) && ! empty( $summary_response['usage'] ) ) {
					Peptide_News_Cost_Tracker::log_api_call(
						$summary_model,
						'summary',
						$summary_response['usage'],
						$article_id,
						$summary_response['request_id'] ?? '',
						$summary_response['cost'] ?? 0.0
					);
				}
			}
		} elseif ( $need_summary ) {
			$results['errors'][] = 'Invalid summary model ID: ' . $summary_model;
			Peptide_News_Logger::error( 'Invalid summary model ID: ' . $summary_model, 'llm' );
		}

		$results['success'] = $any_success;

		// --- Persist results ---
		if ( ! empty( $results['keywords'] ) || ! empty( $results['summary'] ) ) {
			self::save_results( $article_id, $results, $force );
		}

		return $results;
	}

	/**
	 * Process all unanalyzed articles (called after a fetch cycle).
	 *
	 * Includes a time-based guard to prevent cron timeouts and
	 * respects the admin-configured max articles per cycle unless
	 * $override_limit is true (used by the manual bulk-generate button).
	 *
	 * @param int  $batch_size      Max articles to process per run.
	 * @param bool $override_limit  When true, ignore the admin "max per cycle" cap.
	 * @return int                   Number of articles successfully processed.
	 */
	public static function process_unanalyzed( int $batch_size = 10, bool $override_limit = false ): int {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		if ( ! $override_limit ) {
			// Respect admin cost-control setting for automated cron runs.
			$max_per_cycle = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
			if ( $max_per_cycle < 1 ) {
				$max_per_cycle = 10;
			}
			$batch_size = min( $batch_size, $max_per_cycle );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		// Find articles missing tags OR ai_summary.
		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, title, excerpt, content, categories, tags, ai_summary
			 FROM {$table}
			 WHERE is_active = 1
			   AND ( tags = '' OR ai_summary = '' OR ai_summary IS NULL )
			 ORDER BY fetched_at DESC
			 LIMIT %d",
			$batch_size
		) );

		$processed = 0;
		$last_errors = array();
		$start_time = time();

		foreach ( $articles as $article ) {
			// Timeout guard: stop if approaching the limit.
			if ( ( time() - $start_time ) >= self::BATCH_TIMEOUT ) {
				self::log_error( 'Batch timeout reached after ' . $processed . ' articles. Remaining will process next cycle.' );
				break;
			}

			$result = self::process_article( $article );

			// Only count successful processing.
			if ( ! empty( $result['success'] ) ) {
				$processed++;
			}

			// Track errors for debugging.
			if ( ! empty( $result['errors'] ) ) {
				$last_errors = $result['errors'];
			}

			// Small delay to avoid rate limiting.
			if ( next( $articles ) !== false ) {
				usleep( 500000 ); // 0.5s between articles.
			}
		}

		// Clear article cache once per batch (not per article).
		if ( $processed > 0 ) {
			self::clear_article_cache();
		}

		// Log the processing result.
		update_option( 'peptide_news_last_llm_process', array(
			'time'      => current_time( 'mysql' ),
			'processed' => $processed,
			'attempted' => count( $articles ),
			'errors'    => $last_errors,
		) );

		if ( $processed > 0 ) {
			Peptide_News_Logger::info( sprintf( 'AI batch complete: %d/%d articles processed.', $processed, count( $articles ) ), 'llm' );
		} elseif ( count( $articles ) > 0 ) {
			Peptide_News_Logger::warning( sprintf( 'AI batch: 0/%d articles succeeded.', count( $articles ) ), 'llm' );
		}

		return $processed;
	}

	/**
	 * Build the prompt for keyword extraction.
	 *
	 * @param object $article
	 * @return string
	 */
	private static function build_keywords_prompt( object $article ): string {
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
	 * @param object $article
	 * @return string
	 */
	private static function build_summary_prompt( object $article ): string {
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

	/**
	 * Call the OpenRouter API with retry logic for 429 rate limits.
	 *
	 * Backward-compatible wrapper that returns only the content string.
	 * Internally delegates to call_openrouter_with_usage().
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $prompt
	 * @return string|WP_Error  The response text or an error.
	 */
	public static function call_openrouter( string $api_key, string $model, string $prompt ) {
		$result = self::call_openrouter_with_usage( $api_key, $model, $prompt );
		return $result['content'];
	}

	/**
	 * Call the OpenRouter API and return content + token usage data.
	 *
	 * Returns an array with 'content' (string|WP_Error), 'usage' (token counts),
	 * 'request_id' (for debugging), and 'cost' (if API reports it).
	 *
	 * @param string $api_key
	 * @param string $model
	 * @param string $prompt
	 * @return array{content: string|WP_Error, usage: array, request_id: string, cost: float}
	 */
	public static function call_openrouter_with_usage( string $api_key, string $model, string $prompt ): array {
		$result = array(
			'content'    => '',
			'usage'      => array(),
			'request_id' => '',
			'cost'       => 0.0,
		);

		$body = array(
			'model'    => $model,
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
			'max_tokens'  => 300,
			'temperature' => 0.3,
		);

		$retries = 0;
		$backoff = 2; // Initial backoff in seconds.

		while ( $retries <= self::MAX_RETRIES ) {
			$response = wp_remote_post( self::get_api_url(), array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
					'X-Title'       => 'Peptide News Aggregator',
				),
				'body' => wp_json_encode( $body ),
			) );

			if ( is_wp_error( $response ) ) {
				$result['content'] = $response;
				return $result;
			}

			$status = wp_remote_retrieve_response_code( $response );

			// Handle rate limiting with exponential backoff.
			if ( 429 === $status && $retries < self::MAX_RETRIES ) {
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait = $retry_after ? min( (int) $retry_after, 30 ) : $backoff;
				sleep( $wait );
				$retries++;
				$backoff *= 2;
				continue;
			}

			break;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// Extract usage data from response regardless of success/failure.
		// OpenRouter includes usage in the response body: data.usage.{prompt_tokens, completion_tokens, total_tokens}
		if ( is_array( $data ) && isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
			$result['usage'] = array(
				'prompt_tokens'     => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'completion_tokens' => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
				'total_tokens'      => (int) ( $data['usage']['total_tokens'] ?? 0 ),
			);
		}

		// Extract the request ID from response for debugging/reconciliation.
		if ( is_array( $data ) && isset( $data['id'] ) ) {
			$result['request_id'] = (string) $data['id'];
		}

		// OpenRouter may include generation cost in data.usage.total_cost or similar.
		if ( is_array( $data ) && isset( $data['usage']['total_cost'] ) ) {
			$result['cost'] = (float) $data['usage']['total_cost'];
		}

		if ( $status < 200 || $status >= 300 ) {
			$error_msg = 'HTTP ' . $status;
			if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['message'] ) ) {
				$error_msg = $data['error']['message'];
			}
			$result['content'] = new WP_Error( 'openrouter_error', $error_msg );
			return $result;
		}

		// Validate the response structure thoroughly.
		if ( ! is_array( $data ) ) {
			$result['content'] = new WP_Error( 'openrouter_invalid', 'Invalid JSON response from OpenRouter' );
			return $result;
		}
		if ( ! isset( $data['choices'] ) || ! is_array( $data['choices'] ) || empty( $data['choices'] ) ) {
			$result['content'] = new WP_Error( 'openrouter_empty', 'No choices in OpenRouter response' );
			return $result;
		}
		if ( ! isset( $data['choices'][0]['message'] ) || ! is_array( $data['choices'][0]['message'] ) ) {
			$result['content'] = new WP_Error( 'openrouter_empty', 'Malformed choice in OpenRouter response' );
			return $result;
		}
		if ( ! isset( $data['choices'][0]['message']['content'] ) || '' === trim( $data['choices'][0]['message']['content'] ) ) {
			$result['content'] = new WP_Error( 'openrouter_empty', 'Empty content in OpenRouter response' );
			return $result;
		}

		$result['content'] = trim( $data['choices'][0]['message']['content'] );
		return $result;
	}

	/**
	 * Sanitize and normalize keyword output from the LLM.
	 *
	 * @param string $raw  Raw comma-separated keywords.
	 * @return string       Cleaned, deduplicated, comma-separated list.
	 */
	private static function sanitize_keywords( string $raw ): string {
		// Strip all HTML first to prevent XSS.
		$raw = wp_strip_all_tags( $raw );

		// Remove any numbering, bullets, or extra formatting.
		$raw = preg_replace( '/^\d+[\.\)]\s*/m', '', $raw );
		$raw = preg_replace( '/^[-*\x{2022}]\s*/mu', '', $raw );

		$keywords = array_map( 'trim', explode( ',', $raw ) );
		$keywords = array_map( 'sanitize_text_field', $keywords );
		$keywords = array_filter( $keywords );
		$keywords = array_unique( $keywords );

		// Limit to 10 keywords max.
		$keywords = array_slice( $keywords, 0, 10 );

		return implode( ', ', $keywords );
	}

	/**
	 * Save LLM results to the database.
	 *
	 * Note: Cache is NOT cleared here — the caller (process_unanalyzed)
	 * clears cache once per batch for efficiency.
	 *
	 * @param int   $article_id
	 * @param array $results     Array with 'keywords' and 'summary'.
	 * @param bool  $force       Overwrite existing values.
	 */
	private static function save_results( int $article_id, array $results, bool $force = false ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		$update = array();
		$format = array();

		if ( ! empty( $results['keywords'] ) ) {
			$update['tags'] = $results['keywords'];
			$format[]       = '%s';
		}

		if ( ! empty( $results['summary'] ) ) {
			$update['ai_summary'] = $results['summary'];
			$format[]             = '%s';
		}

		if ( ! empty( $update ) ) {
			$wpdb->update(
				$table,
				$update,
				array( 'id' => $article_id ),
				$format,
				array( '%d' )
			);
		}
	}

	/**
	 * AJAX handler: bulk-generate AI summaries for articles that are missing them.
	 *
	 * Processes up to 50 articles per request. Returns the count of articles
	 * processed and remaining so the admin UI can loop until done.
	 *
	 * @since 2.0.1
	 */
	public static function ajax_generate_summaries(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'peptide-news' ), 403 );
		}

		if ( ! self::is_enabled() ) {
			wp_send_json_error( 'AI Analysis is not enabled. Please enable it and set an OpenRouter API key in Peptide News → Settings.' );
		}

		Peptide_News_Logger::info( 'Bulk AI summary generation triggered by admin.', 'llm' );

		// Auto-correct known broken model names to a working free model.
		$broken_models = array(
			'qwen/qwen3.6-plus:free',
			'qwen/qwen3.6-plus-preview:free',
		);
		$working_model = 'google/gemma-3-27b-it:free';
		foreach ( array( 'peptide_news_llm_keywords_model', 'peptide_news_llm_summary_model' ) as $opt ) {
			if ( in_array( get_option( $opt, '' ), $broken_models, true ) ) {
				update_option( $opt, $working_model );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

		// Count how many still need processing.
		$remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE is_active = 1
			   AND ( ai_summary = '' OR ai_summary IS NULL )"
		);

		if ( $remaining < 1 ) {
			wp_send_json_success( array(
				'processed' => 0,
				'remaining' => 0,
				'message'   => 'All articles already have AI summaries.',
			) );
		}

		// Process ONE article per request to stay within PHP's
		// max_execution_time (often 30s on shared hosting). Each article
		// requires 2 API calls (keywords + summary) which can take 10-20s
		// with free models. The JS UI loops automatically until done.
		$processed = self::process_unanalyzed( 1, true );

		// Recount remaining after this batch.
		$still_remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE is_active = 1
			   AND ( ai_summary = '' OR ai_summary IS NULL )"
		);

		// Include error details from the last processing run for debugging.
		$last_run = get_option( 'peptide_news_last_llm_process', array() );
		$errors   = isset( $last_run['errors'] ) ? $last_run['errors'] : array();

		wp_send_json_success( array(
			'processed' => $processed,
			'remaining' => $still_remaining,
			'message'   => sprintf( '%d article(s) summarized, %d remaining.', $processed, $still_remaining ),
			'errors'    => $errors,
		) );
	}

	/**
	 * Clear all article transient caches.
	 */
	private static function clear_article_cache(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
					OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_peptide_news_articles_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_peptide_news_articles_' ) . '%'
			)
		);
	}

	/**
	 * Log an error to the WordPress debug log.
	 *
	 * @param string $message
	 */
	private static function log_error( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Peptide News LLM] ' . $message );
		}
	}
}
