<?php
/**
 * Public-facing functionality.
 *
 * Registers the [peptide_news] shortcode which renders a React-powered
 * news feed, the sidebar widget, and handles AJAX click tracking.
 *
 * @since 1.0.0
 * @since 2.0.0 Rewritten to use React frontend.
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
     *
     * Loads React (from WordPress core), the feed component, and
     * passes configuration via wp_localize_script.
     */
    public function enqueue_scripts() {
        // React feed component (depends on WP-bundled React).
        wp_register_script(
            $this->plugin_name . '-feed',
            PEPTIDE_NEWS_PLUGIN_URL . 'public/js/peptide-news-feed.js',
            array( 'react', 'react-dom' ),
            $this->version,
            true
        );
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
     * Outputs a mount-point div for the React feed component and
     * enqueues all required assets. Configuration is passed to JS
     * via a localized script object.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'count' => get_option( 'peptide_news_articles_count', 10 ),
        ), $atts, 'peptide_news' );

        $count = absint( $atts['count'] );

        // Enqueue assets only when the shortcode is actually rendered.
        wp_enqueue_style( $this->plugin_name );
        wp_enqueue_script( $this->plugin_name . '-feed' );

        // Pass configuration to the React component.
        wp_localize_script( $this->plugin_name . '-feed', 'peptideNewsFeed', array(
            'restUrl'   => esc_url_raw( rest_url( 'peptide-news/v1/' ) ),
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'nonce'     => wp_create_nonce( 'peptide_news_track' ),
            'sessionId' => $this->get_or_create_session_id(),
            'count'     => $count,
        ) );

        // React mount point — the component renders into this div.
        return '<div data-peptide-news-feed></div>';
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
     * Generate or retrieve a session ID for visitor tracking.
     *
     * @return string
     */
    private function get_or_create_session_id() {
        if ( isset( $_COOKIE['pn_session_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['pn_session_id'] ) );
        }

        $session_id = wp_generate_uuid4();

        if ( ! headers_sent() ) {
            setcookie(
                'pn_session_id',
                $session_id,
                array(
                    'expires'  => time() + 1800,
                    'path'     => '/',
                    'secure'   => is_ssl(),
                    'httponly' => true,
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
        echo $public->render_shortcode( array( 'count' => $count ) );

        echo $args['after_widget'];
    }

    public function form( $instance ) {
        if ( ! is_array( $instance ) ) {
            $instance = array();
        }
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
