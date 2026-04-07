<?php
/**
 * Fetches news from multiple sources and stores them in the database.
 *
 * Supported sources:
 *  - RSS / Atom feeds (Google News, PubMed, custom)
 *  - NewsAPI.org
 *
 * @since 1.0.0
 */
class Peptide_News_Fetcher {

    /**
     * Add custom WP-Cron schedules.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_custom_cron_schedules( $schedules ) {
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 900,
            'display'  => __( 'Every 15 Minutes', 'peptide-news' ),
        );
        $schedules['every_thirty_minutes'] = array(
            'interval' => 1800,
            'display'  => __( 'Every 30 Minutes', 'peptide-news' ),
        );
        $schedules['every_four_hours'] = array(
            'interval' => 14400,
            'display'  => __( 'Every 4 Hours', 'peptide-news' ),
        );
        $schedules['every_six_hours'] = array(
            'interval' => 21600,
            'display'  => __( 'Every 6 Hours', 'peptide-news' ),
        );
        return $schedules;
    }

    /**
     * Master fetch method — called by WP-Cron.
     * Uses a transient lock to prevent concurrent executions.
     */
    public function fetch_all_sources() {
        // Prevent overlapping fetch jobs.
        $lock_key = 'peptide_news_fetch_lock';
        if ( get_transient( $lock_key ) ) {
            $this->log_error( 'Fetch already in progress. Skipping this cycle.' );
            return;
        }
        set_transient( $lock_key, true, 300 ); // 5-minute lock.

        $articles = array();

        // Fetch from RSS feeds.
        if ( get_option( 'peptide_news_rss_enabled', 1 ) ) {
            $rss_articles = $this->fetch_rss_feeds();
            $articles     = array_merge( $articles, $rss_articles );
        }

        // Fetch from NewsAPI.
        if ( get_option( 'peptide_news_newsapi_enabled', 0 ) ) {
            $api_key = get_option( 'peptide_news_newsapi_key', '' );
            if ( ! empty( $api_key ) ) {
                $newsapi_articles = $this->fetch_newsapi( $api_key );
                $articles         = array_merge( $articles, $newsapi_articles );
            }
        }

        // Filter out ads, press releases, and promotional content.
        $pre_filter_count = count( $articles );
        if ( class_exists( 'Peptide_News_Content_Filter' ) && Peptide_News_Content_Filter::is_enabled() ) {
            $articles = Peptide_News_Content_Filter::filter_articles( $articles );
        }
        $filtered_out = $pre_filter_count - count( $articles );

        // Store articles (deduplication handled by hash).
        $stored = 0;
        foreach ( $articles as $article ) {
            if ( $this->store_article( $article ) ) {
                $stored++;
            }
        }

        // Log the fetch result.
        update_option( 'peptide_news_last_fetch', array(
            'time'         => current_time( 'mysql' ),
            'found'        => $pre_filter_count,
            'filtered_out' => $filtered_out,
            'new_stored'   => $stored,
        ) );

        // Run AI analysis on newly fetched articles (keywords + summary).
        if ( class_exists( 'Peptide_News_LLM' ) && Peptide_News_LLM::is_enabled() ) {
            $llm_batch_size = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
            $llm_processed  = Peptide_News_LLM::process_unanalyzed( $llm_batch_size );

            // Append LLM stats to the fetch log.
            $fetch_log = get_option( 'peptide_news_last_fetch' );
            if ( is_array( $fetch_log ) ) {
                $fetch_log['ai_processed'] = $llm_processed;
                update_option( 'peptide_news_last_fetch', $fetch_log );
            }
        }

        // Prune old articles beyond retention period.
        $this->prune_old_articles();

        // Release the fetch lock.
        delete_transient( $lock_key );
    }

    /**
     * Fetch articles from configured RSS feeds.
     *
     * @return array
     */
    private function fetch_rss_feeds() {
        $feeds_raw = get_option( 'peptide_news_rss_feeds', '' );
        $feeds     = array_filter( array_map( 'trim', explode( "\n", $feeds_raw ) ) );
        $articles  = array();

        if ( ! function_exists( 'fetch_feed' ) ) {
            require_once ABSPATH . WPINC . '/feed.php';
        }

        foreach ( $feeds as $feed_url ) {
            $feed = fetch_feed( esc_url_raw( $feed_url ) );

            if ( is_wp_error( $feed ) ) {
                $this->log_error( 'RSS fetch failed for ' . $feed_url . ': ' . $feed->get_error_message() );
                continue;
            }

            $max_items = $feed->get_item_quantity( 50 );
            $items     = $feed->get_items( 0, $max_items );

            foreach ( $items as $item ) {
                $pub_date = $item->get_date( 'Y-m-d H:i:s' );
                if ( empty( $pub_date ) ) {
                    $pub_date = current_time( 'mysql' );
                }

                $article_url = esc_url_raw( $item->get_permalink() );
                $source_name = $this->resolve_article_source( $item, $feed_url, $article_url );

                $articles[] = array(
                    'source'        => $source_name,
                    'source_url'    => $article_url,
                    'title'         => sanitize_text_field( $item->get_title() ),
                    'excerpt'       => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 40 ),
                    'content'       => wp_kses_post( $item->get_content() ),
                    'author'        => sanitize_text_field( $item->get_author() ? $item->get_author()->get_name() : '' ),
                    'thumbnail_url' => '',
                    'published_at'  => $pub_date,
                    'categories'    => $this->extract_categories( $item ),
                    'tags'          => '',
                    'language'      => 'en',
                );
            }
        }

        return $articles;
    }

    /**
     * Fetch articles from NewsAPI.org.
     *
     * @param string $api_key
     * @return array
     */
    private function fetch_newsapi( $api_key ) {
        $keywords = get_option( 'peptide_news_search_keywords', 'peptide research' );
        $articles = array();

        $url = add_query_arg( array(
            'q'        => urlencode( $keywords ),
            'language' => 'en',
            'sortBy'   => 'publishedAt',
            'pageSize' => 50,
            'apiKey'   => $api_key,
        ), 'https://newsapi.org/v2/everything' );

        $response = wp_remote_get( $url, array(
            'timeout' => 30,
            'headers' => array( 'User-Agent' => 'PeptideNewsWP/' . PEPTIDE_NEWS_VERSION ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'NewsAPI fetch failed: ' . $response->get_error_message() );
            return $articles;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['articles'] ) || 'ok' !== ( $body['status'] ?? '' ) ) {
            $this->log_error( 'NewsAPI returned no articles or error status.' );
            return $articles;
        }

        foreach ( $body['articles'] as $item ) {
            $pub_date = isset( $item['publishedAt'] )
                ? gmdate( 'Y-m-d H:i:s', strtotime( $item['publishedAt'] ) )
                : current_time( 'mysql' );

            $articles[] = array(
                'source'        => sanitize_text_field( $item['source']['name'] ?? 'NewsAPI' ),
                'source_url'    => esc_url_raw( $item['url'] ?? '' ),
                'title'         => sanitize_text_field( $item['title'] ?? '' ),
                'excerpt'       => wp_trim_words( sanitize_text_field( $item['description'] ?? '' ), 40 ),
                'content'       => wp_kses_post( $item['content'] ?? '' ),
                'author'        => sanitize_text_field( $item['author'] ?? '' ),
                'thumbnail_url' => '',
                'published_at'  => $pub_date,
                'categories'    => '',
                'tags'          => '',
                'language'      => 'en',
            );
        }

        return $articles;
    }

    /**
     * Store an article in the database. Deduplication via SHA-256 hash of URL.
     *
     * @param array $article
     * @return bool True if new article was inserted.
     */
    private function store_article( $article ) {
        global $wpdb;

        $table = $wpdb->prefix . 'peptide_news_articles';
        $hash  = hash( 'sha256', $article['source_url'] );

        // Check for duplicate.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE hash = %s",
            $hash
        ) );

        if ( $exists ) {
            return false;
        }

        $result = $wpdb->insert(
            $table,
            array(
                'source'        => $article['source'],
                'source_url'    => $article['source_url'],
                'title'         => $article['title'],
                'excerpt'       => $article['excerpt'],
                'content'       => $article['content'],
                'author'        => $article['author'],
                'thumbnail_url' => $article['thumbnail_url'],
                'published_at'  => $article['published_at'],
                'fetched_at'    => current_time( 'mysql' ),
                'categories'    => $article['categories'],
                'tags'          => $article['tags'],
                'language'      => $article['language'],
                'hash'          => $hash,
                'is_active'     => 1,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
        );

        return false !== $result;
    }

    /**
     * Remove articles older than the retention period.
     */
    private function prune_old_articles() {
        global $wpdb;

        $retention_days = (int) get_option( 'peptide_news_article_retention', 90 );
        $cutoff         = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

        $table = $wpdb->prefix . 'peptide_news_articles';
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table} SET is_active = 0 WHERE published_at < %s",
            $cutoff
        ) );
    }

    /**
     * Clear all article transient caches.
     */
    private function clear_article_cache() {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                    OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_peptide_news_articles_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_peptide_news_articles_' ) . '%'
            )
        );
    }

    /**
     * Extract categories from a SimplePie item.
     *
     * @param SimplePie_Item $item
     * @return string Comma-separated categories.
     */
    private function extract_categories( $item ) {
        $categories = $item->get_categories();
        if ( empty( $categories ) ) {
            return '';
        }

        $names = array();
        foreach ( $categories as $cat ) {
            $names[] = sanitize_text_field( $cat->get_label() );
        }

        return implode( ', ', array_filter( $names ) );
    }

    /**
     * Resolve the real source name for an RSS feed item.
     *
     * Google News RSS wraps articles from other publishers behind
     * news.google.com URLs, so the feed-level domain is useless.
     * This method tries several strategies in priority order:
     *
     * 1. SimplePie <source> element (Google News RSS includes this).
     * 2. Title suffix — Google News formats titles as "Headline - Source".
     * 3. Resolve the redirect URL to get the actual publisher domain.
     * 4. Fall back to the article permalink domain.
     * 5. Last resort: feed URL domain.
     *
     * @param SimplePie_Item $item     The RSS item.
     * @param string         $feed_url The feed URL.
     * @param string         $article_url The article permalink.
     * @return string Source name or domain.
     */
    private function resolve_article_source( $item, $feed_url, $article_url ) {
        $feed_domain = $this->extract_domain( $feed_url );
        $is_aggregator = in_array(
            $feed_domain,
            array( 'news.google.com', 'news.yahoo.com', 'msn.com', 'feedly.com' ),
            true
        );

        // Strategy 1: SimplePie <source> element.
        $source_obj = $item->get_source();
        if ( $source_obj ) {
            $source_title = $source_obj->get_title();
            if ( ! empty( $source_title ) ) {
                return sanitize_text_field( $source_title );
            }
            $source_link = $source_obj->get_link();
            if ( ! empty( $source_link ) ) {
                $domain = $this->extract_domain( $source_link );
                if ( 'unknown' !== $domain ) {
                    return $domain;
                }
            }
        }

        // Strategy 2: Parse "Headline - Source" from title (common in aggregator feeds).
        if ( $is_aggregator ) {
            $title = $item->get_title();
            if ( ! empty( $title ) && preg_match( '/\s[-\x{2013}\x{2014}]\s([^-\x{2013}\x{2014}]+)$/u', $title, $matches ) ) {
                $candidate = trim( $matches[1] );
                if ( mb_strlen( $candidate ) <= 60 && mb_strlen( $candidate ) >= 2 ) {
                    return sanitize_text_field( $candidate );
                }
            }
        }

        // Strategy 3: Resolve Google News redirect URL to actual destination.
        if ( $is_aggregator ) {
            $resolved = $this->resolve_redirect_url( $article_url );
            if ( $resolved && $resolved !== $article_url ) {
                $domain = $this->extract_domain( $resolved );
                if ( 'unknown' !== $domain && $domain !== $feed_domain ) {
                    return $domain;
                }
            }
        }

        // Strategy 4: Use the article permalink domain.
        $article_domain = $this->extract_domain( $article_url );
        if ( 'unknown' !== $article_domain && $article_domain !== $feed_domain ) {
            return $article_domain;
        }

        // Strategy 5: Fall back to the feed domain.
        return $feed_domain;
    }

    /**
     * Resolve a redirect URL to its final destination without downloading the body.
     *
     * @param string $url The URL to resolve.
     * @return string|false The final URL or false on failure.
     */
    private function resolve_redirect_url( $url ) {
        if ( empty( $url ) ) {
            return false;
        }

        $response = wp_remote_head( $url, array(
            'timeout'     => 8,
            'redirection' => 5,
            'user-agent'  => 'Mozilla/5.0 (compatible; PeptideNewsBot/1.0)',
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $final_url = wp_remote_retrieve_header( $response, 'location' );

        if ( empty( $final_url ) && isset( $response['http_response'] ) ) {
            $http_response = $response['http_response'];
            if ( method_exists( $http_response, 'get_response_object' ) ) {
                $raw = $http_response->get_response_object();
                if ( isset( $raw->url ) ) {
                    return $raw->url;
                }
            }
        }

        return ! empty( $final_url ) ? $final_url : false;
    }

    /**
     * Backfill source names for existing articles that show an aggregator domain.
     *
     * Parses the " - Source" suffix from stored titles to update the source column.
     *
     * @return int Number of articles updated.
     */
    public function backfill_article_sources() {
        global $wpdb;

        $table       = $wpdb->prefix . 'peptide_news_articles';
        $aggregators = array( 'news.google.com', 'news.yahoo.com', 'msn.com' );
        $placeholders = implode( ', ', array_fill( 0, count( $aggregators ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $articles = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, source, source_url
             FROM {$table}
             WHERE source IN ({$placeholders})
               AND is_active = 1",
            ...$aggregators
        ) );

        if ( empty( $articles ) ) {
            return 0;
        }

        $updated = 0;

        foreach ( $articles as $article ) {
            $new_source = '';

            // Try parsing "Headline - Source" from title.
            if ( preg_match( '/\s[-\x{2013}\x{2014}]\s([^-\x{2013}\x{2014}]+)$/u', $article->title, $matches ) ) {
                $candidate = trim( $matches[1] );
                if ( mb_strlen( $candidate ) <= 60 && mb_strlen( $candidate ) >= 2 ) {
                    $new_source = sanitize_text_field( $candidate );
                }
            }

            // Fall back to resolving the redirect URL.
            if ( empty( $new_source ) ) {
                $resolved = $this->resolve_redirect_url( $article->source_url );
                if ( $resolved && $resolved !== $article->source_url ) {
                    $domain = $this->extract_domain( $resolved );
                    if ( 'unknown' !== $domain && ! in_array( $domain, $aggregators, true ) ) {
                        $new_source = $domain;
                    }
                }
                usleep( 300000 ); // 0.3s throttle for HTTP requests.
            }

            if ( ! empty( $new_source ) && $new_source !== $article->source ) {
                $wpdb->update(
                    $table,
                    array( 'source' => $new_source ),
                    array( 'id' => $article->id ),
                    array( '%s' ),
                    array( '%d' )
                );
                $updated++;
            }
        }

        if ( $updated > 0 ) {
            $this->clear_article_cache();
        }

        return $updated;
    }

    /**
     * AJAX handler for the backfill action.
     */
    public function ajax_backfill_sources() {
        check_ajax_referer( 'peptide_news_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        $updated = $this->backfill_article_sources();
        wp_send_json_success( array( 'updated' => $updated ) );
    }

    /**
     * Extract domain from a URL for source identification.
     *
     * @param string $url
     * @return string
     */
    private function extract_domain( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        return $host ? preg_replace( '/^www\./', '', $host ) : 'unknown';
    }

    /**
     * Log an error to the WordPress debug log.
     *
     * @param string $message
     */
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Peptide News] ' . $message );
        }
    }
}
