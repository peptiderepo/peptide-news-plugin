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
            array( 'jquery', 'dashboard' ),
            $this->version,
            true
        );
    }

    /**
     * Register the settings page.
     */
    public function register_settings_page() {
        add_management_page(
            'manage',
            'Peptide News',
            'Peptide News',
            'manage_options',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        require PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/settings-page.php';
    }

    /**
     * Render the analytics dashboard.
     */
    public function render_dashboard() {
        require PEPTIDE_NEWS_PLUGIN_DIR . 'admin/partials/dashboard.php';
    }
}
