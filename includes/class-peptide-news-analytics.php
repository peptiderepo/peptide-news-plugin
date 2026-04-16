<?php
declare( strict_types=1 );
/**
 * Click analytics — recording and aggregation.
 *
 * Handles recording click events from the frontend, updating daily
 * aggregate stats, and providing utility methods for IP handling
 * and device detection.
 *
 * Called by: Peptide_News_Public::handle_click_tracking() (AJAX endpoint).
 * Dependencies: $wpdb (writes to peptide_news_clicks and peptide_news_daily_stats).
 *
 * @since 1.0.0
 * @see   class-peptide-news-analytics-reports.php — Read-only reporting queries.
 * @see   class-peptide-news-rest-api.php          — REST endpoints for analytics data.
 */
class Peptide_News_Analytics {

	/**
	 * Record a click event for an article.
	 *
	 * Side effects: DB insert to clicks table, daily stats upsert.
	 *
	 * @param int   $article_id Article ID that was clicked.
	 * @param array $meta       Context: ip, user_agent, referrer, page_url, session_id.
	 * @return bool True on success.
	 */
	public static function record_click( int $article_id, array $meta = array() ): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'peptide_news_clicks';
		$ip    = isset( $meta['ip'] ) ? $meta['ip'] : self::get_client_ip();

		if ( null === $ip ) {
			return false;
		}

		// GDPR-friendly IP anonymization.
		if ( get_option( 'peptide_news_anonymize_ip', 1 ) ) {
			$ip = self::anonymize_ip( $ip );
		}

		$data = array(
			'article_id'   => absint( $article_id ),
			'clicked_at'   => current_time( 'mysql' ),
			'user_ip'      => sanitize_text_field( $ip ),
			'user_agent'   => sanitize_text_field( $meta['user_agent'] ?? '' ),
			'referrer_url' => esc_url_raw( $meta['referrer'] ?? '' ),
			'page_url'     => esc_url_raw( $meta['page_url'] ?? '' ),
			'session_id'   => sanitize_text_field( $meta['session_id'] ?? '' ),
			'user_id'      => is_user_logged_in() ? get_current_user_id() : null,
			'device_type'  => sanitize_text_field( self::detect_device( $meta['user_agent'] ?? '' ) ),
		);

		$result = $wpdb->insert( $table, $data );

		if ( false !== $result ) {
			self::update_daily_stats( $article_id, $ip );
		}

		return false !== $result;
	}

	/**
	 * Upsert daily aggregated stats for an article.
	 *
	 * @param int    $article_id Article ID.
	 * @param string $ip         Anonymized IP for unique visitor counting.
	 */
	private static function update_daily_stats( int $article_id, string $ip ): void {
		global $wpdb;

		$table      = $wpdb->prefix . 'peptide_news_daily_stats';
		$today      = current_time( 'Y-m-d' );
		$clicks_tbl = $wpdb->prefix . 'peptide_news_clicks';

		$existing = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, click_count FROM {$table} WHERE article_id = %d AND stat_date = %s",
			$article_id, $today
		) );

		$unique = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT user_ip) FROM {$clicks_tbl} WHERE article_id = %d AND DATE(clicked_at) = %s",
			$article_id, $today
		) );

		if ( $existing ) {
			$wpdb->update(
				$table,
				array( 'click_count' => $existing->click_count + 1, 'unique_visitors' => (int) $unique ),
				array( 'id' => $existing->id ),
				array( '%d', '%d' ),
				array( '%d' )
			);
		} else {
			$wpdb->insert( $table, array(
				'article_id'      => $article_id,
				'stat_date'       => $today,
				'click_count'     => 1,
				'unique_visitors' => 1,
			) );
		}
	}

	/**
	 * Get the client IP address from server headers.
	 *
	 * Checks CF-Connecting-IP, X-Forwarded-For, X-Real-IP, then REMOTE_ADDR.
	 *
	 * @return string|null Valid IP or null.
	 */
	private static function get_client_ip(): ?string {
		$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

		foreach ( $ip_keys as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return null;
	}

	/** Anonymize IP: zero last octet (IPv4) or last 80 bits (IPv6). */
	private static function anonymize_ip( string $ip ): string {
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return substr( $ip, 0, strrpos( $ip, ':' ) ) . ':0000';
		}
		return '0.0.0.0';
	}

	/** Simple device detection from user agent string. */
	private static function detect_device( string $ua ): string {
		$ua = strtolower( $ua );

		if ( preg_match( '/(tablet|ipad|playbook|silk)/', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/(mobile|android|iphone|ipod|phone|blackberry|opera mini|iemobile)/', $ua ) ) {
			return 'mobile';
		}
		if ( preg_match( '/(bot|crawl|spider|slurp|wget|curl)/', $ua ) ) {
			return 'bot';
		}

		return 'desktop';
	}

	// ── Backward-compatible proxies ─────────────────────────────────────

	/** @see Peptide_News_Analytics_Reports::get_top_articles() */
	public static function get_top_articles( string $start_date, string $end_date, int $limit = 20 ): array {
		return Peptide_News_Analytics_Reports::get_top_articles( $start_date, $end_date, $limit );
	}

	/** @see Peptide_News_Analytics_Reports::get_click_trends() */
	public static function get_click_trends( string $start_date, string $end_date ): array {
		return Peptide_News_Analytics_Reports::get_click_trends( $start_date, $end_date );
	}

	/** @see Peptide_News_Analytics_Reports::get_popular_topics() */
	public static function get_popular_topics( string $start_date, string $end_date, int $limit = 20 ): array {
		return Peptide_News_Analytics_Reports::get_popular_topics( $start_date, $end_date, $limit );
	}

	/** @see Peptide_News_Analytics_Reports::get_device_breakdown() */
	public static function get_device_breakdown( string $start_date, string $end_date ): array {
		return Peptide_News_Analytics_Reports::get_device_breakdown( $start_date, $end_date );
	}

	/** @see Peptide_News_Analytics_Reports::get_source_performance() */
	public static function get_source_performance( string $start_date, string $end_date ): array {
		return Peptide_News_Analytics_Reports::get_source_performance( $start_date, $end_date );
	}

	/** @see Peptide_News_Analytics_Reports::export_clicks_csv() */
	public static function export_clicks_csv( string $start_date, string $end_date ): array {
		return Peptide_News_Analytics_Reports::export_clicks_csv( $start_date, $end_date );
	}
}
