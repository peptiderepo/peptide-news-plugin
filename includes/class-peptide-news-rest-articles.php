<?php
declare( strict_types=1 );
/**
 * REST API handler for the /articles endpoint.
 *
 * Returns paginated, cached, active articles with source-name stripping
 * to clean up aggregator suffixes from titles, excerpts, and AI summaries.
 *
 * Called by: Peptide_News_Rest_API::register_routes() (callback for GET /articles).
 * Dependencies: $wpdb, Peptide_News_Source_Resolver (for domain extraction).
 *
 * @since 2.6.0
 * @see   class-peptide-news-rest-api.php — Route registration and analytics endpoints.
 */
class Peptide_News_Rest_Articles {

	/**
	 * GET /articles — returns paginated active articles with transient caching.
	 *
	 * Side effects: DB reads, transient cache read/write.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_articles( $request ) {
		global $wpdb;

		$count  = absint( min( $request->get_param( 'count' ), 100 ) );
		$page   = absint( max( $request->get_param( 'page' ), 1 ) );
		$table  = $wpdb->prefix . 'peptide_news_articles';

		$cache_key       = 'peptide_news_articles_' . $page . '_' . $count;
		$cached_response = get_transient( $cache_key );
		if ( false !== $cached_response ) {
			return rest_ensure_response( $cached_response );
		}

		$total    = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_active = %d", 1 ) );
		$max_page = max( 1, (int) ceil( $total / $count ) );
		$page     = min( $page, $max_page );
		$offset   = ( $page - 1 ) * $count;

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

		$this->clean_source_suffixes( $articles );

		$data = array(
			'articles'    => $articles,
			'total'       => (int) $total,
			'page'        => $page,
			'per_page'    => $count,
			'total_pages' => ceil( $total / $count ),
		);

		set_transient( $cache_key, $data, HOUR_IN_SECONDS );

		return rest_ensure_response( $data );
	}

	/**
	 * Strip source/publisher names from title, excerpt, and ai_summary fields.
	 *
	 * Mutates article objects in-place.
	 *
	 * @param array $articles Array of article objects from DB.
	 */
	private function clean_source_suffixes( array &$articles ): void {
		$nbsp = "\xC2\xA0";

		foreach ( $articles as $article ) {
			foreach ( array( 'title', 'excerpt', 'ai_summary' ) as $field ) {
				if ( empty( $article->$field ) ) {
					continue;
				}
				$article->$field = $this->strip_source_suffix( $article->$field, $article->source, $article->source_url );

				// Catch remaining nbsp-separated publisher names from Google News RSS.
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
	}

	/**
	 * Strip the source/publisher name from the end of a string.
	 *
	 * Handles separators: " - Source", " | Source", " — Source", " – Source".
	 * Tries exact match against known source names, then regex fallback.
	 *
	 * @param string $text       The text to clean.
	 * @param string $source     The source name to strip.
	 * @param string $source_url The source URL (for domain-based publisher names).
	 * @return string Cleaned text.
	 */
	private function strip_source_suffix( string $text, string $source, string $source_url = '' ): string {
		if ( empty( $text ) ) {
			return $text;
		}

		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$nbsp       = "\xC2\xA0";
		$separators = array( ' - ', ' | ', ' — ', ' – ', ' // ', $nbsp . $nbsp, '  ', $nbsp );

		$source_names = $this->collect_source_names( $source, $source_url );

		// Try exact (case-insensitive) match against known source names.
		foreach ( $source_names as $name ) {
			foreach ( $separators as $sep ) {
				$suffix     = $sep . $name;
				$suffix_len = strlen( $suffix );
				if ( strlen( $text ) > $suffix_len && strcasecmp( substr( $text, -$suffix_len ), $suffix ) === 0 ) {
					return rtrim( substr( $text, 0, -$suffix_len ) );
				}
			}
		}

		// Fallback 1: strip trailing " - Short Publisher Name" (1-5 words, starts uppercase).
		$pattern   = '/\s+[-\x{2013}\x{2014}|]\s+[A-Z][A-Za-z0-9\s.\'\-]{0,50}$/u';
		$candidate = preg_replace( $pattern, '', $text );
		if ( $candidate !== $text && strlen( $candidate ) > strlen( $text ) * 0.4 ) {
			return rtrim( $candidate );
		}

		// Fallback 2: strip trailing nbsp-separated publisher names (Google News RSS).
		if ( preg_match( '/^(.{10,}?)\x{00A0}{1,2}([A-Z][^.!?\x{00A0}]{0,55})$/u', $text, $m ) ) {
			if ( mb_strlen( $m[1], 'UTF-8' ) > mb_strlen( $text, 'UTF-8' ) * 0.4 ) {
				return rtrim( $m[1] );
			}
		}

		return $text;
	}

	/**
	 * Build list of source names to match against for suffix stripping.
	 *
	 * @param string $source     Article source field.
	 * @param string $source_url Article source URL.
	 * @return array
	 */
	private function collect_source_names( string $source, string $source_url ): array {
		$names = array();
		if ( ! empty( $source ) ) {
			$names[] = $source;
		}

		// Cached distinct source names to avoid DB query per article.
		$cached_sources = get_transient( 'peptide_news_source_names' );
		if ( false === $cached_sources ) {
			global $wpdb;
			$table          = $wpdb->prefix . 'peptide_news_articles';
			$cached_sources = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} WHERE source != '' ORDER BY source" );
			if ( ! is_array( $cached_sources ) ) {
				$cached_sources = array();
			}
			set_transient( 'peptide_news_source_names', $cached_sources, HOUR_IN_SECONDS );
		}
		$names = array_merge( $names, $cached_sources );

		// Extract domain-based publisher name from URL.
		if ( ! empty( $source_url ) ) {
			$host = wp_parse_url( $source_url, PHP_URL_HOST );
			if ( $host ) {
				$host  = preg_replace( '/^www\./', '', $host );
				$parts = explode( '.', $host );
				if ( count( $parts ) >= 2 ) {
					$domain_name = $parts[ count( $parts ) - 2 ];
					$names[]     = str_replace( '-', ' ', $domain_name );
					$names[]     = $domain_name;
				}
			}
		}

		return $names;
	}
}
