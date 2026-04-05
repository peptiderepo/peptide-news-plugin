<?php
/**
 * Public-facing functionality.
 *
 * Registers the [peptide_news] shortcode, the sidebar widget,
 * and handles AJAX click tracking.
 *
 * @since 1.0.0
 */
class Peptide_News_Public {

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $version;

    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
    }

    /**
     * Register front-end CSS (enqueued on demand by the shortcode/widget).
     */
    public function enqueue_styles() {
        wp_register_style(
            $this->plugin_name,
            PEPTIDE_NEWS_PLUGIN_URL . 'public/css/public-style.css',
            array(),
            $this->version,
            'all'
        );
    }

    /**
     * Register front-end JS (enqueued on demand by the shortcode/widget).
     */
    public function enqueue_scripts() {
        wp_register_script(
            $this->plugin_name,
            PEPTIDE_NEWS_PLUGIN_URL . 'public/js/public-script.js',
            array( 'jquery' ),
            $this->version,
            true
        );

        wp_localize_script( $this->plugin_name, 'peptideNewsPublic', array(
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'peptide_news_track' ),
            'session_id' => $this->get_or_create_session_id(),
        ) );
    }

    /**
     * Register the shortcode.
     */
    public function register_shortcode() {
        add_shortcode( 'peptide_news', array( $this, 'render_shortcode' ) );
    }

    /**
     * Register the widget.
     */
    public function register_widget() {
        register_widget( 'Peptide_News_Widget' );
    }

    /**
     * Render the [peptide_news] shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'count'  => get_option( 'peptide_news_articles_count', 10 ),
            'layout' => 'card', // card | compact | list
        ), $atts, 'peptide_news' );

        $articles = $this->get_articles( absint( $atts['count'] ) );

        if ( empty( $articles ) ) {
            return '<div class="pn-no-articles"><p>' . esc_html__( 'No peptide news articles available yet. Check back soon!', 'peptide-news' ) . '</p></div>';
        }

        // Enqueue assets only when the shortcode is actually rendered.
        wp_enqueue_style( $this->plugin_name );
        wp_enqueue_script( $this->plugin_name );

        $fallback_thumb = get_option( 'peptide_news_thumbnail_fallback', '' );
        $layout         = sanitize_key( $atts['layout'] );

        ob_start();
        ?>
        <div class="pn-news-feed pn-layout-<?php echo esc_attr( $layout ); ?>">
            <?php foreach ( $articles as $article ) :
                $thumb = ! empty( $article->thumbnail_url ) ? $article->thumbnail_url : $fallback_thumb;
                $date  = wp_date( 'M j, Y', strtotime( $article->published_at ) );
            ?>
                <article class="pn-article" data-article-id="<?php echo esc_attr( $article->id ); ?>">
                    <?php if ( ! empty( $thumb ) ) : ?>
                        <div class="pn-article-thumb">
                            <a href="<?php echo esc_url( $article->source_url ); ?>"
                               class="pn-track-click"
                               data-article-id="<?php echo esc_attr( $article->id ); ?>"
                               target="_blank" rel="noopener noreferrer">
                                <img src="<?php echo esc_url( $thumb ); ?>"
                                     alt="<?php echo esc_attr( $article->title ); ?>"
                                     loading="lazy" />
                            </a>
                        </div>
                    <?php endif; ?>

                    <div class="pn-article-content">
                        <div class="pn-article-meta">
                            <span class="pn-source"><?php echo esc_html( $article->source ); ?></span>
                            <span class="pn-separator">&middot;</span>
                            <time class="pn-date" datetime="<?php echo esc_attr( $article->published_at ); ?>">
                                <?php echo esc_html( $date ); ?>
                            </time>
                        </div>

                        <h3 class="pn-article-title">
                            <a href="<?php echo esc_url( $article->source_url ); ?>"
                               class="pn-track-click"
                               data-article-id="<?php echo esc_attr( $article->id ); ?>"
                               target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $article->title ); ?>
                            </a>
                        </h3>

                        <p class="pn-article-excerpt">
                            <?php
                            // Prefer AI summary over raw excerpt.
                            $display_text = ! empty( $article->ai_summary ) ? $article->ai_summary : $article->excerpt;
                            echo esc_html( $display_text );
                            ?>
                        </p>

                        <?php if ( ! empty( $article->author ) ) : ?>
                            <span class="pn-author">
                                <?php echo esc_html( $article->author ); ?>
                            </span>
                        <?php endif; ?>

                        <?php
                        // Show AI-extracted keyword tags if available, fall back to categories.
                        $tag_source = ! empty( $article->tags ) ? $article->tags : ( $article->categories ?? '' );
                        if ( ! empty( $tag_source ) ) : ?>
                            <div class="pn-tags">
                                <?php
                                $tag_items = array_map( 'trim', explode( ',', $tag_source ) );
                                foreach ( array_slice( $tag_items, 0, 5 ) as $tag_item ) :
                                    if ( empty( $tag_item ) ) continue;
                                ?>
                                    <span class="pn-tag"><?php echo esc_html( $tag_item ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle AJAX click tracking with basic rate limiting.
     */
    public function handle_click_tracking() {
        check_ajax_referer( 'peptide_news_track', 'nonce' );

        $article_id = isset( $_POST['article_id'] ) ? absint( $_POST['article_id'] ) : 0;

        if ( ! $article_id ) {
            wp_send_json_error( 'Invalid article ID.' );
        }

        // Rate limit: max 1 click per article per session per 5 seconds.
        $session_id   = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
        $throttle_key = 'pn_click_' . md5( $session_id . '_' . $article_id );

        if ( get_transient( $throttle_key ) ) {
            wp_send_json_success( array( 'throttled' => true ) );
        }
        set_transient( $throttle_key, 1, 5 );

        $meta = array(
            'ip'         => '',
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
            'referrer'   => isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '',
            'page_url'   => isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '',
            'session_id' => $session_id,
        );

        $result = Peptide_News_Analytics::record_click( $article_id, $meta );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( 'Failed to record click.' );
        }
    }

    /**
     * Get active articles from the database.
     *
     * @param int $count Number of articles.
     * @return array
     */
    private function get_articles( $count ) {
        global $wpdb;

        $table = $wpdb->prefix . 'peptide_news_articles';

        // Use transient cache to avoid DB hits on every page load.
        $cache_key = 'peptide_news_articles_' . $count;
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $articles = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, source, source_url, title, excerpt, ai_summary, author, thumbnail_url,
                    published_at, categories, tags
             FROM {$table}
             WHERE is_active = 1
             ORDER BY published_at DESC
             LIMIT %d",
            $count
        ) );

        // Cache for 5 minutes.
        set_transient( $cache_key, $articles, 5 * MINUTE_IN_SECONDS );

        return $articles;
    }

    /**
     * Generate or retrieve a session ID for visitor tracking.
     *
     * Sets the cookie in PHP if it doesn't exist so the value
     * is consistent between the server-side localized script data
     * and the client-side cookie.
     *
     * @return string
     */
    private function get_or_create_session_id() {
        if ( isset( $_COOKIE['pn_session_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['pn_session_id'] ) );
        }

        $session_id = wp_generate_uuid4();

        // Set a 30-minute session cookie (matches JS-side behaviour).
        if ( ! headers_sent() ) {
            setcookie(
                'pn_session_id',
                $session_id,
                array(
                    'expires'  => time() + 1800,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => false,   // JS needs read access for sendBeacon.
                    'samesite' => 'Lax',
                )
            );
        }

        return $session_id;
    }
}

/**
 * Peptide News Widget.
 *
 * @since 1.0.0
 */
class Peptide_News_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'peptide_news_widget',
            __( 'Peptide News', 'peptide-news' ),
            array(
                'description' => __( 'Displays the latest peptide research news.', 'peptide-news' ),
                'classname'   => 'peptide-news-widget',
            )
        );
    }

    public function widget( $args, $instance ) {
        $title = apply_filters( 'widget_title', $instance['title'] ?? __( 'Peptide News', 'peptide-news' ) );
        $count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;

        echo $args['before_widget'];

        if ( $title ) {
            echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        }

        $public = new Peptide_News_Public( 'peptide-news', PEPTIDE_NEWS_VERSION );
        echo $public->render_shortcode( array( 'count' => $count, 'layout' => 'compact' ) );

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        $title = isset( $instance['title'] ) ? $instance['title'] : __( 'Peptide News', 'peptide-news' );
        $count = isset( $instance['count'] ) ? absint( $instance['count'] ) : 5;
        ?>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
                <?php esc_html_e( 'Title:', 'peptide-news' ); ?>
            </label>
            <input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>" />
        </p>
        <p>
            <label for="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>">
                <?php esc_html_e( 'Number of articles:', 'peptide-news' ); ?>
            </label>
            <input class="tiny-text" id="<?php echo esc_attr( $this->get_field_id( 'count' ) ); ?>"
                   name="<?php echo esc_attr( $this->get_field_name( 'count' ) ); ?>"
                   type="number" min="1" max="50" value="<?php echo esc_attr( $count ); ?>" />
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        $instance          = array();
        $instance['title'] = sanitize_text_field( $new_instance['title'] ?? '' );
        $instance['count'] = absint( $new_instance['count'] ?? 5 );
        return $instance;
    }
}
