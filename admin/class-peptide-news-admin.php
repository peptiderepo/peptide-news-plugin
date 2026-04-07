<?php
/**
 * Admin-specific functionality.
 *
 * Registers the settings page, analytics dashboard, and admin assets.
 *
 * @since 1.0.0
 */
class Peptide_News_Admin {

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    /**
     * Enqueue admin CSS.
     */
    public function enqueue_styles( $hook ) {
        if ( strpos( $hook, 'peptide-news' ) === false ) {
            return;
        }
        wp_enqueue_style(
            $this->plugin_name . '-admin',
            PEPTIDE_NEWS_PLUGIN_URL . 'admin/css/admin-style.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Enqueue admin JS.
     */
    public function enqueue_scripts( $hook ) {
        if ( strpos( $hook, 'peptide-news' ) === false ) {
            return;
        }

        // Chart.js for analytics dashboard.
        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );

        wp_enqueue_script(
            $this->plugin_name . '-admin',
            PEPTIDE_NEWS_PLUGIN_URL . 'admin/js/admin-script.js',
            array( 'jquery', 'chartjs' ),
            $this->version,
            true
        );

        wp_localize_script( $this->plugin_name . '-admin', 'peptideNewsAdmin', array(
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'rest_url'    => rest_url( 'peptide-news/v1/' ),
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'admin_nonce' => wp_create_nonce( 'peptide_news_admin' ),
        ) );
    }

    /**
     * Add admin menu pages.
     */
    public function add_admin_menu() {
        // Main menu — Analytics Dashboard.
        add_menu_page(
            __( 'Peptide News', 'peptide-news' ),
            __( 'Peptide News', 'peptide-news' ),
            'manage_options',
            'peptide-news-dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-rss',
            30
        );

        // Sub-menu — Settings.
        add_submenu_page(
            'peptide-news-dashboard',
            __( 'Settings', 'peptide-news' ),
            __( 'Settings', 'peptide-news' ),
            'manage_options',
            'peptide-news-settings',
            array( $this, 'render_settings_page' )
        );

        // Sub-menu — Articles.
        add_submenu_page(
            'peptide-news-dashboard',
            __( 'Articles', 'peptide-news' ),
            __( 'Articles', 'peptide-news' ),
            'manage_options',
            'peptide-news-articles',
            array( $this, 'render_articles_page' )
        );
    }

    /**
     * Register all settings.
     */
    public function register_settings() {

        // --- General section ---
        add_settings_section(
            'peptide_news_general',
            __( 'General Settings', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Configure how and when peptide news articles are fetched.', 'peptide-news' ) . '</p>';
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'fetch_interval', __( 'Fetch Interval', 'peptide-news' ), 'render_fetch_interval_field', 'peptide_news_general' );
        $this->add_setting( 'articles_count', __( 'Articles to Display', 'peptide-news' ), 'render_articles_count_field', 'peptide_news_general' );
        $this->add_setting( 'search_keywords', __( 'Search Keywords', 'peptide-news' ), 'render_search_keywords_field', 'peptide_news_general' );

        // --- RSS section ---
        add_settings_section(
            'peptide_news_rss',
            __( 'RSS Feed Sources', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Add RSS feed URLs, one per line.', 'peptide-news' ) . '</p>';
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'rss_enabled', __( 'Enable RSS Feeds', 'peptide-news' ), 'render_rss_enabled_field', 'peptide_news_rss' );
        $this->add_setting( 'rss_feeds', __( 'RSS Feed URLs', 'peptide-news' ), 'render_rss_feeds_field', 'peptide_news_rss' );

        // --- NewsAPI section ---
        add_settings_section(
            'peptide_news_newsapi',
            __( 'NewsAPI.org', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Optional. Get a free API key at newsapi.org.', 'peptide-news' ) . '</p>';
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'newsapi_enabled', __( 'Enable NewsAPI', 'peptide-news' ), 'render_newsapi_enabled_field', 'peptide_news_newsapi' );
        $this->add_setting( 'newsapi_key', __( 'API Key', 'peptide-news' ), 'render_newsapi_key_field', 'peptide_news_newsapi' );

        // --- Analytics section ---
        add_settings_section(
            'peptide_news_analytics_section',
            __( 'Analytics', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Configure click tracking and data retention.', 'peptide-news' ) . '</p>';
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'article_retention', __( 'Article Retention (days)', 'peptide-news' ), 'render_article_retention_field', 'peptide_news_analytics_section' );
        $this->add_setting( 'analytics_retention', __( 'Analytics Data Retention (days)', 'peptide-news' ), 'render_retention_field', 'peptide_news_analytics_section' );
        $this->add_setting( 'anonymize_ip', __( 'Anonymize IPs', 'peptide-news' ), 'render_anonymize_ip_field', 'peptide_news_analytics_section' );

        // --- Content Filter section ---
        add_settings_section(
            'peptide_news_filter_section',
            __( 'Content Filter', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Filter out ads, press releases, and promotional content during fetch. Articles that match the rules are discarded before being saved.', 'peptide-news' ) . '</p>';
                $last_run = get_option( 'peptide_news_filter_last_run' );
                if ( $last_run && is_array( $last_run ) ) {
                    printf(
                        '<p class="description"><strong>%s:</strong> %s | %s: %d | %s: %d | %s: %d | %s: %d</p>',
                        esc_html__( 'Last filter run', 'peptide-news' ),
                        esc_html( $last_run['time'] ),
                        esc_html__( 'Evaluated', 'peptide-news' ),
                        absint( $last_run['total'] ),
                        esc_html__( 'Removed', 'peptide-news' ),
                        absint( $last_run['removed'] ),
                        esc_html__( 'LLM checked', 'peptide-news' ),
                        absint( $last_run['llm_checked'] ),
                        esc_html__( 'Passed', 'peptide-news' ),
                        absint( $last_run['passed'] )
                    );
                }
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'filter_enabled', __( 'Enable Content Filter', 'peptide-news' ), 'render_filter_enabled_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_sensitivity', __( 'Filter Sensitivity', 'peptide-news' ), 'render_filter_sensitivity_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_llm_enabled', __( 'LLM Classification', 'peptide-news' ), 'render_filter_llm_enabled_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_llm_model', __( 'Filter LLM Model', 'peptide-news' ), 'render_filter_llm_model_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_title_keywords', __( 'Title Keywords', 'peptide-news' ), 'render_filter_title_keywords_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_body_keywords', __( 'Body Keywords', 'peptide-news' ), 'render_filter_body_keywords_field', 'peptide_news_filter_section' );
        $this->add_setting( 'filter_blocked_domains', __( 'Blocked Domains', 'peptide-news' ), 'render_filter_blocked_domains_field', 'peptide_news_filter_section' );

        // --- AI / LLM section ---
        add_settings_section(
            'peptide_news_llm_section',
            __( 'AI Analysis (OpenRouter)', 'peptide-news' ),
            function () {
                echo '<p>' . esc_html__( 'Configure LLM-powered keyword extraction and article summarization via OpenRouter.', 'peptide-news' ) . '</p>';
            },
            'peptide-news-settings'
        );

        $this->add_setting( 'llm_enabled', __( 'Enable AI Analysis', 'peptide-news' ), 'render_llm_enabled_field', 'peptide_news_llm_section' );
        $this->add_setting( 'openrouter_api_key', __( 'OpenRouter API Key', 'peptide-news' ), 'render_openrouter_key_field', 'peptide_news_llm_section' );
        $this->add_setting( 'llm_keywords_model', __( 'Keywords Model', 'peptide-news' ), 'render_llm_keywords_model_field', 'peptide_news_llm_section' );
        $this->add_setting( 'llm_summary_model', __( 'Summary Model', 'peptide-news' ), 'render_llm_summary_model_field', 'peptide_news_llm_section' );

        $this->add_setting( 'llm_max_articles', __( 'Max Articles per Cycle', 'peptide-news' ), 'render_llm_max_articles_field', 'peptide_news_llm_section' );
    }

    /**
     * Helper: register a single setting with a field callback.
     */
    private function add_setting( $key, $label, $callback, $section ) {
        $option_name = "peptide_news_{$key}";

        $sanitize_cb = $this->get_sanitize_callback( $key );

        register_setting( 'peptide_news_settings_group', $option_name, array(
            'sanitize_callback' => $sanitize_cb,
        ) );

        add_settings_field(
            $option_name,
            $label,
            array( $this, $callback ),
            'peptide-news-settings',
            $section
        );
    }

    /**
     * Return the appropriate sanitize callback for a given setting key.
     *
     * @param string $key Setting key (without prefix).
     * @return callable
     */
    private function get_sanitize_callback( $key ) {
        $callbacks = array(
            'fetch_interval'       => 'sanitize_text_field',
            'articles_count'       => 'absint',
            'search_keywords'      => 'sanitize_text_field',
            'rss_enabled'          => 'absint',
            'rss_feeds'            => 'sanitize_textarea_field',
            'newsapi_enabled'      => 'absint',
            'newsapi_key'          => 'sanitize_text_field',
            'article_retention'    => 'absint',
            'analytics_retention'  => 'absint',
            'anonymize_ip'         => 'absint',
            'filter_enabled'          => 'absint',
            'filter_sensitivity'      => 'sanitize_text_field',
            'filter_llm_enabled'      => 'absint',
            'filter_llm_model'        => array( $this, 'sanitize_model_id' ),
            'filter_title_keywords'   => 'sanitize_textarea_field',
            'filter_body_keywords'    => 'sanitize_textarea_field',
            'filter_blocked_domains'  => 'sanitize_textarea_field',
            'llm_enabled'          => 'absint',
            'openrouter_api_key'   => 'sanitize_text_field',
            'llm_keywords_model'   => array( $this, 'sanitize_model_id' ),
            'llm_summary_model'    => array( $this, 'sanitize_model_id' ),
            'llm_max_articles'     => 'absint',
        );

        return isset( $callbacks[ $key ] ) ? $callbacks[ $key ] : 'sanitize_text_field';
    }

    /**
     * Sanitize and validate an OpenRouter model ID.
     *
     * @param string $value Raw input.
     * @return string Sanitized model ID or default.
     */
    public function sanitize_model_id( $value ) {
        $value = sanitize_text_field( $value );
        if ( ! empty( $value ) && class_exists( 'Peptide_News_LLM' ) && ! Peptide_News_LLM::is_valid_model( $value ) ) {
            add_settings_error(
                'peptide_news_settings_group',
                'invalid_model',
                sprintf(
                    /* translators: %s: model ID */
                    __( 'Invalid model ID format: %s. Expected format: provider/model-name', 'peptide-news' ),
                    esc_html( $value )
                ),
                'error'
            );
            return 'google/gemini-2.0-flash-001';
        }
        return $value;
    }

    // --- Field renderers ---

    public function render_fetch_interval_field() {
        $value   = get_option( 'peptide_news_fetch_interval', 'twicedaily' );
        $options = array(
            'every_fifteen_minutes' => __( 'Every 15 minutes', 'peptide-news' ),
            'every_thirty_minutes'  => __( 'Every 30 minutes', 'peptide-news' ),
            'hourly'                => __( 'Hourly', 'peptide-news' ),
            'every_four_hours'      => __( 'Every 4 hours', 'peptide-news' ),
            'every_six_hours'       => __( 'Every 6 hours', 'peptide-news' ),
            'twicedaily'            => __( 'Twice daily', 'peptide-news' ),
            'daily'                 => __( 'Daily', 'peptide-news' ),
        );

        echo '<select name="peptide_news_fetch_interval" id="peptide_news_fetch_interval">';
        foreach ( $options as $key => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $key ),
                selected( $value, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'How often to check for new articles. After changing, deactivate and reactivate the plugin.', 'peptide-news' ) . '</p>';
    }

    public function render_articles_count_field() {
        $value = get_option( 'peptide_news_articles_count', 10 );
        printf(
            '<input type="number" name="peptide_news_articles_count" value="%d" min="1" max="100" class="small-text" />',
            absint( $value )
        );
        echo '<p class="description">' . esc_html__( 'Number of articles to display in the shortcode/widget.', 'peptide-news' ) . '</p>';
    }

    public function render_search_keywords_field() {
        $value = get_option( 'peptide_news_search_keywords', '' );
        printf(
            '<input type="text" name="peptide_news_search_keywords" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__( 'Comma-separated keywords for NewsAPI searches.', 'peptide-news' ) . '</p>';
    }

    public function render_rss_enabled_field() {
        $value = get_option( 'peptide_news_rss_enabled', 1 );
        printf(
            '<label><input type="checkbox" name="peptide_news_rss_enabled" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Fetch articles from RSS feeds', 'peptide-news' )
        );
    }

    public function render_rss_feeds_field() {
        $value = get_option( 'peptide_news_rss_feeds', '' );
        printf(
            '<textarea name="peptide_news_rss_feeds" rows="6" class="large-text code">%s</textarea>',
            esc_textarea( $value )
        );
    }

    public function render_newsapi_enabled_field() {
        $value = get_option( 'peptide_news_newsapi_enabled', 0 );
        printf(
            '<label><input type="checkbox" name="peptide_news_newsapi_enabled" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Fetch articles from NewsAPI.org', 'peptide-news' )
        );
    }

    public function render_newsapi_key_field() {
        $value = get_option( 'peptide_news_newsapi_key', '' );
        printf(
            '<input type="password" name="peptide_news_newsapi_key" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
    }

    public function render_article_retention_field() {
        $value = get_option( 'peptide_news_article_retention', 90 );
        printf(
            '<input type="number" name="peptide_news_article_retention" value="%d" min="7" max="3650" class="small-text" />',
            absint( $value )
        );
        echo '<p class="description">' . esc_html__( 'How long to keep fetched articles before deactivating them.', 'peptide-news' ) . '</p>';
    }

    public function render_retention_field() {
        $value = get_option( 'peptide_news_analytics_retention', 365 );
        printf(
            '<input type="number" name="peptide_news_analytics_retention" value="%d" min="30" max="3650" class="small-text" />',
            absint( $value )
        );
        echo '<p class="description">' . esc_html__( 'How long to keep click analytics data.', 'peptide-news' ) . '</p>';
    }

    public function render_anonymize_ip_field() {
        $value = get_option( 'peptide_news_anonymize_ip', 1 );
        printf(
            '<label><input type="checkbox" name="peptide_news_anonymize_ip" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Anonymize IP addresses for GDPR compliance', 'peptide-news' )
        );
    }

    // --- Content Filter field renderers ---

    public function render_filter_enabled_field() {
        $value = get_option( 'peptide_news_filter_enabled', 1 );
        printf(
            '<label><input type="checkbox" name="peptide_news_filter_enabled" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Filter out ads, press releases, and promotional content during fetch', 'peptide-news' )
        );
    }

    public function render_filter_sensitivity_field() {
        $value   = get_option( 'peptide_news_filter_sensitivity', 'moderate' );
        $options = array(
            'strict'   => __( 'Strict — block aggressively (may catch some legitimate articles)', 'peptide-news' ),
            'moderate' => __( 'Moderate — balanced filtering (recommended)', 'peptide-news' ),
            'lenient'  => __( 'Lenient — only block obvious promotional content', 'peptide-news' ),
        );

        echo '<select name="peptide_news_filter_sensitivity" id="peptide_news_filter_sensitivity">';
        foreach ( $options as $key => $label ) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $key ),
                selected( $value, $key, false ),
                esc_html( $label )
            );
        }
        echo '</select>';
    }

    public function render_filter_llm_enabled_field() {
        $value = get_option( 'peptide_news_filter_llm_enabled', 0 );
        printf(
            '<label><input type="checkbox" name="peptide_news_filter_llm_enabled" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Use LLM to classify borderline articles (requires AI Analysis to be enabled)', 'peptide-news' )
        );
    }

    public function render_filter_llm_model_field() {
        $value = get_option( 'peptide_news_filter_llm_model', '' );
        printf(
            '<input type="text" name="peptide_news_filter_llm_model" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__( 'OpenRouter model for content classification. Leave blank to use the Keywords Model above.', 'peptide-news' ) . '</p>';
    }

    public function render_filter_title_keywords_field() {
        $value = get_option( 'peptide_news_filter_title_keywords', '' );
        $placeholder = class_exists( 'Peptide_News_Content_Filter' )
            ? Peptide_News_Content_Filter::get_default_title_keywords_text()
            : '';
        printf(
            '<textarea name="peptide_news_filter_title_keywords" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
            esc_attr( $placeholder ),
            esc_textarea( $value )
        );
        echo '<p class="description">' . esc_html__( 'One keyword/phrase per line. Articles with these in the title are blocked. Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
    }

    public function render_filter_body_keywords_field() {
        $value = get_option( 'peptide_news_filter_body_keywords', '' );
        $placeholder = class_exists( 'Peptide_News_Content_Filter' )
            ? Peptide_News_Content_Filter::get_default_body_keywords_text()
            : '';
        printf(
            '<textarea name="peptide_news_filter_body_keywords" rows="8" class="large-text code" placeholder="%s">%s</textarea>',
            esc_attr( $placeholder ),
            esc_textarea( $value )
        );
        echo '<p class="description">' . esc_html__( 'One keyword/phrase per line. Multiple matches in article body trigger filtering (threshold depends on sensitivity). Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
    }

    public function render_filter_blocked_domains_field() {
        $value = get_option( 'peptide_news_filter_blocked_domains', '' );
        $placeholder = class_exists( 'Peptide_News_Content_Filter' )
            ? Peptide_News_Content_Filter::get_default_blocked_domains_text()
            : '';
        printf(
            '<textarea name="peptide_news_filter_blocked_domains" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
            esc_attr( $placeholder ),
            esc_textarea( $value )
        );
        echo '<p class="description">' . esc_html__( 'One domain per line. Articles from these sources are always blocked. Leave empty to use built-in defaults.', 'peptide-news' ) . '</p>';
    }

    // --- AI / LLM field renderers ---

    public function render_llm_enabled_field() {
        $value = get_option( 'peptide_news_llm_enabled', 0 );
        printf(
            '<label><input type="checkbox" name="peptide_news_llm_enabled" value="1" %s /> %s</label>',
            checked( $value, 1, false ),
            esc_html__( 'Analyze articles with AI during each fetch cycle', 'peptide-news' )
        );
    }

    public function render_openrouter_key_field() {
        $value = get_option( 'peptide_news_openrouter_api_key', '' );
        printf(
            '<input type="password" name="peptide_news_openrouter_api_key" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">' . wp_kses(
            __( 'Get an API key at <a href="https://openrouter.ai/keys" target="_blank" rel="noopener">openrouter.ai/keys</a>.', 'peptide-news' ),
            array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) )
        ) . '</p>';
    }

    public function render_llm_keywords_model_field() {
        $value = get_option( 'peptide_news_llm_keywords_model', 'google/gemini-2.0-flash-001' );
        printf(
            '<input type="text" name="peptide_news_llm_keywords_model" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__( 'OpenRouter model ID for keyword extraction (e.g., google/gemini-2.0-flash-001, openai/gpt-4o-mini).', 'peptide-news' ) . '</p>';
    }

    public function render_llm_summary_model_field() {
        $value = get_option( 'peptide_news_llm_summary_model', 'google/gemini-2.0-flash-001' );
        printf(
            '<input type="text" name="peptide_news_llm_summary_model" value="%s" class="regular-text" />',
            esc_attr( $value )
        );
        echo '<p class="description">' . esc_html__( 'OpenRouter model ID for article summarization (e.g., google/gemini-2.0-flash-001, anthropic/claude-3.5-sonnet).', 'peptide-news' ) . '</p>';
    }

    public function render_llm_max_articles_field() {
        $value = get_option( 'peptide_news_llm_max_articles', 10 );
        printf(
            '<input type="number" name="peptide_news_llm_max_articles" value="%d" min="1" max="50" class="small-text" />',
            absint( $value )
        );
        echo '<p class="description">' . esc_html__( 'Maximum number of articles to analyze with AI per fetch cycle. Controls API costs and prevents cron timeouts.', 'peptide-news' ) . '</p>';
    }

    // --- Page renderers ---

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle manual fetch trigger.
        if ( isset( $_POST['peptide_news_fetch_now'] ) && check_admin_referer( 'peptide_news_fetch_now_action' ) ) {
            $fetcher = new Peptide_News_Fetcher();
            $fetcher->fetch_all_sources();
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Fetch completed!', 'peptide-news' ) . '</p></div>';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

        // Fetch Now button.
        echo '<form method="post" style="display:inline-block;margin-right:12px;">';
        wp_nonce_field( 'peptide_news_fetch_now_action' );
        submit_button( __( 'Fetch Articles Now', 'peptide-news' ), 'secondary', 'peptide_news_fetch_now', false );
        echo '</form>';

        // Backfill Sources button (fixes "news.google.com" source labels).
        echo '<button type="button" id="peptide-backfill-sources" class="button button-secondary">';
        echo esc_html__( 'Fix Article Sources', 'peptide-news' );
        echo '</button>';
        echo '<span id="peptide-backfill-result" style="margin-left:10px;"></span>';

        ?>
        <script>
        (function($) {
            $('#peptide-backfill-sources').on('click', function() {
                var $btn = $(this), $result = $('#peptide-backfill-result');
                $btn.prop('disabled', true).text('<?php echo esc_js( __( 'Fixing...', 'peptide-news' ) ); ?>');
                $result.text('');
                $.post(peptideNewsAdmin.ajax_url, {
                    action: 'peptide_news_backfill_sources',
                    nonce: peptideNewsAdmin.admin_nonce
                }, function(response) {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Fix Article Sources', 'peptide-news' ) ); ?>');
                    if (response.success) {
                        $result.text(response.data.updated + ' article(s) updated.');
                    } else {
                        $result.text('Error: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('<?php echo esc_js( __( 'Fix Article Sources', 'peptide-news' ) ); ?>');
                    $result.text('Request failed.');
                });
            });

        })(jQuery);
        </script>
        <?php

        // Last fetch info.
        $last_fetch = get_option( 'peptide_news_last_fetch' );
        if ( $last_fetch ) {
            $extra_info = '';
            if ( isset( $last_fetch['filtered_out'] ) && $last_fetch['filtered_out'] > 0 ) {
                $extra_info .= sprintf( ' | %s: %d',
                    esc_html__( 'Filtered out', 'peptide-news' ),
                    $last_fetch['filtered_out']
                );
            }
            if ( isset( $last_fetch['ai_processed'] ) ) {
                $extra_info .= sprintf( ' | %s: %d',
                    esc_html__( 'AI analyzed', 'peptide-news' ),
                    $last_fetch['ai_processed']
                );
            }
            printf(
                '<p class="description">%s: %s | %s: %d | %s: %d%s</p>',
                esc_html__( 'Last fetch', 'peptide-news' ),
                esc_html( $last_fetch['time'] ),
                esc_html__( 'Found', 'peptide-news' ),
                $last_fetch['found'],
                esc_html__( 'New stored', 'peptide-news' ),
                $last_fetch['new_stored'],
                $extra_info
            );
        }

        // Settings form.
        echo '<form method="post" action="options.php">';
        settings_fields( 'peptide_news_settings_group' );
        do_settings_sections( 'peptide-news-settings' );
        submit_button();
        echo '</form>';

        // Shortcode usage info.
        echo '<h2>' . esc_html__( 'Usage', 'peptide-news' ) . '</h2>';
        echo '<p><strong>' . esc_html__( 'Shortcode:', 'peptide-news' ) . '</strong> <code>[peptide_news]</code> ' .
             esc_html__( 'or', 'peptide-news' ) . ' <code>[peptide_news count="5"]</code></p>';
        echo '<p><strong>' . esc_html__( 'Widget:', 'peptide-news' ) . '</strong> ' .
             esc_html__( 'Add "Peptide News" from Appearance > Widgets.', 'peptide-news' ) . '</p>';

        echo '</div>';
    }

    public function render_dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle CSV export before any HTML output.
        if ( isset( $_GET['export'] ) && 'csv' === $_GET['export'] ) {
            check_admin_referer( 'peptide_news_export_csv' );
            $this->export_csv();
            return;
        }

        include PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }

    /**
     * Stream a CSV export of click analytics data.
     */
    private function export_csv() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized.', 'peptide-news' ) );
        }

        $raw_start  = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : '';
        $raw_end    = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : '';
        $start_date = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_start ) ? $raw_start : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $end_date   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_end )   ? $raw_end   : gmdate( 'Y-m-d' );

        $data = Peptide_News_Analytics::export_clicks_csv( $start_date, $end_date );

        $filename = 'peptide-news-clicks-' . $start_date . '-to-' . $end_date . '.csv';

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        if ( ! empty( $data ) ) {
            fputcsv( $output, array_keys( $data[0] ) );
            foreach ( $data as $row ) {
                fputcsv( $output, $row );
            }
        } else {
            fputcsv( $output, array( 'No data for selected period' ) );
        }

        fclose( $output );
        exit;
    }

    public function render_articles_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        include PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/articles-list.php';
    }
}
