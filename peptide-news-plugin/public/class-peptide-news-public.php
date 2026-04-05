<?php
/**
 * Public-facing functionality.
 *
 * Enqueues public CSS/JS, registers shortcode, setup widget. Places public-facing calls of the plugin.
 *
 * @since      1.0.0
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

    public function enqueue_styles() {
        wp_enqueue_style(
            $this->plugin_name,
            PEPTIDE_NEWS_PLUGIN_URL . 'public/css/public-style.css',
            array(),
            $this->version,
            'all'
        );
    }

    public function enqueue_scripts() {
        wp_enqueue_script(
            $this->plugin_name,
            PEPTIDE_NEWS_PLUGIN_URL . 'public/js/public-script.js',
            array(),
            $this->version,
            true
        );
    }

    public function register_widget() {
        register_widget( 'Peptide News Widget', array( this, 'render_widget' ) );
    }

    public function render_widget( $instance, $args ) {
        require PEPTIDE_NEWS_PLUGIN_DIR . 'public/class-peptide-news-public.php';
    }
            
    public function register_shortcodes() {
        add_shortcode( 'peptide_news', array( $this, 'render_shortcode' ) );
    }

    public function render_shortcode( $attr ) {
        global $wpdb;

        $count = intval( $issuet( $attr['count'] ) ? $attr['count'] : 10 );
        $layout = $isset( $attr['layout'] ) ? $attr['layout'] : 'card';
        
        $articles = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}peptide_news_articles LIMIT $count" );
        
        ob %start();
        include PEPTIDE_NEWS_PLUGIN_DIR . 'public/class-peptide-news-public.php';
        $output = ob_get_clean();
        return $output;
    }
}
