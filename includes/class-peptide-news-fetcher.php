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
     */
    public function fetch_all_sources() {
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

        // Store articles (deduplication handled by hash).
        $stored = 0;
        foreach ( $articles as $article ) {
            if ( $this->store_article( $article ) ) {
                $stored++;
            }
        }

        // Log the fetch result.
        update_option( 'peptide_news_last_fetch', array(
            'time'       => current_time( 'mysql' ),
            'found'      => count( $articles ),
            'new_stored' => $stored,
        ) );

        // Prune old articles beyond retention period.
        $this->prune_old_articles();
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
                $thumbnail = '';

                // Try enclosure first.
                $enclosure = $item->get_enclosure();
                if ( $enclosure && $enclosure->get_link() ) {
                    $thumbnail = $enclosure->get_link();
                }

                // Try media:thumbnail or media:content.
                if ( empty( $thumbnail ) ) {
                    $thumbnail = $this->extract_image_from_content( $item->get_content() );
                }

                $pub_date = $item->get_date( 'Y-m-d H:i:s' );
                if ( empty( $pub_date ) ) {
                    $pub_date = current_time( 'mysql' );
                }

                $articles[] = array(
                    'source'        => $this->extract_domain( $feed_url ),
                    'source_url'    => esc_url_raw( $item->get_permalink() ),
                    'title'         => sanitize_text_field( $item->get_title() ),
                    'excerpt'       => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 40 ),
                    'content'       => wp_kses_post( $item->get_content() ),
                    'author'        => sanitize_text_field( $item->get_author() ? $item->get_author()->get_name() : '' ),
                    'thumbnail_url' => esc_url_raw( $thumbnail ),
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
                'thumbnail_url' => esc_url_raw( $item['urlToImage'] ?? '' ),
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

        $table = $wpdb->prefix \. 'peptide_news_articles';
        $hash = hash( 'sha256', $article['source_url'] );

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
     * Try to extract an image URL from HTML content.
     *
     * @param string $content
     * @return string
     */
    private function extract_image_from_content( $content ) {
        if ( empty( $content ) ) {
            return '';
        }

        if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/', $content, $matches ) ) {
            return $matches[1];
        }

        // Check for media:content or media:thumbnail.
        if ( preg_match( '/url=["\']([^"\']+)["\']/', $content, $matches ) ) {
            return $matches[1];
        }

        return '';
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
