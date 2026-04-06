<?php
/**
 * LLM integration via OpenRouter for article analysis.
 *
 * Provides keyword extraction and article summarization
 * using configurable models through the OpenRouter API.
 *
 * @since 1.1.0
 */
class Peptide_News_LLM {

    /** @var string OpenRouter API endpoint. */
    const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    /** @var int Maximum retries for rate-limited requests. */
    const MAX_RETRIES = 3;

    /** @var int Maximum elapsed seconds before stopping a batch. */
    const BATCH_TIMEOUT = 120;

    /**
     * Check whether LLM processing is enabled and configured.
     *
     * @return bool
     */
    public static function is_enabled() {
        $api_key = get_option( 'peptide_news_openrouter_api_key', '' );
        return ! empty( $api_key ) && (bool) get_option( 'peptide_news_llm_enabled', 0 );
    }

    /**
     * Validate an OpenRouter model ID format.
     *
     * Accepts patterns like: provider/model-name, provider/model-name:version
     *
     * @param string $model
     * @return bool
     */
    public static function is_valid_model( $model ) {
        return (bool) preg_match( '/^[a-zA-Z0-9_-]+\/[a-zA-Z0-9._-]+(:[a-zA-Z0-9._-]+)?$/', $model );
    }

    /**
     * Process a single article: extract keywords and generate a summary.
     *
     * Skips articles that already have both tags and ai_summary unless $force is true.
     *
     * @param object $article  Database row with id, title, excerpt, content, categories.
     * @param bool   $force    Re-process even if already analyzed.
     * @return array            Results with 'keywords', 'summary', and 'success' keys.
     */
    public static function process_article( $article, $force = false ) {
        if ( ! self::is_enabled() ) {
            return array( 'keywords' => '', 'summary' => '', 'success' => false );
        }

        $results = array(
            'keywords' => '',
            'summary'  => '',
            'success'  => false,
        );

        $api_key = get_option( 'peptide_news_openrouter_api_key', '' );
        $any_success = false;

        // --- Keyword extraction ---
        $keywords_model = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
        $need_keywords  = $force || empty( $article->tags );

        if ( $need_keywords && self::is_valid_model( $keywords_model ) ) {
            $keywords_prompt = self::build_keywords_prompt( $article );
            $keywords_result = self::call_openrouter( $api_key, $keywords_model, $keywords_prompt );

            if ( ! is_wp_error( $keywords_result ) ) {
                $results['keywords'] = self::sanitize_keywords( $keywords_result );
                $any_success = true;
            } else {
                self::log_error( 'Keyword extraction failed for article ' . $article->id . ': ' . $keywords_result->get_error_message() );
            }
        } elseif ( $need_keywords ) {
            self::log_error( 'Invalid keywords model ID: ' . $keywords_model );
        }

        // --- Summarization ---
        $summary_model = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );
        $need_summary  = $force || empty( $article->ai_summary );

        if ( $need_summary && self::is_valid_model( $summary_model ) ) {
            $summary_prompt = self::build_summary_prompt( $article );
            $summary_result = self::call_openrouter( $api_key, $summary_model, $summary_prompt );

            if ( ! is_wp_error( $summary_result ) ) {
                // Strip all HTML tags from LLM output to prevent XSS.
                $results['summary'] = sanitize_textarea_field( wp_strip_all_tags( $summary_result ) );
                $any_success = true;
            } else {
                self::log_error( 'Summarization failed for article ' . $article->id . ': ' . $summary_result->get_error_message() );
            }
        } elseif ( $need_summary ) {
            self::log_error( 'Invalid summary model ID: ' . $summary_model );
        }

        $results['success'] = $any_success;

        // --- Persist results ---
        if ( ! empty( $results['keywords'] ) || ! empty( $results['summary'] ) ) {
            self::save_results( $article->id, $results, $force );
        }

        return $results;
    }

    /**
     * Process all unanalyzed articles (called after a fetch cycle).
     *
     * Includes a time-based guard to prevent cron timeouts and
     * respects the admin-configured max articles per cycle.
     *
     * @param int $batch_size  Max articles to process per run.
     * @return int              Number of articles successfully processed.
     */
    public static function process_unanalyzed( $batch_size = 10 ) {
        if ( ! self::is_enabled() ) {
            return 0;
        }

        // Respect admin cost-control setting.
        $max_per_cycle = absint( get_option( 'peptide_news_llm_max_articles', 10 ) );
        if ( $max_per_cycle < 1 ) {
            $max_per_cycle = 10;
        }
        $batch_size = min( $batch_size, $max_per_cycle );

        global $wpdb;
        $table = $wpdb->prefix . 'peptide_news_articles';

        // Find articles missing tags OR ai_summary.
        $articles = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, excerpt, content, categories, tags, ai_summary
             FROM {$table}
             WHERE is_active = 1
               AND ( tags = '' OR ai_summary = '' OR ai_summary IS NULL )
             ORDER BY fetched_at DESC
             LIMIT %d",
            $batch_size
        ) );

        $processed = 0;
        $start_time = time();

        foreach ( $articles as $article ) {
            // Timeout guard: stop if approaching the limit.
            if ( ( time() - $start_time ) >= self::BATCH_TIMEOUT ) {
                self::log_error( 'Batch timeout reached after ' . $processed . ' articles. Remaining will process next cycle.' );
                break;
            }

            $result = self::process_article( $article );

            // Only count successful processing.
            if ( ! empty( $result['success'] ) ) {
                $processed++;
            }

            // Small delay to avoid rate limiting.
            if ( next( $articles ) !== false ) {
                usleep( 500000 ); // 0.5s between articles.
            }
        }

        // Clear article cache once per batch (not per article).
        if ( $processed > 0 ) {
            self::clear_article_cache();
        }

        // Log the processing result.
        update_option( 'peptide_news_last_llm_process', array(
            'time'      => current_time( 'mysql' ),
            'processed' => $processed,
            'attempted' => count( $articles ),
        ) );

        return $processed;
    }

    /**
     * Build the prompt for keyword extraction.
     *
     * @param object $article
     * @return string
     */
    private static function build_keywords_prompt( $article ) {
        $text = $article->title;
        if ( ! empty( $article->excerpt ) ) {
            $text .= "\n\n" . $article->excerpt;
        }
        if ( ! empty( $article->content ) ) {
            // Limit content to ~2000 chars to keep token usage reasonable.
            $text .= "\n\n" . mb_substr( wp_strip_all_tags( $article->content ), 0, 2000 );
        }

        return "Extract 5-10 relevant keywords or key phrases from this peptide research article. "
             . "Return ONLY a comma-separated list of keywords, nothing else. No numbering, no explanations.\n\n"
             . "Article:\n" . $text;
    }

    /**
     * Build the prompt for article summarization.
     *
     * @param object $article
     * @return string
     */
    private static function build_summary_prompt( $article ) {
        $text = $article->title;
        if ( ! empty( $article->excerpt ) ) {
            $text .= "\n\n" . $article->excerpt;
        }
        if ( ! empty( $article->content ) ) {
            $text .= "\n\n" . mb_substr( wp_strip_all_tags( $article->content ), 0, 3000 );
        }

        return "Summarize this peptide research article in 3-4 sentences. "
             . "Be concise, factual, and accessible to a general audience interested in peptide science. "
             . "Do not include any preamble or labels — just the summary text.\n\n"
             . "Article:\n" . $text;
    }

    /**
     * Call the OpenRouter API with retry logic for 429 rate limits.
     *
     * @param string $api_key
     * @param string $model
     * @param string $prompt
     * @return string|WP_Error  The response text or an error.
     */
    private static function call_openrouter( $api_key, $model, $prompt ) {
        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'max_tokens'  => 300,
            'temperature' => 0.3,
        );

        $retries = 0;
        $backoff = 2; // Initial backoff in seconds.

        while ( $retries <= self::MAX_RETRIES ) {
            $response = wp_remote_post( self::API_URL, array(
                'timeout' => 30,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                    'HTTP-Referer'  => home_url(),
                    'X-Title'       => 'Peptide News Aggregator',
                ),
                'body' => wp_json_encode( $body ),
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $status = wp_remote_retrieve_response_code( $response );

            // Handle rate limiting with exponential backoff.
            if ( 429 === $status && $retries < self::MAX_RETRIES ) {
                $retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
                $wait = $retry_after ? min( (int) $retry_after, 30 ) : $backoff;
                sleep( $wait );
                $retries++;
                $backoff *= 2;
                continue;
            }

            break;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status < 200 || $status >= 300 ) {
            $error_msg = 'HTTP ' . $status;
            if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) && isset( $data['error']['message'] ) ) {
                $error_msg = $data['error']['message'];
            }
            return new WP_Error( 'openrouter_error', $error_msg );
        }

        // Validate the response structure thoroughly.
        if ( ! is_array( $data ) ) {
            return new WP_Error( 'openrouter_invalid', 'Invalid JSON response from OpenRouter' );
        }
        if ( ! isset( $data['choices'] ) || ! is_array( $data['choices'] ) || empty( $data['choices'] ) ) {
            return new WP_Error( 'openrouter_empty', 'No choices in OpenRouter response' );
        }
        if ( ! isset( $data['choices'][0]['message'] ) || ! is_array( $data['choices'][0]['message'] ) ) {
            return new WP_Error( 'openrouter_empty', 'Malformed choice in OpenRouter response' );
        }
        if ( ! isset( $data['choices'][0]['message']['content'] ) || '' === trim( $data['choices'][0]['message']['content'] ) ) {
            return new WP_Error( 'openrouter_empty', 'Empty content in OpenRouter response' );
        }

        return trim( $data['choices'][0]['message']['content'] );
    }

    /**
     * Sanitize and normalize keyword output from the LLM.
     *
     * @param string $raw  Raw comma-separated keywords.
     * @return string       Cleaned, deduplicated, comma-separated list.
     */
    private static function sanitize_keywords( $raw ) {
        // Strip all HTML first to prevent XSS.
        $raw = wp_strip_all_tags( $raw );

        // Remove any numbering, bullets, or extra formatting.
        $raw = preg_replace( '/^\d+[\.\)]\s*/m', '', $raw );
        $raw = preg_replace( '/^[-*\x{2022}]\s*/mu', '', $raw );

        $keywords = array_map( 'trim', explode( ',', $raw ) );
        $keywords = array_map( 'sanitize_text_field', $keywords );
        $keywords = array_filter( $keywords );
        $keywords = array_unique( $keywords );

        // Limit to 10 keywords max.
        $keywords = array_slice( $keywords, 0, 10 );

        return implode( ', ', $keywords );
    }

    /**
     * Save LLM results to the database.
     *
     * Note: Cache is NOT cleared here — the caller (process_unanalyzed)
     * clears cache once per batch for efficiency.
     *
     * @param int   $article_id
     * @param array $results     Array with 'keywords' and 'summary'.
     * @param bool  $force       Overwrite existing values.
     */
    private static function save_results( $article_id, $results, $force = false ) {
        global $wpdb;
        $table = $wpdb->prefix . 'peptide_news_articles';

        $update = array();
        $format = array();

        if ( ! empty( $results['keywords'] ) ) {
            $update['tags'] = $results['keywords'];
            $format[]       = '%s';
        }

        if ( ! empty( $results['summary'] ) ) {
            $update['ai_summary'] = $results['summary'];
            $format[]             = '%s';
        }

        if ( ! empty( $update ) ) {
            $wpdb->update(
                $table,
                $update,
                array( 'id' => $article_id ),
                $format,
                array( '%d' )
            );
        }
    }

    /**
     * Generate AI thumbnails for articles that still have no image
     * after OG scraping. Uses an image generation model via OpenRouter.
     *
     * @param int $batch_size Max articles to process per run.
     * @return int Number of thumbnails generated.
     */
    public static function generate_missing_thumbnails( $batch_size = 10 ) {
        if ( ! self::is_enabled() ) {
            return 0;
        }

        $image_model = get_option( 'peptide_news_llm_image_model', '' );
        if ( empty( $image_model ) ) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'peptide_news_articles';

        // Find active articles with no thumbnail (neither URL nor local).
        $articles = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, title, excerpt, ai_summary, tags, categories
             FROM {$table}
             WHERE is_active = 1
               AND ( thumbnail_url = '' OR thumbnail_url IS NULL )
               AND ( thumbnail_local = '' OR thumbnail_local IS NULL )
             ORDER BY fetched_at DESC
             LIMIT %d",
            $batch_size
        ) );

        if ( empty( $articles ) ) {
            return 0;
        }

        $api_key   = get_option( 'peptide_news_openrouter_api_key', '' );
        $generated = 0;

        foreach ( $articles as $article ) {
            $image_data = self::generate_article_thumbnail( $api_key, $image_model, $article );

            if ( $image_data ) {
                // Save the image locally.
                $local_path = self::save_generated_image( $image_data, $article->id );

                if ( $local_path ) {
                    $wpdb->update(
                        $table,
                        array( 'thumbnail_local' => $local_path ),
                        array( 'id' => $article->id ),
                        array( '%s' ),
                        array( '%d' )
                    );
                    $generated++;
                }
            }

            // Respect rate limits.
            usleep( 1000000 ); // 1 second between generations.
        }

        if ( $generated > 0 ) {
            self::clear_article_cache();
        }

        return $generated;
    }

    /**
     * Generate a thumbnail image for a single article via OpenRouter.
     *
     * @param string $api_key
     * @param string $model   Image generation model ID.
     * @param object $article Database row.
     * @return string|false   Base64-encoded image data, or false.
     */
    private static function generate_article_thumbnail( $api_key, $model, $article ) {
        $prompt = self::build_image_prompt( $article );

        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
        );

        $response = wp_remote_post( self::API_URL, array(
            'timeout' => 60,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => 'Peptide News Aggregator',
            ),
            'body' => wp_json_encode( $body ),
        ) );

        if ( is_wp_error( $response ) ) {
            self::log_error( 'Image generation failed for article ' . $article->id . ': ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status < 200 || $status >= 300 ) {
            $error = isset( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $status;
            self::log_error( 'Image generation HTTP error for article ' . $article->id . ': ' . $error );
            return false;
        }

        // Extract the image data from the response.
        // OpenRouter image models may return base64 data in various formats.
        if ( ! empty( $data['choices'][0]['message']['content'] ) ) {
            $content = $data['choices'][0]['message']['content'];

            // If content is an array (multimodal), look for image parts.
            if ( is_array( $content ) ) {
                foreach ( $content as $part ) {
                    if ( isset( $part['type'] ) && 'image_url' === $part['type']
                         && isset( $part['image_url'] ) && is_array( $part['image_url'] )
                         && isset( $part['image_url']['url'] ) && is_string( $part['image_url']['url'] ) ) {
                        $url = $part['image_url']['url'];
                        if ( strpos( $url, 'data:image' ) === 0 ) {
                            // Extract base64 from data URL.
                            $base64 = preg_replace( '/^data:image\/\w+;base64,/', '', $url );
                            $decoded = base64_decode( $base64, true );
                            if ( false === $decoded ) {
                                self::log_error( 'Invalid base64 image data for article.' );
                                return false;
                            }
                            return $decoded;
                        }
                    }
                }
            }

            // If it's a URL string, download it (with SSRF protection).
            if ( is_string( $content ) && preg_match( '/^https?:\/\//', $content ) ) {
                $content_url = trim( $content );
                if ( ! wp_http_validate_url( $content_url ) ) {
                    self::log_error( 'Invalid image URL (SSRF protection) for article ' . $article->id );
                    return false;
                }
                $img_response = wp_remote_get( $content_url, array(
                    'timeout'             => 30,
                    'limit_response_size' => 5 * 1024 * 1024,
                ) );
                if ( ! is_wp_error( $img_response ) ) {
                    return wp_remote_retrieve_body( $img_response );
                }
            }
        }

        // Check for data array format (DALL-E style).
        if ( ! empty( $data['data'][0]['b64_json'] ) ) {
            $decoded = base64_decode( $data['data'][0]['b64_json'], true );
            if ( false === $decoded ) {
                self::log_error( 'Invalid base64 in DALL-E response for article ' . $article->id );
                return false;
            }
            return $decoded;
        }

        if ( ! empty( $data['data'][0]['url'] ) ) {
            $dalle_url = $data['data'][0]['url'];
            if ( wp_http_validate_url( $dalle_url ) ) {
                $img_response = wp_remote_get( $dalle_url, array(
                    'timeout'             => 30,
                    'limit_response_size' => 5 * 1024 * 1024,
                ) );
                if ( ! is_wp_error( $img_response ) ) {
                    return wp_remote_retrieve_body( $img_response );
                }
            } else {
                self::log_error( 'Invalid DALL-E image URL (SSRF protection) for article ' . $article->id );
            }
        }

        self::log_error( 'Could not extract image data from response for article ' . $article->id );
        return false;
    }

    /**
     * Build the prompt for AI image generation.
     *
     * @param object $article
     * @return string
     */
    private static function build_image_prompt( $article ) {
        $summary = ! empty( $article->ai_summary ) ? $article->ai_summary : $article->excerpt;
        $tags    = ! empty( $article->tags ) ? $article->tags : ( $article->categories ?? '' );

        return "Generate a professional, science-themed thumbnail image for a peptide research news article. "
             . "The image should be modern, clean, and suitable for a news website card layout (16:9 aspect ratio). "
             . "Use a scientific aesthetic: molecular structures, laboratory elements, or abstract biotech visuals. "
             . "Do NOT include any text or words in the image.\n\n"
             . "Article title: " . $article->title . "\n"
             . ( ! empty( $summary ) ? "Summary: " . mb_substr( $summary, 0, 200 ) . "\n" : '' )
             . ( ! empty( $tags ) ? "Keywords: " . $tags . "\n" : '' );
    }

    /**
     * Save a generated image to the WP uploads directory.
     *
     * @param string $image_data Raw image binary data.
     * @param int    $article_id
     * @return string|false Relative path from uploads base, or false.
     */
    private static function save_generated_image( $image_data, $article_id ) {
        if ( empty( $image_data ) || strlen( $image_data ) < 1000 ) {
            return false;
        }

        $upload_dir = wp_upload_dir();
        $target_dir = $upload_dir['basedir'] . '/peptide-news-thumbs';

        if ( ! file_exists( $target_dir ) ) {
            wp_mkdir_p( $target_dir );
        }

        // Detect image type from data.
        $finfo     = new finfo( FILEINFO_MIME_TYPE );
        $mime      = $finfo->buffer( $image_data );
        $ext_map   = array(
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
        );
        $extension = isset( $ext_map[ $mime ] ) ? $ext_map[ $mime ] : 'png';

        $filename = 'pn-ai-' . $article_id . '.' . $extension;
        $filepath = $target_dir . '/' . $filename;

        $result = file_put_contents( $filepath, $image_data );

        if ( false === $result ) {
            self::log_error( 'Failed to save generated thumbnail: ' . $filepath );
            return false;
        }

        // Set secure file permissions.
        chmod( $filepath, 0644 );

        return 'peptide-news-thumbs/' . $filename;
    }

    /**
     * Clear all article transient caches.
     */
    private static function clear_article_cache() {
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
     * Log an error to the WordPress debug log.
     *
     * @param string $message
     */
    private static function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Peptide News LLM] ' . $message );
        }
    }
}
