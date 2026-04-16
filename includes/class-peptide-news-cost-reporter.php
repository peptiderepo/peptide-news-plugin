<?php
declare( strict_types=1 );
/**
 * Reporting and query layer for the LLM cost dashboard.
 *
 * Provides aggregated cost summaries, daily chart data, recent call logs,
 * and the AJAX handler that feeds the admin cost dashboard. All data is
 * read-only against the wp_peptide_news_llm_costs table populated by
 * Peptide_News_Cost_Tracker::log_api_call().
 *
 * Called by: admin AJAX (peptide_news_get_cost_data), admin dashboard rendering.
 * Depends on: $wpdb, Peptide_News_Cost_Tracker (budget context in AJAX response).
 *
 * @since 2.6.0
 * @see   class-peptide-news-cost-tracker.php — Writes cost data; budget enforcement.
 * @see   admin/class-pn-admin-dashboard-pages.php — Renders the cost dashboard UI.
 */
class Peptide_News_Cost_Reporter {

	/**
	 * Get an aggregated cost summary for a date range.
	 *
	 * Returns total spend, total tokens, request count, and per-model/operation breakdowns.
	 *
	 * @param string $start_date Start date (Y-m-d format).
	 * @param string $end_date   End date (Y-m-d format).
	 * @return array{total_cost: float, total_tokens: int, total_requests: int, by_model: array, by_operation: array}
	 */
	public static function get_cost_summary( string $start_date, string $end_date ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'peptide_news_llm_costs';

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
			'by_model'       => ! empty( $by_model ) ? $by_model : array(),
			'by_operation'   => ! empty( $by_operation ) ? $by_operation : array(),
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
		$results = $wpdb->get_results(
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
		);

		return ! empty( $results ) ? $results : array();
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
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
				$limit
			)
		);

		return ! empty( $results ) ? $results : array();
	}

	/**
	 * AJAX handler: return cost dashboard data.
	 *
	 * Accepts 'period' param: 'day', 'week', 'month', or 'custom' with start/end dates.
	 * Returns summary stats, daily chart data, recent calls, and budget context.
	 *
	 * Side effects: nonce + capability check, JSON response.
	 */
	public static function ajax_get_cost_data(): void {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$period     = sanitize_text_field( wp_unslash( $_GET['period'] ?? 'month' ) );
		$date_range = self::resolve_date_range( $period );

		$summary     = self::get_cost_summary( $date_range['start'], $date_range['end'] );
		$daily_costs = self::get_daily_costs( $date_range['start'], $date_range['end'] );
		$recent      = self::get_recent_calls( 25 );

		// Budget context from the tracker.
		$budget_limit = (float) get_option( 'peptide_news_monthly_budget', 0.0 );
		$budget_mode  = get_option( 'peptide_news_budget_mode', Peptide_News_Cost_Tracker::BUDGET_MODE_DISABLED );
		$month_spend  = Peptide_News_Cost_Tracker::get_current_month_spend();

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
			'period'       => $date_range,
		) );
	}

	/**
	 * Resolve a period keyword into start/end date strings.
	 *
	 * @param string $period One of 'day', 'week', 'month', or 'custom'.
	 * @return array{start: string, end: string} Y-m-d formatted dates.
	 */
	private static function resolve_date_range( string $period ): array {
		switch ( $period ) {
			case 'day':
				return array( 'start' => gmdate( 'Y-m-d' ), 'end' => gmdate( 'Y-m-d' ) );

			case 'week':
				return array( 'start' => gmdate( 'Y-m-d', strtotime( '-6 days' ) ), 'end' => gmdate( 'Y-m-d' ) );

			case 'custom':
				$raw_start = sanitize_text_field( wp_unslash( $_GET['start_date'] ?? '' ) );
				$raw_end   = sanitize_text_field( wp_unslash( $_GET['end_date'] ?? '' ) );
				return array(
					'start' => self::validate_date( $raw_start ) ? $raw_start : gmdate( 'Y-m-01' ),
					'end'   => self::validate_date( $raw_end ) ? $raw_end : gmdate( 'Y-m-d' ),
				);

			case 'month':
			default:
				return array( 'start' => gmdate( 'Y-m-01' ), 'end' => gmdate( 'Y-m-d' ) );
		}
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
}
