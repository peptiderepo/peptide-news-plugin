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

        // Scrape OG images for articles missing thumbnails.
        $og_scraped = $this->scrape_missing_thumbnails();

        // Run AI analysis on newly fetched articles (keywords + summary).
        if ( class_exists( 'Peptide_News_LLM' ) && Peptide_News_LLM::is_enabled() ) {
            $llm_batch_size = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
            $llm_processed  = Peptide_News_LLM::process_unanalyzed( $llm_batch_size );

            // Generate AI thumbnails for articles still missing images.
            $ai_thumbs = Peptide_News_LLM::generate_missing_thumbnails( $llm_batch_size );

            // Append LLM stats to the fetch log.
            $fetch_log = get_option( 'peptide_news_last_fetch' );
            if ( is_array( $fetch_log ) ) {
                $fetch_log['ai_processed']  = $llm_processed;
                $fetch_log['og_scraped']    = $og_scraped;
                $fetch_log['ai_thumbnails'] = $ai_thumbs;
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
     * Scrape Open Graph images for articles that have no thumbnail.
     *
     * Fetches the article URL and extracts og:image, twitter:image,
     * or other meta image tags.
     *
     * @return int Number of articles updated with scraped thumbnails.
     */
    private function scrape_missing_thumbnails() {
        global $wpdb;

        $table    = $wpdb->prefix . 'peptide_news_articles';
        $articles = $wpdb->get_results(
            "SELECT id, source_url, title
             FROM {$table}
             WHERE is_active = 1
               AND ( thumbnail_url = '' OR thumbnail_url IS NULL )
               AND ( thumbnail_local = '' OR thumbnail_local IS NULL )
             ORDER BY fetched_at DESC
             LIMIT 20"
        );

        if ( empty( $articles ) ) {
            return 0;
        }

        $updated = 0;

        foreach ( $articles as $article ) {
            $image_url = $this->scrape_og_image( $article->source_url );

            if ( ! empty( $image_url ) ) {
                // Download and store the image locally for reliability.
                $local_path = $this->download_thumbnail( $image_url, $article->id, $article->title );

                if ( $local_path ) {
                    $wpdb->update(
                        $table,
                        array(
                            'thumbnail_url'   => $image_url,
                            'thumbnail_local' => $local_path,
                        ),
                        array( 'id' => $article->id ),
                        array( '%s', '%s' ),
                        array( '%d' )
                    );
                } else {
                    // Store the external URL even if local download fails.
                    $wpdb->update(
                        $table,
                        array( 'thumbnail_url' => $image_url ),
                        array( 'id' => $article->id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                }
                $updated++;
            }

            // Small delay to be respectful to source servers.
            usleep( 300000 ); // 0.3s
        }

        // Clear transient cache if we updated any thumbnails.
        if ( $updated > 0 ) {
            $this->clear_article_cache();
        }

        return $updated;
    }

    /**
     * Scrape Open Graph / meta image tags from a URL.
     *
     * @param string $url The article URL to scrape.
     * @return string Image URL or empty string.
     */
    private function scrape_og_image( $url ) {
        if ( empty( $url ) ) {
            return '';
        }

        $response = wp_remote_get( $url, array(
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; PeptideNewsBot/1.0)',
            'headers'    => array(
                'Accept' => 'text/html',
            ),
            // Only fetch the first ~200KB for efficiency.
            'stream'  => false,
            'limit_response_size' => 200000,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'OG scrape failed for ' . $url . ': ' . $response->get_error_message() );
            return '';
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 400 ) {
            return '';
        }

        $html = wp_remote_retrieve_body( $response );

        if ( empty( $html ) ) {
            return '';
        }

        // Try meta tags in priority order.
        $patterns = array(
            // Open Graph image.
            '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']/is',
            // Twitter card image.
            '/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/is',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image["\']/is',
            // Schema.org image.
            '/<meta[^>]+itemprop=["\']image["\'][^>]+content=["\']([^"\']+)["\']/is',
        );

        foreach ( $patterns as $pattern ) {
            if ( preg_match( $pattern, $html, $matches ) ) {
                $image_url = trim( $matches[1] );

                // Validate it looks like an image URL.
                if ( $this->is_valid_image_url( $image_url ) ) {
                    return esc_url_raw( $image_url );
                }
            }
        }

        return '';
    }

    /**
     * Check if a URL appears to be a valid image.
     *
     * @param string $url
     * @return bool
     */
    private function is_valid_image_url( $url ) {
        if ( empty( $url ) || strlen( $url ) < 10 ) {
            return false;
        }

        // Must start with http(s).
        if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
            return false;
        }

        // Reject obvious non-image URLs (tracking pixels, spacers, etc.)
        $blocklist = array( '1x1', 'pixel', 'spacer', 'blank', 'transparent', 'data:image' );
        $url_lower = strtolower( $url );
        foreach ( $blocklist as $blocked ) {
            if ( strpos( $url_lower, $blocked ) !== false ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Download an image and store it in the WP uploads directory.
     *
     * @param string $image_url External image URL.
     * @param int    $article_id Article ID for unique naming.
     * @param string $title      Article title for alt text / file naming.
     * @return string|false Local relative path on success, false on failure.
     */
    private function download_thumbnail( $image_url, $article_id, $title = '' ) {
        // SSRF prevention: validate URL before fetching.
        if ( ! wp_http_validate_url( $image_url ) ) {
            $this->log_error( 'Invalid image URL (SSRF protection) for article ' . $article_id . ': ' . $image_url );
            return false;
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/peptide-news-thumbs';

        // Create directory if it doesn't exist.
        if ( ! file_exists( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }

        $max_file_size = 5 * 1024 * 1024; // 5 MB.

        $response = wp_remote_get( $image_url, array(
            'timeout'             => 20,
            'user-agent'          => 'Mozilla/5.0 (compatible; PeptideNewsBot/1.0)',
            'limit_response_size' => $max_file_size,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log_error( 'Thumbnail download failed for article ' . $article_id . ': ' . $response->get_error_message() );
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) || strlen( $body ) < 1000 || strlen( $body ) > $max_file_size ) {
            // Too small to be a real image, or exceeds size limit.
            return false;
        }

        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        $extension    = $this->get_extension_from_content_type( $content_type );

        if ( ! $extension ) {
            // Try to detect from the image data.
            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $mime  = $finfo->buffer( $body );
            $extension = $this->get_extension_from_content_type( $mime );
        }

        if ( ! $extension ) {
            return false;
        }

        $filename = 'pn-thumb-' . $article_id . '.' . $extension;
        $filepath = $target_dir . '/' . $filename;

        // Write the file.
        $result = file_put_contents( $filepath, $body );

        if ( false === $result ) {
            $this->log_error( 'Failed to write thumbnail file: ' . $filepath );
            return false;
        }

        // Set secure file permissions.
        chmod( $filepath, 0644 );

        // Return the relative path from uploads base.
        return 'peptide-news-thumbs/' . $filename;
    }

    /**
     * Map content type to file extension.
     *
     * @param string $content_type
     * @return string|false
     */
    private function get_extension_from_content_type( $content_type ) {
        $map = array(
            'image/jpeg'    => 'jpg',
            'image/jpg'     => 'jpg',
            'image/png'     => 'png',
            'image/gif'     => 'gif',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg',
        );

        $content_type = strtolower( trim( explode( ';', $content_type )[0] ) );

        return isset( $map[ $content_type ] ) ? $map[ $content_type ] : false;
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
