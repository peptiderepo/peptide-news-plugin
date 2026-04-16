<?php
declare( strict_types=1 );
/**
 * Tracks LLM API costs: logs every API call, enforces monthly budgets,
 * manages model pricing, and prunes old records.
 *
 * Triggered by: Peptide_News_LLM after each call_openrouter() invocation.
 * Dependencies: $wpdb (custom table), Peptide_News_Logger.
 *
 * Data flow: LLM::call_openrouter() returns usage data → log_api_call()
 *            writes to wp_peptide_news_llm_costs. Budget checks happen
 *            before each LLM call via is_budget_exceeded().
 *
 * @see class-peptide-news-cost-reporter.php — Read-only reporting queries and AJAX handler.
 * @see class-peptide-news-llm.php           — Calls log_api_call() after every API request.
 * @see class-peptide-news-activator.php     — Creates the llm_costs table on activation.
 *
 * @since 2.4.0
 */
class Peptide_News_Cost_Tracker {

	/** @var array<string, array{input: float, output: float}> Fallback pricing per 1M tokens (USD). */
	const DEFAULT_MODEL_PRICING = array(
		'google/gemini-2.0-flash-001'   => array( 'input' => 0.10, 'output' => 0.40 ),
		'google/gemma-3-27b-it:free'    => array( 'input' => 0.00, 'output' => 0.00 ),
		'anthropic/claude-3.5-sonnet'   => array( 'input' => 3.00, 'output' => 15.00 ),
		'anthropic/claude-3-haiku'      => array( 'input' => 0.25, 'output' => 1.25 ),
		'openai/gpt-4o-mini'            => array( 'input' => 0.15, 'output' => 0.60 ),
		'openai/gpt-4o'                 => array( 'input' => 2.50, 'output' => 10.00 ),
	);

	/** @var string Budget enforcement modes. */
	const BUDGET_MODE_HARD_STOP = 'hard_stop';
	const BUDGET_MODE_WARN_ONLY = 'warn_only';
	const BUDGET_MODE_DISABLED  = 'disabled';

	/** Create the llm_costs table. Called from Activator::activate(). Uses dbDelta(). */
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
	 * Side effects: DB insert, spend cache invalidation, budget alert check.
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

		self::check_budget_alerts();

		return true;
	}

	/** Calculate estimated cost from token counts using model pricing (per 1M tokens). */
	public static function calculate_cost( string $model, int $prompt_tokens, int $completion_tokens ): float {
		$pricing = self::get_model_pricing( $model );

		$input_cost  = ( $prompt_tokens / 1000000.0 ) * $pricing['input'];
		$output_cost = ( $completion_tokens / 1000000.0 ) * $pricing['output'];

		return round( $input_cost + $output_cost, 6 );
	}

	/** Get pricing for a model. Checks custom overrides, then defaults, then zero. */
	public static function get_model_pricing( string $model ): array {
		$custom_pricing = get_option( 'peptide_news_custom_model_pricing', array() );
		if ( is_array( $custom_pricing ) && isset( $custom_pricing[ $model ] ) ) {
			return array(
				'input'  => (float) ( $custom_pricing[ $model ]['input'] ?? 0.0 ),
				'output' => (float) ( $custom_pricing[ $model ]['output'] ?? 0.0 ),
			);
		}

		if ( isset( self::DEFAULT_MODEL_PRICING[ $model ] ) ) {
			return self::DEFAULT_MODEL_PRICING[ $model ];
		}

		// Unknown model — assume free to avoid overcharging estimates.
		return array( 'input' => 0.0, 'output' => 0.0 );
	}

	/** Check if monthly budget exceeded. Call before every LLM API call. */
	public static function is_budget_exceeded(): bool {
		$mode  = get_option( 'peptide_news_budget_mode', self::BUDGET_MODE_DISABLED );
		$limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );

		if ( self::BUDGET_MODE_DISABLED === $mode || $limit <= 0.0 ) {
			return false;
		}

		if ( self::BUDGET_MODE_HARD_STOP !== $mode ) {
			return false;
		}

		return self::get_current_month_spend() >= $limit;
	}

	/** Get total spend for current calendar month (5 min transient cache). */
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

	/** Invalidate the monthly spend cache. */
	public static function invalidate_spend_cache(): void {
		delete_transient( 'peptide_news_month_spend_' . gmdate( 'Y_m' ) );
	}

	/** Check budget threshold alerts (50/80/100%) and log warnings once per month. */
	private static function check_budget_alerts(): void {
		$mode  = get_option( 'peptide_news_budget_mode', self::BUDGET_MODE_DISABLED );
		$limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );

		if ( self::BUDGET_MODE_DISABLED === $mode || $limit <= 0.0 ) {
			return;
		}

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
				Peptide_News_Logger::warning( sprintf(
					'LLM budget alert: %.0f%% of $%.2f monthly budget used ($%.4f spent).',
					$percent, $limit, $spend
				), 'cost' );

				if ( 100 === $threshold && self::BUDGET_MODE_HARD_STOP === $mode ) {
					Peptide_News_Logger::error( 'Monthly LLM budget exceeded — API calls will be blocked until next month.', 'cost' );
				}

				$fired[] = $threshold;
			}
		}

		set_transient( $alert_key, $fired, 35 * DAY_IN_SECONDS );
	}

	/** Prune cost records older than retention period (default 365 days, min 30). */
	public static function prune_old_records(): int {
		$retention_days = absint( get_option( 'peptide_news_cost_retention', 365 ) );
		if ( $retention_days < 30 ) {
			$retention_days = 30;
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

	/** @see Peptide_News_Cost_Reporter::ajax_get_cost_data() */
	public static function ajax_get_cost_data(): void {
		Peptide_News_Cost_Reporter::ajax_get_cost_data();
	}

	/** @see Peptide_News_Cost_Reporter::get_cost_summary() */
	public static function get_cost_summary( string $start_date, string $end_date ): array {
		return Peptide_News_Cost_Reporter::get_cost_summary( $start_date, $end_date );
	}

	/** @see Peptide_News_Cost_Reporter::get_daily_costs() */
	public static function get_daily_costs( string $start_date, string $end_date ): array {
		return Peptide_News_Cost_Reporter::get_daily_costs( $start_date, $end_date );
	}

	/** @see Peptide_News_Cost_Reporter::get_recent_calls() */
	public static function get_recent_calls( int $limit = 50 ): array {
		return Peptide_News_Cost_Reporter::get_recent_calls( $limit );
	}
}
