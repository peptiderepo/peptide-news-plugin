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
     * Returns active articles with no-cache headers so that
     * LiteSpeed / CDN layers always serve fresh data.
     */
    public function get_articles( $request ) {
        global $wpdb;

        $count  = min( $request->get_param( 'count' ), 100 );
        $page   = max( $request->get_param( 'page' ), 1 );
        $offset = ( $page - 1 ) * $count;
        $table  = $wpdb->prefix . 'peptide_news_articles';

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

        // Strip source name from titles and summaries (e.g. "Article Title - Toronto Star").
        foreach ( $articles as $article ) {
            $article->title = $this->strip_source_suffix( $article->title, $article->source, $article->source_url );
            if ( ! empty( $article->ai_summary ) ) {
                $article->ai_summary = $this->strip_source_suffix( $article->ai_summary, $article->source, $article->source_url );
            }
            if ( ! empty( $article->excerpt ) ) {
                $article->excerpt = $this->strip_source_suffix( $article->excerpt, $article->source, $article->source_url );
            }
        }

        $total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1" );

        $response = rest_ensure_response( array(
            'articles'    => $articles,
            'total'       => (int) $total,
            'page'        => $page,
            'per_page'    => $count,
            'total_pages' => ceil( $total / $count ),
        ) );

        // Prevent LiteSpeed and browser caches from serving stale article data.
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

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

        // Fallback: strip trailing " - Short Publisher Name" patterns (1-5 words).
        // Only strips if the part after the separator looks like a publisher name
        // (starts with uppercase, no sentence-ending punctuation).
        $pattern = '/\s+[-\x{2013}\x{2014}|]\s+[A-Z][A-Za-z0-9\s.\'\-]{0,50}$/u';
        $candidate = preg_replace( $pattern, '', $text );
        if ( $candidate !== $text && strlen( $candidate ) > strlen( $text ) * 0.4 ) {
            return rtrim( $candidate );
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
}
