<?php
declare( strict_types=1 );
/**
 * Tracks LLM API costs: logs every API call, enforces monthly budgets, and
 * provides aggregated reporting data for the admin cost dashboard.
 *
 * Triggered by: Peptide_News_LLM after each call_openrouter() invocation.
 *               Admin AJAX handlers for cost dashboard data.
 *
 * Dependencies: $wpdb (custom table), Peptide_News_Logger.
 *
 * Data flow: LLM::call_openrouter() returns usage data → Cost_Tracker::log_api_call()
 *            writes to wp_peptide_news_llm_costs table. Budget checks happen
 *            before each LLM call via Cost_Tracker::is_budget_exceeded().
 *            Admin dashboard queries aggregated data via get_cost_summary() and
 *            get_daily_costs().
 *
 * @see class-peptide-news-llm.php       — Calls log_api_call() after every API request
 * @see class-peptide-news-admin.php     — Renders cost dashboard and budget settings
 * @see class-peptide-news-activator.php — Creates the llm_costs table on activation
 * @see ARCHITECTURE.md                  — Data flow diagram
 *
 * @since 2.4.0
 */
class Peptide_News_Cost_Tracker {

	/**
	 * Default model pricing per 1M tokens (USD).
	 *
	 * These are fallback rates used when the API response doesn't include cost data.
	 * Pricing sourced from OpenRouter's published rates as of April 2026.
	 * Users can override per-model pricing via the admin settings.
	 *
	 * @var array<string, array{input: float, output: float}>
	 */
	const DEFAULT_MODEL_PRICING = array(
		'google/gemini-2.0-flash-001'   => array( 'input' => 0.10, 'output' => 0.40 ),
		'google/gemma-3-27b-it:free'    => array( 'input' => 0.00, 'output' => 0.00 ),
		'anthropic/claude-3.5-sonnet'   => array( 'input' => 3.00, 'output' => 15.00 ),
		'anthropic/claude-3-haiku'      => array( 'input' => 0.25, 'output' => 1.25 ),
		'openai/gpt-4o-mini'            => array( 'input' => 0.15, 'output' => 0.60 ),
		'openai/gpt-4o'                 => array( 'input' => 2.50, 'output' => 10.00 ),
	);

	/** @var string Budget enforcement mode: 'hard_stop', 'warn_only', or 'disabled'. */
	const BUDGET_MODE_HARD_STOP = 'hard_stop';
	const BUDGET_MODE_WARN_ONLY = 'warn_only';
	const BUDGET_MODE_DISABLED  = 'disabled';

	/**
	 * Create the llm_costs table. Called from Activator::activate().
	 *
	 * Uses dbDelta() for idempotent CREATE/ALTER so it's safe to call on every
	 * plugin upgrade without manual migration logic.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . 'peptide_news_llm_costs';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id VARCHAR(64) NOT NULL DEFAULT '',
			model VARCHAR(100) NOT NULL DEFAULT '',
			provider VARCHAR(50) NOT NULL DEFAULT 'openrouter',
			operation VARCHAR(50) NOT NULL DEFAULT '',
			prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			total_tokens INT UNSIGNED NOT NULL DEFAULT 0,
			cost_usd DECIMAL(10,6) NOT NULL DEFAULT 0.000000,
			article_id BIGINT(20) UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_created_at (created_at),
			KEY idx_model (model),
			KEY idx_operation (operation),
			KEY idx_article (article_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Log a single API call's token usage and cost.
	 *
	 * Called by LLM::call_openrouter() after a successful (or failed) API response.
	 * Extracts token counts from the OpenRouter response's `usage` field and
	 * calculates cost using model-specific pricing.
	 *
	 * @param string   $model           OpenRouter model ID (e.g., 'google/gemini-2.0-flash-001').
	 * @param string   $operation       What this call was for: 'keywords', 'summary', or 'filter'.
	 * @param array    $usage           Token usage from API response: {prompt_tokens, completion_tokens, total_tokens}.
	 * @param int|null $article_id      Associated article ID, if applicable.
	 * @param string   $request_id      OpenRouter request ID from response headers (for debugging).
	 * @param float    $explicit_cost   Cost reported directly by the API (OpenRouter generation.total_cost), if available.
	 * @return bool True on success.
	 */
	public static function log_api_call(
		string $model,
		string $operation,
		array $usage,
		?int $article_id = null,
		string $request_id = '',
		float $explicit_cost = 0.0
	): bool {
		global $wpdb;

		$prompt_tokens     = absint( $usage['prompt_tokens'] ?? 0 );
		$completion_tokens = absint( $usage['completion_tokens'] ?? 0 );
		$total_tokens      = absint( $usage['total_tokens'] ?? ( $prompt_tokens + $completion_tokens ) );

		// Use explicit cost from API if available, otherwise calculate from pricing table.
		$cost_usd = $explicit_cost > 0.0
			? $explicit_cost
			: self::calculate_cost( $model, $prompt_tokens, $completion_tokens );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$wpdb->prefix . 'peptide_news_llm_costs',
			array(
				'request_id'        => sanitize_text_field( $request_id ),
				'model'             => sanitize_text_field( $model ),
				'provider'          => 'openrouter',
				'operation'         => sanitize_text_field( $operation ),
				'prompt_tokens'     => $prompt_tokens,
				'completion_tokens' => $completion_tokens,
				'total_tokens'      => $total_tokens,
				'cost_usd'          => $cost_usd,
				'article_id'        => $article_id,
				'created_at'        => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%f', '%d', '%s' )
		);

		if ( false === $result ) {
			Peptide_News_Logger::error( 'Failed to log API cost for model ' . $model, 'cost' );
			return false;
		}

		// Check budget thresholds and fire alerts if needed.
		self::check_budget_alerts();

		return true;
	}

	/**
	 * Calculate estimated cost from token counts using model pricing.
	 *
	 * Pricing is per 1M tokens. Checks user-configured overrides first,
	 * then falls back to DEFAULT_MODEL_PRICING, then to zero (free model assumed).
	 *
	 * @param string $model            OpenRouter model ID.
	 * @param int    $prompt_tokens    Number of input tokens.
	 * @param int    $completion_tokens Number of output tokens.
	 * @return float Estimated cost in USD.
	 */
	public static function calculate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		$pricing = self::get_model_pricing( $model );

		$input_cost  = ( $prompt_tokens / 1000000.0 ) * $pricing['input'];
		$output_cost = ( $completion_tokens / 1000000.0 ) * $pricing['output'];

		return round( $input_cost + $output_cost, 6 );
	}

	/**
	 * Get pricing for a specific model (per 1M tokens).
	 *
	 * Checks user-configured custom pricing first, then falls back to defaults.
	 *
	 * @param string $model OpenRouter model ID.
	 * @return array{input: float, output: float}
	 */
	public static function get_model_pricing( string $model ): array {
		// Check user-configured custom pricing overrides.
		$custom_pricing = get_option( 'peptide_news_custom_model_pricing', array() );
		if ( is_array( $custom_pricing ) && isset( $custom_pricing[ $model ] ) ) {
			return array(
				'input'  => (float) ( $custom_pricing[ $model ]['input'] ?? 0.0 ),
				'output' => (float) ( $custom_pricing[ $model ]['output'] ?? 0.0 ),
			);
		}

		// Fall back to built-in defaults.
		if ( isset( self::DEFAULT_MODEL_PRICING[ $model ] ) ) {
			return self::DEFAULT_MODEL_PRICING[ $model ];
		}

		// Unknown model — assume free to avoid overcharging estimates.
		return array( 'input' => 0.0, 'output' => 0.0 );
	}

	/**
	 * Check if the monthly budget has been exceeded.
	 *
	 * Should be called before every LLM API call to enforce the hard stop.
	 * Returns true if budget mode is 'hard_stop' and current month's spend
	 * meets or exceeds the configured limit.
	 *
	 * @return bool True if budget is exceeded and calls should be blocked.
	 */
	public static function is_budget_exceeded(): bool {
		$mode  = get_option( 'peptide_news_budget_mode', self::BUDGET_MODE_DISABLED );
		$limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );

		if ( self::BUDGET_MODE_DISABLED === $mode || $limit <= 0.0 ) {
			return false;
		}

		if ( self::BUDGET_MODE_HARD_STOP !== $mode ) {
			return false;
		}

		$current_spend = self::get_current_month_spend();

		return $current_spend >= $limit;
	}

	/**
	 * Get total spend for the current calendar month.
	 *
	 * Uses a short-lived transient cache (5 min) to avoid hammering the DB
	 * on every budget check during batch processing.
	 *
	 * @return float Total USD spent this month.
	 */
	public static function get_current_month_spend(): float {
		$cache_key = 'peptide_news_month_spend_' . gmdate( 'Y_m' );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$spend = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(cost_usd), 0) FROM {$table} WHERE created_at >= %s",
				gmdate( 'Y-m-01 00:00:00' )
			)
		);

		set_transient( $cache_key, $spend, 5 * MINUTE_IN_SECONDS );

		return $spend;
	}

	/**
	 * Invalidate the monthly spend cache.
	 *
	 * Called after logging a new API call so the next budget check
	 * reflects the latest spend.
	 */
	public static function invalidate_spend_cache(): void {
		delete_transient( 'peptide_news_month_spend_' . gmdate( 'Y_m' ) );
	}

	/**
	 * Check budget threshold alerts and log warnings.
	 *
	 * Fires at 50%, 80%, and 100% of the monthly budget. Each threshold
	 * is only logged once per month (tracked via a transient).
	 */
	private static function check_budget_alerts(): void {
		$mode  = get_option( 'peptide_news_budget_mode', self::BUDGET_MODE_DISABLED );
		$limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );

		if ( self::BUDGET_MODE_DISABLED === $mode || $limit <= 0.0 ) {
			return;
		}

		// Invalidate cache so we get fresh numbers.
		self::invalidate_spend_cache();

		$spend   = self::get_current_month_spend();
		$percent = ( $spend / $limit ) * 100.0;

		$alert_key = 'peptide_news_budget_alerts_' . gmdate( 'Y_m' );
		$fired     = get_transient( $alert_key );
		if ( ! is_array( $fired ) ) {
			$fired = array();
		}

		$thresholds = array( 50, 80, 100 );

		foreach ( $thresholds as $threshold ) {
			if ( $percent >= $threshold && ! in_array( $threshold, $fired, true ) ) {
				$message = sprintf(
					'LLM budget alert: %.0f%% of $%.2f monthly budget used ($%.4f spent).',
					$percent,
					$limit,
					$spend
				);
				Peptide_News_Logger::warning( $message, 'cost' );

				if ( 100 === $threshold && self::BUDGET_MODE_HARD_STOP === $mode ) {
					Peptide_News_Logger::error( 'Monthly LLM budget exceeded — API calls will be blocked until next month.', 'cost' );
				}

				$fired[] = $threshold;
			}
		}

		set_transient( $alert_key, $fired, 35 * DAY_IN_SECONDS );
	}

	/**
	 * Get an aggregated cost summary for a date range.
	 *
	 * Returns total spend, total tokens, request count, and per-model breakdown.
	 * Used by the admin cost dashboard.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array{total_cost: float, total_tokens: int, total_requests: int, by_model: array, by_operation: array}
	 */
	public static function get_cost_summary( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

		// Overall totals.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(cost_usd), 0) AS total_cost,
					COALESCE(SUM(total_tokens), 0) AS total_tokens,
					COUNT(*) AS total_requests
				FROM {$table}
				WHERE created_at >= %s AND created_at < %s",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			)
		);

		// Per-model breakdown.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_model = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					model,
					COUNT(*) AS requests,
					COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
					COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
					COALESCE(SUM(total_tokens), 0) AS total_tokens,
					COALESCE(SUM(cost_usd), 0) AS total_cost
				FROM {$table}
				WHERE created_at >= %s AND created_at < %s
				GROUP BY model
				ORDER BY total_cost DESC",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			)
		);

		// Per-operation breakdown.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$by_operation = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					operation,
					COUNT(*) AS requests,
					COALESCE(SUM(total_tokens), 0) AS total_tokens,
					COALESCE(SUM(cost_usd), 0) AS total_cost
				FROM {$table}
				WHERE created_at >= %s AND created_at < %s
				GROUP BY operation
				ORDER BY total_cost DESC",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			)
		);

		return array(
			'total_cost'     => (float) ( $totals->total_cost ?? 0 ),
			'total_tokens'   => (int) ( $totals->total_tokens ?? 0 ),
			'total_requests' => (int) ( $totals->total_requests ?? 0 ),
			'by_model'       => $by_model ?: array(),
			'by_operation'   => $by_operation ?: array(),
		);
	}

	/**
	 * Get daily cost data for charting.
	 *
	 * Returns one row per day within the date range with aggregated cost and token counts.
	 *
	 * @param string $start_date Start date (Y-m-d).
	 * @param string $end_date   End date (Y-m-d).
	 * @return array Array of objects with {date, cost, tokens, requests}.
	 */
	public static function get_daily_costs( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					DATE(created_at) AS date,
					COALESCE(SUM(cost_usd), 0) AS cost,
					COALESCE(SUM(total_tokens), 0) AS tokens,
					COUNT(*) AS requests
				FROM {$table}
				WHERE created_at >= %s AND created_at < %s
				GROUP BY DATE(created_at)
				ORDER BY date ASC",
				$start_date . ' 00:00:00',
				$end_date . ' 23:59:59'
			)
		) ?: array();
	}

	/**
	 * Get the most recent API calls for the cost log table.
	 *
	 * @param int $limit Number of recent entries to return.
	 * @return array Array of cost log rows.
	 */
	public static function get_recent_calls( int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		) ?: array();
	}

	/**
	 * AJAX handler: return cost dashboard data.
	 *
	 * Accepts 'period' param: 'day', 'week', 'month', or 'custom' with start/end dates.
	 * Returns summary stats and daily chart data.
	 *
	 * @since 2.4.0
	 */
	public static function ajax_get_cost_data(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$period = sanitize_text_field( wp_unslash( $_GET['period'] ?? 'month' ) );

		switch ( $period ) {
			case 'day':
				$start_date = gmdate( 'Y-m-d' );
				$end_date   = gmdate( 'Y-m-d' );
				break;

			case 'week':
				$start_date = gmdate( 'Y-m-d', strtotime( '-6 days' ) );
				$end_date   = gmdate( 'Y-m-d' );
				break;

			case 'custom':
				$raw_start  = sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) );
				$raw_end    = sanitize_text_field( wp_unslash( $_GET['end_date'] ?? '' ) );
				$start_date = self::validate_date( $raw_start ) ? $raw_start : gmdate( 'Y-m-01' );
				$end_date   = self::validate_date( $raw_end ) ? $raw_end : gmdate( 'Y-m-d' );
				break;

			case 'month':
			default:
				$start_date = gmdate( 'Y-m-01' );
				$end_date   = gmdate( 'Y-m-d' );
				break;
		}

		$summary     = self::get_cost_summary( $start_date, $end_date );
		$daily_costs = self::get_daily_costs( $start_date, $end_date );
		$recent      = self::get_recent_calls( 25 );

		// Budget context.
		$budget_limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );
		$budget_mode  = get_option( 'peptide_news_budget_mode', self::BUDGET_MODE_DISABLED );
		$month_spend  = self::get_current_month_spend();

		wp_send_json_success( array(
			'summary'      => $summary,
			'daily_costs'  => $daily_costs,
			'recent_calls' => $recent,
			'budget'       => array(
				'limit'        => $budget_limit,
				'mode'         => $budget_mode,
				'month_spend'  => $month_spend,
				'percent_used' => $budget_limit > 0 ? round( ( $month_spend / $budget_limit ) * 100, 1 ) : 0,
			),
			'period'       => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
		) );
	}

	/**
	 * Validate a date string is in Y-m-d format and represents a real date.
	 *
	 * @param string $date Date string to validate.
	 * @return bool True if valid Y-m-d date.
	 */
	private static function validate_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Prune old cost records beyond the configured retention period.
	 *
	 * Called on a monthly cron schedule. Default retention: 365 days.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function prune_old_records(): int {
		$retention_days = absint( get_option( 'peptide_news_cost_retention', 365 ) );
		if ( $retention_days < 30 ) {
			$retention_days = 30; // Minimum 30 days to prevent accidental data loss.
		}

		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s",
				gmdate( 'Y-m-d 00:00:00', strtotime( "-{$retention_days} days" ) )
			)
		);

		if ( $deleted > 0 ) {
			Peptide_News_Logger::info( sprintf( 'Pruned %d cost records older than %d days.', $deleted, $retention_days ), 'cost' );
		}

		return (int) $deleted;
	}
}
