<?php
declare( strict_types=1 );
/**
 * AJAX handler for bulk AI summary generation.
 *
 * Processes one article per request so the admin JS can loop safely
 * within PHP's max_execution_time. Reports progress (processed count
 * and remaining count) so the UI can show a progress bar.
 *
 * Registered via wp_ajax_peptide_news_generate_summaries hook.
 * Depends on Peptide_News_LLM for orchestration and
 * Peptide_News_Cost_Tracker for budget awareness.
 *
 * @since      2.5.0
 * @see        class-peptide-news-llm.php  Orchestrator that does the actual processing.
 */
class Peptide_News_LLM_Ajax {

	/**
	 * Bulk-generate AI summaries for articles that are missing them.
	 *
	 * Processes one article per request (to stay within max_execution_time).
	 * The admin JS UI loops automatically until all articles are done.
	 *
	 * Side effects: nonce + capability check, option reads/writes, DB queries,
	 * delegates to Peptide_News_LLM::process_unanalyzed(), sends JSON response.
	 */
	public static function generate_summaries(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'peptide-news' ), 403 );
		}

		if ( ! Peptide_News_LLM::is_enabled() ) {
			wp_send_json_error( 'AI Analysis is not enabled. Please enable it and set an OpenRouter API key in Peptide News → Settings.' );
		}

		Peptide_News_Logger::info( 'Bulk AI summary generation triggered by admin.', 'llm' );

		// Auto-correct known broken model names to a working free model.
		$broken_models = array( 'qwen/qwen3.6-plus:free', 'qwen/qwen3.6-plus-preview:free' );
		$working_model = 'google/gemma-3-27b-it:free';
		foreach ( array( 'peptide_news_llm_keywords_model', 'peptide_news_llm_summary_model' ) as $opt ) {
			if ( in_array( get_option( $opt, '' ), $broken_models, true ) ) {
				update_option( $opt, $working_model );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_articles';

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
		// max_execution_time (often 30s on shared hosting).
		$processed = Peptide_News_LLM::process_unanalyzed( 1, true );

		// Recount remaining after this batch.
		$still_remaining = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table}
			 WHERE is_active = 1
			   AND ( ai_summary = '' OR ai_summary IS NULL )"
		);

		$last_run = get_option( 'peptide_news_last_llm_process', array() );
		$errors   = isset( $last_run['errors'] ) ? $last_run['errors'] : array();

		wp_send_json_success( array(
			'processed' => $processed,
			'remaining' => $still_remaining,
			'message'   => sprintf( '%d article(s) summarized, %d remaining.', $processed, $still_remaining ),
			'errors'    => $errors,
		) );
	}
}
