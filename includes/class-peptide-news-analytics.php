<?php
/**
 * Click analytics tracking and reporting.
 *
 * Handles recording click events, aggregating daily stats,
 * and generating trend/topic reports.
 *
 * @since 1.0.0
 */
class Peptide_News_Analytics {

    /**
     * Record a click event for an article.
     *
     * @param int    $article_id
     * @param array  $meta  Additional context (IP, UA, referrer, etc.).
     * @return bool
     */
    public static function record_click( $article_id, $meta = array() ) {
        global $wpdb;

        $table = $wpdb->prefix . 'peptide_news_clicks';

        $ip = isset( $meta['ip'] ) ? $meta['ip'] : self::get_client_ip();

        // Optionally anonymize IP (GDPR-friendly).
        if ( get_option( 'peptide_news_anonymize_ip', 1 ) ) {
            $ip = self::anonymize_ip( $ip );
        }

        $data = array(
            'article_id'  => absint( $article_id ),
            'clicked_at'  => current_time( 'mysql' ),
            'user_ip'     => sanitize_text_field( $ip ),
            'user_agent'  => sanitize_text_field( isset( $meta['user_agent'] ) ? $meta['user_agent'] : '' ),
            'referrer_url' => esc_url_raw( isset( $meta['referrer'] ) ? $meta['referrer'] : '' ),
            'page_url'    => esc_url_raw( isset( $meta['page_url'] ) ? $meta['page_url'] : '' ),
            'session_id'  => sanitize_text_field( isset( $meta['session_id'] ) ? $meta['session_id'] : '' ),
            'user_id'     => is_user_logged_in() ? get_current_user_id() : null,
            'device_type' => sanitize_text_field( self::detect_device( isset( $meta['user_agent'] ) ? $meta['user_agent'] : '' ) ),
        );

        $result = $wpdb->insert( $table, $data );

        // Update daily aggregate.
        if ( false !== $result ) {
            self::update_daily_stats( $article_id, $ip );
        }

        return false !== $result;
    }

    /**
     * Update daily aggregated stats.
     *
     * @param int    $article_id
     * @param string $ip  Anonymized IP for unique visitor counting.
     */
    private static function update_daily_stats( $article_id, $ip ) {
        global $wpdb;

        $table     = $wpdb->prefix . 'peptide_news_daily_stats';
        $today     = current_time( 'Y-m-d' );
        $clicks_tbl = $wpdb->prefix . 'peptide_news_clicks';

        // Upsert daily click count.
        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, click_count FROM {$table} WHERE article_id = %d AND stat_date = %s",
            $article_id,
            $today
        ) );

        // Count unique IPs for today.
        $unique = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_ip) FROM {$clicks_tbl} WHERE article_id = %d AND DATE(clicked_at) = %s",
            $article_id,
            $today
        ) );

        if ( $existing ) {
            $wpdb->update(
                $table,
                array(
                    'click_count'     => $existing->click_count + 1,
                    'unique_visitors' => (int) $unique,
                ),
                array( 'id' => $existing->id ),
                array( '%d', '%d' ),
                array( '%d' )
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'article_id'      => $article_id,
                    'stat_date'       => $today,
                    'click_count'     => 1,
                    'unique_visitors' => 1,
                )
            );
        }
    }

    /**
     * Get top articles by click count within a date range.
     *
     * @param string $start_date  Y-m-d
     * @param string $end_date    Y-m-d
     * @param int    $limit
     * @return array
     */
    public static function get_top_articles( $start_date, $end_date, $limit = 20 ) {
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
            $start_date,
            $end_date,
            $limit
        ) );
    }

    /**
     * Get click trends over time (daily totals).
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_click_trends( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'peptide_news_daily_stats';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT stat_date, SUM(click_count) AS total_clicks, SUM(unique_visitors) AS total_unique
             FROM {$table}
             WHERE stat_date BETWEEN %s AND %s
             GROUP BY stat_date
             ORDER BY stat_date ASC",
            $start_date,
            $end_date
        ) );
    }

    /**
     * Get popular topics/categories by click volume.
     *
     * @param string $start_date
     * @param string $end_date
     * @param int    $limit
     * @return array
     */
    public static function get_popular_topics( $start_date, $end_date, $limit = 20 ) {
        global $wpdb;

        $stats_table    = $wpdb->prefix . 'peptide_news_daily_stats';
        $articles_table = $wpdb->prefix . 'peptide_news_articles';

        // Get articles with their click counts.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.categories, a.title, SUM(s.click_count) AS clicks
             FROM {$stats_table} s
             INNER JOIN {$articles_table} a ON a.id = s.article_id
             WHERE s.stat_date BETWEEN %s AND %s AND a.categories != ''
             GROUP BY a.id
             ORDER BY clicks DESC",
            $start_date,
            $end_date
        ) );

        // Aggregate by category.
        $topics = array();
        foreach ( $rows as $row ) {
            $cats = array_map( 'trim', explode( ',', $row->categories ) );
            foreach ( $cats as $cat ) {
                if ( empty( $cat ) ) {
                    continue;
                }
                $cat_lower = strtolower( $cat );
                if ( ! isset( $topics[ $cat_lower ] ) ) {
                    $topics[ $cat_lower ] = array(
                        'topic'         => $cat,
                        'total_clicks'  => 0,
                        'article_count' => 0,
                    );
                }
                $topics[ $cat_lower ]['total_clicks']  += (int) $row->clicks;
                $topics[ $cat_lower ]['article_count'] += 1;
            }
        }

        // Sort by clicks.
        usort( $topics, function ( $a, $b ) {
            return $b['total_clicks'] - $a['total_clicks'];
        } );

        return array_slice( $topics, 0, $limit );
    }

    /**
     * Get device breakdown stats.
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_device_breakdown( $start_date, $end_date ) {
        global $wpdb;

        $table = $wpdb->prefix . 'peptide_news_clicks';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT device_type, COUNT(*) AS click_count
             FROM {$table}
             WHERE DATE(clicked_at) BETWEEN %s AND %s
             GROUP BY device_type
             ORDER BY click_count DESC",
            $start_date,
            $end_date
        ) );
    }

    /**
     * Get source performance stats.
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function get_source_performance( $start_date, $end_date ) {
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
            $start_date,
            $end_date
        ) );
    }

    /**
     * Export click data as CSV-ready array.
     *
     * @param string $start_date
     * @param string $end_date
     * @return array
     */
    public static function export_clicks_csv( $start_date, $end_date ) {
        global $wpdb;

        $clicks_table   = $wpdb->prefix . 'peptide_news_clicks';
        $articles_table = $wpdb->prefix . 'peptide_news_articles';
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.clicked_at, a.title, a.source, a.source_url, a.categories,
                    c.referrer_url, c.page_url, c.device_type, c.session_id
             FROM {$clicks_table} c
             INNER JOIN {$articles_table} a ON a.id = c.article_id
             WHERE DATE(c.clicked_at) BETWEEN %s AND %s
             ORDER BY c.clicked_at DESC",
            $start_date,
            $end_date
        ), ARRAY_A );
    }

    /**
     * Get the client IP address.
     *
     * @return string
     */
    private static function get_client_ip() {
        $ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );

        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
                $ip = trim( $ip[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Anonymize an IP address (zero out last octet for IPv4, last 80 bits for IPv6).
     *
     * @param string $ip
     * @return string
     */
    private static function anonymize_ip( $ip ) {
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
            return preg_replace( '/\.\d+$/', '.0', $ip );
        }
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            return substr( $ip, 0, strrpos( $ip, ':' ) ) . ':0000';
        }
        return '0.0.0.0';
    }

    /**
     * Simple device detection from user agent.
     *
     * @param string $ua
     * @return string
     */
    private static function detect_device( $ua ) {
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
}
