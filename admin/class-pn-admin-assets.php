<?php
declare( strict_types=1 );
/**
 * Admin asset enqueuing.
 *
 * Handles CSS and JavaScript enqueuing for admin pages,
 * including Chart.js library for analytics and cost dashboards.
 *
 * @since 2.5.0
 * @see Peptide_News_Admin — Main admin orchestrator
 */
class Peptide_News_Admin_Assets {

	/** @var string Plugin name/slug */
	private $plugin_name;

	/** @var string Plugin version for cache busting */
	private $version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_name Plugin name/slug.
	 * @param string $version Plugin version.
	 */
	public function __construct( string $plugin_name, string $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	/**
	 * Enqueue admin CSS.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_styles( string $hook ): void {
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
	 * Enqueue admin JavaScript.
	 *
	 * Includes Chart.js for analytics and cost dashboards,
	 * plus the main admin script with AJAX handlers and localized data.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( strpos( $hook, 'peptide-news' ) === false ) {
			return;
		}

		// Chart.js for analytics dashboard.
		wp_enqueue_script(
			'chartjs',
			plugins_url( 'vendor/chartjs/chart.umd.min.js', __FILE__ ),
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
}
