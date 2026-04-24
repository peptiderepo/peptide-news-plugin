<?php
declare( strict_types=1 );
/**
 * Read-only analytics reporting queries.
 *
 * Provides aggregated data for the admin analytics dashboard and REST API:
 * top articles, click trends, popular topics, device breakdown, source
 * performance, and CSV export.
 *
 * Called by: Peptide_News_Rest_API analytics endpoints, admin dashboard.
 * Dependencies: $wpdb (reads from peptide_news_clicks and peptide_news_daily_stats).
 *
 * @since 2.6.0
 * @see   class-peptide-news-analytics.php     — Click recording (write path).
 * @see   class-peptide-news-rest-api.php      — REST endpoints that consume these queries.
 */
class Peptide_News_Analytics_Reports {

	/** Hard cap on CSV export rows to prevent memory exhaustion. */
	const CSV_EXPORT_MAX_ROWS = 50000;

	/**
	 * Get top articles by click count within a date range.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param int    $limit      Max results.
	 * @return array
	 */
	public static function get_top_articles( string $start_date, string $end_date, int $limit = 20 ): array {
		global $wpdb;

		$stats_table    = $wpdb->prefix . 'peptide_news_daily_stats';
		$articles_table = $wpdb->prefix . 'peptide_news_articles';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.id, a.title, a.source, a.source_url, a.thumbnail_url, a.categories, a.published_at,
					SUM(s.click_count) AS total_clicks,
					SUM(s.unique_visitors) AS total_unique
			 FROM {$stats_table} s
			 INNER JOIN {$articles_table} a ON a.id = s.article_id
			 WHERE s.stat_date BETWEEN %s AND %s
			 GROUP BY a.id
			 ORDER BY total_clicks DESC
			 LIMIT %d",
			$start_date, $end_date, $limit
		) );
	}

	/**
	 * Get click trends over time (daily totals).
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array
	 */
	public static function get_click_trends( string $start_date, string $end_date ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'peptide_news_daily_stats';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT stat_date, SUM(click_count) AS total_clicks, SUM(unique_visitors) AS total_unique
			 FROM {$table}
			 WHERE stat_date BETWEEN %s AND %s
			 GROUP BY stat_date
			 ORDER BY stat_date ASC",
			$start_date, $end_date
		) );
	}

	/**
	 * Get popular topics/categories by click volume.
	 *
	 * Aggregates clicks across articles sharing the same category.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @param int    $limit      Max topics.
	 * @return array
	 */
	public static function get_popular_topics( string $start_date, string $end_date, int $limit = 20 ): array {
		global $wpdb;

		$stats_table    = $wpdb->prefix . 'peptide_news_daily_stats';
		$articles_table = $wpdb->prefix . 'peptide_news_articles';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.categories, a.title, SUM(s.click_count) AS clicks
			 FROM {$stats_table} s
			 INNER JOIN {$articles_table} a ON a.id = s.article_id
			 WHERE s.stat_date BETWEEN %s AND %s AND a.categories != ''
			 GROUP BY a.id
			 ORDER BY clicks DESC",
			$start_date, $end_date
		) );

		$topics = array();
		foreach ( $rows as $row ) {
			$cats = array_map( 'trim', explode( ',', $row->categories ) );
			foreach ( $cats as $cat ) {
				if ( empty( $cat ) ) {
					continue;
				}
				$key = strtolower( $cat );
				if ( ! isset( $topics[ $key ] ) ) {
					$topics[ $key ] = array( 'topic' => $cat, 'total_clicks' => 0, 'article_count' => 0 );
				}
				$topics[ $key ]['total_clicks']  += (int) $row->clicks;
				$topics[ $key ]['article_count'] += 1;
			}
		}

		usort( $topics, function ( $a, $b ) {
			return $b['total_clicks'] - $a['total_clicks'];
		} );

		return array_slice( $topics, 0, $limit );
	}

	/**
	 * Get device breakdown stats.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array
	 */
	public static function get_device_breakdown( string $start_date, string $end_date ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'peptide_news_clicks';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT device_type, COUNT(*) AS click_count
			 FROM {$table}
			 WHERE DATE(clicked_at) BETWEEN %s AND %s
			 GROUP BY device_type
			 ORDER BY click_count DESC",
			$start_date, $end_date
		) );
	}

	/**
	 * Get source performance stats.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array
	 */
	public static function get_source_performance( string $start_date, string $end_date ): array {
		global $wpdb;

		$stats_table    = $wpdb->prefix . 'peptide_news_daily_stats';
		$articles_table = $wpdb->prefix . 'peptide_news_articles';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT a.source,
					COUNT(DISTINCT a.id) AS article_count,
					SUM(s.click_count) AS total_clicks,
					ROUND(IFNULL(SUM(s.click_count) / NULLIF(COUNT(DISTINCT a.id), 0), 0), 1) AS avg_clicks_per_article
			 FROM {$stats_table} s
			 INNER JOIN {$articles_table} a ON a.id = s.article_id
			 WHERE s.stat_date BETWEEN %s AND %s
			 GROUP BY a.source
			 ORDER BY total_clicks DESC",
			$start_date, $end_date
		) );
	}

	/**
	 * Export click data as CSV-ready array.
	 *
	 * Capped at CSV_EXPORT_MAX_ROWS at the SQL level.
	 *
	 * @param string $start_date Y-m-d.
	 * @param string $end_date   Y-m-d.
	 * @return array Associative arrays (one per click row).
	 */
	public static function export_clicks_csv( string $start_date, string $end_date ): array {
		global $wpdb;

		$clicks_table   = $wpdb->prefix . 'peptide_news_clicks';
		$articles_table = $wpdb->prefix . 'peptide_news_articles';

		return $wpdb->get_results( $wpdb->prepare(
			"SELECT c.clicked_at, a.title, a.source, a.source_url, a.categories,
					c.referrer_url, c.page_url, c.device_type, c.session_id
			 FROM {$clicks_table} c
			 INNER JOIN {$articles_table} a ON a.id = c.article_id
			 WHERE DATE(c.clicked_at) BETWEEN %s AND %s
			 ORDER BY c.clicked_at DESC
			 LIMIT %d",
			$start_date, $end_date, self::CSV_EXPORT_MAX_ROWS
		), ARRAY_A );
	}
}
