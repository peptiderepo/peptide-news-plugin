<?php
/**
 * REST API endpoints for external analytics access.
 *
 * Base route: /wp-json/peptide-news/v1/
 *
 * Endpoints:
 *   GET /articles         — list stored articles
 *   GET /analytics/top    — top articles by clicks
 *   GET /analytics/trends — daily click trend data
 *   GET /analytics/topics — popular topics/categories
 *   GET /analytics/devices — device breakdown
 *   GET /analytics/sources — source performance
 *   GET /analytics/export  — CSV export of raw click data
 *
 * @since 1.0.0
 */
class Peptide_News_Rest_API {

	const API_NAMESPACE = 'peptide-news/v1';

	/**
	 * Register REST routes.
	 */
	public function register_routes() {

		// Public: list articles.
		register_rest_route( self::API_NAMESPACE, '/articles', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_articles' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'count' => array(
					'default'           => 10,
					'sanitize_callback' => 'absint',
				),
				'page'  => array(
					'default'           => 1,
					'sanitize_callback' => 'absint',
				),
			),
		) );

		// Admin-only analytics endpoints.
		$analytics_routes = array(
			'/analytics/top'     => 'get_top_articles',
			'/analytics/trends'  => 'get_trends',
			'/analytics/topics'  => 'get_topics',
			'/analytics/devices' => 'get_devices',
			'/analytics/sources' => 'get_sources',
			'/analytics/export'  => 'get_export',
		);

		foreach ( $analytics_routes as $route => $callback ) {
			register_rest_route( self::API_NAMESPACE, $route, array(
				'methods'             => 'GET',
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => $this->get_date_range_args(),
			) );
		}
	}

	/**
	 * Verify the request comes from an admin user.
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function check_admin_permissions( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access analytics data.', 'peptide-news' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Common date-range argument definitions.
	 *
	 * @return array
	 */
	private function get_date_range_args() {
		return array(
			'start_date' => array(
				'default'            => gmdate( 'Y-m-d', strtotime( '-30 days' ) ),
				'sanitize_callback'  => 'sanitize_text_field',
				'validate_callback'  => array( $this, 'validate_date_format' ),
			),
			'end_date' => array(
				'default'            => gmdate( 'Y-m-d' ),
				'sanitize_callback'  => 'sanitize_text_field',
				'validate_callback'  => array( $this, 'validate_date_format' ),
			),
			'limit' => array(
				'default'           => 20,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Validate that a parameter is a Y-m-d date string.
	 *
	 * @param string          $value
	 * @param WP_REST_Request $request
	 * @param string          $param
	 * @return true|WP_Error
	 */
	public function validate_date_format( $value, $request, $param ) {
		if ( ! preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return new WP_Error(
				'rest_invalid_date',
				sprintf( __( 'Invalid date format for %s. Expected Y-m-d.', 'peptide-news' ), $param ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * GET /articles
	 *
	 * Returns active articles with server-side transient caching.
	 * Cache is invalidated whenever new articles are stored.
	 */
	public function get_articles( $request ) {
		global $wpdb;

		$count  = absint( min( $request->get_param( 'count' ), 100 ) );
		$page   = absint( max( $request->get_param( 'page' ), 1 ) );
		$table  = $wpdb->prefix . 'peptide_news_articles';

		// Build cache key from page and count params.
		$cache_key = 'peptide_news_articles_' . $page . '_' . $count;
		$cached_response = get_transient( $cache_key );
		if ( false !== $cached_response ) {
			return rest_ensure_response( $cached_response );
		}

		$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_active = %d", 1 ) );

		// Ensure page doesn't exceed max available pages.
		$max_page = max( 1, (int) ceil( $total / $count ) );
		$page = min( $page, $max_page );
		$offset = ( $page - 1 ) * $count;

		$articles = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, source, source_url, title, excerpt, ai_summary, author,
					thumbnail_url, thumbnail_local, published_at, categories, tags
			 FROM {$table}
			 WHERE is_active = 1
			 ORDER BY published_at DESC
			 LIMIT %d OFFSET %d",
			$count,
			$offset
		) );

		// Strip source name from titles, excerpts, and summaries.
		$nbsp = "\xC2\xA0"; // UTF-8 non-breaking space.
		foreach ( $articles as $article ) {
			$fields = array( 'title', 'excerpt', 'ai_summary' );
			foreach ( $fields as $field ) {
				if ( empty( $article->$field ) ) {
					continue;
				}
				// First pass: use strip_source_suffix for known patterns.
				$article->$field = $this->strip_source_suffix( $article->$field, $article->source, $article->source_url );

				// Second pass: catch any remaining nbsp-separated publisher names
				// (e.g., "...text\xC2\xA0\xC2\xA0Publisher Name" from Google News RSS).
				$text = $article->$field;
				$pos  = strrpos( $text, $nbsp . $nbsp );
				if ( false === $pos ) {
					$pos = strrpos( $text, $nbsp );
				}
				if ( false !== $pos && $pos > 10 ) {
					$candidate = rtrim( substr( $text, 0, $pos ) );
					if ( strlen( $candidate ) > strlen( $text ) * 0.3 ) {
						$article->$field = $candidate;
					}
				}
			}

		}

		$data = array(
			'articles'    => $articles,
			'total'       => (int) $total,
			'page'        => $page,
			'per_page'    => $count,
			'total_pages' => ceil( $total / $count ),
		);

		// Cache the response for 1 hour.
		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		$response = rest_ensure_response( $data );

		return $response;
	}

	/**
	 * Strip the source/publisher name from the end of a string.
	 *
	 * Handles common separator patterns like " - Source", " | Source",
	 * " — Source", and " – Source". Uses case-insensitive matching against
	 * the source field. Also extracts publisher names from source URLs
	 * and has a regex fallback for common RSS title patterns.
	 *
	 * @param string $text       The text to clean.
	 * @param string $source     The source name to strip.
	 * @param string $source_url The source URL (used to extract domain-based publisher names).
	 * @return string Cleaned text.
	 */
	private function strip_source_suffix( $text, $source, $source_url = '' ) {
		if ( empty( $text ) ) {
			return $text;
		}

		// Decode HTML entities first so &nbsp; becomes actual characters.
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// Common separators between title/excerpt and source name.
		// Includes regular spaces, non-breaking spaces (\xC2\xA0), and typographic dashes.
		$nbsp = "\xC2\xA0"; // UTF-8 non-breaking space
		$separators = array(
			' - ', ' | ', ' — ', ' – ', ' // ',
			$nbsp . $nbsp,          // double non-breaking space (Google News RSS excerpts)
			'  ',                    // double regular space
			$nbsp,                   // single non-breaking space
		);

		// Build list of source names to try matching against.
		$source_names = array();
		if ( ! empty( $source ) ) {
			$source_names[] = $source;
		}

		// Get cached source names list to avoid database queries on every API call.
		$cached_sources = get_transient( 'peptide_news_source_names' );
		if ( false === $cached_sources ) {
			global $wpdb;
			$table = $wpdb->prefix . 'peptide_news_articles';
			$cached_sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} WHERE source != '' ORDER BY source" );
			if ( ! is_array( $cached_sources ) ) {
				$cached_sources = array();
			}
			set_transient( 'peptide_news_source_names', $cached_sources, HOUR_IN_SECONDS );
		}
		$source_names = array_merge( $source_names, $cached_sources );

		// Extract domain-based publisher name (e.g., "News-Medical" from "news-medical.net").
		if ( ! empty( $source_url ) ) {
			$host = wp_parse_url( $source_url, PHP_URL_HOST );
			if ( $host ) {
				$host = preg_replace( '/^www\./', '', $host );
				$parts = explode( '.', $host );
				if ( count( $parts ) >= 2 ) {
					$domain_name = $parts[ count( $parts ) - 2 ];
					$source_names[] = str_replace( '-', ' ', $domain_name );
					$source_names[] = $domain_name;
				}
			}
		}

		// Try exact (case-insensitive) match against known source names.
		foreach ( $source_names as $name ) {
			foreach ( $separators as $sep ) {
				$suffix = $sep . $name;
				$suffix_len = strlen( $suffix );
				if ( strlen( $text ) > $suffix_len && strcasecmp( substr( $text, -$suffix_len ), $suffix ) === 0 ) {
					return rtrim( substr( $text, 0, -$suffix_len ) );
				}
			}
		}

		// Fallback 1: strip trailing " - Short Publisher Name" patterns (1-5 words).
		// Only strips if the part after the separator looks like a publisher name
		// (starts with uppercase, no sentence-ending punctuation).
		$pattern = '/\s+[-\x{2013}\x{2014}|]\s+[A-Z][A-Za-z0-9\s.\'\-]{0,50}$/u';
		$candidate = preg_replace( $pattern, '', $text );
		if ( $candidate !== $text && strlen( $candidate ) > strlen( $text ) * 0.4 ) {
			return rtrim( $candidate );
		}

		// Fallback 2: strip trailing nbsp-separated publisher names.
		// Google News RSS excerpts use double non-breaking space + publisher name
		// when the source field doesn't match (e.g., source = "news.google.com").
		// Uses mb_ereg for reliable multibyte matching.
		if ( preg_match( '/^(.{10,}?)\x{00A0}{1,2}([A-Z][^.!?\x{00A0}]{0,55})$/u', $text, $m ) ) {
			if ( mb_strlen( $m[1], 'UTF-8' ) > mb_strlen( $text, 'UTF-8' ) * 0.4 ) {
				return rtrim( $m[1] );
			}
		}

		return $text;
	}

	/**
	 * GET /analytics/top
	 */
	public function get_top_articles( $request ) {
		$data = Peptide_News_Analytics::get_top_articles(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' ),
			$request->get_param( 'limit' )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * GET /analytics/trends
	 */
	public function get_trends( $request ) {
		$data = Peptide_News_Analytics::get_click_trends(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * GET /analytics/topics
	 */
	public function get_topics( $request ) {
		$data = Peptide_News_Analytics::get_popular_topics(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' ),
			$request->get_param( 'limit' )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * GET /analytics/devices
	 */
	public function get_devices( $request ) {
		$data = Peptide_News_Analytics::get_device_breakdown(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * GET /analytics/sources
	 */
	public function get_sources( $request ) {
		$data = Peptide_News_Analytics::get_source_performance(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' )
		);
		return rest_ensure_response( $data );
	}

	/**
	 * GET /analytics/export — returns CSV download.
	 */
	public function get_export( $request ) {
		$data = Peptide_News_Analytics::export_clicks_csv(
			$request->get_param( 'start_date' ),
			$request->get_param( 'end_date' )
		);

		if ( empty( $data ) ) {
			return rest_ensure_response( array( 'message' => 'No data for the selected period.' ) );
		}

		// Return as JSON (client can convert to CSV).
		return rest_ensure_response( array(
			'headers' => array_keys( $data[0] ),
			'rows'    => $data,
			'count'   => count( $data ),
		) );
	}

	/**
	 * AJAX handler: delete one or more articles by ID.
	 *
	 * Expects POST with 'ids' (comma-separated article IDs) and 'nonce'.
	 * Cascading foreign keys on the clicks and daily_stats tables handle
	 * related analytics cleanup automatically.
	 *
	 * @since 2.1.0
	 */
	public static function ajax_delete_articles() {
		check_ajax_referer( 'peptide_news_admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized.', 403 );
		}

		$raw_ids = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
		if ( empty( $raw_ids ) ) {
			wp_send_json_error( 'No article IDs provided.' );
		}

		$ids = array_filter( array_map( 'absint', explode( ',', $raw_ids ) ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( 'Invalid article IDs.' );
		}

		global $wpdb;
		$table        = $wpdb->prefix . 'peptide_news_articles';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE id IN ({$placeholders})",
				$ids
			)
		);

		if ( false === $deleted ) {
			wp_send_json_error( 'Database error.' );
		}

		// Clear article cache so the frontend reflects changes.
		delete_transient( 'peptide_news_articles_1_10' );

		Peptide_News_Logger::info( 'Deleted ' . $deleted . ' article(s): IDs ' . implode( ', ', $ids ), 'admin' );

		wp_send_json_success( array(
			'deleted' => $deleted,
			'ids'     => $ids,
		) );
	}
}
